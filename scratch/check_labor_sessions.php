<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "<h2>Recent Labor Sessions</h2><pre>";
$stmt = $pdo->query("
    SELECT ls.id, ls.user_id, u.first_name, u.last_name, ls.shift_period, ls.start_time, ls.end_time
    FROM labor_sessions ls
    JOIN users u ON ls.user_id = u.id
    ORDER BY ls.start_time DESC LIMIT 15
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo implode(' | ', $r) . "\n";
}

echo "\n\n<h2>Active Sessions (end_time IS NULL)</h2><pre>";
$stmt = $pdo->query("
    SELECT ls.id, ls.user_id, u.first_name, u.last_name, ls.shift_period, ls.start_time
    FROM labor_sessions ls
    JOIN users u ON ls.user_id = u.id
    WHERE ls.end_time IS NULL
    ORDER BY ls.start_time DESC
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo implode(' | ', $r) . "\n";
}

echo "\n\n<h2>DISTINCT shift_period values</h2><pre>";
$stmt = $pdo->query("SELECT DISTINCT shift_period FROM labor_sessions");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
    echo var_export($v, true) . "\n";
}
