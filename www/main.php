<?php
declare(strict_types=1);

/**
 * main.php - MCP Jádro pro JSON-RPC (AI klienti)
 * Zajišuje autentizaci a deleguje logiku na db_interface.
 */

$startTime = microtime(true);
require_once __DIR__ . '/db_interface.php';

// 1. Získání a parsování payloadu (MUSÍ BÝT PRVNÍ, abychom znali ID požadavku pro pøípadné chyby)
$rawInput = file_get_contents('php://input');
$request  = json_decode($rawInput, true);

// Pokud není JSON validní, ID neznáme
if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
	sendResponse(null, null, ["code" => -32700, "message" => "Parse error"], null);
}

$requestId = $request['id'] ?? null;
$method    = $request['method'] ?? '';

// 2. Autentizace s pokroèilým dolováním hlavièek (OBCHÁZENÍ IIS)
$config = require __DIR__ . '/config.php';

if (!($config['auth']['disabled'] ?? false)) {
	$authHeader = '';
	
	// Pokusíme se získat hlavièku ze všech možných úkrytù serveru
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'];
	} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
		$authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	} elseif (isset($_SERVER['HTTP_X_MCP_AUTH'])) {
		$authHeader = $_SERVER['HTTP_X_MCP_AUTH'];                 // Náš záchranný kruh pro IIS !
	} elseif (function_exists('apache_request_headers')) {
		$apacheHeaders = apache_request_headers();
		if (isset($apacheHeaders['Authorization'])) {
			$authHeader = $apacheHeaders['Authorization'];
		} elseif (isset($apacheHeaders['authorization'])) {
			$authHeader = $apacheHeaders['authorization'];
		} elseif (isset($apacheHeaders['X-Mcp-Auth'])) {
			$authHeader = $apacheHeaders['X-Mcp-Auth'];
		}
	}

	// Kontrola tokenu - Pokud selže, vracíme chybu se správným ID klienta!
	if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
		sendResponse($requestId, null, ["code" => -32001, "message" => "Unauthorized - Invalid or missing Bearer token"], null);
	}
}

// 3. Zpracování samotného MCP požadavku
try {
	$dbi = new db_interface();

	if ($method === 'initialize') {
		// Povinná prvotní odpovìï definující vlastnosti serveru
		sendResponse($requestId, [
			"protocolVersion" => "2024-11-05",
			"capabilities" => [
				"tools" => new stdClass()                           // Vygeneruje prázdný JSON objekt {}
			],
			"serverInfo" => [
				"name" => "RamsesMcp",
				"version" => "1.0.0"
			]
		], null, $dbi);
	} elseif ($method === 'notifications/initialized') {
		// Klient potvrdil inicializaci (jedná se o notifikaci, vracíme jen HTTP 200, bez tìla)
		exit;
	} elseif ($method === 'ping') {
		// Keep-alive dotaz klienta
		sendResponse($requestId, new stdClass(), null, $dbi);
	} elseif ($method === 'tools/list') {
		// Požadavek na poskytnutí schémat všech dostupných nástrojù
		sendResponse($requestId, ["tools" => $dbi->getToolsForMain()], null, $dbi);
	} elseif ($method === 'tools/call') {
		// Fyzická exekuce konkrétního nástroje
		$dbi->executeTool($request['params']['name'] ?? '', $request['params']['arguments'] ?? []);
		sendResponse($requestId, $dbi->getResponseAsMcpJson(), null, $dbi);
	} else {
		// Neznámá metoda
		sendResponse($requestId, null, ["code" => -32601, "message" => "Method not found: " . $method], $dbi);
	}
} catch (Throwable $e) {
	sendResponse($requestId, null, ["code" => -32000, "message" => $e->getMessage()], $dbi ?? null);
}

/**
 * Zabalí výstup do striktního JSON-RPC 2.0 formátu.
 */
function sendResponse($id, $result, $error, $dbi) {
	global $startTime, $rawInput, $request;
	header('Content-Type: application/json; charset=utf-8');
	
	$resp = ["jsonrpc" => "2.0"];
	
	// Strictly handle ID (must be string, number, or null)
	if ($id !== null) {
		$resp["id"] = $id;
	} elseif ($error !== null && isset($request['id'])) {
		// Pokud vznikla chyba a klient poslal ID, musíme ho vrátit
		$resp["id"] = $request['id'];
	}

	if ($error) {
		$resp["error"] = $error;
	} else {
		$resp["result"] = $result;
	}
	
	$out = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	
	// Bezpeèný zápis do logu (prevence pádu, pokud je id pole/objekt, což by nemìlo)
	$logId = is_scalar($id) ? (string)$id : 'null';
	
	if ($dbi !== null && isset($request['method'])) {
		$dbi->logRequest($logId, $request['method'], $rawInput, $out, (int)round((microtime(true) - $startTime) * 1000), $error !== null);
	}
	
	echo $out; 
	exit;
}