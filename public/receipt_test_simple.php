<?php
// SUPER SIMPLE RECEIPT TEST
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db_connect.php';

$id = $_GET['id'] ?? 'MERCH2026125350963';

echo "<!DOCTYPE html><html><head><title>Receipt Test</title></head><body>";
echo "<h2>Receipt Debug Test</h2>";
echo "<p>Searching for: <strong>$id</strong></p><hr>";

try {
    // SIMPLEST POSSIBLE QUERY
    $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$txn) {
        die("<p style='color:red;'>ERROR: Transaction not found in database!</p></body></html>");
    }
    
    echo "<p style='color:green;'>✓ Transaction FOUND in database</p>";
    echo "<pre>";
    print_r($txn);
    echo "</pre>";
    
    // Get items
    $stmt2 = $pdo->prepare("SELECT * FROM merchandise_transaction_items WHERE transaction_id = ?");
    $stmt2->execute([$txn['id']]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Items found: " . count($items) . "</p>";
    echo "<pre>";
    print_r($items);
    echo "</pre>";
    
    // NOW TRY TO BUILD THE SALE OBJECT
    echo "<hr><h3>Building Sale Object...</h3>";
    
    $sale = [
        'transaction_id' => $txn['transaction_id'],
        'customer_name' => $txn['customer_name'],
        'total_amount' => $txn['total_amount'],
        'items' => $items,
    ];
    
    echo "<p style='color:green;'>✓ Sale object created successfully!</p>";
    echo "<pre>";
    print_r($sale);
    echo "</pre>";
    
    // TEST: Can we render a simple receipt?
    echo "<hr><h3>Simple Receipt:</h3>";
    echo "<div style='border:1px solid #000; padding:20px; max-width:400px; font-family:monospace;'>";
    echo "<h2 style='text-align:center;'>PETRON STATION</h2>";
    echo "<p>Transaction: {$sale['transaction_id']}</p>";
    echo "<p>Customer: {$sale['customer_name']}</p>";
    echo "<hr>";
    foreach ($items as $item) {
        echo "<p>{$item['product_name']} x{$item['quantity']} - ₱" . number_format($item['subtotal'], 2) . "</p>";
    }
    echo "<hr>";
    echo "<p><strong>TOTAL: ₱" . number_format($sale['total_amount'], 2) . "</strong></p>";
    echo "</div>";
    
    echo "<hr>";
    echo "<p style='color:green;'><strong>✓ ALL TESTS PASSED!</strong></p>";
    echo "<p>The issue is NOT with the data or queries.</p>";
    echo "<p>The issue must be in the original receipt.php file's complex logic.</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "</body></html>";
?>
