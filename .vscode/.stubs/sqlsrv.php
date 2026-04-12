<?php
/**
 * Tyto definice slouží pouze pro IDE (VS Code / Intelephense).
 * Zabrání červenému podtrhávání sqlsrv_ funkcí a zapnou našeptávač.
 */

define('SQLSRV_FETCH_NUMERIC', 1);
define('SQLSRV_FETCH_ASSOC', 2);
define('SQLSRV_FETCH_BOTH', 3);

function sqlsrv_connect(string $serverName, array $connectionInfo = []) {}
function sqlsrv_query($conn, string $sql, array $params = [], array $options = []) {}
function sqlsrv_fetch_array($stmt, int $fetchType = SQLSRV_FETCH_BOTH, ?int $row = null, ?int $offset = null) {}
function sqlsrv_errors(?int $errorsAndOrWarnings = null) {}