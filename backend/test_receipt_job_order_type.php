<?php
// Simulate receipt.php loading a job order by numeric ID
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../public/db_connect.php';

$id = 2;
$type = 'job_order';

echo "Simulating receipt.php with id=$id&type=$type\n";
echo str_repeat("=", 70) . "\n\n";

$jo = null;

try {
    // Try job_orders table first (this will fail for ID 2)
    $stmt = $pdo->prepare("
        SELECT jo.*,
               COALESCE(u.username, 'Mechanic') AS mechanic_name,
               COALESCE(cb.username, 'Staff') AS staff_name,
               s.name  AS station_name
        FROM job_orders jo
        LEFT JOIN users    u  ON u.id  = jo.assigned_mechanic_id
        LEFT JOIN users    cb ON cb.id = jo.created_by
        LEFT JOIN stations s  ON s.id  = jo.station_id
        WHERE jo.job_order_id = ? OR jo.job_order_number = ? OR jo.id = ?
        LIMIT 1
    ");
    $numeric_id = is_numeric($id) ? (int)$id : 0;
    $stmt->execute([$id, $id, $numeric_id]);
    $jo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($jo) {
        echo "✅ Found in job_orders table\n";
    } else {
        echo "❌ NOT found in job_orders table\n";
        echo "⏩ Trying fallback to merchandise_transactions...\n\n";
        
        // FALLBACK: Try merchandise_transactions
        if ($numeric_id > 0) {
            $stmt_mt = $pdo->prepare("
                SELECT mt.*,
                       COALESCE(u.username, 'Staff') AS staff_name,
                       s.name AS station_name,
                       s.location AS station_location,
                       s.address AS station_address,
                       s.vat_tin AS station_vat_tin
                FROM merchandise_transactions mt
                LEFT JOIN users u ON mt.staff_id = u.id
                LEFT JOIN stations s ON mt.station_id = s.id
                WHERE mt.id = ?
                  AND mt.transaction_type IN ('job_order', 'combined')
                LIMIT 1
            ");
            $stmt_mt->execute([$numeric_id]);
            $jo_mt = $stmt_mt->fetch(PDO::FETCH_ASSOC);
            
            if ($jo_mt) {
                echo "✅ Found in merchandise_transactions!\n";
                echo "   Transaction ID: {$jo_mt['transaction_id']}\n";
                echo "   Type: {$jo_mt['transaction_type']}\n";
                echo "   Staff: {$jo_mt['staff_name']}\n";
                echo "   Job Order Service: " . ($jo_mt['job_order_service'] ?? 'NULL') . "\n";
                echo "   Vehicle: " . ($jo_mt['job_order_vehicle_plate'] ?? 'NULL') . "\n";
                echo "   Mechanic: " . ($jo_mt['job_order_mechanic_name'] ?? 'NULL') . "\n";
                echo "\n✅ FALLBACK WORKS! Receipt should display now.\n";
            } else {
                echo "❌ NOT found in merchandise_transactions either\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
