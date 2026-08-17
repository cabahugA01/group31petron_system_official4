<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== notifications table columns ===\n";
foreach ($pdo->query('DESCRIBE notifications')->fetchAll(PDO::FETCH_ASSOC) as $c)
    echo $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . $c['Default'] . "\n";

echo "\n=== Distinct recipient_roles in notifications ===\n";
foreach ($pdo->query("SELECT recipient_role, COUNT(*) as cnt FROM notifications GROUP BY recipient_role ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo json_encode($r) . "\n";

echo "\n=== Sample notifications (last 15, newest first) ===\n";
foreach ($pdo->query("SELECT id,recipient_role,type,event_type,title,status,created_at FROM notifications ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo json_encode($r) . "\n";

echo "\n=== Distinct event_type/type combos ===\n";
foreach ($pdo->query("SELECT DISTINCT type, event_type FROM notifications ORDER BY type, event_type")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo $r['type'] . ' / ' . $r['event_type'] . "\n";

echo "\n=== Total count per status ===\n";
foreach ($pdo->query("SELECT status, COUNT(*) as cnt FROM notifications GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo json_encode($r) . "\n";
