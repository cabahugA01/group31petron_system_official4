<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== JUDY USER DETAILS ===\n";
print_r($pdo->query("SELECT id, username, role, station_id, assigned_shift FROM users WHERE username = 'Judy'")->fetch(PDO::FETCH_ASSOC));
