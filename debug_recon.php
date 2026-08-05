<?php
require_once 'public/db_connect.php';

// Check fuel_reconciliation table
echo "=== fuel_reconciliation COLUMNS ===" . PHP_EOL;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM fuel_reconciliation")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ") null=" . $c['Null'] . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== fuel_reconciliation sample rows ===" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT * FROM fuel_reconciliation ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
    if (empty($rows)) echo "(empty)" . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== fuel_transactions sample ===" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT * FROM fuel_transactions ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

// Check delivery_validation / deliveries table
echo PHP_EOL . "=== delivery-related tables ===" . PHP_EOL;
foreach (['delivery_validation', 'deliveries', 'fuel_deliveries', 'received_items', 'receiving_batches'] as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  $t: $c rows" . PHP_EOL;
    } catch (Exception $e) { echo "  $t: NOT FOUND" . PHP_EOL; }
}
