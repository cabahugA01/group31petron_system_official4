<?php
require_once __DIR__ . '/../public/db_connect.php';
$rows = $pdo->query("SELECT id, first_name, last_name, role, assigned_shift, shift_start_time, shift_end_time, shift_assignment FROM users LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID:{$r['id']} | {$r['first_name']} {$r['last_name']} | role:{$r['role']} | assigned_shift:{$r['assigned_shift']} | shift_assignment:{$r['shift_assignment']} | start:{$r['shift_start_time']} | end:{$r['shift_end_time']}" . PHP_EOL;
}
