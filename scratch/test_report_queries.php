<?php
require_once __DIR__ . '/../public/db_connect.php';

function runTest($name, $sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "[OK] $name: " . count($rows) . " rows.\n";
    } catch (Exception $e) {
        echo "[ERR] $name: " . $e->getMessage() . "\n";
    }
}

echo "=== RETESTING 4.2 AND 4.4 ===\n";
runTest("4.2 Delivery Validation Fixed", "SELECT COALESCE(do.delivery_ref, do.dr_number, CONCAT('DEL-', do.id)) as delivery_reference, do.supplier, do.delivery_date, COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name), 'Admin') as validated_by, do.status as validation_status FROM deliveries_oversight do LEFT JOIN users u ON (do.admin_id = u.id OR do.manager_id = u.id) ORDER BY do.delivery_date DESC LIMIT 5");

runTest("4.4 Stock-In Approval Fixed", "SELECT COALESCE(msi.batch_ref, CONCAT('STK-', msi.id)) as batch_id, msi.product_name as product, msi.qty_received as quantity_received, msi.unit_cost, msi.selling_price, COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name), 'Admin') as approved_by FROM merchandise_stock_in msi LEFT JOIN users u ON msi.encoded_by = u.id ORDER BY msi.encoded_at DESC LIMIT 5");
