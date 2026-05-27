<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// RamsesDB - Nástroj pro sestavení databázových skriptů (PHP verze)
// -----------------------------------------------------------------------------

$manifestFile = 'build_list.txt';                               // Soubor se seznamem skriptů nebo masek (wildcards)
$outputFile   = '--McpDeploy.sql';                              // Výsledný sloučený soubor

echo "----------------------------------------------------------\n";
echo "RamsesDB Auto-Deploy Build\n";
echo "----------------------------------------------------------\n";

// 1. Kontrola existence manifestu
if (!file_exists($manifestFile)) {
	echo "[CHYBA] Soubor manifestu '{$manifestFile}' nebyl nalezen!\n";
	echo "Vytvorte soubor '{$manifestFile}' se seznamem SQL skriptu.\n";
	exit(1);
}

// 2. Příprava výstupního souboru
if (file_exists($outputFile)) {
	unlink($outputFile);                                        // Smazání předchozího buildu
}

$outHandle = fopen($outputFile, 'wb');
if (!$outHandle) {
	echo "[CHYBA] Nelze vytvorit vystupni soubor '{$outputFile}'.\n";
	exit(1);
}

echo "Sestavuji skript '{$outputFile}'...\n\n";

// 3. Vložení BOM (Byte Order Mark) pro UTF-8 na úplný začátek souboru
// Toto donutí SSMS automaticky otevřít soubor v UTF-8 kódování
fwrite($outHandle, "\xEF\xBB\xBF");

// 4. Zápis hlavičky (čisté ASCII)
$dateStr = date('Y-m-d H:i:s');
$header  = "/* ==========================================\n";
$header .= " * RamsesDB Auto-Deploy Build\n";
$header .= " * Vygenerovano {$dateStr}\n";
$header .= " * ========================================== */\n\n";
fwrite($outHandle, $header);

$fileCount = 0;

/**
 * Pomocná funkce pro bezpečné přečtení souboru, odstranění BOM z paměti
 * a konverzi z Windows-1250 do UTF-8 v případě nalezení 8bitové diakritiky.
 * * @param string $filePath Cesta k souboru
 * @param bool &$converted Indikátor, zda došlo ke konverzi kódování
 * @return string Obsah souboru v čistém UTF-8 bez BOM
 */
$processContent = function(string $filePath, bool &$converted): string {
	$content = file_get_contents($filePath);
	$converted = false;
	
	// Pokud řetězec začíná UTF-8 BOM, odřízneme první 3 bajty
	if (str_starts_with($content, "\xEF\xBB\xBF")) {
		$content = substr($content, 3);
	}
	
	// Kontrola platnosti UTF-8 sekvencí. Pokud selže, překódujeme přes nativní iconv.
	if (!mb_check_encoding($content, 'UTF-8')) {
		$content = iconv('WINDOWS-1250', 'UTF-8', $content);
		$converted = true;
	}
	
	return $content;
};

// 5. Zpracování manifestu a expanze zástupných znaků (wildcards)
$manifestLines = file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($manifestLines as $line) {
	$line = trim($line);
	
	// Ignorování komentářů v manifestu
	if (str_starts_with($line, '#')) {
		continue;
	}
	
	// Získání seznamu souborů na základě masky (např. "tools/mcp_*.sql" nebo "db_schema.sql")
	$files = glob($line);
	
	if ($files === false || count($files) === 0) {
		echo "[VAROVANI] Zadne soubory nenalezeny pro polozku/masku: '{$line}'\n";
		continue;
	}
	
	foreach ($files as $file) {
		if (is_file($file)) {
			// Přečtení obsahu s odříznutím BOM a validací/konverzí kódování
			$wasConverted = false;
			$cleanContent = $processContent($file, $wasConverted);
			
			// Výpis informace o zpracování
			$conversionInfo = $wasConverted ? " [konvertovano z Windows-1250]" : "";
			echo "Pridavam: {$file}{$conversionInfo}\n";
			
			// Zápis metadatového komentáře
			fwrite($outHandle, "/* --- Nacteno z: {$file}{$conversionInfo} --- */\n");
			
			// Zápis vyčištěného obsahu
			fwrite($outHandle, $cleanContent);
			
			// Vložení separátoru dávky pro MSSQL
			fwrite($outHandle, "\nGO\n\n");
			
			$fileCount++;
		}
	}
}

fclose($outHandle);

echo "\n==========================================================\n";
echo "HOTOVO! Bylo spojeno {$fileCount} souboru do '{$outputFile}'.\n";
echo "==========================================================\n";
