<?php
require_once 'public/db_connect.php';

// Check po_items for merch PO IDs (10-16)
$stmt = $pdo->query("SELECT * FROM po_items WHERE po_id IN (10,11,12,13,14,15,16)");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "--- po_items for merch POs ---\n";
print_r($items);

// Also check received_items table
try {
    $stmt2 = $pdo->query("SELECT * FROM received_items WHERE po_id IN (10,11,12,13,14,15,16) LIMIT 10");
    $ri = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "--- received_items for merch POs ---\n";
    print_r($ri);
} catch (Exception $e) {
    echo "received_items error: " . $e->getMessage() . "\n";
}
