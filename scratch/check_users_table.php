<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== users Columns ===\n";
try {
    $stmt = $pdo->query("DESCRIBE `users`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
} catch (Exception $e) {
    echo "Error describing users: " . $e->getMessage() . "\n";
}
