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
	
	/** @var array<int, array<string, mixed>> $mcp_tool_data Buffer pro výsledná asociativní data z posledního spuštěného nástroje. */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chybová zpráva (vhodné pro UI diagnostiku). */
	private ?string $last_error = null;

	/** @var bool $isAuthenticated Příznak, zda pro toto spojení proběhlo úspěšné logické přihlášení uživatele (set_login). */
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
		// Nová konvence: vše převedeno na malá písmena
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($tool_name));

		if ($tool['is_generic']) {
			$procName = "mcp_tool_" . $pureName;
			$sql      = "SELECT 1 FROM sys.objects WHERE type = 'P' AND name = ?";
			$stmt     = sqlsrv_query($this->db, $sql, [$procName]);
			$exists   = ($stmt !== false && sqlsrv_has_rows($stmt));
			return ['exists' => $exists, 'target' => $procName];
		} else {
			// Stejná názvová konvence pro PHP třídy
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
			$this->last_error = "Nástroj '$tool_name' nebyl v databázi nalezen.";
			return false;
		}

		$toolDefs = $this->mcp_tool_params[$tool_name] ?? [];
		$toolMeta = $this->mcp_tool_list[$tool_name];
		
		// 2. Příprava argumentů a odstranění save_as (nesmí zasáhnout logiku samotného nástroje)
		$execParams = $params ?? [];
		$execDefs   = [];
		$saveAs     = $execParams['save_as'] ?? null;
		
		foreach ($toolDefs as $def) {
			if ($def['param_name'] !== 'save_as') {
				$execDefs[] = $def;
			}
		}
		
		if (isset($execParams['save_as'])) {
			unset($execParams['save_as']);
		}

		// 3. Rozhodovací logika pro instancování správného objektu (Routing)
		require_once __DIR__ . '/McpTool.php';

		if ($toolMeta['is_generic']) {
			require_once __DIR__ . '/McpGenericStoredProc.php';
			/** @var McpTool $instance */
			$instance = new McpGenericStoredProc($this->db, $tool_name, $execDefs);
		} else {
			// Nová konvence pro načítání PHP tříd z adresáře /tools/
			$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($tool_name));
			$className = "mcp_tool_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";

			if (!file_exists($classFile)) {
				$this->last_error = "Fyzický soubor s implementací nástroje ($classFile) nebyl nalezen.";
				return false;
			}

			require_once $classFile;
			if (!class_exists($className) || !is_subclass_of($className, 'McpTool')) {
				$this->last_error = "Třída $className musí existovat a dědit z McpTool.";
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

		// 6. Orchestrace volitelného parametru save_as
		return $this->processSaveAs($saveAs);
	}

	/**
	 * Pomocná metoda pro parsování unifikovaného TSV výstupu zpět do strukturovaného pole.
	 *
	 * @param string $tsvString Surový TSV řetězec z McpTool objektu.
	 */
	private function parseTsvToData(string $tsvString): void {
		$this->mcp_tool_data = [];
		$lines = explode("\n", trim($tsvString));
		
		if (count($lines) > 0 && !str_contains($lines[0], "\t") && $lines[0] !== '') {
			$firstLine = array_shift($lines);
			// Pokud nástroj korektně hlásí prázdný dataset, vracíme prázdné pole
			if (str_starts_with($firstLine, 'Žádná data')) {
				return;
			}
		}

		if (count($lines) >= 2) {
			$headers = explode("\t", array_shift($lines));
			foreach ($lines as $line) {
				if ($line === '') continue;
				$values = explode("\t", $line);
				$row = [];
				foreach ($headers as $index => $header) {
					$row[$header] = $values[$index] ?? '';
				}
				$this->mcp_tool_data[] = $row;
			}
		}
	}

	/**
	 * Pomocná metoda pro zachycení a uložení stavu nástroje (Claim Check pattern).
	 * Pokud je parametr platný, přepíše se buffer mcp_tool_data jednoduchým upozorněním.
	 *
	 * @param mixed $saveAs Hodnota parametru (string nebo null)
	 * @return bool True při úspěchu
	 */
	private function processSaveAs($saveAs): bool {
		if (!is_string($saveAs) || trim($saveAs) === '') {
			return true;
		}
		
		$saveAs = trim($saveAs);
		
		if (!preg_match('/^[a-z0-9_]+$/', $saveAs)) {
			$this->last_error = "Parametr 'save_as' obsahuje nepovolené znaky. Jsou povolena pouze malá písmena bez diakritiky, číslice a podtržítko.";
			return false;
		}

		$sessionId = $GLOBALS['mcp_session_id'] ?? null;
		if (!$sessionId) {
			$this->last_error = "Kritická chyba: Chybí kontext databázové relace (mcp_session_id) nutný pro uložení proměnné.";
			return false;
		}

		$sqlDelete = "DELETE FROM mcp_saved_values WHERE wwwsession = ? AND save_as = ?";
		$stmtDelete = sqlsrv_query($this->db, $sqlDelete, [$sessionId, $saveAs]);
		if ($stmtDelete === false) {
			$this->last_error = "Chyba při promazávání předchozích hodnot v tabulce mcp_saved_values:\n" . print_r(sqlsrv_errors(), true);
			return false;
		}

		$rowIndex = 0;
		$sqlInsert = "INSERT INTO mcp_saved_values (wwwsession, save_as, row_index, saved_data) VALUES (?, ?, ?, ?)";

		if (!empty($this->mcp_tool_data)) {
			foreach ($this->mcp_tool_data as $row) {
				$val = reset($row);
				$strVal = ($val === null) ? null : (string)$val;
				
				if ($strVal !== null && mb_strlen($strVal) > 200) {
					$strVal = mb_substr($strVal, 0, 200);
				}

				$stmtInsert = sqlsrv_query($this->db, $sqlInsert, [$sessionId, $saveAs, $rowIndex, $strVal]);
				if ($stmtInsert === false) {
					$this->last_error = "Chyba při ukládání hodnoty do mcp_saved_values na indexu {$rowIndex}:\n" . print_r(sqlsrv_errors(), true);
					return false;
				}
				$rowIndex++;
			}
		}

		// Přepis výsledku pro AI i HTML rozhraní
		$this->mcp_tool_data = [
			[
				'rows' => $rowIndex,
				'msg'  => "Data uložena jako '{$saveAs}'"
			]
		];

		return true;
	}

	/**
	 * Formátuje buffer mcp_tool_data jako HTML tabulku pro zobrazení v testovacím UI.
	 *
	 * @return string HTML reprezentace výsledku
	 */
	public function getResponseAsHtml(): string {
		if ($this->last_error !== null) {
			return "<div class='error' style='color: #d93025; padding: 10px; font-weight: bold;'>Chyba: " . htmlspecialchars($this->last_error) . "</div>";
		}
		if (empty($this->mcp_tool_data)) {
			return "<div class='warning' style='padding: 10px;'>Žádná data nebyla nalezena.</div>";
		}

		$html  = "<table class='response-table' style='width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff;'>\n";
		$html .= "\t<thead>\n\t\t<tr style='background: #f8f9fa;'>\n";
		foreach (array_keys($this->mcp_tool_data[0]) as $colName) {
			$html .= "\t\t\t<th style='padding: 10px; border: 1px solid #e2e8f0; text-align: left;'>" . htmlspecialchars((string)$colName) . "</th>\n";
		}
		$html .= "\t\t</tr>\n\t</thead>\n\t<tbody>\n";
		foreach ($this->mcp_tool_data as $row) {
			$html .= "\t\t<tr>\n";
			foreach ($row as $val) {
				$html .= "\t\t\t<td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars((string)$val) . "</td>\n";
			}
			$html .= "\t\t</tr>\n";
		}
		$html .= "\t</tbody>\n</table>";

		return $html;
	}

	/**
	 * Formátuje buffer mcp_tool_data do TSV pro odeslání zpět MCP AI klientovi.
	 *
	 * @return array JSON-RPC Content pole s textovou zprávou
	 */
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

		$tsv = implode("\t", array_keys($this->mcp_tool_data[0])) . "\n";
		
		foreach ($this->mcp_tool_data as $row) {
			$rowStr = array_map(function($val) {
				return str_replace(["\r", "\n", "\t"], " ", (string)$val);
			}, $row);
			
			$tsv .= implode("\t", $rowStr) . "\n";
		}

		return [
			"content" => [["type" => "text", "text" => "Nalezena data:\n" . $tsv]]
		];
	}

	/**
	 * Loguje aktivitu do tabulky mcp_log pro účely auditu a debugování.
	 *
	 * @param string|null $requestId ID požadavku z JSON-RPC od klienta
	 * @param string $method         Volaná metoda (např. tools/call)
	 * @param string $payloadIn      Kompletní syrový JSON vstup
	 * @param string $payloadOut     Kompletní syrový JSON výstup
	 * @param int $durationMs        Doba zpracování v milisekundách
	 * @param bool $isError          Příznak, zda požadavek skončil chybou
	 */
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