<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "Checking database transactions...\n\n";

try {
    // Check merchandise transactions
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM merchandise_transactions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total merchandise transactions: " . $result['count'] . "\n";
    
    if ($result['count'] > 0) {
        $stmt2 = $pdo->query("
            SELECT id, transaction_id, customer_name, total_amount, created_at 
            FROM merchandise_transactions 
            ORDER BY id DESC LIMIT 5
        ");
        echo "\nLatest 5 transactions:\n";
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            echo "  ID: " . $row['id'] . 
                 ", TXN: " . $row['transaction_id'] . 
                 ", Customer: " . $row['customer_name'] . 
                 ", Amount: ₱" . number_format($row['total_amount'], 2) . 
                 ", Date: " . $row['created_at'] . "\n";
        }
    } else {
        echo "\nNo merchandise transactions found. Database is clean.\n";
        echo "Please create a new transaction from the Staff Transactions Hub.\n";
    }
    
    // Check job orders
    echo "\n" . str_repeat("-", 60) . "\n\n";
    $stmt3 = $pdo->query("SELECT COUNT(*) as count FROM job_orders");
    $result3 = $stmt3->fetch(PDO::FETCH_ASSOC);
    echo "Total job orders: " . $result3['count'] . "\n";
    
    if ($result3['count'] > 0) {
        $stmt4 = $pdo->query("
            SELECT id, job_order_id, customer_name, total_amount, status, created_at 
            FROM job_orders 
            ORDER BY id DESC LIMIT 5
        ");
        echo "\nLatest 5 job orders:\n";
        while ($row = $stmt4->fetch(PDO::FETCH_ASSOC)) {
            echo "  ID: " . $row['id'] . 
                 ", JO: " . $row['job_order_id'] . 
                 ", Customer: " . $row['customer_name'] . 
                 ", Amount: ₱" . number_format($row['total_amount'] ?? 0, 2) . 
                 ", Status: " . $row['status'] . 
                 ", Date: " . $row['created_at'] . "\n";
        }
    } else {
        echo "\nNo job orders found. All mechanics are FREE.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
