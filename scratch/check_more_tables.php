<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== DESCRIBE fuel_transaction_audit ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_transaction_audit")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== DESCRIBE fuel_audit_trail ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_audit_trail")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== DESCRIBE validation_actions_log ===\n";
try {
    $cols = $pdo->query("DESCRIBE validation_actions_log")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== DESCRIBE fuel_deliveries ===\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_deliveries")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} - {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
