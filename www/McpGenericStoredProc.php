<?php
declare(strict_types=1);

/**
 * Zpracovává generické MCP nástroje voláním stejnojmenných uložených procedur.
 * Tento přístup umožňuje rychlé přidávání nových funkcí bez nutnosti psát
 * pro každou z nich samostatnou PHP třídu, pokud stačí standardní SQL exekuce.
 */
class McpGenericStoredProc extends McpTool {
	
	private string $toolName;       // Název nástroje z databáze (slouží pro odvození názvu procedury)
	private array  $definitions;    // Definice parametrů nástroje načtené z tabulky mcp_tool_param

	/**
	 * Konstruktor rozšířený o název nástroje a jeho parametry.
	 *
	 * @param resource $db          Aktivní spojení na MSSQL přes sqlsrv_connect
	 * @param string   $toolName    Název volaného nástroje
	 * @param array    $definitions Struktura očekávaných parametrů pro validaci
	 */
	public function __construct($db, string $toolName, array $definitions) {
		parent::__construct($db);
		$this->toolName    = $toolName;
		$this->definitions = $definitions;
	}

	/**
	 * Sestaví SQL příkaz EXEC pro dynamické volání uložené procedury.
	 * Parametry jsou bezpečně předány přes nativní binding sqlsrv.
	 * Zpracovává libovolné množství vrácených result-setů.
	 *
	 * @param array<string, mixed> $params  Vstupní argumenty od klienta (Ollamy)
	 * @return array                        Formátovaná JSON-RPC odpověď s TSV obsahem
	 */
	public function execute(array $params): array {
		// Bezpečné ošetření názvu procedury proti injection (povoleny pouze alfanumerické znaky a podtržítka)
		$procName = "mcp_tool_" . preg_replace('/[^a-zA-Z0-9_]/', '', $this->toolName);
		
		$sqlParams = [];                // Pole fragmentů pro EXEC příkaz (např. "@param = ?")
		$sqlArgs   = [];                // Skutečné hodnoty pro nativní binding parametrizovaného dotazu
		
		// Sestavení parametrů a ošetření specifických datových typů dle definice
		foreach ($this->definitions as $def) {
			$pName = $def['param_name'];
			$val   = $params[$pName] ?? null;
			
			// Pokud parametr není vyplněn, explicitně posíláme NULL (procedura to musí podporovat)
			if ($val === null || $val === '') {
				$sqlParams[] = "@{$pName} = NULL";
			} else {
				// Požadavek: transformace UUID - odstranění pomlček a překlad na binární literál 0x...
				if ($def['param_type'] === 'uuid') {
					$hex = str_replace('-', '', $val);
					$sqlParams[] = "@{$pName} = 0x{$hex}";
				} else {
					// Standardní parametrizovaný dotaz pro ostatní typy (string, number)
					$sqlParams[] = "@{$pName} = ?";
					$sqlArgs[]   = $val;
				}
			}
		}
		
		// Finální sestavení SQL dotazu pro vyvolání procedury
		$sql = "EXEC " . $procName . (!empty($sqlParams) ? " " . implode(', ', $sqlParams) : "");
		
		$stmt = sqlsrv_query($this->db, $sql, $sqlArgs);
		
		if ($stmt === false) {
			return $this->error("Chyba při provádění procedury {$procName}: " . print_r(sqlsrv_errors(), true));
		}

		$finalOutput  = "";             // Agregovaný textový výstup všech bloků
		$dataSetCount = 0;              // Počítadlo nalezených datových bloků
		$next         = true;           // Řídící proměnná pro posun kurzoru
		
		// ARCHITEKTONICKÁ POJISTKA: 
		// Smyčka iteruje přes všechny dostupné výsledkové sady (result sets).
		// Ignoruje prázdné zprávy ("1 row affected") díky kontrole sqlsrv_has_rows.
		do {
			if (sqlsrv_has_rows($stmt)) {
				$tsv       = "";        // Textový buffer pro aktuální blok
				$isFirst   = true;      // Příznak prvního řádku (hlavička)
				$rowNum    = 1;         // Počítadlo umělých řádků, resetuje se pro každý blok
				$blockName = null;      // Tag nadpisu aktuálního bloku
				
				while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
					if ($isFirst) {
						$headers = array_keys($row);
						
						// Detekce a zpracování speciálního řídícího sloupce
						if (array_key_exists('__block_name', $row)) {
							$blockName = (string)$row['__block_name'];
							// Vyřazení řídícího sloupce, aby se nedostal do datové struktury
							$headers = array_filter($headers, fn($k) => $k !== '__block_name');
						}
						
						// Injekce umělého identifikátoru na první pozici pro AI modely
						$headers = array_merge(['row_number'], array_values($headers));
						
						// Aplikace vizuálního oddělovače bloku pro kontext
						if ($blockName !== null && $blockName !== '') {
							$tsv .= "=== " . $blockName . " ===\n";
						} elseif ($dataSetCount > 0) {
							// Nouzový záchyt, pokud procedura vrací více bloků bez popisku
							$tsv .= "=== RESULT_SET_" . ($dataSetCount + 1) . " ===\n";
						}
						
						$tsv .= implode("\t", $headers) . "\n";
						$isFirst = false;
					}
					
					// Zpracování dat s ignorováním řídících informací
					$rowStr = [];
					foreach ($row as $key => $val) {
						if ($key === '__block_name') continue;
						
						if ($val instanceof DateTime) {
							$rowStr[] = $val->format('Y-m-d H:i:s');
						} else {
							// Bezpečná sanitizace: blokace rozbití TSV rozložení
							$rowStr[] = str_replace(["\r", "\n", "\t"], " ", (string)$val);
						}
					}
					
					// Finalizace datového řádku 
					$finalRowValues = array_merge([(string)$rowNum], $rowStr);
					$tsv .= implode("\t", $finalRowValues) . "\n";
					$rowNum++;
				}
				
				// Sestavení celkového formátu za sebou
				if (!$isFirst) {
					if ($dataSetCount > 0) {
						$finalOutput .= "\n"; 
					}
					$finalOutput .= $tsv;
					$dataSetCount++;
				}
			}
			
			// Posun na další result-set a kontrola chyb
			$next = sqlsrv_next_result($stmt);
			if ($next === false) {
				return $this->error("Chyba při posunu na další výsledek u {$procName}: " . print_r(sqlsrv_errors(), true));
			}
		} while ($next !== null);
		
		// Finální fallback výstup, pokud se nenačetla z žádného bloku data
		if ($dataSetCount === 0) {
			$finalOutput = "Žádná data nebyla nalezena.";
		} else {
			$finalOutput = trim($finalOutput);
		}
		
		return $this->success($finalOutput);
	}
}