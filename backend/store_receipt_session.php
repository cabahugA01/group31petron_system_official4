<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit;
}

// Get POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Store receipt data in session
$_SESSION['receipt_data'] = $data;

// Add additional data
$_SESSION['receipt_data']['date'] = date('Y-m-d');
$_SESSION['receipt_data']['time'] = date('H:i:s');
$_SESSION['receipt_data']['staff_name'] = current_user()['name'] ?? 'Staff';
$_SESSION['receipt_data']['staff_id'] = current_user()['id'] ?? $_SESSION['user_id'];

// Log the receipt generation
try {
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $_SESSION['user_id'],
        'Receipt Generated',
        json_encode(['transaction_id' => $data['transaction_id'], 'total_amount' => $data['total_amount']]),
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
} catch (Exception $e) {
    // Continue even if audit fails
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Receipt data stored successfully']);
?>
