<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_interface
 * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tato třída slouží jako "Orchestrátor" mezi MCP protokolem (JSON-RPC) a MSSQL databází.
 * 1. ŽIVOTNÍ CYKLUS: Objekt je vytvářen v main.php, info.php nebo test_exec.php, které vždy 
 * procházejí přes index.php (Router).
 * 2. KONFIGURACE: Striktně využívá globální proměnnou $config. Jakékoli re-importy config.php 
 * jsou zakázány, aby nedošlo k regresi dynamických změn z HTTP hlaviček (X-Mcp-*).
 * 3. KONEKTIVITA: Implementuje Singleton pattern nad globálním $GLOBALS['dbconnection'], 
 * čímž zajišťuje, že v rámci jednoho PHP requestu existuje právě jedno SPID v MSSQL.
 * 4. LOGIKA NÁSTROJŮ: Rozhoduje o směrování exekuce (Generic SQL Procedure vs. Custom PHP Class).
 */

class db_interface {
	
	/** @var resource $db Drží aktivní spojení na MSSQL přes sqlsrv_connect. */
	private $db;
	
	/** @var array $mcp_tool_list Seznam nástrojů načtený z databáze (metadata). */
	private array $mcp_tool_list = [];
	
	/** @var array $mcp_tool_params Definice parametrů (typ, povinnost) pro jednotlivé nástroje. */
	private array $mcp_tool_params = [];
	
	/** @var array $mcp_tool_data Buffer pro výsledná data z posledního spuštěného nástroje. */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chyba (vhodné pro diagnostiku v UI). */
	private ?string $last_error = null;

	/** @var bool $isAuthenticated Příznak, zda pro toto spojení proběhlo úspěšné set_login. */
	private bool $isAuthenticated = false;

	/**
	 * Konstruktor třídy.
	 * Inicializuje DB spojení na základě globálního stavu a přednačítá definice nástrojů.
	 */
	public function __construct() {
		global $config; // Jediný přípustný zdroj pravdy o nastavení serveru a DB

		// 1. Validace kontextu
		if (!isset($config['db'])) {
			throw new Exception("db_interface: Globální konfigurace \$config['db'] není definována. Skript musí běžet přes index.php.");
		}

		// 2. Singleton pro DB spojení
		// Je kritické sdílet stejné spojení, protože MSSQL session (a identita uživatele) je vázána na SPID.
		if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
			$this->db = $GLOBALS['dbconnection'];
		} else {
			// Připojení využívá parametry, které mohly být v index.php přepsány z hlaviček
			$this->db = sqlsrv_connect($config['db']['server'], $config['db']['options']);

			if ($this->db === false) {
				$errors = sqlsrv_errors();
				throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
			}
			
			$GLOBALS['dbconnection'] = $this->db;
			$GLOBALS['dbms'] = 'sqlsrv';
		}

		// 3. Registrace dostupných nástrojů
		// Provádí se hned při startu, abychom znali schopnosti serveru (capabilities).
		$this->loadToolsFromDatabase();
	}

	/**
	 * Autentizuje MCP spojení zavoláním procedury set_login.
	 * Bez tohoto kroku nesmí executeTool povolit žádnou operaci.
	 * * @param string $user     Login uživatele
	 * @param string $password Heslo v prostém textu (v DB se porovnává MD5 hash)
	 * @param string $ip       IP adresa klienta
	 */
	public function authenticate(string $user, string $password, string $ip = '127.0.0.1'): void {
		$sessionID = 'mcp_' . uniqid();
		$pwdMd5    = md5($password);
		
		$sql    = "EXEC set_login @wwwsession = ?, @login = ?, @pwd = ?, @client_ip = ?, @application = ?";
		$params = [
			$sessionID,
			$user,
			$pwdMd5,
			$ip,
			'Ramses MCP Server'
		];

		$stmt = sqlsrv_query($this->db, $sql, $params);

		if ($stmt === false) {
			throw new Exception("Kritická chyba volání procedury set_login:\n" . print_r(sqlsrv_errors(), true));
		}

		$authResult = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
		
		if ($authResult && isset($authResult['code']) && (int)$authResult['code'] < 0) {
			throw new Exception("Autentizace selhala: " . ($authResult['msg'] ?? 'Neznámá chyba'));
		}

		$this->isAuthenticated = true;
	}

	/**
	 * Načte strukturu nástrojů a jejich parametrů z tabulek mcp_tool a mcp_tool_param.
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
	 * Vrací syrová data o nástrojích pro diagnostický dashboard info.php.
	 */
	public function getToolsForInfo(): array {
		return [
			'tools'  => $this->mcp_tool_list,
			'params' => $this->mcp_tool_params
		];
	}

	/**
	 * Generuje JSON Schema pro metodu tools/list v MCP protokolu.
	 * Překládá DB typy na JSON typy (např. bigint -> number).
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

			if (empty($schema['inputSchema']['properties'])) {
				$schema['inputSchema']['properties'] = new stdClass();
			}

			$schemaList[] = $schema;
		}

		return array_values($schemaList);
	}

	/**
	 * Diagnostická metoda pro ověření existence implementace nástroje.
	 * Kontroluje buď existenci SQL procedury (mcp_tool_...) nebo PHP souboru v /tools/.
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
	 * HLAVNÍ EXEKUČNÍ BOD: Spouští logiku nástroje.
	 * Provádí validaci parametrů (povinnost, UUID formát) a následně volá 
	 * příslušnou implementaci.
	 */
	public function executeTool(string $tool_name, ?array $params = null): bool {
		$this->mcp_tool_data = [];
		$this->last_error    = null;

		// 1. Bezpečnostní pojistka - bez autentizace (set_login) se dál nepustíme
		if (!$this->isAuthenticated) {
			$this->last_error = "Pro spuštění nástroje je vyžadována předchozí autentizace uživatele.";
			return false;
		}

		if (!isset($this->mcp_tool_list[$tool_name])) {
			$this->last_error = "Nástroj '$tool_name' nebyl v databázi nalezen.";
			return false;
		}

		$toolDefs = $this->mcp_tool_params[$tool_name];
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

		// 3. Směrování exekuce
		if ($toolMeta['is_generic']) {
			// SQL IMPLEMENTACE: Volání procedury mcp_tool_{name}
			$procName  = "mcp_tool_" . preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);
			$sqlParams = [];
			$sqlArgs   = [];

			foreach ($toolDefs as $def) {
				$pName = $def['param_name'];
				$val   = $params[$pName] ?? null;

				if ($val === null || $val === '') {
					$sqlParams[] = "@{$pName} = NULL";
				} else {
					if ($def['param_type'] === 'uuid') {
						$hex = str_replace('-', '', $val);
						$sqlParams[] = "@{$pName} = 0x{$hex}";
					} else {
						$sqlParams[] = "@{$pName} = ?";
						$sqlArgs[]   = $val;
					}
				}
			}

			$sql  = "EXEC " . $procName . " " . implode(', ', $sqlParams);
			$stmt = sqlsrv_query($this->db, $sql, $sqlArgs);

			if ($stmt === false) {
				$this->last_error = "Chyba při provádění procedury {$procName}:\n" . print_r(sqlsrv_errors(), true);
				return false;
			}

			while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
				foreach ($row as $key => $value) {
					if ($value instanceof DateTime) {
						$row[$key] = $value->format('Y-m-d H:i:s');
					}
				}
				$this->mcp_tool_data[] = $row;
			}
			return true;

		} else {
			// PHP IMPLEMENTACE: Volání třídy Get_{name}
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
			$result = $instance->execute($params);

			if (isset($result['isError']) && $result['isError']) {
				$this->last_error = $result['content'][0]['text'] ?? 'Neznámá chyba v custom nástroji.';
				return false;
			}

			// Parsování TSV výstupu z custom třídy zpět do pole pro unifikované zobrazení
			$tsvString = $result['content'][0]['text'] ?? '';
			$lines = explode("\n", trim($tsvString));
			
			if (count($lines) > 0 && !str_contains($lines[0], "\t")) {
				array_shift($lines);
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
	 * Formátuje buffer $mcp_tool_data jako HTML tabulku pro info.php.
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
	 * Formátuje buffer $mcp_tool_data do TSV pro MCP AI klienta.
	 * TSV je zvoleno pro maximální úsporu tokenů v kontextovém okně LLM.
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
	 * Loguje aktivitu do tabulky mcp_log.
	 * Voláno z main.php po dokončení každého requestu.
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