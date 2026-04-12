<?php

class Get_client_detail extends McpTool {
    public function execute(array $params): array {
        // Kontrola vstupních parametrù
        if (!isset($params['clientId'])) {
            return $this->error("Chybí povinný parametr 'clientId'.");
        }
        
        $clientId = $params['clientId'];
        
        // Využití pøipraveného DB pøipojení ($this->db)
        $stmt = $this->db->prepare("SELECT Jmeno, Prijmeni, Email FROM Klienti WHERE Id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            return $this->error("Klient s ID {$clientId} nebyl nalezen.");
        }

        // Formátování a odeslání úspìšné odpovìdi
        $resultText = "Nalezen klient: {$client['Jmeno']} {$client['Prijmeni']} (Email: {$client['Email']})";
        return $this->success($resultText);
    }
}