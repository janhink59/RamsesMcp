<?php
declare(strict_types=1);

/**
 * RamsesMcp - McpTool (Abstraktní základ pro PHP nástroje)
 * * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Toto je základní třída (Base Class) pro všechny "Custom" MCP nástroje 
 * implementované v PHP. Každý nový soubor v adresáři /tools/ (např. Get_client_detail.php)
 * MUSÍ dědit z této třídy.
 *
 * * * HLAVNÍ FUNKCE:
 * 1. UNIFIKACE VSTUPŮ: Zajišťuje, že parametry od AI klienta projdou validací 
 * (povinnost, formát UUID) dříve, než se dostanou k samotné logice.
 * 2. STANDARDIZACE VÝSTUPŮ: Poskytuje metody success() a error(), které balí 
 * výsledky do struktury vyžadované MCP protokolem.
 * 3. DB KONEKTIVITA: Drží sdílené spojení na MSSQL, které získala od db_interface.
 */
abstract class McpTool {
	
	/** * @var resource $db Drží aktivní spojení na MSSQL (Singleton z db_interface).
	 */
	protected $db;

	/**
	 * Konstruktor přijímá již otevřené a autentizované databázové spojení.
	 * * @param resource $db
	 */
	public function __construct($db) {
		$this->db = $db;
	}

	/**
	 * Validační brána volaná z db_interface před samotným spuštěním execute().
	 * * * DESIGN DECISION:
	 * Tato metoda odděluje "špinavou práci" s kontrolou typů od samotné 
	 * byznys logiky nástroje. Pokud validace selže, execute() se vůbec nespustí.
	 *
	 * @param array<string, mixed> $params Očekávané parametry (klíč => hodnota) od AI klienta.
	 * @param array<int, array{param_name: string, param_type: string, is_required: int|bool}> $definitions Definice z DB tabulky mcp_tool_param.
	 * @return array MCP formátovaná odpověď (success nebo error).
	 */
	public function validateAndExecute(array $params, array $definitions): array {
		foreach ($definitions as $def) {
			$name = $def['param_name'];
			$val = $params[$name] ?? '';

			// 1. Kontrola povinného parametru (nesmí být null ani prázdný řetězec)
			if ($def['is_required'] && ($val === null || $val === '')) {
				return $this->error("Parametr '$name' je povinný.");
			}

			// 2. Typová kontrola pro UUID (pokud je hodnota vyplněna)
			// DESIGN DECISION: Striktní Regex kontrola chrání SQL dotazy před nevalidními literály.
			if ($def['param_type'] === 'uuid' && !empty($val)) {
				if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
					return $this->error("Parametr '$name' musí být platné UUID.");
				}
			}
		}
		
		// Pokud je vše v pořádku, delegujeme řízení na konkrétní implementaci
		return $this->execute($params);
	}

	/**
	 * Hlavní byznys logika nástroje (SQL dotazy, transformace).
	 * Musí být definována v každé podtřídě v adresáři /tools/.
	 * * * POŽADAVEK NA VÝSTUP (PRO AI):
	 * Výstup by měl být ideálně v TSV formátu (Tab-Separated Values) pro 
	 * úsporu tokenů v AI kontextu.
	 *
	 * @param array<string, mixed> $params Již validované parametry.
	 * @return array Standardní MCP Content pole.
	 */
	abstract public function execute(array $params): array;

	/**
	 * Formátuje úspěšný výsledek tak, aby mu AI model rozuměl.
	 * * @param string $text Textový výsledek (ideálně TSV tabulka).
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
	 * Formátuje chybové hlášení tak, aby AI věděla, že se něco nepovedlo.
	 * * @param string $message Popis chyby (např. "Klient nebyl nalezen").
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