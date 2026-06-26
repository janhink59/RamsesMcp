<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_report_generic.php
 * Generický vykreslovač reportů. Zpracovává zobrazení reportů, které nemají
 * vlastní custom proxy skript. Výsledky čte buď jako `EXEC procedura`,
 * `SELECT [sloupce] FROM view WHERE... ORDER BY...` nebo `SELECT [sloupce] FROM funkcia(...) ORDER BY...`
 * (zohledňuje parametry z $_POST).
 */

// Předpokládáme, že $conn, $reportCode, $reportMeta a $parameters
// jsou dostupné z mcp_report.php, který tento soubor includuje.

if (!isset($reportCode) || !isset($conn)) {
	die("Chyba: Skript nelze volat napřímo. Musí být volán z mcp_report.php.");
}

// 1. Zjištění názvu procedury/view/funkce a metadat z číselníku
$procName = '';
$selectCols = '*';
$orderBy = '';
$sqlProc = "SELECT procedure_name, select_columns, order_by FROM mcp_report WHERE report_code = ?";
$stmtProc = sqlsrv_query($conn, $sqlProc, [$reportCode]);
if ($stmtProc && sqlsrv_has_rows($stmtProc)) {
	$rowProc = sqlsrv_fetch_array($stmtProc, SQLSRV_FETCH_ASSOC);
	$procName = trim((string)$rowProc['procedure_name']);
	$selectCols = trim((string)$rowProc['select_columns']);
	if ($selectCols === '') {
		$selectCols = '*';
	}
	$orderBy = trim((string)$rowProc['order_by']);
}
if ($procName === '') {
	$procName = 'mcp_report_' . $reportCode;
}

// 1.5 Načtení aliasů sloupců (Fallback / Override pattern)
$columnAliases = [];
// A) Globální aliasy
$sqlGlobal = "SELECT column_name, header_title FROM mcp_report_columns WHERE report_code = ''";
$stmtGlobal = sqlsrv_query($conn, $sqlGlobal);
if ($stmtGlobal) {
	while ($row = sqlsrv_fetch_array($stmtGlobal, SQLSRV_FETCH_ASSOC)) {
		$columnAliases[$row['column_name']] = $row['header_title'];
	}
}
// B) Specifické aliasy pro tento report (přepíší ty globální)
$sqlSpecific = "SELECT column_name, header_title FROM mcp_report_columns WHERE report_code = ?";
$stmtSpecific = sqlsrv_query($conn, $sqlSpecific, [$reportCode]);
if ($stmtSpecific) {
	while ($row = sqlsrv_fetch_array($stmtSpecific, SQLSRV_FETCH_ASSOC)) {
		$columnAliases[$row['column_name']] = $row['header_title'];
	}
}

// 2. Zjištění typu objektu v databázi (Procedura vs View vs Funkce)
$objType = 'SQL_STORED_PROCEDURE';
$sqlObj = "SELECT type_desc FROM sys.objects WHERE object_id = OBJECT_ID(?)";
$stmtObj = sqlsrv_query($conn, $sqlObj, [$procName]);
if ($stmtObj && sqlsrv_has_rows($stmtObj)) {
	$rowObj = sqlsrv_fetch_array($stmtObj, SQLSRV_FETCH_ASSOC);
	$objType = trim((string)$rowObj['type_desc']);
}

// 3. Sestavení finálního T-SQL dotazu
$sql = "";
if (strpos(strtoupper($objType), 'VIEW') !== false || strpos(strtoupper($objType), 'USER_TABLE') !== false) {
	// POHLED / TABULKA: Skládáme WHERE přesně z předaných parametrů a aplikujeme select a order
	$sql = "SELECT {$selectCols} FROM " . $procName;
	$where = [];
	foreach ($parameters as $pName => $pData) {
		if (isset($_POST[$pName]) && $_POST[$pName] !== '') {
			$val = $_POST[$pName];
			$litType = $pData['type']; // stripsl() z RamsesLib elegantně zkousne i string 'int', 'string' atd.
			
			if (is_array($val)) {
				// Sestavení dynamického IN (...)
				$escaped = [];
				foreach ($val as $v) {
					$escaped[] = stripsl($v, $litType);
				}
				$inList = implode(', ', $escaped);
				$where[] = "{$pName} IN ({$inList})";
			} else {
				$where[] = "{$pName} = " . stripsl($val, $litType);
			}
		}
	}
	if (!empty($where)) {
		$sql .= " WHERE " . implode(" AND ", $where);
	}
	if ($orderBy !== '') {
		$sql .= " ORDER BY " . $orderBy;
	}
} elseif (strpos(strtoupper($objType), 'FUNCTION') !== false) {
	// TABULKOVÁ FUNKCE: Načteme parametry z sys.parameters pro dodržení přesného pořadí signatury
	$funcArgs = [];
	$sqlArgs = "SELECT name FROM sys.parameters WHERE object_id = OBJECT_ID(?) AND parameter_id > 0 ORDER BY parameter_id";
	$stmtArgs = sqlsrv_query($conn, $sqlArgs, [$procName]);
	if ($stmtArgs) {
		while ($rowArg = sqlsrv_fetch_array($stmtArgs, SQLSRV_FETCH_ASSOC)) {
			$pNameWithAt = (string)$rowArg['name'];
			$pName = ltrim($pNameWithAt, '@');
			
			if (isset($_POST[$pName]) && $_POST[$pName] !== '') {
				$val = $_POST[$pName];
				$litType = isset($parameters[$pName]['type']) ? $parameters[$pName]['type'] : 'string';
				
				if (is_array($val)) {
					// Pokud je předáno pole do skalárního parametru funkce, spojíme prvky do CSV řetězce
					$csvVal = implode(',', $val);
					$funcArgs[] = stripsl($csvVal, $litType);
				} else {
					$funcArgs[] = stripsl($val, $litType);
				}
			} else {
				$funcArgs[] = "NULL";
			}
		}
	}
	
	// Sestavíme dotaz s aplikovaným select_columns
	$sql = "SELECT {$selectCols} FROM " . $procName . "(" . implode(", ", $funcArgs) . ")";
	
	// Aplikujeme order_by i na funkci
	if ($orderBy !== '') {
		$sql .= " ORDER BY " . $orderBy;
	}
	
} else {
	// ULOŽENÁ PROCEDURA: O parametry se postará sám SQL Server skrz aktuální @@SPID a kontext
	$sql = "SET NOCOUNT ON;\n\tEXEC " . $procName;
}

// 4. Vykonání dotazu (navázání na RamsesLib.php)
$dbquery = sqlrun($sql);
$htmlTables = "";

if ($dbquery !== false && $dbquery !== 1) {
	$tableCount = 0;
	
	// Cyklus pro vícero vrácených sad dat (multiple result-sets)
	do {
		$numFields = sqlsrv_num_fields($dbquery);
		if ($numFields === false || $numFields == 0) {
			continue; // Ignorujeme prázdné hlášky a skryté result-sety
		}
		
		$tableCount++;
		$htmlTables .= "<div class='table-responsive'>\n";
		$htmlTables .= "\t<table class='mcp-generic-table'>\n";
		
		$isFirstRow = true;
		$colNames = [];
		
		// Průchod daty
		while ($row = fetch($dbquery)) {
			// Při prvním řádku sestavíme <thead> na základě asociativních klíčů
			if ($isFirstRow) {
				$htmlTables .= "\t\t<thead>\n\t\t\t<tr>\n";
				foreach ($row as $colName => $value) {
					if (!is_numeric($colName)) { // Ramses fetch() vrací čísla i stringy
						$colNames[] = $colName;
						// Použití aliasu z databáze, nebo fallback na název sloupce
						$displayName = $columnAliases[$colName] ?? $colName;
						$htmlTables .= "\t\t\t\t<th>" . htmlspecialchars((string)$displayName) . "</th>\n";
					}
				}
				$htmlTables .= "\t\t\t</tr>\n\t\t</thead>\n\t\t\t<tbody>\n";
				$isFirstRow = false;
			}
			
			// Datové buňky
			$htmlTables .= "\t\t\t<tr>\n";
			foreach ($colNames as $colName) {
				$val = $row[$colName];
				if ($val === null || $val === '') {
					$htmlTables .= "\t\t\t\t<td class='null-cell'></td>\n";
				} else {
					$htmlTables .= "\t\t\t\t<td>" . htmlspecialchars((string)$val) . "</td>\n";
				}
			}
			$htmlTables .= "\t\t\t</tr>\n";
		}
		
		// Pokud sada neobsahovala žádná data (zcela prázdná tabulka), získáme alespoň hlavičky přes metadata
		if ($isFirstRow) {
			$metadata = sqlsrv_field_metadata($dbquery);
			if ($metadata) {
				$htmlTables .= "\t\t<thead>\n\t\t\t<tr>\n";
				foreach ($metadata as $field) {
					$colName = $field['Name'];
					$displayName = $columnAliases[$colName] ?? $colName;
					$htmlTables .= "\t\t\t\t<th>" . htmlspecialchars((string)$displayName) . "</th>\n";
				}
				$htmlTables .= "\t\t\t</tr>\n\t\t</thead>\n\t\t<tbody>\n";
				$htmlTables .= "\t\t\t<tr><td colspan='" . count($metadata) . "' class='empty-state'>Žádná data k zobrazení.</td></tr>\n";
			}
		} else {
			$htmlTables .= "\t\t</tbody>\n";
		}
		
		$htmlTables .= "\t</table>\n</div>\n";
		
	} while (next_result($dbquery, true)); // force=true vnutí přesun na další result-set
	
	if ($tableCount === 0) {
		$htmlTables .= "<div class='info-msg'>Dotaz proběhl úspěšně, ale nevrátil žádná tabulková data (žádné sloupce).</div>\n";
	}
} else {
	$htmlTables .= "<div class='error-msg'>Při vykonávání dotazu došlo k chybě. Otevřete log pro detaily.</div>\n";
}

// Před zahájením HTML výstupu vyčistíme případný buffer, aby chybová hlášení (Notice/Warning) 
// nenarušila JSON-RPC odpověď nadřazeného skriptu směrem k MCP klientovi.
if (ob_get_level() > 0) {
	ob_clean();
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title><?php echo htmlspecialchars($reportMeta['title'] ?? 'Generický Report'); ?> - RamsesMcp Report</title>
	<style>
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
		h1 { margin-top: 0; color: #1a202c; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
		.desc { color: #4a5568; font-size: 1.1rem; margin-bottom: 2rem; }
		
		/* Generická tabulka */
		.table-responsive { width: 100%; overflow-x: auto; margin-bottom: 2rem; border-radius: 8px; border: 1px solid #e2e8f0; }
		.mcp-generic-table { width: 100%; border-collapse: collapse; text-align: left; background: #fff; font-size: 0.95rem; }
		.mcp-generic-table th { background-color: #f7fafc; color: #2d3748; padding: 12px 16px; font-weight: 600; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
		.mcp-generic-table td { padding: 10px 16px; border-bottom: 1px solid #edf2f7; color: #4a5568; }
		.mcp-generic-table tr:hover td { background-color: #fbfbfb; }
		
		.mcp-generic-table .null-cell { color: #cbd5e0; text-align: center; }
		.mcp-generic-table .null-cell::after { content: '-'; font-style: normal; }
		.mcp-generic-table .empty-state { text-align: center; padding: 2rem; color: #a0aec0; font-style: italic; }
		
		.info-msg { padding: 15px; background: #ebf8ff; color: #2b6cb0; border-radius: 8px; border: 1px solid #bee3f8; }
		.error-msg { padding: 15px; background: #fed7d7; color: #c53030; border-radius: 8px; border: 1px solid #feb2b2; }
		
		.debug-sql { margin-top: 2rem; font-size: 0.85rem; color: #718096; }
		.debug-sql summary { cursor: pointer; padding: 5px; font-weight: bold; }
		.debug-sql pre { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow-x: auto; font-family: 'Cascadia Code', monospace; tab-size: 4; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php echo htmlspecialchars($reportMeta['title'] ?? 'Report'); ?></h1>
		<?php if (!empty($reportMeta['description'])): ?>
			<div class="desc"><?php echo nl2br(htmlspecialchars($reportMeta['description'])); ?></div>
		<?php endif; ?>
		
		<?php echo $htmlTables; ?>
		
		<details class="debug-sql">
			<summary>Zobrazit vykonaný T-SQL dotaz</summary>
			<pre><code><?php echo htmlspecialchars($sql); ?></code></pre>
		</details>
	</div>
</body>
</html>