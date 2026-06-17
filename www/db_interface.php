<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_interface
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tato třída slouží jako "Orchestrátor" mezi MCP protokolem (JSON-RPC) a MSSQL databází.
 * Zajišťuje směrování (routing) volání nástrojů z klienta do správné
 * implementace (generická SQL procedura vs. dedikovaná PHP třída z adresáře /tools).
 *
 * ZÁVISLOSTI NA DB SCHÉMATU (Očekávaná struktura):
 * - Tabulka `mcp_tool`: Metadata nástrojů (sloupce: mcp_tool, name, title, description, is_generic)
 * - Tabulka `mcp_tool_param`: Definice parametrů (sloupce: param_name, param_type, is_required)
 * - Tabulka `mcp_log`: Audit log (sloupce: request_id, method, payload_in, duration_ms, error_flag)
 * - Tabulka `mcp_saved_values`: Dočasné tabulky s hodnotami pole (sloupce: wwwsession, save_as, row_index, saved_data)
 *
 * GLOBÁLNÍ ZÁVISLOSTI:
 * Striktně deleguje fyzické připojení k databázi a autentizační logiku 
 * na centralizované funkce v souboru `db_connect.php`.
 */

require_once __DIR__ . '/db_connect.php';

class db_interface {
	
	/** @var resource $db Drží aktivní spojení na MSSQL získané z db_connect.php. */
	private $db;
	
	/** @var array<string, array{mcp_tool: string, name: string, title: string, description: string, is_generic: bool}> $mcp_tool_list Metadata nástrojů. */
	private array $mcp_tool_list = [];
	
	/** @var array<string, array<int, array{param_name: string, param_title: string, param_type: string, description: string, is_required: bool}>> $mcp_tool_params Definice parametrů. */
	private array $mcp_tool_params = [];
	
	/** @var array<int, array{block_name: string|null, rows: array<int, array<string, mixed>>}> $mcp_tool_data Buffer strukturovaných bloků (result-setů). */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chybová zpráva (vhodné pro UI diagnostiku). */
	private ?string $last_error = null;

	/** @var bool $isAuthenticated Příznak, zda pro ovo spojení proběhlo úspěšné logické přihlášení uživatele (set_login). */
	private bool $isAuthenticated = false;

	/**
	 * Konstruktor třídy.
	 * Inicializuje singleton spojení a okamžitě registruje dostupné nástroje do vnitřní mapy.
	 */
	public function __construct() {
		$this->db = getMssqlConnection();
		$this->loadToolsFromDatabase();
	}

	/**
	 * Autentizuje spojení pro konkrétního uživatele MCP.
	 *
	 * @param string $user     Login koncového uživatele (např. Administrator)
	 * @param string $password Heslo uživatele v prostém textu
	 * @param string $ip       Auditní IP adresa (default: 127.0.0.1)
	 * @throws Exception       Propaguje výjimku nahoru, pokud selže SQL nebo login
	 */
	public function authenticate(string $user, string $password, string $ip = '127.0.0.1'): void {
		try {
			authenticateMcp($user, $password, $ip);
			$this->isAuthenticated = true;
		} catch (Throwable $e) {
			$this->isAuthenticated = false;
			throw $e;
		}
	}

	/**
	 * Načte strukturu nástrojů a jejich parametrů z databáze do interní mapy pro rychlé ověřování.
	 */
	private function loadToolsFromDatabase(): void {
		$sql = "SELECT t.mcp_tool, t.name, t.title AS tool_title, t.description AS tool_desc, t.is_generic,
					   p.param_name, p.param_title, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tool t
				LEFT JOIN mcp_tool_param p ON t.mcp_tool = p.mcp_tool
				ORDER BY t.name";

		$query = sqlsrv_query($this->db, $sql);
		
		if ($query === false) return;

		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];

			if (!isset($this->mcp_tool_list[$tName])) {
				$isGeneric = isset($row['is_generic']) && (int)$row['is_generic'] === 1;

				$this->mcp_tool_list[$tName] = [
					'mcp_tool'    => $row['mcp_tool'],
					'name'        => $tName,
					'title'       => $row['tool_title'],
					'description' => $row['tool_desc'],
					'is_generic'  => $isGeneric
				];
				
				$this->mcp_tool_params[$tName] = []; 
			}

			if (!empty($row['param_name'])) {
				$isRequired = isset($row['is_required']) && (int)$row['is_required'] === 1;
				
				$this->mcp_tool_params[$tName][] = [
					'param_name'  => $row['param_name'],
					'param_title' => $row['param_title'],
					'param_type'  => $row['param_type'],
					'description' => $row['param_desc'],
					'is_required' => $isRequired
				];
			}
		}
	}

	/**
	 * Vrací syrová data o nástrojích pro diagnostický dashboard (info.php).
	 *
	 * @return array{tools: array, params: array}
	 */
	public function getToolsForInfo(): array {
		return [
			'tools'  => $this->mcp_tool_list,
			'params' => $this->mcp_tool_params
		];
	}

	/**
	 * Generuje strukturu JSON Schema pro MCP metodu tools/list.
	 * Využívá dedikovanou třídu McpRegistry.
	 *
	 * @return array<int, array> Indexované pole odpovídající specifikaci MCP protokolu.
	 */
	public function getToolsForMain(): array {
		require_once __DIR__ . '/McpRegistry.php';
		
		$registry = new McpRegistry($this->db);
		$tools = $registry->getTools();
		
		// Očištění schématu od našich interních příznaků a zajištění kompatibility
		foreach ($tools as &$tool) {
			unset($tool['is_generic']);
			
			// Pro Ollama/Page Assist: properties nesmí být prázdné pole [], ale prázdný objekt {}
			if (empty($tool['inputSchema']['properties'])) {
				$tool['inputSchema']['properties'] = new stdClass();
			}
		}
		
		return $tools;
	}

	/**
	 * Diagnostická metoda pro ověření existence fyzické implementace nástroje.
	 *
	 * @param string $tool_name
	 * @return array{exists: bool, target: string}
	 */
	public function getImplementationStatus(string $tool_name): array {
		if (!isset($this->mcp_tool_list[$tool_name])) {
			return ['exists' => false, 'target' => 'Neznámý nástroj'];
		}

		$tool     = $this->mcp_tool_list[$tool_name];
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($tool_name));

		if ($tool['is_generic']) {
			$procName = "mcp_tool_" . $pureName;
			$sql      = "SELECT 1 FROM sys.objects WHERE type = 'P' AND name = ?";
			$stmt     = sqlsrv_query($this->db, $sql, [$procName]);
			$exists   = ($stmt !== false && sqlsrv_has_rows($stmt));
			return ['exists' => $exists, 'target' => $procName];
		} else {
			$className = "mcp_tool_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";
			return ['exists' => file_exists($classFile), 'target' => $className . ".php"];
		}
	}

	/**
	 * HLAVNÍ EXEKUČNÍ BOD: Orchestruje spuštění logiky konkrétního nástroje.
	 *
	 * @param string $tool_name Název nástroje
	 * @param array<string, mixed>|null $params Asociativní pole vstupních parametrů
	 * @return bool True při úspěchu, False při chybě
	 */
	public function executeTool(string $tool_name, ?array $params = null): bool {
		$this->mcp_tool_data = [];
		$this->last_error    = null;

		// 1. Bezpečnostní pojistka kontextu
		if (!$this->isAuthenticated) {
			$this->last_error = "Pro spuštění nástroje je vyžadována předchozí autentizace uživatele.";
			return false;
		}

		if (!isset($this->mcp_tool_list[$tool_name])) {
			$this->last_error = "Nástroj '$tool_name' nebyl v databázi znalezen.";
			return false;
		}

		$toolDefs = $this->mcp_tool_params[$tool_name] ?? [];
		$toolMeta = $this->mcp_tool_list[$tool_name];
		
		// 2. Příprava argumentů a odstranění save_as i save_only (nesmí zasáhnout logiku samotného nástroje)
		$execParams = $params ?? [];
		$execDefs   = [];
		$saveAs     = $execParams['save_as'] ?? null;
		
		// Vytažení a výchozí nastavení parametru save_only (implicitně 0 = plná data)
		$saveOnly   = isset($execParams['save_only']) ? (int)$execParams['save_only'] : 0;
		
		// Fallback: Pokud jde o dispečer filtrů a nebylo řečeno kam uložit, uložíme to pod názvem filtru
		if (strtolower($tool_name) === 'set_filter' && empty($saveAs) && !empty($execParams['filter_code'])) {
			$saveAs = trim((string)$execParams['filter_code']);
		}
		
		foreach ($toolDefs as $def) {
			// Parametry řízení orchestrátoru nepředáváme dál do fyzických SQL procedur
			if ($def['param_name'] !== 'save_as' && $def['param_name'] !== 'save_only') {
				$execDefs[] = $def;
			}
		}
		
		if (isset($execParams['save_as'])) unset($execParams['save_as']);
		if (isset($execParams['save_only'])) unset($execParams['save_only']);

		// 3. Rozhodovací logika pro instancování správného objektu (Routing)
		require_once __DIR__ . '/McpTool.php';

		if ($toolMeta['is_generic']) {
			require_once __DIR__ . '/McpGenericStoredProc.php';
			/** @var McpTool $instance */
			$instance = new McpGenericStoredProc($this->db, $tool_name, $execDefs);
		} else {
			$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($tool_name));
			$className = "mcp_tool_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";

			if (!file_exists($classFile)) {
				$this->last_error = "Fyzický soubor s implementací nástroje ($classFile) nebyl nalezen.";
				return false;
			}

			require_once $classFile;
			if (!class_exists($className) || !is_subclass_of($className, 'McpTool')) {
				$this->last_error = "Třída $className must existovat a dědit z McpTool.";
				return false;
			}

			/** @var McpTool $instance */
			$instance = new $className($this->db);
		}

		// 4. Unifikovaná validace a spuštění nezávisle na typu (SQL vs PHP)
		$result = $instance->validateAndExecute($execParams, $execDefs);

		if (isset($result['isError']) && $result['isError']) {
			$this->last_error = $result['content'][0]['text'] ?? 'Neznámá chyba v nástroji.';
			return false;
		}

		// 5. Zpětné parsování TSV výstupu do pole
		$tsvString = $result['content'][0]['text'] ?? '';
		$this->parseTsvToData($tsvString);

		// 6. Orchestrace volitelných parametrů save_as, save_only a pevného řádkového kontextu row_{tool_name}
		return $this->processSaveAs($tool_name, $saveAs, $saveOnly);
	}

	/**
	 * Pomocná metoda pro parsování unifikovaného TSV výstupu zpět do strukturované podoby bloků.
	 */
	private function parseTsvToData(string $tsvString): void {
		$this->mcp_tool_data = [];
		$lines = explode("\n", trim($tsvString));
		
		if (count($lines) > 0 && !str_contains($lines[0], "\t") && !str_starts_with($lines[0], '===') && $lines[0] !== '') {
			$firstLine = trim($lines[0]);
			if (str_starts_with($firstLine, 'Žádná data') || str_starts_with($firstLine, 'Chyba')) {
				return;
			}
		}

		$currentBlockName = null;
		$currentHeaders   = null;
		$currentRows      = [];

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') continue;

			if (preg_match('/^===\s*(.+?)\s*===$/', $line, $matches)) {
				if ($currentHeaders !== null || !empty($currentRows)) {
					$this->mcp_tool_data[] = [
						'block_name' => $currentBlockName,
						'rows'       => $currentRows
					];
				}
				$currentBlockName = $matches[1];
				$currentHeaders   = null;
				$currentRows      = [];
				continue;
			}

			if ($currentHeaders === null) {
				$currentHeaders = explode("\t", $line);
			} else {
				$values = explode("\t", $line);
				$row = [];
				foreach ($currentHeaders as $index => $header) {
					$row[$header] = $values[$index] ?? '';
				}
				$currentRows[] = $row;
			}
		}

		if ($currentHeaders !== null || !empty($currentRows)) {
			$this->mcp_tool_data[] = [
				'block_name' => $currentBlockName,
				'rows'       => $currentRows
			];
		}
	}

	/**
	 * Pomocná metoda pro plnění kontextu a zachycení stavu nástroje.
	 * ORCHESTRÁTOR NYNÍ SÁM GENERUJE TEXTOVÉ ZPRÁVY PRO LLM na základě zjištěného počtu dat.
	 */
	private function processSaveAs(string $tool_name, $saveAs, int $saveOnly): bool {
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($tool_name));
		$autoVarName = 'row_' . $pureName;

		if (is_string($saveAs) && trim($saveAs) !== '') {
			$saveAs = trim($saveAs);
			if (!preg_match('/^[a-z0-9_]+$/', $saveAs)) {
				$this->last_error = "Parametr 'save_as' obsahuje nepovolené znaky.";
				return false;
			}
		} else {
			$saveAs = null;
		}

		$sessionId = $GLOBALS['mcp_session_id'] ?? null;
		if (!$sessionId) {
			$this->last_error = "Kritická chyba: Chybí kontext databázové relace (mcp_session_id).";
			return false;
		}

		// 1. KROK: Vyčištění předchozího stavu
		$sqlDeleteAuto = "DELETE FROM mcp_saved_values WHERE wwwsession = ? AND save_as = ?";
		$stmtDeleteAuto = sqlsrv_query($this->db, $sqlDeleteAuto, [$sessionId, $autoVarName]);
		if ($stmtDeleteAuto !== false) sqlsrv_free_stmt($stmtDeleteAuto);

		if ($saveAs !== null) {
			$sqlDeleteSaveAs = "DELETE FROM mcp_saved_values WHERE wwwsession = ? AND save_as = ?";
			$stmtDeleteSaveAs = sqlsrv_query($this->db, $sqlDeleteSaveAs, [$sessionId, $saveAs]);
			if ($stmtDeleteSaveAs !== false) sqlsrv_free_stmt($stmtDeleteSaveAs);
		}

		$rowIndex = 0;
		$sqlInsert = "INSERT INTO mcp_saved_values (wwwsession, save_as, row_index, saved_data) VALUES (?, ?, ?, ?)";
		
		$filteredData = [];
		$customMessagePassed = false;

		// 2. KROK: Procházení dat a uložení
		if (!empty($this->mcp_tool_data)) {
			foreach ($this->mcp_tool_data as $block) {
				if (empty($block['rows'])) continue;
				
				$firstColName = array_key_first($block['rows'][0]);
				$isMessageBlock = in_array(strtolower((string)$firstColName), ['result', 'msg', 'message', 'error']);
				
				if ($isMessageBlock) {
					// Pokud by SQL vyhodilo explicitní chybovou nebo varovnou zprávu, propustíme ji.
					$filteredData[] = $block;
					$customMessagePassed = true;
				} else {
					// Jsou to data! Automaticky je uložíme do databáze.
					foreach ($block['rows'] as $row) {
						$valToSave = null;
						foreach ($row as $key => $val) {
							if ($key !== 'row_number') {
								$valToSave = $val;
								break;
							}
						}
						
						if ($valToSave === null) $valToSave = reset($row);
						$strVal = ($valToSave === null) ? null : (string)$valToSave;
						
						if ($strVal !== null && mb_strlen($strVal) > 200) {
							$strVal = mb_substr($strVal, 0, 200);
						}

						$autoIndex = $rowIndex + 1;

						$stmtInsertAuto = sqlsrv_query($this->db, $sqlInsert, [$sessionId, $autoVarName, $autoIndex, $strVal]);
						if ($stmtInsertAuto !== false) sqlsrv_free_stmt($stmtInsertAuto);

						if ($saveAs !== null) {
							$stmtInsertSaveAs = sqlsrv_query($this->db, $sqlInsert, [$sessionId, $saveAs, $rowIndex, $strVal]);
							if ($stmtInsertSaveAs !== false) sqlsrv_free_stmt($stmtInsertSaveAs);
						}

						$rowIndex++;
					}
					
					if ($saveOnly === 0) {
						$filteredData[] = $block;
					}
				}
			}
		}

		// 3. KROK: GENERUJEME INTELIGENTNÍ ZPRÁVU V ORCHESTRÁTORU (zamezení duplicity v SQL)
		if ($rowIndex > 0) {
			$targetVar = $saveAs !== null ? $saveAs : $autoVarName;
			
			if ($rowIndex === 1) {
				$msg = "ÚSPĚCH: Nalezen přesně 1 záznam. Identifikátor byl automaticky uložen do kontextové proměnné '{$targetVar}'.";
			} else {
				$msg = "ÚSPĚCH: Nalezeno {$rowIndex} záznamů. Identifikátory byly uloženy do kontextové proměnné '{$targetVar}'.";
			}

			$sysBlock = [
				'block_name' => 'System',
				'rows'       => [['result' => $msg]]
			];

			if ($saveOnly === 1) {
				// Vracíme JEN zprávu
				$this->mcp_tool_data = [$sysBlock];
			} else {
				// Připojíme zprávu PŘED samotná data
				array_unshift($filteredData, $sysBlock);
				$this->mcp_tool_data = $filteredData;
			}
		} elseif (!$customMessagePassed) {
			// SQL nevrátilo ani data, ani vlastní chybovou zprávu
			$this->mcp_tool_data = [
				[
					'block_name' => 'System',
					'rows'       => [['result' => "UPOZORNĚNÍ: Dotaz nevrátil žádná data."]]
				]
			];
		} else {
			$this->mcp_tool_data = $filteredData;
		}

		return true;
	}

	public function getResponseAsHtml(): string {
		if ($this->last_error !== null) {
			return "<div class='error' style='color: #d93025; padding: 10px; font-weight: bold;'>Chyba: " . htmlspecialchars($this->last_error) . "</div>";
		}
		if (empty($this->mcp_tool_data)) {
			return "<div class='warning' style='padding: 10px;'>Žádná data nebyla nalezena.</div>";
		}

		$html = "";
		foreach ($this->mcp_tool_data as $block) {
			if (!empty($block['block_name'])) {
				$html .= "<h4 style='margin-top: 15px; margin-bottom: 5px; color: #2d3748; border-bottom: 1px solid #edf2f7; padding-bottom: 4px;'>" . htmlspecialchars($block['block_name']) . "</h4>\n";
			}

			if (empty($block['rows'])) {
				$html .= "<div class='warning' style='padding: 10px; color: #718096;'>Žádné řádky v tomto bloku.</div>\n";
				continue;
			}

			$html .= "<table class='response-table' style='width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff;'>\n";
			$html .= "\t<thead>\n\t\t<tr style='background: #f8f9fa;'>\n";
			foreach (array_keys($block['rows'][0]) as $colName) {
				$html .= "\t\t\t<th style='padding: 10px; border: 1px solid #e2e8f0; text-align: left;'>" . htmlspecialchars((string)$colName) . "</th>\n";
			}
			$html .= "\t\t</tr>\n\t</thead>\n\t<tbody>\n";
			foreach ($block['rows'] as $row) {
				$html .= "\t\t<tr>\n";
				foreach ($row as $val) {
					$html .= "\t\t\t<td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars((string)$val) . "</td>\n";
				}
				$html .= "\t\t</tr>\n";
			}
			$html .= "\t</tbody>\n</table>\n";
		}

		return $html;
	}

	public function getResponseAsMcpJson(): array {
		if ($this->last_error !== null) {
			return [
				"isError" => true,
				"content" => [["type" => "text", "text" => "Chyba při provádění nástroje: " . $this->last_error]]
			];
		}
		if (empty($this->mcp_tool_data)) {
			return [
				"content" => [["type" => "text", "text" => "Žádná data nebyla nalezena."]]
			];
		}

		$tsv = "";
		
		foreach ($this->mcp_tool_data as $block) {
			if (!empty($block['block_name'])) {
				$tsv .= "=== " . $block['block_name'] . " ===\n";
			}

			if (!empty($block['rows'])) {
				$tsv .= implode("\t", array_keys($block['rows'][0])) . "\n";
				
				foreach ($block['rows'] as $row) {
					$rowStr = array_map(function($val) {
						return str_replace(["\r", "\n", "\t"], " ", (string)$val);
					}, $row);
					
					$tsv .= implode("\t", $rowStr) . "\n";
				}
			}
		}

		return [
			"content" => [["type" => "text", "text" => "Nalezena data:\n" . trim($tsv)]]
		];
	}

	public function logRequest(?string $requestId, string $method, string $payloadIn, string $payloadOut, int $durationMs, bool $isError): void {
		$sql = "INSERT INTO mcp_log (request_id, method, payload_in, payload_out, duration_ms, error_flag) 
				VALUES (?, ?, ?, ?, ?, ?)";
		
		$params = [
			(string)$requestId,
			$method,
			$payloadIn,
			$payloadOut,
			$durationMs,
			$isError ? 1 : 0
		];
		
		sqlsrv_query($this->db, $sql, $params);
	}
}