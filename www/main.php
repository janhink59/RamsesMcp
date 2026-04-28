<?php
declare(strict_types=1);

/**
 * main.php - MCP Jádro pro JSON-RPC (AI klienti)
 * Zajišuje autentizaci z ENV hlavièek a deleguje logiku na db_interface.
 */

$startTime = microtime(true);
require_once __DIR__ . '/db_interface.php';

// 1. Získání a parsování payloadu (MUSÍ BÝT PRVNÍ, abychom znali ID)
$rawInput = file_get_contents('php://input');
$request  = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
	sendResponse(null, null, ["code" => -32700, "message" => "Parse error"], null);
}

$requestId = $request['id'] ?? null;
$method    = $request['method'] ?? '';

// 2. Vytvoøení instance rozhraní (Tím se otevøe fyzické DB pøipojení)
$dbi = new db_interface();

// 3. Extrakce identity z prostøedí klienta (Page Assist) a Autentizace
$user = $_SERVER['MCP_USER'] ?? $_SERVER['HTTP_X_MCP_USER'] ?? '';
$pass = $_SERVER['MCP_PASS'] ?? $_SERVER['HTTP_X_MCP_PASS'] ?? '';
$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
	if (empty($user) || empty($pass)) {
		throw new Exception("Unauthorized - Chybí pøihlašovací údaje (MCP_USER, MCP_PASS). Ujistìte se, že klient odesílá tyto hlavièky.");
	}

	// Provedeme autentizaci na stávajícím SPID v rámci $dbi
	$dbi->authenticate($user, $pass, $ip);

	// 4. Zpracování samotného MCP požadavku
	if ($method === 'initialize') {
		sendResponse($requestId, [
			"protocolVersion" => "2024-11-05",
			"capabilities" => [
				"tools" => new stdClass()
			],
			"serverInfo" => [
				"name" => "RamsesMcp",
				"version" => "2.0.0"
			]
		], null, $dbi);
	} elseif ($method === 'notifications/initialized') {
		exit;
	} elseif ($method === 'ping') {
		sendResponse($requestId, new stdClass(), null, $dbi);
	} elseif ($method === 'tools/list') {
		sendResponse($requestId, ["tools" => $dbi->getToolsForMain()], null, $dbi);
	} elseif ($method === 'tools/call') {
		$dbi->executeTool($request['params']['name'] ?? '', $request['params']['arguments'] ?? []);
		sendResponse($requestId, $dbi->getResponseAsMcpJson(), null, $dbi);
	} else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Method not found: " . $method], $dbi);
	}

} catch (Throwable $e) {
	sendResponse($requestId, null, ["code" => -32001, "message" => $e->getMessage()], $dbi ?? null);
}

/**
 * Zabalí výstup do striktního JSON-RPC 2.0 formátu.
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
	
	$logId = is_scalar($id) ? (string)$id : 'null';
	if ($dbi !== null && isset($request['method'])) {
		$dbi->logRequest($logId, $request['method'], $rawInput, $out, (int)round((microtime(true) - $startTime) * 1000), $error !== null);
	}
	
	echo $out; 
	exit;
}