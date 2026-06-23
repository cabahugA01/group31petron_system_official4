<?php
require_once __DIR__ . '/../public/db_connect.php';

function print_columns($pdo, $table) {
    try {
        echo "=== Columns of $table ===\n";
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

print_columns($pdo, 'merchandise_transactions');
print_columns($pdo, 'job_orders');
