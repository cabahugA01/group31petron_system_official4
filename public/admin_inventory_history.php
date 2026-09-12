<?php
// Smart router fallback for legacy/notification links
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'admin');

$type = strtolower(trim($_GET['type'] ?? ''));
$qs   = strtolower($_SERVER['QUERY_STRING'] ?? '');

$is_merch = ($type === 'merch' || $type === 'merchandise' || strpos($qs, 'merch') !== false);

if (in_array($role, ['admin', 'superadmin'])) {
    $target = $is_merch ? 'admin_inventory_merchandise.php' : 'admin_inventory_fuel.php';
} elseif (in_array($role, ['manager', 'supervisor'])) {
    $target = $is_merch ? 'manager_inventory_merchandise.php' : 'manager_inventory_fuel.php';
} else {
    $target = $is_merch ? 'staff_inventory_merchandise.php' : 'staff_inventory_fuel.php';
}

header("Location: {$target}");
exit;