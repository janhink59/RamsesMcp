<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_tool_prepare_report.php
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento non-generic MCP nástroj slouží jako finální validační brána před zobrazením
 * reportu uživateli. Umělá inteligence jej zavolá ve chvíli, kdy si myslí, že
 * už nasbírala a uložila všechna potřebná data.
 * * * * LOGIKA:
 * 1. Zkontroluje existenci `report_code` v tabulce mcp_report.
 * 2. Zjistí, zda pro report existuje specifický PHP proxy skript (např. mcp_report_soa.php).
 * 3. POKUD NEEXISTUJE, jde o generický report a MUSÍ pro něj v DB existovat SQL procedura.
 * 4. Zjistí všechny povinné parametry pro tento report v mcp_report_param.
 * 5. Ověří, že pro všechny povinné parametry existuje záznam v mcp_saved_values.
 * 6. Pokud něco chybí nebo neexistuje výkonná vrstva, vrátí TSV s rows=-1.
 * 7. Pokud je vše OK, vygeneruje absolutní URL.
 */
class mcp_tool_prepare_report extends McpTool {
	
	/**
	 * Hlavní výkonná metoda.
	 * * @param array{report_code: string} $params 
	 * @return array
	 */
	public function execute(array $params): array {
		global $full_base_url; // Bezpečně sestaveno v detect_url.php
		
		// 1. Získání identifikátoru aktuální databázové relace (LLM kontext)
		$sessionId = $GLOBALS['mcp_session_id'] ?? '';
		if (empty($sessionId)) {
			return $this->error("Kritická chyba: Chybí kontext databázové relace (mcp_session_id).");
		}
		
		// 2. Extrakce a validace vstupního parametru
		$reportCode = $params['report_code'];
		
		// 3. Ověření existence reportu a jeho metadat
		$sqlReport = "SELECT title, procedure_name FROM mcp_report WHERE report_code = ?";
		$stmtReport = sqlsrv_query($this->db, $sqlReport, [$reportCode]);
		
		if ($stmtReport === false) {
			return $this->error("Chyba při dotazu do číselníku reportů: " . print_r(sqlsrv_errors(), true));
		}
		
		$report = sqlsrv_fetch_array($stmtReport, SQLSRV_FETCH_ASSOC);
		if (!$report) {
			return $this->success("rows\tmsg\turl\n-1\tReport s kódem '{$reportCode}' v systému neexistuje. Ověřte kód reportu.\t");
		}

		// Ošetření prázdného názvu (fallback na report_code, pokud je title prázdné)
		$reportTitle = trim((string)$report['title']);
		if ($reportTitle === '') {
			$reportTitle = $reportCode;
		}

		// 4. Validace existence výkonné vrstvy (Custom proxy vs Generická SQL procedura)
		$customReportFile = dirname(__DIR__) . "/mcp_report_" . $reportCode . ".php";
		
		if (!file_exists($customReportFile)) {
			// A) Neexistuje specifický PHP proxy skript. Report tím pádem spadne do generického
			// zpracování. Musí tedy existovat fyzická SQL procedura!
			$procName = trim((string)$report['procedure_name']);
			if ($procName === '') {
				$procName = 'mcp_report_' . $reportCode;
			}
			
			$sqlProcCheck = "SELECT OBJECT_ID(?) AS proc_id";
			$stmtProcCheck = sqlsrv_query($this->db, $sqlProcCheck, [$procName]);
			$rowProc = sqlsrv_fetch_array($stmtProcCheck, SQLSRV_FETCH_ASSOC);
			
			if (empty($rowProc['proc_id'])) {
				return $this->success("rows\tmsg\turl\n-1\tNelze spustit report. V databázi chybí výkonná T-SQL procedura '{$procName}' pro report '{$reportTitle}'.\t");
			}
		}
		// B) Pokud file_exists vrátí true (např. mcp_report_soa.php), proceduru v DB vůbec nehledáme.
		
		// 5. Křížová kontrola povinných parametrů (Claim Check Pattern)
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
		
		// Vyhodnocení chybějících parametrů
		if (!empty($missingParams)) {
			$missingList = implode(', ', $missingParams);
			return $this->success("rows\tmsg\turl\n-1\tNelze spustit report. V dočasné paměti chybí připravené parametry: {$missingList}\t");
		}
		
		// 6. Sestavení finální absolutní URL
		$finalUrl = $full_base_url . "mcp_report.php?report_code=" . urlencode((string)$reportCode);
		
		// Návrat úspěšného výsledku (rows=1, zpráva, URL adresa)
		return $this->success("rows\tmsg\turl\n1\tData pro report '{$reportTitle}' jsou připravena úspěšně.\t{$finalUrl}");
	}
}