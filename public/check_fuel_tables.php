<?php
// Quick check for fuel delivery tables
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();

echo "<h1>Fuel Delivery Tables Check</h1>";
echo "<p><strong>Station ID:</strong> {$station_id}</p>";
echo "<hr>";

// Check fuel_deliveries table
echo "<h2>1. fuel_deliveries table</h2>";
try {
    $check = $pdo->query("SHOW TABLES LIKE 'fuel_deliveries'")->fetch();
    if ($check) {
        echo "<p style='color:green;'>✅ Table EXISTS</p>";
        
        $count = $pdo->query("SELECT COUNT(*) FROM fuel_deliveries")->fetchColumn();
        echo "<p><strong>Total records:</strong> {$count}</p>";
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ?");
        $stmt->execute([$station_id]);
        $station_count = $stmt->fetchColumn();
        echo "<p><strong>Records at your station:</strong> {$station_count}</p>";
        
        if ($station_count > 0) {
            echo "<h3>Recent Records:</h3>";
            $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE station_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$station_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
            echo "<tr style='background:#f0f0f0;'>";
            echo "<th>ID</th><th>Batch ID</th><th>Fuel Type</th><th>Supplier</th><th>Invoice</th><th>Liters</th><th>Tank</th><th>Tanker</th><th>Date</th><th>Status</th><th>Created</th>";
            echo "</tr>";
            foreach ($records as $r) {
                echo "<tr>";
                echo "<td>{$r['id']}</td>";
                echo "<td style='font-family:monospace;'>{$r['batch_id']}</td>";
                echo "<td>{$r['fuel_type']}</td>";
                echo "<td>" . htmlspecialchars($r['supplier']) . "</td>";
                echo "<td>{$r['invoice_no']}</td>";
                echo "<td>{$r['delivery_liters']} L</td>";
                echo "<td>{$r['tank_assigned']}</td>";
                echo "<td>{$r['tanker_number']}</td>";
                echo "<td>{$r['delivery_date']}</td>";
                echo "<td><strong>{$r['status']}</strong></td>";
                echo "<td>{$r['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p style='color:red;'>❌ Table DOES NOT EXIST</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";

// Check deliveries_oversight table
echo "<h2>2. deliveries_oversight table</h2>";
try {
    $check = $pdo->query("SHOW TABLES LIKE 'deliveries_oversight'")->fetch();
    if ($check) {
        echo "<p style='color:green;'>✅ Table EXISTS</p>";
        
        $count = $pdo->query("SELECT COUNT(*) FROM deliveries_oversight WHERE delivery_type = 'fuel'")->fetchColumn();
        echo "<p><strong>Total fuel records:</strong> {$count}</p>";
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND delivery_type = 'fuel'");
        $stmt->execute([$station_id]);
        $station_count = $stmt->fetchColumn();
        echo "<p><strong>Fuel records at your station:</strong> {$station_count}</p>";
        
        if ($station_count > 0) {
            echo "<h3>Recent Records:</h3>";
            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE station_id = ? AND delivery_type = 'fuel' ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$station_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
            echo "<tr style='background:#f0f0f0;'>";
            echo "<th>ID</th><th>Batch ID</th><th>Ref</th><th>Product</th><th>Supplier</th><th>DR</th><th>Qty</th><th>Date</th><th>Status</th><th>Created</th>";
            echo "</tr>";
            foreach ($records as $r) {
                echo "<tr>";
                echo "<td>{$r['id']}</td>";
                echo "<td style='font-family:monospace;'>" . htmlspecialchars($r['batch_id'] ?? '—') . "</td>";
                echo "<td style='font-family:monospace;'>{$r['delivery_ref']}</td>";
                echo "<td>{$r['product']}</td>";
                echo "<td>" . htmlspecialchars($r['supplier']) . "</td>";
                echo "<td>" . htmlspecialchars($r['dr_number'] ?? '—') . "</td>";
                echo "<td>{$r['quantity']} {$r['unit']}</td>";
                echo "<td>{$r['delivery_date']}</td>";
                echo "<td><strong>{$r['status']}</strong></td>";
                echo "<td>{$r['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p style='color:red;'>❌ Table DOES NOT EXIST</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p><strong>ISSUE FOUND:</strong></p>";
echo "<ul>";
echo "<li>📝 <strong>Record Fuel Delivery</strong> page (staff_fuel_deliveries.php) saves to <strong>fuel_deliveries</strong> table</li>";
echo "<li>👁️ <strong>Fuel Deliveries History</strong> page (staff_fuel_delivery_status.php) queries from <strong>deliveries_oversight</strong> table</li>";
echo "<li>⚠️ <strong>Result:</strong> Data saved sa usa ka table, pero gi-query sa lain nga table = WALA'Y MAKITA</li>";
echo "</ul>";

echo "<h3>Solution:</h3>";
echo "<p>Need to update either:</p>";
echo "<ol>";
echo "<li><strong>Option 1 (Recommended):</strong> Update History page to query <strong>fuel_deliveries</strong> table</li>";
echo "<li><strong>Option 2:</strong> Update Record page to save to <strong>deliveries_oversight</strong> table</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='staff_fuel_delivery_status.php'>← Back to Fuel Deliveries History</a></p>";
echo "<p><a href='staff_fuel_deliveries.php'>→ Record New Fuel Delivery</a></p>";
?>
