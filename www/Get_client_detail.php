<?php
/**
 * MCP Nástroj pro získání detailu klienta.
 * Implementováno pomocí nativního ovladače sqlsrv.
 */
class Get_client_detail extends McpTool {
    
    /**
     * Vykoná SQL dotaz a vrátí data ve formátu TSV.
     * * @param array{clientId?: string} $params Očekávané parametry (clientId je UUID)
     * @return array
     */
    public function execute(array $params): array {
        // Kontrola vstupních parametrů (validace UUID proběhla v McpTool)
        if (!isset($params['clientId'])) {
            return $this->error("Chybí povinný parametr 'clientId'.");
        }
        
        $clientId = $params['clientId'];
        
        // SQL dotaz pro MSSQL
        $sql = "SELECT Jmeno, Prijmeni, Email FROM Klienti WHERE Id = ?";
        
        // Spuštění dotazu přes sqlsrv_query s parametry (ochrana proti SQL Injection)
        $stmt = sqlsrv_query($this->db, $sql, [$clientId]);
        
        if ($stmt === false) {
            return $this->error("Chyba při provádění dotazu: " . print_r(sqlsrv_errors(), true));
        }

        $client = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if (!$client) {
            return $this->error("Klient s ID {$clientId} nebyl nalezen.");
        }

        // Formátování výstupu jako TSV (Tab-Separated Values)
        // Hlavička + data oddělená tabulátorem (\t)
        $header = "Jmeno\tPrijmeni\tEmail";
        $data = "{$client['Jmeno']}\t{$client['Prijmeni']}\t{$client['Email']}";
        
        $resultText = "Nalezen klient:\n" . $header . "\n" . $data;
        
        return $this->success($resultText);
    }
}