<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

echo "=== users table ===\n";
try {
    $q = $pdo->query("DESCRIBE users");
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$r['Field']} - {$r['Type']} (Null: {$r['Null']}, Key: {$r['Key']}, Default: {$r['Default']})\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}
