<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $stmt = $pdo->query("SELECT * FROM system_settings LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
