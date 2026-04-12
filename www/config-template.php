<?php
declare(strict_types=1);

/**
 * Konfigurační soubor pro RamsesMsc MCP Server
 * V produkci je doporučeno načítat hesla z proměnných prostředí (getenv).
 */
return [
    'db' => [
        // Adresa serveru (první parametr pro sqlsrv_connect)
        'server' => 'localhost', 
        
        // Asociativní pole přesně tak, jak ho vyžaduje nativní ovladač sqlsrv (druhý parametr)
        'options' => [
            'Database'             => 'RamsesMscDB',
            'UID'                  => 'sa',
            'PWD'                  => 'TvojeSilneHeslo',
            'CharacterSet'         => 'UTF-8', 
            'ReturnDatesAsStrings' => true, // Zabrání problémům s serializací DateTime objektů v JSONu
        ]
    ],
    'auth' => [
        'bearer_token' => 'zde_vloz_tvuj_vygenerovany_nahodny_token_pro_mcp',
    ]
];