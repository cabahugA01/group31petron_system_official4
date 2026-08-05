<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "TABLES FOUND: " . count($tables) . "\n\n";
    foreach ($tables as $t) {
        echo "=== TABLE: $t ===\n";
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  - {$c['Field']} ({$c['Type']})\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
