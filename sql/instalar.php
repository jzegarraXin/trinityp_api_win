<?php
/**
 * Instalador del esquema trinityp_api_win.
 * Uso (CLI): php sql/instalar.php
 * Ejecuta sql/schema.sql (separado por l\u00edneas "GO").
 * Se conecta como sa al SQL Server 10.10.0.7 (debe existir config/database.php).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/../config/database.php';
if (!isset($config['pwd']) || $config['pwd'] === 'TU_PASSWORD_SA') {
    fwrite(STDERR, "config/database.php no esta configurado.\n");
    exit(1);
}

$conn = sqlsrv_connect($config['server'], [
    'Database'              => 'master',
    'UID'                   => $config['uid'],
    'PWD'                   => $config['pwd'],
    'Encrypt'               => 0,
    'TrustServerCertificate'=> 1,
    'CharacterSet'          => 'UTF-8',
]);
if (!$conn) {
    fwrite(STDERR, 'No se pudo conectar a ' . $config['server'] . ': ' . print_r(sqlsrv_errors(), true));
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "No se encontro sql/schema.sql\n");
    exit(1);
}

$errores = 0;
foreach (explode("\nGO", $sql) as $i => $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    if (!sqlsrv_query($conn, $stmt)) {
        $errores++;
        fwrite(STDERR, "[$i] ERROR: " . print_r(sqlsrv_errors(), true) . "\n  -> " . substr($stmt, 0, 120) . "\n");
    } else {
        echo "[$i] OK\n";
    }
}

if ($errores > 0) {
    fwrite(STDERR, "Finalizado con $errores error(es).\n");
    exit(1);
}
echo "Esquema trinityp_api_win instalado correctamente.\n";