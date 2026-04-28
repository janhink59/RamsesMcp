<?php
declare(strict_types=1);

/**
 * RamsesMcp - Centrální správa databázového připojení a autentizace.
 * Verze 2.0 - Autentizace delegována na MSSQL proceduru set_login.
 */

/**
 * Vytvoří a vrátí spojení do MSSQL databáze (Singleton).
 * * @return resource                     Aktivní sqlsrv spojení
 * @throws Exception                    Při selhání připojení
 */
function getMssqlConnection() {
	if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
		return $GLOBALS['dbconnection'];
	}

	$configPath = __DIR__ . '/config.php';
	if (!file_exists($configPath)) {
		throw new Exception("Konfigurační soubor config.php nebyl nalezen.");
	}
	
	$config = require $configPath;
	$conn   = sqlsrv_connect($config['db']['server'], $config['db']['options']);

	if ($conn === false) {
		$errors = sqlsrv_errors();
		throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
	}

	// Uložení spojení do globálního kontextu pro zachování SPID
	$GLOBALS['dbconnection'] = $conn;
	$GLOBALS['dbms']         = 'sqlsrv';

	return $conn;
}

/**
 * Provede autentizaci uživatele proti databázi pomocí procedury set_login.
 * * @param string $user                  Přihlašovací jméno (login)
 * @param string $password              Heslo v prostém textu
 * @param string $ip                    IP adresa klienta pro logování v DB
 * @return string                       Vrací vygenerované ID session
 * @throws Exception                    Při neúspěšné autentizaci
 */
function authenticateMcp(string $user, string $password, string $ip = '127.0.0.1'): string {
	$conn      = getMssqlConnection();
	$sessionID = 'mcp_' . uniqid();
	$pwdMd5    = md5($password);
	
	// Parametrizované volání bezpečné proti SQL Injection
	$sql    = "EXEC set_login @wwwsession = ?, @login = ?, @pwd = ?, @client_ip = ?, @application = ?";
	$params = [
		$sessionID, 
		$user, 
		$pwdMd5, 
		$ip, 
		'Ramses MCP Server'
	];

	$stmt = sqlsrv_query($conn, $sql, $params);

	if ($stmt === false) {
		throw new Exception("Kritická chyba volání procedury set_login: " . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE));
	}

	// Kontrola výsledku procedury na aplikační chyby
	$authResult = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
	
	if ($authResult && isset($authResult['code']) && (int)$authResult['code'] < 0) {
		throw new Exception("Autentizace selhala: " . ($authResult['msg'] ?? 'Neznámá chyba'));
	}

	return $sessionID;
}