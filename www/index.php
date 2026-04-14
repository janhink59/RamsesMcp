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

// Načtení závislostí
require_once 'RamsesLib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/McpTool.php';

// ------------------------------------------------------------------
// 1. DIAGNOSTICKÝ REŽIM (HTML)
// ------------------------------------------------------------------
if (isset($_GET['test'])) {
	require_once __DIR__ . '/test.php';
	exit; // Tímto se zpracování ukončí a vrací se pouze HTML
}

// ------------------------------------------------------------------
// 2. STANDARDNÍ MCP REŽIM (JSON)
// ------------------------------------------------------------------

/**
 * Vynucuje odeslání validního JSON-RPC formátu a ukončuje skript.
 * HTTP kód je vždy 200 OK, aby Apache/PHP nevkládaly vlastní chybové HTML.
 */
function sendResponse(string|int|null $id, mixed $result = null, ?array $error = null): never {
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

	echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

// Načtení konfigurace
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
	sendResponse(null, null, ["code" => -32000, "message" => "Internal Error: Chybí konfigurační soubor config.php"]);
}
$config = require $configPath;

// Kontrola autentizace (Bearer token)
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!str_starts_with($authHeader, 'Bearer ') || substr($authHeader, 7) !== $config['auth']['bearer_token']) {
	// Záměrně nepoužíváme http_response_code(401), aby to Apache nepřepsal na HTML.
	// Ollama uvidí chybu v tomto JSONu a pochopí ji.
	sendResponse(null, null, [
		"code"    => -32001, 
		"message" => "Unauthorized: Neplatný nebo chybějící Bearer token."
	]);
}

// Hlavní smyčka zpracování MCP požadavku
try {
	// Připojení k databázi (pokud selže, vyhodí výjimku a chytíme ji dole)
	$db = getMssqlConnection();

	// Načtení těla JSON požadavku
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

	// Vyřízení metody: listování nástrojů
	if ($method === 'tools/list') {
		$sql = "SELECT t.name, t.description, p.param_name, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tool t
				LEFT JOIN mcp_tool_param p ON t.mcp_tool = p.mcp_tool
				ORDER BY t.name";
		
		$query = sqlsrv_query($db, $sql);
		if ($query === false) throw new Exception("Chyba DB: " . print_r(sqlsrv_errors(), true));

		$tools = [];
		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];
			if (!isset($tools[$tName])) {
				$tools[$tName] = [
					"name"        => $tName,
					"description" => $row['description'],
					"inputSchema" => ["type" => "object", "properties" => [], "required" => []]
				];
			}
			
			if ($row['param_name']) {
				$jsonType = ($row['param_type'] === 'number' || $row['param_type'] === 'bigint') ? 'number' : 'string';
				$tools[$tName]['inputSchema']['properties'][$row['param_name']] = [
					"type"        => $jsonType,
					"description" => $row['param_desc']
				];
				if ($row['is_required']) {
					$tools[$tName]['inputSchema']['required'][] = $row['param_name'];
				}
			}
		}
		sendResponse($requestId, ["tools" => array_values($tools)]);
	} 
	// Vyřízení metody: volání nástroje
	elseif ($method === 'tools/call') {
		$toolName = $request['params']['name'] ?? '';
		$toolArgs = $request['params']['arguments'] ?? [];
		
		$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', $toolName);
		$className = "Get_" . $pureName;
		$classFile = __DIR__ . "/tools/" . $className . ".php";

		if (file_exists($classFile)) {
			require_once $classFile;
			
			if (class_exists($className) && is_subclass_of($className, 'McpTool')) {
				$sqlParams = "SELECT param_name, param_type, is_required FROM mcp_tool_param 
							  WHERE mcp_tool = (SELECT mcp_tool FROM mcp_tool WHERE name = ?)";
				$stmtParams = sqlsrv_query($db, $sqlParams, [$toolName]);
				
				$definitions = [];
				while ($d = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) {
					$definitions[] = $d;
				}

				/** @var McpTool $instance */
				$instance = new $className($db);
				$result = $instance->validateAndExecute($toolArgs, $definitions);
				
				sendResponse($requestId, $result);
			}
		}
		
		sendResponse($requestId, null, [
			"code"    => -32601, 
			"message" => "Nástroj $className nebyl nalezen nebo chybí implementace."
		]);
	} 
	// Neznámá metoda
	else {
		sendResponse($requestId, null, ["code" => -32601, "message" => "Metoda '$method' není podporována."]);
	}

} catch (Throwable $e) {
	// Globální zachytávání chyb - zaručuje, že ven vypadne vždy jen JSON
	sendResponse($requestId ?? null, null, [
		"code"    => -32000, 
		"message" => "Server Error: " . $e->getMessage()
	]);
}