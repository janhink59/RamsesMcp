<?php
declare(strict_types=1);

/**
 * RamsesMcp - Hlavní vstupní bod (Router)
 * Striktní rozdělení: 
 * - Pokud je v URL ?test -> Vrací HTML dashboard (UTF-8).
 * - Jinak -> Vždy vrací validní JSON-RPC pro MCP klienta (Ollama).
 */

$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 

set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

// Globální časovač pro měření celkové doby trvání requestu (využito pro mcp_log)
$startTime = microtime(true);

// Načtení klíčových závislostí a sdílených knihoven
require_once 'RamsesLib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/McpTool.php';
require_once __DIR__ . '/McpGenericStoredProc.php';

// ------------------------------------------------------------------
// 1. DIAGNOSTICKÝ REŽIM (HTML)
// ------------------------------------------------------------------
// Diagnostika a Testovací UI se vyvolá přidáním parametru ?test do URL.
if (isset($_GET['test'])) {
	require_once __DIR__ . '/test.php';
	exit;                               // Tímto se zpracování ukončí a vrací se pouze interaktivní HTML dashboard
}

// ------------------------------------------------------------------
// 2. STANDARDNÍ MCP REŽIM (JSON)
// ------------------------------------------------------------------

/**
 * Vynucuje odeslání validního JSON-RPC formátu a ukončuje skript.
 * Zároveň loguje kompletní request, response a trvání do tabulky mcp_log.
 * HTTP kód je vždy 200 OK, aby Apache/PHP nevkládaly vlastní chybové HTML,
 * které by rozbilo JSON parser v MCP klientovi.
 * * @param string|int|null $id     Identifikátor požadavku (dle JSON-RPC specifikace)
 * @param mixed           $result Výsledek úspěšného volání
 * @param array|null      $error  Chybový objekt (pokud volání selhalo)
 */
function sendResponse(string|int|null $id, mixed $result = null, ?array $error = null): never {
	global $db, $rawInput, $request, $startTime;
	
	header('Content-Type: application/json; charset=utf-8');
	
	$response = [
		"jsonrpc" => "2.0",
		"id"      => $id
	];

	if ($error !== null) {
		$response["error"] = $error;
	} else {
		$response["result"] = $result;
	}

	$jsonOutput = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	// Zápis do logovací tabulky mcp_log (pokud se úspěšně navázalo spojení s databází)
	if (isset($db) && $db !== false) {
		$durationMs = (int)round((microtime(true) - $startTime) * 1000);
		$methodName = $request['method'] ?? 'unknown';
		$isError    = $error !== null ? 1 : 0;
		$reqIdStr   = (string)$id;
		
		$logSql = "INSERT INTO mcp_log (request_id, method, payload_in, payload_out, duration_ms, error_flag) 
				   VALUES (?, ?, ?, ?, ?, ?)";
		
		sqlsrv_query($db, $logSql, [
			$reqIdStr, 
			$methodName, 
			$rawInput ?? '', 
			$jsonOutput, 
			$durationMs, 
			$isError
		]);
	}

	echo $jsonOutput;
	exit;
}

// Načtení konfigurace
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
	sendResponse(null, null, ["code" => -32000, "message" => "Internal Error: Chybí konfigurační soubor config.php"]);
}
$config = require $configPath;

// Kontrola autentizace (Bearer token) s ohledem na bypass pro lokální vývoj
$authDisabled = $config['auth']['disabled'] ?? false;
$headers      = getallheaders();
$authHeader   = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!$authDisabled) {
	if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
		// Záměrně nepoužíváme http_response_code(401), aby to Apache nepřepsal na HTML.
		// Ollama uvidí chybu v tomto JSONu a pochopí ji.
		sendResponse(null, null, [
			"code"    => -32001, 
			"message" => "Unauthorized: Neplatný nebo chybějící Bearer token."
		]);
	}
}

// Hlavní smyčka zpracování MCP požadavku
try {
	// Připojení k databázi (pokud selže, vyhodí výjimku a chytíme ji dole v catch bloku)
	$db = getMssqlConnection();

	// Načtení těla JSON požadavku ze standardního vstupu
	$rawInput = file_get_contents('php://input');
	
	if (empty($rawInput)) {
		sendResponse(null, null, ["code" => -32700, "message" => "Parse error: Požadavek neobsahuje žádná data (očekáván JSON)."]);
	}

	$request = json_decode($rawInput, true);

	if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
		sendResponse(null, null, ["code" => -32700, "message" => "Parse error: Neplatný formát JSON."]);
	}

	$requestId = $request['id'] ?? null;
	$method    = $request['method'] ?? '';

	// Vyřízení metody: listování nástrojů (Discovery fáze klienta)
	if ($method === 'tools/list') {
		require_once __DIR__ . '/McpRegistry.php';
		$registry = new McpRegistry($db);
		
		// Registr zapouzdřuje složitou logiku načítání metadat a formátování do JSON Schema
		sendResponse($requestId, ["tools" => $registry->getTools()]);
	} 
	// Vyřízení metody: volání konkrétního nástroje s argumenty
	elseif ($method === 'tools/call') {
		$toolName = $request['params']['name'] ?? '';
		$toolArgs = $request['params']['arguments'] ?? [];
		
		// Zjištění informací o nástroji z databáze, především příznaku is_generic
		$sqlTool  = "SELECT mcp_tool, is_generic FROM mcp_tool WHERE name = ?";
		$stmtTool = sqlsrv_query($db, $sqlTool, [$toolName]);
		$toolRow  = sqlsrv_fetch_array($stmtTool, SQLSRV_FETCH_ASSOC);

		if (!$toolRow) {
			sendResponse($requestId, null, ["code" => -32601, "message" => "Nástroj '$toolName' není evidován v databázi."]);
		}

		// Načtení definic parametrů nástroje pro následnou typovou a povinnostní validaci
		$sqlParams  = "SELECT param_name, param_type, is_required FROM mcp_tool_param WHERE mcp_tool = ?";
		$stmtParams = sqlsrv_query($db, $sqlParams, [$toolRow['mcp_tool']]);
		
		$definitions = [];
		while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) {
			$definitions[] = $d;
		}

		// Rozvětvení aplikační logiky na základě příznaku is_generic
		if ($toolRow['is_generic']) {
			// Nástroj je generický -> voláme dynamickou třídu obsluhující uloženou proceduru
			$instance = new McpGenericStoredProc($db, $toolName, $definitions);
			$result   = $instance->validateAndExecute($toolArgs, $definitions);
			sendResponse($requestId, $result);
		} else {
			// Nástroj je specifický -> hledáme konkrétní PHP třídu na disku (prefix Get_)
			$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
			$className = "Get_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";

			if (file_exists($classFile)) {
				require_once $classFile;
				
				if (class_exists($className) && is_subclass_of($className, 'McpTool')) {
					/** @var McpTool $instance */
					$instance = new $className($db);
					$result   = $instance->validateAndExecute($toolArgs, $definitions);
					sendResponse($requestId, $result);
				}
			}
			
			// Pokud fyzický skript nebo třída neexistuje, vracíme specifikovanou JSON-RPC chybu
			sendResponse($requestId, null, ["code" => -32601, "message" => "Nástroj $className nebyl nalezen nebo chybí implementace."]);
		}
	} 
	// Neznámá metoda, kterou tento server nepodporuje
	else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Metoda '$method' není podporována."]);
	}

} catch (Throwable $e) {
	// Globální zachytávání chyb - zaručuje, že klient vždy dostane validní JSON odpověď.
	// K záznamu do mcp_log tabulky dojde automaticky v rámci funkce sendResponse.
	sendResponse($requestId ?? null, null, [
		"code"    => -32000, 
		"message" => "Server Error: " . $e->getMessage()
	]);
}