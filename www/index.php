<?php
declare(strict_types=1);

/**
 * RamsesMcp - index.php (Front Controller & Router)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript je jediný vstupní bod (Entry Point) celé aplikace. Všechny požadavky
 * (z prohlížeče i od AI klienta) musí procházet přes tento soubor.
 * * * HLAVNÍ ÚKOLY:
 * 1. ROUTING: Směřuje požadavky na základě parametru `?mode=` v URL.
 * 2. CONFIG ORCHESTRATION: Načítá statický soubor config.php a dynamicky jej 
 * přebíjí hodnotami z HTTP hlaviček (X-Mcp-*). To umožňuje bezpečné 
 * přepínání databází/uživatelů přímo z klienta (např. Page Assist).
 * 3. OUTPUT CONTROL: Zajišťuje integritu výstupu. Pro AI (režim 'main') je 
 * kritické, aby výstup neobsahoval žádné PHP varování nebo náhodné mezery, 
 * které by rozbily JSON formát.
 * * * PODPOROVANÉ REŽIMY (?mode=):
 * - mode=main : (Výchozí) Jádro pro JSON-RPC komunikaci s AI modely (Ollama, Claude).
 * - mode=info : Interaktivní HTML dashboard pro diagnostiku (prohlížeč).
 * - mode=test : AJAX endpoint pro spouštění testů z dashboardu.
 */

// 1. ZÁCHRANNÝ BUFFERING: Zachytí jakýkoliv nechtěný výstup (BOM, mezery, chyby v configu),
// aby bylo možné jej v režimu 'main' vyčistit před odesláním JSON odpovědi.
ob_start();

// Zjištění režimu. Pokud parametr chybí, automaticky předpokládáme AI klienta.
$mode = $_GET['mode'] ?? 'main';

// Pro AI režim vypínáme zobrazování chyb v HTML formátu, chceme čisté logy.
if ($mode === 'main') {
	ini_set('display_errors', '0');
	error_reporting(E_ALL);
}

// 2. NAČTENÍ KONFIGURACE: Jediné přípustné místo pro require 'config.php'.
// Výsledek je uložen do globální proměnné, kterou využívají db_connect a db_interface.
$config = require_once __DIR__ . '/config.php';

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