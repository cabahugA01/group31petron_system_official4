<?php
/**
 * Test: Void Reason & Remarks Auto-Fetch Verification
 * tests/test_void_auto_fetch.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user'] = ['id' => 1, 'role' => 'manager', 'station_id' => 1];

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "=== Testing Void Reason & Remarks Auto-Fetch ===\n";

// 1. Get sample merchandise transaction
$stmt = $pdo->query("SELECT id, transaction_id, staff_id FROM merchandise_transactions LIMIT 1");
$sample_txn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sample_txn) {
    echo "[!] No merchandise transactions found to test.\n";
    exit;
}

$txn_id = $sample_txn['id'];
$txn_str = $sample_txn['transaction_id'];
$staff_id = (int)$sample_txn['staff_id'];

echo "Found transaction ID: {$txn_id} ({$txn_str})\n";

// 2. Set sample void request in transaction_requests
$test_reason = "Staff Encoding Error - Wrong Quantity";
$test_remarks = "Customer ordered 2 bottles of engine oil but 4 bottles were accidentally entered by cashier.";

$pdo->prepare("DELETE FROM transaction_requests WHERE transaction_id = ? OR transaction_id = ?")->execute([$txn_id, $txn_str]);

$ins = $pdo->prepare("
    INSERT INTO transaction_requests 
        (station_id, transaction_id, record_source, request_type, request_reason, remarks, requested_by, status, requested_at)
    VALUES (1, ?, 'merchandise_transactions', 'Void', ?, ?, ?, 'Pending', NOW())
");
$ins->execute([$txn_id, $test_reason, $test_remarks, $staff_id]);
$req_id = $pdo->lastInsertId();

echo "[+] Inserted sample pending void request ID: {$req_id}\n";

// 3. Test get_transaction_details.php backend response
$_GET['type'] = 'merchandise_transactions';
$_GET['id'] = $txn_id;

ob_start();
require __DIR__ . '/../backend/get_transaction_details.php';
$json_out = ob_get_clean();

$data = json_decode($json_out, true);

if (!$data || !($data['success'] ?? false)) {
    echo "[FAIL] get_transaction_details.php returned invalid response: $json_out\n";
    exit(1);
}

echo "\nBackend Response Check:\n";
echo "  - void_reason: " . ($data['void_reason'] ?? 'NULL') . "\n";
echo "  - staff_remarks: " . ($data['staff_remarks'] ?? 'NULL') . "\n";
echo "  - pending_void_reason: " . ($data['pending_void_reason'] ?? 'NULL') . "\n";
echo "  - pending_void_remarks: " . ($data['pending_void_remarks'] ?? 'NULL') . "\n";
echo "  - pending_void_staff_name: " . ($data['pending_void_staff_name'] ?? 'NULL') . "\n";

$pass_reason = (($data['void_reason'] ?? '') === $test_reason || ($data['pending_void_reason'] ?? '') === $test_reason);
$pass_remarks = (($data['staff_remarks'] ?? '') === $test_remarks || ($data['pending_void_remarks'] ?? '') === $test_remarks);

if ($pass_reason && $pass_remarks) {
    echo "\n[PASS] Void reason & remarks successfully auto-fetched by backend!\n";
} else {
    echo "\n[FAIL] Void reason or remarks did not match expected values.\n";
    exit(1);
}

// Clean test record from transaction_requests
$pdo->prepare("DELETE FROM transaction_requests WHERE id = ?")->execute([$req_id]);
echo "[+] Cleaned test record from transaction_requests.\n";
