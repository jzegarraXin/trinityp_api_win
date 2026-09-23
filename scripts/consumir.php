<?php
/**
 * Runner CLI de sincronizacion con la API WIN.
 * Uso: php scripts/consumir.php   (tambien invocado por cron cada hora)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('America/Lima');

$config     = require __DIR__ . '/../config/database.php';
$credentials = require __DIR__ . '/../config/credentials.php';
require __DIR__ . '/../lib/ApiWin.php';

$conn = sqlsrv_connect($config['server'], [
    'Database'               => $config['database'],
    'UID'                    => $config['uid'],
    'PWD'                    => $config['pwd'],
    'Encrypt'                => 0,
    'TrustServerCertificate' => 1,
    'CharacterSet'           => 'UTF-8',
]);
if (!$conn) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " ERROR conexion SQL: " . print_r(sqlsrv_errors(), true) . "\n");
    exit(1);
}

$api = new ApiWin($conn, $credentials);

try {
    $res = $api->sincronizar();
    echo date('Y-m-d H:i:s') . ' OK ' . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    try { $api->logError($e); } catch (\Throwable $ign) {}
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ERROR ' . $e->getMessage() . "\n");
    exit(1);
}