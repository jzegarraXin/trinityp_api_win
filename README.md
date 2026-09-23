# trinityp_api_win

Proyecto PHP (XINTEC) para el cliente **WIN** (WI-NET TELECOM). Consume la API
del service layer de Win (`win-phoenixservicelayer.azurewebsites.net`), almacena
el histórico de órdenes en SQL Server y expone una vista consolidada con
descarga CSV para Operaciones.

| Concepto | Valor |
|---|---|
| URL publica | `https://trinity.xintech.pe/trinityp_api_win/` |
| Servidor | `172.30.10.12` (`/var/www/biosac.net/public/trinityp_api_win/`) |
| Base de datos | `trinityp_api_win` en `10.10.0.7` |
| API cliente | `win-phoenixservicelayer.azurewebsites.net` |
| Ejecución periódica | cron cada hora (`0 * * * *`) |

## Estructura

```
trinityp_api_win/
├── index.php                  Vista consolidada (filtros + descarga CSV + "consumir ahora")
├── config/
│   ├── database.php           Conexión SQL Server (10.10.0.7)  [NO se sube a GitHub]
│   ├── database.sample.php    Plantilla de conexión
│   ├── credentials.php        Credenciales API del cliente       [NO se sube a GitHub]
│   └── credentials.sample.php Plantilla de credenciales
├── lib/
│   └── ApiWin.php             Cliente de la API (login JWT + CargarDatos + upsert dedupe)
├── scripts/
│   └── consumir.php           Runner CLI usado por cron
├── sql/
│   ├── schema.sql             DDL completo (BD + tablas + vistas + índices)
│   └── instalar.php           Instalador (ejecuta schema.sql)
└── logs/                      Bitácoras del cron (ignoradas por git)
```

## Instalación

1. `cp config/credentials.sample.php config/credentials.php` y completa los datos
   del cliente (endpoints, `usuario`/`Contraseña`, `codiUsua`, `numeCuenta`, `webkey`).
2. `cp config/database.sample.php config/database.php` y pon la conexión SQL.
3. Crear la BD y tablas (1 sola vez):

```
php sql/instalar.php
```

4. Sincronizar manualmente (prueba):

```
php scripts/consumir.php
```

5. **Cron** (cada hora, minuto 0) en `/etc/crontab` o `crontab -u www-data -e`:

```
0 * * * * www-data php /var/www/biosac.net/public/trinityp_api_win/scripts/consumir.php >> /var/www/biosac.net/public/trinityp_api_win/logs/consumir.log 2>&1
```

## Cómo funciona el dedupe

- La API devuelve una lista de órdenes; el campo **`OrdenId`** es la clave natural.
- En cada ejecución se hace **UPSERT** (si la orden ya existe se actualiza el
  snapshot actual; si no, se inserta nueva). Así **no se duplican** registros y
  el histórico acumula todas las órdenes vistas.
- Cada ejecución queda **logueada en `win_consumos`** (fecha, obtenidos,
  insertados, actualizados, error).

## Base de datos (10.10.0.7 / `trinityp_api_win`)

- `dbo.win_ordenes` — una fila por orden (PK `orden_id`), columnas auditadas
  (`primera_consulta`, `ultima_consulta`). Índices: `estado`, `fecha_visita`.
- `dbo.win_consumos` — log histórico de cada corrida.
- `dbo.vw_win_ordenes` — vista consolidada para consulta de Operaciones.
- `dbo.vw_win_consumos` — vista del log de corridas.

## Panel

Card agregada en el panel de XINTEC (`/var/www/biosac.net/public/index.php`):
"TRINITYP API WIN" → `https://trinity.xintech.pe/trinityp_api_win/`.