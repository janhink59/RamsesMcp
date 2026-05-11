<?php
declare(strict_types=1);

/**
 * RamsesMcp - db_interface
 * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tato třída slouží jako "Orchestrátor" mezi MCP protokolem a MSSQL databází.
 * Logiku připojení a autentizace deleguje na db_connect.php.
 */

require_once __DIR__ . '/db_connect.php';

class db_interface {
	
	/** @var resource $db Drží aktivní spojení na MSSQL. */
	private $db;
	
	/** @var array $mcp_tool_list Seznam nástrojů načtený z databáze (metadata). */
	private array $mcp_tool_list = [];
	
	/** @var array $mcp_tool_params Definice parametrů pro jednotlivé nástroje. */
	private array $mcp_tool_params = [];
	
	/** @var array $mcp_tool_data Buffer pro výsledná data. */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chyba. */
	private ?string $last_error = null;

	/** @var bool $isAuthenticated Příznak úspěšného set_login. */
	private bool $isAuthenticated = false;

	/**
	 * Konstruktor třídy.
	 * Využívá centralizovanou funkci getMssqlConnection().
	 */
	public function __construct() {
		// Centralizované získání spojení (Singleton)
		$this->db = getMssqlConnection();

		// Registrace dostupných nástrojů
		$this->loadToolsFromDatabase();
	}

	/**
	 * Autentizuje MCP spojení delegováním na centralizovanou funkci v db_connect.php.
	 */
	public function authenticate(string $user, string $password, string $ip = '127.0.0.1'): void {
		try {
			authenticateMcp($user, $password, $ip);
			$this->isAuthenticated = true;
		} catch (Throwable $e) {
			$this->isAuthenticated = false;
			throw $e; // Probublání hlášení (např. do info.php)
		}
	}

	/**
	 * Načte strukturu nástrojů a jejich parametrů z databáze.
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
	 * Vrací syrová data o nástrojích pro diagnostický dashboard.
	 */
	public function getToolsForInfo(): array {
		return [
			'tools'  => $this->mcp_tool_list,
			'params' => $this->mcp_tool_params
		];
	}

	/**
	 * Generuje JSON Schema pro metodu tools/list.
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
	 */
	public function executeTool(string $tool_name, ?array $params = null): bool {
		$this->mcp_tool_data = [];
		$this->last_error    = null;

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

		if ($toolMeta['is_generic']) {
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

			// Robustní zpracování výsledků (přeskočení count messages)
			while (!sqlsrv_has_rows($stmt)) {
				$next = sqlsrv_next_result($stmt);
				if ($next === false) {
					$this->last_error = "Chyba při posunu na další výsledek u {$procName}:\n" . print_r(sqlsrv_errors(), true);
					return false;
				} elseif ($next === null) {
					break;
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

			$instance = new $className($this->db);
			$result = $instance->execute($params ?? []);

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
	 * Formátuje buffer mcp_tool_data jako HTML tabulku.
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
	 * Formátuje buffer mcp_tool_data do TSV pro MCP AI klienta.
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