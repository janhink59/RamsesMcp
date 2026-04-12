<?php
declare(strict_types=1);

/**
 * Registr MCP nástrojů.
 * Načítá definice nástrojů a jejich parametrů z databáze a formátuje je pro MCP Discovery.
 */
class McpRegistry {
	/** @var resource $db Drží aktivní spojení na MSSQL přes sqlsrv_connect. */
	private $db;

	/**
	 * @param resource $db
	 */
	public function __construct($db) {
		$this->db = $db;
	}

	/**
	 * Načte všechny nástroje a jejich parametry a vrátí je ve formátu pro tools/list.
	 * @return array<int, array{
	 * name: string, 
	 * title: string, 
	 * description: string, 
	 * inputSchema: array{
	 * type: string, 
	 * properties: array<string, array{type: string, title: string, description: string}>, 
	 * required: string[]
	 * }
	 * }>
	 */
	public function getTools(): array {
		$sql = "SELECT t.name, t.title AS tool_title, t.description, 
					   p.param_name, p.param_title, p.param_type, p.description AS param_desc, p.is_required
				FROM mcp_tool t
				LEFT JOIN mcp_tool_param p ON t.mcp_tool = p.mcp_tool
				ORDER BY t.name";

		$query = sqlsrv_query($this->db, $sql);
		if ($query === false) {
			return [];
		}

		$tools = [];
		while ($row = sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC)) {
			$tName = $row['name'];
			if (!isset($tools[$tName])) {
				$tools[$tName] = [
					"name" => $tName,
					"title" => $row['tool_title'],
					"description" => ($row['tool_title'] !== '' ? $row['tool_title'] . " - " : "") . $row['description'],
					"inputSchema" => [
						"type" => "object",
						"properties" => [],
						"required" => []
					]
				];
			}

			if ($row['param_name']) {
				$jsonType = ($row['param_type'] === 'number' || $row['param_type'] === 'bigint') ? 'number' : 'string';
				
				$tools[$tName]['inputSchema']['properties'][$row['param_name']] = [
					"type" => $jsonType,
					"title" => $row['param_title'],
					"description" => $row['param_desc'] . ($row['param_type'] === 'uuid' ? " (UUID)" : "")
				];

				if ($row['is_required']) {
					$tools[$tName]['inputSchema']['required'][] = $row['param_name'];
				}
			}
		}

		return array_values($tools);
	}
}