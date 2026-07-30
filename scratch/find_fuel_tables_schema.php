<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "=== TABLES ANALYSIS ===\n";
foreach ($tables as $t) {
    if (strpos($t, 'fuel') !== false || strpos($t, 'report') !== false || strpos($t, 'shift') !== false || strpos($t, 'reading') !== false || strpos($t, 'transaction') !== false) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo sprintf("%-35s : %d rows\n", $t, $count);
    }
}
?>
