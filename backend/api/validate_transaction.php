<?php
// API endpoint for validating transactions
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();
header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict to managers only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$station_id = user_station_id();
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['transaction_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transaction ID required']);
    exit;
}

$transaction_id = (int)$data['transaction_id'];

try {
    $pdo = getPDO();
    
    // Update transaction status to Completed
    $stmt = $pdo->prepare("UPDATE sales SET status = 'Completed', updated_at = NOW() WHERE id = ? AND station_id = ?");
    $stmt->execute([$transaction_id, $station_id]);
    
    if ($stmt->rowCount() > 0) {
        // Log the validation action
        $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Transaction Validated', ?, NOW())");
        $stmt->execute([$station_id, $me['id'], "Transaction #$transaction_id validated by manager"]);
        
        echo json_encode(['success' => true, 'message' => 'Transaction validated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Transaction not found or already processed']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
