<?php
declare(strict_types=1);

/**
 * RamsesMcp - Centrální správa databázového připojení a autentizace.
 * Verze 2.2 - Striktní využití globální konfigurace $config.
 * * KONTEXT: Tento soubor je inkludován v rámci index.php (přímo nebo skrze info.php).
 * Veškerá práce s konfiguračními soubory a jejich modifikace skrze HTTP hlavičky
 * (X-Mcp-Dbserver, X-Mcp-Database atd.) proběhla v index.php. 
 * Zde již nesmí dojít k opětovnému načítání config.php.
 */

/**
 * Vytvoří a vrátí spojení do MSSQL databáze (Singleton).
 * Využívá globální proměnnou $config, která již v sobě nese případné přepisy z hlaviček.
 * * @return resource                     Aktivní sqlsrv spojení
 * @throws Exception                    Při selhání připojení nebo pokud konfigurace neexistuje
 */
function getMssqlConnection() {
	global $config; // Předpokládáme existenci globálního pole z index.php

	// 1. Singleton - pokud už spojení existuje v rámci tohoto požadavku, vrátíme ho
	if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
		return $GLOBALS['dbconnection'];
	}

	// 2. Validace přítomnosti konfigurace
	if (!isset($config['db'])) {
		throw new Exception("Kritická chyba: Globální konfigurace \$config nebyla nalezena. Skript musí být spuštěn přes index.php.");
	}
	
	// 3. Fyzické připojení k MSSQL
	// Používáme server a options (Database, UID, PWD atd.) přímo z globálního pole
	$conn = sqlsrv_connect($config['db']['server'], $config['db']['options']);

	if ($conn === false) {
		$errors = sqlsrv_errors();
		throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
	}

	// Uložení spojení pro další volání v rámci téže exekuce (zachování SPID v DB)
	$GLOBALS['dbconnection'] = $conn;
	$GLOBALS['dbms']         = 'sqlsrv';

	return $conn;
}

/**
 * Provede autentizaci uživatele proti databázi pomocí procedury set_login.
 * * @param string $user                  Login z MCP klienta (předaný z main.php/info.php)
 * @param string $password              Heslo (předané z main.php/info.php)
 * @param string $ip                    IP adresa pro auditní záznam v DB
 * @return string                       Vrací ID session (pro interní logování)
 * @throws Exception                    Při neúspěšné autentizaci
 */
function authenticateMcp(string $user, string $password, string $ip = '127.0.0.1'): string {
	$conn      = getMssqlConnection();
	$sessionID = 'mcp_' . uniqid();
	$pwdMd5    = md5($password);
	
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

	$authResult = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
	
	if ($authResult && isset($authResult['code']) && (int)$authResult['code'] < 0) {
		throw new Exception("Autentizace selhala: " . ($authResult['msg'] ?? 'Neznámá chyba'));
	}

	return $sessionID;
}