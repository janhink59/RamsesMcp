<?php
/**
 * Diagnostický skript pro MCP Server (HTML výstup v UTF-8)
 */

header('Content-Type: text/html; charset=utf-8');

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
$dbError = null;
$tools = [];

if ($config) {
	$db = @sqlsrv_connect($config['db']['server'], $config['db']['options']);
	if ($db) {
		$dbStatus = "OK - Připojeno";
		$registry = new McpRegistry($db);
		$tools = $registry->getTools();
	} else {
		$dbStatus = "CHYBA PŘIPOJENÍ";
		$dbError = print_r(sqlsrv_errors(), true);
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
		.ok { color: #1e7e34; font-weight: bold; }
		.error { color: #d93025; font-weight: bold; }
		.warning { color: #856404; font-weight: bold; }
		table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
		th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; }
		th { background: #f8f9fa; }
		code { background: #eee; padding: 2px 5px; border-radius: 4px; }
		pre { background: #222; color: #eee; padding: 1rem; border-radius: 8px; overflow-x: auto; }
	</style>
</head>
<body>
	<div class="card">
		<h1>🔍 MCP Diagnostika</h1>
		<p>Tento dashboard nyní běží v bypass režimu (bez blokování přístupu).</p>
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
		<h2>💾 Databáze (SQLSRV)</h2>
		<p class="<?php echo ($dbStatus === 'OK - Připojeno') ? 'ok' : 'error'; ?>">
			<?php echo $dbStatus; ?>
		</p>
		<?php if ($dbError) echo "<pre>$dbError</pre>"; ?>
	</div>

	<div class="card">
		<h2>🛠️ Nástroje z McpRegistry</h2>
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
					<td><?php echo count($tool['inputSchema']['properties']); ?></td>
					<td class="<?php echo $exists ? 'ok' : 'error'; ?>">
						<?php echo $exists ? "✅ OK" : "❌ Chybí $className.php"; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</body>
</html>