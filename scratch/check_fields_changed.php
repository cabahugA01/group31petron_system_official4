<?php
require __DIR__ . '/../public/db_connect.php';

$row = $pdo->query("SELECT * FROM transaction_adjustments LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "=== fields_changed Sample ===\n";
if ($row) {
    echo "JSON string: " . $row['fields_changed'] . "\n\n";
    print_r(json_decode($row['fields_changed'], true));
} else {
    echo "No adjustments found in database.\n";
}
