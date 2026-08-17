<?php
$dir = __DIR__ . '/../public';
$skip = ['login.php','index.php','logout.php','register.php','refresh_captcha.php',
         'update_pass.php','db_connect.php','receipt.pdf.php','superadmin_product_pricing.php'];

$with_require = [];
$with_session_only = [];
$no_protect = [];

foreach (glob($dir . '/*.php') as $fpath) {
    $name = basename($fpath);
    if (in_array($name, $skip)) continue;
    if (preg_match('/^(print_|verify|receipt_|report_pdf|sales_reports_export|staff_customer_export|staff_inventory_fuel_export|staff_inventory_merchandise_export|setup_inventory|update_password|receipt\.php)/', $name)) continue;

    $c = file_get_contents($fpath);
    if (!$c) continue;

    if (preg_match('/require_login\s*\(\s*\)/', $c)) {
        $with_require[] = $name;
    } elseif (preg_match('/session_start\s*\(/', $c)) {
        $with_session_only[] = $name;
    } else {
        $no_protect[] = $name;
    }
}

sort($with_session_only);
sort($no_protect);

echo "=== Pages WITH require_login(): " . count($with_require) . " ===\n";
echo "=== Pages with session_start but NO require_login(): " . count($with_session_only) . " ===\n";
foreach ($with_session_only as $f) echo "  NEEDS_GUARD: $f\n";
echo "\n=== Pages with NO session protection: " . count($no_protect) . " ===\n";
foreach ($no_protect as $f) echo "  UNPROTECTED: $f\n";
