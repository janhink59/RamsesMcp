<?php
declare(strict_types=1);

/**
 * RamsesMcp - main.php (Jádro JSON-RPC pro AI klienty)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript je spouštěn výhradně z index.php (Router) v režimu '?mode=main'.
 * Jeho jediným úkolem je komunikovat v přísném standardu JSON-RPC 2.0.
 *
 * * PŘEDPOKLADY BĚHU:
 * 1. Output Buffer je čistý (zajištěno ob_clean() v index.php).
 * 2. Globální proměnná $config je naplněna a případně modifikována HTTP hlavičkami.
 *
 * * OŠETŘENÍ CHYB (DESIGN DECISION):
 * Jakákoliv výjimka (Exception) nebo formatační chyba NESMÍ vypsat HTML. 
 * Vše se musí zachytit v bloku try-catch a vrátit přes funkci sendResponse() 
 * jako strukturovaný JSON objekt "error".
 */

// --- CORS a podpora HTTP hlaviček z klienta (např. Page Assist) ---
// DESIGN DECISION: Webový klient (prohlížeč) při detekci vlastních hlaviček pošle nejprve OPTIONS dotaz.
// Musíme mu říct, že naše hlavičky X-Mcp-* jsou výslovně povoleny, jinak prohlížeč požadavek zahodí dřív, než dojde k PHP.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Mcp-User, X-Mcp-Pass, X-Mcp-Dbserver, X-Mcp-Database");

// Rychlé vyřízení preflight OPTIONS požadavku (bez spouštění databázové logiky)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

$startTime = microtime(true);
require_once __DIR__ . '/db_interface.php';

/** * @global array $config Globální konfigurace z index.php 
 */
global $config;

// 1. Získání a parsování payloadu
// DESIGN DECISION: Toto musí být první krok před DB spojením. Pokud spadne DB,
// potřebujeme už znát "id" JSON-RPC požadavku, abychom do logu a klientovi poslali správnou odpověď.
$rawInput = file_get_contents('php://input');
$request  = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
	sendResponse(null, null, ["code" => -32700, "message" => "Parse error"], null);
}

$requestId = $request['id'] ?? null;
$method    = $request['method'] ?? '';

// 2. Vytvoření instance rozhraní (Tím se otevře fyzické DB připojení přes Singleton)
$dbi = new db_interface();

// 3. Extrakce identity z globální konfigurace a Autentizace
// Router v index.php již vyřešil přepisy z HTTP hlaviček do pole $config
$user = $config['mcp']['user'] ?? '';
$pass = $config['mcp']['password'] ?? '';
$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
	if (empty($user) || empty($pass)) {
		throw new Exception("Unauthorized - Chybí přihlašovací údaje. Ujistěte se, že klient odesílá hlavičky X-Mcp-User a X-Mcp-Pass.");
	}

	// 4. Logická autentizace (nastavení kontextu v DB pro aktuální spojení/SPID)
	$dbi->authenticate($user, $pass, $ip);

	// 5. Zpracování samotného MCP požadavku (JSON-RPC metody) podle oficiální specifikace
	if ($method === 'initialize') {
		// Handshake fáze: Server deklaruje své schopnosti (tools)
		sendResponse($requestId, [
			"protocolVersion" => "2024-11-05",
			"capabilities" => [
				"tools" => new stdClass()
			],
			"serverInfo" => [
				"name" => $config['mcp']['name'] ?? "RamsesMcp",
				"version" => $config['mcp']['version'] ?? "2.0.0"
			]
		], null, $dbi);
	} elseif ($method === 'notifications/initialized') {
		// Upozornění od klienta, že inicializace proběhla. Specifikace říká: Nevracet odpověď.
		exit;
	} elseif ($method === 'ping') {
		// Udržování spojení naživu
		sendResponse($requestId, new stdClass(), null, $dbi);
	} elseif ($method === 'tools/list') {
		// Vrací seznam nástrojů ve formátu JSON Schema (vygenerováno přes dbi)
		sendResponse($requestId, ["tools" => $dbi->getToolsForMain()], null, $dbi);
	} elseif ($method === 'tools/call') {
		// Spuštění konkrétního nástroje. Výsledek se generuje typicky v úsporném TSV formátu.
		$dbi->executeTool($request['params']['name'] ?? '', $request['params']['arguments'] ?? []);
		sendResponse($requestId, $dbi->getResponseAsMcpJson(), null, $dbi);
	} else {
		// Neznámá metoda
		sendResponse($requestId, null, ["code" => -32601, "message" => "Method not found: " . $method], $dbi);
	}

} catch (Throwable $e) {
	// Zachycení všech chyb (vč. chyb z DB) a jejich transformace do JSON-RPC error bloku
	sendResponse($requestId, null, ["code" => -32001, "message" => $e->getMessage()], $dbi ?? null);
}

/**
 * Zabalí výstup do striktního JSON-RPC 2.0 formátu, odešle ho klientovi a provede zápis do logu.
 *
 * @param mixed $id Identifikátor požadavku (značí asynchronní spárování)
 * @param mixed $result Úspěšná data (null v případě chyby)
 * @param array|null $error Pole s popisem chyby ['code' => int, 'message' => string]
 * @param db_interface|null $dbi Instance DB rozhraní pro účely logování do mcp_log
 */
function sendResponse($id, $result, ?array $error, ?db_interface $dbi) {
	global $startTime, $rawInput, $request;
	
	// DESIGN DECISION: Explicitní deklarace Content-Type UTF-8 je kritická pro AI klienty, 
	// aby nedocházelo k chybám v kódování češtiny z MSSQL.
	header('Content-Type: application/json; charset=utf-8');
	
	$resp = ["jsonrpc" => "2.0"];
	
	if ($id !== null) {
		$resp["id"] = $id;
	} elseif ($error !== null && isset($request['id'])) {
		$resp["id"] = $request['id'];
	}

	if ($error) {
		$resp["error"] = $error;
	} else {
		$resp["result"] = $result;
	}
	
	// Bezpečné kódování JSONu - nesmí escapovat Unicode (češtinu) ani lomítka (pro cesty)
	$out = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	
	// Logování požadavku do tabulky mcp_log (pokud je k dispozici DB rozhraní a známe metodu)
	$logId = is_scalar($id) ? (string)$id : 'null';
	if ($dbi !== null && isset($request['method'])) {
		$dbi->logRequest(
			$logId, 
			$request['method'], 
			$rawInput, 
			$out, 
			(int)round((microtime(true) - $startTime) * 1000), 
			$error !== null
		);
	}
	
	echo $out; 
	exit;
}