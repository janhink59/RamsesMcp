<?php
declare(strict_types=1);

/**
 * Tøída db_interface
 * Centrální správa databázového pøipojení a registr MCP nástrojù.
 * Tento skript slouží pro ovìøení stavu serveru, konektivity do DB a 
 * pøímé testování MCP nástrojù (JSON-RPC).
 */
class db_interface {
	
	/** @var resource $db Drží aktivní spojení na MSSQL pøes sqlsrv_connect. */
	private $db;
	
	/** @var array $mcp_tool_list Seznam nástrojù naètený z databáze. */
	private array $mcp_tool_list = [];
	
	/** @var array $mcp_tool_params Definice parametrù pro jednotlivé nástroje. */
	private array $mcp_tool_params = [];
	
	/** @var array $mcp_tool_data Výsledek prvního result setu po volání procedury. */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chyba pøi exekuci. */
	private ?string $last_error = null;

	/** @var bool $isAuthenticated Indikuje, zda byl nastaven kontext uživatele. */
	private bool $isAuthenticated = false;

	/**
	 * Konstruktor pøijímá databázové spojení nebo ho novì vytváøí.
	 * Naváže spojení s MSSQL a rovnou do pamìti naète definice všech nástrojù.
	 * NEPROVÁDÍ pøihlášení konkrétního uživatele.
	 */
	public function __construct() {
		$configPath = __DIR__ . '/config.php';
		if (!file_exists($configPath)) {
			throw new Exception("Konfiguraèní soubor config.php nebyl nalezen.");
		}
		$config = require $configPath;

		// 1. Uložíme spojení a typ DB pro pøípadné další použití (Singleton pattern)
		if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
			$this->db = $GLOBALS['dbconnection'];
		} else {
			// Pøedáváme konfiguraci pøesnì tak, jak ji vyžaduje nativní ovladaè sqlsrv
			$this->db = sqlsrv_connect($config['db']['server'], $config['db']['options']);

			if ($this->db === false) {
				$errors = sqlsrv_errors();
				throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
			}
			
			$GLOBALS['dbconnection'] = $this->db;
			$GLOBALS['dbms'] = 'sqlsrv';
		}

		// 2. Automatické pøednaètení struktury nástrojù pøi startu tøídy
		$this->loadToolsFromDatabase();
	}

	/**
	 * Autentizuje uživatele v databázi voláním procedury set_login.
	 * Nastaví správný kontext pro aktuální spojení (SPID).
	 * * @param string $user     Pøihlašovací jméno
	 * @param string $password Heslo (bude zahašováno do MD5)
	 * @param string $ip       IP adresa klienta
	 * @throws Exception       Pøi neúspìšné autentizaci
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
	 * Naète všechny povolené nástroje a zanoøené definice jejich parametrù.
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
	 * Vrací seznam nástrojù a parametrù ve formátu vhodném pro stránku info.php.
	 * @return array
	 */
	public function getToolsForInfo(): array {
		return [
			'tools'  => $this->mcp_tool_list,
			'params' => $this->mcp_tool_params
		];
	}

	/**
	 * Vrací seznam nástrojù ve standardizovaném JSON Schema formátu pro AI modely.
	 * @return array
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
	 * Ovìøí fyzickou existenci nástroje (zda existuje SQL procedura nebo PHP tøída).
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
	 * Hlavní validaèní logika a samotná implementace logiky nástroje.
	 * @param string     $tool_name
	 * @param array|null $params
	 * @return bool
	 */
	public function executeTool(string $tool_name, ?array $params = null): bool {
		$this->mcp_tool_data = [];
		$this->last_error    = null;

		// 1. Ochrana exekuce kontextem
		if (!$this->isAuthenticated) {
			$this->last_error = "Pro spuštìní nástroje je vyžadována pøedchozí autentizace uživatele.";
			return false;
		}

		if (!isset($this->mcp_tool_list[$tool_name])) {
			$this->last_error = "Nástroj '$tool_name' nebyl v databázi nalezen.";
			return false;
		}

		$toolDefs = $this->mcp_tool_params[$tool_name];
		$toolMeta = $this->mcp_tool_list[$tool_name];
		
		// 2. STRIKTNÍ VALIDACE VSTUPÙ
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

		// 3. ROZVÌTVENÍ APLIKAÈNÍ LOGIKY
		if ($toolMeta['is_generic']) {
			
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
				$this->last_error = "Chyba pøi provádìní procedury {$procName}:\n" . print_r(sqlsrv_errors(), true);
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
				$this->last_error = "Tøída $className musí existovat a dìdit z McpTool.";
				return false;
			}

			/** @var McpTool $instance */
			$instance = new $className($this->db);
			$result = $instance->execute($params);

			if (isset($result['isError']) && $result['isError']) {
				$this->last_error = $result['content'][0]['text'] ?? 'Neznámá chyba v custom nástroji.';
				return false;
			}

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
	 * Zobrazení výsledku v hezkém formátu jako standardní HTML tabulku.
	 * @return string
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
	 * Formátuje naètená data pro AI ve formátu TSV.
	 * @return array
	 */
	public function getResponseAsMcpJson(): array {
		if ($this->last_error !== null) {
			return [
				"isError" => true,
				"content" => [["type" => "text", "text" => "Chyba pøi provádìní nástroje: " . $this->last_error]]
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
	 * Zápis do logovací tabulky mcp_log.
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