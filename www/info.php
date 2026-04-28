<?php
declare(strict_types=1);

/**
 * info.php - Diagnostický dashboard projektu RamsesMcp.
 * Verze 2.1 - Plná závislost na config.php, odstraněna správa identity v UI.
 * Slouží k přehledu nástrojů, jejich testování a generování šablon.
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/db_interface.php';
require_once __DIR__ . '/db_connect.php'; 
$config = require __DIR__ . '/config.php';

try {
	// Inicializace rozhraní a načtení struktury nástrojů
	$dbi      = new db_interface();
	$data     = $dbi->getToolsForInfo();
	$tools    = $data['tools'];
	$params   = $data['params'];
	$dbStatus = "✅ OK - Připojeno k MSSQL";
	$dbClass  = "ok";
} catch (Throwable $e) {
	$dbStatus = "❌ Chyba: " . $e->getMessage();
	$dbClass  = "error";
	$tools    = [];
	$params   = [];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title>RamsesMcp Info & Diagnostic</title>
	<style>
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
		h1, h2, h3 { margin-top: 0; color: #1a202c; }
		.ok { color: #1e7e34; font-weight: bold; }
		.error { color: #d93025; font-weight: bold; }
		.status-box { padding: 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; }
		table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
		th, td { text-align: left; padding: 12px 15px; border-bottom: 1px solid #edf2f7; vertical-align: top; }
		th { background: #f8f9fa; color: #4a5568; text-transform: uppercase; font-size: 0.85rem; }
		.test-wrapper { display: none; background: #fdfdfd; border: 2px solid #3182ce; padding: 1.5rem; border-radius: 8px; margin-top: 10px; }
		.parameter-section { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px dashed #cbd5e0; }
		.response-section { background: #fff; padding: 10px; border: 1px solid #e2e8f0; min-height: 50px; overflow-x: auto; }
		.input-group { margin-bottom: 0.8rem; }
		.input-group label { display: block; font-weight: bold; font-size: 0.9rem; margin-bottom: 4px; }
		.input-group input { width: 100%; max-width: 400px; padding: 8px; border: 1px solid #cbd5e0; border-radius: 4px; }
		button { background: #3182ce; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; }
		button:hover { background: #2b6cb0; }
		button.btn-test { background: #38a169; }
		button.btn-template { background: #d69e2e; color: #fff; }
		.code-template { background: #1a202c; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-family: 'Cascadia Code', monospace; font-size: 0.9rem; tab-size: 4; }
	</style>
</head>
<body>

	<div class="card">
		<h1>🔍 RamsesMcp Info Dashboard</h1>
		<div class="status-box">
			<p><strong>Stav DB:</strong> <span class="<?php echo $dbClass; ?>"><?php echo htmlspecialchars($dbStatus); ?></span></p>
			<p><strong>Verze:</strong> <code><?php echo htmlspecialchars($config['mcp']['version'] ?? '2.0.0'); ?></code></p>
			<p><strong>Testovací uživatel:</strong> <code><?php echo htmlspecialchars($config['mcp']['test_user'] ?? '---'); ?></code></p>
		</div>
	</div>

	<div class="card">
		<h2>🛠️ Registrované nástroje</h2>
		<?php if (empty($tools)): ?>
			<p class="error">Žádné nástroje nebyly načteny z databáze.</p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Název</th>
						<th>Popis</th>
						<th>Implementace</th>
						<th>Akce</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tools as $tName => $tool): ?>
					<?php 
						$safeName = htmlspecialchars($tName); 
						$implStatus = $dbi->getImplementationStatus($tName);
					?>
					<tr>
						<td>
							<code><?php echo $safeName; ?></code><br>
							<small style="color: #718096;"><?php echo $tool['is_generic'] ? '📦 SQL' : '🛠️ PHP'; ?></small>
						</td>
						<td><?php echo htmlspecialchars($tool['description']); ?></td>
						<td>
							<?php if ($implStatus['exists']): ?>
								<span class="ok">✅ OK</span>
							<?php else: ?>
								<span class="error">❌ Chybí</span>
							<?php endif; ?>
						</td>
						<td>
							<button onclick="toggleTest('<?php echo $safeName; ?>')"><?php echo $implStatus['exists'] ? 'Testovat' : 'Šablona'; ?></button>
						</td>
					</tr>
					<tr id="row_<?php echo $safeName; ?>" style="display: none;">
						<td colspan="4">
							<div id="wrapper_<?php echo $safeName; ?>" class="test-wrapper">
								<?php if ($implStatus['exists']): ?>
									<div class="parameter-section">
										<h3>Parametry: <?php echo $safeName; ?></h3>
										<form id="form_<?php echo $safeName; ?>">
											<input type="hidden" name="tool_name" value="<?php echo $safeName; ?>">
											<?php if (empty($params[$tName])): ?>
												<p><small>Žádné parametry nejsou vyžadovány.</small></p>
											<?php else: ?>
												<?php foreach ($params[$tName] as $p): ?>
													<div class="input-group">
														<label><?php echo htmlspecialchars($p['param_name']); ?>:</label>
														<input type="text" name="params[<?php echo htmlspecialchars($p['param_name']); ?>]">
													</div>
												<?php endforeach; ?>
											<?php endif; ?>
											<button type="button" class="btn-test" onclick="runTest('<?php echo $safeName; ?>')">Spustit test</button>
										</form>
									</div>
									<div id="response_<?php echo $safeName; ?>" class="response-section">
										<p><small>Čekám na test...</small></p>
									</div>
								<?php else: ?>
									<h3 style="color: #b7791f;">Šablona pro: <?php echo htmlspecialchars($implStatus['target']); ?></h3>
									<pre class='code-template'><code><?php 
										if ($tool['is_generic']) {
											echo htmlspecialchars("CREATE PROCEDURE " . $implStatus['target'] . "\nAS\nBEGIN\n\tSELECT 'Not implemented';\nEND");
										} else {
											echo htmlspecialchars("<?php\nclass " . str_replace('.php', '', $implStatus['target']) . " extends McpTool {\n\tpublic function execute(array \$params): array {\n\t\treturn \$this->success();\n\t}\n}");
										}
									?></code></pre>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<script>
		function toggleTest(name) {
			const row = document.getElementById('row_' + name);
			row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
			document.getElementById('wrapper_' + name).style.display = (row.style.display === 'none') ? 'none' : 'block';
		}

		async function runTest(name) {
			const resDiv = document.getElementById('response_' + name);
			const formData = new FormData(document.getElementById('form_' + name));
			resDiv.innerHTML = "⏳ Volám test_exec.php...";
			try {
				// test_exec.php si sám vytáhne testovací údaje z config.php
				const response = await fetch('test_exec.php', { method: 'POST', body: formData });
				resDiv.innerHTML = await response.text();
			} catch (e) {
				resDiv.innerHTML = '<div class="error">Chyba: ' + e.message + '</div>';
			}
		}
	</script>
</body>
</html>