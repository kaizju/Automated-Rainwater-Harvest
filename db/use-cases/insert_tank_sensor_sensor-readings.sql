INSERT INTO users (username,email, password, role, is_verified) VALUES
    ('admin','admin@example.com', '$2y$10$8dYBmLeZ0ckr.5nhTPP2Iu5.1P8YFXKakLzhIzVK1MoPRucUiBZ3i', 'admin', 1),
     ('manager','manager@example.com', '$2y$10$iTXAcFnPjEI1ZGPVKuRAzuo9QnBZUDNu/lx92rtME04RZuoYiVtou', 'manager', 1),
    ('user','user@example.com',  '$2y$10$z05il1DQeZEve1Dd895DT.ocFc4fHKRRnSgvEN.b2HHDBdjDywB6m', 'user',  1);

-- Step 5: Activity log (now user_id 1 and 2 exist)
INSERT INTO user_activity_logs (user_id,role, email, action, status) VALUES
    (1,'Admin', 'admin@example.com', 'register', 'success'),
    (2,'Admin', 'manager@example.com', 'register', 'success'),
    (3,'User', 'user@example.com',  'register', 'success');

-- Step 6: Tank
INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank)
VALUES ('Tank 1', 'Jude Crib', 1200, 5000, 'Active');

-- Step 7: Sensor (tank_id = 1 now exists)
INSERT INTO sensors (tank_id, sensor_type, model, unit, is_active)
VALUES (1, 'Water Level', 'Model 1', 'L', 'Active');

-- Step 8: Sensor reading
INSERT INTO sensor_readings (sensor_id, user_id, anomaly)
VALUES (1, 1, 'None');

-- Step 9: Water usage
INSERT INTO water_usage (tank_id, user_id, usage_liters, usage_type)
VALUES (1, 1, 200.00, 'Cleaning');

-- Step 10: Water quality
INSERT INTO water_quality (tank_id, user_id, turbidity, ph_level, quality_status)
VALUES (1, 1, 2.50, 7.20, 'Good');