<?php
declare(strict_types=1);

/**
 * MCP Server - Vstupní bod pro IIS/Apache (SQLSRV verze)
 */

// --- Odbočka pro testování ---
if (isset($_GET['test'])) {
	require_once __DIR__ . '/test.php';
	exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/McpTool.php';
require_once __DIR__ . '/McpRegistry.php';
$config = require __DIR__ . '/config.php';

/**
 * Pomocná funkce pro JSON-RPC odpověď
 * @param string|int|null $id
 * @param mixed $result
 * @param array|null $error
 */
function sendResponse($id, $result = null, $error = null): never {
	header('Content-Type: application/json; charset=utf-8');
	$response = ["jsonrpc" => "2.0", "id" => $id];
	if ($error !== null) { $response["error"] = $error; } else { $response["result"] = $result; }
	echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

// --- 1. Autentizace ---
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
	http_response_code(401);
	echo json_encode(["error" => "Unauthorized: Neplatný token"]);
	exit;
}

// --- 2. Připojení k MSSQL ---
$db = sqlsrv_connect($config['db']['server'], $config['db']['options']);
/** @var resource|false $db */

if ($db === false) {
	http_response_code(500);
	echo json_encode(["error" => "Database Connection Error", "details" => sqlsrv_errors()]);
	exit;
}

// --- 3. Příjem a dekódování JSON-RPC požadavku ---
$rawInput = file_get_contents('php://input');
/** @var array{id?: string|int, method?: string, params?: array{name?: string, arguments?: array<string, mixed>}}|null $request */
$request = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
	sendResponse(null, null, ["code" => -32700, "message" => "Parse error: Neplatný JSON."]);
}

$requestId = $request['id'] ?? null;
$method = $request['method'] ?? '';

try {
	// --- Metoda: tools/list ---
	if ($method === 'tools/list') {
		$registry = new McpRegistry($db);
		sendResponse($requestId, ["tools" => $registry->getTools()]);
	} 

	// --- Metoda: tools/call ---
	elseif ($method === 'tools/call') {
		$toolName = $request['params']['name'] ?? '';
		$toolArgs = $request['params']['arguments'] ?? [];

		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
		$className = "Get_" . $pureName;
		$classFile = __DIR__ . "/tools/" . $className . ".php";

		if (file_exists($classFile)) {
			require_once $classFile;
			if (class_exists($className) && is_subclass_of($className, 'McpTool')) {
				$sqlParams = "SELECT param_name, param_type, is_required FROM mcp_tool_param 
							  WHERE mcp_tool = (SELECT mcp_tool FROM mcp_tool WHERE name = ?)";
				$stmtParams = sqlsrv_query($db, $sqlParams, [$toolName]);
				
				$definitions = [];
				if ($stmtParams) {
					while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) { $definitions[] = $d; }
				}

				$instance = new $className($db);
				sendResponse($requestId, $instance->validateAndExecute($toolArgs, $definitions));
			}
		}
		sendResponse($requestId, null, ["code" => -32601, "message" => "Nástroj $className nenalezen."]);
	} 
	else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Metoda $method neexistuje."]);
	}
} catch (Throwable $e) {
	sendResponse($requestId, null, ["code" => -32603, "message" => "Internal Error: " . $e->getMessage()]);
}