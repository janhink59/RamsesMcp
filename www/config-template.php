<?php
declare(strict_types=1);

/**
 * Konfigurační soubor pro RamsesMcp Server
 * V produkci je doporučeno načítat hesla z proměnných prostředí (getenv).
 */
return [
	'db' => [
		// Adresa serveru (první parametr pro sqlsrv_connect)
		'server' => 'localhost', 
		
		// Asociativní pole přesně tak, jak ho vyžaduje nativní ovladač sqlsrv (druhý parametr)
		'options' => [
			'Database'             => 'RamsesMcpDB',
			'UID'                  => 'sa',
			'PWD'                  => 'TvojeSilneHeslo',
			'CharacterSet'         => 'UTF-8', 
			'ReturnDatesAsStrings' => true,         // Zabrání problémům se serializací DateTime objektů v JSONu
			'APP'                  => 'Ramses', // Interní název uživatele pro nastavení kontextu (sp_set_session_context)
		]
	],
	
	'auth' => [
		'disabled' => true;
		'bearer_token' => 'zde_vloz_tvuj_vygenerovany_nahodny_token_pro_mcp',
	]
];