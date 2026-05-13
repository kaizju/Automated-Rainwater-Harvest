<?php
// others/store.php — receives POST from serial_bridge.py
require_once '../connections/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Accept JSON body or form POST
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = $_POST;

$apiKey   = trim($data['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
$pct      = isset($data['pct'])      ? (float)$data['pct']      : null;
$volumeL  = isset($data['liters'])   ? (float)$data['liters']   :
           (isset($data['volume_l']) ? (float)$data['volume_l'] : null);
$status   = $data['status']   ?? 'NORMAL';
$alert    = $data['alert']    ?? 'none';
$rawAdc   = isset($data['raw_adc'])   ? (int)$data['raw_adc']   : null;
$uptimeMs = isset($data['uptime_ms']) ? (int)$data['uptime_ms'] : null;
$distCm   = isset($data['dist_cm'])   ? (float)$data['dist_cm'] : null;
$heightCm = isset($data['height_cm']) ? (float)$data['height_cm'] : 0;

// Validate API key
if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing API key']);
    exit;
}

// Look up sensor by API key
$stmt = $pdo->prepare("
    SELECT s.sensor_id, s.tank_id, s.tank_height_cm, s.mount_offset_cm
    FROM sensors s
    WHERE s.api_key = ?
      AND s.is_active = 'Active'
      AND s.sensor_status = 'assigned'
    LIMIT 1
");
$stmt->execute([$apiKey]);
$sensor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sensor) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or unassigned sensor key: ' . $apiKey]);
    exit;
}

$sensorId = (int)$sensor['sensor_id'];
$tankId   = (int)$sensor['tank_id'];

if ($pct === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing pct value']);
    exit;
}

$pct = min(100, max(0, $pct));

// Get tank capacity
$tankRow = $pdo->prepare("SELECT max_capacity FROM tank WHERE tank_id = ?");
$tankRow->execute([$tankId]);
$tank = $tankRow->fetch(PDO::FETCH_ASSOC);
$capacityL = $tank ? (float)$tank['max_capacity'] : 0;

// Calculate volume if not provided
if ($volumeL === null) {
    $volumeL = round(($pct / 100) * $capacityL, 2);
}

// Normalize alert casing
$alert  = strtolower($alert);
$status = strtoupper($status);

// Save to water_level_readings
$pdo->prepare("
    INSERT INTO water_level_readings
        (sensor_id, tank_id, pct, height_cm, dist_cm,
         volume_l, capacity_l, status, alert,
         raw_adc, uptime_ms, recorded_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
")->execute([
    $sensorId, $tankId, $pct, $heightCm, $distCm,
    $volumeL, $capacityL, $status, $alert,
    $rawAdc, $uptimeMs
]);

// Save anomaly to sensor_readings
$anomaly = 'None';
if ($pct <= 5)  $anomaly = 'Critical Low';
if ($pct >= 98) $anomaly = 'Overflow Risk';
if ($alert === 'danger')  $anomaly = 'Critical Low';
if ($alert === 'warning') $anomaly = 'Low Water';

$pdo->prepare("
    INSERT INTO sensor_readings (sensor_id, anomaly, recorded_at)
    VALUES (?, ?, NOW())
")->execute([$sensorId, $anomaly]);

// Update tank current_liters in real time
$pdo->prepare("
    UPDATE tank SET current_liters = ? WHERE tank_id = ?
")->execute([round($volumeL), $tankId]);

// Update sensor last_reading_at
$pdo->prepare("
    UPDATE sensors SET last_reading_at = NOW() WHERE sensor_id = ?
")->execute([$sensorId]);

echo json_encode([
    'status'    => 'ok',
    'sensor_id' => $sensorId,
    'tank_id'   => $tankId,
    'pct'       => $pct,
    'volume_l'  => $volumeL,
    'capacity_l'=> $capacityL,
    'anomaly'   => $anomaly,
]);