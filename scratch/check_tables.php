<?php
require_once 'C:/xampp/htdocs/group31petron_system_official4/backend/config/database.php';
$pdo = getDatabaseConnection();

$tables = ['stock_requests', 'fuel_stock_requests', 'stock_request_audit', 'fuel_stock_request_audit', 'notifications'];
foreach ($tables as $t) {
    try {
        $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_COLUMN);
        echo $t . ': ' . implode(', ', $cols) . "\n";
    } catch (Exception $e) {
        echo $t . ': NOT FOUND - ' . $e->getMessage() . "\n";
    }
}
