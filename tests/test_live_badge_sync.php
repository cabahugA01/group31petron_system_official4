<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$stmt = $pdo->query("SELECT id, username, role, station_id FROM users WHERE username LIKE '%judy%' OR role = 'staff' LIMIT 1");
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if ($u) {
    $role = role_key($u['role']);
    $station_id = (int)$u['station_id'];
    $user_id = (int)$u['id'];
    
    $cat = get_category_unread_counts($pdo, $user_id, $role, $station_id);
    $bell = ($cat['transactions'] ?? 0)
          + ($cat['fuel']         ?? 0)
          + ($cat['inventory']    ?? 0)
          + ($cat['customers']    ?? 0)
          + ($cat['prod_pricing'] ?? 0)
          + ($cat['reports']      ?? 0);
          
    echo "User: " . $u['username'] . " (Role: " . $u['role'] . ", Station ID: " . $station_id . ")\n";
    echo "--- Sidebar Category Breakdown ---\n";
    echo "Transactions:   " . ($cat['transactions'] ?? 0) . "\n";
    echo "Fuel:           " . ($cat['fuel'] ?? 0) . "\n";
    echo "Inventory:      " . ($cat['inventory'] ?? 0) . "\n";
    echo "Customers:      " . ($cat['customers'] ?? 0) . "\n";
    echo "Prod Pricing:   " . ($cat['prod_pricing'] ?? 0) . "\n";
    echo "Reports:        " . ($cat['reports'] ?? 0) . "\n";
    echo "-----------------------------------\n";
    echo "Header Bell Count (Total Sidebar): " . $bell . "\n";
} else {
    echo "No staff user found.\n";
}
