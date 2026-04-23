<?php
declare(strict_types=1);

/**
 * Registr MCP nástrojů.
 * Odpovídá za dotázání databáze, extrakci metadat a jejich formátování
 * do podoby JSON Schema, kterému MCP klienti (např. Ollama) nativně rozumí.
 */
class McpRegistry {
	/** @var resource $db Drží aktivní spojení na MSSQL přes sqlsrv_connect. */
	private $db;

	/**
	 * Konstruktor přijímá databázové spojení.
	 * * @param resource $db
	 */
	public function __construct($db) {
		$this->db = $db;
	}

	/**
	 * Načte všechny povolené nástroje a zanořené definice jejich parametrů.
	 * * @return array Strukturované pole připravené k serializaci do JSONu pro tools/list.
	 */
	public function getTools(): array {
		// Sloupec is_generic je nezbytný pro UI Dashboard a správné směrování v index.php.
		// Samotný MCP protokol tento údaj ignoruje, ale my ho neseme s sebou pro lokální logiku.
		$sql = "SELECT t.name, t.title AS tool_title, t.description, t.is_generic, 
					   p.param_name, p.param_title, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tool t
				LEFT JOIN mcp_tool_param p ON t.mcp_tool = p.mcp_tool
				ORDER BY t.name";

		$query = sqlsrv_query($this->db, $sql);
		
		// Při selhání dotazu se tiše vrátí prázdné pole, router to nevnímá jako kritickou chybu.
		if ($query === false) {
			return [];
		}

		$tools = [];
		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];
			
			// Pokud nástroj ještě není v poli definován, vytvoříme pro něj kořenový uzel.
			if (!isset($tools[$tName])) {
				// BEZPEČNÉ PŘETYPOVÁNÍ: Ošetření proti anomáliím nativního sqlsrv ovladače.
				// MSSQL typ BIT může přijít jako int(1), int(0), string("1"), string("0") nebo jako prázdná hodnota.
				$isGeneric = isset($row['is_generic']) && (int)$row['is_generic'] === 1;
				
				$tools[$tName] = [
					"name"        => $tName,
					"title"       => $row['tool_title'],
					"description" => ($row['tool_title'] !== '' ? $row['tool_title'] . " - " : "") . $row['description'],
					"is_generic"  => $isGeneric,                                    // Interní flag propisovaný pro test.php a index.php
					"inputSchema" => [
						"type"       => "object",
						"properties" => [],
						"required"   => []
					]
				];
			}

			// Pokud má nástroj navázané parametry, vložíme je do struktury inputSchema -> properties.
			if ($row['param_name']) {
				// Normalizace databázových typů na standardní datové typy podporované JSON Schema.
				$jsonType = ($row['param_type'] === 'number' || $row['param_type'] === 'bigint') ? 'number' : 'string';
				
				$tools[$tName]['inputSchema']['properties'][$row['param_name']] = [
					"type"        => $jsonType,
					"title"       => $row['param_title'],
					"description" => $row['param_desc'] . ($row['param_type'] === 'uuid' ? " (UUID)" : "")
				];

				// Záznam povinného pole do pole required, jak definuje MCP specifikace.
				if ($row['is_required']) {
					$tools[$tName]['inputSchema']['required'][] = $row['param_name'];
				}
			}
		}

		// Převod asociativního pole na indexované pole, které vyžaduje MCP specifikace (seznam).
		return array_values($tools);
	}
}