<?php
/**
 * Get Merchandise Transaction Details
 * 
 * Fetches complete merchandise transaction details including items
 * for the View modal in Staff Merchandise History
 * 
 * Parameters:
 * - $_GET['id'] - transaction_id (string like MERCH_20260419...)
 * 
 * Returns: JSON with transaction details and items array
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';

// Verify login
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get transaction ID
$txn_id = trim($_GET['id'] ?? '');

if (empty($txn_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction ID']);
    exit;
}

try {
    // Fetch main transaction data
    $stmt = $pdo->prepare("
        SELECT 
            mt.id,
            mt.transaction_id,
            mt.customer_name,
            mt.total_amount,
            mt.payment_method,
            mt.payment_status,
            mt.validation_status,
            mt.amount_paid,
            mt.balance_due,
            mt.subtotal_amount,
            mt.vat_amount,
            mt.remarks,
            mt.shift_name,
            mt.shift_period,
            mt.transaction_date,
            mt.created_at,
            u.name AS staff_name
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.user_id = mt.staff_id
        WHERE mt.transaction_id = ?
        LIMIT 1
    ");
    $stmt->execute([$txn_id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$txn) {
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }
    
    // Fetch transaction items
    $stmt_items = $pdo->prepare("
        SELECT 
            product_name,
            category,
            size_variant,
            quantity,
            unit_price,
            subtotal,
            item_type
        FROM merchandise_transaction_items
        WHERE transaction_id = ?
        ORDER BY id ASC
    ");
    $stmt_items->execute([$txn['id']]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals if not stored
    $total = (float)($txn['total_amount'] ?? 0);
    $paid = (float)($txn['amount_paid'] ?? 0);
    $balance = (float)($txn['balance_due'] ?? max(0, $total - $paid));
    
    // Calculate subtotal and VAT
    if (!empty($txn['subtotal_amount']) && (float)$txn['subtotal_amount'] > 0) {
        $subtotal = (float)$txn['subtotal_amount'];
        $vat = !empty($txn['vat_amount']) ? (float)$txn['vat_amount'] : round($subtotal * 0.12, 2);
    } else {
        // Calculate from items
        $items_sum = 0;
        foreach ($items as $item) {
            $items_sum += (float)($item['subtotal'] ?? 0);
        }
        $subtotal = $items_sum > 0 ? $items_sum : ($total > 0 ? round($total / 1.12, 2) : 0);
        $vat = round($subtotal * 0.12, 2);
    }
    
    // Payment status badge
    $pay_status_raw = strtolower(trim($txn['payment_status'] ?? 'pending payment'));
    $pay_badge_colors = [
        'paid' => '#16a34a',
        'partial payment' => '#d97706',
        'partial' => '#d97706',
        'pending payment' => '#dc2626',
        'pending' => '#dc2626',
        'credit' => '#7c3aed'
    ];
    $pay_badge_color = $pay_badge_colors[$pay_status_raw] ?? '#6b7280';
    $pay_badge = '<span style="display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;color:#fff;background:' . $pay_badge_color . ';">' . strtoupper($txn['payment_status'] ?? 'Pending') . '</span>';
    
    // Validation status badge
    $val_status_raw = strtolower(trim($txn['validation_status'] ?? 'pending'));
    $val_badge_colors = [
        'approved' => '#16a34a',
        'validated' => '#16a34a',
        'pending' => '#d97706',
        'pending validation' => '#d97706',
        'rejected' => '#dc2626',
        'adjusted' => '#7c3aed'
    ];
    $val_badge_color = $val_badge_colors[$val_status_raw] ?? '#6b7280';
    $val_badge = '<span style="display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;color:#fff;background:' . $val_badge_color . ';">' . strtoupper($txn['validation_status'] ?? 'Pending') . '</span>';
    
    // Format response
    $response = [
        'success' => true,
        'transaction' => [
            'id' => $txn['id'],
            'transaction_id' => $txn['transaction_id'],
            'customer_name' => $txn['customer_name'] ?: 'Walk-in Customer',
            'total_amount' => $total,
            'payment_method' => $txn['payment_method'] ?: 'Cash',
            'payment_status' => $txn['payment_status'] ?: 'Pending Payment',
            'payment_status_badge' => $pay_badge,
            'validation_status' => $txn['validation_status'] ?: 'Pending',
            'validation_status_badge' => $val_badge,
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'subtotal_display' => '₱' . number_format($subtotal, 2),
            'vat_display' => '₱' . number_format($vat, 2),
            'total_display' => '₱' . number_format($total, 2),
            'paid_display' => '₱' . number_format($paid, 2),
            'balance_display' => $balance > 0 ? '₱' . number_format($balance, 2) : '—',
            'remarks' => $txn['remarks'] ?: null,
            'shift_name' => $txn['shift_name'],
            'shift_period' => $txn['shift_period'],
            'transaction_date' => date('M j, Y h:i A', strtotime($txn['transaction_date'] ?: $txn['created_at'])),
            'staff_name' => $txn['staff_name'] ?: 'N/A',
            'items' => $items
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("get_merchandise_transaction_details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
