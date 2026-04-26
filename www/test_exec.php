<?php
declare(strict_types=1);

/**
 * test_exec.php - Endpoint pro asynchronní testy z info.php.
 * Pøijímá POST data a vrací HTML tabulku výsledku.
 */

require_once __DIR__ . '/db_interface.php';

try {
	$toolName = $_POST['tool_name'] ?? '';
	$toolArgs = $_POST['params'] ?? [];

	$dbi = new db_interface();
	
	// Vykonání nástroje (internì øeší validaci i exekuci procedury)
	$dbi->executeTool($toolName, $toolArgs);

	// Vracíme èisté HTML, které JavaScript vloží do pøíslušného divu
	echo $dbi->getResponseAsHtml();

} catch (Throwable $e) {
	echo "<div style='color:red; font-weight:bold;'>Chyba pøi testu: " . htmlspecialchars($e->getMessage()) . "</div>";
}