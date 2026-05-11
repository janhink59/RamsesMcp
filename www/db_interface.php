<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_interface
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tato třída slouží jako "Orchestrátor" mezi MCP protokolem (JSON-RPC) a MSSQL databází.
 * Zajišťuje směrování (routing) volání nástrojů z klienta do správné
 * implementace (generická SQL procedura vs. dedikovaná PHP třída z adresáře /tools).
 *
 * * ZÁVISLOSTI NA DB SCHÉMATU (Očekávaná struktura):
 * - Tabulka `mcp_tool`: Metadata nástrojů (sloupce: mcp_tool, name, title, description, is_generic)
 * - Tabulka `mcp_tool_param`: Definice parametrů (sloupce: param_name, param_type, is_required)
 * - Tabulka `mcp_log`: Audit log (sloupce: request_id, method, payload_in, duration_ms, error_flag)
 * * * GLOBÁLNÍ ZÁVISLOSTI:
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
	 * Inicializuje singleton spojení a okamžitě registruje dostupné nástroje do paměti,
	 * abychom mohli ihned odpovídat na dotazy klienta ohledně schopností serveru (capabilities).
	 */
	public function __construct() {
		// Centralizované získání spojení (Singleton, uchovává SPID)
		$this->db = getMssqlConnection();

		// Registrace dostupných nástrojů
		$this->loadToolsFromDatabase();
	}

	/**
	 * Autentizuje spojení pro konkrétního uživatele MCP.
	 * Bez úspěšného zavolání nesmí proběhnout exekuce žádného nástroje.
	 * * @param string $user     Login koncového uživatele (např. Administrator)
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
			throw $e; // Probublání hlášení (např. do info.php pro zobrazení "Chyba nastavení kontextu")
		}
	}

	/**
	 * Načte strukturu nástrojů a jejich parametrů z databáze a naplní interní registry.
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
	 * Překládá DB typy (bigint, uuid) na JSON standard (number, string).
	 * @return array<int, array> Indexované pole odpovídající specifikaci MCP protokolu.
	 */
	public function getToolsForMain(): array {
		$schemaList = [];

		foreach ($this->mcp_tool_list as $tName => $tool) {
			$schema = [
				"name"        => $tool['name'],
				"title"       => $tool['title'],
				"description" => ($tool['title'] !== '' ? $tool['title'] . " - " : "") . $tool['description'],
				"inputSchema" => [
					"type"       => "object",
					"properties" => [],
					"required"   => []
				]
			];

			foreach ($this->mcp_tool_params[$tName] as $param) {
				$pName = $param['param_name'];
				$jsonType = ($param['param_type'] === 'number' || $param['param_type'] === 'bigint') ? 'number' : 'string';

				$schema['inputSchema']['properties'][$pName] = [
					"type"        => $jsonType,
					"title"       => $param['param_title'],
					"description" => $param['description'] . ($param['param_type'] === 'uuid' ? " (UUID)" : "")
				];

				if ($param['is_required']) {
					$schema['inputSchema']['required'][] = $pName;
				}
			}

			// Pro Ollama/Page Assist: Pokud nástroj nemá parametry, vlastnosti musí být prázdný objekt {}, nikoliv pole []
			if (empty($schema['inputSchema']['properties'])) {
				$schema['inputSchema']['properties'] = new stdClass();
			}

			$schemaList[] = $schema;
		}

		return array_values($schemaList);
	}

	/**
	 * Diagnostická metoda pro ověření existence fyzické implementace nástroje.
	 * @param string $tool_name
	 * @return array{exists: bool, target: string}
	 */
	public function getImplementationStatus(string $tool_name): array {
		if (!isset($this->mcp_tool_list[$tool_name])) {
			return ['exists' => false, 'target' => 'Neznámý nástroj'];
		}

		$tool     = $this->mcp_tool_list[$tool_name];
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);

		if ($tool['is_generic']) {
			$procName = "mcp_tool_" . $pureName;
			$sql      = "SELECT 1 FROM sys.objects WHERE type = 'P' AND name = ?";
			$stmt     = sqlsrv_query($this->db, $sql, [$procName]);
			$exists   = ($stmt !== false && sqlsrv_has_rows($stmt));
			return ['exists' => $exists, 'target' => $procName];
		} else {
			$className = "Get_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";
			return ['exists' => file_exists($classFile), 'target' => $className . ".php"];
		}
	}

	/**
	 * HLAVNÍ EXEKUČNÍ BOD: Spouští logiku konkrétního nástroje.
	 * Řeší validaci parametrů, ošetření UUID a rozbočení mezi SQL a PHP třídou.
	 * * @param string $tool_name Název nástroje
	 * @param array<string, mixed>|null $params Asociativní pole vstupních parametrů od klienta
	 * @return bool True při úspěchu, False při chybě (text chyby je v $this->last_error)
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
		
		// 2. Striktní validace vstupů před odesláním do DB/třídy
		foreach ($toolDefs as $def) {
			$pName = $def['param_name'];
			$val   = $params[$pName] ?? null;

			if ($def['is_required'] && ($val === null || $val === '')) {
				$this->last_error = "Parametr '$pName' je povinný.";
				return false;
			}

			if ($def['param_type'] === 'uuid' && !empty($val)) {
				if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
					$this->last_error = "Parametr '$pName' musí být platné UUID.";
					return false;
				}
			}
		}

		// 3. Směrování exekuce podle příznaku is_generic
		if ($toolMeta['is_generic']) {
			// SQL IMPLEMENTACE: Volání generické uložené procedury
			$procName  = "mcp_tool_" . preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);
			$sqlParams = [];
			$sqlArgs   = [];

			if (!empty($toolDefs)) {
				foreach ($toolDefs as $def) {
					$pName = $def['param_name'];
					$val   = $params[$pName] ?? null;

					if ($val === null || $val === '') {
						$sqlParams[] = "@{$pName} = NULL";
					} else {
						if ($def['param_type'] === 'uuid') {
							// Překlad UUID z textu na binární hex formát pro MSSQL
							$hex = str_replace('-', '', $val);
							$sqlParams[] = "@{$pName} = 0x{$hex}";
						} else {
							$sqlParams[] = "@{$pName} = ?";
							$sqlArgs[]   = $val;
						}
					}
				}
			}

			$sql = "EXEC " . $procName . (!empty($sqlParams) ? " " . implode(', ', $sqlParams) : "");
			$stmt = sqlsrv_query($this->db, $sql, $sqlArgs);

			if ($stmt === false) {
				$this->last_error = "Chyba při provádění procedury {$procName}:\n" . print_r(sqlsrv_errors(), true);
				return false;
			}

			// ARCHITEKTONICKÁ POJISTKA (NEODSTRAŇOVAT!):
			// Pokud procedura v DB není zkompilována s 'SET NOCOUNT ON', MSSQL server pošle informační 
			// zprávy typu "1 row affected" jako prázdné result sety. Tato smyčka je přeskakuje, 
			// dokud nenarazí na reálná data ze závěrečného SELECTu procedury.
			while (!sqlsrv_has_rows($stmt)) {
				$next = sqlsrv_next_result($stmt);
				if ($next === false) {
					$this->last_error = "Chyba při posunu na další výsledek u {$procName}:\n" . print_r(sqlsrv_errors(), true);
					return false;
				} elseif ($next === null) {
					break; // Konec výsledků, nenalezen žádný dataset
				}
			}

			if (sqlsrv_has_rows($stmt)) {
				while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
					foreach ($row as $key => $value) {
						if ($value instanceof DateTime) {
							$row[$key] = $value->format('Y-m-d H:i:s');
						}
					}
					$this->mcp_tool_data[] = $row;
				}
			}
			return true;

		} else {
			// PHP IMPLEMENTACE: Volání dedikované třídy (např. Get_client_detail.php)
			$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);
			$className = "Get_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";

			if (!file_exists($classFile)) {
				$this->last_error = "Fyzický soubor s implementací nástroje ($classFile) nebyl nalezen.";
				return false;
			}

			require_once __DIR__ . '/McpTool.php';
			require_once $classFile;

			if (!class_exists($className) || !is_subclass_of($className, 'McpTool')) {
				$this->last_error = "Třída $className musí existovat a dědit z McpTool.";
				return false;
			}

			/** @var McpTool $instance */
			$instance = new $className($this->db);
			$result = $instance->execute($params ?? []);

			if (isset($result['isError']) && $result['isError']) {
				$this->last_error = $result['content'][0]['text'] ?? 'Neznámá chyba v custom nástroji.';
				return false;
			}

			// Zpětné parsování TSV výstupu z custom třídy do pole, abychom 
			// mohli data zobrazit i v HTML dashboardu info.php.
			$tsvString = $result['content'][0]['text'] ?? '';
			$lines = explode("\n", trim($tsvString));
			
			if (count($lines) > 0 && !str_contains($lines[0], "\t")) {
				array_shift($lines); // Odstranění volitelného úvodního textu (např. "Nalezena data:")
			}

			if (count($lines) >= 2) {
				$headers = explode("\t", array_shift($lines));
				foreach ($lines as $line) {
					$values = explode("\t", $line);
					$row = [];
					foreach ($headers as $index => $header) {
						$row[$header] = $values[$index] ?? '';
					}
					$this->mcp_tool_data[] = $row;
				}
			}
			return true;
		}
	}

	/**
	 * Formátuje buffer mcp_tool_data jako HTML tabulku pro zobrazení v testovacím UI.
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
	 * * DESIGN DECISION (Proč TSV místo JSON objektů?):
	 * Odeslání odpovědi z databáze v TSV (Tab-Separated Values) je úmyslná volba 
	 * architektury. Minimalizuje redundanci (odpadá opakování názvů klíčů pro každý řádek),
	 * což přináší radikální úsporu cenných tokenů v kontextovém okně LLM. AI model 
	 * umí TSV tabulky naprosto nativně a spolehlivě číst.
	 * * @return array JSON-RPC Content pole s textovou zprávou
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
				// Bezpečnostní sanitace: odstranění odřádkování a tabulátorů přímo z dat, 
				// aby nedošlo k rozbití formátu TSV.
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