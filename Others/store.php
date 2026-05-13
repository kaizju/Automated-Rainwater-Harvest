<?php
// others/store.php — receives POST from serial_bridge.py
require_once '../connections/config.php';

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// Fallback to POST fields
if (!$data) $data = $_POST;

$apiKey      = trim($data['api_key']      ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
$distanceCm  = isset($data['distance_cm'])  ? (float)$data['distance_cm']  : null;
$pct         = isset($data['pct'])          ? (float)$data['pct']          : null;
$liters      = isset($data['liters'])       ? (float)$data['liters']       : null;

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing API key']);
    exit;
}

// Validate API key → get sensor
$stmt = $pdo->prepare("
    SELECT s.sensor_id, s.tank_id, s.tank_height_cm, s.mount_offset_cm
    FROM sensors s
    WHERE s.api_key = ? AND s.is_active = 'Active' AND s.sensor_status = 'assigned'
    LIMIT 1
");
$stmt->execute([$apiKey]);
$sensor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sensor) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or unassigned sensor']);
    exit;
}

$sensorId = $sensor['sensor_id'];
$tankId   = $sensor['tank_id'];
$height   = (float)$sensor['tank_height_cm'];
$offset   = (float)$sensor['mount_offset_cm'];

// Calculate pct from distance if not provided
if ($pct === null && $distanceCm !== null) {
    $waterDepth = max(0, $height - ($distanceCm - $offset));
    $pct        = $height > 0 ? round(($waterDepth / $height) * 100, 2) : 0;
    $pct        = min(100, max(0, $pct));
}

if ($pct === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing distance_cm or pct']);
    exit;
}

// Calculate liters from pct if not provided
if ($liters === null && $tankId) {
    $tankStmt = $pdo->prepare("SELECT max_capacity FROM tank WHERE tank_id = ?");
    $tankStmt->execute([$tankId]);
    $tankRow = $tankStmt->fetch(PDO::FETCH_ASSOC);
    if ($tankRow) {
        $liters = round(($pct / 100) * $tankRow['max_capacity'], 2);
    }
}

// Detect anomaly
$anomaly = 'None';
if ($pct <= 5)  $anomaly = 'Critical Low';
if ($pct >= 98) $anomaly = 'Overflow Risk';
if ($distanceCm !== null && $distanceCm > ($height + $offset + 10)) $anomaly = 'Sensor Error';

// Save to water_level_readings
$pdo->prepare("
    INSERT INTO water_level_readings
        (sensor_id, tank_id, distance_cm, pct, liters, recorded_at)
    VALUES (?, ?, ?, ?, ?, NOW())
")->execute([$sensorId, $tankId, $distanceCm, $pct, $liters]);

// Save to sensor_readings (for anomaly tracking on dashboard)
$pdo->prepare("
    INSERT INTO sensor_readings (sensor_id, anomaly, recorded_at)
    VALUES (?, ?, NOW())
")->execute([$sensorId, $anomaly]);

// Update tank's current_liters in real time
if ($tankId && $liters !== null) {
    $pdo->prepare("UPDATE tank SET current_liters = ? WHERE tank_id = ?")
        ->execute([$liters, $tankId]);
}

echo json_encode([
    'status'  => 'ok',
    'sensor'  => $sensorId,
    'tank_id' => $tankId,
    'pct'     => $pct,
    'liters'  => $liters,
    'anomaly' => $anomaly,
]);