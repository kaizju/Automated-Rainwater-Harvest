<?php
// others/data.php — serves live sensor/tank data as JSON
require_once '../connections/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$action = $_GET['action'] ?? 'tank_levels';

switch ($action) {

    // Live water levels for all tanks
    case 'tank_levels':
        $rows = $pdo->query("
            SELECT t.tank_id, t.tankname, t.current_liters, t.max_capacity,
                   t.status_tank,
                   ROUND((t.current_liters / t.max_capacity) * 100, 1) AS pct,
                   (SELECT wlr.pct FROM water_level_readings wlr
                    WHERE wlr.tank_id = t.tank_id
                    ORDER BY wlr.reading_id DESC LIMIT 1) AS sensor_pct,
                   (SELECT wlr.recorded_at FROM water_level_readings wlr
                    WHERE wlr.tank_id = t.tank_id
                    ORDER BY wlr.reading_id DESC LIMIT 1) AS last_reading
            FROM tank t ORDER BY t.tankname
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'tanks' => $rows]);
        break;

    // Latest reading for a specific sensor
    case 'sensor_reading':
        $sensorId = (int)($_GET['sensor_id'] ?? 0);
        $row = $pdo->prepare("
            SELECT pct, liters, distance_cm, recorded_at
            FROM water_level_readings
            WHERE sensor_id = ?
            ORDER BY reading_id DESC LIMIT 1
        ");
        $row->execute([$sensorId]);
        $reading = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'reading' => $reading]);
        break;

    // Chart data — last 20 readings for a tank
    case 'chart':
        $tankId = (int)($_GET['tank_id'] ?? 0);
        $rows = $pdo->prepare("
            SELECT pct, liters, recorded_at
            FROM water_level_readings
            WHERE tank_id = ?
            ORDER BY reading_id DESC LIMIT 20
        ");
        $rows->execute([$tankId]);
        $readings = array_reverse($rows->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['status' => 'ok', 'readings' => $readings]);
        break;

    // Summary stats for dashboard widgets
    case 'summary':
        $tanks    = $pdo->query("SELECT COUNT(*) FROM tank")->fetchColumn();
        $online   = $pdo->query("SELECT COUNT(*) FROM tank WHERE LOWER(status_tank)='active'")->fetchColumn();
        $sensors  = $pdo->query("SELECT COUNT(*) FROM sensors WHERE sensor_status='assigned'")->fetchColumn();
        $lastRead = $pdo->query("
            SELECT recorded_at FROM water_level_readings
            ORDER BY reading_id DESC LIMIT 1
        ")->fetchColumn();
        $secondsAgo = $lastRead ? (time() - strtotime($lastRead)) : null;
        echo json_encode([
            'status'       => 'ok',
            'tank_count'   => (int)$tanks,
            'online_count' => (int)$online,
            'sensor_count' => (int)$sensors,
            'last_reading' => $lastRead,
            'seconds_ago'  => $secondsAgo,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}