<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_report.php (Vizuální prohlížeč reportů a master dispečer)
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript běžící pod standardním prohlížečem slouží jako jediný integrační
 * bod mezi pamětí LLM (mcp_saved_values) a starším ekosystémem Ramses.
 * 1. Zjistí název serveru a načte specifický multitenantní rcfg_*.php.
 * 2. Načte kompletní řetězec IP adres (fingerprint) přes RamsesLib.php.
 * 3. Zvedne nativní PHP session prohlížeče (nesmí se přihlašovat jako bot!).
 * 4. Zavolá proceduru mcp_join_session_by_ip, která spáruje prohlížeč s AI relací.
 * 5. Načte všechna připravená data k reportu pod LLM kontextem.
 * 6. Injektuje data v nativním formátu přímo do superglobálního pole $_POST.
 * 7. Deleguje řízení na sub-report (mcp_report_{kód}.php), pokud existuje, nebo na mcp_report_generic.php.
 */

ob_start();
ini_set('display_errors', '1');                                 // Zapnuto pro okamžitou vizuální diagnostiku chyb v prohlížeči
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

// 1. DYNAMICKÉ NAČTENÍ MULTITENANTNÍ KONFIGURACE (Shodně s index.php)
$CONFIG_SERVER_NAME = $_SERVER['SERVER_NAME'] ?? '';
if (empty($CONFIG_SERVER_NAME) && isset($_SERVER['HTTP_HOST'])) {
	$CONFIG_SERVER_NAME = explode(':', $_SERVER['HTTP_HOST'])[0];
}
$CONFIG_SERVER_NAME = preg_replace('/[^a-zA-Z0-9_.-]/', '', $CONFIG_SERVER_NAME);

if ($CONFIG_SERVER_NAME === '') {
	$CONFIG_SERVER_NAME = 'localhost';
}

$configFile = __DIR__ . '/rcfg_' . $CONFIG_SERVER_NAME . '.php';

if (!file_exists($configFile)) {
	ob_end_clean();
	header('Content-Type: text/html; charset=utf-8');
	die("<div style='color: #d93025; font-family: sans-serif; padding: 20px;'><strong>Kritická chyba:</strong> Konfigurační soubor <code>rcfg_{$CONFIG_SERVER_NAME}.php</code> nebyl pro tento host nalezen.</div>");
}

$config = require_once $configFile;
require_once __DIR__ . '/db_connect.php';

// 2. NAČTENÍ CENTRALIZOVANÝCH KNIHOVEN A URL DETEKCE
// Symlink-safe metoda pro nalezení nadřazeného rootu Ramses
$virtualDir = dirname($_SERVER['SCRIPT_FILENAME']);
$parentDir  = dirname($virtualDir); 
$documentRoot = $parentDir;                                     // Fallback pro starší sub-reporty
require_once $parentDir . '/RamsesLib.php';
require_once __DIR__ . '/detect_url.php';

if (isset($_SERVER['HTTP_X_MCP_USER']) && trim($_SERVER['HTTP_X_MCP_USER']) !== '') {
	$config['mcp']['user'] = trim($_SERVER['HTTP_X_MCP_USER']);
}
if (isset($_SERVER['HTTP_X_MCP_PASS']) && trim($_SERVER['HTTP_X_MCP_PASS']) !== '') {
	$config['mcp']['password'] = trim($_SERVER['HTTP_X_MCP_PASS']);
}

$reportCode = $_GET['report_code'] ?? '';

if (empty($reportCode)) {
	ob_end_clean();
	die("Chyba: Nebyl zadán kód reportu (parametr report_code chybí).");
}

// Získáváme kompletní síťovou stopu (IP path chain) pro bezpečné ověření identity
$ip = get_client_ip_path();

try {
	// ========================================================================
	// 3. NASTARTOVÁNÍ NATIVNÍ PHP RELACE PROHLÍŽEČE
	// ========================================================================
	// Zvedneme existující session prohlížeče (nebo ji vytvoříme, pokud jde o první přístup).
	// Skript nesmí volat authenticateMcp(), aby nespustil úklid AI paměti!
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	$sessionId = session_id();
	
	if (empty($sessionId)) {
		throw new Exception("Nepodařilo se inicializovat nativní PHP session prohlížeče.");
	}

	// 4. Otevření fyzického databázového spojení
	$conn = getMssqlConnection();
	
	// ========================================================================
	// 5. HANDOFF KONTEXTU PŘES ŘETĚZEC IP ADRES (Network Fingerprinting)
	// ========================================================================
	// Voláme proceduru, která na základě IP cesty vyhledá MCP session, 
	// bezpečně ji adoptuje/oživí a vrátí její kód.
	$sqlJoin = "EXEC mcp_join_session_by_ip @wwwsession = ?, @ip = ?";
	$stmtJoin = sqlsrv_query($conn, $sqlJoin, [$sessionId, $ip]);
	
	if ($stmtJoin === false) {
		throw new Exception("Chyba při volání procedury mcp_join_session_by_ip: " . print_r(sqlsrv_errors(), true));
	}
	
	// Přeskočení případných SET NOCOUNT a informačních hlášek z vnitřku T-SQL
	while (!sqlsrv_has_rows($stmtJoin)) {
		$next = sqlsrv_next_result($stmtJoin);
		if ($next === false || $next === null) {
			break;
		}
	}
	
	$llmSessionId = null;                                       // Inicializace na čistý NULL
	if (sqlsrv_has_rows($stmtJoin)) {
		$rowJoin = sqlsrv_fetch_array($stmtJoin, SQLSRV_FETCH_ASSOC);
		if (!empty($rowJoin['llm_session'])) {
			$llmSessionId = $rowJoin['llm_session'];
		}
	}
	sqlsrv_free_stmt($stmtJoin);

	// VALIDACE ÚSPĚCHU: SQL procedura vrací buď platné MCP_ID, nebo čisté NULL.
	if ($llmSessionId === null) {
		throw new Exception("Nepodařilo se spárovat relaci prohlížeče s aktivním kontextem AI asistenta. Aktivní MCP relace pro tuto zřetězenou IP stopu buď neexistuje, vypršela (timeout 60 min), nebo byla vyvolána z jiného PC/VPN segmentu než samotný model.");
	}
	// ========================================================================
	
	// Načtení metadat reportu
	$sqlReport  = "SELECT title, description FROM mcp_report WHERE report_code = ?";
	$stmtReport = sqlsrv_query($conn, $sqlReport, [$reportCode]);
	
	if ($stmtReport === false) {
		throw new Exception("Chyba při dotazu na metadata: " . print_r(sqlsrv_errors(), true));
	}
	
	while (!sqlsrv_has_rows($stmtReport)) {
		$next = sqlsrv_next_result($stmtReport);
		if ($next === false) throw new Exception("Chyba u reportu.");
		elseif ($next === null) break;
	}
	
	$reportMeta = sqlsrv_fetch_array($stmtReport, SQLSRV_FETCH_ASSOC);
	sqlsrv_free_stmt($stmtReport);
	
	if (!$reportMeta) {
		throw new Exception("Report s kódem '{$reportCode}' nebyl nalezen.");
	}

	// Extrakce dat z MCP paměti podle dohledaného původního LLM session ID
	$sqlParams = "
		SELECT 
			p.param_name, 
			p.param_title, 
			p.param_type, 
			p.is_array, 
			p.is_required,
			p.description AS param_desc,
			v.row_index,
			v.saved_data
		FROM mcp_report_param p
		LEFT JOIN mcp_saved_values v 
			ON p.param_name = v.save_as 
			AND v.wwwsession = ?                                    
		WHERE p.report_code = ?
		ORDER BY p.param_name, v.row_index
	";
	
	$stmtParams = sqlsrv_query($conn, $sqlParams, [$llmSessionId, $reportCode]);
	
	if ($stmtParams === false) {
		throw new Exception("Chyba při dotazu na parametry: " . print_r(sqlsrv_errors(), true));
	}
	
	while (!sqlsrv_has_rows($stmtParams)) {
		$next = sqlsrv_next_result($stmtParams);
		if ($next === false) throw new Exception("Chyba u parametrů.");
		elseif ($next === null) break;
	}
	
	$parameters = [];
	if (sqlsrv_has_rows($stmtParams)) {
		while ($row = sqlsrv_fetch_array($stmtParams, SQLSRV_FETCH_ASSOC)) {
			$pName = $row['param_name'];
			
			if (!isset($parameters[$pName])) {
				$parameters[$pName] = [
					'title'    => $row['param_title'],
					'type'     => $row['param_type'],
					'is_array' => (bool)$row['is_array'],
					'required' => (bool)$row['is_required'],
					'desc'     => $row['param_desc'],
					'values'   => []
				];
			}
			
			if ($row['saved_data'] !== null) {
				$parameters[$pName]['values'][] = $row['saved_data'];
				
				// --- INJEKCE DO NATIVNÍHO POLE $_POST ---
				if ($row['is_array']) {
					if (!isset($_POST[$pName])) {
						$_POST[$pName] = [];
					}
					$_POST[$pName][] = $row['saved_data'];
				} else {
					$_POST[$pName] = $row['saved_data'];
				}
			}
		}
	}
	sqlsrv_free_stmt($stmtParams);
	
	ob_end_clean();
	
} catch (Throwable $e) {
	ob_end_clean();
	die("<div style='color: #d93025; font-family: sans-serif; padding: 20px; background: #fce8e6; border: 1px solid #fad2cf; border-radius: 8px; margin: 20px;'><strong>Kritická chyba předání kontextu:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ============================================================================
// DELEGACE NA SUB-REPORT NEBO GENERICKÝ VYKRESLOVAČ
// ============================================================================
$customReportFile = __DIR__ . "/mcp_report_" . $reportCode . ".php";
if (file_exists($customReportFile)) {
	include $customReportFile;
	exit;
} else {
	include __DIR__ . "/mcp_report_generic.php";
	exit;
}