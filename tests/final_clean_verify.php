<?php
require_once __DIR__ . '/../public/db_connect.php';

// Final clean: truncate all notifications
$pdo->exec("TRUNCATE TABLE notifications");
echo "[1] Notifications table TRUNCATED - 0 records.\n";

// Check activity_logs for any remaining entries that could trigger false notifications
// Look for any 'clock' or 'Auto' entries that could match old loose patterns
$stmt = $pdo->query("
    SELECT action, COUNT(*) as cnt 
    FROM activity_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    GROUP BY action 
    ORDER BY cnt DESC 
    LIMIT 20
");
$log_actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n[2] Recent activity_log actions in last 48h:\n";
foreach ($log_actions as $a) {
    echo "   " . str_pad($a['action'], 50) . " x" . $a['cnt'] . "\n";
}

// Final badge check
require_once __DIR__ . '/../backend/lib.php';

echo "\n[3] Final badge counts across ALL roles:\n";
$roles = ['staff', 'manager', 'admin', 'superadmin'];
$all_zero = true;
foreach ($roles as $r) {
    $stmt = $pdo->prepare("SELECT id, username, role, station_id FROM users WHERE LOWER(role) = ? LIMIT 1");
    $stmt->execute([$r]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $st_id = (int)($u['station_id'] ?: 1);
        $cat = get_category_unread_counts($pdo, (int)$u['id'], role_key($u['role']), $st_id);
        $bell = ($cat['transactions'] ?? 0)
              + ($cat['fuel']         ?? 0)
              + ($cat['inventory']    ?? 0)
              + ($cat['customers']    ?? 0)
              + ($cat['prod_pricing'] ?? 0)
              + ($cat['reports']      ?? 0);
        if ($bell > 0) $all_zero = false;
        printf("   %-12s (User: %-15s) -> Header Bell: %-2d | All sidebar counts: %d\n",
            strtoupper($r), $u['username'], $bell, $bell
        );
    }
}

echo "\n";
if ($all_zero) {
    echo "[OK] ALL ROLES: Header bell = 0, Sidebar badges = 0. Database is CLEAN.\n";
} else {
    echo "[WARNING] Some roles still have non-zero counts. Review above.\n";
}
