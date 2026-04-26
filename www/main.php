<?php
declare(strict_types=1);

/**
 * main.php - MCP Jádro pro JSON-RPC (AI klienti)
 * Zajišuje autentizaci a deleguje logiku na db_interface.
 */

$startTime = microtime(true);
require_once __DIR__ . '/db_interface.php';

// 1. Autentizace
$config = require __DIR__ . '/config.php';
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!($config['auth']['disabled'] ?? false)) {
	if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32001, "message" => "Unauthorized"]]);
		exit;
	}
}

try {
	$dbi = new db_interface();
	$rawInput = file_get_contents('php://input');
	$request = json_decode($rawInput, true);

	if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
		sendResponse(null, null, ["code" => -32700, "message" => "Parse error"], $dbi);
	}

	$requestId = $request['id'] ?? null;
	$method = $request['method'] ?? '';

	if ($method === 'tools/list') {
		sendResponse($requestId, ["tools" => $dbi->getToolsForMain()], null, $dbi);
	} elseif ($method === 'tools/call') {
		$dbi->executeTool($request['params']['name'] ?? '', $request['params']['arguments'] ?? []);
		sendResponse($requestId, $dbi->getResponseAsMcpJson(), null, $dbi);
	} else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Method not found"], $dbi);
	}
} catch (Throwable $e) {
	sendResponse($requestId ?? null, null, ["code" => -32000, "message" => $e->getMessage()], $dbi ?? null);
}

function sendResponse($id, $result, $error, $dbi) {
	global $startTime, $rawInput, $request;
	header('Content-Type: application/json; charset=utf-8');
	$resp = ["jsonrpc" => "2.0", "id" => $id];
	if ($error) $resp["error"] = $error; else $resp["result"] = $result;
	$out = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($dbi) {
		$dbi->logRequest($id, $request['method'] ?? 'unknown', $rawInput, $out, (int)round((microtime(true) - $startTime) * 1000), $error !== null);
	}
	echo $out; exit;
}