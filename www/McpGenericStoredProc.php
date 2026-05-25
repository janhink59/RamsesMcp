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

		// ARCHITEKTONICKÁ POJISTKA: 
		// Pokud procedura v DB není zkompilována s 'SET NOCOUNT ON', MSSQL server pošle informační 
		// zprávy typu "1 row affected" jako prázdné result sety. Tato smyčka je přeskakuje.
		while (!sqlsrv_has_rows($stmt)) {
			$next = sqlsrv_next_result($stmt);
			if ($next === false) {
				return $this->error("Chyba při posunu na další výsledek u {$procName}: " . print_r(sqlsrv_errors(), true));
			} elseif ($next === null) {
				break; // Konec výsledků, nenalezen žádný dataset
			}
		}
		
		// Agregace výsledku do TSV formátu pro maximální úsporu tokenů v AI kontextu
		$tsv     = "";                  // Finální textový řetězec obsahující TSV data
		$isFirst = true;                // Příznak pro prvotní zachycení hlavičky (názvy sloupců)
		$rowNum  = 1;                   // Počítadlo pro umělé číslované řádky napomáhající UI AI modelu
		
		if (sqlsrv_has_rows($stmt)) {
			while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
				if ($isFirst) {
					// Vložení hlavičky oddělené tabulátorem (klíče asociativního pole)
					// Injekce sloupce row_number na absolutně první pozici výstupní sady
					$headers = array_merge(['row_number'], array_keys($row));
					$tsv .= implode("\t", $headers) . "\n";
					$isFirst = false;
				}
				
				// Sanitize výstupu: zabránění rozbití TSV formátu nahrazením nepovolených znaků
				$rowStr = array_map(function($val) {
					if ($val instanceof DateTime) {
						return $val->format('Y-m-d H:i:s');
					}
					// Nahrazení nových řádků a tabulátorů uvnitř hodnot prostou mezerou
					return str_replace(["\r", "\n", "\t"], " ", (string)$val);
				}, $row);
				
				// Bezpečné vložení umělého čísla řádku na začátek datového proudu
				$finalRowValues = array_merge([(string)$rowNum], array_values($rowStr));
				
				$tsv .= implode("\t", $finalRowValues) . "\n";
				$rowNum++;
			}
		}
		
		// Ošetření stavu, kdy procedura proběhne úspěšně, ale nevrátí žádný výsledek
		if ($isFirst) {
			$tsv = "Žádná data nebyla nalezena.";
		} else {
			$tsv = "Nalezena data:\n" . $tsv;
		}
		
		return $this->success($tsv);
	}
}