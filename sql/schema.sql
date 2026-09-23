-- =====================================================================
-- trinityp_api_win · esquema completo (SQL Server)
-- Cliente: WIN (WI-NET TELECOM) · Desarrollado por XINTEC
-- Single-line statements separados por "GO" para el instalador PHP.
-- =====================================================================
IF DB_ID('trinityp_api_win') IS NULL EXEC('CREATE DATABASE trinityp_api_win');
GO
USE trinityp_api_win;
GO
-- Tabla principal: una fila por orden (dedupe por orden_id)
IF OBJECT_ID('dbo.win_ordenes','U') IS NULL
CREATE TABLE dbo.win_ordenes (
    id                  bigint IDENTITY(1,1) NOT NULL,
    orden_id            int NOT NULL,
    tipo_orden          nvarchar(50)  NULL,
    tipo_trabajo        nvarchar(100) NULL,
    motivo_trabajo      nvarchar(250) NULL,
    fecha_solicitud     datetime2     NULL,
    cliente             nvarchar(250) NULL,
    tipo                nvarchar(100) NULL,
    tipo_cliente_id     int           NULL,
    producto            nvarchar(250) NULL,
    cuadrilla           nvarchar(250) NULL,
    fecha_visita        datetime2     NULL,
    estado              nvarchar(100) NULL,
    localidad           nvarchar(150) NULL,
    provincia           nvarchar(150) NULL,
    fecha_ultimo_estado datetime2     NULL,
    iden_servicio       nvarchar(max) NULL,
    region              nvarchar(250) NULL,
    zona                nvarchar(150) NULL,
    empresa             nvarchar(250) NULL,
    pais                nvarchar(50)  NULL,
    ubicacion           nvarchar(100) NULL,
    codi_segui_clien    nvarchar(100) NULL,
    codi_segui          nvarchar(100) NULL,
    tele_movil          nvarchar(50)  NULL,
    tele_fijo           nvarchar(50)  NULL,
    prioridad           nvarchar(50)  NULL,
    fecha_fin_visita    datetime2     NULL,
    fecha_ini_visita    datetime2     NULL,
    motivo_cancelacion  nvarchar(250) NULL,
    motivo_finalizacion nvarchar(250) NULL,
    motivo_anulacion    nvarchar(250) NULL,
    motivo_suspension   nvarchar(250) NULL,
    proveedor           nvarchar(200) NULL,
    sector_operativo    nvarchar(200) NULL,
    motivo              nvarchar(250) NULL,
    motivo_regestion    nvarchar(250) NULL,
    id_proyecto         nvarchar(100) NULL,
    codigo_postal       nvarchar(20)  NULL,
    primera_consulta    datetime2 NOT NULL CONSTRAINT DF_wo_pc DEFAULT SYSDATETIME(),
    ultima_consulta     datetime2 NOT NULL CONSTRAINT DF_wo_uc DEFAULT SYSDATETIME(),
    CONSTRAINT PK_win_ordenes PRIMARY KEY (orden_id)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_win_ordenes_estado')
    CREATE INDEX IX_win_ordenes_estado ON dbo.win_ordenes (estado);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_win_ordenes_fecha_visita')
    CREATE INDEX IX_win_ordenes_fecha_visita ON dbo.win_ordenes (fecha_visita);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_win_ordenes_fecha_solicitud')
    CREATE INDEX IX_win_ordenes_fecha_solicitud ON dbo.win_ordenes (fecha_solicitud);
GO
-- Log de ejecuciones (histórico de sincronizaciones)
IF OBJECT_ID('dbo.win_consumos','U') IS NULL
CREATE TABLE dbo.win_consumos (
    id              bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_win_consumos PRIMARY KEY,
    codi_usua       nvarchar(50)  NULL,
    nume_cuenta     nvarchar(250) NULL,
    fecha_ejecucion datetime2 NOT NULL CONSTRAINT DF_wc_fe DEFAULT SYSDATETIME(),
    total_obtenidos int NOT NULL CONSTRAINT DF_wc_to DEFAULT 0,
    insertados      int NOT NULL CONSTRAINT DF_wc_in DEFAULT 0,
    actualizados    int NOT NULL CONSTRAINT DF_wc_ac DEFAULT 0,
    http_estado     int NULL,
    error           nvarchar(max) NULL
);
GO
-- Vista consolidada para Operaciones
IF OBJECT_ID('dbo.vw_win_ordenes','V') IS NOT NULL DROP VIEW dbo.vw_win_ordenes;
GO
CREATE VIEW dbo.vw_win_ordenes AS
SELECT
    id, orden_id, tipo_orden, tipo_trabajo, motivo_trabajo, fecha_solicitud,
    cliente, tipo, tipo_cliente_id, producto, cuadrilla, fecha_visita, estado,
    localidad, provincia, fecha_ultimo_estado, iden_servicio, region, zona,
    empresa, pais, ubicacion, codi_segui_clien, codi_segui, tele_movil,
    tele_fijo, prioridad, fecha_fin_visita, fecha_ini_visita, motivo_cancelacion,
    motivo_finalizacion, motivo_anulacion, motivo_suspension, proveedor,
    sector_operativo, motivo, motivo_regestion, id_proyecto, codigo_postal,
    primera_consulta, ultima_consulta
FROM dbo.win_ordenes;
GO
IF OBJECT_ID('dbo.vw_win_consumos','V') IS NOT NULL DROP VIEW dbo.vw_win_consumos;
GO
CREATE VIEW dbo.vw_win_consumos AS
SELECT
    id, codi_usua, nume_cuenta, fecha_ejecucion, total_obtenidos, insertados,
    actualizados, http_estado, error,
    CASE WHEN error IS NOT NULL AND error <> '' THEN 'Error' ELSE 'OK' END AS resultado
FROM dbo.win_consumos;
GO