<?php
/**
 * Backend: Process Fuel Reading Review
 * Handles approve/reject actions from manager review modal
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

header('Content-Type: application/json');

require_login();
$me = current_user();
$userRole = strtolower(trim($me['role'] ?? ''));
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);

if (!$isManager) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Only managers can review readings.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action !== 'review_reading') {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid reading ID.']);
    exit;
}

if (!in_array($status, ['Verified', 'Rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}

if ($status === 'Rejected' && empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
    exit;
}

try {
    // Get station_id for the reading
    $stmt = $pdo->prepare("SELECT station_id, status, sales_liters, pump_id FROM fuel_daily_readings WHERE id = ?");
    $stmt->execute([$id]);
    $reading = $stmt->fetch();
    
    if (!$reading) {
        echo json_encode(['success' => false, 'message' => 'Reading not found.']);
        exit;
    }
    
    if (!in_array($reading['status'], ['Pending Review', 'Pending'])) {
        echo json_encode(['success' => false, 'message' => 'This reading has already been processed.']);
        exit;
    }
    
    // Get fuel type from fuel_pumps table using pump_id
    $fuel_type_name = '';
    if ($reading['pump_id']) {
        $stmt = $pdo->prepare("
            SELECT ft.name 
            FROM fuel_pumps fp 
            LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id 
            WHERE fp.id = ?
        ");
        $stmt->execute([$reading['pump_id']]);
        $fuel_type_name = $stmt->fetchColumn();
    }
    
    // Build notes content
    $notesContent = '';
    $stockDeducted = false;
    $stockBefore = null;
    $stockAfter = null;
    
    if ($status === 'Rejected') {
        $notesContent = "[REJECTED] Reason: " . $reason;
        if (!empty($notes)) {
            $notesContent .= "\nNotes: " . $notes;
        }
        $notesContent .= "\nReviewed by: " . $me['name'] . " on " . date('Y-m-d H:i:s');
    } else {
        $notesContent = "[VERIFIED] Verified by " . $me['name'] . " on " . date('Y-m-d H:i:s');
        if (!empty($notes)) {
            $notesContent .= "\nNotes: " . $notes;
        }
        
        // DEDUCT STOCK ON VERIFICATION
        if ($status === 'Verified' && $reading['sales_liters'] > 0 && $fuel_type_name) {
            $station_id = $reading['station_id'];
            
            // Check if stock already deducted for this reading
            $stmt = $pdo->prepare("
                SELECT id FROM inventory_transactions 
                WHERE reference_type = 'fuel_daily_readings' AND reference_id = ? AND transaction_type = 'pump_reading'
            ");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                // Get current stock level by fuel type name
                $stmt = $pdo->prepare("
                    SELECT si.id as inventory_id, si.stock_level
                    FROM station_inventory si
                    INNER JOIN products p ON si.product_id = p.id
                    WHERE si.station_id = ? 
                      AND p.name = ? 
                      AND p.type_id = (SELECT id FROM product_types WHERE name = 'fuel')
                ");
                $stmt->execute([$station_id, $fuel_type_name]);
                $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inventory && $inventory['stock_level'] >= $reading['sales_liters']) {
                    $stockBefore = $inventory['stock_level'];
                    $stockAfter = $stockBefore - $reading['sales_liters'];
                    
                    // Update stock level
                    $stmt = $pdo->prepare("UPDATE station_inventory SET stock_level = ? WHERE id = ?");
                    $stmt->execute([$stockAfter, $inventory['inventory_id']]);
                    
                    // Record transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_transactions 
                        (station_id, product_id, transaction_type, quantity, reference_type, reference_id, created_by, notes, created_at)
                        VALUES (?, (SELECT product_id FROM station_inventory WHERE id = ?), 'pump_reading', ?, 'fuel_daily_readings', ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $station_id, 
                        $inventory['inventory_id'],
                        -$reading['sales_liters'],
                        $id,
                        $me['id'],
                        "Stock deducted after shift reading verification - Sales: {$reading['sales_liters']} L"
                    ]);
                    
                    $stockDeducted = true;
                }
            }
        }
    }
    
    // Update the reading status
    $stmt = $pdo->prepare("UPDATE fuel_daily_readings SET status = ?, notes = CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id = ?");
    $stmt->execute([$status, $notesContent, $id]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'No changes made.']);
        exit;
    }
    
    // Log the activity
    $details = $status === 'Verified' 
        ? "Verified pump reading #$id" 
        : "Rejected pump reading #$id - Reason: $reason";
    
    log_activity($pdo, $me['id'], 'Review Pump Reading', $details, 'fuel_management');
    
    $message = $status === 'Verified' 
        ? 'Pump reading verified successfully!' 
        : 'Pump reading rejected successfully!';
    
    if ($stockDeducted) {
        $message .= " Stock deducted: {$stockBefore}L → {$stockAfter}L ({$fuel_type_name})";
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
