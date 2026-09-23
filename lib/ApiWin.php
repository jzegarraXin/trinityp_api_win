<?php
/**
 * ApiWin - Cliente PHP de la API WIN (Phoenix Service Layer).
 *
 * Flujo:
 *  1. login()            -> POST /API/Login/Autenticar  (token JWT, vigencia 5 min)
 *  2. cargarDatos(token) -> POST /API/Win/CargarDatos   (lista de ordenes)
 *  3. sincronizar()      -> UPSERT por OrdenId (antidup):
 *                           si la orden ya existe se actualiza; si no, se inserta.
 *                          Ademas registra la corrida en win_consumos.
 *
 * Desarrollado por XINTEC para el cliente WIN.
 */
class ApiWin
{
    /** @var resource SQL Server connection (sqlsrv) */
    private $db;
    /** @var array config de credentials.php */
    private $cfg;

    /** Mapa: campo del JSON de la API -> columna SQL. El orden define el orden de parametros. */
    private const MAPA = [
        'OrdenId'            => 'orden_id',
        'TipoOrden'          => 'tipo_orden',
        'TipoTraba'          => 'tipo_trabajo',
        'Motivo Trabajo'     => 'motivo_trabajo',
        'F.Soli'             => 'fecha_solicitud',
        'Cliente'            => 'cliente',
        'Tipo'               => 'tipo',
        'TipoClienId'        => 'tipo_cliente_id',
        'Producto'           => 'producto',
        'Cuadrilla'          => 'cuadrilla',
        'F.Visita'           => 'fecha_visita',
        'Estado'             => 'estado',
        'Localidad'          => 'localidad',
        'Provincia'          => 'provincia',
        'FechaUltiEsta'      => 'fecha_ultimo_estado',
        'IdenServi'          => 'iden_servicio',
        'Region'             => 'region',
        'Zona'               => 'zona',
        'Empresa'            => 'empresa',
        'Pais'               => 'pais',
        'Ubicación'          => 'ubicacion',
        'CodiSeguiClien'     => 'codi_segui_clien',
        'CodiSegui'          => 'codi_segui',
        'TeleMovilNume'      => 'tele_movil',
        'TeleFijoNume'       => 'tele_fijo',
        'Prioridad'          => 'prioridad',
        'FechaFinVisi'       => 'fecha_fin_visita',
        'FechaIniVisi'       => 'fecha_ini_visita',
        'Motivo Cancelación' => 'motivo_cancelacion',
        'Motivo Finalización'=> 'motivo_finalizacion',
        'Motivo Anulación'   => 'motivo_anulacion',
        'Motivo Suspensión'  => 'motivo_suspension',
        'Proveedeor'         => 'proveedor',
        'Sector Operativo'   => 'sector_operativo',
        'Motivo'             => 'motivo',
        'Motivo Regestión'   => 'motivo_regestion',
        'Id de Proyecto'     => 'id_proyecto',
        'Código Postal'      => 'codigo_postal',
    ];

    public function __construct($conn, array $cfg)
    {
        $this->db  = $conn;
        $this->cfg = $cfg;
    }

    /* ------------------------------------------------------------------ */
    /*  API                                                                */
    /* ------------------------------------------------------------------ */

    public function login(): string
    {
        $r = $this->httpPost($this->cfg['url_login'], [
            'Usuario'    => $this->cfg['usuario'],
            'Contraseña' => $this->cfg['contrasena'],
        ]);
        $json = json_decode($r['cuerpo'], true);
        if (!is_array($json) || empty($json['Token'])) {
            $det = is_array($json) && isset($json['ErrDes']) ? $json['ErrDes'] : $r['cuerpo'];
            throw new RuntimeException('[login] La API WIN rechazo las credenciales: ' . $det);
        }
        return (string)$json['Token'];
    }

    public function cargarDatos(string $token): array
    {
        $r = $this->httpPost($this->cfg['url_datos'], [
            'codiUsua'   => $this->cfg['codi_usua'],
            'numeCuenta' => $this->cfg['nume_cuenta'],
        ], $token);
        $json = json_decode($r['cuerpo'], true);
        if (!is_array($json)) {
            throw new RuntimeException('[CargarDatos] Respuesta no valida (HTTP ' . $r['http'] . '): ' . substr($r['cuerpo'], 0, 300));
        }
        return $json;
    }

    /* ------------------------------------------------------------------ */
    /*  Sincronizacion (login + datos + UPSERT + log)                      */
    /* ------------------------------------------------------------------ */

    public function sincronizar(): array
    {
        $token       = $this->login();
        $datos       = $this->cargarDatos($token);
        $insertados  = 0;
        $actualizados = 0;

        foreach ($datos as $fila) {
            if (!is_array($fila)) continue;
            $ordenId = isset($fila['OrdenId']) ? (int)$fila['OrdenId'] : 0;
            if ($ordenId <= 0) continue;

            $valores = $this->valoresDe($fila);

            if ($this->existe($ordenId)) {
                $this->actualizar($ordenId, $valores);
                $actualizados++;
            } else {
                $this->insertar($ordenId, $valores);
                $insertados++;
            }
        }

        $n = count($datos);
        $this->logConsumo($n, $insertados, $actualizados, 200, null);

        return [
            'obtenidos'   => $n,
            'insertados'  => $insertados,
            'actualizados'=> $actualizados,
        ];
    }

    public function logError(\Throwable $e): void
    {
        $this->logConsumo(0, 0, 0, null, substr($e->getMessage(), 0, 4000));
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP                                                               */
    /* ------------------------------------------------------------------ */

    private function httpPost(string $url, array $payload, ?string $token = null): array
    {
        $h = ['Content-Type: application/json'];
        if ($token !== null) {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $cuerpo = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $http   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($cuerpo === false || $errno !== 0) {
            throw new RuntimeException("Error de red con la API WIN ($errno): $error");
        }
        return ['http' => $http, 'cuerpo' => $cuerpo];
    }

    /* ------------------------------------------------------------------ */
    /*  SQL (sqlsrv, parametrizado)                                        */
    /* ------------------------------------------------------------------ */

    private static function columnas(): array
    {
        return array_values(self::MAPA); // orden_id primero (MAPA inicia con OrdenId)
    }

    private static function paramables(): array
    {
        $f = self::columnas();
        array_shift($f); // descarta orden_id (va aparte / en el WHERE)
        return $f;
    }

    private function valoresDe(array $fila): array
    {
        $out = [];
        foreach (self::MAPA as $apiKey => $col) {
            if ($apiKey === 'OrdenId') continue;
            $v = array_key_exists($apiKey, $fila) ? $fila[$apiKey] : null;
            if ($v === null || $v === '') { $out[] = null; continue; }
            if ($col === 'tipo_cliente_id') { $out[] = (int)$v; continue; }
            $out[] = (string)$v;
        }
        return $out;
    }

    /** Ejecuta una consulta parametrizada devolviendo el recurso. */
    private function ejecutar(string $sql, array $valores)
    {
        $refs = [];
        foreach ($valores as $i => $v) {
            $refs[$i] = &$valores[$i];
        }
        $r = sqlsrv_query($this->db, $sql, $refs);
        if ($r === false) {
            throw new RuntimeException('SQL: ' . print_r(sqlsrv_errors(), true));
        }
        return $r;
    }

    private function existe(int $ordenId): bool
    {
        $v = [$ordenId];
        $r = sqlsrv_query($this->db, 'SELECT COUNT(*) FROM dbo.win_ordenes WHERE orden_id = ?', [&$v[0]]);
        if ($r === false) {
            throw new RuntimeException('existe: ' . print_r(sqlsrv_errors(), true));
        }
        $row = sqlsrv_fetch_array($r, SQLSRV_FETCH_NUMERIC);
        return (int)$row[0] > 0;
    }

    private function insertar(int $ordenId, array $valores): void
    {
        $cols = self::columnas();
        $ph   = implode(', ', array_fill(0, count($cols), '?'));
        $sql  = 'INSERT INTO dbo.win_ordenes (' . implode(', ', $cols) . ') VALUES (' . $ph . ')';
        $this->ejecutar($sql, array_merge([$ordenId], $valores));
    }

    private function actualizar(int $ordenId, array $valores): void
    {
        $cols = self::paramables();
        $set  = implode(', ', array_map(fn($c) => $c . ' = ?', $cols));
        $sql  = 'UPDATE dbo.win_ordenes SET ' . $set . ', ultima_consulta = SYSDATETIME() WHERE orden_id = ?';
        $this->ejecutar($sql, array_merge($valores, [$ordenId]));
    }

    private function logConsumo(int $total, int $ins, int $act, ?int $http, ?string $error): void
    {
        $sql = 'INSERT INTO dbo.win_consumos (codi_usua, nume_cuenta, fecha_ejecucion, total_obtenidos, insertados, actualizados, http_estado, error)
                VALUES (?, ?, SYSDATETIME(), ?, ?, ?, ?, ?)';
        try {
            $this->ejecutar($sql, [
                $this->cfg['codi_usua'],
                $this->cfg['nume_cuenta'],
                $total,
                $ins,
                $act,
                $http,
                $error,
            ]);
        } catch (\Throwable $e) {
            error_log('[trinityp_api_win] No se pudo registrar el consumo: ' . $e->getMessage());
        }
    }
}