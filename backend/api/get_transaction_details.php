<?php
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'Invalid transaction ID']);
    exit;
}

$transaction_id = (int)$_GET['id'];

try {
    // Get transaction details
    $stmt = $pdo->prepare("SELECT s.*, u.name as staff_name FROM sales s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }

    // Get sale items
    $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $itemsStmt->execute([$transaction_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get audit logs
    $logsStmt = $pdo->prepare("SELECT * FROM activity_logs WHERE details LIKE ? ORDER BY created_at DESC");
    $logsStmt->execute(["%#$transaction_id%"]);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'id' => $transaction['id'],
        'customer' => $transaction['customer'],
        'staff_name' => $transaction['staff_name'],
        'payment_method' => $transaction['payment_method'],
        'status' => $transaction['status'],
        'created_at' => $transaction['created_at'],
        'total' => $transaction['total'],
        'items' => $items,
        'logs' => $logs
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
