<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$date_from  = date('Y-m-d', strtotime('-90 days'));
$date_to    = date('Y-m-d');

$where  = ["DATE(ft.transaction_date) BETWEEN ? AND ?", "ft.station_id = ?"];
$params = [$date_from, $date_to, $station_id];

// Summary cards query
$sc_sql = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN LOWER(ft.status) LIKE '%pending%' OR ft.status IS NULL OR ft.status='' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN LOWER(ft.status) IN ('verified','adjusted') THEN 1 ELSE 0 END) as validated,
    SUM(CASE WHEN LOWER(ft.status) = 'rejected' THEN 1 ELSE 0 END) as rejected,
    COALESCE(SUM(ft.liters_sold), 0) as liters,
    COALESCE(SUM(ft.total_amount), 0) as sales
    FROM fuel_transactions ft
    WHERE " . implode(' AND ', $where);

$sc = $pdo->prepare($sc_sql);
$sc->execute($params);
$sc_row = $sc->fetch(PDO::FETCH_ASSOC);

echo "=== Summary Cards (station 1253, last 90d) ===\n";
echo "Total: {$sc_row['total']}\n";
echo "Pending: {$sc_row['pending']}\n";
echo "Validated: {$sc_row['validated']}\n";
echo "Rejected: {$sc_row['rejected']}\n";
echo "Liters: {$sc_row['liters']}\n";
echo "Sales: ₱{$sc_row['sales']}\n";

// Full table query
echo "\n=== Transaction Rows ===\n";
$stmt = $pdo->prepare("SELECT ft.id, ft.transaction_id, ft.fuel_type,
    ft.previous_reading, ft.present_reading, ft.calibration,
    ft.liters_sold, ft.price_per_liter, ft.total_amount,
    ft.shift_period, ft.shift_name, ft.status, ft.transaction_date, ft.validated_at,
    ft.notes, ft.reject_reason,
    COALESCE(NULLIF(CONCAT(TRIM(COALESCE(staff.first_name,'')), ' ', TRIM(COALESCE(staff.last_name,''))), ' '), staff.username, 'Unknown') AS staff_name,
    COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mgr.first_name,'')), ' ', TRIM(COALESCE(mgr.last_name,''))), ' '), mgr.username, '—') AS manager_name,
    fp.pump_number, s.name AS station_name
    FROM fuel_transactions ft
    LEFT JOIN users staff ON ft.staff_id = staff.id
    LEFT JOIN users mgr   ON ft.validated_by = mgr.id
    LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
    LEFT JOIN stations s ON ft.station_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ft.transaction_date DESC, ft.id DESC LIMIT 500");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Row count: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    $pump = $r['pump_number'] ?: 'P'.$r['pump_id'] ?? '—';
    echo "  [{$r['transaction_id']}] {$r['fuel_type']} | Pump: {$pump} | {$r['liters_sold']}L | ₱{$r['total_amount']} | {$r['status']} | Encoder: {$r['staff_name']} | Validator: {$r['manager_name']}\n";
}
