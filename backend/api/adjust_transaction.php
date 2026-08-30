<?php
// Robust adjust transaction API - Handles both Sales and Fuel Transactions
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

// Start session if not already started
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../security_helpers.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../transaction_schema_fix.php';

// Authoritative Security Enforcement: Re-verify DB user, 5-min timeout, RBAC check
$me = enforce_server_security('approve_transactions', null, false);

// Set JSON response header
header('Content-Type: application/json');

$role = role_key($me['role'] ?? '');

if (!is_manager_or_above()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Manager access required. Current role: ' . $role]);
    exit;
}

$station_id = (int)($me['station_id'] ?? 1);

// Handle case where station_id is null
if (!$station_id) {
    // Try to get station_id from session directly
    $station_id = $_SESSION['user']['station_id'] ?? null;
}

// If still null, use a default or throw error
if (!$station_id) {
    echo json_encode(['success' => false, 'message' => 'Station ID not found. Please contact administrator.']);
    exit;
}

$input = file_get_contents('php://input');
if ($input === false) {
    echo json_encode(['success' => false, 'message' => 'No input data received']);
    exit;
}

// Log input for debugging
error_log("Adjust Transaction Input: " . $input);

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()]);
    exit;
}

// Log parsed data for debugging
error_log("Adjust Transaction Parsed Data: " . print_r($data, true));

if (!isset($data['transaction_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transaction ID required']);
    exit;
}

$transaction_id = $data['transaction_id'];
$manager_id = $me['id'];

try {
    global $pdo;
    
    // Ensure PDO is available
    if (!isset($pdo)) {
        throw new Exception('Database connection not available');
    }
    
    // Ensure required columns exist (fuel_transactions only)
    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Pending'");
    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS manager_id INT");
    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS action VARCHAR(50)");
    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS old_value TEXT");
    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS new_value TEXT");
    
    // Ensure audit trail table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_trail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255) NOT NULL,
            manager_id INT NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            old_value TEXT,
            new_value TEXT,
            station_id INT NOT NULL,
            INDEX idx_transaction (transaction_id),
            INDEX idx_manager (manager_id),
            INDEX idx_timestamp (timestamp)
        )
    ");
    
    $updated = false;
    $transaction_type = '';
    $old_value = '';
    $new_value = '';
    
    $fuel_row = null;
    $merch_row = null;

    if (is_numeric($transaction_id)) {
        $stmt = $pdo->prepare("SELECT id, status, total_amount, liters_sold FROM fuel_transactions WHERE id = ? AND station_id = ?");
        $stmt->execute([$transaction_id, $station_id]);
        $fuel_row = $stmt->fetch();

        if (!$fuel_row) {
            $stmt = $pdo->prepare("SELECT id, validation_status AS status, total_amount FROM merchandise_transactions WHERE id = ? AND station_id = ?");
            $stmt->execute([$transaction_id, $station_id]);
            $merch_row = $stmt->fetch();
        }
    } else {
        $stmt = $pdo->prepare("SELECT id, status, total_amount, liters_sold FROM fuel_transactions WHERE transaction_id = ? AND station_id = ?");
        $stmt->execute([$transaction_id, $station_id]);
        $fuel_row = $stmt->fetch();
    }

    if ($fuel_row) {
        $status = strtolower($fuel_row['status'] ?? '');
        if (!empty($status) && !in_array($status, ['pending validation', 'pending', ''])) {
            echo json_encode(['success' => false, 'message' => 'Transaction already processed: ' . ucfirst($fuel_row['status'])]);
            exit;
        }
        $old_value = json_encode(['total_amount' => $fuel_row['total_amount'], 'liters_sold' => $fuel_row['liters_sold'], 'status' => $fuel_row['status']]);
        $stmt = $pdo->prepare("UPDATE fuel_transactions SET status = 'Adjusted', validated_by = ?, validated_at = NOW() WHERE id = ? AND station_id = ?");
        $stmt->execute([$manager_id, $fuel_row['id'], $station_id]);
        $updated = true;
        $transaction_type = 'Fuel';
        $db_txn_id = $fuel_row['id'];
        $new_value = 'Pending variance investigation';
    } elseif ($merch_row) {
        $status = strtolower($merch_row['status'] ?? '');
        if (!empty($status) && !in_array($status, ['pending validation', 'pending', ''])) {
            echo json_encode(['success' => false, 'message' => 'Transaction already processed: ' . ucfirst($merch_row['status'])]);
            exit;
        }
        $old_value = json_encode(['total_amount' => $merch_row['total_amount'], 'status' => $merch_row['status']]);
        $stmt = $pdo->prepare("UPDATE merchandise_transactions SET validation_status = 'Adjusted', validated_by = ?, validated_at = NOW() WHERE id = ? AND station_id = ?");
        $stmt->execute([$manager_id, $merch_row['id'], $station_id]);
        $updated = true;
        $transaction_type = 'Merchandise';
        $db_txn_id = $merch_row['id'];
        $new_value = 'Pending variance investigation';
    }

    if (!$updated) {
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        exit;
    }
    
    // Log to audit trail
    $audit_stmt = $pdo->prepare("
        INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id) 
        VALUES (?, ?, 'Adjust', ?, ?, ?)
    ");
    $audit_stmt->execute([$db_txn_id, $manager_id, $old_value, $new_value, $station_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => $transaction_type . ' transaction marked for variance investigation',
        'transaction_type' => $transaction_type
    ]);
    
} catch (Exception $e) {
    // Log the actual error for debugging
    error_log("Adjust Transaction Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

