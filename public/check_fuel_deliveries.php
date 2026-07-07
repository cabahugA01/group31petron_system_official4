<?php
// Quick diagnostic tool to check fuel delivery data
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();

echo "<h1>Fuel Deliveries Data Check</h1>";
echo "<p><strong>Current User:</strong> " . htmlspecialchars($me['name'] ?? $me['username']) . " (ID: {$me['id']})</p>";
echo "<p><strong>Station ID:</strong> {$station_id}</p>";
echo "<hr>";

// Check if table exists
try {
    $check = $pdo->query("SHOW TABLES LIKE 'deliveries_oversight'")->fetch();
    if (!$check) {
        echo "<p style='color:red;'>❌ Table 'deliveries_oversight' does NOT exist!</p>";
        exit;
    }
    echo "<p style='color:green;'>✅ Table 'deliveries_oversight' exists</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error checking table: " . $e->getMessage() . "</p>";
    exit;
}

// Check total records
try {
    $total = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight")->fetchColumn();
    echo "<p><strong>Total records in deliveries_oversight:</strong> {$total}</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// Check fuel-type records
try {
    $fuel_count = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight WHERE delivery_type = 'fuel'")->fetchColumn();
    echo "<p><strong>Fuel delivery records:</strong> {$fuel_count}</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

// Check records for this station
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND delivery_type = 'fuel'");
    $stmt->execute([$station_id]);
    $station_fuel = $stmt->fetchColumn();
    echo "<p><strong>Fuel deliveries at your station:</strong> {$station_fuel}</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Recent Fuel Deliveries (All Stations)</h2>";

try {
    $stmt = $pdo->query("
        SELECT 
            id, 
            delivery_ref, 
            supplier, 
            product, 
            quantity, 
            delivery_date, 
            status, 
            station_id,
            encoded_by,
            delivery_type,
            created_at
        FROM deliveries_oversight 
        WHERE delivery_type = 'fuel'
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo "<p style='color:orange;'>⚠️ No fuel delivery records found in database</p>";
        echo "<p><strong>Suggestion:</strong> Create a fuel delivery via 'Record Fuel Delivery' page first.</p>";
    } else {
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
        echo "<tr style='background:#f0f0f0;'>";
        echo "<th>ID</th><th>Ref</th><th>Supplier</th><th>Product</th><th>Qty</th><th>Date</th><th>Status</th><th>Station</th><th>Encoded By</th><th>Created</th>";
        echo "</tr>";
        foreach ($records as $r) {
            $highlight = ($r['station_id'] == $station_id) ? "background:#ffffcc;" : "";
            echo "<tr style='{$highlight}'>";
            echo "<td>{$r['id']}</td>";
            echo "<td>{$r['delivery_ref']}</td>";
            echo "<td>" . htmlspecialchars($r['supplier']) . "</td>";
            echo "<td>" . htmlspecialchars($r['product']) . "</td>";
            echo "<td>{$r['quantity']}</td>";
            echo "<td>{$r['delivery_date']}</td>";
            echo "<td><strong>{$r['status']}</strong></td>";
            echo "<td>{$r['station_id']}</td>";
            echo "<td>{$r['encoded_by']}</td>";
            echo "<td>{$r['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p style='color:green;'>✅ Found " . count($records) . " fuel delivery record(s)</p>";
        echo "<p><em>Note: Yellow highlighted rows are from your station (ID: {$station_id})</em></p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error fetching records: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Status Distribution (Your Station)</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM deliveries_oversight 
        WHERE station_id = ? AND delivery_type = 'fuel'
        GROUP BY status
    ");
    $stmt->execute([$station_id]);
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($statuses)) {
        echo "<p style='color:orange;'>No fuel deliveries at your station yet.</p>";
    } else {
        echo "<ul>";
        foreach ($statuses as $s) {
            echo "<li><strong>{$s['status']}:</strong> {$s['count']} record(s)</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='staff_fuel_delivery_status.php'>← Back to Fuel Deliveries History</a></p>";
echo "<p><a href='staff_fuel_deliveries.php'>→ Record New Fuel Delivery</a></p>";
?>
