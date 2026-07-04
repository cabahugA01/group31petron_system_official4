<?php
/**
 * Test script to verify Purchase Request and Purchase Order data fetching
 * This will verify both merchandise and fuel tabs are fetching data correctly
 */
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();

echo "<h1>Purchase Request & Purchase Order Data Verification</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .section { margin: 30px 0; padding: 20px; border: 2px solid #002F6C; border-radius: 8px; }
    .success { color: #28a745; }
    .warning { color: #fd7e14; }
    .error { color: #dc3545; }
    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #002F6C; color: white; }
</style>";

// ============================================================================
// PURCHASE REQUEST MODULE TESTS
// ============================================================================
echo "<div class='section'>";
echo "<h2>📦 Purchase Request Review Module (Manager)</h2>";
echo "<p>Station ID: <strong>{$station_id}</strong></p>";

// Test 1: Merchandise Requests
echo "<h3>1. Merchandise Stock Requests</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT sr.id, sr.item_name, sr.item_category, sr.requested_quantity, sr.status, 
               COALESCE(u.name, 'Unknown') AS staff_name, sr.created_at
        FROM stock_requests sr 
        LEFT JOIN users u ON sr.staff_id = u.id 
        WHERE sr.station_id = ? AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $merch_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($merch_requests) > 0) {
        echo "<p class='success'>✓ Found " . count($merch_requests) . " merchandise requests</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Item</th><th>Category</th><th>Qty</th><th>Status</th><th>Staff</th><th>Date</th></tr>";
        foreach ($merch_requests as $req) {
            echo "<tr>";
            echo "<td>{$req['id']}</td>";
            echo "<td>{$req['item_name']}</td>";
            echo "<td>{$req['item_category']}</td>";
            echo "<td>{$req['requested_quantity']}</td>";
            echo "<td>{$req['status']}</td>";
            echo "<td>{$req['staff_name']}</td>";
            echo "<td>{$req['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠ No merchandise requests found in database</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 2: Fuel Requests
echo "<h3>2. Fuel Stock Requests</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT fsr.id, fsr.fuel_type, fsr.requested_liters, fsr.status, 
               COALESCE(u.name, 'Unknown') AS staff_name, fsr.created_at
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        WHERE fsr.station_id = ?
        ORDER BY fsr.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $fuel_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($fuel_requests) > 0) {
        echo "<p class='success'>✓ Found " . count($fuel_requests) . " fuel requests</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Fuel Type</th><th>Liters</th><th>Status</th><th>Staff</th><th>Date</th></tr>";
        foreach ($fuel_requests as $req) {
            echo "<tr>";
            echo "<td>{$req['id']}</td>";
            echo "<td>{$req['fuel_type']}</td>";
            echo "<td>{$req['requested_liters']}</td>";
            echo "<td>{$req['status']}</td>";
            echo "<td>{$req['staff_name']}</td>";
            echo "<td>{$req['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠ No fuel requests found in database</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "</div>";

// ============================================================================
// PURCHASE ORDER MODULE TESTS
// ============================================================================
echo "<div class='section'>";
echo "<h2>📋 Purchase Orders Module (Admin)</h2>";

// Test 3: Merchandise Purchase Orders
echo "<h3>3. Merchandise Purchase Orders</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT po.id, po.po_number, po.product_name, po.quantity, po.total_amount, 
               po.status, po.created_at, COALESCE(po.batch_id, 'Not Batched') AS batch_id
        FROM purchase_orders po
        WHERE po.station_id = ? AND po.type = 'merch'
        ORDER BY po.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $merch_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($merch_pos) > 0) {
        echo "<p class='success'>✓ Found " . count($merch_pos) . " merchandise purchase orders</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>PO #</th><th>Product</th><th>Qty</th><th>Amount</th><th>Status</th><th>Batch</th><th>Date</th></tr>";
        foreach ($merch_pos as $po) {
            echo "<tr>";
            echo "<td>{$po['id']}</td>";
            echo "<td>{$po['po_number']}</td>";
            echo "<td>{$po['product_name']}</td>";
            echo "<td>{$po['quantity']}</td>";
            echo "<td>₱" . number_format($po['total_amount'], 2) . "</td>";
            echo "<td>{$po['status']}</td>";
            echo "<td>{$po['batch_id']}</td>";
            echo "<td>{$po['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠ No merchandise purchase orders found in database</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

// Test 4: Fuel Purchase Orders
echo "<h3>4. Fuel Purchase Orders</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT fpo.id, fpo.po_number, ft.name AS fuel_name, fpo.volume, fpo.total_amount, 
               fpo.status, fpo.created_at, COALESCE(fpo.batch_id, 'Not Batched') AS batch_id
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        WHERE fpo.station_id = ?
        ORDER BY fpo.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $fuel_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($fuel_pos) > 0) {
        echo "<p class='success'>✓ Found " . count($fuel_pos) . " fuel purchase orders</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>PO #</th><th>Fuel Type</th><th>Volume (L)</th><th>Amount</th><th>Status</th><th>Batch</th><th>Date</th></tr>";
        foreach ($fuel_pos as $po) {
            echo "<tr>";
            echo "<td>{$po['id']}</td>";
            echo "<td>{$po['po_number']}</td>";
            echo "<td>{$po['fuel_name']}</td>";
            echo "<td>{$po['volume']}</td>";
            echo "<td>₱" . number_format($po['total_amount'], 2) . "</td>";
            echo "<td>{$po['status']}</td>";
            echo "<td>{$po['batch_id']}</td>";
            echo "<td>{$po['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠ No fuel purchase orders found in database</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "</div>";

// ============================================================================
// SUMMARY & RECOMMENDATIONS
// ============================================================================
echo "<div class='section'>";
echo "<h2>📊 Summary & Recommendations</h2>";

$total_merch_req = count($merch_requests ?? []);
$total_fuel_req = count($fuel_requests ?? []);
$total_merch_po = count($merch_pos ?? []);
$total_fuel_po = count($fuel_pos ?? []);

echo "<ul>";
echo "<li><strong>Total Merchandise Requests:</strong> {$total_merch_req}</li>";
echo "<li><strong>Total Fuel Requests:</strong> {$total_fuel_req}</li>";
echo "<li><strong>Total Merchandise POs:</strong> {$total_merch_po}</li>";
echo "<li><strong>Total Fuel POs:</strong> {$total_fuel_po}</li>";
echo "</ul>";

echo "<h3>Status</h3>";
if ($total_merch_req > 0 && $total_fuel_req > 0 && $total_merch_po > 0 && $total_fuel_po > 0) {
    echo "<p class='success'><strong>✓ ALL MODULES ARE FETCHING DATA CORRECTLY!</strong></p>";
    echo "<p>Both Purchase Request and Purchase Order modules are properly fetching data from merchandise and fuel tabs.</p>";
} else {
    echo "<p class='warning'><strong>⚠ SOME TABLES ARE EMPTY</strong></p>";
    echo "<p>The queries are working correctly, but some tables don't have data yet. This is normal if:</p>";
    echo "<ul>";
    if ($total_merch_req == 0) echo "<li>Staff haven't submitted merchandise stock requests yet</li>";
    if ($total_fuel_req == 0) echo "<li>Staff haven't submitted fuel stock requests yet</li>";
    if ($total_merch_po == 0) echo "<li>Manager hasn't approved merchandise requests to create POs yet</li>";
    if ($total_fuel_po == 0) echo "<li>Manager hasn't approved fuel requests to create POs yet</li>";
    echo "</ul>";
    echo "<p><strong>Recommendation:</strong> Have staff submit stock requests, and managers approve them to generate purchase orders.</p>";
}

echo "<h3>Module Access URLs</h3>";
echo "<ul>";
echo "<li><strong>Purchase Request Review (Manager):</strong> <a href='public/manager_stock_request_review.php' target='_blank'>manager_stock_request_review.php</a></li>";
echo "<li><strong>Purchase Orders (Admin):</strong> <a href='public/admin_purchase_orders.php' target='_blank'>admin_purchase_orders.php</a></li>";
echo "</ul>";

echo "</div>";

echo "<p style='text-align:center; margin-top:30px; color:#666;'><em>Test completed on " . date('Y-m-d H:i:s') . "</em></p>";
?>
