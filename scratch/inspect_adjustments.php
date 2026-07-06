<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== DISTINCT adjustment_type IN fuel_adjustments ===\n";
$types = $pdo->query("SELECT adjustment_type, COUNT(*) as count FROM fuel_adjustments GROUP BY adjustment_type")->fetchAll(PDO::FETCH_ASSOC);
foreach ($types as $t) {  echo "- {$t['adjustment_type']}: {$t['count']} rows\n";
}

echo "\n=== ALL ROWS IN fuel_adjustments ===\n";
$rows = $pdo->query("SELECT id, adjustment_type, reason, liters, previous_value, new_value FROM fuel_adjustments LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {  echo "- ID {$r['id']} | Type: {$r['adjustment_type']} | Liters: {$r['liters']} | Prev: {$r['previous_value']} | New: {$r['new_value']} | Reason: {$r['reason']}\n";
}
