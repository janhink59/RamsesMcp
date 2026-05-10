<?php
declare(strict_types=1);

/**
 * RamsesMcp - Hlavní vstupní bod (Front Controller / Router)
 * * Směruje požadavky na základě parametru ?mode= v URL.
 * Podporované režimy:
 * - mode=main : (Výchozí) Jádro pro zpracování standardních JSON-RPC MCP požadavků (Ollama).
 * - mode=info : Vrací interaktivní HTML dashboard s přehledem nástrojů pro zobrazení v běžném prohlížeči
 * - mode=test : Endpoint pro asynchronní spouštění testů z dashboardu "info".
 * * Globální kontext ($config):
 * Očekává se struktura z config.php, případně přepsaná HTTP hlavičkami (X-Mcp-*):
 * - ['db']['server']              (string) IP nebo název MSSQL serveru
 * - ['db']['options']['Database'] (string) Název cílové databáze
 * - ['mcp']['user']               (string) Přihlašovací jméno z hlavičky/konfigu
 * - ['mcp']['password']           (string) Heslo z hlavičky/konfigu
 */

// 1. Start bufferingu - zachytí mezery, BOM nebo chyby z configu
ob_start();

// Zjištění požadovaného režimu z URL. Pokud chybí, defaultuje na 'main'.
$mode = $_GET['mode'] ?? 'main';

// Nastavení error reportingu pro režim main - chceme čistý JSON, ne HTML chyby
if ($mode === 'main') {
	ini_set('display_errors', '0');
	error_reporting(E_ALL);
}

// 2. Načtení základní konfigurace do normální globální proměnné
// Očekává se, že config.php vrací pole (stejná struktura jako config-template.php)
$config = require_once __DIR__ . '/config.php';

// 3. Přepis konfigurace pomocí HTTP hlaviček z Page Assist (pokud jsou dostupné)
// V PHP se vlastní hlavičky X-Mcp-* mapují do pole $_SERVER s prefixem HTTP_X_MCP_*
if (isset($_SERVER['HTTP_X_MCP_DBSERVER']) && trim($_SERVER['HTTP_X_MCP_DBSERVER']) !== '') {
	$config['db']['server']              = trim($_SERVER['HTTP_X_MCP_DBSERVER']);  // Přepis nastavení MSSQL serveru
}

if (isset($_SERVER['HTTP_X_MCP_DATABASE']) && trim($_SERVER['HTTP_X_MCP_DATABASE']) !== '') {
	$config['db']['options']['Database'] = trim($_SERVER['HTTP_X_MCP_DATABASE']);  // Přepis nastavení cílové databáze
}

if (isset($_SERVER['HTTP_X_MCP_USER']) && trim($_SERVER['HTTP_X_MCP_USER']) !== '') {
	$config['mcp']['user']               = trim($_SERVER['HTTP_X_MCP_USER']);      // Přepis MCP uživatele (pro info/test)
}

if (isset($_SERVER['HTTP_X_MCP_PASS']) && trim($_SERVER['HTTP_X_MCP_PASS']) !== '') {
	$config['mcp']['password']           = trim($_SERVER['HTTP_X_MCP_PASS']);      // Přepis MCP hesla
}

// Nastavení základních cest pro případné sdílené knihovny
$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 

set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

// 4. Delegování na příslušný obslužný skript
switch ($mode) {
	case 'info':
		// Spláchnutí bufferu do výstupu (pro HTML dashboard je případný text neškodný)
		ob_end_flush();
		require_once __DIR__ . '/info.php';
		break;

	case 'test':
		ob_end_flush();
		require_once __DIR__ . '/test_exec.php';
		break;

	case 'main':
	default:
		// Kritický krok: Před přechodem do main.php smažeme veškerý textový balast z bufferu!
		ob_clean();
		require_once __DIR__ . '/main.php';
		break;
}