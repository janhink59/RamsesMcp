<?php
declare(strict_types=1);

/**
 * RamsesMcp - detect_url.php (Dynamická detekce Base URL)
 * * * ARCHITEKTONICKÝ KONTEXT (PRO AI):
 * Tento modul zajišťuje automatickou detekci absolutní základní URL adresy MCP serveru.
 * Je volán z index.php a výsledek ukládá do globální konfigurace $config['mcp']['base_url'].
 * To umožňuje generovat plné absolutní URL odkazy pro klienty (např. Page Assist),
 * kteří by s relativními odkazy (vzhledem k izolaci rozšíření v prohlížeči) selhali.
 * * * PODPOROVANÁ PROSTŘEDÍ:
 * - Localhost (včetně specifických portů jako 8080)
 * - Intranet (s podporou reverzních proxy přes HTTP_X_FORWARDED_PROTO)
 * - Veřejný web (standardní HTTPS)
 */

global $config;                                                                 // Využíváme globální konfiguraci z index.php

// 1. Detekce protokolu (zohledňujeme i reverzní proxy v intranetech)
$protocol = 'http://';
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443) {
	$protocol = 'https://';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
	$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] . '://';
}

// 2. Detekce hostitele (obsahuje doménu/IP i případný nestandardní port)
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// 3. Detekce podřízeného adresáře (odřízneme název skriptu index.php a získáme čistou cestu)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$currentDir = rtrim(dirname($scriptName), '/\\');

// 4. Sestavení finální základní URL adresy MCP serveru a injekce do konfigurace
$config['mcp']['base_url'] = $protocol . $host . $currentDir;
