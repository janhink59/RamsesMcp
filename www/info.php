<?php
declare(strict_types=1);

/**
 * info.php - Diagnostický dashboard projektu RamsesMcp.
 * Nahrazuje původní test.php. Slouží k přehledu nástrojů a jejich testování.
 * Nově obsahuje také generátor šablon (predikci kódu) pro chybějící nástroje.
 * Pro exekuci testů využívá asynchronní volání, aby se stránka nemusela obnovovat.
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/db_interface.php';

// Načtení konfigurace pro kontrolu stavu autentizace v UI
$config        = require __DIR__ . '/config.php';
$expectedToken = $config['auth']['bearer_token'] ?? '';
$authDisabled  = $config['auth']['disabled'] ?? false;

try {
	// Inicializace rozhraní a načtení kompletní struktury nástrojů a parametrů
	$dbi      = new db_interface();
	$data     = $dbi->getToolsForInfo();
	$tools    = $data['tools'];
	$params   = $data['params'];
	$dbStatus = "✅ OK - Připojeno k MSSQL a inicializováno (debuglogin OK)";
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
		/* Základní rozložení a typografie */
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
		h1, h2, h3 { margin-top: 0; color: #1a202c; }
		
		/* Barvy pro stavy (OK, Error, Warning) */
		.ok { color: #1e7e34; font-weight: bold; }
		.error { color: #d93025; font-weight: bold; }
		.warning { color: #856404; font-weight: bold; }
		.status-box { padding: 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; }
		
		/* Tabulka nástrojů */
		table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
		th, td { text-align: left; padding: 12px 15px; border-bottom: 1px solid #edf2f7; vertical-align: top; }
		th { background: #f8f9fa; color: #4a5568; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; }
		
		/* Kontejner pro testování nebo predikci kódu (skrytý) */
		.test-wrapper { display: none; background: #fdfdfd; border: 2px solid #3182ce; padding: 1.5rem; border-radius: 8px; margin-top: 10px; }
		.parameter-section { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px dashed #cbd5e0; }
		.response-section { background: #fff; padding: 10px; border: 1px solid #e2e8f0; min-height: 50px; }
		
		/* Formulářové prvky a tlačítka */
		.input-group { margin-bottom: 0.8rem; }
		.input-group label { display: block; font-weight: bold; font-size: 0.9rem; margin-bottom: 4px; }
		.input-group input { width: 100%; max-width: 400px; padding: 8px; border: 1px solid #cbd5e0; border-radius: 4px; }
		button { background: #3182ce; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; }
		button:hover { background: #2b6cb0; }
		button.btn-test { background: #38a169; }
		button.btn-test:hover { background: #2f855a; }
		button.btn-template { background: #d69e2e; color: #fff; }
		button.btn-template:hover { background: #b7791f; }
		
		/* Stylování predikovaného kódu */
		.code-template { background: #1a202c; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-family: 'Cascadia Code', Consolas, monospace; font-size: 0.9rem; line-height: 1.4; margin-top: 10px; tab-size: 4; }
	</style>
</head>
<body>

	<div class="card">
		<h1>🔍 RamsesMcp Info Dashboard</h1>
		<div class="status-box">
			<p><strong>Stav DB:</strong> <span class="<?php echo $dbClass; ?>"><?php echo htmlspecialchars($dbStatus); ?></span></p>
			<p><strong>Autentizace:</strong> 
				<?php if ($authDisabled): ?>
					<span class="ok">VYPNUTA (Bypass pro vývoj)</span>
				<?php else: ?>
					<span class="ok">ZAPNUTA (Bearer token vyžadován)</span>
				<?php endif; ?>
			</p>
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
						<th>Stav implementace</th>
						<th>Akce</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tools as $tName => $tool): ?>
					<?php 
						$safeName = htmlspecialchars($tName); 
						// Dotaz do db_interface na reálnou fyzickou existenci procedury nebo PHP souboru
						$implStatus = $dbi->getImplementationStatus($tName);
					?>
					<tr>
						<td>
							<code><?php echo $safeName; ?></code><br>
							<small style="color: #718096;"><?php echo $tool['is_generic'] ? '📦 Generický (SQL)' : '🛠️ Custom (PHP)'; ?></small>
						</td>
						<td><?php echo htmlspecialchars($tool['description']); ?></td>
						<td>
							<?php if ($implStatus['exists']): ?>
								<span class="ok">✅ OK</span><br>
								<small style="color: #718096;">(Nalezeno: <?php echo htmlspecialchars($implStatus['target']); ?>)</small>
							<?php else: ?>
								<span class="error">❌ Chybí</span><br>
								<small style="color: #d93025;">(Očekáváno: <?php echo htmlspecialchars($implStatus['target']); ?>)</small>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($implStatus['exists']): ?>
								<button onclick="toggleTest('<?php echo $safeName; ?>')">Testovat</button>
							<?php else: ?>
								<button class="btn-template" onclick="toggleTest('<?php echo $safeName; ?>')">Zobrazit šablonu</button>
							<?php endif; ?>
						</td>
					</tr>
					<tr id="row_<?php echo $safeName; ?>" style="display: none;">
						<td colspan="4">
							<div id="wrapper_<?php echo $safeName; ?>" class="test-wrapper" style="<?php echo !$implStatus['exists'] ? 'border-color: #d69e2e;' : ''; ?>">
								
								<?php if ($implStatus['exists']): ?>
									<div id="parameters_<?php echo $safeName; ?>" class="parameter-section">
										<h3>Parametry pro: <?php echo $safeName; ?></h3>
										<form id="form_<?php echo $safeName; ?>">
											<input type="hidden" name="tool_name" value="<?php echo $safeName; ?>">
											<?php if (empty($params[$tName])): ?>
												<p><small>Tento nástroj nemá definované žádné vstupní parametry.</small></p>
											<?php else: ?>
												<?php foreach ($params[$tName] as $p): ?>
													<div class="input-group">
														<label>
															<?php echo htmlspecialchars($p['param_name']); ?> 
															(<?php echo htmlspecialchars($p['param_type']); ?>)
															<?php echo $p['is_required'] ? '<span class="error">*</span>' : ''; ?>:
														</label>
														<input type="text" name="params[<?php echo htmlspecialchars($p['param_name']); ?>]" 
															   placeholder="<?php echo htmlspecialchars($p['description'] ?? ''); ?>">
													</div>
												<?php endforeach; ?>
											<?php endif; ?>
											<button type="button" class="btn-test" onclick="runTest('<?php echo $safeName; ?>')">Spustit test</button>
										</form>
									</div>
									
									<div class="response-container">
										<h3>Výsledek exekuce (HTML Tabulka)</h3>
										<div id="response_<?php echo $safeName; ?>" class="response-section">
											<p><small>Zatím nebyl spuštěn žádný test...</small></p>
										</div>
									</div>
								
								<?php else: ?>
									<div>
										<h3 style="color: #b7791f; margin-bottom: 5px;">⚠️ Chybějící implementace</h3>
										<p>Nástroj <strong><?php echo $safeName; ?></strong> je sice zaregistrován v databázi, ale chybí mu fyzická implementace (<code><?php echo htmlspecialchars($implStatus['target']); ?></code>).</p>
										<p>Zde je predikovaný zdrojový kód připravený podle očekávaných parametrů. Stačí ho zkopírovat, doplnit vnitřní logiku a vložit na server:</p>
										
										<?php
										// Příprava parametrů a typů pro šablony
										$pureName = preg_replace('/[^a-zA-Z0-9_]/', '', $tName);
										
										if ($tool['is_generic']) {
											// --- ŠABLONA PRO SQL PROCEDURU ---
											$sqlCode  = "CREATE PROCEDURE " . $implStatus['target'] . "\n";
											$paramLines = [];
											
											if (!empty($params[$tName])) {
												foreach ($params[$tName] as $p) {
													// Převod JSON/MCP typů na bezpečné MSSQL datové typy
													$sqlType = 'NVARCHAR(MAX)';
													if ($p['param_type'] === 'uuid') {
														$sqlType = 'UNIQUEIDENTIFIER';
													} elseif ($p['param_type'] === 'number') {
														$sqlType = 'INT';
													}
													
													$requiredFlag = $p['is_required'] ? "" : " = NULL";
													$paramLines[] = "\t@" . $p['param_name'] . " " . $sqlType . $requiredFlag;
												}
												$sqlCode .= implode(",\n", $paramLines) . "\n";
											}
											
											$sqlCode .= "AS\nBEGIN\n\tSET NOCOUNT ON;\n\n";
											$sqlCode .= "\t-- TODO: Zde implementujte vaši SQL logiku\n";
											$sqlCode .= "\t-- Model AI očekává, že procedura vrátí standardní SELECT (result set).\n\n";
											$sqlCode .= "\tSELECT 'Logika zatím není implementována' AS Status;\n";
											$sqlCode .= "END\nGO";
											
											echo "<pre class='code-template'><code>" . htmlspecialchars($sqlCode) . "</code></pre>";
											
										} else {
											// --- ŠABLONA PRO PHP TŘÍDU ---
											$className = str_replace('.php', '', $implStatus['target']);
											$phpCode  = "<?php\ndeclare(strict_types=1);\n\n";
											$phpCode .= "/**\n * Custom MCP nástroj pro: " . $safeName . "\n */\n";
											$phpCode .= "class " . $className . " extends McpTool {\n\n";
											$phpCode .= "\t/**\n\t * Vykoná nástroj a vrátí strukturovaná data ve formátu TSV pro AI.\n\t * @param array \$params Zvalidované vstupní parametry\n\t * @return array MCP odpověď\n\t */\n";
											$phpCode .= "\tpublic function execute(array \$params): array {\n";
											
											if (!empty($params[$tName])) {
												$phpCode .= "\t\t// Vaše dostupné parametry z asociativního pole \$params:\n";
												foreach ($params[$tName] as $p) {
													$reqInfo = $p['is_required'] ? "[Povinný]" : "[Volitelný]";
													$phpCode .= "\t\t// - \$params['" . $p['param_name'] . "'] -> (" . $p['param_type'] . ") " . $reqInfo . "\n";
												}
											}
											
											$phpCode .= "\n\t\t// TODO: Zde implementujte vaši PHP nebo vlastní databázovou logiku.\n";
											$phpCode .= "\t\t// Přístup k připojení: \$this->db (sqlsrv resource)\n\n";
											
											$phpCode .= "\t\t// Návratová struktura (Příklad TSV)\n";
											$phpCode .= "\t\t\$header = \"ID\\tJmeno\\tStatus\";\n";
											$phpCode .= "\t\t\$data   = \"1\\tUkázka\\tN/A\";\n\n";
											$phpCode .= "\t\treturn \$this->success(\"Nalezena data:\\n\" . \$header . \"\\n\" . \$data);\n";
											
											$phpCode .= "\t}\n}\n";
											
											echo "<pre class='code-template'><code>" . htmlspecialchars($phpCode) . "</code></pre>";
										}
										?>
									</div>
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
		/**
		 * Přepíná zobrazení testovacího wrapperu pod řádkem nástroje.
		 */
		function toggleTest(toolName) {
			const row = document.getElementById('row_' + toolName);
			const wrapper = document.getElementById('wrapper_' + toolName);
			
			if (row.style.display === 'none') {
				row.style.display = 'table-row';
				wrapper.style.display = 'block';
			} else {
				row.style.display = 'none';
				wrapper.style.display = 'none';
			}
		}

		/**
		 * Odesílá parametry na asynchronní testovací endpoint (main rozcestník v test režimu).
		 * Výsledek v podobě HTML tabulky následně vloží do příslušného divu.
		 */
		async function runTest(toolName) {
			const responseDiv = document.getElementById('response_' + toolName);
			const form = document.getElementById('form_' + toolName);
			const formData = new FormData(form);

			responseDiv.innerHTML = "⏳ Provádím test, prosím čekejte...";

			try {
				// Voláme centrální front-controller (index.php) a předáváme mód test
				const response = await fetch('index.php?mode=test', {
					method: 'POST',
					body: formData
				});

				if (!response.ok) {
					throw new Error('Chyba serveru: ' + response.status);
				}

				const htmlResult = await response.text();
				responseDiv.innerHTML = htmlResult;

			} catch (error) {
				responseDiv.innerHTML = '<div class="error">Kritická chyba AJAX: ' + error.message + '</div>';
			}
		}
	</script>
</body>
</html>