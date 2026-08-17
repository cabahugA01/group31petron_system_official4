<?php
require_once __DIR__ . '/../public/db_connect.php';

// 1. Remove mock activity logs
$pdo->exec("
    DELETE FROM activity_logs 
    WHERE action IN (
        'Server downtime detected - Node-4 offline',
        'High CPU usage alert',
        'Database connection error: timeout',
        'API connection failure: FleetCard sync',
        'Git merge conflict',
        'Sync job delay: ERP',
        'Password reset request',
        'Suspicious activity flagged',
        'Deployment: release-v2.4.1',
        'Rollback action: release-v2.4.0'
    )
");
echo "[1] Removed artificial mock developer activity logs.\n";

// 2. Truncate notifications table completely (all roles: Staff, Manager, Admin, Superadmin, Developer)
$pdo->exec("TRUNCATE TABLE notifications");
echo "[2] Truncated `notifications` table for all roles.\n";

// 3. Verify clean state across all roles
echo "\n--- VERIFYING ALL ROLES (STAFF, MANAGER, ADMIN, SUPERADMIN) ---\n";

require_once __DIR__ . '/../backend/lib.php';

$roles = ['staff', 'manager', 'admin', 'superadmin'];
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
        printf("%-12s (User: %-15s) -> Header Bell: %-2d | Sidebar Badges: %s\n",
            strtoupper($r), $u['username'], $bell, json_encode($cat)
        );
    }
}
