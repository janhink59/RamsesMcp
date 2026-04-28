<?php
declare(strict_types=1);

/**
 * test_exec.php - Endpoint pro asynchronní testy z info.php.
 * Pøijímá POST data, provádí autentizaci a vrací HTML tabulku výsledku.
 */

require_once __DIR__ . '/db_interface.php';

try {
	$toolName = $_POST['tool_name'] ?? '';
	$toolArgs = $_POST['params'] ?? [];
	
	// Testovací údaje z konfigurace (fallback)
	$configPath = __DIR__ . '/config.php';
	if (!file_exists($configPath)) {
		throw new Exception("Konfiguraèní soubor config.php nebyl nalezen.");
	}
	$config = require $configPath;
	
	$user = $config['mcp']['test_user'] ?? '';
	$pass = $config['mcp']['test_password'] ?? '';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	if (empty($user) || empty($pass)) {
		throw new Exception("V config.php chybí údaje test_user a test_password nezbytné pro diagnostiku.");
	}

	// 1. Otevøení spojení s databází
	$dbi = new db_interface();
	
	// 2. Nastavení kontextu v DB (pøihlášení)
	$dbi->authenticate($user, $pass, $ip);

	// 3. Vykonání nástroje (internì provede validaci a kontrolu is_authenticated)
	$dbi->executeTool($toolName, $toolArgs);

	// Vracíme èisté HTML, které JavaScript vloží do pøíslušného divu
	echo $dbi->getResponseAsHtml();

} catch (Throwable $e) {
	echo "<div style='color:red; font-weight:bold; padding: 10px; background: #fff5f5; border: 1px solid #feb2b2; border-radius: 5px;'>Chyba pøi testu: " . htmlspecialchars($e->getMessage()) . "</div>";
}