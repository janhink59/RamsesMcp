<?php
declare(strict_types=1);

/**
 * RamsesMcp - detect_url.php (Dynamická detekce Base URL)
 *
 * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento modul zajišťuje automatickou detekci absolutní základní URL adresy MCP serveru.
 * Striktně využívá $config['mcp']['url_prefix'] a zpřístupňuje globální proměnné:
 * 1. $full_base_url - Pro absolutní odkazy (používáno v nástrojích MCP pro AI)
 * 2. $STRIPPED_URI  - Pro relativní odkazy v dashboardu legacy systému
 */

// Zajistíme, že tyto proměnné budou po inkludování dostupné v globálním scope
global $config, $full_base_url, $STRIPPED_URI;

// 1. Detekce protokolu (zohledňujeme i reverzní proxy v intranetech)
$protocol = 'http://';
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443) {
	$protocol = 'https://';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
	$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] . '://';
}

// 2. Detekce hostitele (obsahuje doménu/IP i případný nestandardní port)
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// 3. Zpracování URL Prefixu z konfigurace (Multitenancy / reverzní proxy)
// Zabezpečíme, že prefix bude VŽDY začínat a končit lomítkem.
$urlPrefix = $config['mcp']['url_prefix'] ?? '/';
$urlPrefix = '/' . trim($urlPrefix, '/') . '/';
if ($urlPrefix === '//') {
	$urlPrefix = '/';
}

// 4. Sestavení finální absolutní URL adresy (pro MCP nástroje)
$full_base_url = $protocol . $host . $urlPrefix;

// 5. Sestavení STRIPPED_URI (pro relativní odkazy v dashboardu)
// Ořízneme prefix z aktuální cesty, aby interní router věděl, kde přesně se nachází.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUri  = parse_url($requestUri, PHP_URL_PATH) ?: '/';

if (strpos($parsedUri, $urlPrefix) === 0) {
	$STRIPPED_URI = '/' . substr($parsedUri, strlen($urlPrefix));
} else {
	$STRIPPED_URI = $parsedUri;
}

// Pro zpětnou kompatibilitu, pokud by to nějaký starší kód hledal v poli
$config['mcp']['base_url'] = $full_base_url;