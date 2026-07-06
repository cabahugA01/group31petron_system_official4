<?php
// Test login credential lookup
require_once __DIR__ . '/public/db_connect.php';

$test_logins = [  'testsuperadmin@petron.com',  'stafftest@gmail.com',  'manager@gmail.com',  'pepito@gmail.com',
];

foreach ($test_logins as $login) {  $stmt = $pdo->prepare("SELECT id, username, email, role, status, password_hash FROM users WHERE email = ? OR username = ? LIMIT 1");  $stmt->execute([$login, $login]);  $user = $stmt->fetch(PDO::FETCH_ASSOC);  if ($user) {  $has_hash = !empty($user['password_hash']);  echo " {$login} → role={$user['role']}, status={$user['status']}, has_hash=" . ($has_hash ? 'YES' : 'NO') . "\n";  } else {  echo " {$login} → NOT FOUND\n";  }
}
echo "\nAll original users tested.\n";
