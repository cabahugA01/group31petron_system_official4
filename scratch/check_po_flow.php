<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== EMPTY STATUS RECORDS (need fix) ===\n";
$rows = $pdo->query("SELECT id, request_no, item_name, status, staff_id, station_id FROM stock_requests WHERE status = '' OR status IS NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID:{$r['id']} PR:{$r['request_no']} Item:{$r['item_name']} Station:{$r['station_id']}\n";
}

echo "\n=== 'Approved' STATUS (should be in pending for Admin?) ===\n";
$rows = $pdo->query("SELECT id, request_no, item_name, status, manager_id, station_id FROM stock_requests WHERE status = 'Approved'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID:{$r['id']} PR:{$r['request_no']} Item:{$r['item_name']} Mgr:{$r['manager_id']} Station:{$r['station_id']}\n";
}

echo "\n=== 'Purchase Order Generated' STATUS ===\n";
$rows = $pdo->query("SELECT id, request_no, item_name, status, manager_id, station_id FROM stock_requests WHERE status = 'Purchase Order Generated'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID:{$r['id']} PR:{$r['request_no']} Item:{$r['item_name']} Mgr:{$r['manager_id']} Station:{$r['station_id']}\n";
}

echo "\n=== FUEL 'Approved' STATUS ===\n";
$rows = $pdo->query("SELECT id, request_no, fuel_type, status, manager_id, station_id FROM fuel_stock_requests WHERE status = 'Approved'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID:{$r['id']} PR:{$r['request_no']} Fuel:{$r['fuel_type']} Mgr:{$r['manager_id']} Station:{$r['station_id']}\n";
}

echo "\n=== PENDING IN ADMIN (Waiting for PO) ===\n";
$rows = $pdo->query("SELECT id, request_no, item_name, approved_quantity, status FROM stock_requests WHERE status = 'Waiting for Purchase Order'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ID:{$r['id']} PR:{$r['request_no']} Item:{$r['item_name']} ApprQty:{$r['approved_quantity']}\n";
}
