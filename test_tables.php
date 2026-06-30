<?php
require_once __DIR__ . '/public/db_connect.php';
try {
    echo "Connecting...\n";
    $tables = array_column($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
    echo "Tables in schema: " . count($tables) . "\n";
    foreach ($tables as $t) {
        try {
            $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
            echo "[OK] {$t}\n";
        } catch (Exception $e) {
            echo "[ERROR] {$t}: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
