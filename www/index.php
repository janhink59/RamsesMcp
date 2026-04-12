<?php
declare(strict_types=1);

/**
 * MCP Server - Vstupní bod pro IIS/Apache (SQLSRV verze)
 * Zpracovává JSON-RPC 2.0 požadavky, autentizuje klienta přes Bearer token.
 */

require_once __DIR__ . '/McpTool.php';
$config = require __DIR__ . '/config.php';

// --- Pomocná funkce pro JSON-RPC odpověď ---
function sendResponse(string|int|null $id, mixed $result = null, ?array $error = null): never {
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        "jsonrpc" => "2.0",
        "id" => $id
    ];
    
    if ($error !== null) {
        $response["error"] = $error;
    } else {
        $response["result"] = $result;
    }

    // JSON_UNESCAPED_UNICODE udrží českou diakritiku v čistém stavu (neescapovanou)
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- 1. Autentizace ---
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

// Použití modernější a bezpečnější PHP 8+ funkce str_starts_with
if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Neplatný nebo chybějící token"]);
    exit;
}

// --- 2. Připojení k MSSQL ---
// Zde je oprava tvé chyby: předáváme přímo pole 'options' z configu
$db = sqlsrv_connect($config['db']['server'], $config['db']['options']);

if ($db === false) {
    http_response_code(500);
    // V produkci by se detaily chyby (sqlsrv_errors) měly logovat do souboru, 
    // ne nutně vracet klientovi z bezpečnostních důvodů, ale pro vývoj je to ok.
    echo json_encode([
        "error" => "Database Connection Error",
        "details" => sqlsrv_errors()
    ]);
    exit;
}

// --- 3. Příjem a dekódování JSON-RPC požadavku ---
$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($request)) {
    sendResponse(null, null, ["code" => -32700, "message" => "Parse error: Neplatný JSON."]);
}

$requestId = $request['id'] ?? null;
$method = $request['method'] ?? '';

try {
    // --- Metoda: tools/list ---
    if ($method === 'tools/list') {
        $sql = "SELECT t.name, t.description, p.param_name, p.param_type, p.description AS param_desc, p.is_required
                FROM mcp_tools t
                LEFT JOIN mcp_tool_params p ON t.id = p.tool_id
                ORDER BY t.name";
        
        $query = sqlsrv_query($db, $sql);
        if ($query === false) {
            throw new Exception("Chyba při čtení definic nástrojů: " . print_r(sqlsrv_errors(), true));
        }

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
                    "description" => $row['param_desc'] . ($row['param_type'] === 'uuid' ? " (UUID)" : "")
                ];
                if ($row['is_required']) {
                    $tools[$tName]['inputSchema']['required'][] = $row['param_name'];
                }
            }
        }
        
        // Zajištění prázdného objektu pro JSON Schema, pokud nejsou nástroje
        sendResponse($requestId, ["tools" => array_values($tools)]);
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
                $sqlParams = "SELECT param_name, param_type, is_required FROM mcp_tool_params 
                              WHERE tool_id = (SELECT id FROM mcp_tools WHERE name = ?)";
                $stmtParams = sqlsrv_query($db, $sqlParams, [$toolName]);
                
                if ($stmtParams === false) {
                    throw new Exception("Chyba při čtení parametrů nástroje.");
                }

                $definitions = [];
                while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) {
                    $definitions[] = $d;
                }

                $instance = new $className($db);
                $result = $instance->validateAndExecute($toolArgs, $definitions);
                sendResponse($requestId, $result);
            }
        }
        sendResponse($requestId, null, ["code" => -32601, "message" => "Nástroj $className nenalezen."]);
    } 
    
    // --- Metoda nenalezena ---
    else {
        sendResponse($requestId, null, ["code" => -32601, "message" => "Metoda $method neexistuje."]);
    }

} catch (Throwable $e) {
    sendResponse($requestId, null, [
        "code" => -32603, 
        "message" => "Internal Error: " . $e->getMessage()
    ]);
}