<?php
declare(strict_types=1);

/**
 * Tøída db_interface
 * Centrální správa databázového pøipojení a registr MCP nástrojù.
 * * Tento skript slouží pro ovìøení stavu serveru, konektivity do DB a 
 * pøímé testování MCP nástrojù (JSON-RPC) bez nutnosti klienta (Ollamy).
 * Plnì nahrazuje pùvodní soubory db_connect.php, McpRegistry.php, McpGenericStoredProc.php
 * a integruje logiku validace a smìrování z McpTool.php a index.php.
 */
class db_interface {
	
	/** @var resource $db Drží aktivní spojení na MSSQL pøes sqlsrv_connect. */
	private $db;
	
	/** @var array $mcp_tool_list Seznam nástrojù naètený z databáze. */
	private array $mcp_tool_list = [];
	
	/** @var array $mcp_tool_params Definice parametrù pro jednotlivé nástroje. */
	private array $mcp_tool_params = [];
	
	/** @var array $mcp_tool_data Výsledek prvního result setu po volání procedury nebo parsování z PHP tøídy. */
	private array $mcp_tool_data = [];
	
	/** @var string|null $last_error Poslední zachycená chyba pøi exekuci. */
	private ?string $last_error = null;

	/**
	 * Konstruktor pøijímá databázové spojení nebo ho novì vytváøí.
	 * Naváže spojení s MSSQL, ovìøí kontext (debuglogin) 
	 * a rovnou do pamìti naète definice všech dostupných nástrojù.
	 */
	public function __construct() {
		$config = require __DIR__ . '/config.php';

		// 1. Uložíme spojení a typ DB pro pøípadné další použití (Singleton pattern)
		// Pokud spojení už existuje globálnì, použijeme ho. Jinak ho vytvoøíme.
		if (isset($GLOBALS['dbconnection']) && $GLOBALS['dbconnection'] !== false) {
			$this->db = $GLOBALS['dbconnection'];
		} else {
			// Pøedáváme konfiguraci pøesnì tak, jak ji vyžaduje nativní ovladaè sqlsrv
			$this->db = sqlsrv_connect($config['db']['server'], $config['db']['options']);

			if ($this->db === false) {
				$errors = sqlsrv_errors();
				// Zmìna: Místo tvrdého die() s JSONem vyhodíme výjimku.
				throw new Exception("Database connection failed: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
			}
			
			$GLOBALS['dbconnection'] = $this->db;
			$GLOBALS['dbms'] = 'sqlsrv';

			// 2. Pøíprava dotazu s bezpeènými parametry
			$contextUser = $config['db']['options']['APP'] ?? 'mcp_server'; // Použito dynamicky z configu
			$sql         = "execute debuglogin ?";
			$params      = [$contextUser]; 

			// 3. Nativní spuštìní dotazu
			$stmt = sqlsrv_query($this->db, $sql, $params);

			if ($stmt === false) {
				$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
				$GLOBALS['dbconnection'] = false;
				throw new Exception("Kritická chyba volání procedury debuglogin:\n" . print_r($sqlErrors, true));
			}

			$appErrorRows = [];

			// 4. Agresivní kontrola: Projdeme VŠECHNY výsledky z procedury
			do {
				while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
					$appErrorRows[] = $row;
				}
			} while (sqlsrv_next_result($stmt));

			// 5. Kontrola na tvrdé SQL chyby (vráceno zpìt!)
			$sqlErrors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
			if (!empty($sqlErrors)) {
				$GLOBALS['dbconnection'] = false;
				throw new Exception("Kritická SQL chyba z debuglogin:\n" . print_r($sqlErrors, true));
			}

			// 6. Kontrola aplikaèních chyb
			if (!empty($appErrorRows)) {
				$GLOBALS['dbconnection'] = false;
				throw new Exception("Aplikaèní chyba pøihlášení (debuglogin):\n" . print_r($appErrorRows, true));
			}
		}

		// 7. Automatické pøednaètení struktury nástrojù pøi startu tøídy
		$this->loadToolsFromDatabase();
	}

	/**
	 * Naète všechny povolené nástroje a zanoøené definice jejich parametrù.
	 * Výsledky ukládá do privátních vlastností pro pozdìjší formátování.
	 */
	private function loadToolsFromDatabase(): void {
		// Sloupec is_generic je nezbytný pro UI Dashboard a správné smìrování v index.php.
		// Samotný MCP protokol tento údaj ignoruje, ale my ho neseme s sebou pro lokální logiku.
		$sql = "SELECT t.mcp_tool, t.name, t.title AS tool_title, t.description AS tool_desc, t.is_generic,
					   p.param_name, p.param_title, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tool t
				LEFT JOIN mcp_tool_param p ON t.mcp_tool = p.mcp_tool
				ORDER BY t.name";

		$query = sqlsrv_query($this->db, $sql);
		
		// Pøi selhání dotazu se tiše vrátí a ponechá pole prázdná, router to nevnímá jako kritickou chybu.
		if ($query === false) {
			return;
		}

		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];

			// Pokud nástroj ještì není v poli definován, vytvoøíme pro nìj koøenový uzel.
			if (!isset($this->mcp_tool_list[$tName])) {
				// BEZPEÈNÉ PØETYPOVÁNÍ: Ošetøení proti anomáliím nativního sqlsrv ovladaèe.
				// MSSQL typ BIT mùže pøijít jako int(1), int(0), string("1"), string("0") nebo jako prázdná hodnota.
				$isGeneric = isset($row['is_generic']) && (int)$row['is_generic'] === 1;

				$this->mcp_tool_list[$tName] = [
					'mcp_tool'    => $row['mcp_tool'],
					'name'        => $tName,
					'title'       => $row['tool_title'],
					'description' => $row['tool_desc'],
					'is_generic'  => $isGeneric                                 // Interní flag propisovaný pro UI
				];
				
				// Inicializace prázdného pole parametrù pro tento nástroj
				$this->mcp_tool_params[$tName] = []; 
			}

			// Pokud má nástroj navázané parametry, pøidáme je do seznamu
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
	 * * @return array Asociativní pole obsahující 'tools' a 'params'.
	 */
	public function getToolsForInfo(): array {
		return [
			'tools'  => $this->mcp_tool_list,
			'params' => $this->mcp_tool_params
		];
	}

	/**
	 * Registr zapouzdøuje složitou logiku naèítání metadat a formátování do JSON Schema.
	 * Vrací seznam nástrojù ve standardizovaném JSON Schema formátu pro AI modely.
	 * * @return array Strukturované pole pøipravené k serializaci do JSONu pro tools/list.
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

			// Pokud má nástroj navázané parametry, vložíme je do struktury inputSchema -> properties.
			foreach ($this->mcp_tool_params[$tName] as $param) {
				$pName = $param['param_name'];
				
				// Normalizace databázových typù na standardní datové typy podporované JSON Schema.
				$jsonType = ($param['param_type'] === 'number' || $param['param_type'] === 'bigint') ? 'number' : 'string';

				$schema['inputSchema']['properties'][$pName] = [
					"type"        => $jsonType,
					"title"       => $param['param_title'],
					"description" => $param['description'] . ($param['param_type'] === 'uuid' ? " (UUID)" : "")
				];

				// Záznam povinného pole do pole required, jak definuje MCP specifikace.
				if ($param['is_required']) {
					$schema['inputSchema']['required'][] = $pName;
				}
			}
			$schemaList[] = $schema;
		}

		// Pøevod asociativního pole na indexované pole, které vyžaduje MCP specifikace (seznam).
		return array_values($schemaList);
	}

	/**
	 * Ovìøí fyzickou existenci nástroje (zda existuje SQL procedura nebo PHP tøída).
	 * Využíváno v info.php pro vizuální kontrolu a blokování testù.
	 * * @param string $tool_name
	 * @return array{exists: bool, target: string}
	 */
	public function getImplementationStatus(string $tool_name): array {
		if (!isset($this->mcp_tool_list[$tool_name])) {
			return ['exists' => false, 'target' => 'Neznámý nástroj'];
		}

		$tool     = $this->mcp_tool_list[$tool_name];
		// Pøíprava dat pro kontrolu existence
		$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);

		if ($tool['is_generic']) {
			// Generický nástroj -> hledáme SQL proceduru mcp_tool_{name}
			$procName = "mcp_tool_" . $pureName;
			$sql      = "SELECT 1 FROM sys.objects WHERE type = 'P' AND name = ?";
			$stmt     = sqlsrv_query($this->db, $sql, [$procName]);
			$exists   = ($stmt !== false && sqlsrv_has_rows($stmt));
			return ['exists' => $exists, 'target' => $procName];
		} else {
			// Custom nástroj -> hledáme PHP soubor Get_{name}.php
			$className = "Get_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";
			return ['exists' => file_exists($classFile), 'target' => $className . ".php"];
		}
	}

	/**
	 * Hlavní validaèní logika a samotná implementace logiky nástroje.
	 * Podporuje generické (SQL procedury) i custom (PHP tøídy).
	 * * @param string     $tool_name Název nástroje
	 * @param array|null $params    Vstupní argumenty
	 * @return bool                 True pokud se provedení podaøilo, jinak False
	 */
	public function executeTool(string $tool_name, ?array $params = null): bool {
		$this->mcp_tool_data = [];
		$this->last_error    = null;

		if (!isset($this->mcp_tool_list[$tool_name])) {
			$this->last_error = "Nástroj '$tool_name' nebyl v databázi nalezen.";
			return false;
		}

		$toolDefs = $this->mcp_tool_params[$tool_name];
		$toolMeta = $this->mcp_tool_list[$tool_name];
		
		// ---------------------------------------------------------
		// 1. STRIKTNÍ VALIDACE VSTUPÙ (Pøeneseno z McpTool.php)
		// ---------------------------------------------------------
		foreach ($toolDefs as $def) {
			$pName = $def['param_name'];
			$val   = $params[$pName] ?? null;

			// Kontrola povinného parametru
			if ($def['is_required'] && ($val === null || $val === '')) {
				$this->last_error = "Parametr '$pName' je povinný.";
				return false;
			}

			// Typová kontrola pro UUID (pokud je hodnota vyplnìna)
			if ($def['param_type'] === 'uuid' && !empty($val)) {
				if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
					$this->last_error = "Parametr '$pName' musí být platné UUID.";
					return false;
				}
			}
		}

		// ---------------------------------------------------------
		// 2. ROZVÌTVENÍ APLIKAÈNÍ LOGIKY (Podle pøíznaku is_generic)
		// ---------------------------------------------------------
		if ($toolMeta['is_generic']) {
			
			// --- Nástroj je generický -> voláme uloženou proceduru ---
			
			// Bezpeèné ošetøení názvu procedury proti injection (povoleny pouze alfanumerické znaky a podtržítka)
			$procName  = "mcp_tool_" . preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);
			
			$sqlParams = [];                // Pole fragmentù pro EXEC pøíkaz (napø. "@param = ?")
			$sqlArgs   = [];                // Skuteèné hodnoty pro nativní binding parametrizovaného dotazu

			// Sestavení parametrù a ošetøení specifických datových typù dle definice
			foreach ($toolDefs as $def) {
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
			$sql  = "EXEC " . $procName . " " . implode(', ', $sqlParams);
			$stmt = sqlsrv_query($this->db, $sql, $sqlArgs);

			if ($stmt === false) {
				$this->last_error = "Chyba pøi provádìní procedury {$procName}:\n" . print_r(sqlsrv_errors(), true);
				return false;
			}

			// Agregace prvního result setu do pamìti
			while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
				// Normalizace objektù (napø. DateTime) na string
				foreach ($row as $key => $value) {
					if ($value instanceof DateTime) {
						$row[$key] = $value->format('Y-m-d H:i:s');
					}
				}
				$this->mcp_tool_data[] = $row;
			}
			return true;

		} else {
			
			// --- Nástroj je specifický -> hledáme konkrétní PHP tøídu na disku (prefix Get_) ---
			
			$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', $tool_name);
			$className = "Get_" . $pureName;
			$classFile = __DIR__ . "/tools/" . $className . ".php";

			if (!file_exists($classFile)) {
				$this->last_error = "Fyzický soubor s implementací nástroje ($classFile) nebyl nalezen.";
				return false;
			}

			// Nutnost naèíst rodièovskou tøídu McpTool
			require_once __DIR__ . '/McpTool.php';
			require_once $classFile;

			if (!class_exists($className) || !is_subclass_of($className, 'McpTool')) {
				$this->last_error = "Tøída $className musí existovat a dìdit z McpTool.";
				return false;
			}

			/** @var McpTool $instance */
			$instance = new $className($this->db);
			
			// Vykoná SQL dotaz a vrátí data ve formátu TSV.
			$result = $instance->execute($params);

			// Kontrola, zda nám custom tøída nevrátila chybové hlášení
			if (isset($result['isError']) && $result['isError']) {
				$this->last_error = $result['content'][0]['text'] ?? 'Neznámá chyba v custom nástroji.';
				return false;
			}

			// Extrakce vráceného TSV stringu zpìt do asociativního pole $mcp_tool_data, 
			// aby getResponseAsHtml() dokázala vykreslit tabulku i pro PHP nástroje
			$tsvString = $result['content'][0]['text'] ?? '';
			
			$lines = explode("\n", trim($tsvString));
			
			// Odstranìní úvodního textového popisu (napø. "Nalezen klient:\n")
			if (count($lines) > 0 && !str_contains($lines[0], "\t")) {
				array_shift($lines);
			}

			if (count($lines) >= 2) {
				// Hlavièka + data oddìlená tabulátorem (\t)
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
	 * Ideální pro zobrazení v prohlížeèi (info.php).
	 * * @return string Kompletní HTML kód pro vložení do dashboardu.
	 */
	public function getResponseAsHtml(): string {
		if ($this->last_error !== null) {
			return "<div class='error' style='color: #d93025; padding: 10px; font-weight: bold;'>Chyba: " . htmlspecialchars($this->last_error) . "</div>";
		}
		if (empty($this->mcp_tool_data)) {
			return "<div class='warning' style='padding: 10px;'>Žádná data nebyla nalezena.</div>";
		}

		$html  = "<table class='response-table' style='width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff;'>\n";
		
		// Vložení hlavièky (klíèe asociativního pole)
		$html .= "\t<thead>\n\t\t<tr style='background: #f8f9fa;'>\n";
		foreach (array_keys($this->mcp_tool_data[0]) as $colName) {
			$html .= "\t\t\t<th style='padding: 10px; border: 1px solid #e2e8f0; text-align: left;'>" . htmlspecialchars((string)$colName) . "</th>\n";
		}
		$html .= "\t\t</tr>\n\t</thead>\n";
		
		// Vložení tìl tabulky
		$html .= "\t<tbody>\n";
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
	 * Agregace prvního result setu do TSV formátu pro maximální úsporu tokenù v AI kontextu.
	 * * @return array Struktura pro MCP JSON-RPC response.
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

		// Finální textový øetìzec obsahující TSV data
		$tsv = implode("\t", array_keys($this->mcp_tool_data[0])) . "\n";
		
		foreach ($this->mcp_tool_data as $row) {
			// Sanitize výstupu: zabránìní rozbití TSV formátu nahrazením nepovolených znakù
			$rowStr = array_map(function($val) {
				// Nahrazení nových øádkù a tabulátorù uvnitø hodnot prostou mezerou
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
	 * Zaznamená MCP transakci, vstupní/výstupní payloady a dobu trvání.
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