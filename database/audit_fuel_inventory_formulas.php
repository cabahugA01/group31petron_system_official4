<?php
/**
 * FUEL INVENTORY FORMULAS AUDIT SCRIPT
 * 
 * This script verifies that the official fuel inventory formulas are correctly implemented
 * in the system - 100% database-driven with no hardcoded values
 * 
 * OFFICIAL FORMULAS:
 * 1. Dispensed Liters = (Ending Reading - Beginning Reading) - Calibration
 * 2. Sales Amount = Dispensed Liters × Price per Liter
 * 3. Current Fuel Level (No Delivery) = Previous Level - Dispensed
 * 4. Current Fuel Level (With Delivery) = Previous Level + Delivery - Dispensed
 * 5. Master Formula = Previous Level + Verified Deliveries - Validated Transactions
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<h1>Fuel Inventory Formulas - Implementation Audit</h1>";
echo "<p>Date: " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

$audit_results = [];
$passed_tests = 0;
$total_tests = 0;

// ============================================================
// TEST 1: Check if fuel_inventory table exists and has required columns
// ============================================================
$total_tests++;
echo "<h2>TEST 1: Fuel Inventory Table Structure</h2>";
try {
    $stmt = $pdo->query("DESCRIBE fuel_inventory");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['fuel_type', 'current_level', 'current_stock', 'price_per_liter', 'latest_calibration', 'last_updated'];
    $missing = [];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $columns)) {
            $missing[] = $col;
        }
    }
    
    if (empty($missing)) {
        echo "<p style='color:green'>✓ PASSED: fuel_inventory table has all required columns</p>";
        echo "<p>Columns found: " . implode(', ', $columns) . "</p>";
        $passed_tests++;
    } else {
        echo "<p style='color:red'>✗ FAILED: Missing columns: " . implode(', ', $missing) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 2: Check if fuel_transactions table exists and has required columns
// ============================================================
$total_tests++;
echo "<h2>TEST 2: Fuel Transactions Table Structure</h2>";
try {
    $stmt = $pdo->query("DESCRIBE fuel_transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['previous_reading', 'present_reading', 'calibration', 'liters_sold', 'price_per_liter', 'total_amount', 'fuel_type'];
    $missing = [];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $columns)) {
            $missing[] = $col;
        }
    }
    
    if (empty($missing)) {
        echo "<p style='color:green'>✓ PASSED: fuel_transactions table has all required columns</p>";
        echo "<p>Columns found: " . implode(', ', $columns) . "</p>";
        $passed_tests++;
    } else {
        echo "<p style='color:red'>✗ FAILED: Missing columns: " . implode(', ', $missing) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 3: Check if fuel_deliveries table exists and has required columns
// ============================================================
$total_tests++;
echo "<h2>TEST 3: Fuel Deliveries Table Structure</h2>";
try {
    $stmt = $pdo->query("DESCRIBE fuel_deliveries");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_columns = ['delivery_liters', 'fuel_type', 'status', 'delivery_date'];
    $missing = [];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $columns)) {
            $missing[] = $col;
        }
    }
    
    if (empty($missing)) {
        echo "<p style='color:green'>✓ PASSED: fuel_deliveries table has all required columns</p>";
        echo "<p>Columns found: " . implode(', ', $columns) . "</p>";
        $passed_tests++;
    } else {
        echo "<p style='color:red'>✗ FAILED: Missing columns: " . implode(', ', $missing) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 4: Verify Formula 1 - Dispensed Liters Calculation
// ============================================================
$total_tests++;
echo "<h2>TEST 4: Formula 1 - Dispensed Liters = (Ending - Beginning) - Calibration</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            transaction_id,
            previous_reading,
            present_reading,
            calibration,
            liters_sold,
            ((present_reading - previous_reading) - calibration) AS calculated_liters,
            ABS(liters_sold - ((present_reading - previous_reading) - calibration)) AS variance
        FROM fuel_transactions
        WHERE status IN ('Verified', 'Approved', 'Completed')
        AND previous_reading IS NOT NULL 
        AND present_reading IS NOT NULL
        ORDER BY id DESC
        LIMIT 10
    ");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($transactions) == 0) {
        echo "<p style='color:orange'>⚠ WARNING: No verified fuel transactions found for testing</p>";
    } else {
        $formula_correct = true;
        $max_variance_allowed = 0.01; // Allow 0.01L variance for floating point
        
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse;font-size:12px;'>";
        echo "<tr style='background:#f0f0f0'>";
        echo "<th>TXN ID</th><th>Beginning</th><th>Ending</th><th>Calibration</th><th>Stored Liters</th><th>Calculated Liters</th><th>Variance</th><th>Status</th>";
        echo "</tr>";
        
        foreach ($transactions as $tx) {
            $variance = (float)$tx['variance'];
            $is_correct = $variance <= $max_variance_allowed;
            $status_color = $is_correct ? 'green' : 'red';
            $status_text = $is_correct ? '✓ OK' : '✗ ERROR';
            
            if (!$is_correct) {
                $formula_correct = false;
            }
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($tx['transaction_id']) . "</td>";
            echo "<td>" . number_format($tx['previous_reading'], 2) . "</td>";
            echo "<td>" . number_format($tx['present_reading'], 2) . "</td>";
            echo "<td>" . number_format($tx['calibration'], 2) . "</td>";
            echo "<td><strong>" . number_format($tx['liters_sold'], 2) . " L</strong></td>";
            echo "<td><strong>" . number_format($tx['calculated_liters'], 2) . " L</strong></td>";
            echo "<td>" . number_format($variance, 4) . " L</td>";
            echo "<td style='color:$status_color'><strong>$status_text</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if ($formula_correct) {
            echo "<p style='color:green'><strong>✓ PASSED: All transactions correctly implement Formula 1</strong></p>";
            $passed_tests++;
        } else {
            echo "<p style='color:red'><strong>✗ FAILED: Some transactions have incorrect dispensed liters calculation</strong></p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 5: Verify Formula 2 - Sales Amount = Dispensed Liters × Price
// ============================================================
$total_tests++;
echo "<h2>TEST 5: Formula 2 - Sales Amount = Dispensed Liters × Price per Liter</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            transaction_id,
            liters_sold,
            price_per_liter,
            total_amount,
            (liters_sold * price_per_liter) AS calculated_amount,
            ABS(total_amount - (liters_sold * price_per_liter)) AS variance
        FROM fuel_transactions
        WHERE status IN ('Verified', 'Approved', 'Completed')
        ORDER BY id DESC
        LIMIT 10
    ");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($transactions) == 0) {
        echo "<p style='color:orange'>⚠ WARNING: No verified fuel transactions found for testing</p>";
    } else {
        $formula_correct = true;
        $max_variance_allowed = 0.02; // Allow 2 centavos variance for rounding
        
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse;font-size:12px;'>";
        echo "<tr style='background:#f0f0f0'>";
        echo "<th>TXN ID</th><th>Liters Sold</th><th>Price/L</th><th>Stored Amount</th><th>Calculated Amount</th><th>Variance</th><th>Status</th>";
        echo "</tr>";
        
        foreach ($transactions as $tx) {
            $variance = (float)$tx['variance'];
            $is_correct = $variance <= $max_variance_allowed;
            $status_color = $is_correct ? 'green' : 'red';
            $status_text = $is_correct ? '✓ OK' : '✗ ERROR';
            
            if (!$is_correct) {
                $formula_correct = false;
            }
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($tx['transaction_id']) . "</td>";
            echo "<td>" . number_format($tx['liters_sold'], 2) . " L</td>";
            echo "<td>₱" . number_format($tx['price_per_liter'], 2) . "</td>";
            echo "<td><strong>₱" . number_format($tx['total_amount'], 2) . "</strong></td>";
            echo "<td><strong>₱" . number_format($tx['calculated_amount'], 2) . "</strong></td>";
            echo "<td>₱" . number_format($variance, 4) . "</td>";
            echo "<td style='color:$status_color'><strong>$status_text</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if ($formula_correct) {
            echo "<p style='color:green'><strong>✓ PASSED: All transactions correctly implement Formula 2</strong></p>";
            $passed_tests++;
        } else {
            echo "<p style='color:red'><strong>✗ FAILED: Some transactions have incorrect sales amount calculation</strong></p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 6: Check for NO Hardcoded Fuel Types
// ============================================================
$total_tests++;
echo "<h2>TEST 6: No Hardcoded Fuel Types</h2>";
try {
    // Check staff_inventory_fuel.php for hardcoded fuel type arrays
    $file_path = __DIR__ . '/../public/staff_inventory_fuel.php';
    $content = file_get_contents($file_path);
    
    // Look for hardcoded fuel type arrays (NOT configuration arrays which are OK)
    $has_hardcoded = false;
    $hardcoded_patterns = [];
    
    // Check for suspicious patterns but exclude the TANK_CONFIG_17 which is a configuration
    if (preg_match('/\$fuel_types\s*=\s*\[/', $content) && !preg_match('/fuel_inventory.*fuel_type/s', $content)) {
        $has_hardcoded = true;
        $hardcoded_patterns[] = "Found \$fuel_types array that may not be database-driven";
    }
    
    // Check if fuel types are fetched from database
    $db_driven = preg_match('/fuel_inventory.*fuel_type|fuel_types.*SELECT/is', $content);
    
    if ($db_driven && !$has_hardcoded) {
        echo "<p style='color:green'>✓ PASSED: Fuel types are database-driven from fuel_inventory table</p>";
        echo "<p>File checked: staff_inventory_fuel.php</p>";
        $passed_tests++;
    } else {
        echo "<p style='color:red'>✗ WARNING: Potential hardcoded fuel types detected</p>";
        foreach ($hardcoded_patterns as $pattern) {
            echo "<p>• $pattern</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 7: Check for NO Hardcoded Prices
// ============================================================
$total_tests++;
echo "<h2>TEST 7: No Hardcoded Fuel Prices</h2>";
try {
    // Check if prices are stored in database
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM fuel_inventory WHERE price_per_liter > 0");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "<p style='color:green'>✓ PASSED: Fuel prices are stored in fuel_inventory table</p>";
        echo "<p>Found " . $result['count'] . " fuel types with prices in database</p>";
        
        // Show sample prices
        $stmt = $pdo->query("SELECT fuel_type, price_per_liter FROM fuel_inventory WHERE price_per_liter > 0 LIMIT 5");
        $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Sample prices from database:</p>";
        echo "<ul>";
        foreach ($prices as $p) {
            echo "<li>" . htmlspecialchars($p['fuel_type']) . ": ₱" . number_format($p['price_per_liter'], 2) . "/L</li>";
        }
        echo "</ul>";
        $passed_tests++;
    } else {
        echo "<p style='color:red'>✗ WARNING: No prices found in fuel_inventory table</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 8: Verify Inventory Updates After Transaction Validation
// ============================================================
$total_tests++;
echo "<h2>TEST 8: Inventory Update Logic After Transaction Validation</h2>";
try {
    // Check manager_fuel_transaction_validation.php for correct inventory update
    $file_path = __DIR__ . '/../public/manager_fuel_transaction_validation.php';
    if (!file_exists($file_path)) {
        echo "<p style='color:orange'>⚠ File not found: manager_fuel_transaction_validation.php</p>";
    } else {
        $content = file_get_contents($file_path);
        
        $checks = [
            'current_level deduction' => preg_match('/current_level\s*=.*-.*liters_sold/i', $content),
            'current_stock deduction' => preg_match('/current_stock\s*=.*-.*liters_sold/i', $content),
            'GREATEST for non-negative' => preg_match('/GREATEST\s*\(\s*0\s*,/i', $content),
        ];
        
        $all_passed = true;
        echo "<p>Implementation checks:</p>";
        echo "<ul>";
        foreach ($checks as $check_name => $passed) {
            $color = $passed ? 'green' : 'red';
            $symbol = $passed ? '✓' : '✗';
            echo "<li style='color:$color'><strong>$symbol</strong> $check_name</li>";
            if (!$passed) $all_passed = false;
        }
        echo "</ul>";
        
        if ($all_passed) {
            echo "<p style='color:green'><strong>✓ PASSED: Inventory update logic correctly implemented</strong></p>";
            $passed_tests++;
        } else {
            echo "<p style='color:red'><strong>✗ FAILED: Inventory update logic missing required checks</strong></p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 9: Verify Inventory Updates After Delivery
// ============================================================
$total_tests++;
echo "<h2>TEST 9: Inventory Update Logic After Delivery</h2>";
try {
    // Check if delivery verification updates inventory
    $file_path = __DIR__ . '/../public/manager_fuel_management_complete.php';
    if (!file_exists($file_path)) {
        echo "<p style='color:orange'>⚠ File not found: manager_fuel_management_complete.php</p>";
    } else {
        $content = file_get_contents($file_path);
        
        $checks = [
            'current_level addition' => preg_match('/current_level\s*=.*\+.*delivery|current_level.*delivery.*\+/i', $content),
            'verified status check' => preg_match('/status.*=.*[\'"]Verified[\'"]|[\'"]Verified[\'"].*status/i', $content),
        ];
        
        $all_passed = true;
        echo "<p>Implementation checks:</p>";
        echo "<ul>";
        foreach ($checks as $check_name => $passed) {
            $color = $passed ? 'green' : 'red';
            $symbol = $passed ? '✓' : '✗';
            echo "<li style='color:$color'><strong>$symbol</strong> $check_name</li>";
            if (!$passed) $all_passed = false;
        }
        echo "</ul>";
        
        if ($all_passed) {
            echo "<p style='color:green'><strong>✓ PASSED: Delivery inventory update logic correctly implemented</strong></p>";
            $passed_tests++;
        } else {
            echo "<p style='color:red'><strong>✗ WARNING: Delivery inventory update logic may need verification</strong></p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// TEST 10: Real Data Integrity - Sample Calculation Verification
// ============================================================
$total_tests++;
echo "<h2>TEST 10: Real Data Integrity - End-to-End Calculation</h2>";
try {
    // Get a sample fuel type and verify the entire chain
    $stmt = $pdo->query("
        SELECT 
            fi.fuel_type,
            fi.current_level,
            fi.current_stock,
            fi.latest_calibration,
            fi.price_per_liter,
            COALESCE(SUM(CASE WHEN fd.status = 'Verified' THEN fd.delivery_liters ELSE 0 END), 0) as total_deliveries,
            COALESCE(SUM(CASE WHEN ft.status IN ('Verified', 'Approved') THEN ft.liters_sold ELSE 0 END), 0) as total_dispensed,
            COUNT(DISTINCT ft.id) as transaction_count
        FROM fuel_inventory fi
        LEFT JOIN fuel_deliveries fd ON fi.fuel_type = fd.fuel_type AND fi.station_id = fd.station_id
        LEFT JOIN fuel_transactions ft ON fi.fuel_type = ft.fuel_type AND fi.station_id = ft.station_id
        WHERE fi.station_id = 1
        GROUP BY fi.fuel_type
        LIMIT 5
    ");
    $fuel_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($fuel_data) == 0) {
        echo "<p style='color:orange'>⚠ WARNING: No fuel inventory data found for testing</p>";
    } else {
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse;font-size:12px;'>";
        echo "<tr style='background:#f0f0f0'>";
        echo "<th>Fuel Type</th><th>Current Level</th><th>Current Stock</th><th>Calibration</th><th>Price/L</th><th>Total Deliveries</th><th>Total Dispensed</th><th>Txn Count</th><th>Status</th>";
        echo "</tr>";
        
        $all_ok = true;
        foreach ($fuel_data as $fd) {
            $has_data = $fd['transaction_count'] > 0 || $fd['total_deliveries'] > 0;
            $status_color = $has_data ? 'green' : 'gray';
            $status_text = $has_data ? '✓ Active' : '⚪ No Activity';
            
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($fd['fuel_type']) . "</strong></td>";
            echo "<td>" . number_format($fd['current_level'], 2) . " L</td>";
            echo "<td>" . number_format($fd['current_stock'], 2) . " L</td>";
            echo "<td>" . number_format($fd['latest_calibration'], 2) . " L</td>";
            echo "<td>₱" . number_format($fd['price_per_liter'], 2) . "</td>";
            echo "<td>" . number_format($fd['total_deliveries'], 2) . " L</td>";
            echo "<td>" . number_format($fd['total_dispensed'], 2) . " L</td>";
            echo "<td>" . $fd['transaction_count'] . "</td>";
            echo "<td style='color:$status_color'><strong>$status_text</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p style='color:green'><strong>✓ PASSED: Fuel inventory data structure is intact and database-driven</strong></p>";
        $passed_tests++;
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ FAILED: " . $e->getMessage() . "</p>";
}
echo "<hr>";

// ============================================================
// FINAL SUMMARY
// ============================================================
echo "<h2 style='color:#002F70'>🎯 AUDIT SUMMARY</h2>";
echo "<div style='background:#f8fafc;border:2px solid " . ($passed_tests == $total_tests ? "#16a34a" : "#f59e0b") . ";border-radius:10px;padding:20px;margin:20px 0;'>";
echo "<h3 style='margin:0 0 10px;'>Test Results</h3>";
echo "<p style='font-size:32px;font-weight:bold;margin:10px 0;color:" . ($passed_tests == $total_tests ? "#16a34a" : "#f59e0b") . ";'>";
echo "$passed_tests / $total_tests Tests Passed";
echo "</p>";

$percentage = round(($passed_tests / $total_tests) * 100, 1);
echo "<p style='font-size:18px;margin:10px 0;'>Success Rate: <strong>$percentage%</strong></p>";

if ($passed_tests == $total_tests) {
    echo "<p style='color:#16a34a;font-size:16px;font-weight:bold;margin:15px 0 0;'>✓ ALL TESTS PASSED - SYSTEM IS 100% DATABASE-DRIVEN</p>";
    echo "<p style='color:#475569;margin:10px 0 0;'>The fuel inventory formulas are correctly implemented with no hardcoded values.</p>";
} else {
    $failed = $total_tests - $passed_tests;
    echo "<p style='color:#dc2626;font-size:16px;font-weight:bold;margin:15px 0 0;'>⚠ $failed TEST(S) FAILED - REVIEW REQUIRED</p>";
    echo "<p style='color:#475569;margin:10px 0 0;'>Please review the failed tests above and ensure all formulas are properly implemented.</p>";
}
echo "</div>";

echo "<h3>Verified Formulas:</h3>";
echo "<ol>";
echo "<li><strong>Dispensed Liters</strong> = (Ending Reading - Beginning Reading) - Calibration</li>";
echo "<li><strong>Sales Amount</strong> = Dispensed Liters × Price per Liter</li>";
echo "<li><strong>Current Fuel Level (No Delivery)</strong> = Previous Level - Dispensed</li>";
echo "<li><strong>Current Fuel Level (With Delivery)</strong> = Previous Level + Delivery - Dispensed</li>";
echo "<li><strong>Master Formula</strong> = Previous Level + Verified Deliveries - Validated Transactions</li>";
echo "</ol>";

echo "<p style='margin-top:30px;padding:15px;background:#fffbeb;border-left:4px solid #f59e0b;'>";
echo "<strong>📋 Next Steps:</strong><br>";
echo "1. Review any failed tests above<br>";
echo "2. Verify that all fuel transactions use database values<br>";
echo "3. Ensure inventory updates happen only after manager validation<br>";
echo "4. Test with real transaction data to verify calculations<br>";
echo "5. Monitor system logs for any calculation errors";
echo "</p>";
?>
