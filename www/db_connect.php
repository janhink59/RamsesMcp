<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_connect.php
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento soubor je nízkoúrovňovým základem celého projektu. Zajišťuje veškerou 
 * komunikaci s Microsoft SQL Serverem pomocí ovladače sqlsrv. Fyzické připojení 
 * se otevírá pod servisním účtem z konfigurace, zatímco logická identita uživatele 
 * se spravuje na úrovni databázových relací (SPID) pomocí procedur init_wwwsession a set_login.
 *
 * KLÍČOVÉ PRINCIPY:
 * 1. SINGLETON / SPID: Používáme globální proměnnou $GLOBALS['dbconnection']. To je kritické, 
 * protože MSSQL session (a identita nastavená přes set_login) je vázána na SPID (vlákno). 
 * Pokud bychom vytvořili nové spojení, ztratíme kontext přihlášeného uživatele.
 * 2. DETERMINISTICKÁ RELACE A AUTENTIZACE: 
 * a) Fyzická: sqlsrv_connect se připojuje pod servisním účtem (z configu).
 * b) Logická: Generuje se deterministický hash relace (Login + X-Forwarded-For + Datum).
 * c) Optimalizace: Před voláním set_login (který by resetoval stav/cache klienta) se volá 
 * init_wwwsession. Pokud relace běží, recykluje se stávající stav MSSQL.
 * 3. EXPOZICE STAVU PRO PHP NÁSTROJE: Vygenerované Session ID je dostupné v 
 * $GLOBALS['mcp_session_id'] pro potřeby PHP nástrojů (sdílená paměť, souborová cache).
 *
 * STRUKTURA GLOBÁLNÍ KONFIGURACE ($config):
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
 *
 * @global array $config         Musí obsahovat klíče ['db']['server'] a ['db']['options'].
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
 * Vytváří deterministické Session ID z IP adresy a jména. Před samotným přihlášením
 * kontroluje existenci relace pomocí init_wwwsession, aby nedocházelo k opakovanému
 * resetování cache a nastavení uživatele.
 *
 * @param string $user           Login uživatele (Administrator, hink, atd.)
 * @param string $password       Heslo v prostém textu (v DB se porovnává jeho MD5 hash).
 * @param string $ip             Výchozí auditní IP adresa (z REMOTE_ADDR).
 * @return string                Vrací vygenerované ID session (pro interní logování).
 * @throws Exception             Vyhazuje chybu, pokud set_login vrátí code < 0.
 */
function authenticateMcp(string $user, string $password, string $ip = '127.0.0.1'): string {
	// Získáme singleton spojení (fyzická vrstva)
	$conn = getMssqlConnection();
	
	// 1. Získání reálné IP adresy (zohlednění proxy / load balancerů)
	$realIp = $ip;
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		// Proxy může vrátit řetězec více IP adres oddělených čárkou (např. "client_ip, proxy_1_ip").
		// Nás zajímá ta první zleva, což je vždy původní klient.
		$ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$realIp = trim($ipList[0]);
	} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		$realIp = trim($_SERVER['HTTP_CLIENT_IP']);
	}

	// 2. Vytvoření deterministického identifikátoru relace (Session ID)
	// Spojením loginu, reálné IP a aktuálního dne (Y-m-d) zajistíme, 
	// že stejný člověk u stejného PC dostane po celý den naprosto stejné ID pro set_login.
	$hashInput = $user . '|' . $realIp . '|' . date('Y-m-d');
	// Prefix 'mcp_' a zkrácený hash (16 znaků) tvoří ideální řetězec dlouhý 20 znaků,
	// který se bezpečně vejde do standardních DB sloupců pro sessiony.
	$sessionID = 'mcp_' . substr(md5($hashInput), 0, 16);
	
	// Expozice Session ID pro PHP nástroje mimo databázi
	$GLOBALS['mcp_session_id'] = $sessionID;
	
	// 3. Kontrola, zda relace již není přihlášena (prevence resetu cache a nastavení)
	$sqlInit  = "EXEC init_wwwsession @wwwsession = ?";
	$stmtInit = sqlsrv_query($conn, $sqlInit, [$sessionID]);
	
	if ($stmtInit !== false) {
		$initResult = sqlsrv_fetch_array($stmtInit, SQLSRV_FETCH_ASSOC);
		
		// EXPLICITNÍ UKONČENÍ DOTAZU: Uvolníme paměť a cursory ihned po přečtení potřebných dat
		sqlsrv_free_stmt($stmtInit);
		
		// Pokud [code] >= 0, uživatel je již bezpečně přihlášen, kontext trvá, vracíme ID
		if ($initResult && isset($initResult['code']) && (int)$initResult['code'] >= 0) {
			return $sessionID;
		}
	}
	
	$pwdMd5 = md5($password);
	
	// 4. Volání aplikační logiky přihlášení v MSSQL (původní způsob při neexistující session)
	// Posíláme naši nalezenou reálnou IP i přímo do procedury jako auditní stopu.
	$sql    = "EXEC set_login @wwwsession = ?, @login = ?, @pwd = ?, @client_ip = ?, @application = ?";
	$params = [
		$sessionID, 
		$user, 
		$pwdMd5, 
		$realIp, 
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
		throw new Exception("Chyba nastavení kontextu uživatele (procedura set_login zamítla přístup): " . ($authResult['msg'] ?? 'Invalid user login or password'));
	}

	return $sessionID;
}