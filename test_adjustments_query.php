<?php
// TEST SCRIPT: Verify fuel_adjustments table has data and query works
require_once __DIR__ . '/public/db_connect.php';

echo "<h2>Testing Fuel Adjustments Query</h2>";
echo "<hr>";

// Test 1: Check if table exists and has records
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM fuel_adjustments");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total records in fuel_adjustments table:</strong> {$result['total']}</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}

// Test 2: Show sample records
echo "<h3>Sample Records from fuel_adjustments:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM fuel_adjustments ORDER BY created_at DESC LIMIT 5");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo "<p><em>No records found in fuel_adjustments table.</em></p>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse:collapse;'>";
        echo "<tr><th>ID</th><th>Station ID</th><th>Date</th><th>Fuel Type ID</th><th>Adjustment Type</th><th>Liters</th><th>Reason</th><th>User ID</th><th>Created At</th></tr>";
        foreach ($records as $r) {
            echo "<tr>";
            echo "<td>{$r['id']}</td>";
            echo "<td>{$r['station_id']}</td>";
            echo "<td>{$r['adjustment_date']}</td>";
            echo "<td>{$r['fuel_type_id']}</td>";
            echo "<td>{$r['adjustment_type']}</td>";
            echo "<td>{$r['liters']}</td>";
            echo "<td>" . htmlspecialchars(substr($r['reason'], 0, 50)) . "...</td>";
            echo "<td>{$r['user_id']}</td>";
            echo "<td>{$r['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}

// Test 3: Run the actual query used in manager_fuel_adjustments.php
echo "<h3>Testing Actual Query (with JOINs):</h3>";
$station_id = 1253; // Default test station
echo "<p><strong>Station ID:</strong> {$station_id}</p>";

try {
    $stmt = $pdo->prepare("
        SELECT fa.*, ft.name as fuel_type_name, u.name as user_name 
        FROM fuel_adjustments fa 
        JOIN fuel_types ft ON fa.fuel_type_id=ft.id 
        JOIN users u ON fa.user_id=u.id 
        WHERE fa.station_id=? 
        ORDER BY fa.adjustment_date DESC, fa.created_at DESC 
        LIMIT 15
    ");
    $stmt->execute([$station_id]);
    $recent_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Records found for station {$station_id}:</strong> " . count($recent_adjustments) . "</p>";
    
    if (empty($recent_adjustments)) {
        echo "<p><em>No adjustment records found for this station.</em></p>";
        echo "<p><strong>Possible reasons:</strong></p>";
        echo "<ul>";
        echo "<li>No adjustments have been made yet for station {$station_id}</li>";
        echo "<li>The station_id in fuel_adjustments doesn't match</li>";
        echo "<li>The fuel_type_id or user_id foreign keys are invalid</li>";
        echo "</ul>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse:collapse;font-size:0.85rem;'>";
        echo "<tr><th>ID</th><th>Date</th><th>Fuel Type</th><th>Type</th><th>Liters</th><th>Reason</th><th>Manager</th><th>Timestamp</th></tr>";
        foreach ($recent_adjustments as $adj) {
            $liters = (float)($adj['liters'] ?? 0);
            $liters_display = ($liters >= 0 ? '+' : '') . number_format($liters, 2) . ' L';
            $adj_type_display = ucfirst(str_replace('_', ' ', $adj['adjustment_type']));
            
            echo "<tr>";
            echo "<td><strong>#{$adj['id']}</strong></td>";
            echo "<td>" . date('M j, Y', strtotime($adj['adjustment_date'])) . "</td>";
            echo "<td><strong>{$adj['fuel_type_name']}</strong></td>";
            echo "<td>{$adj_type_display}</td>";
            echo "<td style='text-align:right;'><strong>{$liters_display}</strong></td>";
            echo "<td>" . htmlspecialchars(substr($adj['reason'], 0, 50)) . "...</td>";
            echo "<td>{$adj['user_name']}</td>";
            echo "<td>" . date('M j, Y H:i', strtotime($adj['created_at'])) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}

// Test 4: Check all stations with adjustments
echo "<h3>Stations with Adjustment Records:</h3>";
try {
    $stmt = $pdo->query("
        SELECT station_id, COUNT(*) as adjustment_count 
        FROM fuel_adjustments 
        GROUP BY station_id 
        ORDER BY adjustment_count DESC
    ");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stations)) {
        echo "<p><em>No stations have adjustment records.</em></p>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse:collapse;'>";
        echo "<tr><th>Station ID</th><th>Adjustment Count</th></tr>";
        foreach ($stations as $s) {
            echo "<tr><td>{$s['station_id']}</td><td>{$s['adjustment_count']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Test complete!</strong> Visit <a href='public/manager_fuel_adjustments.php'>manager_fuel_adjustments.php</a> to see the actual page.</p>";
?>
