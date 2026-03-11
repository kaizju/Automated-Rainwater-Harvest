<?php
require_once __DIR__ . '/../Connections/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET — fetch tank data
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM tank WHERE tank_id = 1");
    $stmt->execute();
    $tank = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tank) {
        echo json_encode(["error" => "No tank found"]);
        exit;
    }

    $pct = round(($tank['current_liters'] / $tank['max_capacity']) * 100, 1);

    echo json_encode([
        "tankname"             => $tank['tankname'],
        "location_add"         => $tank['location_add'],
        "max_capacity"         => (float)$tank['max_capacity'],
        "current"              => (float)$tank['current_liters'],
        "percent_full"         => $pct,
        "percent_available"    => round(100 - $pct, 1),
        "status_tank"          => $tank['status_tank']
    ]);
}


if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['current_liters'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing current_liters"]);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE tank SET current_liters = ? WHERE tank_id = 1");
    $stmt->execute([$data['current_liters']]);
    echo json_encode(["success" => true]);
}
