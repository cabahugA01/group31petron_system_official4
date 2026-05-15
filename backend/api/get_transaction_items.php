<?php
/**
 * GET /backend/api/get_transaction_items.php?id=<merchandise_transaction_id>
 * Returns itemized list for a merchandise transaction (manager view details).
 */
header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit;
}

try {
    // Verify the transaction belongs to this station
    $stmt = $pdo->prepare("
        SELECT mt.id, mt.transaction_id, mt.customer_name, mt.payment_method,
               mt.total_amount, mt.amount_tendered, mt.change_amount,
               mt.validation_status, mt.rejection_reason, mt.created_at,
               mt.shift_period, mt.shift_name,
               u.name AS staff_name,
               vm.name AS validated_by_name,
               mt.validated_at
        FROM merchandise_transactions mt
        LEFT JOIN users u  ON mt.staff_id    = u.id
        LEFT JOIN users vm ON mt.validated_by = vm.id
        WHERE mt.id = ? AND mt.station_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $station_id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }

    // Fetch items
    $stmt2 = $pdo->prepare("
        SELECT mti.product_name, mti.category, mti.size_variant,
               mti.quantity, mti.unit_price, mti.subtotal
        FROM merchandise_transaction_items mti
        WHERE mti.transaction_id = ?
        ORDER BY mti.id ASC
    ");
    $stmt2->execute([$txn['id']]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'txn'     => $txn,
        'items'   => $items,
    ]);

} catch (Exception $e) {
    error_log('get_transaction_items error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
