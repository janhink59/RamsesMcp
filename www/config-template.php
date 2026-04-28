<?php
declare(strict_types=1);

/**
 * RamsesMcp - Konfigurační šablona
 * * Tento soubor slouží jako vzor pro config.php.
 * V nové verzi byla odstraněna sekce auth (Bearer token a IP adresy),
 * protože autentizace probíhá delegovaně na MSSQL server přes set_login.
 */

return [
	// Konfigurace databázového připojení (sqlsrv)
	'db' => [
		'server'  => 'localhost',					// Název serveru nebo IP adresa
		'options' => [
			'Database' => 'RamsesMsc',				// Název databáze
			'UID'      => 'mcp_service_account',	// Systémový účet pro fyzické připojení k DB
			'PWD'      => 'your_strong_password',	// Heslo systémového účtu
			'CharacterSet' => 'UTF-8',				// Kódování pro správné zobrazení češtiny
			'LoginTimeout' => 30,					// Timeout pro připojení
		],
	],

	// Nastavení MCP serveru
	'mcp' => [
		'name'            => 'RamsesMsc Server',	// Identifikace serveru v MCP listu
		'version'         => '2.0.0',				// Verze implementace
		
		/**
		 * TESTOVACÍ KONTEXT (info.php)
		 * Tyto údaje se použijí pouze v diagnostickém rozhraní info.php,
		 * nebo v testovacím režimu, pokud chybí Authorization hlavička.
		 */
		'test_user'       => 'Administrator',		// Výchozí login pro testování
		'test_password'   => 'admin123',			// Výchozí heslo pro testování
	],

	// Ladění a diagnostika
	'debug' => [
		'enabled'         => true,					// Zapne detailní výpisy chyb
		'log_path'        => __DIR__ . '/../logs/mcp_error.log', // Cesta k logovacímu souboru
	],
];