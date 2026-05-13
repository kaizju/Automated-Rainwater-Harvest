-- ============================================================
--  EcoRain — Automated Rainwater System
--  Full Migration Script (Base + IoT Water Level Sensor)
--  Run once, top to bottom on a fresh install.
--  Database: automated_rainwater
-- ============================================================

-- ── 0. Database ───────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS automated_rainwater
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE automated_rainwater;

-- ── 1. Users ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    username                   VARCHAR(255)        NOT NULL,
    email                      VARCHAR(255) UNIQUE NOT NULL,
    password                   VARCHAR(255)        NOT NULL,
    role                       ENUM('admin','manager','user') DEFAULT 'user',
    is_verified                TINYINT(1)          DEFAULT 0,
    verification_token         VARCHAR(64)         NULL,
    email_verification_expires DATETIME            NULL,
    created_at                 TIMESTAMP           DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── 2. Activity Logs (depends on users) ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS user_activity_logs (
    activity_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NULL,
    role         ENUM('admin','manager','user') DEFAULT 'user',
    email        VARCHAR(255) NULL,
    action       VARCHAR(50)  NOT NULL,
    status       ENUM('success','failed') NOT NULL DEFAULT 'success',
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    module       VARCHAR(100) NULL    COMMENT 'e.g. tank, users, settings',
    description  TEXT         NULL    COMMENT 'Human-readable detail',
    old_value    TEXT         NULL    COMMENT 'JSON snapshot before change',
    new_value    TEXT         NULL    COMMENT 'JSON snapshot after change',
    severity     ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ual_role_date (role, created_at DESC),
    INDEX idx_ual_user_date (user_id, created_at DESC),
    INDEX idx_ual_action    (action)
) ENGINE=InnoDB;

-- ── 3. Page Visits (depends on users) ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS page_visits (
    visit_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NULL,
    role        ENUM('admin','manager','user') DEFAULT 'user',
    email       VARCHAR(255) NULL,
    page        VARCHAR(255) NOT NULL  COMMENT 'e.g. /App/Admin/admin_dashboard.php',
    page_label  VARCHAR(100) NULL      COMMENT 'Human label e.g. "Admin Dashboard"',
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    visited_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pv_user (user_id, visited_at DESC),
    INDEX idx_pv_role (role, visited_at DESC),
    INDEX idx_pv_page (page)
) ENGINE=InnoDB;

-- ── 4. Tank ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS tank (
    tank_id        INT AUTO_INCREMENT PRIMARY KEY,
    tankname       VARCHAR(255) UNIQUE NOT NULL,
    location_add   VARCHAR(255)        NOT NULL,
    current_liters INT                 NOT NULL DEFAULT 0,
    max_capacity   INT                 NOT NULL DEFAULT 5000,
    status_tank    VARCHAR(255)        NOT NULL
) ENGINE=InnoDB;

-- ── 5. System Alerts (depends on tank + users) ────────────────────────────────

CREATE TABLE IF NOT EXISTS system_alerts (
    alert_id    INT AUTO_INCREMENT PRIMARY KEY,
    tank_id     INT          NULL,
    user_id     INT          NULL,
    alert_type  VARCHAR(100) NOT NULL  COMMENT 'e.g. low_water, ph_critical, sensor_anomaly',
    message     TEXT         NOT NULL,
    severity    ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    is_resolved TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP    NULL,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)     ON DELETE SET NULL,
    INDEX idx_sa_resolved (is_resolved, created_at DESC)
) ENGINE=InnoDB;

-- ── 6. Sensors (depends on tank + users) ──────────────────────────────────────
--       Includes all IoT columns from the start; tank_id is nullable
--       so sensors can exist in an unassigned pool.

CREATE TABLE IF NOT EXISTS sensors (
    sensor_id        INT AUTO_INCREMENT PRIMARY KEY,
    tank_id          INT           NULL                    COMMENT 'NULL = unassigned/available pool',
    sensor_type      VARCHAR(255)  NOT NULL,
    model            VARCHAR(255)  NOT NULL,
    unit             VARCHAR(255)  NOT NULL,
    is_active        VARCHAR(255)  NOT NULL,
    -- IoT registration fields
    serial_port      VARCHAR(20)   NULL                    COMMENT 'COM3, /dev/ttyUSB0 etc.',
    baud_rate        INT           NOT NULL DEFAULT 9600,
    api_key          VARCHAR(64)   NULL                    COMMENT 'Unique key for this sensor bridge',
    sensor_status    ENUM('available','assigned','offline','error')
                                   NOT NULL DEFAULT 'available',
    last_reading_at  TIMESTAMP     NULL,
    tank_height_cm   DECIMAL(6,2)  NULL                    COMMENT 'Physical height of tank in cm',
    mount_offset_cm  DECIMAL(6,2)  NULL                    COMMENT 'Sensor distance from tank rim to water surface max',
    ip_address       VARCHAR(45)   NULL                    COMMENT 'IP of the bridge machine',
    registered_by    INT           NULL,
    registered_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    notes            TEXT          NULL,
    FOREIGN KEY (tank_id)      REFERENCES tank(tank_id)  ON DELETE CASCADE,
    FOREIGN KEY (registered_by) REFERENCES users(id)     ON DELETE SET NULL,
    INDEX idx_sensors_status (sensor_status),
    INDEX idx_sensors_tank   (tank_id)
) ENGINE=InnoDB;

-- ── 7. Sensor Readings / Anomaly Log (depends on sensors + users) ─────────────

CREATE TABLE IF NOT EXISTS sensor_readings (
    reading_id  INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id   INT          NOT NULL,
    user_id     INT          NULL,
    anomaly     VARCHAR(255) NOT NULL,
    recorded_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── 8. Water Level Readings — IoT high-frequency stream ───────────────────────
--       Kept separate from sensor_readings to avoid bloating the anomaly log.

CREATE TABLE IF NOT EXISTS water_level_readings (
    reading_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    sensor_id    INT            NOT NULL,
    tank_id      INT            NOT NULL,
    pct          DECIMAL(5,2)   NOT NULL  COMMENT 'Fill percentage 0-100',
    height_cm    DECIMAL(6,2)   NOT NULL  COMMENT 'Water height in cm',
    dist_cm      DECIMAL(6,2)   NULL      COMMENT 'Raw ultrasonic distance',
    volume_l     DECIMAL(10,2)  NOT NULL  COMMENT 'Calculated volume in litres',
    capacity_l   DECIMAL(10,2)  NOT NULL  COMMENT 'Tank max capacity at time of reading',
    status       VARCHAR(16)    NOT NULL  COMMENT 'NORMAL / LOW / CRITICAL / FULL / OVERFLOW',
    alert        VARCHAR(16)    NOT NULL  COMMENT 'NONE / LOW / CRITICAL / OVERFLOW',
    raw_adc      INT            NULL,
    uptime_ms    BIGINT         NULL,
    recorded_at  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    FOREIGN KEY (tank_id)   REFERENCES tank(tank_id)      ON DELETE CASCADE,
    INDEX idx_wlr_sensor_time (sensor_id, recorded_at DESC),
    INDEX idx_wlr_tank_time   (tank_id,   recorded_at DESC),
    INDEX idx_wlr_recorded    (recorded_at DESC)
) ENGINE=InnoDB;

-- ── 9. Sensor Assignment Log (audit trail for link/unlink events) ──────────────

CREATE TABLE IF NOT EXISTS sensor_assignments (
    assignment_id  INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id      INT       NOT NULL,
    tank_id        INT       NULL,
    assigned_by    INT       NULL,
    action         ENUM('assigned','unassigned') NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id)   REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    FOREIGN KEY (tank_id)     REFERENCES tank(tank_id)      ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── 10. Water Usage (depends on tank + users) ─────────────────────────────────

CREATE TABLE IF NOT EXISTS water_usage (
    usage_id     INT AUTO_INCREMENT PRIMARY KEY,
    tank_id      INT           NOT NULL,
    user_id      INT           NULL,
    usage_liters DECIMAL(10,2) NOT NULL,
    usage_type   VARCHAR(255)  NOT NULL,
    recorded_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── 11. Water Quality (depends on tank + users) ───────────────────────────────

CREATE TABLE IF NOT EXISTS water_quality (
    quality_id     INT AUTO_INCREMENT PRIMARY KEY,
    tank_id        INT           NOT NULL,
    user_id        INT           NULL,
    turbidity      DECIMAL(10,2) NOT NULL,
    ph_level       DECIMAL(4,2)  NOT NULL,
    quality_status VARCHAR(255)  NOT NULL,
    recorded_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── 12. System Settings ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         NOT NULL
) ENGINE=InnoDB;

-- ============================================================
--  SEED DATA  (order mirrors FK dependencies)
-- ============================================================

-- ── Users ─────────────────────────────────────────────────────────────────────
INSERT INTO users (username, email, password, role, is_verified) VALUES
    ('admin',   'admin@example.com',   '$2y$10$8dYBmLeZ0ckr.5nhTPP2Iu5.1P8YFXKakLzhIzVK1MoPRucUiBZ3i', 'admin',   1),
    ('manager', 'manager@example.com', '$2y$10$iTXAcFnPjEI1ZGPVKuRAzuo9QnBZUDNu/lx92rtME04RZuoYiVtou', 'manager', 1),
    ('user',    'user@example.com',    '$2y$10$z05il1DQeZEve1Dd895DT.ocFc4fHKRRnSgvEN.b2HHDBdjDywB6m', 'user',    1);

-- ── Activity Logs ─────────────────────────────────────────────────────────────
INSERT INTO user_activity_logs (user_id, role, email, action, status) VALUES
    (1, 'admin',   'admin@example.com',   'register', 'success'),
    (2, 'manager', 'manager@example.com', 'register', 'success'),
    (3, 'user',    'user@example.com',    'register', 'success');

-- ── Tanks ─────────────────────────────────────────────────────────────────────
INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank) VALUES
    ('Agusan Tank', 'Agusan Canyon Manolo Fortich', 1200, 5000, 'Active'),
    ('Libona Tank', 'Poblacion Libona',             1200, 5000, 'Active'),
    ('Alae Tank',   'Alae Manolo Fortich',          1200, 5000, 'Active');

-- ── Sensors ───────────────────────────────────────────────────────────────────
INSERT INTO sensors (tank_id, sensor_type, model, unit, is_active) VALUES
    (1, 'Water Level', 'Model 1', 'L', 'Active');

-- Demo IoT sensor in the unassigned pool (only if no other sensors exist)
INSERT INTO sensors (tank_id, sensor_type, model, unit, is_active,
                     serial_port, baud_rate, sensor_status, tank_height_cm,
                     mount_offset_cm, api_key, notes)
SELECT NULL, 'Water Level', 'HC-SR04 Ultrasonic', 'L', 'Active',
       'COM3', 9600, 'available', 100.00, 5.00,
       'demo_sensor_key_001',
       'Demo ultrasonic sensor — assign to a tank to activate'
WHERE (SELECT COUNT(*) FROM sensors WHERE api_key = 'demo_sensor_key_001') = 0;

-- ── Sensor Reading ────────────────────────────────────────────────────────────
INSERT INTO sensor_readings (sensor_id, user_id, anomaly) VALUES
    (1, 1, 'None');

-- ── Water Usage ───────────────────────────────────────────────────────────────
INSERT INTO water_usage (tank_id, user_id, usage_liters, usage_type) VALUES
    (1, 1, 200.00, 'Cleaning');

-- ── Water Quality ─────────────────────────────────────────────────────────────
INSERT INTO water_quality (tank_id, user_id, turbidity, ph_level, quality_status) VALUES
    (1, 1, 2.50, 7.20, 'Good');

-- ── System Settings (IoT defaults) ───────────────────────────────────────────
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('iot_api_key',           'ecorain_iot_secret_2025'),
    ('iot_poll_interval_s',   '10'),
    ('iot_low_threshold_pct', '20'),
    ('iot_critical_pct',      '10'),
    ('iot_full_pct',          '95');

-- ============================================================
--  END OF MIGRATION
-- ============================================================
SELECT 'EcoRain full migration complete.' AS result;