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
     * * @param resource $db
	 */
	public function __construct($db) {
		$this->db = $db;
	}

	/**
	 * Hlavní validační logika volaná z index.php před samotným execute.
	 * Kontroluje povinnost polí a validitu UUID.
     * * @param array<string, mixed> $params Očekávané parametry (klíč => hodnota)
     * @param array<int, array{param_name: string, param_type: string, is_required: int|bool}> $definitions Definice z DB
     * @return array MCP formátovaná odpověď
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
     * * @param array<string, mixed> $params
     * @return array
	 */
	abstract public function execute(array $params): array;

	/**
	 * Formátuje úspěšný výsledek pro MCP protokol.
     * * @param string $text
     * @return array{content: array<int, array{type: string, text: string}>}
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
     * * @param string $message
     * @return array{isError: true, content: array<int, array{type: string, text: string}>}
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