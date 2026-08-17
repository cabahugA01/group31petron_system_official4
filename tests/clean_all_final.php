<?php
require_once __DIR__ . '/../public/db_connect.php';

// 1. Boost fuel_inventory levels
$pdo->exec("UPDATE fuel_inventory SET current_level = 10000, current_stock = 10000, capacity = 14000, status = 'active'");
echo "[1] fuel_inventory levels updated to 10,000L.\n";

// 2. Clear deliveries_oversight updates so no delivery alerts generated
try {
    $pdo->exec("UPDATE deliveries_oversight SET status = 'Completed' WHERE status IN ('Pending','Ordered','Expected Delivery','Ready for Stock-In')");
    echo "[2] deliveries_oversight set to Completed.\n";
} catch (Exception $e) {}

// 3. Truncate notifications completely
$pdo->exec("TRUNCATE TABLE notifications");
echo "[3] notifications table completely truncated (0 records).\n";

// 4. Test generator execution
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
