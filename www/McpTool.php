<?php
/**
 * Abstraktní základ pro všechny MCP nástroje.
 * Zajišťuje jednotné rozhraní pro validaci vstupů a formátování odpovědí.
 */
abstract class McpTool {
	/** * @var resource $db Drží aktivní spojení na MSSQL přes sqlsrv_connect.
	 */
	protected $db;

	/**
	 * Konstruktor přijímá databázové spojení.
	 */
	public function __construct($db) {
		$this->db = $db;
	}

	/**
	 * Hlavní validační logika volaná z index.php před samotným execute.
	 * Kontroluje povinnost polí a validitu UUID.
	 */
	public function validateAndExecute(array $params, array $definitions): array {
		foreach ($definitions as $def) {
			$name = $def['param_name'];
			$val = $params[$name] ?? '';

			// Kontrola povinného parametru
			if ($def['is_required'] && ($val === null || $val === '')) {
				return $this->error("Parametr '$name' je povinný.");
			}

			// Typová kontrola pro UUID (pokud je hodnota vyplněna)
			if ($def['param_type'] === 'uuid' && !empty($val)) {
				if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
					return $this->error("Parametr '$name' musí být platné UUID.");
				}
			}
		}
		return $this->execute($params);
	}

	/**
	 * Samotná implementace logiky nástroje (SQL dotazy, transformace dat).
	 * Musí být definována v každé podtřídě v adresáři /tools/.
	 */
	abstract public function execute(array $params): array;

	/**
	 * Formátuje úspěšný výsledek pro MCP protokol.
	 */
	protected function success(string $text): array {
		return [
			"content" => [
				[
					"type" => "text",
					"text" => $text
				]
			]
		];
	}

	/**
	 * Formátuje chybové hlášení tak, aby mu AI model rozuměl.
	 */
	protected function error(string $message): array {
		return [
			"isError" => true,
			"content" => [
				[
					"type" => "text",
					"text" => "Chyba při provádění nástroje: " . $message
				]
			]
		];
	}
}