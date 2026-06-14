<?php
declare(strict_types=1);

/**
 * RamsesMcp - index.php (Front Controller & Router)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript je jediný vstupní bod (Entry Point) celé aplikace. Všechny požadavky
 * (z prohlížeče i od AI klienta) musí procházet přes tento soubor.
 * * * HLAVNÍ ÚKOLY:
 * 1. ROUTING: Směřuje požadavky na základě parametru `?mode=` v URL.
 * 2. CONFIG ORCHESTRATION: Načítá dynamický konfigurační soubor rcfg_*.php podle aliasu.
 * Přísně vyžaduje jeho existenci (žádný fallback) a přebíjí jej hodnotami z HTTP hlaviček.
 * 3. OUTPUT CONTROL: Zajišťuje integritu výstupu. Pro AI (režim 'main') je
 * kritické, aby výstup neobsahoval žádné PHP varování nebo náhodné mezery,
 * které by rozbily JSON formát.
 * * * PODPOROVANÉ REŽIMY (?mode=):
 * - mode=main : (Výchozí) Jádro pro JSON-RPC komunikaci s AI modely (Ollama, Claude).
 * - mode=info : Interaktivní HTML dashboard pro diagnostiku (prohlížeč).
 * - mode=test : AJAX endpoint pro spouštění testů z dashboardu.
 */

// 1. ZÁCHRANNÝ BUFFERING: Zachytí jakýkoliv nechtěný výstup (BOM, mezery, chyby v configu),
//    aby bylo možné jej v režimu 'main' vyčistit před odesláním JSON odpovědi.
ob_start();

// Zjištění režimu. Pokud parametr chybí, automaticky předpokládáme AI klienta.
$mode = $_GET['mode'] ?? 'main';

// Pro AI režim vypínáme zobrazování chyb v HTML formátu, chceme čisté logy.
if ($mode === 'main') {
	ini_set('display_errors', '0');
	error_reporting(E_ALL);
}

// 2. NAČTENÍ KONFIGURACE: Dynamická detekce instance na základě SERVER_NAME / HTTP_HOST.
$CONFIG_SERVER_NAME = $_SERVER['SERVER_NAME'] ?? '';
if (empty($CONFIG_SERVER_NAME) && isset($_SERVER['HTTP_HOST'])) {
	$CONFIG_SERVER_NAME = explode(':', $_SERVER['HTTP_HOST'])[0];
}

// Vyčištění názvu pro bezpečné vyhledání souboru na disku (odstranění nepovolených znaků)
$CONFIG_SERVER_NAME = preg_replace('/[^a-zA-Z0-9_.-]/', '', $CONFIG_SERVER_NAME);

// Pokud se nepodaří zjistit název serveru (např. z CLI bez parametrů), použijeme výchozí localhost
if ($CONFIG_SERVER_NAME === '') {
	$CONFIG_SERVER_NAME = 'localhost';
}

$configFile = __DIR__ . '/rcfg_' . $CONFIG_SERVER_NAME . '.php';

// Striktní ošetření chybějící multitenantní konfigurace bez fallbacku
if (!file_exists($configFile)) {
	if ($mode === 'main') {
		// Okamžitá JSON-RPC odpověď s chybou. ID je null, protože payload ještě nečteme.
		ob_clean();
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			"jsonrpc" => "2.0",
			"error" => [
				"code" => -32000,
				"message" => "Systémová chyba: Konfigurační soubor 'rcfg_{$CONFIG_SERVER_NAME}.php' na serveru neexistuje."
			],
			"id" => null
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	} else {
		// V režimu 'info' nebo 'test' vypíšeme srozumitelnou HTML chybu
		ob_end_clean();
		header('Content-Type: text/html; charset=utf-8');
		die("<div style='color: #d93025; font-family: sans-serif; padding: 20px;'><strong>Kritická chyba:</strong> Požadovaný konfigurační soubor <code>rcfg_{$CONFIG_SERVER_NAME}.php</code> nebyl na serveru nalezen.</div>");
	}
}

$config = require_once $configFile;

/**
 * DYNAMICKÝ PŘEPIS KONFIGURACE (Context Injection):
 * Klient (např. prohlížečové rozšíření Page Assist) může poslat vlastní parametry spojení.
 * Mapujeme standardní HTTP hlavičky (X-Mcp-*) do vnitřního pole $config.
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

// Nastavení include path pro případné externí knihovny v nadřazených složkách
$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 
set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

// 3. DYNAMICKÁ DETEKCE URL: Inkludujeme modul pro automatické zjištění Base URL serveru
require_once __DIR__ . '/detect_url.php';

/**
 * 4. DELEGOVÁNÍ (ROUTING):
 * Na základě zvoleného režimu inkludujeme příslušný logický soubor.
 */
switch ($mode) {
	case 'info':
		// V HTML režimu (Dashboard) případný balast z bufferu nevadí.
		ob_end_flush();
		require_once __DIR__ . '/info.php';
		break;

	case 'test':
		ob_end_flush();
		require_once __DIR__ . '/test_exec.php';
		break;

	case 'main':
	default:
		/**
		 * DESIGN DECISION (Integrita JSON-RPC):
		 * Před spuštěním main.php (AI komunikace) totálně vyčistíme výstupní buffer.
		 * Tím odstraníme jakékoliv mezery nebo PHP notice, které se mohly vygenerovat
		 * během načítání konfigurace. AI klient dostane 100% čistý JSON.
		 */
		ob_clean();
		require_once __DIR__ . '/main.php';
		break;
}