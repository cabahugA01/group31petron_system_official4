<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';

echo "=== All users in users table ===\n";
$stmt = $pdo->query("SELECT id, employee_id, first_name, last_name, username, role, station_id FROM users");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$r['id']} | User: {$r['username']} | Name: {$r['first_name']} {$r['last_name']} | Role: {$r['role']}\n";
}
