<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->exec("DELETE FROM user_preferences WHERE preference_key LIKE 'badge_seen_%'");
    echo "[DONE] Cleaned all legacy badge_seen preferences from user_preferences.\n";
} catch (Exception $e) {}

$total_notifs = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
echo "[CONFIRMED] `notifications` table has exactly " . $total_notifs . " records in MySQL database.\n";
