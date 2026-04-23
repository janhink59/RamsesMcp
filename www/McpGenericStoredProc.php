<?php
declare(strict_types=1);

/**
 * Zpracovává generické MCP nástroje voláním stejnojmenných uložených procedur.
 * Tento pøístup umožòuje rychlé pøidávání nových funkcí bez nutnosti psát
 * pro každou z nich samostatnou PHP tøídu, pokud staèí standardní SQL exekuce.
 */
class McpGenericStoredProc extends McpTool {
	
	private string $toolName;       // Název nástroje z databáze (slouží pro odvození názvu procedury)
	private array  $definitions;    // Definice parametrù nástroje naètené z tabulky mcp_tool_param

	/**
	 * Konstruktor rozšíøený o název nástroje a jeho parametry.
	 * * @param resource $db          Aktivní spojení na MSSQL pøes sqlsrv_connect
	 * @param string   $toolName    Název volaného nástroje
	 * @param array    $definitions Struktura oèekávaných parametrù pro validaci
	 */
	public function __construct($db, string $toolName, array $definitions) {
		parent::__construct($db);
		$this->toolName    = $toolName;
		$this->definitions = $definitions;
	}

	/**
	 * Sestaví SQL pøíkaz EXEC pro dynamické volání uložené procedury.
	 * Parametry jsou bezpeènì pøedány pøes nativní binding sqlsrv.
	 * * @param array<string, mixed> $params  Vstupní argumenty od klienta (Ollamy)
	 * @return array                        Formátovaná JSON-RPC odpovìï s TSV obsahem
	 */
	public function execute(array $params): array {
		// Bezpeèné ošetøení názvu procedury proti injection (povoleny pouze alfanumerické znaky a podtržítka)
		$procName = "mcp_tool_" . preg_replace('/[^a-zA-Z0-9_]/', '', $this->toolName);
		
		$sqlParams = [];                // Pole fragmentù pro EXEC pøíkaz (napø. "@param = ?")
		$sqlArgs   = [];                // Skuteèné hodnoty pro nativní binding parametrizovaného dotazu
		
		// Sestavení parametrù a ošetøení specifických datových typù dle definice
		foreach ($this->definitions as $def) {
			$pName = $def['param_name'];
			$val   = $params[$pName] ?? null;
			
			// Pokud parametr není vyplnìn, explicitnì posíláme NULL (procedura to musí podporovat)
			if ($val === null || $val === '') {
				$sqlParams[] = "@{$pName} = NULL";
			} else {
				// Požadavek: transformace UUID - odstranìní pomlèek a pøeklad na binární literál 0x...
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
		$sql = "EXEC " . $procName . " " . implode(', ', $sqlParams);
		
		$stmt = sqlsrv_query($this->db, $sql, $sqlArgs);
		
		if ($stmt === false) {
			return $this->error("Chyba pøi provádìní procedury {$procName}: " . print_r(sqlsrv_errors(), true));
		}
		
		// Agregace prvního result setu do TSV formátu pro maximální úsporu tokenù v AI kontextu
		$tsv     = "";                  // Finální textový øetìzec obsahující TSV data
		$isFirst = true;                // Pøíznak pro prvotní zachycení hlavièky (názvy sloupcù)
		
		while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
			if ($isFirst) {
				// Vložení hlavièky oddìlené tabulátorem (klíèe asociativního pole)
				$tsv .= implode("\t", array_keys($row)) . "\n";
				$isFirst = false;
			}
			
			// Sanitize výstupu: zabránìní rozbití TSV formátu nahrazením nepovolených znakù
			$rowStr = array_map(function($val) {
				if ($val instanceof DateTime) {
					return $val->format('Y-m-d H:i:s');
				}
				// Nahrazení nových øádkù a tabulátorù uvnitø hodnot prostou mezerou
				return str_replace(["\r", "\n", "\t"], " ", (string)$val);
			}, $row);
			
			$tsv .= implode("\t", $rowStr) . "\n";
		}
		
		// Ošetøení stavu, kdy procedura probìhne úspìšnì, ale nevrátí žádný výsledek
		if ($isFirst) {
			$tsv = "Žádná data nebyla nalezena.";
		} else {
			$tsv = "Nalezena data:\n" . $tsv;
		}
		
		return $this->success($tsv);
	}
}