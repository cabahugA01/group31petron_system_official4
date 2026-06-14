<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $stmt = $pdo->query("DESCRIBE system_settings");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
