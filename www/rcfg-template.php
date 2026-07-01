<?php
declare(strict_types=1);

/**
 * RamsesMcp - Konfigurační šablona
 * Tento soubor slouží jako vzor pro rcfg_*.php (případně config.php), které mají stejnou strukturu.
 * Řadu nastavení mohou upřesnit http hlavičky, které začínají prefixem "X-Mcp-"
 * Bez hlaviček se pracuje v testovacím režimu prohlížeče.
 */

return [
	// Konfigurace databázového připojení (sqlsrv), komentář v závorce je http hlavička, která nastavení může přebít
	'db' => [
		'server'  => 'localhost',					// Název serveru nebo IP adresa (X-Mcp-Dbserver)
		'options' => [
			'Database' => 'RamsesMsc',				// Název databáze (X-Mcp-Database)
			// Další údaje se hlavičkami nemodifikují, pokud má klient přístup k více databázím, platí všude stejné přístupové údaje
			'UID'      => 'mcp_service_account',	// Systémový účet pro fyzické připojení k DB
			'PWD'      => 'your_strong_password',	// Heslo systémového účtu
			'CharacterSet' => 'UTF-8',				// Kódování pro správné zobrazení češtiny
			'LoginTimeout' => 30,					// Timeout pro připojení
		],
	],

	// Nastavení MCP serveru, který musí nastavit kontext uživatele, protože tools mají využívat jeho prostředí

	'mcp' => [
		'name'            => 'RamsesMcp Server',	// Identifikace serveru v MCP listu
		'version'         => '2.0.0',				// Verze implementace
		
		/**
		 * URL Prefix pro reverzní proxy a subadresáře (Multitenancy)
		 * Používá se pro sestavení správné absolutní URL adresy (např. u odkazů na reporty).
		 * - Pokud je aplikace v rootu webu (https://hostname/), ponechte prázdné: ''
		 * - Pro intranet / subadresáře uveďte cestu s počátečním lomítkem (např.: '/ramses' nebo '/ramses/client1')
		 */
		'url_prefix'      => '/',
		'ollama_url'      => 'http://localhost:11434',
		
		/**
		 * Nastavení kontextu uživatele aplikace (info.php), ne každý má přístup ke všemu, k aplikaci se MCP server musí přihlásit.
		 * */
		'user'       => 'Administrator', // Výchozí login pro testování (X-Mcp-User)
		'password'   => 'admin123',      // Výchozí heslo pro testování (X-Mcp-Pass)
	],

	// Ladění a diagnostika
	'debug' => [
		'enabled'         => true,                               // Povoluje režim testování
		'log_path'        => __DIR__ . '/../logs/mcp_error.log', // Cesta k logovacímu souboru
	],
];