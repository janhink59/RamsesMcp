<?php
declare(strict_types=1);

/**
 * test_exec.php - Endpoint pro asynchronní testy z info.php.
 * Verze 2.5 - Oprava zalamování dlouhých JSON řetězců (pre-wrap).
 */

require_once __DIR__ . '/db_interface.php';

global $config; // Využíváme konfiguraci připravenou v index.php

try {
	$toolName = $_POST['tool_name'] ?? '';
	$toolArgs = $_POST['params'] ?? [];
	
	if (!isset($config['mcp'])) {
		throw new Exception("Konfigurace MCP nebyla nalezena.");
	}

	// Používáme standardní klíče 'user' a 'password' z naší šablony
	$user = $config['mcp']['user'] ?? '';
	$pass = $config['mcp']['password'] ?? '';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	if (empty($user) || empty($pass)) {
		throw new Exception("V konfiguraci chybí přihlašovací údaje (user/password).");
	}

	// 1. Inicializace DBI (využívá globální $config)
	$dbi = new db_interface();
	
	// 2. Nastavení kontextu v DB (set_login)
	$dbi->authenticate($user, $pass, $ip);

	// 3. Vykonání nástroje
	$dbi->executeTool($toolName, $toolArgs);

	// 4. Získání obou formátů výsledků
	$htmlTable = $dbi->getResponseAsHtml();
	$mcpJsonData = $dbi->getResponseAsMcpJson();

	// 5. Vytvoření simulované JSON-RPC obálky pro AI
	$jsonRpcResponse = [
		"jsonrpc" => "2.0",
		"id" => "test_" . substr(md5((string)time()), 0, 8),
		"result" => $mcpJsonData
	];
	
	$jsonString = json_encode($jsonRpcResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	// 6. Vrácení výsledku s vertikálním řazením a omezením šířky
	echo "<div style='display: flex; flex-direction: column; gap: 20px; margin-top: 15px; max-width: 100%; box-sizing: border-box;'>\n";
	
	// Horní sekce: HTML tabulka
	echo "\t<div style='background: #fdfdfd; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; width: 100%; box-sizing: border-box; overflow-x: auto;'>\n";
	echo "\t\t<h4 style='margin-top: 0; color: #2d3748; border-bottom: 1px solid #edf2f7; padding-bottom: 8px;'>Vizuální kontrola (HTML)</h4>\n";
	echo "\t\t" . $htmlTable . "\n";
	echo "\t</div>\n";
	
	// Spodní sekce: Surový JSON (Payload pro AI)
	// Zde je klíčová změna: přidáno white-space: pre-wrap; a word-wrap: break-word;
	echo "\t<div style='background: #fdfdfd; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; width: 100%; box-sizing: border-box;'>\n";
	echo "\t\t<h4 style='margin-top: 0; color: #2d3748; border-bottom: 1px solid #edf2f7; padding-bottom: 8px;'>Surový Payload pro AI (JSON-RPC)</h4>\n";
	echo "\t\t<pre style='background: #1a202c; color: #e2e8f0; padding: 15px; border-radius: 8px; font-family: \"Cascadia Code\", monospace; font-size: 0.85rem; tab-size: 4; margin-bottom: 0; white-space: pre-wrap; word-wrap: break-word;'><code>" . htmlspecialchars($jsonString) . "</code></pre>\n";
	echo "\t</div>\n";
	
	echo "</div>\n";

} catch (Throwable $e) {
	echo "<div style='color:red; font-weight:bold; padding: 10px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 5px; max-width: 100%;'>Chyba při testu: " . htmlspecialchars($e->getMessage()) . "</div>";
}