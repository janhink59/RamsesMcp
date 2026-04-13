<?php
/**
 * RamsesMcp - Centrální správa databázového připojení
 */

function getMssqlConnection() {
	if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
		return $GLOBALS['dbconnection'];
	}

	$config = require __DIR__ . '/config.php';

	$conn = sqlsrv_connect($config['db']['server'], $config['db']['options']);

	if ($conn === false) {
		$errors = sqlsrv_errors();
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}
		die(json_encode([
			"jsonrpc" => "2.0",
			"error" => [
				"code"    => -32000, 
				"message" => "Database connection failed",
				"details" => $errors
			],
			"id" => null
		], JSON_UNESCAPED_UNICODE));
	}

	// 1. Uložíme spojení a typ DB pro případné další použití
	$GLOBALS['dbconnection'] = $conn;
	$GLOBALS['dbms'] = 'sqlsrv';

	// --- POMOCNÁ FUNKCE PRO ZASTAVENÍ S UTF-8 HLAVIČKOU ---
	$dieWithError = function($message) {
		$GLOBALS['dbconnection'] = false;
		if (!headers_sent()) {
			header('Content-Type: text/plain; charset=utf-8');
		}
		die($message);
	};

	// 2. Příprava dotazu s bezpečnými parametry
	$contextUser = $config['db']['options']['APP'] ?? 'mcp_server';
	$sql = "execute debuglogin ?";
	$params = ['mcp_server'];

	// 3. Nativní spuštění dotazu
	$stmt = sqlsrv_query($conn, $sql, $params);

	if ($stmt === false) {
		$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
		$dieWithError("Kritická chyba volání procedury debuglogin:\n" . print_r($sqlErrors, true));
	}

	$appErrorRows = [];

	// 4. Agresivní kontrola: Projdeme VŠECHNY výsledky z procedury
	do {
		while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
			$appErrorRows[] = $row;
		}
	} while (sqlsrv_next_result($stmt));

	// 5. Kontrola na tvrdé SQL chyby
	$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
	if (!empty($sqlErrors)) {
		$dieWithError("Kritická SQL chyba z debuglogin:\n" . print_r($sqlErrors, true));
	}

	// 6. Kontrola aplikačních chyb
	if (!empty($appErrorRows)) {
		$dieWithError("Aplikační chyba přihlášení (debuglogin):\n" . print_r($appErrorRows, true));
	}

	return $conn;
}