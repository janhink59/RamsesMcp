<?php
declare(strict_types=1);

/**
 * RamsesMcp - mcp_report_soa.php
 *
 * SPECIFICKÝ DISPEČER PRO REPORT SOA (Statement of Applicability)
 * Tento skript je volán ZEVNITŘ master dispečera (mcp_report.php).
 * Všechny připravené parametry má dostupné v poli $_POST.
 * * Jeho jedinou rolí je vzít data z $_POST a automaticky je odeslat do 
 * staršího modulu Ramses, který očekává čistý POST požadavek.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

$_POST['no_title']=1;
$_POST['subpage']='report';
require_once($documentRoot . '/RamsesLib.php');
$a=sqlfirstrow("select * from repo_regulation where builtin_code=".charliteral($_POST['soa_builtin_code']??''));
//$debugmode=1;
// debugitem("select * from repo_regulation where builtin_code=".charliteral($reportCode), $a);
// debugget();
// debugprint();
$_POST['regulation']=$a['original']??'';

//die();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<title>Přesměrování na report SOA...</title>
	<style>
		body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
		.loader-box { background: white; padding: 2.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); text-align: center; max-width: 450px; }
		.spinner { border: 4px solid rgba(0, 0, 0, 0.1); width: 40px; height: 40px; border-radius: 50%; border-left-color: #3182ce; animation: spin 1s linear infinite; margin: 0 auto 1.5rem auto; }
		h2 { margin: 0 0 10px 0; color: #1a202c; }
		p { color: #4a5568; margin: 0 0 20px 0; font-size: 0.95rem; line-height: 1.5; }
		button { background: #3182ce; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
		button:hover { background: #2b6cb0; }
		@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
	</style>
</head>
<body>

	<div class="loader-box">
		<div class="spinner"></div>
		<h2>Načítání reportu SOA</h2>
		<p>Odesílám připravená data do aplikace Ramses...</p>
		
		<form id="redirectForm" action="../index.php" method="POST">
			<input type="hidden" name="page" value="repo_soa">
			
			<?php foreach ($_POST as $key => $value): ?>
				<?php if (is_array($value)): ?>
					<?php foreach ($value as $val): ?>
						<input type="hidden" name="<?php echo htmlspecialchars((string)$key); ?>[]" value="<?php echo htmlspecialchars((string)$val); ?>">
					<?php endforeach; ?>
				<?php else: ?>
					<input type="hidden" name="<?php echo htmlspecialchars((string)$key); ?>" value="<?php echo htmlspecialchars((string)$value); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			
			<noscript>
				<p>Váš prohlížeč nepodporuje JavaScript. Pro pokračování klikněte níže:</p>
				<button type="submit">Pokračovat na report</button>
			</noscript>
		</form>
	</div>

	<script>
		// Okamžité automatické odeslání formuláře při načtení stránky
		document.getElementById('redirectForm').submit();
	</script>
</body>
</html>