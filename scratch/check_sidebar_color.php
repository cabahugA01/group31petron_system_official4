<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $stmt = $pdo->query("SELECT * FROM system_settings WHERE setting_key IN ('sidebar_color', 'primary_color', 'button_color')");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
