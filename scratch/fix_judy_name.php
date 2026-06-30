<?php
require_once __DIR__ . '/../public/db_connect.php';

// Fix Judy's duplicate name - first_name should be just "Judy", last_name "Lastimosa"
$pdo->prepare("UPDATE users SET first_name = 'Judy', last_name = 'Lastimosa' WHERE id = 9 AND username = 'judy'")->execute();
echo "Fixed Judy's name." . PHP_EOL;

// Verify
$row = $pdo->query("SELECT id, first_name, last_name, username FROM users WHERE id = 9")->fetch(PDO::FETCH_ASSOC);
echo "After fix: " . json_encode($row) . PHP_EOL;
