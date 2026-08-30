<?php
// Robust approve transaction API - Handles both Sales and Fuel Transactions
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
header('Content-Type: application/json');

$role = role_key($me['role'] ?? '');

// Restrict to managers only
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

// Get input data with error handling
$input = file_get_contents('php://input');
if ($input === false) {
    echo json_encode(['success' => false, 'message' => 'No input data received']);
    exit;
}

// Log input for debugging
error_log("Approve Transaction Input: " . $input);

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()]);
    exit;
}

// Log parsed data for debugging
error_log("Approve Transaction Parsed Data: " . print_r($data, true));

if (!isset($data['transaction_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transaction ID required']);
    exit;
}

$transaction_id = $data['transaction_id'];
$manager_id = $me['id'];

try {
    global $pdo;
    if (!isset($pdo)) throw new Exception('Database connection not available');

    $updated = false;
    $transaction_type = '';

    // Try fuel first by numeric id, then merchandise
    $fuel_row = null;
    $merch_row = null;

    if (is_numeric($transaction_id)) {
        $stmt = $pdo->prepare("SELECT id, COALESCE(status,'') AS status FROM fuel_transactions WHERE id = ? AND station_id = ?");
        $stmt->execute([$transaction_id, $station_id]);
        $fuel_row = $stmt->fetch();

        if (!$fuel_row) {
            $stmt = $pdo->prepare("SELECT id, COALESCE(validation_status,'') AS status FROM merchandise_transactions WHERE id = ? AND station_id = ?");
            $stmt->execute([$transaction_id, $station_id]);
            $merch_row = $stmt->fetch();
        }
    } else {
        $stmt = $pdo->prepare("SELECT id, COALESCE(status,'') AS status FROM fuel_transactions WHERE transaction_id = ? AND station_id = ?");
        $stmt->execute([$transaction_id, $station_id]);
        $fuel_row = $stmt->fetch();
    }

    if ($fuel_row) {
        $status = strtolower($fuel_row['status']);
        if (!empty($status) && !in_array($status, ['pending validation', 'pending', ''])) {
            echo json_encode(['success' => false, 'message' => 'Transaction already processed: ' . ucfirst($fuel_row['status'])]);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE fuel_transactions SET status = 'Verified', validated_by = ?, validated_at = NOW() WHERE id = ? AND station_id = ?");
        $stmt->execute([$manager_id, $fuel_row['id'], $station_id]);
        $updated = $stmt->rowCount() > 0;
        $transaction_type = 'Fuel';
        $db_txn_id = $fuel_row['id'];
    } elseif ($merch_row) {
        $status = strtolower($merch_row['status']);
        if (!empty($status) && !in_array($status, ['pending validation', 'pending', ''])) {
            echo json_encode(['success' => false, 'message' => 'Transaction already processed: ' . ucfirst($merch_row['status'])]);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE merchandise_transactions SET validation_status = 'Approved', validated_by = ?, validated_at = NOW(), updated_at = NOW() WHERE id = ? AND station_id = ?");
        $stmt->execute([$manager_id, $merch_row['id'], $station_id]);
        $updated = $stmt->rowCount() > 0;
        $transaction_type = 'Merchandise';
        $db_txn_id = $merch_row['id'];
    }

    if (!$updated) {
        echo json_encode(['success' => false, 'message' => 'Transaction not found or already processed']);
        exit;
    }

    // Log to audit trail (silent — never break main flow)
    try {
        $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, station_id) VALUES (?, ?, 'Approve', ?)")
            ->execute([$db_txn_id, $manager_id, $station_id]);
    } catch (Exception $ae) {}

    echo json_encode([
        'success' => true,
        'message' => $transaction_type . ' transaction approved successfully',
        'transaction_type' => $transaction_type
    ]);

} catch (Exception $e) {
    error_log("Approve Transaction Error: " . $e->getMessage());
    error_log("Approve Transaction Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

