<?php
declare(strict_types=1);

/**
 * RamsesMcp - Get_prepare_report.php
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento non-generic MCP nástroj slouží jako finální validační brána před zobrazením
 * reportu uživateli. Umělá inteligence jej zavolá ve chvíli, kdy si myslí, že
 * už nasbírala a uložila všechna potřebná data (přes save_as parametr u jiných nástrojů).
 * * * * LOGIKA:
 * 1. Zkontroluje existenci `report_code` v tabulce mcp_report.
 * 2. Zjistí všechny povinné parametry pro tento report v mcp_report_param.
 * 3. Ověří, že pro všechny povinné parametry existuje záznam v mcp_saved_values pod aktuální SPID/wwwsession.
 * 4. Pokud něco chybí, vrátí TSV s rows=-1 a vyjmenuje chybějící data.
 * 5. Pokud je vše OK, vygeneruje absolutní proklikávací URL s využitím globální detekce Base URL.
 */
class Get_prepare_report extends McpTool {
	
	/**
	 * Hlavní výkonná metoda.
	 * * @param array{report_code: string} $params 
	 * @return array
	 */
	public function execute(array $params): array {
		global $config;
		
		// 1. Získání identifikátoru aktuální databázové relace
		$sessionId = $GLOBALS['mcp_session_id'] ?? '';
		if (empty($sessionId)) {
			return $this->error("Kritická chyba: Chybí kontext databázové relace (mcp_session_id).");
		}
		
		// 2. Extrakce a validace vstupního parametru (přítomnost zaručuje rodičovská třída)
		$reportCode = $params['report_code'];
		
		// 3. Ověření existence reportu v databázi
		$sqlReport = "SELECT title FROM mcp_report WHERE report_code = ?";
		$stmtReport = sqlsrv_query($this->db, $sqlReport, [$reportCode]);
		
		if ($stmtReport === false) {
			return $this->error("Chyba při dotazu do číselníku reportů: " . print_r(sqlsrv_errors(), true));
		}
		
		$report = sqlsrv_fetch_array($stmtReport, SQLSRV_FETCH_ASSOC);
		if (!$report) {
			// Report nenalezen -> Vracíme LLM řízenou chybu přes TSV (aby AI mohla reagovat v chatu)
			return $this->success("rows\tmsg\turl\n-1\tReport s kódem '{$reportCode}' neexistuje.\t");
		}
		
		// 4. Křížová kontrola povinných parametrů vůči uloženým hodnotám v session (Claim Check Pattern)
		// Tento dotaz vybere názvy těch parametrů, které jsou povinné, ale CHYBÍ v mcp_saved_values.
		$sqlMissing = "
			SELECT p.param_name
			FROM mcp_report_param p
			WHERE p.report_code = ?
			  AND p.is_required = 1
			  AND NOT EXISTS (
				  SELECT 1 
				  FROM mcp_saved_values v
				  WHERE v.wwwsession = ? 
				    AND v.save_as = p.param_name
			  )
		";
		
		$stmtMissing = sqlsrv_query($this->db, $sqlMissing, [$reportCode, $sessionId]);
		
		if ($stmtMissing === false) {
			return $this->error("Chyba při validaci parametrů reportu: " . print_r(sqlsrv_errors(), true));
		}
		
		$missingParams = [];
		while ($row = sqlsrv_fetch_array($stmtMissing, SQLSRV_FETCH_ASSOC)) {
			$missingParams[] = $row['param_name'];
		}
		
		// 5. Vyhodnocení výsledku a generování odpovědi
		if (!empty($missingParams)) {
			// Pokud data chybí, vrátíme model instrukci, ať si je nejdříve sežene a uloží.
			$missingList = implode(', ', $missingParams);
			return $this->success("rows\tmsg\turl\n-1\tNelze spustit report. V dočasné paměti chybí připravené parametry: {$missingList}\t");
		}
		
		// 6. Sestavení finální absolutní URL
		$baseUrl  = $config['mcp']['base_url'] ?? '';
		$finalUrl = rtrim($baseUrl, '/') . "/mcp_report.php?report_code=" . urlencode((string)$reportCode);
		
		// Návrat úspěšného výsledku (rows=1, zpráva, URL adresa)
		return $this->success("rows\tmsg\turl\n1\tData pro report '{$report['title']}' jsou připravena úspěšně.\t{$finalUrl}");
	}
}