<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_tool_reset_context.php
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento non-generic MCP nástroj slouží k úplnému vyčištění dočasné paměti
 * (Claim-Check patternu) pro aktuálního uživatele/session.
 * Maže všechny záznamy z tabulky mcp_saved_values, které si LLM uložil
 * přes parametr save_as. AI by jej měla volat na začátku nového scénáře.
 */
class mcp_tool_reset_context extends McpTool {
	
	/**
	 * Hlavní výkonná metoda.
	 * Nástroj neočekává žádné povinné parametry, pracuje pouze s interním kontextem.
	 *
	 * @param array $params
	 * @return array
	 */
	public function execute(array $params): array {
		// 1. Získání identifikátoru aktuální databázové relace (z db_connect.php)
		$sessionId = $GLOBALS['mcp_session_id'] ?? '';
		
		if (empty($sessionId)) {
			return $this->error("Kritická chyba: Chybí kontext databázové relace (mcp_session_id).");
		}
		
		// 2. Sestavení a spuštění DELETE dotazu pro vyčištění kontextu uživatele
		$sql  = "DELETE FROM mcp_saved_values WHERE wwwsession = ?";
		$stmt = sqlsrv_query($this->db, $sql, [$sessionId]);
		
		if ($stmt === false) {
			return $this->error("Chyba při mazání kontextových proměnných: " . print_r(sqlsrv_errors(), true));
		}
		
		// 3. Zjištění počtu smazaných řádků (pro lepší feedback umělé inteligenci)
		$deletedRows = sqlsrv_rows_affected($stmt);
		if ($deletedRows === false || $deletedRows < 0) {
			$deletedRows = 0;
		}
		
		// Uvolnění prostředků
		sqlsrv_free_stmt($stmt);
		
		// 4. Návrat úspěšného výsledku ve formátu TSV (šetří tokeny)
		return $this->success("rows\tmsg\n1\tKontext byl úspěšně vyčištěn. Smazáno {$deletedRows} záznamů z paměti chatu.\t");
	}
}