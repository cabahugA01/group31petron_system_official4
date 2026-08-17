<?php
require_once __DIR__ . '/../public/db_connect.php';

$pdo->exec("UPDATE fuel_stock_requests SET status = 'Completed'");
$pdo->exec("UPDATE fuel_adjustments SET status = 'Completed'");
$pdo->exec("UPDATE fuel_transactions SET status = 'Completed'");
$pdo->exec("UPDATE purchase_orders SET status = 'Completed', delivery_validated = 0");

echo "\n--- VERIFYING CLEAN BADGE COUNTS (ALL ROLES) ---\n";

require_once __DIR__ . '/../backend/lib.php';

$roles_to_check = ['staff', 'manager', 'admin', 'superadmin'];
foreach ($roles_to_check as $r) {
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
