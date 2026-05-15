<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

header('Content-Type: application/json');

// Start session for user authentication
session_start();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    // Validate required fields
    $required_fields = ['action', 'details', 'page'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }
    
    $user_id = $_SESSION['user_id'];
    $station_id = user_station_id();
    
    if (!$station_id) {
        throw new Exception('Station not found');
    }
    
    // Log to audit trail
    $stmt = $pdo->prepare("
        INSERT INTO staff_audit_log (
            staff_id,
            station_id,
            action,
            details,
            reference_id,
            page,
            section,
            ip_address,
            user_agent,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $user_id,
        $station_id,
        $input['action'],
        is_string($input['details']) ? $input['details'] : json_encode($input['details']),
        $input['reference_id'] ?? null,
        $input['page'],
        $input['section'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Audit trail logged successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
