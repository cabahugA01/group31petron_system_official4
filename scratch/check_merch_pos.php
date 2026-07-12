<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- Merchandise POs with Admin Finalized/Approved status ---\n";
$pos = $pdo->query("SELECT id, po_number, status, type FROM purchase_orders WHERE type='merch' AND status IN ('Admin Finalized','Approved')")->fetchAll(PDO::FETCH_ASSOC);
print_r($pos);

echo "\n--- Items for those POs ---\n";
foreach ($pos as $po) {
    echo "\nPO id={$po['id']} ({$po['po_number']}):\n";
    $items = $pdo->prepare("SELECT poi.id, poi.item_name, poi.quantity, ip.unit FROM purchase_order_items poi LEFT JOIN inventory_products ip ON poi.product_id = ip.id WHERE poi.po_id = ?");
    $items->execute([$po['id']]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
}
