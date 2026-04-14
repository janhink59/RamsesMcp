<?php
/**
 * Diagnostický skript pro MCP Server (HTML výstup v UTF-8)
 */

// ------------------------------------------------------------------
// BEZPEČNOSTNÍ POJISTKA PROTI NECHTĚNÉMU INCLUDE
// Pokud je tento soubor načten jako závislost a chybí parametr ?test,
// okamžitě ukončíme jeho zpracování. Zabráníme tak vypsání HTML kódu,
// který by rozbil JSON výstup pro MCP klienta (Ollamu).
// ------------------------------------------------------------------
if (!isset($_GET['test']) && basename($_SERVER['SCRIPT_FILENAME']) !== 'test.php') {
	return;
}

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/McpRegistry.php';

$configPath = __DIR__ . '/config.php';
$config = file_exists($configPath) ? require $configPath : null;

// --- Kontrola autentizace v aktuálním requestu ---
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = $config['auth']['bearer_token'] ?? '';

$authPresent = !empty($authHeader);
$authValid = $authPresent && str_starts_with($authHeader, 'Bearer ') && substr($authHeader, 7) === $expectedToken;

// --- Diagnostika DB ---
$dbStatus = "Nezkoušeno";
$dbClass = "warning";
$dbError = null;
$tools = [];

if ($config) {
	try {
		$db = getMssqlConnection();
		$dbStatus = "✅ OK - Připojeno a inicializováno (debuglogin OK)";
		$dbClass = "ok";
		
		$registry = new McpRegistry($db);
		$tools = $registry->getTools();
	} catch (Throwable $e) {
		$msg = $e->getMessage();
		
		if (str_contains($msg, 'debuglogin')) {
			$dbStatus = "⚠️ PŘIPOJENO, ALE KONTEXT SELHAL (debuglogin)";
			$dbClass = "warning";
		} else {
			$dbStatus = "❌ CHYBA PŘIPOJENÍ / INICIALIZACE";
			$dbClass = "error";
		}
		$dbError = $msg;
	}
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title>MCP Diagnostika</title>
	<style>
		body { font-family: sans-serif; line-height: 1.5; color: #333; max-width: 1000px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 2rem; }
		.ok { color: #1e7e34; }
		.error { color: #d93025; }
		.warning { color: #856404; }
		table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
		th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
		th { background: #f8f9fa; }
		pre { background: #222; color: #eee; padding: 1rem; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; }
	</style>
</head>
<body>
	<div class="card">
		<h1>🔍 MCP Diagnostika</h1>
		<p>Tento dashboard nyní běží v bypass režimu (bez blokování přístupu), ale využívá sdílenou logiku <code>db_connect.php</code>.</p>
	</div>

	<div class="card">
		<h2>🔐 Stav autentizace (Bearer)</h2>
		<?php if (!$authPresent): ?>
			<p class="warning">⚠️ V tomto požadavku nebyla zaslána žádná Authorization hlavička.</p>
			<p><small>Při běžném volání by server vrátil chybu 401.</small></p>
		<?php elseif ($authValid): ?>
			<p class="ok">✅ Autentizace je platná (token souhlasí).</p>
		<?php else: ?>
			<p class="error">❌ Autentizace selhala (token je neplatný nebo má špatný formát).</p>
			<p>Zasláno: <code><?php echo htmlspecialchars($authHeader); ?></code></p>
		<?php endif; ?>
		
		<p><strong>Očekávaný token v config.php:</strong> <code><?php echo htmlspecialchars($expectedToken); ?></code></p>
	</div>

	<div class="card">
		<h2>💾 Databáze (SQLSRV přes db_connect.php)</h2>
		<p class="<?php echo $dbClass; ?>" style="font-weight: bold; font-size: 1.2rem;">
			<?php echo $dbStatus; ?>
		</p>
		<?php if ($dbError): ?>
			<pre><?php echo htmlspecialchars($dbError); ?></pre>
			<?php if (str_contains($dbError, 'debuglogin')): ?>
				<p class="warning"><strong>Tip:</strong> SQL spojení funguje, ale procedura <code>debuglogin</code> vrátila chybu. Pravděpodobně chybí záznam pro <code>mcp_server</code> v konfiguračních tabulkách DB.</p>
			<?php endif; ?>
		<?php endif; ?>
		
		<?php if (!$config): ?>
			<p class="error">❌ Soubor <code>config.php</code> nebyl nalezen!</p>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>🛠️ Nástroje z McpRegistry</h2>
		<?php if (empty($tools)): ?>
			<p class="warning">Žádné nástroje nebyly načteny. Zkontrolujte tabulku <code>mcp_tool</code>.</p>
		<?php else: ?>
			<table>
				<thead><tr><th>Name</th><th>Title</th><th>Params</th><th>Implementace</th></tr></thead>
				<tbody>
					<?php foreach ($tools as $tool): ?>
					<?php 
						$className = "Get_" . preg_replace('/[^a-zA-Z0-9_]/', '', $tool['name']);
						$exists = file_exists(__DIR__ . "/tools/" . $className . ".php");
					?>
					<tr>
						<td><code><?php echo htmlspecialchars($tool['name']); ?></code></td>
						<td><?php echo htmlspecialchars($tool['title'] ?? '---'); ?></td>
						<td><?php echo count($tool['inputSchema']['properties'] ?? []); ?></td>
						<td class="<?php echo $exists ? 'ok' : 'error'; ?>">
							<?php echo $exists ? "✅ OK" : "❌ Chybí $className.php"; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</body>
</html>