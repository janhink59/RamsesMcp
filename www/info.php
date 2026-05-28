<?php
declare(strict_types=1);

/**
 * RamsesMcp - info.php (Diagnostický Dashboard)
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Toto je vizuální diagnostické rozhraní (UI) pro vývojáře. Není součástí
 * standardní JSON-RPC komunikace s AI modely. Vykresluje HTML, nikoliv JSON.
 *
 * PŘEDPOKLADY BĚHU:
 * Soubor je volán výhradně skrze index.php (v režimu ?mode=info), který
 * mu připraví globální proměnnou $config a propláchne výstupní buffer.
 *
 * HLAVNÍ CÍLE TOHOTO SOUBORU:
 * 1. Ověřit nízkoúrovňové připojení k databázi (sqlsrv).
 * 2. Ověřit aplikační přihlášení (set_login) pro uživatele definovaného v configu.
 * 3. Vylistovat dostupné MCP nástroje z DB a nabídnout formuláře pro jejich otestování.
 */

header('Content-Type: text/html; charset=utf-8');

/** @global array $config Globální konfigurace připravená v index.php */
global $config;

require_once __DIR__ . '/db_interface.php';
require_once __DIR__ . '/db_connect.php'; 

try {
	// Ochrana proti přímému spuštění mimo router
	if (!isset($config)) {
		throw new Exception("Kritická chyba: Globální konfigurace \$config nebyla nalezena. info.php musí být voláno přes index.php.");
	}

	$dbi = new db_interface();

	// DESIGN DECISION (Test autentizace):
	// Úmyslně zde izolovaně testujeme metodu authenticate().
	// Využíváme klíče z konfigurace, které už mohly být přepsány hlavičkami z prohlížeče.
	$user = $config['mcp']['user'] ?? '';
	$pass = $config['mcp']['password'] ?? '';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

	if (empty($user) || empty($pass)) {
		throw new Exception("V konfiguraci chybí údaje pro MCP autentizaci (user/password).");
	}

	// Pokud set_login selže, vyhodí authenticate() výjimku s popisem chyby z db_connect.php
	$dbi->authenticate($user, $pass, $ip);

	// Načtení dat pro vygenerování tabulky nástrojů
	$data     = $dbi->getToolsForInfo();
	$tools    = $data['tools'];
	$params   = $data['params'];
	$dbStatus = "✅ OK - Připojeno a autentizováno jako '$user'";
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
		
		/* Optimalizovaný diagnostický box pro úsporu místa */
		.status-box { padding: 8px 12px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; line-height: 1.3; }
		.status-box p { margin: 2px 0; font-size: 0.95rem; }
		
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
			<p><strong>Stav autentizace:</strong> <span class="<?php echo $dbClass; ?>"><?php echo htmlspecialchars($dbStatus); ?></span></p>
			<p><strong>Server:</strong> <code><?php echo htmlspecialchars($config['db']['server'] ?? '---'); ?></code></p>
			<p><strong>Databáze:</strong> <code><?php echo htmlspecialchars($config['db']['options']['Database'] ?? '---'); ?></code></p>
			<p><strong>Verze MCP:</strong> <code><?php echo htmlspecialchars($config['mcp']['version'] ?? '---'); ?></code></p>
			<p><strong>Base URL:</strong> <a href="<?php echo htmlspecialchars($config['mcp']['base_url'] ?? '#'); ?>" target="_blank" style="color: #3182ce; text-decoration: none;"><code><?php echo htmlspecialchars($config['mcp']['base_url'] ?? '---'); ?></code></a></p>
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
						<td>
							<?php if (!empty($tool['title'])): ?>
								<strong><?php echo htmlspecialchars($tool['title']); ?></strong><br>
							<?php endif; ?>
							<?php echo htmlspecialchars($tool['description']); ?>
						</td>
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
										<h3>Parametry: <?php echo !empty($tool['title']) ? htmlspecialchars($tool['title']) : $safeName; ?></h3>
										<form id="form_<?php echo $safeName; ?>">
											<input type="hidden" name="tool_name" value="<?php echo $safeName; ?>">
											<?php if (empty($params[$tName])): ?>
												<p><small>Žádné parametry nejsou vyžadovány.</small></p>
											<?php else: ?>
												<?php foreach ($params[$tName] as $p): ?>
													<div class="input-group">
														<label>
															<?php echo htmlspecialchars($p['param_title'] ?: $p['param_name']); ?>
															<span style="color: #718096; font-weight: normal; font-size: 0.8rem;">(<?php echo htmlspecialchars($p['param_name']); ?>)</span>:
														</label>
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
											// Vyhodnocení názvu: prioritně title z DB, fallback na system name, jinak fyzický cíl
											$toolTitle = !empty($tool['title']) ? $tool['title'] : (!empty($tName) ? $tName : $implStatus['target']);
											
											$sqlTemplate  = "/*\n";
											$sqlTemplate .= "\tNástroj: " . $toolTitle . "\n";
											
											// Formátování popisu do víceřádkového komentáře, pokud existuje
											if (isset($tool['description']) && trim((string)$tool['description']) !== '') {
												$descLines = explode("\n", $tool['description']);
												$sqlTemplate .= "\tPopis:   " . array_shift($descLines) . "\n";
												foreach ($descLines as $line) {
													$sqlTemplate .= "\t         " . trim($line) . "\n";
												}
											}
											
											$sqlTemplate .= "*/\n";
											$sqlTemplate .= "CREATE OR ALTER PROCEDURE " . $implStatus['target'] . "\n";
											
											// Generování deklarace parametrů s typováním a zarovnanými komentáři včetně titulků
											if (!empty($params[$tName])) {
												$validParams = array_values(array_filter($params[$tName], fn($p) => $p['param_name'] !== 'save_as'));
												
												if (!empty($validParams)) {
													$maxDeclLen = 0;
													$parsedParams = [];
													
													foreach ($validParams as $p) {
														$sqlType = 'NVARCHAR(MAX)';                     // Výchozí fallback
														$pType = strtolower((string)$p['param_type']);
														
														if ($pType === 'uuid' || $pType === 'guid') {
															$sqlType = 'UNIQUEIDENTIFIER';
														} elseif ($pType === 'number' || $pType === 'int') {
															$sqlType = 'INT';
														} elseif ($pType === 'bit') {
															$sqlType = 'BIT';
														}
														
														$decl = "@" . $p['param_name'] . " " . $sqlType;
														if (strlen($decl) > $maxDeclLen) {
															$maxDeclLen = strlen($decl);
														}
														
														$parsedParams[] = [
															'decl'  => $decl,
															'req'   => $p['is_required'] ? 'Povinný' : 'Volitelný',
															'title' => trim((string)($p['param_title'] ?? '')),
															'desc'  => trim(str_replace(["\r", "\n", "\t"], " ", (string)($p['description'] ?? '')))
														];
													}
													
													$paramLines = [];
													$total = count($parsedParams);
													foreach ($parsedParams as $i => $pp) {
														$comma = ($i < $total - 1) ? "," : "";
														$padDecl = str_pad($pp['decl'] . $comma, $maxDeclLen + 1, " ");
														
														$comment = "-- [" . $pp['req'] . "]";
														if ($pp['title'] !== '') {
															$comment .= " " . $pp['title'];
															if ($pp['desc'] !== '') $comment .= " -";
														}
														if ($pp['desc'] !== '') {
															$comment .= " " . $pp['desc'];
														}
														
														$paramLines[] = "\t" . $padDecl . " " . $comment;
													}
													$sqlTemplate .= implode("\n", $paramLines) . "\n";
												}
											}
											
											$sqlTemplate .= "AS\n";
											$sqlTemplate .= "BEGIN\n";
											$sqlTemplate .= "\tSET NOCOUNT ON;\n";
											$sqlTemplate .= "\t\n";
											$sqlTemplate .= "\t-- TODO: Zde implementujte logiku nástroje pro AI\n";
											$sqlTemplate .= "\t\n";
											$sqlTemplate .= "\t-- Příklad pro vracení více datových sad (Multi Result-Sets):\n";
											$sqlTemplate .= "\t-- SELECT 'Základní info' AS __block_name, 'Hodnota' AS Sloupec1;\n";
											$sqlTemplate .= "\t-- SELECT 'Detailní data' AS __block_name, 'Hodnota' AS Sloupec1;\n";
											$sqlTemplate .= "\t\n";
											$sqlTemplate .= "\tSELECT 'Not implemented' AS Status;\n";
											$sqlTemplate .= "END\n";
											$sqlTemplate .= "GO";
											
											echo htmlspecialchars($sqlTemplate);
										} else {
											echo htmlspecialchars("<?php\nclass " . str_replace('.php', '', $implStatus['target']) . " extends McpTool {\n\tpublic function execute(array \$params): array {\n\t\treturn \$this->success(\"Nástroj zatím není implementován.\");\n\t}\n}");
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
			resDiv.innerHTML = "⏳ Volám test_exec.php přes index.php...";
			try {
				// DESIGN DECISION (Kritické pro integritu):
				// Záměrně voláme index.php?mode=test, nikoliv přímo test_exec.php!
				// Tím zajistíme, že požadavek projde přes router, který správně načte
				// případné konfigurační hlavičky (X-Mcp-*) a nastaví správnou DB a login.
				const response = await fetch('index.php?mode=test', { method: 'POST', body: formData });
				resDiv.innerHTML = await response.text();
			} catch (e) {
				resDiv.innerHTML = '<div class="error">Chyba AJAX požadavku: ' + e.message + '</div>';
			}
		}
	</script>
</body>
</html>