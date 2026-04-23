<?php
/**
 * RamsesMcp - Diagnostický dashboard a interaktivní testovací rozhraní.
 * Tento skript slouží pro ověření stavu serveru, konektivity do DB a 
 * přímé testování MCP nástrojů (JSON-RPC) bez nutnosti klienta (Ollamy).
 */

header('Content-Type: text/html; charset=utf-8');

// Načtení nezbytných závislostí pro komunikaci s DB a registrem
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/McpRegistry.php';

// Načtení konfigurace (potřebujeme ji pro zobrazení očekávaných tokenů a bypassu)
$configPath = __DIR__ . '/config.php';
$config     = file_exists($configPath) ? require $configPath : null;

// --- 1. KONTROLA AUTENTIZACE V AKTUÁLNÍM REQUESTU (PROHLÍŽEČ) ---
$headers       = getallheaders();
$authHeader    = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = $config['auth']['bearer_token'] ?? '';
$authDisabled  = $config['auth']['disabled'] ?? false;

$authPresent   = !empty($authHeader);
$authValid     = $authPresent && str_starts_with($authHeader, 'Bearer ') && substr($authHeader, 7) === $expectedToken;

// --- 2. DIAGNOSTIKA DATABÁZE A NAČTENÍ NÁSTROJŮ ---
$dbStatus = "Nezkoušeno";
$dbClass  = "warning";
$dbError  = null;
$tools    = [];

if ($config) {
	try {
		// Pokus o spojení a automatické vyvolání procedury debuglogin (definováno v db_connect.php)
		$db = getMssqlConnection();
		$dbStatus = "✅ OK - Připojeno a inicializováno (debuglogin OK)";
		$dbClass  = "ok";
		
		// Inicializace registru pro načtení seznamu nástrojů z DB
		$registry = new McpRegistry($db);
		$tools    = $registry->getTools();
	} catch (Throwable $e) {
		$msg = $e->getMessage();
		
		// Rozlišení, zda selhalo samotné spojení nebo až aplikační kontext (debuglogin)
		if (str_contains($msg, 'debuglogin')) {
			$dbStatus = "⚠️ PŘIPOJENO, ALE KONTEXT SELHAL (debuglogin)";
			$dbClass  = "warning";
		} else {
			$dbStatus = "❌ CHYBA PŘIPOJENÍ / INICIALIZACE";
			$dbClass  = "error";
		}
		$dbError = $msg;
	}
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title>RamsesMcp Dashboard</title>
	<style>
		/* Moderní čistý styl pro interní diagnostický dashboard */
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 1200px; margin: 2rem auto; background: #f0f2f5; padding: 0 1rem; }
		.card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
		h1, h2 { margin-top: 0; color: #1a202c; }
		.ok { color: #1e7e34; font-weight: bold; }
		.error { color: #d93025; font-weight: bold; }
		.warning { color: #856404; font-weight: bold; }
		
		/* Stylování tabulky nástrojů */
		table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: #fff; }
		th, td { text-align: left; padding: 12px 15px; border-bottom: 1px solid #edf2f7; }
		th { background: #f8f9fa; color: #4a5568; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; }
		tr:hover { background-color: #fcfcfc; }
		
		/* Interaktivní prvky a konzole */
		code { background: #edf2f7; padding: 0.2rem 0.4rem; border-radius: 4px; font-family: 'Cascadia Code', Consolas, monospace; font-size: 0.9em; }
		pre { background: #1a202c; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; font-size: 0.85rem; }
		.test-panel { display: none; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.5rem; border-radius: 8px; margin-top: 10px; }
		
		/* Formulářové prvky v testu */
		.input-group { margin-bottom: 1rem; }
		.input-group label { display: block; font-weight: bold; margin-bottom: 0.3rem; font-size: 0.9rem; }
		.input-group input { width: 100%; max-width: 400px; padding: 0.6rem; border: 1px solid #cbd5e0; border-radius: 6px; }
		button { background: #3182ce; color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; font-weight: 600; transition: background 0.2s; }
		button:hover { background: #2b6cb0; }
		button.secondary { background: #718096; margin-left: 0.5rem; }
	</style>
</head>
<body>

	<div class="card">
		<h1>🔍 RamsesMcp Diagnostika</h1>
		<p>Vítejte v dashboardu. Tento nástroj slouží k ladění SQL procedur a PHP tříd před jejich nasazením pro AI modely.</p>
	</div>

	<div class="card">
		<h2>🔐 Stav autentizace (Bearer)</h2>
		<?php if ($authDisabled): ?>
			<p class="ok">✅ Autentizace je globálně VYPNUTA (bypass v config.php). Ideální pro lokální vývoj.</p>
		<?php elseif (!$authPresent): ?>
			<p class="warning">⚠️ V tomto požadavku nebyla zaslána žádná Authorization hlavička.</p>
			<p><small>Při volání přes MCP klienta (Ollama) je tento token vyžadován.</small></p>
		<?php elseif ($authValid): ?>
			<p class="ok">✅ Autentizace je platná (token souhlasí).</p>
		<?php else: ?>
			<p class="error">❌ Autentizace selhala (token je neplatný).</p>
			<p>Zasláno: <code><?php echo htmlspecialchars($authHeader); ?></code></p>
		<?php endif; ?>
		
		<p><strong>Očekávaný token:</strong> <code><?php echo htmlspecialchars($expectedToken); ?></code></p>
	</div>

	<div class="card">
		<h2>💾 Databáze (SQLSRV)</h2>
		<p class="<?php echo $dbClass; ?>" style="font-size: 1.1rem;">
			<?php echo $dbStatus; ?>
		</p>
		<?php if ($dbError): ?>
			<pre><?php echo htmlspecialchars($dbError); ?></pre>
			<?php if (str_contains($dbError, 'debuglogin')): ?>
				<p class="warning"><strong>Tip:</strong> SQL spojení funguje, ale procedura <code>debuglogin</code> selhala. Zkontrolujte, zda má uživatel <code><?php echo $config['db']['options']['APP'] ?? 'mcp_server'; ?></code> záznam v konfiguračních tabulkách.</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="card">
		<h2>🛠️ Seznam nástrojů z McpRegistry</h2>
		<?php if (empty($tools)): ?>
			<p class="warning">Žádné nástroje nebyly načteny. Zkontrolujte tabulku <code>mcp_tool</code> a parametry.</p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Název (Name)</th>
						<th>Typ exekuce</th>
						<th>Params</th>
						<th>Stav implementace</th>
						<th>Akce</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tools as $tool): ?>
					<?php 
						// Příprava dat pro kontrolu existence
						$pureName  = preg_replace('/[^a-zA-Z0-9_]/', '', $tool['name']);
						$isGeneric = !empty($tool['is_generic']);
						
						if ($isGeneric) {
							// Generický nástroj -> hledáme SQL proceduru mcp_tool_{name}
							$targetName = "mcp_tool_" . $pureName;
							$sqlCheck   = "SELECT 1 FROM sys.objects WHERE type = 'P' AND name = ?";
							$stmtCheck  = sqlsrv_query($db, $sqlCheck, [$targetName]);
							$exists     = ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck));
							$typeDesc   = "📦 SQL Procedura";
						} else {
							// Custom nástroj -> hledáme PHP soubor Get_{name}.php
							$targetName = "Get_" . $pureName . ".php";
							$exists     = file_exists(__DIR__ . "/tools/" . $targetName);
							$typeDesc   = "🛠️ PHP Třída";
						}
					?>
					<tr>
						<td><code><?php echo htmlspecialchars($tool['name']); ?></code></td>
						<td><small><?php echo $typeDesc; ?></small></td>
						<td><?php echo count($tool['inputSchema']['properties'] ?? []); ?></td>
						<td class="<?php echo $exists ? 'ok' : 'error'; ?>">
							<?php echo $exists ? "✅ OK ($targetName)" : "❌ Chybí $targetName"; ?>
						</td>
						<td>
							<button onclick="toggleTestPanel('<?php echo $pureName; ?>')">Otestovat</button>
						</td>
					</tr>
					
					<tr id="test-row-<?php echo $pureName; ?>" class="test-panel-row" style="display:none;">
						<td colspan="5">
							<div class="test-panel">
								<h3>Testování nástroje: <?php echo htmlspecialchars($tool['name']); ?></h3>
								<form id="form-<?php echo $pureName; ?>">
									<?php foreach ($tool['inputSchema']['properties'] as $pName => $pDef): ?>
										<div class="input-group">
											<label><?php echo htmlspecialchars($pName); ?> (<?php echo $pDef['title'] ?? $pName; ?>):</label>
											<input type="text" name="<?php echo htmlspecialchars($pName); ?>" 
												   placeholder="<?php echo htmlspecialchars($pDef['description'] ?? ''); ?>">
										</div>
									<?php endforeach; ?>
									
									<button type="button" onclick="executeTool('<?php echo $tool['name']; ?>', '<?php echo $pureName; ?>')">
										Odeslat JSON-RPC Request
									</button>
									<button type="button" class="secondary" onclick="toggleTestPanel('<?php echo $pureName; ?>')">Zavřít</button>
								</form>
								
								<div style="margin-top: 1.5rem;">
									<strong>Odpověď serveru (JSON-RPC Result):</strong>
									<pre id="result-<?php echo $pureName; ?>">Zatím nebyl odeslán žádný požadavek...</pre>
								</div>
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
		 * Přepíná viditelnost testovacího panelu pro konkrétní nástroj.
		 */
		function toggleTestPanel(id) {
			const row = document.getElementById('test-row-' + id);
			row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
		}

		/**
		 * Sestaví validní JSON-RPC request a odešle ho na index.php (Router).
		 * Využívá fetch API a Bearer token z konfigurace.
		 */
		async function executeTool(toolName, id) {
			const form = document.getElementById('form-' + id);
			const resultArea = document.getElementById('result-' + id);
			const formData = new FormData(form);
			
			// Mapování argumentů z formuláře
			const args = {};
			formData.forEach((value, key) => {
				if (value.trim() !== "") args[key] = value;
			});

			// Standardní MCP/JSON-RPC struktura
			const payload = {
				jsonrpc: "2.0",
				id: "test-" + Date.now(),
				method: "tools/call",
				params: {
					name: toolName,
					arguments: args
				}
			};

			resultArea.innerText = "⏳ Odesílám požadavek...";

			try {
				const response = await fetch('index.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer <?php echo $expectedToken; ?>'
					},
					body: JSON.stringify(payload)
				});

				const data = await response.json();
				
				// Zobrazení výsledku v hezkém formátu
				resultArea.innerText = JSON.stringify(data, null, 2);
			} catch (error) {
				resultArea.innerHTML = '<span class="error">Chyba při komunikaci se serverem:</span>\n' + error.message;
			}
		}
	</script>
</body>
</html>