-- ============================================================
-- seed.sql  –  run AFTER schema.sql
-- ============================================================

USE automated_rainwater;

START TRANSACTION;

-- ------------------------------------------------------------
-- Users  (password = 'password123' for both)
-- ------------------------------------------------------------
INSERT INTO users (email, password, role, is_verified) VALUES
    ('admin@example.com', '$2y$10$8dYBmLeZ0ckr.5nhTPP2Iu5.1P8YFXKakLzhIzVK1MoPRucUiBZ3i', 'admin', 1),
    ('user@example.com',  '$2y$10$z05il1DQeZEve1Dd895DT.ocFc4fHKRRnSgvEN.b2HHDBdjDywB6m', 'user',  1);

-- ------------------------------------------------------------
-- Activity log for both seed users
-- ------------------------------------------------------------
INSERT INTO user_activity_logs (user_id, email, action, status) VALUES
    (1, 'admin@example.com', 'register', 'success'),
    (2, 'user@example.com',  'register', 'success');

-- ------------------------------------------------------------
-- Tank
-- ------------------------------------------------------------
INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank)
VALUES ('Tank 1', 'Jude Crib', 1200, 5000, 'Active');

-- ------------------------------------------------------------
-- Sensor  (tank_id = 1)
-- ------------------------------------------------------------
INSERT INTO sensors (tank_id, sensor_type, model, unit, is_active)
VALUES (1, 'Water Level', 'Model 1', 'L', 'Active');

-- ------------------------------------------------------------
-- Sensor reading  (sensor_id = 1, user_id = 1)
-- ------------------------------------------------------------
INSERT INTO sensor_readings (sensor_id, user_id, anomaly)
VALUES (1, 1, 'None');

-- ------------------------------------------------------------
-- Water usage  (tank_id = 1, user_id = 1)
-- ------------------------------------------------------------
INSERT INTO water_usage (tank_id, user_id, usage_liters, usage_type)
VALUES (1, 1, 200.00, 'Cleaning');

-- ------------------------------------------------------------
-- Water quality  (tank_id = 1, user_id = 1)
-- ------------------------------------------------------------
INSERT INTO water_quality (tank_id, user_id, turbidity, ph_level, quality_status)
VALUES (1, 1, 2.50, 7.20, 'Good');

COMMIT;