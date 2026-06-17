<?php
declare(strict_types=1);

/**
 * RamsesMcp - index.php (Front Controller, Router & Security Gateway)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript je jediným vstupním bodem (Entry Point) celé aplikace. Všechny požadavky
 * (z prohlížeče i od AI klientů či prohlížečových rozšíření) musí procházet přes tento soubor.
 *
 * * * HLAVNÍ ÚKOLY A MECHANISMY:
 * 1. CORS GATEWAY: Okamžitě zpracovává preflight OPTIONS požadavky od browser extension (např. Page Assist).
 * CORS politika a hlavičky jsou vynuceny na samotném začátku, aby se předešlo zablokování spojení prohlížečem.
 * 2. CONFIG ORCHESTRATION & MULTITENANCY: Detekuje instanci podle SERVER_NAME / HTTP_HOST a vyžaduje
 * specifický `rcfg_{SERVER_NAME}.php`. Neexistuje fallback. V případě chybějící konfigurace bezpečně
 * vrací zformovanou JSON-RPC nebo HTML chybu a ihned ukončuje běh. Pro MCP klienty vynucuje kód HTTP 200.
 * 3. CONTEXT INJECTION (Přepis konfigurace): Mapuje příchozí HTTP hlavičky X-Mcp-* do vnitřního pole $config,
 * čímž za běhu přebíjí identitu uživatele, heslo a cílovou databázi bez nutnosti měnit soubor na disku.
 * 4. ROUTING: Směřuje požadavky na základě parametru `?mode=` v URL na konkrétní subsystémy.
 *
 * * * PODPOROVANÉ REŽIMY (?mode=):
 * - mode=main : (Výchozí) Jádro pro JSON-RPC komunikaci s AI modely (Ollama, Claude).
 * - mode=info : Interaktivní HTML dashboard pro vývojáře (diagnostika a testování).
 * - mode=test : AJAX endpoint pro spouštění izolovaných integračních testů (vrací HTML a JSON payload).
 */

// 1. ZÁCHRANNÝ BUFFERING
ob_start();

// Zjištění režimu. Pokud parametr chybí, automaticky předpokládáme AI klienta.
$mode = $_GET['mode'] ?? 'main';

// ========================================================================
// UNIVERZÁLNÍ CORS OCHRANA
// Musí být na úplném začátku, aby preflight OPTIONS prošel bez ohledu na režim
// nebo to, zda na serveru vůbec existuje konfigurační soubor.
// ========================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
header("Access-Control-Allow-Headers: Content-Type, X-Mcp-User, X-Mcp-Pass, X-Mcp-Dbserver, X-Mcp-Database");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

if ($mode === 'main') {
	ini_set('display_errors', '0');
	error_reporting(E_ALL);
}

// 2. NAČTENÍ KONFIGURACE: Dynamická detekce instance na základě SERVER_NAME / HTTP_HOST.
$CONFIG_SERVER_NAME = $_SERVER['SERVER_NAME'] ?? '';
if (empty($CONFIG_SERVER_NAME) && isset($_SERVER['HTTP_HOST'])) {
	$CONFIG_SERVER_NAME = explode(':', $_SERVER['HTTP_HOST'])[0];
}

$CONFIG_SERVER_NAME = preg_replace('/[^a-zA-Z0-9_.-]/', '', $CONFIG_SERVER_NAME);

if ($CONFIG_SERVER_NAME === '') {
	$CONFIG_SERVER_NAME = 'localhost';
}

$configFile = __DIR__ . '/rcfg_' . $CONFIG_SERVER_NAME . '.php';

// Striktní ošetření chybějící multitenantní konfigurace
if (!file_exists($configFile)) {
	if ($mode === 'main') {
		ob_clean();
		// KRIZOVÁ OPRAVA PRO MCP: Vždy musíme vrátit HTTP 200. Pokud bychom nechali 500,
		// MCP klient spojení okamžitě shodí s transportní chybou o SSE endpoints.
		http_response_code(200);
		header('Content-Type: application/json; charset=utf-8');
		
		$reqId = 0;
		$rawInput = file_get_contents('php://input');
		if ($rawInput) {
			$parsed = json_decode($rawInput, true);
			if (isset($parsed['id']) && (is_string($parsed['id']) || is_int($parsed['id']))) {
				$reqId = $parsed['id'];
			}
		}

		echo json_encode([
			"jsonrpc" => "2.0",
			"error" => [
				"code" => -32000,
				"message" => "Systémová chyba: Konfigurační soubor 'rcfg_{$CONFIG_SERVER_NAME}.php' na serveru neexistuje."
			],
			"id" => $reqId
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	} else {
		ob_end_clean();
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
		die("<div style='color: #d93025; font-family: sans-serif; padding: 20px;'><strong>Kritická chyba:</strong> Požadovaný konfigurační soubor <code>rcfg_{$CONFIG_SERVER_NAME}.php</code> nebyl na serveru nalezen.</div>");
	}
}

$config = require_once $configFile;

/**
 * DYNAMICKÝ PŘEPIS KONFIGURACE (Context Injection):
 */
if (isset($_SERVER['HTTP_X_MCP_DBSERVER']) && trim($_SERVER['HTTP_X_MCP_DBSERVER']) !== '') {
	$config['db']['server'] = trim($_SERVER['HTTP_X_MCP_DBSERVER']);
}

if (isset($_SERVER['HTTP_X_MCP_DATABASE']) && trim($_SERVER['HTTP_X_MCP_DATABASE']) !== '') {
	$config['db']['options']['Database'] = trim($_SERVER['HTTP_X_MCP_DATABASE']);
}

if (isset($_SERVER['HTTP_X_MCP_USER']) && trim($_SERVER['HTTP_X_MCP_USER']) !== '') {
	$config['mcp']['user'] = trim($_SERVER['HTTP_X_MCP_USER']);
}

if (isset($_SERVER['HTTP_X_MCP_PASS']) && trim($_SERVER['HTTP_X_MCP_PASS']) !== '') {
	$config['mcp']['password'] = trim($_SERVER['HTTP_X_MCP_PASS']);
}

$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 
set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

// 3. DYNAMICKÁ DETEKCE URL
require_once __DIR__ . '/detect_url.php';

/**
 * 4. DELEGOVÁNÍ (ROUTING):
 */
switch ($mode) {
	case 'info':
		ob_end_flush();
		header('Content-Type: text/html; charset=utf-8');
		require_once __DIR__ . '/info.php';
		break;

	case 'test':
		ob_end_flush();
		// Explicitně vnutíme HTML hlavičku, aby prohlížeč nezkoušel vyhodnotit výstup jako text/json
		header('Content-Type: text/html; charset=utf-8'); 
		require_once __DIR__ . '/test_exec.php';
		break;

	case 'main':
	default:
		ob_clean();
		require_once __DIR__ . '/main.php';
		break;
}