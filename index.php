<?php
/**
 * trinityp_api_win - Vista consolidada para Operaciones.
 *
 * - Acceso con login simple (config/auth.php).
 * - Consulta con filtros y paginacion sobre el historico (dbo.win_ordenes).
 * - Descarga CSV (todas las columnas de la vista dbo.vw_win_ordenes).
 * - "Consumir ahora" (requiere webkey) para ejecutar una sincronizacion con la API.
 *
 * XINTEC · cliente WIN.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('America/Lima');

session_start();

$auth       = require __DIR__ . '/config/auth.php';
$loginError = null;
$autenticado = !empty($_SESSION['win_login']);

if (!$autenticado) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['login'])) {
        $u = trim((string)($_POST['usuario'] ?? ''));
        $p = (string)($_POST['contrasena'] ?? '');
        if (hash_equals($auth['usuario'], $u) && hash_equals($auth['contrasena'], $p)) {
            session_regenerate_id(true);
            $_SESSION['win_login'] = true;
            $autenticado = true;
        } else {
            $loginError = 'Usuario o contrase\u00f1a incorrecta.';
        }
    }
}

if (!$autenticado) {
    header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acceso · API WIN · XINTEC</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#0f1720;color:#e6edf3;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .box{background:#16202b;border:1px solid #24313f;border-radius:12px;padding:28px 30px;width:340px}
  .box h1{font-size:17px;margin-bottom:2px}
  .box .sub{color:#8b98a5;font-size:12.5px;margin-bottom:20px}
  .box label{display:block;font-size:12.5px;color:#c2ccd6;margin:12px 0 4px}
  .box input{width:100%;padding:9px 11px;background:#0c1520;border:1px solid #2d3f52;border-radius:8px;color:#e6edf3;font-size:13.5px}
  .box input:focus{outline:none;border-color:#5fb3ff}
  .btn{width:100%;margin-top:20px;background:#e8b93d;color:#0f1720;border:0;border-radius:8px;padding:10px;font-size:14px;font-weight:600;cursor:pointer}
  .btn:hover{background:#f1c75b}
  .err{background:#261312;border:1px solid #f85149;color:#f85149;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:12px}
</style>
</head>
<body>
  <form class="box" method="post">
    <h1>XINTEC · API WIN</h1>
    <div class="sub">Acceso restringido · consumo de la API de WI-NET TELECOM</div>
    <?php if ($loginError): ?><div class="err"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <label for="u">Usuario</label>
    <input id="u" name="usuario" type="text" autocomplete="username" required autofocus>
    <label for="p">Contraseña</label>
    <input id="p" name="contrasena" type="password" autocomplete="current-password" required>
    <button class="btn" type="submit" name="login" value="1">Ingresar</button>
  </form>
</body>
</html><?php
    exit;
}

if (isset($_GET['salir'])) {
    session_destroy();
    header('Location: ' . (strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '.'));
    exit;
}

$config = require __DIR__ . '/config/database.php';

$conn = sqlsrv_connect($config['server'], [
    'Database'               => $config['database'],
    'UID'                    => $config['uid'],
    'PWD'                    => $config['pwd'],
    'Encrypt'                => 0,
    'TrustServerCertificate' => 1,
    'CharacterSet'           => 'UTF-8',
]);

if (!$conn) {
    http_response_code(500);
    echo '<h1>No se pudo conectar a la base de datos</h1><pre>' . htmlspecialchars(print_r(sqlsrv_errors(), true)) . '</pre>';
    exit;
}

/* ---------------------------------------------------------------- */
/*  Helpers SQL                                                      */
/* ---------------------------------------------------------------- */

function qEjecutar($conn, string $sql, array $valores = [])
{
    $refs = [];
    foreach ($valores as $i => $v) {
        $refs[$i] = &$valores[$i];
    }
    $r = sqlsrv_query($conn, $sql, $refs);
    if ($r === false) {
        throw new RuntimeException('SQL: ' . print_r(sqlsrv_errors(), true));
    }
    return $r;
}

function qFila($conn, string $sql, array $valores = [])
{
    $r = qEjecutar($conn, $sql, $valores);
    return sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC);
}

/**
 * Construye WHERE+params a partir de los filtros GET.
 * @return array [whereSql, params, totalSinPaginacion|null]
 */
function construirFiltros(array $g, bool $conConteo = false): array
{
    $w       = [];
    $params  = [];

    if (!empty($g['q'])) {
        $w[] = '(cliente LIKE ? OR codi_segui LIKE ? OR tele_movil LIKE ? OR codi_segui_clien LIKE ?)';
        $v = '%' . trim($g['q']) . '%';
        array_push($params, $v, $v, $v, $v);
    }
    if (!empty($g['estado'])) {
        $w[] = 'estado = ?';
        $params[] = $g['estado'];
    }
    if (!empty($g['localidad'])) {
        $w[] = 'localidad LIKE ?';
        $params[] = '%' . trim($g['localidad']) . '%';
    }
    if (!empty($g['orden'])) {
        $w[] = 'orden_id = ?';
        $params[] = (int)$g['orden'];
    }
    if (!empty($g['desde'])) {
        $w[] = 'fecha_visita >= ?';
        $params[] = $g['desde'];
    }
    if (!empty($g['hasta'])) {
        $w[] = 'fecha_visita < DATEADD(day, 1, ?)';
        $params[] = $g['hasta'];
    }

    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    return [$where, $params];
}

function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------- */
/*  Accion: consumir ahora                                           */
/* ---------------------------------------------------------------- */

$mensaje = null;
$mensajeTipo = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consumir'])) {
    $credentials = require __DIR__ . '/config/credentials.php';
    if ($_POST['webkey'] === $credentials['webkey']) {
        require __DIR__ . '/lib/ApiWin.php';
        try {
            $api = new ApiWin($conn, $credentials);
            $res = $api->sincronizar();
            $mensaje = 'Sincronizacion completada: obtenidos=' . $res['obtenidos']
                     . ', insertados=' . $res['insertados'] . ', actualizados=' . $res['actualizados'];
            $mensajeTipo = 'ok';
        } catch (\Throwable $e) {
            try { $api->logError($e); } catch (\Throwable $ign) {}
            $mensaje = 'Error en la sincronizacion: ' . $e->getMessage();
            $mensajeTipo = 'err';
        }
    } else {
        $mensaje = 'Clave invalida.';
        $mensajeTipo = 'err';
    }
}

/* ---------------------------------------------------------------- */
/*  Exportacion CSV                                                  */
/* ---------------------------------------------------------------- */

if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    [$where, $params] = construirFiltros($_GET);
    $sql = 'SELECT * FROM dbo.vw_win_ordenes' . $where . ' ORDER BY id DESC';

    $cabeceras = [
        'ID', 'OrdenId', 'TipoOrden', 'TipoTrabajo', 'MotivoTrabajo', 'F.Solicitud',
        'Cliente', 'Tipo', 'TipoClienteId', 'Producto', 'Cuadrilla', 'F.Visita',
        'Estado', 'Localidad', 'Provincia', 'F.UtlimoEstado', 'IdenServicio',
        'Region', 'Zona', 'Empresa', 'Pais', 'Ubicacion', 'CodiSeguiCliente',
        'CodiSegui', 'TelefonoMovil', 'TelefonoFijo', 'Prioridad', 'F.FinVisita',
        'F.IniVisita', 'MotivoCancelacion', 'MotivoFinalizacion', 'MotivoAnulacion',
        'MotivoSuspension', 'Proveedor', 'SectorOperativo', 'Motivo',
        'MotivoRegestion', 'IdProyecto', 'CodigoPostal', 'PrimeraConsulta',
        'UltimaConsulta',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="win_ordenes_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para Excel

    $out = fopen('php://output', 'w');
    fputcsv($out, $cabeceras, ';');
    $r = qEjecutar($conn, $sql, $params);
    $cols = array_keys(sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC));
    // re-ejecutar ya que consumimos la primera fila
    $r = qEjecutar($conn, $sql, $params);
    $primera = true;
    while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
        $fila = [];
        foreach ($cabeceras as $i => $cab) {
            $k = $primera ? array_keys($row)[$i] : $cols[$i] ?? '';
            $val = $row[$k] ?? '';
            if ($val instanceof DateTime) { $val = $val->format('Y-m-d H:i:s'); }
            $fila[] = $val;
        }
        fputcsv($out, $fila, ';');
        $primera = false;
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------- */
/*  Datos para la vista                                               */
/* ---------------------------------------------------------------- */

$porEstado = [];
$r = qEjecutar($conn, 'SELECT TOP 8 estado, COUNT(*) AS total FROM dbo.win_ordenes GROUP BY estado ORDER BY total DESC');
while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
    $porEstado[] = $row;
}

$resumen = qFila($conn, 'SELECT COUNT(*) AS total_ordenes FROM dbo.win_ordenes');
$ultimoConsumo = qFila($conn, 'SELECT TOP 1 fecha_ejecucion, total_obtenidos, insertados, actualizados, error FROM dbo.win_consumos ORDER BY id DESC');

$ultimosConsumos = [];
$r = qEjecutar($conn, 'SELECT TOP 5 fecha_ejecucion, total_obtenidos, insertados, actualizados, error FROM dbo.win_consumos ORDER BY id DESC');
while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
    $ultimosConsumos[] = $row;
}

/* filtros + paginacion */
[$where, $params] = construirFiltros($_GET);
$total = (int)qFila($conn, 'SELECT COUNT(*) AS t FROM dbo.win_ordenes' . $where, $params)['t'];
$pag   = max(1, (int)($_GET['pag'] ?? 1));
$per   = 100;
$sqlLista = 'SELECT id, orden_id, fecha_solicitud, cliente, tipo, producto, cuadrilla, fecha_visita, estado, localidad, zona, codi_segui, tele_movil, prioridad, sector_operativo, fecha_ultimo_estado
             FROM dbo.win_ordenes' . $where . ' ORDER BY id DESC OFFSET ' . (($pag - 1) * $per) . ' ROWS FETCH NEXT ' . $per . ' ROWS ONLY';
$lista = [];
$r = qEjecutar($conn, $sqlLista, $params);
while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) { $row[$k] = $v->format('Y-m-d H:i'); }
    }
    $lista[] = $row;
}
$paginas = max(1, (int)ceil($total / $per));

/* estados disponibles para el filtro */
$estados = [];
$r = qEjecutar($conn, 'SELECT DISTINCT estado FROM dbo.win_ordenes WHERE estado IS NOT NULL ORDER BY estado');
while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_NUMERIC)) { $estados[] = $row[0]; }

function colorEstado(string $e): string
{
    $e = mb_strtolower(trim($e));
    if (strpos($e, 'agend') !== false) return '#2f6fed';
    if (strpos($e, 'fin') !== false || strpos($e, 'finaliz') !== false) return '#1f9d55';
    if (strpos($e, 'cancel') !== false || strpos($e, 'anul') !== false || strpos($e, 'suspend') !== false) return '#d0452a';
    if (strpos($e, 'visita') !== false || strpos($e, 'rumbo') !== false || strpos($e, 'viaje') !== false) return '#d98b1f';
    return '#555';
}

function urlFiltros(): string
{
    $q = $_GET;
    unset($q['pag']);
    return http_build_query($q);
}

$qs = urlFiltros();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API WIN · Historial de órdenes · XINTEC</title>
<style>
  :root{--azul:#0b2a4a;--acento:#e8b93d;}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,Arial,sans-serif;background:#f2f4f8;color:#222}
  .top{background:var(--azul);color:#fff;padding:14px 22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .top h1{font-size:19px;font-weight:600}
  .top .sub{color:#cbd5e1;font-size:12.5px}
  .top a.brand{color:var(--acento);text-decoration:none;font-weight:600;margin-left:auto;font-size:13px}
  .wrap{max-width:1300px;margin:0 auto;padding:18px}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
  .card{background:#fff;border:1px solid #e3e6ec;border-radius:10px;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .card .num{font-size:24px;font-weight:700;color:var(--azul)}
  .card .lab{font-size:12px;color:#6b7280;margin-top:2px}
  .chip{display:inline-block;background:#eef2f7;border-radius:12px;padding:3px 9px;font-size:11.5px;margin:2px 3px 0 0;color:#374151}
  .chip b{color:var(--azul)}
  .panel{background:#fff;border:1px solid #e3e6ec;border-radius:10px;padding:14px 16px;margin-bottom:16px}
  .panel h2{font-size:14px;color:var(--azul);margin-bottom:10px}
  form.filtros{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
  form.filtros input,form.filtros select{padding:7px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px}
  .btn{background:var(--azul);color:#fff;border:0;border-radius:7px;padding:8px 14px;font-size:13px;cursor:pointer}
  .btn.sec{background:#fff;color:var(--azul);border:1px solid var(--azul)}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e6ec;border-radius:10px;overflow:hidden;font-size:12.5px}
  th{background:#0f3057;color:#fff;text-align:left;padding:8px 9px;white-space:nowrap}
  td{padding:7px 9px;border-bottom:1px solid #eef0f4;vertical-align:top}
  tr:hover td{background:#f7f9fc}
  .st{color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;white-space:nowrap;display:inline-block}
  .banner{border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13.5px}
  .banner.ok{background:#eaf7ef;color:#1f7a45;border:1px solid #bfe8cf}
  .banner.err{background:#fdecea;color:#a33327;border:1px solid #f3c2bd}
  .pager{display:flex;gap:8px;margin-top:12px;align-items:center;font-size:13px}
  .pager a{color:var(--azul);text-decoration:none;border:1px solid #cbd5e1;border-radius:6px;padding:4px 10px}
  .tiny{font-size:11.5px;color:#6b7280}
  .mono{font-family:Consolas,monospace;font-size:11.5px}
  .dk{background:#fff;border:1px solid #e3e6ec;border-radius:10px;margin-bottom:16px;}
  .dk summary{padding:10px 16px;cursor:pointer;font-size:13px;color:var(--azul);font-weight:600}
  .dk .inner{padding:0 16px 12px;font-size:12.5px}
</style>
</head>
<body>
<div class="top">
  <h1>XINTEC · API WIN</h1>
  <span class="sub">Historial de órdenes del service layer (WI-NET TELECOM)</span>
  <a class="brand" href="https://trinity.xintech.pe/">&larr; Panel</a>
  <a class="brand" href="?salir=1">Salir (<?= esc($auth['usuario']) ?>)</a>
</div>

<div class="wrap">
  <?php if ($mensaje): ?>
    <div class="banner <?= $mensajeTipo === 'ok' ? 'ok' : 'err' ?>"><?= esc($mensaje) ?></div>
  <?php endif; ?>

  <div class="cards">
    <div class="card"><div class="num"><?= (int)$resumen['total_ordenes'] ?></div><div class="lab">Órdenes registradas</div></div>
    <div class="card">
      <div class="num"><?= $ultimoConsumo ? (int)$ultimoConsumo['total_obtenidos'] : 0 ?></div>
      <div class="lab">Última corrida · obtenidas</div>
      <div class="tiny"><?= $ultimoConsumo ? esc($ultimoConsumo['fecha_ejecucion']->format('d/m/Y H:i')) : '—' ?></div>
    </div>
    <div class="card">
      <div class="num" style="color:<?= $ultimoConsumo && empty($ultimoConsumo['error']) ? '#1f9d55' : '#d0452a' ?>">
        <?= $ultimoConsumo && empty($ultimoConsumo['error']) ? 'OK' : 'ERR' ?>
      </div>
      <div class="lab">Estado de la API</div>
      <div class="tiny">cron cada hora</div>
    </div>
    <div class="card">
      <div class="num"><?= count($estados) ?></div>
      <div class="lab">Estados distintos</div>
      <div class="tiny"><?= count($ultimosConsumos) ?> últimas corridas logueadas</div>
    </div>
  </div>

  <div class="panel">
    <h2>Órdenes por estado (top 8)</h2>
    <?php foreach ($porEstado as $pe): ?>
      <span class="chip"><b><?= esc($pe['estado'] ?: '—') ?></b> <?= (int)$pe['total'] ?></span>
    <?php endforeach; ?>
    <?php if (!$porEstado): ?><span class="tiny">Sin datos todavía.</span><?php endif; ?>
  </div>

  <div class="panel">
    <h2>Filtros y consulta</h2>
    <form class="filtros" method="get">
      <input type="text" name="q" placeholder="Cliente / CodiSegui / Teléfono" value="<?= esc($_GET['q'] ?? '') ?>">
      <input type="text" name="orden" placeholder="OrdenId" value="<?= esc($_GET['orden'] ?? '') ?>" style="width:110px">
      <select name="estado">
        <option value="">Estado (todos)</option>
        <?php foreach ($estados as $e): ?>
          <option value="<?= esc($e) ?>" <?= (($_GET['estado'] ?? '') === $e) ? 'selected' : '' ?>><?= esc($e) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="localidad" placeholder="Localidad" value="<?= esc($_GET['localidad'] ?? '') ?>">
      <input type="date" name="desde" value="<?= esc($_GET['desde'] ?? '') ?>">
      <input type="date" name="hasta" value="<?= esc($_GET['hasta'] ?? '') ?>">
      <button class="btn" type="submit">Buscar</button>
      <a class="btn sec" href="?">Limpiar</a>
      <a class="btn sec" href="?exportar=csv&<?= $qs ?>">Descargar CSV</a>
      <span class="tiny" style="margin-left:auto"><?= $total ?> resultado(s) · página <?= $pag ?> de <?= $paginas ?></span>
    </form>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th><th>Orden</th><th>F. Solicitud</th><th>Cliente</th><th>Tipo</th>
        <th>Producto</th><th>Cuadrilla</th><th>F. Visita</th><th>Estado</th>
        <th>Localidad</th><th>Zona</th><th>CodiSegui</th><th>Teléfono</th>
        <th>Prioridad</th><th>Sector Operativo</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$lista): ?>
        <tr><td colspan="15" style="text-align:center;color:#6b7280;padding:18px">Sin resultados para estos filtros.</td></tr>
      <?php endif; ?>
      <?php foreach ($lista as $i => $r): ?>
        <tr>
          <td class="tiny"><?= ($pag - 1) * $per + $i + 1 ?></td>
          <td class="mono"><?= (int)$r['orden_id'] ?></td>
          <td class="tiny"><?= esc($r['fecha_solicitud'] ?? '') ?></td>
          <td><?= esc($r['cliente']) ?></td>
          <td><?= esc($r['tipo']) ?></td>
          <td><?= esc($r['producto']) ?></td>
          <td><?= esc($r['cuadrilla']) ?></td>
          <td class="tiny"><?= esc($r['fecha_visita'] ?? '') ?></td>
          <td><span class="st" style="background:<?= colorEstado((string)$r['estado']) ?>"><?= esc($r['estado']) ?></span></td>
          <td><?= esc($r['localidad']) ?></td>
          <td><?= esc($r['zona']) ?></td>
          <td class="mono"><?= esc($r['codi_segui']) ?></td>
          <td class="mono"><?= esc($r['tele_movil']) ?></td>
          <td><?= esc($r['prioridad']) ?></td>
          <td class="tiny"><?= esc($r['sector_operativo']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="pager">
    <?php if ($pag > 1): ?><a href="?pag=<?= $pag - 1 ?>&<?= $qs ?>">&laquo; Anterior</a><?php endif; ?>
    <span>Página <?= $pag ?> de <?= $paginas ?></span>
    <?php if ($pag < $paginas): ?><a href="?pag=<?= $pag + 1 ?>&<?= $qs ?>">Siguiente &raquo;</a><?php endif; ?>
  </div>

  <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:18px">
    <details class="dk" style="flex:1;min-width:330px">
      <summary>Últimas sincronizaciones (histórico de consumos)</summary>
      <div class="inner">
        <?php if (!$ultimosConsumos): ?><span class="tiny">Sin corridas todavía.</span><?php endif; ?>
        <?php foreach ($ultimosConsumos as $uc): ?>
          <div style="margin:5px 0">
            <span class="mono"><?= esc($uc['fecha_ejecucion']->format('Y-m-d H:i:s')) ?></span> ·
            obtenidas <b><?= (int)$uc['total_obtenidos'] ?></b> · insertadas <b><?= (int)$uc['insertados'] ?></b> ·
            actualizadas <b><?= (int)$uc['actualizados'] ?></b>
            <?php if (!empty($uc['error'])): ?> · <span style="color:#d0452a"><?= esc($uc['error']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </details>

    <details class="dk" style="flex:1;min-width:330px">
      <summary>Ejecutar sincronización manual</summary>
      <div class="inner">
        <form method="post" onsubmit="return confirm('¿Deseas consumir la API de WIN ahora?')">
          <input type="hidden" name="consumir" value="1">
          <input type="password" name="webkey" placeholder="Clave de sincronización" style="padding:7px 10px;border:1px solid #cbd5e1;border-radius:7px">
          <button class="btn" type="submit">Consumir ahora</button>
        </form>
        <p class="tiny" style="margin-top:8px">Solo personal autorizado. El cron lo hace cada hora automáticamente.</p>
      </div>
    </details>
  </div>

  <p class="tiny" style="margin:20px 0 30px;text-align:center">XINTEC · trinityp_api_win · BD <span class="mono">trinityp_api_win</span> (10.10.0.7) · Últimos 100 registros por página · CSV: todas las columnas</p>
</div>
</body>
</html>