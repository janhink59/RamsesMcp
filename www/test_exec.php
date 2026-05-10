<?php
declare(strict_types=1);

/**
 * test_exec.php - Endpoint pro asynchronní testy z info.php.
 * Verze 2.2 - Plná integrace do globální konfigurace.
 */

require_once __DIR__ . '/db_interface.php';

global $config; // Využíváme konfiguraci pøipravenou v index.php

try {
	$toolName = $_POST['tool_name'] ?? '';
	$toolArgs = $_POST['params'] ?? [];
	
	if (!isset($config['mcp'])) {
		throw new Exception("Konfigurace MCP nebyla nalezena.");
	}

	// Používáme standardní klíèe 'user' a 'password' z naší šablony
	$user = $config['mcp']['user'] ?? '';
	$pass = $config['mcp']['password'] ?? '';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	if (empty($user) || empty($pass)) {
		throw new Exception("V konfiguraci chybí pøihlašovací údaje (user/password).");
	}

	// 1. Inicializace DBI (využívá globální $config)
	$dbi = new db_interface();
	
	// 2. Nastavení kontextu v DB (set_login)
	$dbi->authenticate($user, $pass, $ip);

	// 3. Vykonání nástroje
	$dbi->executeTool($toolName, $toolArgs);

	// 4. Vrácení HTML výsledku pro AJAX v info.php
	echo $dbi->getResponseAsHtml();

} catch (Throwable $e) {
	echo "<div style='color:red; font-weight:bold; padding: 10px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 5px;'>Chyba pøi testu: " . htmlspecialchars($e->getMessage()) . "</div>";
}