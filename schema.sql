-- ============================================================
-- IoT Sensor Map — MySQL Schema  (tables + Event)
--
-- ASSUMPTION: the database is already created (via the Hostinger control
-- panel or XAMPP/phpMyAdmin) and selected before running.
-- This file creates ONLY tables and the automatic cleanup Event.
-- ============================================================

-- -------------------------------------------------------
-- sensors — the sensor registry
--
-- A sensor is deleted in ONE case only:
--   confirmed = 0  AND  registered_at > 3 min atgal
--   (registered, but never sent a single record)
--
-- A confirmed sensor (confirmed = 1) is NEVER deleted
-- automatically — its history must be preserved.
--
-- The name (VLN1, VLN2...) is formed in queries:
--   CONCAT(city_prefix, id) AS label
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS sensors (
    id              INT AUTO_INCREMENT  PRIMARY KEY,
    lat             DECIMAL(10,7)       NOT NULL,
    lng             DECIMAL(10,7)       NOT NULL,
    mac             VARCHAR(17)         NULL DEFAULT NULL COMMENT 'WiFi MAC, NULL while waiting for the first send',
    is_outdoor      TINYINT(1)          NOT NULL DEFAULT 0 COMMENT '0 = indoor, 1 = outdoor',
    secret          VARCHAR(64)         NULL DEFAULT NULL COMMENT 'Optional HMAC shared-secret for the signature',
    city_prefix     VARCHAR(10)         NOT NULL DEFAULT 'VLN',
    confirmed       TINYINT(1)          NOT NULL DEFAULT 0,
    registered_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- UNIQUE with MAC: several pending ones (mac=NULL) at the same location
    -- coexist, because MySQL/MariaDB allows several NULLs in a unique key.
    -- Confirmed ones (real MAC) — unique by lat+lng+mac.
    UNIQUE KEY uq_coords_mac (lat, lng, mac),
    INDEX idx_coords (lat, lng),
    INDEX idx_city_id (city_prefix, id),
    INDEX idx_last_seen (last_seen),
    INDEX idx_confirmed (confirmed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- readings — all measurements (history kept indefinitely)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS readings (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    sensor_id       INT             NOT NULL,
    recorded_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    temperature     DECIMAL(6,2)    NULL COMMENT 'C',
    humidity        DECIMAL(5,2)    NULL COMMENT 'percent',
    co2             DECIMAL(8,2)    NULL COMMENT 'ppm',
    pm1             DECIMAL(8,2)    NULL COMMENT 'ug/m3',
    pm2_5           DECIMAL(8,2)    NULL COMMENT 'ug/m3',
    pm10            DECIMAL(8,2)    NULL COMMENT 'ug/m3',
    grains          DECIMAL(8,2)    NULL COMMENT 'grains/m3',
    radiation       DECIMAL(8,4)    NULL COMMENT 'uSv/h',
    -- Gas / air-quality metrics (MQ-2/4/6/8/135 family and equivalents).
    -- All ppm unless noted; NULL when the sensor does not measure them.
    alcohol         DECIMAL(8,2)    NULL COMMENT 'ethanol ppm (MQ-135)',
    methane         DECIMAL(8,2)    NULL COMMENT 'CH4 ppm (MQ-4)',
    propane         DECIMAL(8,2)    NULL COMMENT 'propane ppm (MQ-2)',
    butane          DECIMAL(8,2)    NULL COMMENT 'butane ppm (MQ-6)',
    lpg             DECIMAL(8,2)    NULL COMMENT 'LPG ppm (MQ-2/6)',
    hydrogen        DECIMAL(8,2)    NULL COMMENT 'H2 ppm (MQ-8)',
    co              DECIMAL(8,2)    NULL COMMENT 'carbon monoxide ppm (MQ-135)',
    smoke           DECIMAL(8,2)    NULL COMMENT 'smoke ppm (MQ-2)',
    ammonia         DECIMAL(8,2)    NULL COMMENT 'NH3 ppm (MQ-135)',
    nox             DECIMAL(8,2)    NULL COMMENT 'NOx ppm (MQ-135)',
    benzene         DECIMAL(8,2)    NULL COMMENT 'benzene ppm (MQ-135)',
    air_quality     DECIMAL(8,2)    NULL COMMENT 'air-quality index (MQ-135)',
    co2_equiv       DECIMAL(8,2)    NULL COMMENT 'CO2 equivalent ppm (MQ-135)',
    INDEX idx_sensor_time  (sensor_id, recorded_at),
    INDEX idx_recorded_at  (recorded_at),
    CONSTRAINT fk_readings_sensor
        FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Migration for existing installs (idempotent) ──────────────────
-- New gas/air-quality columns are added to a readings table that was
-- created before this version. MariaDB supports ADD COLUMN IF NOT EXISTS,
-- so re-running the schema installer safely upgrades an existing DB.
ALTER TABLE readings
    ADD COLUMN IF NOT EXISTS alcohol     DECIMAL(8,2) NULL COMMENT 'ethanol ppm (MQ-135)',
    ADD COLUMN IF NOT EXISTS methane     DECIMAL(8,2) NULL COMMENT 'CH4 ppm (MQ-4)',
    ADD COLUMN IF NOT EXISTS propane     DECIMAL(8,2) NULL COMMENT 'propane ppm (MQ-2)',
    ADD COLUMN IF NOT EXISTS butane      DECIMAL(8,2) NULL COMMENT 'butane ppm (MQ-6)',
    ADD COLUMN IF NOT EXISTS lpg         DECIMAL(8,2) NULL COMMENT 'LPG ppm (MQ-2/6)',
    ADD COLUMN IF NOT EXISTS hydrogen    DECIMAL(8,2) NULL COMMENT 'H2 ppm (MQ-8)',
    ADD COLUMN IF NOT EXISTS co          DECIMAL(8,2) NULL COMMENT 'carbon monoxide ppm (MQ-135)',
    ADD COLUMN IF NOT EXISTS smoke       DECIMAL(8,2) NULL COMMENT 'smoke ppm (MQ-2)',
    ADD COLUMN IF NOT EXISTS ammonia     DECIMAL(8,2) NULL COMMENT 'NH3 ppm (MQ-135)',
    ADD COLUMN IF NOT EXISTS nox         DECIMAL(8,2) NULL COMMENT 'NOx ppm (MQ-135)',
    ADD COLUMN IF NOT EXISTS benzene     DECIMAL(8,2) NULL COMMENT 'benzene ppm (MQ-135)',
    ADD COLUMN IF NOT EXISTS air_quality DECIMAL(8,2) NULL COMMENT 'air-quality index (MQ-135)',
    ADD COLUMN IF NOT EXISTS co2_equiv   DECIMAL(8,2) NULL COMMENT 'CO2 equivalent ppm (MQ-135)';

-- -------------------------------------------------------
-- 1.4 Rate limiting: a counter keyed by key (IP or action)
--     in a sliding window. Used by reading + admin login.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key       VARCHAR(120)  NOT NULL,
    window_start INT           NOT NULL COMMENT 'Unix time, window start',
    counter      INT           NOT NULL DEFAULT 0,
    PRIMARY KEY (rl_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2.2 Audit log: who, when, what was deleted/changed
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    occurred_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_ip    VARCHAR(45)  NULL,
    action      VARCHAR(40)  NOT NULL COMMENT 'pvz. delete_sensor, clear_readings',
    target_id   INT          NULL,
    details     VARCHAR(255) NULL,
    INDEX idx_audit_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2.3 Admin login credential — the administrator's login name
--     is stored ONLY as a one-way hash (never in plaintext).
--     The secret hash stays in includes/settings.php. Single row (id = 1).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_credentials (
    id                       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    email_hash               VARCHAR(255)     NOT NULL COMMENT 'one-way hash of the admin login name',
    password_change_required TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'set after a 24h stage-2 lockout',
    updated_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- Event: only UNCONFIRMED sensors are deleted
-- (sent no measurement within 3 min)
--
-- Requires the Event Scheduler:  SET GLOBAL event_scheduler = ON;
-- If shared hosting does not allow it — use api/cleanup.php via Cron.
-- -------------------------------------------------------
DROP EVENT IF EXISTS cleanup_unconfirmed_sensors;
CREATE EVENT cleanup_unconfirmed_sensors
    ON SCHEDULE EVERY 1 MINUTE
    COMMENT 'Delete sensors that sent no data within 3 min'
    DO
        DELETE FROM sensors
        WHERE confirmed = 0
          AND registered_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)
