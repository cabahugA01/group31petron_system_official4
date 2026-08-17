<?php
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SHOW TABLES LIKE '%notif%'");
$notif_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt2 = $pdo->query("SHOW TABLES LIKE '%alert%'");
$alert_tables = $stmt2->fetchAll(PDO::FETCH_COLUMN);

echo "--- Notification & Alert Tables in Database ---\n";
foreach (array_merge($notif_tables, $alert_tables) as $t) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo str_pad($t, 30) . ": " . $cnt . " rows\n";
}

// Check notifications table count
$total_notifs = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
echo "\nTotal rows in `notifications` table: " . $total_notifs . "\n";

// Check user isolation in notifications table structure
$schema = $pdo->query("DESCRIBE notifications")->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- `notifications` Table Schema (Checking user isolation) ---\n";
foreach ($schema as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ") - Key: " . $col['Key'] . "\n";
}
