<?php
declare(strict_types=1);

/**
 * RamsesMcp - Hlavní vstupní bod (Front Controller / Router)
 * * Směruje požadavky na základě parametru ?mode= v URL.
 * Podporované režimy:
 * - mode=info : Vrací interaktivní HTML dashboard s přehledem nástrojů.
 * - mode=test : Endpoint pro asynchronní spouštění testů z dashboardu.
 * - mode=main : (Výchozí) Jádro pro zpracování standardních JSON-RPC MCP požadavků (Ollama).
 */

// Nastavení základních cest pro případné sdílené knihovny
$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 

set_include_path(get_include_path() . PATH_SEPARATOR . $parentDir);

// Zjištění požadovaného režimu z URL. Pokud chybí, defaultuje na 'main'.
$mode = $_GET['mode'] ?? 'main';

// Delegování na příslušný obslužný skript
switch ($mode) {
	case 'info':
		require_once __DIR__ . '/info.php';
		break;

	case 'test':
		require_once __DIR__ . '/test_exec.php';
		break;

	case 'main':
	default:
		// Původní logika z index.php zpracovávající JSON-RPC musí být přesunuta sem
		require_once __DIR__ . '/main.php';
		break;
}