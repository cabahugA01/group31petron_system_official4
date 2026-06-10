<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "Job Orders Table Schema:\n";
echo str_repeat("=", 70) . "\n\n";

// Get all columns
$cols = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_ASSOC);

echo "Columns related to users:\n";
foreach ($cols as $c) {
    $field = $c['Field'];
    if (stripos($field, 'user') !== false || 
        stripos($field, 'mechanic') !== false || 
        stripos($field, 'created') !== false || 
        stripos($field, 'staff') !== false ||
        stripos($field, 'assigned') !== false ||
        stripos($field, 'validated') !== false) {
        echo "  - {$field} ({$c['Type']})\n";
    }
}

echo "\n";
echo "Users Table Primary Key:\n";
$user_cols = $pdo->query("SHOW COLUMNS FROM users WHERE `Key` = 'PRI'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($user_cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']})\n";
}
?>
