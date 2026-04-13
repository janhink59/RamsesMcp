<?php
declare(strict_types=1);

/**
 * MCP Server - Vstupní bod (Router)
 */

$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 

set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

require_once 'RamsesLib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/McpTool.php';
$config = require __DIR__ . '/config.php';

if (isset($_GET['test'])) {
    require_once __DIR__ . '/test.php';
    exit;
}

function sendResponse(string|int|null $id, mixed $result = null, ?array $error = null): never {
    header('Content-Type: application/json; charset=utf-8');
    $response = ["jsonrpc" => "2.0", "id" => $id];
    if ($error !== null) { $response["error"] = $error; } else { $response["result"] = $result; }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Neplatný nebo chybějící token"]);
    exit;
}

$db = getMssqlConnection();

$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
    sendResponse(null, null, ["code" => -32700, "message" => "Parse error: Neplatný JSON."]);
}

$requestId = $request['id'] ?? null;
$method = $request['method'] ?? '';

try {
    if ($method === 'tools/list') {
        $sql = "SELECT t.name, t.description, p.param_name, p.param_type, p.description AS param_desc, p.is_required
                FROM mcp_tools t
                LEFT JOIN mcp_tool_params p ON t.id = p.tool_id
                ORDER BY t.name";
        
        $query = sqlsrv_query($db, $sql);
        if ($query === false) throw new Exception("Chyba DB: " . print_r(sqlsrv_errors(), true));

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
            if ($row['param_name']) {
                $jsonType = ($row['param_type'] === 'number' || $row['param_type'] === 'bigint') ? 'number' : 'string';
                $tools[$tName]['inputSchema']['properties'][$row['param_name']] = [
                    "type" => $jsonType,
                    "description" => $row['param_desc']
                ];
                if ($row['is_required']) $tools[$tName]['inputSchema']['required'][] = $row['param_name'];
            }
        }
        sendResponse($requestId, ["tools" => array_values($tools)]);
    } 
    elseif ($method === 'tools/call') {
        $toolName = $request['params']['name'] ?? '';
        $toolArgs = $request['params']['arguments'] ?? [];
        $pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
        $className = "Get_" . $pureName;
        $classFile = __DIR__ . "/tools/" . $className . ".php";

        if (file_exists($classFile)) {
            require_once $classFile;
            if (class_exists($className) && is_subclass_of($className, 'McpTool')) {
                $sqlParams = "SELECT param_name, param_type, is_required FROM mcp_tool_params 
                              WHERE tool_id = (SELECT id FROM mcp_tools WHERE name = ?)";
                $stmtParams = sqlsrv_query($db, $sqlParams, [$toolName]);
                $definitions = [];
                while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) { $definitions[] = $d; }

                $instance = new $className($db);
                $result = $instance->validateAndExecute($toolArgs, $definitions);
                sendResponse($requestId, $result);
            }
        }
        sendResponse($requestId, null, ["code" => -32601, "message" => "Nástroj $className nenalezen."]);
    } else {
        sendResponse($requestId, null, ["code" => -32601, "message" => "Metoda $method neexistuje."]);
    }
} catch (Throwable $e) {
    sendResponse($requestId, null, ["code" => -32603, "message" => "Internal Error: " . $e->getMessage()]);
}