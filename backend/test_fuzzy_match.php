<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "Testing Receipt Fuzzy Matching\n";
echo str_repeat("=", 60) . "\n\n";

$test_ids = [
    'MERCH202612535096',    // Truncated (missing '3' at end)
    'MERCH2026125350963',   // Full correct ID
    '1',                    // Numeric ID
];

foreach ($test_ids as $id) {
    echo "Searching for: $id\n";
    
    $stmt = $pdo->prepare("
        SELECT transaction_id, customer_name, total_amount
        FROM merchandise_transactions 
        WHERE transaction_id = ? 
           OR transaction_id LIKE ? 
           OR id = ? 
        LIMIT 1
    ");
    
    $numeric_id = is_numeric($id) ? (int)$id : 0;
    $stmt->execute([$id, $id . '%', $numeric_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "  ✓ FOUND: {$result['transaction_id']}\n";
        echo "    Customer: {$result['customer_name']}\n";
        echo "    Amount: ₱" . number_format($result['total_amount'], 2) . "\n";
    } else {
        echo "  ✗ NOT FOUND\n";
    }
    echo "\n";
}

echo str_repeat("=", 60) . "\n";
echo "Test completed!\n";
?>
