<?php
declare(strict_types=1);

require_once __DIR__ . '/McpTool.php';

/**
 * Generická obálka pro spouštění jakékoliv neznámé uložené procedury v MSSQL
 * na základě definice v DB. Zajišťuje mapování parametrů, formátování 
 * výsledku do TSV a podporu pro vícenásobné sady výsledků (Multiple Result-Sets).
 */
class McpGenericStoredProc extends McpTool {
	
	private string $toolName;
	private array $definitions;

	/**
	 * @param resource $db        Aktivní spojení na MSSQL
	 * @param string $toolName    Název nástroje (např. 'set_organization')
	 * @param array  $definitions Definice parametrů načtená z db_interface
	 */
	public function __construct($db, string $toolName, array $definitions) {
		parent::__construct($db);
		$this->toolName    = $toolName;
		$this->definitions = $definitions;
	}

	/**
	 * Spustí uloženou proceduru a zpracuje její vícenásobné výsledky.
	 *
	 * @param array $params Asociativní pole vstupních parametrů.
	 * @return array JSON-RPC Content pole s textovou (TSV) zprávou.
	 */
	public function execute(array $params): array {
		// Validace a sestavení fyzického názvu procedury
		$safeToolName = preg_replace('/[^a-zA-Z0-9_]/', '', $this->toolName);
		$procName     = "mcp_tool_" . $safeToolName;
		
		$sqlParams = [];
		$execArgs  = [];
		
		// Mapování vstupů uživatele na definované SQL parametry
		foreach ($this->definitions as $def) {
			// Zde byla chyba! Původní regulární výraz odstraňoval podtržítka.
			// OPRAVA: [^a-zA-Z0-9_] zaručí, že názvy jako 'free_text' zůstanou nedotčené.
			$pName = preg_replace('/[^a-zA-Z0-9_]/', '', $def['param_name']);
			
			// Extrakce hodnoty, výchozí NULL, pokud není zadána
			$val = $params[$pName] ?? null;
			
			if ($val === null || $val === '') {
				// Pokud je parametr vyžadován (is_required), ale chybí, vracíme chybu rovnou.
				if (isset($def['is_required']) && $def['is_required']) {
					return $this->error("Chybí povinný parametr '{$pName}'.");
				}
				// Jinak předáváme NULL pro SQL, který jej zpracuje ve svém těle.
				$execArgs[] = "@{$pName} = NULL";
			} else {
				// Parametr je zadán, předáváme jeho obsah jako bezpečnou vázanou proměnnou (Bind parameter)
				$execArgs[]  = "@{$pName} = ?";
				
				// OPRAVA IMSSP -40: Explicitně ovladači říkáme, že $val je UTF-8 řetězec,
				// bez ohledu na to, jaké je výchozí kódování spojení nebo OS.
				$sqlParams[] = [
					$val, 
					SQLSRV_PARAM_IN, 
					SQLSRV_PHPTYPE_STRING('UTF-8')
				];
			}
		}

		$argsString = implode(", ", $execArgs);
		$sql = "EXEC {$procName} {$argsString}";

		// Exekuce dotazu (vč. vázaných parametrů proti SQL injection)
		$stmt = sqlsrv_query($this->db, $sql, $sqlParams);

		if ($stmt === false) {
			$errors = sqlsrv_errors();
			$errorString = print_r($errors, true);
			
			// OPRAVA KÓDOVÁNÍ: sqlsrv_errors vrací na českých Windows chybové zprávy v CP1250.
			// Pro úspěšný json_encode je musíme natvrdo převést do UTF-8.
			try {
				// ZMĚNA: Používáme 'CP1250' místo 'Windows-1250'
				$errorStringUtf8 = mb_convert_encoding($errorString, 'UTF-8', 'CP1250');
			} catch (ValueError $e) {
				// Bezpečnostní fallback, pokud by modul mbstring neznal identifikátor CP1250
				$errorStringUtf8 = iconv('CP1250', 'UTF-8//IGNORE', $errorString);
				if ($errorStringUtf8 === false) {
					$errorStringUtf8 = "Chyba DB (nelze prevest kodovani z CP1250 do UTF-8).";
				}
			}
			
			return $this->error("Chyba při provádění procedury {$procName}:\n" . $errorStringUtf8);
		}

		// Buffer pro kompletní výstup včetně případných více sad výsledků
		$tsvOutput = "";
		$resultSetIndex = 1;

		// Iterace přes všechny sady výsledků
		do {
			// Přeskočení případných prázdných result-setů (např. u pouhých UPDATE/INSERT)
			if (!sqlsrv_has_rows($stmt)) {
				continue;
			}

			// Načtení struktury hlaviček pro aktuální result-set
			$fieldMetadata = sqlsrv_field_metadata($stmt);
			if ($fieldMetadata === false) {
				continue;
			}

			$headers   = [];
			$blockName = null;

			// Projdeme všechny sloupce a detekujeme speciální systémový sloupec '__block_name'
			foreach ($fieldMetadata as $meta) {
				if (strtolower($meta['Name']) === '__block_name') {
					$blockName = true;
				} else {
					$headers[] = $meta['Name'];
				}
			}

			$rows = [];
			$currentBlockNameFromData = null;

			// Načtení dat z aktuální sady
			while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
				// Pokud je v datové sadě sloupec __block_name, využijeme jej jako nadpis pro klienta
				if ($blockName === true && isset($row['__block_name'])) {
					$currentBlockNameFromData = (string)$row['__block_name'];
				}
				
				$cleanRow = [];
				foreach ($headers as $h) {
					$cleanRow[] = $row[$h] ?? '';
				}
				$rows[] = $cleanRow;
			}

			if (empty($rows)) {
				continue; // Nalezeny sloupce, ale žádná data
			}

			// Určení názvu bloku (pokud není definován v datech, použije se číselné označení)
			$title = $currentBlockNameFromData ?: "Result Set {$resultSetIndex}";

			// Zápis hlavičky bloku pro db_interface.php (oddělovač ===)
			$tsvOutput .= "=== {$title} ===\n";
			
			// Zápis sloupců
			$tsvOutput .= implode("\t", $headers) . "\n";
			
			// Zápis datových řádků
			foreach ($rows as $r) {
				// Bezpečnostní náhrada řídících znaků za mezery, aby se nerozbilo TSV formátování
				$cleanStrings = array_map(function($val) {
					return str_replace(["\r", "\n", "\t"], " ", (string)$val);
				}, $r);
				$tsvOutput .= implode("\t", $cleanStrings) . "\n";
			}

			$resultSetIndex++;
			$tsvOutput .= "\n"; // Mezera mezi bloky pro vizuální oddělení
			
		} while (sqlsrv_next_result($stmt));

		sqlsrv_free_stmt($stmt);

		// Pokud nedošlo k žádné chybě, ale nic se nevrátilo (např. spuštěna void procedura)
		if (trim($tsvOutput) === '') {
			return $this->success("Procedura proběhla, ale nevrátila žádná data.");
		}

		return $this->success(trim($tsvOutput));
	}
}