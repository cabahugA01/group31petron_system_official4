<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$station_id = user_station_id();

echo "<h1>Stock-In Tables Debug</h1>";
echo "<p><strong>Station ID:</strong> $station_id</p>";

// Check merchandise_stock_in table
echo "<h2>Merchandise Stock-In Table</h2>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM merchandise_stock_in WHERE station_id = ?");
    $stmt->execute([$station_id]);
    $count = $stmt->fetchColumn();
    echo "<p>Total records for this station: <strong>$count</strong></p>";
    
    if ($count > 0) {
        $stmt = $pdo->prepare("SELECT * FROM merchandise_stock_in WHERE station_id = ? ORDER BY encoded_at DESC LIMIT 10");
        $stmt->execute([$station_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Last 10 Records:</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr><th>ID</th><th>Batch Ref</th><th>PO Number</th><th>Product</th><th>Qty Received</th><th>Encoded At</th></tr>";
        foreach ($records as $r) {
            echo "<tr>";
            echo "<td>{$r['id']}</td>";
            echo "<td>{$r['batch_ref']}</td>";
            echo "<td>{$r['po_number']}</td>";
            echo "<td>{$r['product_name']}</td>";
            echo "<td>{$r['qty_received']}</td>";
            echo "<td>{$r['encoded_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red;'>No records found! Staff needs to encode stock-in first.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// Check fuel_stock_in table
echo "<h2>Fuel Stock-In Table</h2>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM fuel_stock_in WHERE station_id = ?");
    $stmt->execute([$station_id]);
    $count = $stmt->fetchColumn();
    echo "<p>Total records for this station: <strong>$count</strong></p>";
    
    if ($count > 0) {
        $stmt = $pdo->prepare("SELECT * FROM fuel_stock_in WHERE station_id = ? ORDER BY encoded_at DESC LIMIT 10");
        $stmt->execute([$station_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Last 10 Records:</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr><th>ID</th><th>Batch Ref</th><th>Fuel Type</th><th>Qty Received</th><th>Encoded At</th></tr>";
        foreach ($records as $r) {
            echo "<tr>";
            echo "<td>{$r['id']}</td>";
            echo "<td>{$r['batch_ref']}</td>";
            echo "<td>{$r['fuel_type']}</td>";
            echo "<td>{$r['qty_received']}</td>";
            echo "<td>{$r['encoded_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red;'>No records found! Staff needs to encode stock-in first.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// Check deliveries_oversight table
echo "<h2>Deliveries Oversight Table (Pending)</h2>";
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM deliveries_oversight 
        WHERE station_id = ? 
          AND delivery_type = 'merchandise'
          AND status IN ('Ready for Stock-In', 'Validated', 'Partial Delivery', 'Damaged Items')
    ");
    $stmt->execute([$station_id]);
    $count = $stmt->fetchColumn();
    echo "<p>Pending merchandise deliveries: <strong>$count</strong></p>";
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM deliveries_oversight 
        WHERE station_id = ? 
          AND delivery_type = 'fuel'
          AND status IN ('Ready for Stock-In', 'Validated', 'Partial Delivery', 'Damaged Items')
    ");
    $stmt->execute([$station_id]);
    $count = $stmt->fetchColumn();
    echo "<p>Pending fuel deliveries: <strong>$count</strong></p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='staff_stock_in.php'>&larr; Back to Stock-In</a></p>";
?>
