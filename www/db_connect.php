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
		// Změna: Místo tvrdého die() s JSONem vyhodíme výjimku.
		throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
	}

	// 1. Uložíme spojení a typ DB pro případné další použití
	$GLOBALS['dbconnection'] = $conn;
	$GLOBALS['dbms'] = 'sqlsrv';

	// 2. Příprava dotazu s bezpečnými parametry
	$contextUser = $config['db']['options']['APP'] ?? 'mcp_server';
	$sql = "execute debuglogin ?";
	$params = [$contextUser]; // Použito dynamicky z configu

	// 3. Nativní spuštění dotazu
	$stmt = sqlsrv_query($conn, $sql, $params);

	if ($stmt === false) {
		$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
		$GLOBALS['dbconnection'] = false;
		throw new Exception("Kritická chyba volání procedury debuglogin:\n" . print_r($sqlErrors, true));
	}

	$appErrorRows = [];

	// 4. Agresivní kontrola: Projdeme VŠECHNY výsledky z procedury
	do {
		while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
			$appErrorRows[] = $row;
		}
	} while (sqlsrv_next_result($stmt));

	// 5. Kontrola na tvrdé SQL chyby (vráceno zpět!)
	$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
	if (!empty($sqlErrors)) {
		$GLOBALS['dbconnection'] = false;
		throw new Exception("Kritická SQL chyba z debuglogin:\n" . print_r($sqlErrors, true));
	}

	// 6. Kontrola aplikačních chyb
	if (!empty($appErrorRows)) {
		$GLOBALS['dbconnection'] = false;
		throw new Exception("Aplikační chyba přihlášení (debuglogin):\n" . print_r($appErrorRows, true));
	}

	return $conn;
}