
CREATE DATABASE IF NOT EXISTS automated_rainwater
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE automated_rainwater;

CREATE TABLE IF NOT EXISTS users (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    email                     VARCHAR(255) UNIQUE NOT NULL,
    password                  VARCHAR(255)        NOT NULL,
    role                      ENUM('admin','user') DEFAULT 'user',
    is_verified               TINYINT(1)          DEFAULT 0,
    verification_token        VARCHAR(64)         NULL,
    email_verification_expires DATETIME           NULL,
    created_at                TIMESTAMP           DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP           DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS user_activity_logs (
    activity_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NULL,                     
    email        VARCHAR(255) NULL,
    action       VARCHAR(50)  NOT NULL,
    status       ENUM('success','failed') NOT NULL DEFAULT 'success',
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tank (
    tank_id        INT AUTO_INCREMENT PRIMARY KEY,
    tankname       VARCHAR(255) UNIQUE NOT NULL,
    location_add   VARCHAR(255)        NOT NULL,
    current_liters INT                 NOT NULL DEFAULT 0,
    max_capacity   INT                 NOT NULL DEFAULT 5000,
    status_tank    VARCHAR(255)        NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sensors (
    sensor_id   INT AUTO_INCREMENT PRIMARY KEY,
    tank_id     INT          NOT NULL,
    sensor_type VARCHAR(255) NOT NULL,
    model       VARCHAR(255) NOT NULL,
    unit        VARCHAR(255) NOT NULL,
    is_active   VARCHAR(255) NOT NULL,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sensor_readings (
    reading_id  INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id   INT          NOT NULL,
    user_id     INT          NULL,
    anomaly     VARCHAR(255) NOT NULL,
    recorded_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS water_usage (
    usage_id     INT AUTO_INCREMENT PRIMARY KEY,
    tank_id      INT             NOT NULL,
    user_id      INT             NULL,
    usage_liters DECIMAL(10,2)   NOT NULL,
    usage_type   VARCHAR(255)    NOT NULL,
    recorded_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

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