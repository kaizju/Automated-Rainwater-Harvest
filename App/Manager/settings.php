<?php
require_once '../../connections/config.php';
require_once '../../connections/functions.php';

requireAnyRole(['admin', 'manager']);
logPageVisit('Manager Settings', 'Settings');

$activePage = 'Settings';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'manager') {
  header('Location: ../../index.php');
  exit;
}
/* ── Handle: Register Sensor POST ────────────────────────────────────── */
$sensorSuccess = '';
$sensorError   = '';

// Fetch available sensors for the wizard
$availableSensors = $pdo->query("
    SELECT s.sensor_id, s.model, s.serial_port, s.sensor_status,
           s.tank_height_cm, t.tankname AS current_tank, t.location_add AS current_location
    FROM sensors s
    LEFT JOIN tank t ON s.tank_id = t.tank_id
    WHERE s.is_active = 'Active'
    ORDER BY s.sensor_status ASC, s.sensor_id ASC
")->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_sensor'])) {
  try {
    $serialPort   = trim($_POST['serial_port']       ?? '');
    $baudRate     = (int)($_POST['baud_rate']        ?? 9600);
    $tankHeightCm = (float)($_POST['tank_height_cm'] ?? 0);
    $mountOffset  = (float)($_POST['mount_offset_cm'] ?? 5);
    $sensorType   = trim($_POST['sensor_type']       ?? 'Water Level');
    $model        = trim($_POST['model']             ?? 'HC-SR04 Ultrasonic');
    $assignTankId = !empty($_POST['assign_tank_id']) ? (int)$_POST['assign_tank_id'] : null;
    $notes        = trim($_POST['notes']             ?? '');

    if (!$serialPort)      throw new Exception('Serial port is required.');
    if ($tankHeightCm <= 0) throw new Exception('Tank height must be greater than 0.');

    // Check duplicate port
    $portCheck = $pdo->prepare("SELECT sensor_id FROM sensors 
                                    WHERE serial_port = ? AND sensor_status != 'offline' LIMIT 1");
    $portCheck->execute([$serialPort]);
    if ($portCheck->fetch()) {
      throw new Exception("Serial port \"$serialPort\" is already registered.");
    }

    $apiKey       = 'ecr_' . bin2hex(random_bytes(16));
    $sensorStatus = $assignTankId ? 'assigned' : 'available';

    $pdo->prepare("
            INSERT INTO sensors
                (tank_id, sensor_type, model, unit, is_active,
                 serial_port, baud_rate, api_key, sensor_status,
                 tank_height_cm, mount_offset_cm, notes, registered_by, registered_at)
            VALUES
                (:tid, :type, :model, 'L', 'Active',
                 :port, :baud, :key, :status,
                 :height, :offset, :notes, :uid, NOW())
        ")->execute([
      ':tid'    => $assignTankId,
      ':type'   => $sensorType,
      ':model'  => $model,
      ':port'   => $serialPort,
      ':baud'   => $baudRate,
      ':key'    => $apiKey,
      ':status' => $sensorStatus,
      ':height' => $tankHeightCm,
      ':offset' => $mountOffset,
      ':notes'  => $notes,
      ':uid'    => $_SESSION['user_id'],
    ]);
    $newSensorId = (int)$pdo->lastInsertId();

    if ($assignTankId) {
      $pdo->prepare("
                INSERT INTO sensor_assignments (sensor_id, tank_id, assigned_by, action)
                VALUES (?, ?, ?, 'assigned')
            ")->execute([$newSensorId, $assignTankId, $_SESSION['user_id']]);
    }

    $sensorSuccess = "Sensor registered! API Key: <code style='background:#1e293b;color:#93c5fd;
                          padding:2px 8px;border-radius:5px;font-size:.82em'>{$apiKey}</code><br>
                          Copy this key into <strong>serial_bridge.py</strong>.";
  } catch (Exception $e) {
    $sensorError = $e->getMessage();
  }
}

// Fetch tanks for the sensor modal dropdown
$allTanksForSensor = $pdo->query("
    SELECT tank_id, tankname, location_add,
           (SELECT COUNT(*) FROM sensors 
            WHERE tank_id = tank.tank_id AND sensor_status = 'assigned') AS has_sensor
    FROM tank ORDER BY tankname
")->fetchAll(PDO::FETCH_ASSOC);
$success = '';
$error   = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$tank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$rows     = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

function cfg(array $rows, string $key, $default)
{
  return $rows[$key] ?? $default;
}

/* ── Handle Delete Tank POST ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tank'])) {
  try {
    $tankId = (int)($_POST['tank_id'] ?? 0);
    if ($tankId <= 0) throw new Exception('Invalid tank ID.');

    $stmt = $pdo->prepare("DELETE FROM tank WHERE tank_id = ?");
    $stmt->execute([$tankId]);

    if ($stmt->rowCount() === 0) throw new Exception('Tank not found or already deleted.');

    $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address)
                       VALUES (?, ?, ?, 'delete_tank', 'success', ?)")
      ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'manager', $_SERVER['REMOTE_ADDR'] ?? '']);

    $success  = 'Tank deleted successfully.';
    $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

/* ── Handle Add Tank POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tank'])) {
    try {
        $tankname      = trim($_POST['tankname']        ?? '');
        $location_add  = trim($_POST['location_add']    ?? '');
        $currentLiters = (int)($_POST['current_liters'] ?? 0);
        $maxCapacity   = (int)($_POST['max_capacity']   ?? 0);
        $statusTank    = trim($_POST['status_tank']     ?? 'Active');
        $assignSensor  = !empty($_POST['assign_sensor_id']) ? (int)$_POST['assign_sensor_id'] : null;

        if ($tankname     === '') throw new Exception('Tank name is required.');
        if ($location_add === '') throw new Exception('Location is required.');
        if ($maxCapacity  <= 0)   throw new Exception('Max capacity must be greater than 0.');

        $pdo->prepare("INSERT INTO tank (tankname, location_add, current_liters, max_capacity, status_tank)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$tankname, $location_add, $currentLiters, $maxCapacity, $statusTank]);

        $newTankId = (int)$pdo->lastInsertId();

        // Assign sensor if selected
        if ($assignSensor) {
            // Unassign sensor from old tank first
            $pdo->prepare("UPDATE sensors SET tank_id = NULL, sensor_status = 'available' WHERE sensor_id = ?")
                ->execute([$assignSensor]);
            // Assign to new tank
            $pdo->prepare("UPDATE sensors SET tank_id = ?, sensor_status = 'assigned' WHERE sensor_id = ?")
                ->execute([$newTankId, $assignSensor]);
            $pdo->prepare("INSERT INTO sensor_assignments (sensor_id, tank_id, assigned_by, action) VALUES (?, ?, ?, 'assigned')")
                ->execute([$assignSensor, $newTankId, $_SESSION['user_id']]);
        }

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?, ?, ?, 'add_tank', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'], $_SERVER['REMOTE_ADDR'] ?? '']);

        $success  = 'Tank added successfully.';
        $tank     = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* ── Handle Edit Tank POST ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tank'])) {
    try {
        $tankId        = (int)($_POST['tank_id']        ?? 0);
        $tankname      = trim($_POST['tankname']        ?? '');
        $location_add  = trim($_POST['location_add']    ?? '');
        $currentLiters = (int)($_POST['current_liters'] ?? 0);
        $maxCapacity   = (int)($_POST['max_capacity']   ?? 0);
        $statusTank    = trim($_POST['status_tank']     ?? 'Active');
        $assignSensor  = !empty($_POST['assign_sensor_id']) ? (int)$_POST['assign_sensor_id'] : null;

        if ($tankId <= 0)         throw new Exception('Invalid tank ID.');
        if ($tankname === '')     throw new Exception('Tank name is required.');
        if ($location_add === '') throw new Exception('Location is required.');
        if ($maxCapacity <= 0)    throw new Exception('Max capacity must be greater than 0.');

        $pdo->prepare("UPDATE tank SET tankname=?, location_add=?, current_liters=?, max_capacity=?, status_tank=? WHERE tank_id=?")
            ->execute([$tankname, $location_add, $currentLiters, $maxCapacity, $statusTank, $tankId]);

        // Reassign sensor
        if ($assignSensor) {
            // Free old sensor on this tank
            $pdo->prepare("UPDATE sensors SET tank_id=NULL, sensor_status='available' WHERE tank_id=? AND sensor_id != ?")
                ->execute([$tankId, $assignSensor]);
            // Free this sensor from any other tank
            $pdo->prepare("UPDATE sensors SET tank_id=NULL, sensor_status='available' WHERE sensor_id=?")
                ->execute([$assignSensor]);
            // Assign
            $pdo->prepare("UPDATE sensors SET tank_id=?, sensor_status='assigned' WHERE sensor_id=?")
                ->execute([$tankId, $assignSensor]);
            $pdo->prepare("INSERT INTO sensor_assignments (sensor_id, tank_id, assigned_by, action) VALUES (?, ?, ?, 'assigned')")
                ->execute([$assignSensor, $tankId, $_SESSION['user_id']]);
        }

        $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address) VALUES (?, ?, ?, 'edit_tank', 'success', ?)")
            ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'], $_SERVER['REMOTE_ADDR'] ?? '']);

        $success  = 'Tank updated successfully.';
        $allTanks = $pdo->query("SELECT tank_id, tankname, location_add, status_tank FROM tank ORDER BY tank_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
/* ── Handle Settings POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_tank']) && !isset($_POST['delete_tank'])) {
  try {
    $capacity  = (int)($_POST['tank_capacity'] ?? 5000);
    $threshold = (int)($_POST['threshold']     ?? 1000);

    if ($tank) {
      $pdo->prepare("UPDATE tank SET max_capacity = ? WHERE tank_id = ?")
        ->execute([$capacity, $tank['tank_id']]);
    }

    $settings = [
      'tank_capacity'       => $capacity,
      'threshold'           => $threshold,
      'overflow_prevention' => isset($_POST['overflow_prevention']) ? '1' : '0',
      'pump_auto'           => isset($_POST['pump_auto'])           ? '1' : '0',
      'pump_schedule'       => $_POST['pump_schedule']              ?? 'smart',
      'pump_wattage'        => (int)($_POST['pump_wattage']         ?? 100),
      'notif_low_water'     => isset($_POST['notif_low_water'])     ? '1' : '0',
      'notif_heavy_rain'    => isset($_POST['notif_heavy_rain'])    ? '1' : '0',
      'notif_pump_failure'  => isset($_POST['notif_pump_failure'])  ? '1' : '0',
      'notif_weekly'        => isset($_POST['notif_weekly'])        ? '1' : '0',
      'notif_monthly'       => isset($_POST['notif_monthly'])       ? '1' : '0',
      'ph_min'              => $_POST['ph_min']                     ?? '6.5',
      'ph_max'              => $_POST['ph_max']                     ?? '8.5',
      'tds_threshold'       => (int)($_POST['tds_threshold']        ?? 100),
      'test_frequency'      => $_POST['test_frequency']             ?? 'every_6h',
      'account_email'       => trim($_POST['account_email']         ?? ''),
      'account_timezone'    => $_POST['account_timezone']           ?? 'Asia/Manila',
    ];

    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
                               VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($settings as $k => $v) $stmt->execute([$k, $v]);

    $pdo->prepare("INSERT INTO user_activity_logs (user_id, email, role, action, status, ip_address)
                       VALUES (?, ?, ?, 'update_settings', 'success', ?)")
      ->execute([$_SESSION['user_id'], $_SESSION['email'] ?? '', $_SESSION['role'] ?? 'manager', $_SERVER['REMOTE_ADDR'] ?? '']);

    $rows    = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $tank    = $pdo->query("SELECT * FROM tank LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $success = 'Settings saved successfully.';
  } catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
  }
}

$me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$me       = $me->fetch(PDO::FETCH_ASSOC);
$initials = strtoupper(substr($me['username'] ?? $me['email'] ?? 'M', 0, 2));

$maxCap  = (int)cfg($rows, 'tank_capacity', $tank['max_capacity'] ?? 5000);
$threshV = (int)cfg($rows, 'threshold', 1000);
$pct     = $maxCap > 0 ? round($threshV / $maxCap * 100) : 20;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>EcoRain — Settings</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&family=DM+Mono:wght@400;500&family=Barlow+Condensed:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>/others/all.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
/* ── Sensor Registration Modal ── */
.sensor-modal-bd {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.6); z-index: 400;
  align-items: center; justify-content: center;
  padding: 1rem;
}
.sensor-modal-bd.open { display: flex; }
/* ── Edit button ── */
.btn-edit-tank {
  background: transparent; border: 1px solid #bfdbfe; color: #3b82f6;
  border-radius: 7px; padding: .32rem .75rem; font-size: .78rem; font-weight: 600;
  font-family: 'Inter', sans-serif; cursor: pointer;
  display: inline-flex; align-items: center; gap: .3rem;
  transition: background .15s, border-color .15s;
}
.btn-edit-tank:hover { background: #eff6ff; border-color: #3b82f6; }

/* ── Wizard modal ── */
.wizard-bd {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.55); z-index: 500;
  align-items: center; justify-content: center; padding: 1rem;
}
.wizard-bd.open { display: flex; }
.wizard-box {
  background: #fff; border-radius: 18px;
  width: 100%; max-width: 580px; max-height: 92vh;
  overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.22);
  animation: modal-pop .22s cubic-bezier(.34,1.56,.64,1);
}
.wizard-head {
  padding: 1.1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; background: #fff; z-index: 1;
}
.wizard-head h3 { font-size: .95rem; font-weight: 700; color: #111827; font-family: 'Space Grotesk', sans-serif; }
.wizard-close {
  background: none; border: none; cursor: pointer; color: #9ca3af;
  font-size: 1.1rem; width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.wizard-close:hover { background: #f3f4f6; color: #374151; }

/* Step indicator */
.wizard-steps {
  display: flex; align-items: center; gap: 0;
  padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
}
.wstep {
  display: flex; align-items: center; gap: .5rem;
  font-size: .75rem; font-weight: 600; color: #9ca3af;
  flex: 1;
}
.wstep.active { color: #3b82f6; }
.wstep.done   { color: #16a34a; }
.wstep-num {
  width: 24px; height: 24px; border-radius: 50%;
  background: #e5e7eb; color: #6b7280;
  display: flex; align-items: center; justify-content: center;
  font-size: .72rem; font-weight: 700; flex-shrink: 0;
  transition: background .2s, color .2s;
}
.wstep.active .wstep-num { background: #3b82f6; color: #fff; }
.wstep.done   .wstep-num { background: #16a34a; color: #fff; }
.wstep-line { flex: 1; height: 2px; background: #e5e7eb; margin: 0 .35rem; }
.wstep-line.done { background: #16a34a; }

/* Wizard body */
.wizard-body { padding: 1.5rem; }
.wiz-panel { display: none; }
.wiz-panel.active { display: block; }

/* Sensor picker */
.sensor-picker-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: .65rem;
  max-height: 300px; overflow-y: auto; padding: .1rem;
}
.sensor-option {
  border: 2px solid #e5e7eb; border-radius: 10px; padding: .85rem;
  cursor: pointer; transition: border-color .15s, background .15s;
  position: relative;
}
.sensor-option:hover { border-color: #93c5fd; background: #f0f9ff; }
.sensor-option.selected { border-color: #3b82f6; background: #eff6ff; }
.sensor-option.unavailable { opacity: .5; cursor: not-allowed; border-color: #e5e7eb; }
.sensor-option input[type=radio] { position: absolute; opacity: 0; }
.sensor-model { font-size: .82rem; font-weight: 700; color: #111827; }
.sensor-port  { font-family: 'DM Mono', monospace; font-size: .7rem; color: #6b7280; margin-top: .15rem; }
.sensor-loc   { font-size: .7rem; color: #3b82f6; margin-top: .3rem; }
.sensor-status-chip {
  display: inline-block; font-size: .65rem; font-weight: 700;
  padding: .15rem .45rem; border-radius: 20px; margin-top: .35rem;
}
.chip-available  { background: #dcfce7; color: #15803d; }
.chip-assigned   { background: #fef3c7; color: #92400e; }
.sensor-none-opt {
  border: 2px dashed #e5e7eb; border-radius: 10px; padding: .85rem;
  cursor: pointer; text-align: center; color: #9ca3af;
  font-size: .82rem; transition: border-color .15s;
  grid-column: 1 / -1;
}
.sensor-none-opt:hover { border-color: #93c5fd; }
.sensor-none-opt.selected { border-color: #3b82f6; color: #3b82f6; }

/* Mini map in wizard */
#wizardMap { height: 180px; border-radius: 10px; overflow: hidden; margin-top: .75rem; border: 1px solid #e5e7eb; }

/* Confirm step */
.confirm-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: .55rem 0; border-bottom: 1px solid #f3f4f6; font-size: .84rem;
}
.confirm-row:last-child { border-bottom: none; }
.confirm-row .lbl { color: #6b7280; }
.confirm-row .val { font-weight: 600; color: #111827; }

/* Wizard footer */
.wizard-footer {
  display: flex; justify-content: space-between; align-items: center;
  padding: 1rem 1.5rem; border-top: 1px solid #f1f5f9;
  position: sticky; bottom: 0; background: #fff;
}
.wiz-btn {
  padding: .6rem 1.35rem; border-radius: 8px; font-size: .84rem;
  font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; border: none;
  transition: opacity .15s, transform .1s;
}
.wiz-btn:hover { opacity: .88; transform: translateY(-1px); }
.wiz-btn-primary { background: #3b82f6; color: #fff; }
.wiz-btn-ghost   { background: #f3f4f6; color: #374151; }
.wiz-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }

/* Edit modal */
.edit-modal-bd {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.55); z-index: 500;
  align-items: center; justify-content: center; padding: 1rem;
}
.edit-modal-bd.open { display: flex; }
.edit-modal-box {
  background: #fff; border-radius: 18px; width: 100%; max-width: 520px;
  max-height: 92vh; overflow-y: auto;
  box-shadow: 0 24px 80px rgba(0,0,0,.22);
  animation: modal-pop .22s cubic-bezier(.34,1.56,.64,1);
}
.edit-modal-head {
  padding: 1.1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(135deg, #0f172a, #1e3a5f);
  border-radius: 18px 18px 0 0;
}
.edit-modal-head h3 { font-size: .95rem; font-weight: 700; color: #fff; font-family: 'Space Grotesk', sans-serif; }
.edit-modal-body { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.edit-field { display: flex; flex-direction: column; gap: .35rem; }
.edit-field.full { grid-column: 1 / -1; }
.edit-field label { font-size: .72rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #6b7280; }
.edit-field input,
.edit-field select { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .6rem .85rem; font-size: .875rem; font-family: 'Inter', sans-serif; color: #111827; outline: none; width: 100%; transition: border-color .15s; }
.edit-field input:focus,
.edit-field select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.edit-modal-footer { display: flex; gap: .65rem; justify-content: flex-end; padding: 1rem 1.5rem; border-top: 1px solid #f1f5f9; }

@media (max-width: 540px) {
  .sensor-picker-grid { grid-template-columns: 1fr; }
  .edit-modal-body { grid-template-columns: 1fr; }
  .edit-field.full { grid-column: 1; }
}
.sensor-modal-box {
  background: #fff; border-radius: 18px;
  width: 100%; max-width: 620px;
  max-height: 90vh; overflow-y: auto;
  box-shadow: 0 24px 80px rgba(0,0,0,.25);
  animation: modal-pop .22s cubic-bezier(.34,1.56,.64,1);
}

.sensor-modal-head {
  display: flex; align-items: center; gap: .75rem;
  padding: 1.1rem 1.5rem;
  background: linear-gradient(135deg, #0f172a, #1e3a5f);
  border-radius: 18px 18px 0 0;
  position: sticky; top: 0; z-index: 1;
}
.sensor-modal-head h3 {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 1rem; font-weight: 700; color: #fff;
}
.sensor-modal-head p { font-size: .73rem; color: #94a3b8; margin-top: .1rem; }

.sensor-modal-close {
  margin-left: auto; background: rgba(255,255,255,.1);
  border: none; cursor: pointer; color: #fff;
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; transition: background .15s;
}
.sensor-modal-close:hover { background: rgba(255,255,255,.2); }

.sensor-modal-body {
  padding: 1.5rem;
  display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
}

.s-field { display: flex; flex-direction: column; gap: .35rem; }
.s-field.full { grid-column: 1 / -1; }
.s-field label {
  font-size: .72rem; font-weight: 700;
  letter-spacing: .07em; text-transform: uppercase; color: #6b7280;
}
.s-field input,
.s-field select,
.s-field textarea {
  background: #f9fafb; border: 1px solid #e5e7eb;
  border-radius: 8px; padding: .6rem .85rem;
  font-size: .875rem; font-family: 'Inter', sans-serif;
  color: #111827; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
}
.s-field input:focus,
.s-field select:focus,
.s-field textarea:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}
.s-field .s-hint { font-size: .7rem; color: #9ca3af; }
.s-field textarea { resize: vertical; min-height: 70px; }

.s-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .6rem 1.2rem; border-radius: 8px;
  font-size: .82rem; font-weight: 600;
  font-family: 'Inter', sans-serif;
  cursor: pointer; border: none;
  transition: opacity .15s, transform .1s;
}
.s-btn:hover  { opacity: .88; transform: translateY(-1px); }
.s-btn:active { transform: translateY(0); }
.s-btn-primary { background: #3b82f6; color: #fff; }
.s-btn-ghost   {
  background: #fff; color: #6b7280;
  border: 1px solid #e5e7eb;
}

.s-flash-err {
  grid-column: 1 / -1;
  display: flex; align-items: center; gap: .5rem;
  padding: .75rem 1rem; border-radius: 8px;
  background: #fef2f2; border: 1px solid #fecaca;
  color: #dc2626; font-size: .83rem; font-weight: 500;
}

@media (max-width: 540px) {
  .sensor-modal-body { grid-template-columns: 1fr; }
  .s-field.full { grid-column: 1; }
  .sensor-modal-box  { border-radius: 14px; }
  .sensor-modal-head { border-radius: 14px 14px 0 0; }
}
    body {
      font-family: 'Inter', sans-serif;
      background: #e5e7eb;
      color: #111827;
      font-size: 14px;
      display: flex;
      min-height: 100vh;
      overflow-x: hidden;
    }

    .sidebar {
      width: 260px;
      min-width: 260px;
      background: #0f172a;
      min-height: 100vh;
      height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      display: flex;
      flex-direction: column;
      padding: 1.5rem 1rem;
      flex-shrink: 0;
      z-index: 100;
      transition: transform .25s ease;
      overflow-y: auto;
    }

    .sidebar.open {
      transform: translateX(0) !important;
    }

    .sidebar-logo {
      display: flex;
      align-items: center;
      gap: .65rem;
      padding: .25rem .5rem .25rem .25rem;
      margin-bottom: 2rem;
    }

    .logo-drop {
      width: 34px;
      height: 34px;
      background: linear-gradient(160deg, #60a5fa 10%, #2563eb 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.05rem;
      flex-shrink: 0;
    }

    .logo-name {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 1.1rem;
      font-weight: 700;
      color: #fff;
      letter-spacing: -.01em;
    }

    .nav-section {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: .1rem;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: .75rem;
      padding: .62rem .9rem;
      border-radius: 8px;
      font-size: .875rem;
      font-weight: 500;
      color: #94a3b8;
      text-decoration: none;
      transition: background .15s, color .15s;
    }

    .nav-link svg {
      width: 17px;
      height: 17px;
      flex-shrink: 0;
      stroke-width: 1.8;
    }

    .nav-link:hover {
      background: rgba(255, 255, 255, .07);
      color: #e2e8f0;
    }

    .nav-link.active {
      background: rgba(255, 255, 255, .12);
      color: #fff;
      font-weight: 600;
    }

    .sidebar-footer {
      margin-top: auto;
      padding-top: 1rem;
      border-top: 1px solid rgba(255, 255, 255, .08);
    }

    .nav-link.logout:hover {
      background: rgba(239, 68, 68, .13);
      color: #fca5a5;
    }

    .overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 99;
    }

    .overlay.show {
      display: block;
    }

    .main-wrap {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      overflow: hidden;
      margin-left: 260px;
      transition: margin-left .25s;
    }

    .topbar {
      height: 64px;
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.75rem;
      flex-shrink: 0;
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: .75rem;
    }

    .hamburger {
      display: none;
      background: none;
      border: none;
      cursor: pointer;
      padding: .35rem;
      color: #111827;
      border-radius: 8px;
    }

    .hamburger svg {
      width: 22px;
      height: 22px;
    }

    .topbar-left .page-title {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 1.2rem;
      font-weight: 700;
      color: #111827;
    }

    .topbar-left .page-sub {
      font-size: .78rem;
      color: #6b7280;
      margin-top: .1rem;
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: .85rem;
    }

    .t-search {
      display: flex;
      align-items: center;
      gap: .5rem;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: .45rem .85rem;
      width: 200px;
    }

    .t-search svg {
      width: 14px;
      height: 14px;
      color: #9ca3af;
      flex-shrink: 0;
    }

    .t-search input {
      background: none;
      border: none;
      outline: none;
      font-size: .83rem;
      font-family: 'Inter', sans-serif;
      color: #111827;
      width: 100%;
    }

    .t-search input::placeholder {
      color: #9ca3af;
    }

    .t-icon {
      width: 36px;
      height: 36px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: #6b7280;
      position: relative;
    }

    .t-icon svg {
      width: 16px;
      height: 16px;
    }

    .notif-dot {
      position: absolute;
      top: 6px;
      right: 6px;
      width: 7px;
      height: 7px;
      background: #ef4444;
      border-radius: 50%;
      border: 1.5px solid #fff;
    }

    .t-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: .8rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
    }

    .page-content {
      flex: 1;
      overflow-y: auto;
      padding: 1.5rem 1.75rem 3rem;
    }

    .flash {
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: .8rem 1rem;
      border-radius: 10px;
      font-size: .84rem;
      font-weight: 500;
      margin-bottom: 1.25rem;
    }

    .flash-ok {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #16a34a;
    }

    .flash-err {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #dc2626;
    }

    .s-card {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      margin-bottom: 1.1rem;
      overflow: hidden;
    }

    .s-card-head {
      display: flex;
      align-items: center;
      gap: .75rem;
      padding: 1rem 1.5rem;
      border-bottom: 1px solid #f3f4f6;
    }

    .s-card-icon {
      width: 34px;
      height: 34px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .95rem;
      flex-shrink: 0;
    }

    .icon-blue {
      background: #eff6ff;
    }

    .icon-green {
      background: #f0fdf4;
    }

    .icon-yellow {
      background: #fffbeb;
    }

    .icon-purple {
      background: #f5f3ff;
    }

    .icon-slate {
      background: #f8fafc;
    }

    .s-card-title {
      font-size: .9rem;
      font-weight: 700;
      color: #111827;
    }

    .s-card-sub {
      font-size: .73rem;
      color: #9ca3af;
      margin-top: .08rem;
    }

    .s-card-body {
      padding: 1.25rem 1.5rem 1.5rem;
    }

    .fg2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.1rem;
    }

    .fg1 {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.1rem;
    }

    .mb {
      margin-bottom: 1.1rem;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: .38rem;
    }

    .field>label {
      font-size: .72rem;
      font-weight: 600;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: #6b7280;
    }

    .f-input,
    .f-select {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: .6rem .85rem;
      font-size: .875rem;
      font-family: 'Inter', sans-serif;
      color: #111827;
      outline: none;
      width: 100%;
      transition: border-color .15s, box-shadow .15s;
    }

    .f-input:focus,
    .f-select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, .1);
    }

    .f-input[readonly] {
      background: #f3f4f6;
      color: #9ca3af;
      cursor: not-allowed;
    }

    .f-select {
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 2.2rem;
      cursor: pointer;
    }

    .slider-wrap {
      display: flex;
      align-items: center;
      gap: .75rem;
    }

    .slider-wrap input[type=range] {
      flex: 1;
      -webkit-appearance: none;
      height: 5px;
      border-radius: 3px;
      outline: none;
      cursor: pointer;
      background: linear-gradient(to right, #3b82f6 0%, #3b82f6 var(--val, 20%), #e5e7eb var(--val, 20%), #e5e7eb 100%);
    }

    .slider-wrap input[type=range]::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 17px;
      height: 17px;
      border-radius: 50%;
      background: #3b82f6;
      border: 2.5px solid #fff;
      box-shadow: 0 1px 5px rgba(59, 130, 246, .4);
      cursor: pointer;
    }

    .slider-lbl {
      font-size: .78rem;
      font-weight: 600;
      color: #3b82f6;
      min-width: 58px;
      text-align: right;
      background: #eff6ff;
      border-radius: 6px;
      padding: .25rem .55rem;
    }

    .row-divider {
      border: none;
      border-top: 1px solid #f3f4f6;
      margin: 1rem 0;
    }

    .tog-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .88rem 0;
      border-bottom: 1px solid #f3f4f6;
      gap: 1rem;
    }

    .tog-row:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .tog-row:first-child {
      padding-top: 0;
    }

    .tog-info {
      flex: 1;
      min-width: 0;
    }

    .tog-info strong {
      font-size: .875rem;
      font-weight: 500;
      color: #111827;
      display: block;
    }

    .tog-info span {
      font-size: .75rem;
      color: #9ca3af;
      margin-top: .12rem;
      display: block;
      line-height: 1.4;
    }

    .tog {
      position: relative;
      width: 42px;
      height: 24px;
      flex-shrink: 0;
      cursor: pointer;
    }

    .tog input {
      opacity: 0;
      width: 0;
      height: 0;
      position: absolute;
    }

    .tog-track {
      position: absolute;
      inset: 0;
      background: #d1d5db;
      border-radius: 12px;
      transition: background .2s;
      cursor: pointer;
    }

    .tog-thumb {
      position: absolute;
      top: 2px;
      left: 2px;
      width: 20px;
      height: 20px;
      background: #fff;
      border-radius: 50%;
      box-shadow: 0 1px 4px rgba(0, 0, 0, .2);
      transition: transform .2s;
      pointer-events: none;
    }

    .tog input:checked~.tog-track {
      background: #22c55e;
    }

    .tog input:checked~.tog-thumb {
      transform: translateX(18px);
    }

    .ph-wrap {
      display: flex;
      align-items: center;
      gap: .6rem;
    }

    .ph-wrap .f-input {
      text-align: center;
    }

    .ph-dash {
      color: #9ca3af;
      font-size: 1rem;
      font-weight: 500;
      flex-shrink: 0;
    }

    .save-bar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: .85rem;
      margin-top: .5rem;
      flex-wrap: wrap;
    }

    .btn-discard {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: .65rem 1.5rem;
      font-size: .875rem;
      font-weight: 500;
      color: #6b7280;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
      transition: border-color .15s, color .15s;
    }

    .btn-discard:hover {
      border-color: #94a3b8;
      color: #374151;
    }

    .btn-save {
      background: #3b82f6;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: .65rem 2rem;
      font-size: .875rem;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: .5rem;
      transition: background .15s, transform .1s;
    }

    .btn-save:hover {
      background: #2563eb;
      transform: translateY(-1px);
    }

    .btn-save:active {
      transform: translateY(0);
    }

    .btn-save svg {
      width: 15px;
      height: 15px;
    }

    .toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: #0f172a;
      color: #fff;
      padding: .9rem 1.25rem;
      border-radius: 12px;
      font-size: .84rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: .7rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
      transform: translateY(70px) scale(.95);
      opacity: 0;
      transition: transform .32s cubic-bezier(.34, 1.56, .64, 1), opacity .32s;
      z-index: 999;
      pointer-events: none;
    }

    .toast.show {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    /* ── Tank list table ────────────────────────────────────────────────────── */
    .tank-list-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: .75rem;
    }

    .tank-list-table thead tr {
      background: #f9fafb;
    }

    .tank-list-table th {
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .07em;
      text-transform: uppercase;
      color: #6b7280;
      padding: .6rem .85rem;
      text-align: left;
      border-bottom: 1px solid #e5e7eb;
    }

    .tank-list-table td {
      padding: .7rem .85rem;
      font-size: .855rem;
      color: #111827;
      border-bottom: 1px solid #f3f4f6;
      vertical-align: middle;
    }

    .tank-list-table tr:last-child td {
      border-bottom: none;
    }

    .tank-list-table tr:hover td {
      background: #f9fafb;
    }

    .tank-badge {
      display: inline-flex;
      align-items: center;
      padding: .2rem .65rem;
      border-radius: 20px;
      font-size: .72rem;
      font-weight: 600;
    }

    .tank-badge.active {
      background: #dcfce7;
      color: #15803d;
    }

    .tank-badge.inactive {
      background: #fee2e2;
      color: #b91c1c;
    }

    .tank-badge.maintenance {
      background: #fef3c7;
      color: #92400e;
    }

    .btn-delete-tank {
      background: transparent;
      border: 1px solid #fecaca;
      color: #ef4444;
      border-radius: 7px;
      padding: .32rem .75rem;
      font-size: .78rem;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      transition: background .15s, border-color .15s;
    }

    .btn-delete-tank:hover {
      background: #fef2f2;
      border-color: #ef4444;
    }

    .btn-delete-tank svg {
      width: 13px;
      height: 13px;
    }

    /* ── Confirm modal ──────────────────────────────────────────────────────── */
    .modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 200;
      align-items: center;
      justify-content: center;
    }

    .modal-backdrop.show {
      display: flex;
    }

    .modal-box {
      background: #fff;
      border-radius: 16px;
      padding: 1.75rem;
      max-width: 380px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .18);
      text-align: center;
      animation: modal-pop .22s cubic-bezier(.34, 1.56, .64, 1);
    }

    @keyframes modal-pop {
      from {
        opacity: 0;
        transform: scale(.9);
      }

      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    .modal-icon {
      width: 48px;
      height: 48px;
      background: #fef2f2;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1rem;
    }

    .modal-icon svg {
      width: 22px;
      height: 22px;
      color: #ef4444;
    }

    .modal-box h4 {
      font-family: 'Space Grotesk', sans-serif;
      font-size: 1rem;
      font-weight: 700;
      color: #111827;
      margin-bottom: .45rem;
    }

    .modal-box p {
      font-size: .83rem;
      color: #6b7280;
      line-height: 1.5;
      margin-bottom: 1.5rem;
    }

    .modal-box p strong {
      color: #111827;
    }

    .modal-actions {
      display: flex;
      gap: .65rem;
    }

    .btn-modal-cancel {
      flex: 1;
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: .65rem;
      font-size: .85rem;
      font-weight: 500;
      color: #6b7280;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
    }

    .btn-modal-cancel:hover {
      border-color: #94a3b8;
    }

    .btn-modal-confirm {
      flex: 1;
      background: #ef4444;
      border: none;
      border-radius: 8px;
      padding: .65rem;
      font-size: .85rem;
      font-weight: 600;
      color: #fff;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
    }

    .btn-modal-confirm:hover {
      background: #dc2626;
    }

    /* ── Add Tank form ──────────────────────────────────────────────────────── */
    :root {
      --fgen-bg: #0d1117;
      --fgen-surface: #161b22;
      --fgen-border: #2a3441;
      --fgen-focus: #3b82f6;
      --fgen-accent: #3b82f6;
      --fgen-glow: rgba(59, 130, 246, 0.18);
      --fgen-text: #e6edf3;
      --fgen-muted: #7d8fa3;
      --fgen-label-c: #8b9ab0;
      --fgen-radius: 6px;
      --fgen-font-ui: 'Barlow Condensed', sans-serif;
      --fgen-font-mono: 'DM Mono', monospace;
    }

    .btn-add-tank {
      font-family: var(--fgen-font-ui);
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: var(--fgen-accent);
      background: transparent;
      border: 1px solid #d1d5db;
      border-radius: var(--fgen-radius);
      padding: 0.5rem 1rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      transition: background 0.18s, border-color 0.18s, box-shadow 0.18s;
    }

    .btn-add-tank::before {
      content: '+';
      font-size: 1.05rem;
      font-weight: 400;
      line-height: 1;
      color: var(--fgen-accent);
    }

    .btn-add-tank:hover {
      background: rgba(59, 130, 246, 0.07);
      border-color: var(--fgen-accent);
      box-shadow: 0 0 0 3px var(--fgen-glow);
    }

    #addTankForm {
      display: none;
      animation: fgen-slidein 0.22s ease forwards;
      margin-top: 1rem;
    }

    #addTankForm.fgen-collapsing {
      animation: fgen-slideout 0.22s ease forwards;
      pointer-events: none;
    }

    @keyframes fgen-slidein {
      from {
        opacity: 0;
        transform: translateY(-8px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fgen-slideout {
      from {
        opacity: 1;
        transform: translateY(0);
      }

      to {
        opacity: 0;
        transform: translateY(-8px);
      }
    }

    .fgen-form {
      font-family: var(--fgen-font-ui);
      background: var(--fgen-surface);
      border: 1px solid var(--fgen-border);
      border-radius: var(--fgen-radius);
      padding: 1.5rem 1.75rem;
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 0;
      position: relative;
      box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.06), 0 6px 24px rgba(0, 0, 0, 0.35);
    }

    .fgen-form::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--fgen-accent), #60a5fa);
      border-radius: var(--fgen-radius) var(--fgen-radius) 0 0;
    }

    .fgen-field {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
      padding-bottom: 1.1rem;
      position: relative;
    }

    .fgen-field:not(:last-of-type)::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 1px;
      background: var(--fgen-border);
      opacity: 0.5;
    }

    .fgen-label {
      font-family: var(--fgen-font-mono);
      font-size: 0.66rem;
      font-weight: 500;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--fgen-label-c);
      user-select: none;
      display: flex;
      align-items: center;
      gap: 0.4rem;
    }

    .fgen-label::before {
      content: '//';
      color: var(--fgen-accent);
      opacity: 0.6;
      font-size: 0.63rem;
    }

    .fgen-input,
    .fgen-select {
      font-family: var(--fgen-font-mono);
      font-size: 0.9rem;
      color: var(--fgen-text);
      background: var(--fgen-bg);
      border: 1px solid var(--fgen-border);
      border-radius: var(--fgen-radius);
      padding: 0.58rem 0.85rem;
      width: 100%;
      box-sizing: border-box;
      outline: none;
      transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
      -webkit-appearance: none;
      appearance: none;
    }

    .fgen-input::placeholder {
      color: var(--fgen-muted);
      opacity: 0.55;
    }

    .fgen-input:focus,
    .fgen-select:focus {
      border-color: var(--fgen-focus);
      background: #0f1620;
      box-shadow: 0 0 0 3px var(--fgen-glow);
    }

    .fgen-input[type='number']::-webkit-inner-spin-button,
    .fgen-input[type='number']::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }

    .fgen-input[type='number'] {
      -moz-appearance: textfield;
    }

    .fgen-select {
      padding-right: 2.25rem;
      cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237d8fa3' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.85rem center;
      background-color: var(--fgen-bg);
    }

    .fgen-select option {
      background: #1c2333;
      color: var(--fgen-text);
    }

    .fgen-form-actions {
      display: flex;
      gap: 0.6rem;
      margin-top: 1.25rem;
    }

    .fgen-submit {
      font-family: var(--fgen-font-ui);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: #fff;
      background: var(--fgen-accent);
      border: none;
      border-radius: var(--fgen-radius);
      padding: 0.65rem 1.4rem;
      cursor: pointer;
      flex: 1;
      position: relative;
      overflow: hidden;
      transition: background 0.18s, transform 0.12s, box-shadow 0.18s;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    }

    .fgen-submit:hover {
      background: #2563eb;
      transform: translateY(-1px);
      box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
    }

    .fgen-cancel {
      font-family: var(--fgen-font-ui);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: var(--fgen-muted);
      background: transparent;
      border: 1px solid var(--fgen-border);
      border-radius: var(--fgen-radius);
      padding: 0.65rem 1.2rem;
      cursor: pointer;
      transition: border-color 0.18s, color 0.18s;
    }

    .fgen-cancel:hover {
      border-color: #3b4a60;
      color: var(--fgen-text);
    }

    @media (max-width: 900px) {
      .sidebar {
        transform: translateX(-100%);
      }

      .main-wrap {
        margin-left: 0;
      }

      .hamburger {
        display: flex;
      }

      .t-search {
        display: none;
      }

      .fg2 {
        grid-template-columns: 1fr;
      }

      .page-content {
        padding: 1.25rem;
      }

      .topbar {
        padding: 0 1rem;
      }
    }

    @media (max-width: 480px) {
      .s-card-body {
        padding: 1rem;
      }

      .save-bar {
        justify-content: stretch;
      }

      .btn-save,
      .btn-discard {
        flex: 1;
        text-align: center;
        justify-content: center;
      }

      .ph-wrap {
        flex-wrap: wrap;
      }

      .ph-wrap .f-input {
        min-width: 80px;
      }
    }
  </style>
</head>

<body>

  <div class="overlay" id="overlay" onclick="closeSidebar()"></div>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="logo-drop">💧</div>
      <span class="logo-name">EcoRain</span>
    </div>
    <nav class="nav-section">
      <a href="<?= BASE_URL ?>/app/manager/manager.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
        Dashboard
      </a>
      <a href="<?= BASE_URL ?>/app/manager/manager_oversight.php" class="nav-item <?= $activePage === 'oversight' ? 'active' : '' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
        Oversight
      </a>
      <a href="<?= BASE_URL ?>/app/manager/usage.php" class="nav-item <?= $activePage === 'usage' ? 'active' : '' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
        </svg>
        Usage Stats
      </a>
      <a href="<?= BASE_URL ?>/app/manager/weather.php" class="nav-item">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
        </svg>
        Weather
      </a>
      <a href="<?= BASE_URL ?>/app/manager/map.php" class="nav-item">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z" />
          <circle cx="12" cy="10" r="3" />
        </svg>
        Tank Map
      </a>
      <a href="<?= BASE_URL ?>/app/manager/settings.php" class="nav-item">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
        </svg>
        Settings
      </a>

      <div class="sidebar-spacer"></div>
      <div class="sidebar-bottom">
        <a href="<?= BASE_URL ?>/connections/signout.php" class="nav-item logout">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
          </svg>
          Log Out
        </a>
      </div>
  </aside>

  <!-- MAIN -->
  <div class="main-wrap">
    <header class="topbar">
      <div class="topbar-left">
        <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
          </svg>
        </button>
        <div>
          <div class="page-title">Settings</div>
          <div class="page-sub">Configure your EcoRain System</div>
        </div>
      </div>
      <div class="topbar-right">
        <div class="t-search">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
          </svg>
          <input type="text" placeholder="Search..." />
        </div>
        <div class="t-icon">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 01-3.46 0" />
          </svg>
          <span class="notif-dot"></span>
        </div>
        <a href="<?php echo BASE_URL; ?>/app/manager/user.php" class="t-avatar"><?= htmlspecialchars($initials) ?></a>
      </div>
    </header>

    <div class="page-content">

      <?php if ($success): ?>
        <div class="flash flash-ok">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12" />
          </svg>
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="flash flash-err">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST">

        <!-- TANK CONFIGURATION -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-blue">🪣</div>
            <div>
              <div class="s-card-title">Tank Configuration</div>
              <div class="s-card-sub">Manage capacity and overflow thresholds</div>
            </div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>Tank Capacity (Litres)</label>
                <input class="f-input" type="number" name="tank_capacity" id="tankCapacity"
                  value="<?= (int)cfg($rows, 'tank_capacity', $tank['max_capacity'] ?? 5000) ?>"
                  min="100" step="100" />
              </div>
              <div class="field">
                <label>Low-Level Alert Threshold</label>
                <div class="slider-wrap">
                  <input type="range" name="threshold" id="threshold"
                    min="0" max="<?= $maxCap ?>" value="<?= $threshV ?>"
                    style="--val:<?= $pct ?>%"
                    oninput="updateSlider(this,'thresholdVal')" />
                  <span class="slider-lbl" id="thresholdVal"><?= number_format($threshV) ?>L</span>
                </div>
              </div>
            </div>
            <hr class="row-divider" />
            <div class="tog-row">
              <div class="tog-info">
                <strong>Overflow Prevention</strong>
                <span>Automatically divert water when tank reaches capacity</span>
              </div>
              <label class="tog">
                <input type="checkbox" name="overflow_prevention" <?= cfg($rows, 'overflow_prevention', '1') === '1' ? 'checked' : '' ?> />
                <div class="tog-track"></div>
                <div class="tog-thumb"></div>
              </label>
            </div>
            <hr class="row-divider" />

            <!-- REGISTERED TANKS LIST -->
            <?php if (!empty($allTanks)): ?>
              <div style="margin-bottom:1.25rem;">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6b7280;margin-bottom:.6rem;">
                  Registered Tanks
                </div>
                <table class="tank-list-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Tank Name</th>
                      <th>Location</th>
                      <th>Status</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($allTanks as $t): ?>
                      <tr>
                        <td style="color:#9ca3af;font-size:.78rem"><?= $t['tank_id'] ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($t['tankname']) ?></td>
                        <td style="color:#6b7280">📍 <?= htmlspecialchars($t['location_add']) ?></td>
                        <td>
                          <span class="tank-badge <?= strtolower($t['status_tank']) ?>">
                            <?= htmlspecialchars($t['status_tank']) ?>
                          </span>
                        </td>
                        <td style="display:flex;gap:.4rem;align-items:center">
  <button type="button"
          class="btn-edit-tank"
          onclick="openEditModal(
            <?= $t['tank_id'] ?>,
            '<?= htmlspecialchars(addslashes($t['tankname'])) ?>',
            '<?= htmlspecialchars(addslashes($t['location_add'])) ?>',
            '<?= $t['status_tank'] ?>'
          )">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" width="13" height="13">
      <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
      <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
    </svg>
    Edit
  </button>
  <button type="button" class="btn-delete-tank"
          onclick="confirmDelete(<?= $t['tank_id'] ?>, '<?= htmlspecialchars(addslashes($t['tankname'])) ?>')">
    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="3 6 5 6 21 6"/>
      <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
      <path d="M10 11v6M14 11v6"/>
      <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
    </svg>
    Delete
  </button>
</td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <!-- ADD TANK TRIGGER -->
            <button type="button" class="btn-add-tank" onclick="openTankWizard()">Add Tank</button>
            <button type="button" class="btn-add-tank"
              onclick="openSensorModal()"
              style="margin-left:.5rem;color:#3b82f6">
              Register Sensor
            </button>

            <!-- ADD TANK INLINE FORM -->
            <div id="addTankForm">
              <form method="POST" class="fgen-form">
                <input type="hidden" name="add_tank" value="1" />
                <div class="fgen-field">
                  <label class="fgen-label">Tank Name</label>
                  <input type="text" name="tankname" class="fgen-input" placeholder="e.g. Main Rooftop Tank" required />
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Location Address</label>
                  <input type="text" name="location_add" class="fgen-input" placeholder="e.g. Brgy. Poblacion, Manolo Fortich, Bukidnon" required />
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Current Liters</label>
                  <input type="number" name="current_liters" class="fgen-input" placeholder="0" min="0" />
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Max Capacity (L)</label>
                  <input type="number" name="max_capacity" class="fgen-input" placeholder="5000" min="1" required />
                </div>
                <div class="fgen-field">
                  <label class="fgen-label">Status</label>
                  <select name="status_tank" class="fgen-select" id="tankStatusSelect">
                    <option value="">Select status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                  </select>
                </div>
                <div class="fgen-form-actions">
                  <button type="submit" class="fgen-submit">Submit</button>
                  <button type="button" class="fgen-cancel" onclick="hideAddTankForm()">Cancel</button>
                </div>
              </form>
            </div>

          </div>
        </div>

        <!-- PUMP SETTINGS -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-green">⚙️</div>
            <div>
              <div class="s-card-title">Pump Settings</div>
              <div class="s-card-sub">Control automation and scheduling</div>
            </div>
          </div>
          <div class="s-card-body">
            <div class="tog-row">
              <div class="tog-info">
                <strong>Auto Mode</strong>
                <span>Pump operates based on demand and weather conditions</span>
              </div>
              <label class="tog">
                <input type="checkbox" name="pump_auto" <?= cfg($rows, 'pump_auto', '1') === '1' ? 'checked' : '' ?> />
                <div class="tog-track"></div>
                <div class="tog-thumb"></div>
              </label>
            </div>
            <hr class="row-divider" />
            <div class="fg2">
              <div class="field">
                <label>Schedule Mode</label>
                <select class="f-select" name="pump_schedule">
                  <?php
                  $schedules = ['smart' => 'Smart (Weather-based)', 'fixed' => 'Fixed Schedule', 'manual' => 'Manual Only', 'sensor' => 'Sensor-Driven'];
                  $curSched  = cfg($rows, 'pump_schedule', 'smart');
                  foreach ($schedules as $v => $l) echo "<option value=\"$v\"" . ($curSched === $v ? ' selected' : '') . ">$l</option>";
                  ?>
                </select>
              </div>
              <div class="field">
                <label>Max Wattage Limit (W)</label>
                <input class="f-input" type="number" name="pump_wattage" value="<?= (int)cfg($rows, 'pump_wattage', 100) ?>" min="0" />
              </div>
            </div>
          </div>
        </div>

        <!-- NOTIFICATIONS -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-yellow">🔔</div>
            <div>
              <div class="s-card-title">Notifications</div>
              <div class="s-card-sub">Choose which alerts and reports to receive</div>
            </div>
          </div>
          <div class="s-card-body">
            <?php
            $notifs = [
              'notif_low_water'    => ['Low Water Alert',     '1', 'Alert when tank drops below threshold'],
              'notif_heavy_rain'   => ['Heavy Rain Alert',    '1', 'Alert when heavy rain is forecast'],
              'notif_pump_failure' => ['Pump Failure Alert',  '1', 'Alert when pump encounters an error'],
              'notif_weekly'       => ['Weekly Usage Report', '0', 'Receive weekly water usage summary'],
              'notif_monthly'      => ['Monthly Summary',     '1', 'Monthly system performance report'],
            ];
            foreach ($notifs as $name => [$lbl, $def, $desc]):
              $chk = cfg($rows, $name, $def) === '1';
            ?>
              <div class="tog-row">
                <div class="tog-info">
                  <strong><?= $lbl ?></strong>
                  <span><?= $desc ?></span>
                </div>
                <label class="tog">
                  <input type="checkbox" name="<?= $name ?>" <?= $chk ? 'checked' : '' ?> />
                  <div class="tog-track"></div>
                  <div class="tog-thumb"></div>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- WATER QUALITY -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-purple">💧</div>
            <div>
              <div class="s-card-title">Water Quality Alerts</div>
              <div class="s-card-sub">pH, TDS thresholds and testing schedule</div>
            </div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>pH Range (Min – Max)</label>
                <div class="ph-wrap">
                  <input class="f-input" type="number" name="ph_min" step="0.1" min="0" max="14" value="<?= cfg($rows, 'ph_min', '6.5') ?>" />
                  <span class="ph-dash">—</span>
                  <input class="f-input" type="number" name="ph_max" step="0.1" min="0" max="14" value="<?= cfg($rows, 'ph_max', '8.5') ?>" />
                </div>
              </div>
              <div class="field">
                <label>TDS Threshold (ppm)</label>
                <input class="f-input" type="number" name="tds_threshold" value="<?= (int)cfg($rows, 'tds_threshold', 100) ?>" min="0" />
              </div>
            </div>
            <hr class="row-divider" />
            <div class="fg1">
              <div class="field">
                <label>Test Frequency</label>
                <select class="f-select" name="test_frequency">
                  <?php
                  $freqs  = ['every_3h' => 'Every 3 hours', 'every_6h' => 'Every 6 hours', 'every_12h' => 'Every 12 hours', 'daily' => 'Once daily', 'continuous' => 'Continuous'];
                  $curFrq = cfg($rows, 'test_frequency', 'every_6h');
                  foreach ($freqs as $v => $l) echo "<option value=\"$v\"" . ($curFrq === $v ? ' selected' : '') . ">$l</option>";
                  ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- ACCOUNT -->
        <div class="s-card">
          <div class="s-card-head">
            <div class="s-card-icon icon-slate">👤</div>
            <div>
              <div class="s-card-title">Account</div>
              <div class="s-card-sub">Email, timezone and role preferences</div>
            </div>
          </div>
          <div class="s-card-body">
            <div class="fg2 mb">
              <div class="field">
                <label>Email Address</label>
                <input class="f-input" type="email" name="account_email" value="<?= htmlspecialchars(cfg($rows, 'account_email', $me['email'] ?? '')) ?>" />
              </div>
              <div class="field">
                <label>Role</label>
                <input class="f-input" type="text" value="<?= ucfirst($me['role'] ?? 'manager') ?>" readonly />
              </div>
            </div>
            <hr class="row-divider" />
            <div class="fg1">
              <div class="field">
                <label>Timezone</label>
                <select class="f-select" name="account_timezone">
                  <?php
                  $tzones = ['Asia/Manila' => 'Asia/Manila (PHT +8)', 'UTC' => 'UTC', 'America/Los_Angeles' => 'Pacific Time (PT)', 'America/New_York' => 'Eastern Time (ET)'];
                  $curTz  = cfg($rows, 'account_timezone', 'Asia/Manila');
                  foreach ($tzones as $v => $l) echo "<option value=\"$v\"" . ($curTz === $v ? ' selected' : '') . ">$l</option>";
                  ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Save Bar -->
        <div class="save-bar">
          <button type="button" class="btn-discard" onclick="window.location.reload()">Discard</button>
          <button type="submit" class="btn-save">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <polyline points="20 6 9 17 4 12" />
            </svg>
            Save Changes
          </button>
        </div>

      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM MODAL -->
  <div class="modal-backdrop" id="deleteModal">
    <div class="modal-box">
      <div class="modal-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <polyline points="3 6 5 6 21 6" />
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" />
          <path d="M10 11v6M14 11v6" />
          <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" />
        </svg>
      </div>
      <h4>Delete Tank?</h4>
      <p>You're about to permanently delete <strong id="modalTankName"></strong>.<br>This action cannot be undone.</p>
      <div class="modal-actions">
        <button class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
        <button class="btn-modal-confirm" onclick="submitDelete()">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Hidden delete form -->
  <form method="POST" id="deleteTankForm" style="display:none">
    <input type="hidden" name="delete_tank" value="1" />
    <input type="hidden" name="tank_id" id="deleteTankId" />
  </form>

  <div class="toast" id="toast">✅&nbsp; Settings saved successfully</div>

  <script>
    /* ══ TANK WIZARD ══════════════════════════════════════════ */
let wizardStep    = 1;
let selectedSensorId   = null;
let selectedSensorName = 'None (assign later)';
let wizMap = null, wizMarker = null;

function openTankWizard() {
  wizardStep = 1;
  selectedSensorId   = null;
  selectedSensorName = 'None (assign later)';
  document.getElementById('wiz_sensor_id').value = '';
  document.getElementById('tankWizardForm').reset();
  // Reset sensor selection UI
  document.querySelectorAll('.sensor-option').forEach(el => el.classList.remove('selected'));
  document.getElementById('sensorNoneOpt').classList.add('selected');
  renderWizardStep();
  document.getElementById('tankWizard').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeTankWizard() {
  document.getElementById('tankWizard').classList.remove('open');
  document.body.style.overflow = '';
}

function renderWizardStep() {
  [1,2,3].forEach(i => {
    document.getElementById(`wpanel-${i}`).classList.toggle('active', i === wizardStep);
    const step = document.getElementById(`wstep-${i}`);
    step.classList.remove('active','done');
    if (i === wizardStep) step.classList.add('active');
    if (i < wizardStep)  step.classList.add('done');
    if (i < 3) {
      document.getElementById(`wline-${i}`).classList.toggle('done', i < wizardStep);
    }
  });

  document.getElementById('wizStepLabel').textContent = `Step ${wizardStep} of 3`;
  document.getElementById('wizBtnBack').style.visibility = wizardStep > 1 ? 'visible' : 'hidden';

  const nextBtn = document.getElementById('wizBtnNext');
  if (wizardStep === 3) {
    nextBtn.textContent = '✓ Add Tank';
    nextBtn.onclick = () => document.getElementById('tankWizardForm').submit();
  } else {
    nextBtn.textContent = 'Next →';
    nextBtn.onclick = wizardNext;
  }

  if (wizardStep === 2) initWizardMap();
  if (wizardStep === 3) fillConfirm();
}

function wizardNext() {
  if (wizardStep === 1) {
    const name = document.getElementById('wiz_tankname').value.trim();
    const loc  = document.getElementById('wiz_location').value.trim();
    const cap  = document.getElementById('wiz_capacity').value;
    if (!name) { alert('Tank name is required.'); return; }
    if (!loc)  { alert('Location is required.'); return; }
    if (!cap || parseInt(cap) <= 0) { alert('Max capacity must be > 0.'); return; }
  }
  if (wizardStep < 3) { wizardStep++; renderWizardStep(); }
}

function wizardBack() {
  if (wizardStep > 1) { wizardStep--; renderWizardStep(); }
}

function selectSensor(id, el) {
  document.querySelectorAll('.sensor-option, .sensor-none-opt').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
  selectedSensorId   = id;
  document.getElementById('wiz_sensor_id').value = id ?? '';
  if (id) {
    selectedSensorName = el.querySelector('.sensor-model')?.textContent?.trim() ?? 'Sensor #' + id;
  } else {
    selectedSensorName = 'None (assign later)';
  }
}

function fillConfirm() {
  document.getElementById('conf_name').textContent   = document.getElementById('wiz_tankname').value;
  document.getElementById('conf_loc').textContent    = document.getElementById('wiz_location').value;
  document.getElementById('conf_cap').textContent    = parseInt(document.getElementById('wiz_capacity').value).toLocaleString() + ' L';
  document.getElementById('conf_cur').textContent    = parseInt(document.getElementById('wiz_current').value || 0).toLocaleString() + ' L';
  document.getElementById('conf_status').textContent = document.getElementById('wiz_status').value;
  document.getElementById('conf_sensor').textContent = selectedSensorName;
}

function initWizardMap() {
  if (!document.getElementById('wizardMap')) return;

  const loc = document.getElementById('wiz_location').value.trim();

  if (!wizMap) {
    wizMap = L.map('wizardMap').setView([8.360015, 124.868419], 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap', maxZoom: 19
    }).addTo(wizMap);
  }

  setTimeout(() => wizMap.invalidateSize(), 100);

  if (loc) {
    fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(loc)}&format=json&limit=1`, {
      headers: { 'Accept-Language': 'en' }
    })
    .then(r => r.json())
    .then(data => {
      if (data && data.length > 0) {
        const lat = parseFloat(data[0].lat);
        const lng = parseFloat(data[0].lon);
        wizMap.flyTo([lat, lng], 15);
        if (wizMarker) wizMarker.remove();
        wizMarker = L.marker([lat, lng]).addTo(wizMap)
          .bindPopup(`📍 ${loc}`).openPopup();
        document.getElementById('wizMapNote').textContent = `📍 ${data[0].display_name}`;
      } else {
        document.getElementById('wizMapNote').textContent = 'Location not found on map.';
      }
    })
    .catch(() => {
      document.getElementById('wizMapNote').textContent = 'Map preview unavailable.';
    });
  } else {
    document.getElementById('wizMapNote').textContent = 'No location entered.';
  }
}

// Close wizard on backdrop click
document.getElementById('tankWizard').addEventListener('click', function(e) {
  if (e.target === this) closeTankWizard();
});

/* ══ EDIT TANK MODAL ════════════════════════════════════════ */
function openEditModal(tankId, tankname, location, status) {
  document.getElementById('edit_tank_id').value   = tankId;
  document.getElementById('edit_tankname').value  = tankname;
  document.getElementById('edit_location').value  = location;
  document.getElementById('edit_status').value    = status;

  // Fetch current liters + capacity via AJAX
  fetch(`<?= BASE_URL ?>/others/data.php?action=tank_levels`)
    .then(r => r.json())
    .then(data => {
      const tank = (data.tanks || []).find(t => t.tank_id == tankId);
      if (tank) {
        document.getElementById('edit_current').value  = tank.current_liters;
        document.getElementById('edit_capacity').value = tank.max_capacity;
      }
    })
    .catch(() => {});

  document.getElementById('editTankModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeEditModal() {
  document.getElementById('editTankModal').classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('editTankModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});

// ESC closes any open modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeTankWizard();
    closeEditModal();
  }
});

<?php if ($success && (isset($_POST['add_tank']) || isset($_POST['edit_tank']))): ?>
// Auto-open was successful — just show toast
(function() {
  const t = document.getElementById('toast');
  if (t) { t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3200); }
})();
<?php endif; ?>
    function openSensorModal() {
      document.getElementById('sensorModal').classList.add('open');
      document.body.style.overflow = 'hidden';
    }

    function closeSensorModal() {
      document.getElementById('sensorModal').classList.remove('open');
      document.body.style.overflow = '';
    }
    // Close on backdrop click
    document.getElementById('sensorModal').addEventListener('click', function(e) {
      if (e.target === this) closeSensorModal();
    });
    // Close on ESC
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeSensorModal();
    });
    // Auto-open if there was a sensor error or success
    <?php if ($sensorError || $sensorSuccess): ?>
      openSensorModal();
    <?php endif; ?>

    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlay').classList.toggle('show');
    }

    function closeSidebar() {
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('overlay').classList.remove('show');
    }

    function updateSlider(el, valId) {
      const max = parseInt(el.max) || 5000;
      const val = parseInt(el.value);
      el.style.setProperty('--val', Math.round(val / max * 100) + '%');
      document.getElementById(valId).textContent = val.toLocaleString() + 'L';
    }
    document.getElementById('tankCapacity').addEventListener('input', function() {
      const s = document.getElementById('threshold');
      const newMax = parseInt(this.value) || 5000;
      if (parseInt(s.value) > newMax) s.value = newMax;
      s.max = newMax;
      updateSlider(s, 'thresholdVal');
    });

    function showAddTankForm() {
      const wrap = document.getElementById('addTankForm');
      wrap.style.display = 'block';
      void wrap.offsetWidth;
      wrap.style.animation = 'none';
      requestAnimationFrame(() => {
        wrap.style.animation = '';
      });
    }

    function hideAddTankForm() {
      const wrap = document.getElementById('addTankForm');
      wrap.classList.add('fgen-collapsing');
      wrap.addEventListener('animationend', () => {
        wrap.style.display = 'none';
        wrap.classList.remove('fgen-collapsing');
      }, {
        once: true
      });
    }
    document.querySelector('#addTankForm .fgen-form').addEventListener('submit', function(e) {
      e.preventDefault();
      const wrap = document.getElementById('addTankForm');
      wrap.classList.add('fgen-collapsing');
      wrap.addEventListener('animationend', () => {
        this.submit();
      }, {
        once: true
      });
    });

    const tankStatusSel = document.getElementById('tankStatusSelect');
    if (tankStatusSel) {
      tankStatusSel.addEventListener('change', e => {
        e.target.dataset.value = e.target.value;
      });
    }

    // Delete modal
    function confirmDelete(tankId, tankName) {
      document.getElementById('deleteTankId').value = tankId;
      document.getElementById('modalTankName').textContent = '"' + tankName + '"';
      document.getElementById('deleteModal').classList.add('show');
    }

    function closeDeleteModal() {
      document.getElementById('deleteModal').classList.remove('show');
    }

    function submitDelete() {
      document.getElementById('deleteTankForm').submit();
    }
    document.getElementById('deleteModal').addEventListener('click', function(e) {
      if (e.target === this) closeDeleteModal();
    });

    <?php if ($success): ?>
        (function() {
          const t = document.getElementById('toast');
          t.classList.add('show');
          setTimeout(() => t.classList.remove('show'), 3200);
        })();
    <?php endif; ?>

    setTimeout(() => {
      document.querySelectorAll('.flash').forEach(a => {
        a.style.transition = 'opacity .5s';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
      });
    }, 4000);
  </script>
</body>
<!-- ══ ADD TANK WIZARD MODAL ══ -->
<div class="wizard-bd" id="tankWizard">
  <div class="wizard-box">

    <div class="wizard-head">
      <h3 id="wizardTitle">Add New Tank</h3>
      <button class="wizard-close" onclick="closeTankWizard()">✕</button>
    </div>

    <!-- Step indicators -->
    <div class="wizard-steps">
      <div class="wstep active" id="wstep-1">
        <div class="wstep-num">1</div>
        <span>Tank Info</span>
      </div>
      <div class="wstep-line" id="wline-1"></div>
      <div class="wstep" id="wstep-2">
        <div class="wstep-num">2</div>
        <span>Sensor</span>
      </div>
      <div class="wstep-line" id="wline-2"></div>
      <div class="wstep" id="wstep-3">
        <div class="wstep-num">3</div>
        <span>Confirm</span>
      </div>
    </div>

    <form method="POST" id="tankWizardForm">
      <input type="hidden" name="add_tank" value="1">
      <input type="hidden" name="assign_sensor_id" id="wiz_sensor_id" value="">

      <!-- ── STEP 1: Tank Info ── -->
      <div class="wizard-body">
        <div class="wiz-panel active" id="wpanel-1">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="edit-field">
              <label>Tank Name *</label>
              <input type="text" name="tankname" id="wiz_tankname"
                     placeholder="e.g. Main Rooftop Tank" required>
            </div>
            <div class="edit-field">
              <label>Status</label>
              <select name="status_tank" id="wiz_status">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Maintenance">Maintenance</option>
              </select>
            </div>
            <div class="edit-field full">
              <label>Location Address *</label>
              <input type="text" name="location_add" id="wiz_location"
                     placeholder="e.g. Brgy. Poblacion, Zamboanga" required>
            </div>
            <div class="edit-field">
              <label>Current Liters</label>
              <input type="number" name="current_liters" id="wiz_current"
                     placeholder="0" min="0" value="0">
            </div>
            <div class="edit-field">
              <label>Max Capacity (L) *</label>
              <input type="number" name="max_capacity" id="wiz_capacity"
                     placeholder="5000" min="1" required>
            </div>
          </div>
        </div>

        <!-- ── STEP 2: Sensor Picker ── -->
        <div class="wiz-panel" id="wpanel-2">
          <p style="font-size:.82rem;color:#6b7280;margin-bottom:1rem">
            Select a sensor to assign to this tank. You can skip and assign later.
          </p>

          <div class="sensor-picker-grid" id="sensorPickerGrid">
            <div class="sensor-none-opt selected" id="sensorNoneOpt" onclick="selectSensor(null, this)">
              ➕ No sensor yet — assign later
            </div>
            <?php foreach ($availableSensors as $sv): ?>
            <div class="sensor-option <?= $sv['sensor_status'] === 'assigned' ? '' : '' ?>"
                 id="sensor-opt-<?= $sv['sensor_id'] ?>"
                 onclick="selectSensor(<?= $sv['sensor_id'] ?>, this)">
              <input type="radio" name="_sensor_pick" value="<?= $sv['sensor_id'] ?>">
              <div class="sensor-model"><?= htmlspecialchars($sv['model']) ?></div>
              <div class="sensor-port"><?= htmlspecialchars($sv['serial_port'] ?? '—') ?></div>
              <div>
                <span class="sensor-status-chip <?= $sv['sensor_status'] === 'available' ? 'chip-available' : 'chip-assigned' ?>">
                  <?= ucfirst($sv['sensor_status']) ?>
                </span>
              </div>
              <?php if ($sv['current_tank']): ?>
                <div class="sensor-loc">📍 Currently: <?= htmlspecialchars($sv['current_tank']) ?></div>
              <?php endif; ?>
              <div style="font-size:.68rem;color:#94a3b8;margin-top:.2rem">
                Height: <?= $sv['tank_height_cm'] ?>cm
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Mini map showing tank location -->
          <div style="margin-top:1rem">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:.4rem">
              Tank Location Preview
            </div>
            <div id="wizardMap"></div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:.4rem" id="wizMapNote">
              Enter location in Step 1 to preview on map.
            </div>
          </div>
        </div>

        <!-- ── STEP 3: Confirm ── -->
        <div class="wiz-panel" id="wpanel-3">
          <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.75rem">Review Details</div>
          <div class="confirm-row"><span class="lbl">Tank Name</span><span class="val" id="conf_name">—</span></div>
          <div class="confirm-row"><span class="lbl">Location</span><span class="val" id="conf_loc">—</span></div>
          <div class="confirm-row"><span class="lbl">Max Capacity</span><span class="val" id="conf_cap">—</span></div>
          <div class="confirm-row"><span class="lbl">Current Liters</span><span class="val" id="conf_cur">—</span></div>
          <div class="confirm-row"><span class="lbl">Status</span><span class="val" id="conf_status">—</span></div>
          <div class="confirm-row"><span class="lbl">Sensor</span><span class="val" id="conf_sensor">None (assign later)</span></div>
        </div>
      </div>

      <div class="wizard-footer">
        <button type="button" class="wiz-btn wiz-btn-ghost" id="wizBtnBack"
                onclick="wizardBack()" style="visibility:hidden">Back</button>
        <div style="font-size:.75rem;color:#9ca3af" id="wizStepLabel">Step 1 of 3</div>
        <button type="button" class="wiz-btn wiz-btn-primary" id="wizBtnNext"
                onclick="wizardNext()">Next →</button>
      </div>

    </form>
  </div>
</div>

<!-- ══ EDIT TANK MODAL ══ -->
<div class="edit-modal-bd" id="editTankModal">
  <div class="edit-modal-box">
    <div class="edit-modal-head">
      <h3>✏️ Edit Tank</h3>
      <button onclick="closeEditModal()"
              style="background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;width:28px;height:28px;border-radius:50%;font-size:1rem">✕</button>
    </div>

    <form method="POST">
      <input type="hidden" name="edit_tank" value="1">
      <input type="hidden" name="tank_id" id="edit_tank_id">

      <div class="edit-modal-body">
        <div class="edit-field">
          <label>Tank Name *</label>
          <input type="text" name="tankname" id="edit_tankname" required>
        </div>
        <div class="edit-field">
          <label>Status</label>
          <select name="status_tank" id="edit_status">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Maintenance">Maintenance</option>
          </select>
        </div>
        <div class="edit-field full">
          <label>Location Address *</label>
          <input type="text" name="location_add" id="edit_location" required>
        </div>
        <div class="edit-field">
          <label>Current Liters</label>
          <input type="number" name="current_liters" id="edit_current" min="0">
        </div>
        <div class="edit-field">
          <label>Max Capacity (L) *</label>
          <input type="number" name="max_capacity" id="edit_capacity" min="1" required>
        </div>
        <div class="edit-field full">
          <label>Reassign Sensor (optional)</label>
          <select name="assign_sensor_id" id="edit_sensor">
            <option value="">— Keep current sensor —</option>
            <?php foreach ($availableSensors as $sv): ?>
            <option value="<?= $sv['sensor_id'] ?>">
              <?= htmlspecialchars($sv['model']) ?> (<?= htmlspecialchars($sv['serial_port'] ?? '—') ?>)
              — <?= ucfirst($sv['sensor_status']) ?>
              <?= $sv['current_tank'] ? ' @ '.$sv['current_tank'] : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="edit-modal-footer">
        <button type="button" class="wiz-btn wiz-btn-ghost" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="wiz-btn wiz-btn-primary">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>
<!-- ── SENSOR REGISTRATION MODAL ── -->
<div class="sensor-modal-bd" id="sensorModal">
  <div class="sensor-modal-box">

    <div class="sensor-modal-head">
      <svg fill="none" viewBox="0 0 24 24" stroke="#60a5fa" stroke-width="2" width="22" height="22">
        <circle cx="12" cy="12" r="3" />
        <path d="M6.3 6.3a8 8 0 000 11.4M17.7 6.3a8 8 0 010 11.4" />
      </svg>
      <div>
        <h3>Register Hardware</h3>
        <p>API key is auto-generated — copy it into serial_bridge.py</p>
      </div>
      <button class="sensor-modal-close" onclick="closeSensorModal()">✕</button>
    </div>

    <!-- Success banner -->
    <?php if ($sensorSuccess): ?>
      <div style="margin:1rem 1.5rem 0;padding:.85rem 1rem;border-radius:8px;
                background:#f0fdf4;border:1px solid #bbf7d0;
                color:#16a34a;font-size:.83rem;font-weight:500;line-height:1.6">
        ✅ <?= $sensorSuccess ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="sensor-modal-body">

        <!-- Error -->
        <?php if ($sensorError): ?>
          <div class="s-flash-err full">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <?= htmlspecialchars($sensorError) ?>
          </div>
        <?php endif; ?>

        <div class="s-field">
          <label>Serial Port *</label>
          <input type="text" name="serial_port"
            placeholder="COM3  or  /dev/ttyUSB0" required
            value="<?= htmlspecialchars($_POST['serial_port'] ?? '') ?>">
          <span class="s-hint">Windows: COM3, COM4 · Linux: /dev/ttyUSB0</span>
        </div>

        <div class="s-field">
          <label>Baud Rate</label>
          <select name="baud_rate">
            <option value="9600" selected>9600 (default)</option>
            <option value="115200">115200</option>
            <option value="57600">57600</option>
            <option value="4800">4800</option>
          </select>
        </div>

        <div class="s-field">
          <label>Tank Height (cm) *</label>
          <input type="number" name="tank_height_cm"
            placeholder="100" step="0.5" min="1" required
            value="<?= htmlspecialchars($_POST['tank_height_cm'] ?? '') ?>">
          <span class="s-hint">Full internal height of the tank</span>
        </div>

        <div class="s-field">
          <label>Mount Offset (cm)</label>
          <input type="number" name="mount_offset_cm"
            value="<?= htmlspecialchars($_POST['mount_offset_cm'] ?? '5') ?>"
            step="0.5" min="0">
          <span class="s-hint">Gap from sensor face to max water level</span>
        </div>

        <div class="s-field">
          <label>Sensor Type</label>
          <input type="text" name="sensor_type"
            value="<?= htmlspecialchars($_POST['sensor_type'] ?? 'Water Level') ?>">
        </div>

        <div class="s-field">
          <label>Model</label>
          <input type="text" name="model"
            value="<?= htmlspecialchars($_POST['model'] ?? 'HC-SR04 Ultrasonic') ?>">
        </div>

        <div class="s-field full">
          <label>Assign to Tank (optional)</label>
          <select name="assign_tank_id">
            <option value="">— Add to available pool first —</option>
            <?php foreach ($allTanksForSensor as $t): ?>
              <option value="<?= $t['tank_id'] ?>"
                <?= $t['has_sensor'] ? 'style="color:#d97706"' : '' ?>>
                <?= htmlspecialchars($t['tankname']) ?>
                <?= $t['has_sensor'] ? ' (has sensor)' : '' ?>
                — <?= htmlspecialchars($t['location_add']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="s-hint">You can assign later from the Sensors page.</span>
        </div>

        <div class="s-field full">
          <label>Notes</label>
          <textarea name="notes"
            placeholder="e.g. Installed on north side rooftop tank, cable 3m"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="s-field full"
          style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.25rem">
          <button type="button" class="s-btn s-btn-ghost"
            onclick="closeSensorModal()">Cancel</button>
          <button type="submit" name="register_sensor" class="s-btn s-btn-primary">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"
              stroke-width="2" width="14" height="14">
              <polyline points="20 6 9 17 4 12" />
            </svg>
            Register Sensor
          </button>
        </div>

      </div>
    </form>
  </div>
</div>

</html>