<?php
/**
 * Batch ID Backfill Script
 * Updates old-format batch IDs (B-YYYYMMDD-PO####, SI-*) in merchandise_batches
 * and backfills products that have stock but no batch record.
 */
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$updated = 0;
$created = 0;

// 1. Fix old B-YYYYMMDD-PO#### batch numbers in merchandise_batches
$old_fmt = $pdo->query("SELECT id, batch_number, date_received FROM merchandise_batches WHERE batch_number LIKE 'B-%' OR batch_number LIKE 'SI-%' OR batch_number LIKE 'FI-SI-%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($old_fmt as $row) {
    $date_str = date('Ymd', strtotime($row['date_received'] ?? 'now'));
    $seq = $pdo->query("SELECT COUNT(*) + 1 FROM merchandise_batches WHERE batch_number LIKE 'BT-{$date_str}-%' AND id != {$row['id']}")->fetchColumn();
    $new_bn = 'BT-' . $date_str . '-' . str_pad((int)$seq, 4, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE merchandise_batches SET batch_number = ? WHERE id = ?")->execute([$new_bn, $row['id']]);
    echo "  Updated batch id={$row['id']}: [{$row['batch_number']}] → [{$new_bn}]\n";
    $updated++;
}

// 2. Backfill products with stock but no active batch record
$products_no_batch = $pdo->query("
    SELECT p.id, p.name, p.current_stock, p.updated_at, p.cost
    FROM products p
    WHERE p.station_id = {$station_id}
      AND p.current_stock > 0
      AND NOT EXISTS (
          SELECT 1 FROM merchandise_batches mb 
          WHERE mb.product_id = p.id AND mb.status = 'active'
      )
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($products_no_batch as $prod) {
    $date_str = date('Ymd', strtotime($prod['updated_at'] ?? 'now'));
    $seq = $pdo->query("SELECT COUNT(*) + 1 FROM merchandise_batches WHERE batch_number LIKE 'BT-{$date_str}-%'")->fetchColumn();
    $new_bn = 'BT-' . $date_str . '-' . str_pad((int)$seq, 4, '0', STR_PAD_LEFT);
    
    $pdo->prepare("
        INSERT INTO merchandise_batches 
            (product_id, station_id, batch_number, quantity_received, remaining_qty, 
             unit_cost, supplier, date_received, encoded_by, status, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'Petron Corporation', ?, NULL, 'active', 'Auto-backfill batch record', NOW(), NOW())
    ")->execute([
        $prod['id'], $station_id, $new_bn,
        (int)$prod['current_stock'], (int)$prod['current_stock'],
        (float)($prod['cost'] ?? 0),
        date('Y-m-d', strtotime($prod['updated_at'] ?? 'now'))
    ]);
    echo "  Created batch for product [{$prod['name']}]: {$new_bn} (qty={$prod['current_stock']})\n";
    $created++;
}

echo "\nDone. Updated: {$updated}, Created: {$created}\n";
