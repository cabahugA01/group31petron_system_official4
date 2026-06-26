<?php
require_once __DIR__ . '/../public/db_connect.php';

// Find the same admin user as test_render_po.php
$user = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'superadmin') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("No users found to seed with.");
}
$user_id = $user['id'];
$station_id = $user['station_id'];

// Find or insert Petron supplier
$sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn();
if (!$sup_id) {
    $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
    $sup_id = $pdo->lastInsertId();
}

// Find valid fuel types
$fuel_types = $pdo->query("SELECT id FROM fuel_types LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($fuel_types) < 2) {
    die("Need at least 2 fuel types in the database.");
}
$fuel_type_1 = $fuel_types[0];
$fuel_type_2 = $fuel_types[1];

echo "Using User ID: $user_id, Station ID: $station_id, Supplier ID: $sup_id, Fuel Types: $fuel_type_1, $fuel_type_2\n";

// Clear existing test POs to keep DB clean
$pdo->exec("DELETE FROM purchase_orders WHERE station_id = $station_id");
$pdo->exec("DELETE FROM fuel_purchase_orders WHERE station_id = $station_id");

$rand = rand(1000, 9999);

// 1. Pending Merchandise PO
$pdo->prepare("
    INSERT INTO purchase_orders (
        product_name, quantity, unit_price, total_amount, type, po_number, station_id, created_by, status, created_at, updated_at, supplier_id, supplier_name
    ) VALUES (
        'Petron Blaze Racing 4T Premium Multi-Grade', 50, 250.00, 12500.00, 'merch', 'POM-20260625-{$rand}1', ?, ?, 'Pending Admin Validation', NOW() - INTERVAL 1 DAY, NOW(), ?, 'Petron Corporation'
    )
")->execute([$station_id, $user_id, $sup_id]);

// 2. Approved Merchandise PO
$pdo->prepare("
    INSERT INTO purchase_orders (
        product_name, quantity, unit_price, total_amount, type, po_number, batch_id, station_id, created_by, admin_id, status, admin_finalized, admin_finalized_at, created_at, updated_at, supplier_id, supplier_name
    ) VALUES (
        'Petron Rev-X Premium Multi-grade 15W-40', 100, 320.00, 32000.00, 'merch', 'POM-20260624-{$rand}2', 'POM-20260624-{$rand}', ?, ?, ?, 'Admin Finalized', 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, NOW(), ?, 'Petron Corporation'
    )
")->execute([$station_id, $user_id, $user_id, $sup_id]);

// 3. Delivered/Stocked-In Merchandise PO
$pdo->prepare("
    INSERT INTO purchase_orders (
        product_name, quantity, unit_price, total_amount, type, po_number, batch_id, station_id, created_by, admin_id, status, admin_finalized, admin_finalized_at, stock_in_done, stock_in_at, stock_in_by, created_at, updated_at, supplier_id, supplier_name
    ) VALUES (
        'Petron Sprint 4T Scooter Oil', 80, 180.00, 14400.00, 'merch', 'POM-20260623-{$rand}3', 'POM-20260623-{$rand}', ?, ?, ?, 'Admin Finalized', 1, NOW() - INTERVAL 3 DAY, 1, NOW() - INTERVAL 3 DAY, ?, NOW() - INTERVAL 3 DAY, NOW(), ?, 'Petron Corporation'
    )
")->execute([$station_id, $user_id, $user_id, $user_id, $sup_id]);

// 4. Cancelled Merchandise PO
$pdo->prepare("
    INSERT INTO purchase_orders (
        product_name, quantity, unit_price, total_amount, type, po_number, station_id, created_by, status, created_at, updated_at, rejection_reason, supplier_id, supplier_name
    ) VALUES (
        'Petron Coolant Ready-to-Use', 30, 150.00, 4500.00, 'merch', 'POM-20260622-{$rand}4', ?, ?, 'Rejected by Admin', NOW() - INTERVAL 4 DAY, NOW(), 'Discrepancy in requested quantity', ?, 'Petron Corporation'
    )
")->execute([$station_id, $user_id, $sup_id]);

// 5. Pending Fuel PO
$pdo->prepare("
    INSERT INTO fuel_purchase_orders (
        po_number, station_id, fuel_type_id, volume, unit_price, total_amount, status, created_by, created_at, supplier_id
    ) VALUES (
        'POF-20260625-{$rand}1', ?, ?, 10000.00, 60.50, 605000.00, 'Pending Admin Validation', ?, NOW() - INTERVAL 1 DAY, ?
    )
")->execute([$station_id, $fuel_type_1, $user_id, $sup_id]);

// 6. Approved Fuel PO
$pdo->prepare("
    INSERT INTO fuel_purchase_orders (
        po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount, status, created_by, approved_by, approved_at, created_at, supplier_id
    ) VALUES (
        'POF-20260624-{$rand}2', 'POF-20260624-{$rand}', ?, ?, 15000.00, 65.20, 978000.00, 'Approved PO', ?, ?, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, ?
    )
")->execute([$station_id, $fuel_type_2, $user_id, $user_id, $sup_id]);

echo "Seeded sample purchase orders successfully.\n";
