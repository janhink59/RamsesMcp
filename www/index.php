<?php
/**
 * MCP Server - Vstupní bod pro IIS/Apache (SQLSRV verze)
 * * Zpracovává JSON-RPC 2.0 poadavky, autentizuje klienta pøes Bearer token
 * a mapuje MCP metody na databázové definice a PHP tøídy v /tools/.
 */

require_once 'McpTool.php';
$config = require 'config.php';

// --- 1. Autentizace ---
// Ovìøení Bearer tokenu z HTTP hlavièky pro zabezpeèení pøístupu.
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (strpos($authHeader, 'Bearer ') !== 0 || substr($authHeader, 7) !== $config['auth_token']) {
	http_response_code(401);
	die(json_encode(["error" => "Unauthorized: Neplatnı token"]));
}

// --- 2. Pøipojení k MSSQL pøes ovladaè sqlsrv ---
$db = sqlsrv_connect($config['db']['server'], $config['db']['connection_info']);
if (!$db) {
	http_response_code(500);
	die(json_encode([
		"error" => "DB Connection Error", 
		"details" => sqlsrv_errors()
	]));
}

// --- 3. Pøíjem a dekódování JSON-RPC poadavku ---
$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);
$requestId = $request['id'] ?? null;
$method = $request['method'] ?? '';

/**
 * Odešle standardizovanou JSON-RPC odpovìï a ukonèí skript.
 */
function sendResponse($id, $result = null, $error = null) {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		"jsonrpc" => "2.0", 
		"id" => $id, 
		"result" => $result, 
		"error" => $error
	]);
	exit;
}

try {
	/**
	 * Metoda tools/list: Vrátí AI seznam dostupnıch funkcí.
	 * Data se tahají z tabulek mcp_tools a mcp_tool_params.
	 */
	if ($method === 'tools/list') {
		$sql = "SELECT t.name, t.description, p.param_name, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tools t
				LEFT JOIN mcp_tool_params p ON t.id = p.tool_id
				ORDER BY t.name";
		
		$query = sqlsrv_query($db, $sql);
		if ($query === false) throw new Exception("Chyba pøi ètení definic nástrojù.");

		$tools = [];
		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];
			if (!isset($tools[$tName])) {
				$tools[$tName] = [
					"name" => $tName,
					"description" => $row['description'],
					"inputSchema" => ["type" => "object", "properties" => [], "required" => []]
				];
			}
			// Mapování interních typù na JSON Schema standard
			if ($row['param_name']) {
				$jsonType = ($row['param_type'] === 'number' || $row['param_type'] === 'bigint') ? 'number' : 'string';
				$tools[$tName]['inputSchema']['properties'][$row['param_name']] = [
					"type" => $jsonType,
					"description" => $row['param_desc'] . ($row['param_type'] === 'uuid' ? " (UUID)" : "")
				];
				if ($row['is_required']) {
					$tools[$tName]['inputSchema']['required'][] = $row['param_name'];
				}
			}
		}
		sendResponse($requestId, ["tools" => array_values($tools)]);
	} 

	/**
	 * Metoda tools/call: Vykoná konkrétní akci.
	 * Dynamicky hledá soubor Get_{název}.php a instancuje tøídu Get_{název}.
	 */
	elseif ($method === 'tools/call') {
		$toolName = $request['params']['name'] ?? '';
		$toolArgs = $request['params']['arguments'] ?? [];

		// Bezpeènostní filtr a aplikace prefixu Get_
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
		$className = "Get_" . $pureName;
		$classFile = __DIR__ . "/tools/" . $className . ".php";

		if (file_exists($classFile)) {
			require_once $classFile;

			if (class_exists($className) && is_subclass_of($className, 'McpTool')) {
				// Naètení aktuálních definic parametrù pro validaci typu uuid a is_required
				$sqlParams = "SELECT param_name, param_type, is_required FROM mcp_tool_params 
							  WHERE tool_id = (SELECT id FROM mcp_tools WHERE name = ?)";
				$stmtParams = sqlsrv_query($db, $sqlParams, [$toolName]);
				
				$definitions = [];
				while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) {
					$definitions[] = $d;
				}

				// Vytvoøení instance nástroje s injektovanım DB spojením
				$instance = new $className($db);
				$result = $instance->validateAndExecute($toolArgs, $definitions);
				sendResponse($requestId, $result);
			}
		}
		sendResponse($requestId, null, ["code" => -32601, "message" => "Implementace tøídy $className nenalezena."]);
	}
} catch (Throwable $e) {
	// Zachycení neoèekávanıch vıjimek a jejich zabalení do JSON-RPC erroru
	sendResponse($requestId, null, [
		"code" => -32603, 
		"message" => "Internal Error: " . $e->getMessage()
	]);
}