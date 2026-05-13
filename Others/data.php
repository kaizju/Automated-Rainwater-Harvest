<?php
// others/data.php — serves live JSON to dashboards
require_once '../connections/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$action = $_GET['action'] ?? 'tank_levels';

switch ($action) {

    case 'tank_levels':
        $rows = $pdo->query("
            SELECT
                t.tank_id,
                t.tankname,
                t.current_liters,
                t.max_capacity,
                t.status_tank,
                ROUND((t.current_liters / NULLIF(t.max_capacity,0)) * 100, 1) AS pct,
                (SELECT wlr.pct
                 FROM water_level_readings wlr
                 WHERE wlr.tank_id = t.tank_id
                 ORDER BY wlr.recorded_at DESC LIMIT 1) AS sensor_pct,
                (SELECT wlr.volume_l
                 FROM water_level_readings wlr
                 WHERE wlr.tank_id = t.tank_id
                 ORDER BY wlr.recorded_at DESC LIMIT 1) AS sensor_liters,
                (SELECT wlr.status
                 FROM water_level_readings wlr
                 WHERE wlr.tank_id = t.tank_id
                 ORDER BY wlr.recorded_at DESC LIMIT 1) AS sensor_status,
                (SELECT wlr.recorded_at
                 FROM water_level_readings wlr
                 WHERE wlr.tank_id = t.tank_id
                 ORDER BY wlr.recorded_at DESC LIMIT 1) AS last_reading
            FROM tank t
            ORDER BY t.tankname
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'tanks' => $rows]);
        break;

    case 'chart':
        $tankId = (int)($_GET['tank_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT pct, volume_l, recorded_at
            FROM water_level_readings
            WHERE tank_id = ?
            ORDER BY recorded_at DESC LIMIT 20
        ");
        $stmt->execute([$tankId]);
        $readings = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['status' => 'ok', 'readings' => $readings]);
        break;

    case 'summary':
        $tanks   = $pdo->query("SELECT COUNT(*) FROM tank")->fetchColumn();
        $online  = $pdo->query("SELECT COUNT(*) FROM tank WHERE LOWER(status_tank)='active'")->fetchColumn();
        $sensors = $pdo->query("SELECT COUNT(*) FROM sensors WHERE sensor_status='assigned'")->fetchColumn();
        $last    = $pdo->query("
            SELECT recorded_at FROM water_level_readings
            ORDER BY recorded_at DESC LIMIT 1
        ")->fetchColumn();
        echo json_encode([
            'status'       => 'ok',
            'tank_count'   => (int)$tanks,
            'online_count' => (int)$online,
            'sensor_count' => (int)$sensors,
            'last_reading' => $last,
            'seconds_ago'  => $last ? (time() - strtotime($last)) : null,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}