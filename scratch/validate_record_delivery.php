<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$station_id = 1253;
$grouped_merch_pos = [];
$grouped_fuel_pos  = [];

// ── Merchandise POs ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT po.id, po.po_number, po.status,
           po.product_name AS po_product_name,
           po.quantity AS po_quantity,
           po.expected_delivery_date, po.created_at, po.remarks,
           s.name as supplier_name,
           CONCAT(u_prep.first_name, ' ', u_prep.last_name) AS prepared_by_name,
           CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u_prep ON po.created_by = u_prep.id
    LEFT JOIN users u_app ON po.approved_by = u_app.id
    WHERE po.station_id = ?
      AND po.status IN ('Admin Finalized', 'Approved')
      AND po.type = 'merch'
    ORDER BY po.expected_delivery_date ASC, po.created_at ASC
");
$stmt->execute([$station_id]);
$merch_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($merch_raw as $row) {
    $po_num = $row['po_number'];
    if (!isset($grouped_merch_pos[$po_num])) {
        $grouped_merch_pos[$po_num] = [
            'id'          => $row['id'],
            'po_number'   => $po_num,
            'supplier_name' => $row['supplier_name'] ?? 'Petron Corporation',
            'status'      => $row['status'],
            'prepared_by_name' => $row['prepared_by_name'] ?: 'Manager',
            'approved_by_name' => $row['approved_by_name'] ?: 'Admin',
            'po_ids'      => [],
            'items'       => []
        ];
    }
    $grouped_merch_pos[$po_num]['po_ids'][] = $row['id'];
    if (!empty($row['po_product_name'])) {
        $grouped_merch_pos[$po_num]['items'][] = [
            'item_id'      => 'po_' . $row['id'],
            'product_name' => $row['po_product_name'],
            'ordered_qty'  => (float)$row['po_quantity'],
            'unit'         => 'pcs',
            'sku'          => '—',
            'from_po_row'  => true
        ];
    }
}

// For each group, check purchase_order_items (newer format)
foreach ($grouped_merch_pos as $po_num => &$po_group) {
    $all_po_ids = $po_group['po_ids'];
    $in_ph = implode(',', array_fill(0, count($all_po_ids), '?'));
    $poi_stmt = $pdo->prepare("
        SELECT poi.id as item_id, poi.item_name as product_name, poi.quantity as ordered_qty,
               ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
        FROM purchase_order_items poi
        LEFT JOIN inventory_products ip ON poi.product_id = ip.id
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE poi.po_id IN ($in_ph)
    ");
    $poi_stmt->execute(array_merge([$station_id], $all_po_ids));
    $poi_rows = $poi_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($poi_rows)) {
        $po_group['items'] = [];
        foreach ($poi_rows as $pi) {
            $po_group['items'][] = [
                'item_id'      => $pi['item_id'],
                'product_name' => $pi['product_name'],
                'ordered_qty'  => (float)$pi['ordered_qty'],
                'unit'         => format_merch_unit($pi['unit'] ?? 'pcs'),
                'sku'          => $pi['sku'] ?? '—',
                'from_po_row'  => false
            ];
        }
    } else {
        foreach ($po_group['items'] as &$item) {
            $u_stmt = $pdo->prepare("
                SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                FROM inventory_products ip
                LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                WHERE ip.product_name = ? LIMIT 1
            ");
            $u_stmt->execute([$station_id, $item['product_name']]);
            $found_unit = $u_stmt->fetchColumn();
            $item['unit'] = format_merch_unit($found_unit ?: 'pcs');
        }
        unset($item);
    }
}
unset($po_group);

echo "=== MERCHANDISE POs (grouped by po_number) ===\n";
echo "Total distinct PO groups: " . count($grouped_merch_pos) . "\n";
foreach ($grouped_merch_pos as $po) {
    echo "\n  PO: {$po['po_number']} | Status: {$po['status']} | Supplier: {$po['supplier_name']}\n";
    echo "  PO IDs: " . implode(', ', $po['po_ids']) . "\n";
    echo "  Items (" . count($po['items']) . "):\n";
    foreach ($po['items'] as $item) {
        $src = $item['from_po_row'] ? '[legacy]' : '[poi]';
        echo "    {$src} {$item['product_name']} | Qty: {$item['ordered_qty']} {$item['unit']}\n";
    }
}

// ── Fuel POs ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT fpo.*, ft.name as fuel_type_name, s.name as supplier_name,
           CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name
    FROM fuel_purchase_orders fpo
    LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
    LEFT JOIN suppliers s ON fpo.supplier_id = s.id
    LEFT JOIN users u_app ON fpo.approved_by = u_app.id
    WHERE fpo.station_id = ?
      AND fpo.status IN ('Approved PO', 'Approved')
    ORDER BY fpo.expected_delivery_date ASC, fpo.created_at ASC
");
$stmt->execute([$station_id]);
$fuel_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($fuel_raw as $row) {
    $po_num = $row['po_number'];
    if (!isset($grouped_fuel_pos[$po_num])) {
        $grouped_fuel_pos[$po_num] = [
            'po_number'    => $po_num,
            'supplier_name'=> $row['supplier_name'] ?? 'Petron Corp',
            'approved_by'  => $row['approved_by_name'] ?: 'Manager',
            'status'       => $row['status'],
            'expected_delivery_date' => $row['expected_delivery_date'],
            'items'        => []
        ];
    }
    $grouped_fuel_pos[$po_num]['items'][] = [
        'id'          => $row['id'],
        'fuel_type'   => $row['fuel_type_name'] ?: 'Fuel',
        'ordered_qty' => (float)$row['volume']
    ];
}

echo "\n\n=== FUEL POs (grouped by po_number) ===\n";
echo "Total distinct PO groups: " . count($grouped_fuel_pos) . "\n";
foreach ($grouped_fuel_pos as $po) {
    $total_liters = array_sum(array_column($po['items'], 'ordered_qty'));
    echo "\n  PO: {$po['po_number']} | Status: {$po['status']} | Total: " . number_format($total_liters) . " L\n";
    foreach ($po['items'] as $item) {
        echo "    - {$item['fuel_type']} | {$item['ordered_qty']} L\n";
    }
}

echo "\n\n=== SUMMARY CARD STATS ===\n";
$count_pending_merch_pos = count($grouped_merch_pos);
echo "Pending Merch POs: $count_pending_merch_pos\n";

$s = $pdo->prepare("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id=? AND delivery_type='merchandise' AND DATE(delivery_date)=CURDATE()");
$s->execute([$station_id]);
echo "Deliveries Today: " . (int)$s->fetchColumn() . "\n";

$s = $pdo->prepare("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id=? AND delivery_type='merchandise' AND status='Pending Stock-In'");
$s->execute([$station_id]);
echo "Pending Stock-In: " . (int)$s->fetchColumn() . "\n";

$s = $pdo->prepare("SELECT COUNT(DISTINCT delivery_ref) FROM deliveries_oversight WHERE station_id=? AND delivery_type='merchandise' AND status IN ('Stock-In Complete','Confirmed','Closed')");
$s->execute([$station_id]);
echo "Completed: " . (int)$s->fetchColumn() . "\n";
