<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FINAL SYSTEM INTEGRITY REPORT ===" . PHP_EOL . PHP_EOL;

// 1. Users check
echo "--- USERS ---" . PHP_EOL;
$users = $pdo->query("SELECT id, employee_id, CONCAT(first_name,' ',last_name) AS full_name, username, email, role, status, assigned_shift, station_id FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "  [{$u['id']}] {$u['full_name']} | {$u['role']} | {$u['username']} | {$u['email']} | {$u['status']} | Shift: " . ($u['assigned_shift'] ?? 'N/A') . " | Station: {$u['station_id']}" . PHP_EOL;
}

echo PHP_EOL . "--- STATIONS (only station 1253) ---" . PHP_EOL;
$s = $pdo->query("SELECT id, name, status FROM stations WHERE id = 1253")->fetch(PDO::FETCH_ASSOC);
echo "  Station: " . json_encode($s) . PHP_EOL;

// 2. Check password_reset_tokens table
echo PHP_EOL . "--- PASSWORD_RESET_TOKENS ---" . PHP_EOL;
try {
    $tokens = $pdo->query("SELECT user_id, token_type, is_used, expires_at FROM password_reset_tokens ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tokens as $t) {
        echo "  " . json_encode($t) . PHP_EOL;
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . PHP_EOL;
}

// 3. Activity logs
echo PHP_EOL . "--- ACTIVITY_LOGS (last 5) ---" . PHP_EOL;
try {
    $logs = $pdo->query("SELECT id, user_id, action, details, created_at FROM activity_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as $l) {
        echo "  [{$l['id']}] user#{$l['user_id']} | {$l['action']} | {$l['created_at']}" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "  Table missing or error: " . $e->getMessage() . PHP_EOL;
}

// 4. Check for duplicate usernames
echo PHP_EOL . "--- DUPLICATE USERNAME CHECK ---" . PHP_EOL;
$dupes = $pdo->query("SELECT username, COUNT(*) as cnt FROM users GROUP BY username HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($dupes)) {
    echo "  No duplicate usernames found. ✅" . PHP_EOL;
} else {
    foreach ($dupes as $d) {
        echo "  DUPLICATE: {$d['username']} ({$d['cnt']} times) ❌" . PHP_EOL;
    }
}

// 5. Check for duplicate emails
echo PHP_EOL . "--- DUPLICATE EMAIL CHECK ---" . PHP_EOL;
$dupes2 = $pdo->query("SELECT email, COUNT(*) as cnt FROM users WHERE email IS NOT NULL AND email != '' GROUP BY email HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
if (empty($dupes2)) {
    echo "  No duplicate emails found. ✅" . PHP_EOL;
} else {
    foreach ($dupes2 as $d) {
        echo "  DUPLICATE: {$d['email']} ({$d['cnt']} times) ❌" . PHP_EOL;
    }
}

echo PHP_EOL . "=== DONE ===" . PHP_EOL;
