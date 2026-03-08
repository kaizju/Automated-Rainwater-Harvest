START TRANSACTION;

CREATE DATABASE rainwater;
USE rainwater;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_activity_logs (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)  -- ✅ Fixed: "user" → "users"
) ENGINE=InnoDB;

CREATE TABLE tank (
    tank_id INT AUTO_INCREMENT PRIMARY KEY,
    tankname VARCHAR(255) UNIQUE NOT NULL,
    location_add VARCHAR(255) NOT NULL,
    capacity VARCHAR(255) NOT NULL,
    status_tank VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE sensors (
    sensor_id INT AUTO_INCREMENT PRIMARY KEY,
    tank_id INT,
    sensor_type VARCHAR(255) NOT NULL,
    model VARCHAR(255) NOT NULL,
    unit VARCHAR(255) NOT NULL,
    is_active VARCHAR(255) NOT NULL,
    FOREIGN KEY (tank_id) REFERENCES tank(tank_id)
) ENGINE=InnoDB;

CREATE TABLE sensor_readings (
    reading_id INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id INT,
    user_id INT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    anomaly VARCHAR(255) NOT NULL,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id),  
    FOREIGN KEY (user_id) REFERENCES users(user_id)         
) ENGINE=InnoDB;

COMMIT;