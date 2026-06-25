<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== fuel_adjustments structure ===\n";
foreach ($pdo->query("DESCRIBE fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo "{$col['Field']} - {$col['Type']} - Default: {$col['Default']}\n";
}
echo "\n=== distinct adjustment_type values ===\n";
try {
    $rows = $pdo->query("SELECT DISTINCT adjustment_type, COUNT(*) as cnt FROM fuel_adjustments GROUP BY adjustment_type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo "  {$r['adjustment_type']} ({$r['cnt']})\n";
} catch (Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== distinct status values ===\n";
try {
    $rows = $pdo->query("SELECT DISTINCT status, COUNT(*) as cnt FROM fuel_adjustments GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo "  ".($r['status']??'NULL')." ({$r['cnt']})\n";
} catch (Exception $e) { echo $e->getMessage()."\n"; }

echo "\n=== sample rows ===\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_adjustments LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo $e->getMessage()."\n"; }
