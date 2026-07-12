<?php
// Simulate admin session
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = [
    'id' => 4, 'name' => 'Kathrine Pepito', 'username' => 'pepito@gmail.com',
    'role' => 'admin', 'station_id' => 1253
];

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$me = current_user();
$role = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);
$myStationId = (int)($me['station_id'] ?? 0);

$station_param = $myStationId ? [$myStationId] : [];
$station_where = $myStationId ? "station_id = ? AND " : "";

$safe_count = function(string $sql, array $params = []) use ($pdo) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
};

$counts = [];
$total = 0;

$counts['merch_tx'] = $safe_count("SELECT COUNT(*) FROM merchandise_transactions WHERE {$station_where}LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')", $station_param);
$counts['fuel_tx'] = $safe_count("SELECT COUNT(*) FROM fuel_transactions WHERE {$station_where}LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')", $station_param);
$counts['job_orders'] = $safe_count("SELECT COUNT(*) FROM job_orders WHERE {$station_where}LOWER(COALESCE(validation_status,status,'')) IN ('pending','pending validation','pending_validation')", $station_param);
$counts['merch_po'] = $safe_count("SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted') AND COALESCE(admin_finalized,0)=0", $station_param);
$counts['fuel_po'] = $safe_count("SELECT COUNT(*) FROM fuel_purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted')", $station_param);
$counts['customers'] = $safe_count("SELECT COUNT(*) FROM customers WHERE {$station_where}LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','pending_validation','for review')", $station_param);
$counts['prices'] = $safe_count("SELECT COUNT(*) FROM pending_price_approvals WHERE {$station_where}status='pending'", $station_param);
$counts['awaiting_stockin'] = $safe_count("SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}admin_finalized=1 AND (COALESCE(stock_in_done,0)=0 OR status IN ('Approved','Approved PO','Admin Finalized'))", $station_param);
$counts['fuel_adj'] = $safe_count("SELECT COUNT(*) FROM fuel_adjustments WHERE {$station_where}(LOWER(COALESCE(status,''))='pending' OR status IS NULL OR status='')", $station_param);
$low_merch_params = $myStationId ? [$myStationId] : [];
$low_merch_where  = $myStationId ? "si.station_id=? AND " : "";
$counts['low_merch'] = $safe_count("SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id=si.product_id WHERE {$low_merch_where}COALESCE(si.stock_level,0) <= COALESCE(si.reorder_level, ip.min_stock, 10) AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')", $low_merch_params);

foreach ($counts as $k => $v) {
    $total += $v;
    echo "{$k}: {$v}\n";
}
echo "\nTOTAL BELL BADGE: {$total}\n";
echo "\nDB notifications unread: " . $safe_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'", [$user_id]) . "\n";
