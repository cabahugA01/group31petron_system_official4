<?php
require_once __DIR__ . '/../public/db_connect.php';

// Migrate notifications table - add new columns safely
$migrations = [
    "ALTER TABLE notifications ADD COLUMN recipient_role VARCHAR(30) NULL AFTER user_id",
    "ALTER TABLE notifications ADD COLUMN reference_type VARCHAR(80) NULL AFTER redirect_url",
    "ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER reference_type",
    "ALTER TABLE notifications ADD COLUMN shift_period VARCHAR(20) NULL AFTER reference_id",
];

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        echo "[ADDED] " . preg_match('/ADD COLUMN (\w+)/', $sql, $m) ? $m[1] : '?' ;
        echo "\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "[EXISTS] Column already exists - OK\n";
        } else {
            echo "[ERROR] " . $e->getMessage() . "\n";
        }
    }
}

// Add indexes
$indexes = [
    "ALTER TABLE notifications ADD INDEX idx_ref (reference_type, reference_id)",
    "ALTER TABLE notifications ADD INDEX idx_shift (shift_period)",
    "ALTER TABLE notifications ADD INDEX idx_role (recipient_role)",
];
foreach ($indexes as $sql) {
    try { $pdo->exec($sql); echo "[INDEX ADDED]\n"; }
    catch (PDOException $e) { echo "[INDEX EXISTS or SKIP]\n"; }
}

echo "\n=== Final notifications schema ===\n";
$cols = $pdo->query("DESCRIBE notifications")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";

// Check if void_request pending table needed
echo "\n=== Checking void workflow tables ===\n";
foreach (['voided_transactions','merchandise_transactions'] as $t) {
    $r = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "  $t: $r rows\n";
}
// Check workflow_status in merchandise_transactions
$r = $pdo->query("SELECT DISTINCT workflow_status FROM merchandise_transactions LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
echo "  merchandise_transactions.workflow_status values: " . implode(', ', $r) . "\n";
