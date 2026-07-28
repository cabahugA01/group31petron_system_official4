<?php
/**
 * Save Fuel Reading Adjustment (Manager Only)
 * POST: tank_id, fuel_type, new_reading, calibration, reason, remarks
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Manager only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied. Manager access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$fuel_type   = trim($_POST['fuel_type']   ?? '');
$new_reading = trim($_POST['new_reading'] ?? '');
$calibration = trim($_POST['calibration'] ?? '0');
$reason      = trim($_POST['reason']      ?? '');
$remarks     = trim($_POST['remarks']     ?? '');

// Validate
if (empty($fuel_type)) {
    echo json_encode(['success' => false, 'error' => 'Fuel type is required.']);
    exit;
}
if ($new_reading === '' || !is_numeric($new_reading)) {
    echo json_encode(['success' => false, 'error' => 'New meter reading is required and must be a number.']);
    exit;
}
if (empty($reason)) {
    $reason = !empty($remarks) ? $remarks : 'Meter reading adjustment by Manager';
}

$new_reading = (float) $new_reading;
$calibration = (float) $calibration;

try {
    // Get current fuel inventory record with flexible matching
    $stmt = $pdo->prepare(
        "SELECT id, current_level, fuel_type_id, fuel_type FROM fuel_inventory 
         WHERE station_id = ? AND (
             LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR
             LOWER(TRIM(fuel_type)) LIKE CONCAT('%', LOWER(TRIM(?)), '%') OR
             LOWER(TRIM(?)) LIKE CONCAT('%', LOWER(TRIM(fuel_type)), '%')
         ) 
         LIMIT 1"
    );
    $stmt->execute([$station_id, $fuel_type, $fuel_type, $fuel_type]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        $stmt2 = $pdo->prepare("SELECT id, current_level, fuel_type_id, fuel_type FROM fuel_inventory WHERE station_id = ? LIMIT 1");
        $stmt2->execute([$station_id]);
        $inv = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$inv) {
        echo json_encode(['success' => false, 'error' => 'Fuel inventory record not found for: ' . htmlspecialchars($fuel_type)]);
        exit;
    }

    $old_level = (float)($inv['current_level'] ?? 0);
    $variance  = round($new_reading - $old_level, 4);

    $pdo->beginTransaction();

    // Update fuel_inventory current level
    $upd = $pdo->prepare(
        "UPDATE fuel_inventory 
         SET current_level = ?, last_updated = NOW() 
         WHERE id = ? AND station_id = ?"
    );
    $upd->execute([$new_reading, $inv['id'], $station_id]);

    // Log to fuel_adjustments table
    $ins = $pdo->prepare(
        "INSERT INTO fuel_adjustments 
         (station_id, fuel_type_id, adjustment_date, adjustment_type, liters, reason, notes, user_id, status, created_at)
         VALUES (?, ?, NOW(), 'Meter Reading Adjustment', ?, ?, ?, ?, 'Approved', NOW())"
    );
    $ins->execute([
        $station_id,
        $inv['fuel_type_id'],
        $variance,
        $reason,
        $remarks,
        $me['id']
    ]);

    // Activity log
    $display_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Manager');
    log_activity(
        $pdo,
        $me['id'],
        'Fuel Reading Adjusted',
        "Adjusted {$fuel_type} reading from {$old_level}L to {$new_reading}L (variance: {$variance}L). Reason: {$reason}",
        'fuel_management'
    );

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => "Fuel reading for {$fuel_type} updated successfully.",
        'new_level'   => $new_reading,
        'old_level'   => $old_level,
        'variance'    => $variance
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
