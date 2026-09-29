<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$tables = ['pending_price_approvals', 'price_requests', 'fuel_price_requests', 'station_inventory', 'fuel_types', 'fuel_prices'];
$info = [];

foreach ($tables as $t) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        $count = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $sample = $pdo->query("SELECT * FROM {$t} LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $info[$t] = [
            'exists' => true,
            'count' => $count,
            'columns' => array_column($cols, 'Field'),
            'sample' => $sample
        ];
    } catch (Exception $e) {
        $info[$t] = ['exists' => false, 'error' => $e->getMessage()];
    }
}

// Check notifications for Kaloy Larosa and Edgar Eslit
$users = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.role, u.station_id, s.name as station_name
    FROM users u
    LEFT JOIN stations s ON s.id = u.station_id
    WHERE u.first_name LIKE '%kaloy%' OR u.first_name LIKE '%edgar%'
")->fetchAll(PDO::FETCH_ASSOC);

$notifs = [];
foreach ($users as $u) {
    $n = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $n->execute([$u['id']]);
    $notifs[$u['first_name'] . ' (Station ' . $u['station_id'] . ')'] = $n->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'tables' => $info,
    'users' => $users,
    'recent_notifications' => $notifs
], JSON_PRETTY_PRINT);
