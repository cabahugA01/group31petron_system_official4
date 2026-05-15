<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/lib.php';

session_start();
require_login();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['service_id']) || !isset($data['new_status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$allowed_statuses = ['Pending', 'In Progress', 'Completed'];

if (!in_array($data['new_status'], $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // 1. Get current status
    $stmt = $pdo->prepare("SELECT status FROM service_entries WHERE id = ?");
    $stmt->execute([$data['service_id']]);
    $current = $stmt->fetch();
    
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        exit;
    }
    
    // 2. Update status
    $update_fields = ['status = ?', 'updated_at = NOW()'];
    $params = [$data['new_status'], $data['service_id']];
    
    if ($data['new_status'] === 'In Progress') {
        $update_fields[] = 'started_at = NOW()';
    } elseif ($data['new_status'] === 'Completed') {
        $update_fields[] = 'completed_at = NOW()';
    }
    
    $stmt = $pdo->prepare("
        UPDATE service_entries 
        SET " . implode(', ', $update_fields) . "
        WHERE id = ?
    ");
    $stmt->execute([$data['new_status'], $data['service_id']]);
    
    // 3. Log status change
    $stmt = $pdo->prepare("
        INSERT INTO service_status_history (service_id, old_status, new_status, changed_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['service_id'],
        $current['status'],
        $data['new_status'],
        $data['staff_id']
    ]);
    
    // 4. Log activity
    log_activity($pdo, $data['staff_id'], 'Update Service Status', 
        "Changed service #{$data['service_id']} from {$current['status']} to {$data['new_status']}", 
        'pos_services');
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>