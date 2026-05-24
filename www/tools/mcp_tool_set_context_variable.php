<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_tool_set_context_variable.php
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento non-generic MCP nástroj umožňuje modelu umělé inteligence explicitně
 * zapisovat nebo mazat hodnoty v dočasné paměti chatu (mcp_saved_values).
 * * LOGIKA POLE (ARRAY):
 * Nástroj podporuje ukládání polí opakovaným voláním se stejným názvem proměnné.
 * AI tak může například postupně uložit 3 různá ID do stejného pole.
 * Pokud se předá pouze variable_name (bez variable_value), celá proměnná se vymaže.
 */
class mcp_tool_set_context_variable extends McpTool {
	
	/**
	 * Hlavní výkonná metoda.
	 *
	 * @param array $params
	 * @return array
	 */
	public function execute(array $params): array {
		// 1. Získání identifikátoru aktuální databázové relace
		$sessionId = $GLOBALS['mcp_session_id'] ?? '';
		
		if (empty($sessionId)) {
			return $this->error("Kritická chyba: Chybí kontext databázové relace (mcp_session_id).");
		}
		
		$varName  = $params['variable_name'] ?? '';
		$varValue = $params['variable_value'] ?? null;
		
		// 2. Ochrana integrity databáze (Odpovídá CHECK constraintu tabulky mcp_saved_values)
		if (!preg_match('/^[a-z0-9_]+$/', $varName)) {
			return $this->error("Parametr 'variable_name' obsahuje nepovolené znaky. Povolena jsou pouze malá písmena bez diakritiky, číslice a podtržítko.");
		}
		
		// -------------------------------------------------------------------------
		// REŽIM 1: Výmaz proměnné (pokud chybí hodnota)
		// -------------------------------------------------------------------------
		if ($varValue === null || $varValue === '') {
			$sqlDelete  = "DELETE FROM mcp_saved_values WHERE wwwsession = ? AND save_as = ?";
			$stmtDelete = sqlsrv_query($this->db, $sqlDelete, [$sessionId, $varName]);
			
			if ($stmtDelete === false) {
				return $this->error("Chyba při mazání proměnné: " . print_r(sqlsrv_errors(), true));
			}
			
			$deleted = sqlsrv_rows_affected($stmtDelete);
			if ($deleted === false || $deleted < 0) {
				$deleted = 0;
			}
			sqlsrv_free_stmt($stmtDelete);
			
			return $this->success("rows\tmsg\n1\tProměnná '{$varName}' byla vymazána (smazáno záznamů: {$deleted}).\t");
		}
		
		// -------------------------------------------------------------------------
		// REŽIM 2: Přidání hodnoty (podpora polí přes inkrementaci row_index)
		// -------------------------------------------------------------------------
		
		// 3. Zjištění aktuálně nejvyššího indexu pro danou proměnnou (Claim-Check)
		// Pokud proměnná ještě neexistuje, vrátí -1 (takže další vložený prvek bude mít index 0)
		$sqlMax  = "SELECT ISNULL(MAX(row_index), -1) AS max_idx FROM mcp_saved_values WHERE wwwsession = ? AND save_as = ?";
		$stmtMax = sqlsrv_query($this->db, $sqlMax, [$sessionId, $varName]);
		
		if ($stmtMax === false) {
			return $this->error("Chyba při zjišťování indexu pole: " . print_r(sqlsrv_errors(), true));
		}
		
		$rowMax  = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC);
		$nextIdx = (int)$rowMax['max_idx'] + 1;
		sqlsrv_free_stmt($stmtMax);
		
		// 4. Oříznutí hodnoty podle limitu sloupce v DB (NVARCHAR 200)
		$strVal = (string)$varValue;
		if (mb_strlen($strVal) > 200) {
			$strVal = mb_substr($strVal, 0, 200);
		}
		
		// 5. Fyzické vložení nového prvku (nebo jediné hodnoty)
		$sqlInsert  = "INSERT INTO mcp_saved_values (wwwsession, save_as, row_index, saved_data) VALUES (?, ?, ?, ?)";
		$stmtInsert = sqlsrv_query($this->db, $sqlInsert, [$sessionId, $varName, $nextIdx, $strVal]);
		
		if ($stmtInsert === false) {
			return $this->error("Chyba při ukládání hodnoty do DB: " . print_r(sqlsrv_errors(), true));
		}
		sqlsrv_free_stmt($stmtInsert);
		
		return $this->success("rows\tmsg\n1\tHodnota úspěšně uložena do proměnné '{$varName}' na pozici indexu [{$nextIdx}].\t");
	}
}