<?php
// Fix backend/api/stock_request.php
$file1 = 'c:/xampp/htdocs/group31petron_system_official4/backend/api/stock_request.php';
$c1 = file_get_contents($file1);

// Add helper function sr_get_safe_user_id
$helper_func = <<<'PHP'
function sr_get_safe_user_id(PDO $pdo, array $me, int $station_id): ?int {
    $user_id = (int)($me['id'] ?? 0);
    if ($user_id > 0) {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $chk->execute([$user_id]);
            if ($chk->fetchColumn()) {
                return $user_id;
            }
        } catch (Exception $e) {}
    }
    try {
        $chk_alt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? ORDER BY id ASC LIMIT 1");
        $chk_alt->execute([$station_id]);
        $found = $chk_alt->fetchColumn();
        return $found ? (int)$found : null;
    } catch (Exception $e) {}

    return null;
}

PHP;

if (strpos($c1, 'sr_get_safe_user_id') === false) {
    $c1 = str_replace("function sr_resolve_merch_product", $helper_func . "function sr_resolve_merch_product", $c1);
}

// In handle_create of stock_request.php, replace $me['id'] with $safe_staff_id for audit logs & performed_by
$c1 = str_replace(
    "\$request_id, \$me['id'], \$role,",
    "\$request_id, \$safe_staff_id, \$role,",
    $c1
);
$c1 = str_replace(
    "log_activity(\$pdo, \$me['id'], 'Create Stock Request',",
    "log_activity(\$pdo, \$safe_staff_id, 'Create Stock Request',",
    $c1
);
$c1 = str_replace(
    "->execute([\$me['id'], \$detail, \$request_id, \$ip, \$ua]);",
    "->execute([\$safe_staff_id, \$detail, \$request_id, \$ip, \$ua]);",
    $c1
);

file_put_contents($file1, $c1);
echo "Updated backend/api/stock_request.php!\n";

// Fix backend/api/fuel_stock_request.php
$file2 = 'c:/xampp/htdocs/group31petron_system_official4/backend/api/fuel_stock_request.php';
$c2 = file_get_contents($file2);

if (strpos($c2, 'sr_get_safe_user_id') === false) {
    $c2 = str_replace("function get_next_request_no", $helper_func . "function get_next_request_no", $c2);
}

$c2 = str_replace(
    "\$request_id, \$me['id'], \$role,",
    "\$request_id, \$safe_staff_id, \$role,",
    $c2
);
$c2 = str_replace(
    "log_activity(\$pdo, \$me['id'], 'Create Fuel Stock Request',",
    "log_activity(\$pdo, \$safe_staff_id, 'Create Fuel Stock Request',",
    $c2
);

file_put_contents($file2, $c2);
echo "Updated backend/api/fuel_stock_request.php!\n";
