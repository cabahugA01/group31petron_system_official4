<?php
require_once 'C:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';

$tables = ['stock_requests', 'fuel_stock_requests', 'stock_request_audit', 'fuel_stock_request_audit', 'notifications'];
foreach ($tables as $t) {
    try {
        $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_COLUMN);
        echo $t . ': ' . implode(', ', $cols) . "<br>\n";
    } catch (Exception $e) {
        echo $t . ': <b>NOT FOUND</b><br>' . "\n";
    }
}
