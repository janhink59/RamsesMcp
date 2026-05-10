<?php
declare(strict_types=1);

/**
 * main.php - MCP Jádro pro JSON-RPC (AI klienti)
 * Verze 2.1 - Integrace s globální konfigurací a routerem index.php.
 * * KONTEXT: Tento soubor je inkludován z index.php v režimu 'main'.
 * Identita uživatele a nastavení DB již byly zpracovány v index.php
 * a jsou dostupné v globální proměnné $config.
 */

// --- CORS a podpora HTTP hlaviček z klienta (např. Page Assist) ---
// Webový klient (prohlížeč) při detekci vlastních hlaviček pošle nejprve OPTIONS dotaz.
// Musíme mu říct, že naše hlavičky X-Mcp-* jsou výslovně povoleny, jinak požadavek zahodí.
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

// Zpřístupnění globální konfigurace z index.php
global $config;

// 1. Získání a parsování payloadu (MUSÍ BÝT PRVNÍ, abychom znali ID požadavku pro logování)
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

	// Provedeme logickou autentizaci (set_login) v DB pro aktuální spojení
	$dbi->authenticate($user, $pass, $ip);

	// 4. Zpracování samotného MCP požadavku (JSON-RPC metody)
	if ($method === 'initialize') {
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
		// Upozornění od klienta, že inicializace proběhla. Nevrací se standardní JSON-RPC odpověď.
		exit;
	} elseif ($method === 'ping') {
		sendResponse($requestId, new stdClass(), null, $dbi);
	} elseif ($method === 'tools/list') {
		// Vrací seznam nástrojů ve formátu JSON Schema
		sendResponse($requestId, ["tools" => $dbi->getToolsForMain()], null, $dbi);
	} elseif ($method === 'tools/call') {
		// Spuštění konkrétního nástroje a vrácení výsledku v TSV
		$dbi->executeTool($request['params']['name'] ?? '', $request['params']['arguments'] ?? []);
		sendResponse($requestId, $dbi->getResponseAsMcpJson(), null, $dbi);
	} else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Method not found: " . $method], $dbi);
	}

} catch (Throwable $e) {
	sendResponse($requestId, null, ["code" => -32001, "message" => $e->getMessage()], $dbi ?? null);
}

/**
 * Zabalí výstup do striktního JSON-RPC 2.0 formátu a provede zápis do DB logu.
 */
function sendResponse($id, $result, $error, $dbi) {
	global $startTime, $rawInput, $request;
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
	
	$out = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	
	// Logování požadavku do tabulky mcp_log (pokud je k dispozici DB rozhraní)
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