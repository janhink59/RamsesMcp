<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_report.php (Vizuální prohlížeč reportů a master dispečer)
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento skript běží pod standardním prohlížečem a slouží jako jediný integrační
 * bod mezi pamětí LLM (mcp_saved_values) a starším ekosystémem Ramses.
 * 1. Zjistí deterministický kontext relace přes authenticateMcp.
 * 2. Načte všechna připravená data k reportu pod tímto kontextem.
 * 3. Injektuje data v nativním formátu přímo do superglobálního pole $_POST.
 * 4. Určí absolutní root webu přes $_SERVER['DOCUMENT_ROOT'].
 * 5. Deleguje řízení na sub-report (mcp_report_{kód}.php), pokud existuje.
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

$config = require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';

// Globální definice kořenového adresáře webu (překonání symlinků/junctions)
$documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

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

$user = $config['mcp']['user'] ?? '';
$pass = $config['mcp']['password'] ?? '';
$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
	// Authentikace pro vytvoření platného deterministického DB kontextu
	$sessionId = authenticateMcp($user, $pass, $ip);
	$conn      = getMssqlConnection();
	
	// ========================================================================
	// DEBUG ZÓNA PRO KONTROLU KONTEXTU (Smaž po odladění!)
	// Pokud ti stále chodí prázdné data, odkomentuj tento řádek.
	// Zjistíš tak, jaké ID prohlížeč vygeneroval, a můžeš to porovnat s tím, 
	// co je fyzicky v databázi od LLM (mcp_0dc6693b6a4abf5e).
	// die("Vygenerovaný prohlížečový kontext: " . htmlspecialchars($sessionId));
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

	// Extrakce dat z MCP paměti podle lokálně vygenerovaného session ID
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
			AND v.wwwsession = ?                                    -- Identita je bezpečně odvozena
		WHERE p.report_code = ?
		ORDER BY p.param_name, v.row_index
	";
	
	$stmtParams = sqlsrv_query($conn, $sqlParams, [$sessionId, $reportCode]);
	
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
	die("<div style='color: #d93025; font-family: sans-serif; padding: 20px;'><strong>Kritická chyba:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}

// ============================================================================
// DELEGACE NA SUB-REPORT (Který nyní najde data v $_POST)
// ============================================================================
$customReportFile = __DIR__ . "/mcp_report_" . $reportCode . ".php";
if (file_exists($customReportFile)) {
	include $customReportFile;
	exit;
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title><?php echo htmlspecialchars($reportMeta['title'] ?? 'Report'); ?> - RamsesMcp Report</title>
	<style>
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1000px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
		h1 { margin-top: 0; color: #1a202c; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
		.desc { color: #4a5568; font-size: 1.1rem; margin-bottom: 2rem; }
		.param-box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; background: #fdfdfd; }
		.param-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; border-bottom: 1px dashed #cbd5e0; padding-bottom: 8px; }
		.param-title { font-size: 1.2rem; font-weight: bold; color: #2b6cb0; }
		.param-badges span { font-size: 0.8rem; padding: 3px 8px; border-radius: 12px; font-weight: bold; margin-left: 5px; }
		.badge-req { background: #fed7d7; color: #c53030; }
		.badge-opt { background: #e2e8f0; color: #4a5568; }
		.badge-type { background: #bfeeb7; color: #22543d; }
		.badge-array { background: #bee3f8; color: #2b6cb0; }
		.param-desc { font-size: 0.9rem; color: #718096; margin-bottom: 15px; }
		.value-list { list-style-type: none; padding: 0; margin: 0; }
		.value-list li { background: #fff; border: 1px solid #e2e8f0; padding: 8px 12px; margin-bottom: 5px; border-radius: 4px; font-family: 'Cascadia Code', monospace; font-size: 0.95rem; }
		.value-empty { color: #a0aec0; font-style: italic; }
		.btn-generate { display: block; width: 100%; background: #3182ce; color: white; border: none; padding: 15px; border-radius: 8px; font-size: 1.2rem; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; margin-top: 2rem; transition: background 0.3s; }
		.btn-generate:hover { background: #2b6cb0; }
	</style>
</head>
<body>

	<div class="card">
		<h1><?php echo htmlspecialchars($reportMeta['title']); ?></h1>
		<?php if (!empty($reportMeta['description'])): ?>
			<div class="desc"><?php echo nl2br(htmlspecialchars($reportMeta['description'])); ?></div>
		<?php endif; ?>
		
		<h3>Nasbíraná vstupní data (Claim-Check)</h3>
		
		<?php if (empty($parameters)): ?>
			<p>Tento report nevyžaduje předběžné shromáždění žádných parametrů.</p>
		<?php else: ?>
			<?php foreach ($parameters as $pName => $pData): ?>
				<div class="param-box">
					<div class="param-header">
						<div class="param-title"><?php echo htmlspecialchars($pData['title'] !== '' ? $pData['title'] : $pName); ?></div>
						<div class="param-badges">
							<span class="badge-type"><?php echo htmlspecialchars($pData['type']); ?></span>
							<?php if ($pData['is_array']): ?>
								<span class="badge-array">Array</span>
							<?php endif; ?>
							<?php if ($pData['required']): ?>
								<span class="badge-req">Povinné</span>
							<?php else: ?>
								<span class="badge-opt">Volitelné</span>
							<?php endif; ?>
						</div>
					</div>
					
					<?php if (!empty($pData['desc'])): ?>
						<div class="param-desc"><?php echo htmlspecialchars($pData['desc']); ?></div>
					<?php endif; ?>
					
					<?php if (empty($pData['values'])): ?>
						<div class="value-empty">⚠️ Žádná hodnota nebyla AI modelem nalezena ani uložena v dočasné paměti.</div>
					<?php else: ?>
						<ul class="value-list">
							<?php foreach ($pData['values'] as $val): ?>
								<li><?php echo htmlspecialchars((string)$val); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
		
		<button class="btn-generate" onclick="alert('Datové napojení na mcp_saved_values funguje! Report prozatím nemá implementován automatický přesun dat.')">
			Spustit zpracování reportu
		</button>
	</div>

</body>
</html>