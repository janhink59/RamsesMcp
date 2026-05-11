<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_connect.php
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento soubor je nízkoúrovňovým základem celého projektu. Zajišťuje veškerou 
 * komunikaci s Microsoft SQL Serverem pomocí ovladače sqlsrv.
 * * * KLÍČOVÉ PRINCIPY:
 * 1. SINGLETON / SPID: Používáme globální proměnnou $GLOBALS['dbconnection']. To je kritické, 
 * protože MSSQL session (a identita nastavená přes set_login) je vázána na SPID (vlákno). 
 * Pokud bychom vytvořili nové spojení, ztratíme kontext přihlášeného uživatele.
 * 2. DVOUVRSTVÁ AUTENTIZACE: 
 * a) Fyzická: sqlsrv_connect se připojuje pod servisním účtem (z configu).
 * b) Logická: Procedura set_login nastaví v DB identity uživatele MCP klienta.
 * * * STRUKTURA GLOBÁLNÍ KONFIGURACE ($config):
 * [
 * 'db' => [
 * 'server'  => string, // IP/Host (přebíjeno hlavičkou X-Mcp-Dbserver)
 * 'options' => [
 * 'Database' => string, // Název DB (přebíjeno hlavičkou X-Mcp-Database)
 * 'UID'      => string, // Servisní login
 * 'PWD'      => string  // Servisní heslo
 * ]
 * ],
 * 'mcp' => [
 * 'user'     => string, // Login koncového uživatele (přebíjeno X-Mcp-User)
 * 'password' => string  // Heslo koncového uživatele (přebíjeno X-Mcp-Pass)
 * ]
 * ]
 */

/**
 * getMssqlConnection - Zajišťuje fyzické připojení k SQL Serveru.
 * * @global array $config         Musí obsahovat klíče ['db']['server'] a ['db']['options'].
 * @return resource              Vrací aktivní handler sqlsrv spojení.
 * @throws Exception             Rozlišuje chybu chybějící konfigurace a chybu spojení (síť/login).
 */
function getMssqlConnection() {
	global $config;

	// 1. Kontrola existence spojení v rámci aktuálního requestu (Singleton)
	if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
		return $GLOBALS['dbconnection'];
	}

	// 2. Validace přítomnosti konfigurace (vytvořeno v index.php)
	if (!isset($config['db'])) {
		throw new Exception("Kritická chyba: Globální konfigurace \$config['db'] nebyla nalezena. Skript musí běžet přes index.php.");
	}
	
	// 3. Pokus o fyzické připojení
	// Používají se parametry z config.php, které mohly být modifikovány HTTP hlavičkami v index.php.
	$conn = sqlsrv_connect($config['db']['server'], $config['db']['options']);

	if ($conn === false) {
		$errors = sqlsrv_errors();
		// Specifické hlášení pro AI/UI: Rozlišujeme fyzickou vrstvu (sqlsrv_connect)
		throw new Exception("Chyba fyzického připojení k databázi (sqlsrv_connect): " . json_encode($errors, JSON_UNESCAPED_UNICODE));
	}

	// Uložení do globálu pro budoucí volání ve stejném běhu skriptu
	$GLOBALS['dbconnection'] = $conn;
	$GLOBALS['dbms']         = 'sqlsrv';

	return $conn;
}

/**
 * authenticateMcp - Nastavuje identitu koncového uživatele v databázi.
 * * @param string $user           Login uživatele ( Administrator, hink, atd.)
 * @param string $password       Heslo v prostém textu (v DB se porovnává jeho MD5 hash).
 * @param string $ip             Auditní IP adresa klienta.
 * @return string                Vrací vygenerované ID session (pro interní logování).
 * @throws Exception             Vyhazuje chybu, pokud set_login vrátí code < 0.
 */
function authenticateMcp(string $user, string $password, string $ip = '127.0.0.1'): string {
	// Získáme singleton spojení (fyzická vrstva)
	$conn      = getMssqlConnection();
	$sessionID = 'mcp_' . uniqid();
	$pwdMd5    = md5($password);
	
	// Volání aplikační logiky přihlášení v MSSQL
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
		// Chyba v samotném SQL dotazu (např. chybějící procedura set_login)
		throw new Exception("Kritická chyba SQL při volání procedury set_login: " . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE));
	}

	// Procedura set_login vrací výsledek (SELECT) s kódem a zprávou
	$authResult = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
	
	if ($authResult && isset($authResult['code']) && (int)$authResult['code'] < 0) {
		// Logická chyba: špatné heslo, neexistující uživatel, zablokovaný účet.
		// AI tak okamžitě ví, že spojení funguje, ale uživatel neexistuje.
		throw new Exception("Chyba nastavení kontextu uživatele (procedura set_login zamítla přístup): " . ($authResult['msg'] ?? 'Invalid user login or password'));
	}

	return $sessionID;
}