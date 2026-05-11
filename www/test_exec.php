<?php
declare(strict_types=1);

/**
 * RamsesMcp - test_exec.php (AJAX Test Executor)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento soubor slouží jako asynchronní (AJAX) brána pro spouštění testů 
 * přímo z dashboardu info.php. 
 * * * KRITICKÁ ZÁVISLOST (ROUTING):
 * Tento skript je navržen tak, aby byl inkludován z index.php v režimu '?mode=test'.
 * Pokud by byl volán přímo (test_exec.php), selže, protože nebude mít přístup 
 * k naplněné globální proměnné $config ani k nastaveným include cestám.
 * * * IDENTITY FLOW:
 * Skript přebírá identitu (uživatele/heslo/server) z globálního $config, 
 * což znamená, že testy běží přesně pod tím kontextem, který je aktuálně 
 * nastaven v prohlížeči (včetně případných X-Mcp-* hlaviček).
 */

require_once __DIR__ . '/db_interface.php';

/** @global array $config Globální konfigurace připravená routerem index.php */
global $config;

try {
	// 1. EXTRAKCE VSTUPŮ Z AJAXU
	// Názvy klíčů odpovídají atributům 'name' ve formulářích v info.php
	$toolName = $_POST['tool_name'] ?? '';
	$toolArgs = $_POST['params'] ?? [];
	
	// Kontrola, zda router správně předal konfiguraci
	if (!isset($config['mcp'])) {
		throw new Exception("Konfigurace MCP nebyla nalezena. Skript musí běžet přes index.php.");
	}

	// 2. PŘÍPRAVA IDENTITY
	// Používáme standardní klíče 'user' a 'password', které mohly být 
	// dynamicky přepsány v index.php pomocí HTTP hlaviček.
	$user = $config['mcp']['user'] ?? '';
	$pass = $config['mcp']['password'] ?? '';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	if (empty($user) || empty($pass)) {
		throw new Exception("V konfiguraci chybí přihlašovací údaje (user/password).");
	}

	/**
	 * 3. EXEKUČNÍ ŘETĚZEC (Orchestrace):
	 * a) Inicializace DBI (vytvoří singleton fyzické DB spojení).
	 * b) Autentizace (zavolá set_login pro nastavení logického kontextu v MSSQL).
	 * c) Provedení nástroje (vyhledá SQL proceduru nebo PHP třídu).
	 */
	$dbi = new db_interface();
	
	// Provedeme logickou autentizaci (set_login) v DB pro aktuální SPID
	$dbi->authenticate($user, $pass, $ip);

	// Spuštění samotné logiky nástroje
	$dbi->executeTool($toolName, $toolArgs);

	/**
	 * 4. VRÁCENÍ VÝSLEDKU:
	 * DESIGN DECISION: Pro potřeby info.php vracíme data formátovaná jako 
	 * čistou HTML tabulku. DBI se postará o sanitaci i formátování.
	 */
	echo $dbi->getResponseAsHtml();

} catch (Throwable $e) {
	// Ošetření chyb tak, aby se v dashboardu zobrazily v červeném boxu
	echo "<div style='color:red; font-weight:bold; padding: 10px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 5px;'>Chyba při testu: " . htmlspecialchars($e->getMessage()) . "</div>";
}