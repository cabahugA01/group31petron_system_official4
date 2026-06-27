<?php
// ============================================================
// 17-Tanker Fuel Inventory Estate View
// Shows: Beginning + Purchases = Total Available - Sales - Calibration = Ending Balance
// Compare with Actual Dip Reading → Variance & Status
// ============================================================
$page_id = 'fuel_inventory_estate';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// ── 17-Tanker Configuration ──
$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>1],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>3],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>4],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',   'tank'=>'Underground Tank #14', 'tanker_num'=>1],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 2',   'tank'=>'Underground Tank #15', 'tanker_num'=>2],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 3',   'tank'=>'Underground Tank #16', 'tanker_num'=>3],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 4',   'tank'=>'Underground Tank #17', 'tanker_num'=>4],
];

// ── Fetch inventory data for each tanker ──
$tanker_data = [];
foreach ($TANK_CONFIG_17 as $tank) {
    $fuel_key = strtolower(trim($tank['fuel_type']));
    
    try {
        // Get pump master inventory for beginning balance
        $stmt = $pdo->prepare("
            SELECT beginning_balance, latest_calibration, current_balance, actual_dip_reading,
                   last_dip_date, updated_at
            FROM pump_master_inventory
            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = ?
            LIMIT 1
        ");
        $stmt->execute([$station_id, $fuel_key]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get total purchases (deliveries) for today
        $stmt_purchases = $pdo->prepare("
            SELECT COALESCE(SUM(delivery_liters), 0) as total_purchases
            FROM fuel_deliveries
            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = ?
              AND DATE(delivery_date) = CURDATE()
              AND status = 'Verified'
        ");
        $stmt_purchases->execute([$station_id, $fuel_key]);
        $purchases = (float)$stmt_purchases->fetchColumn();
        
        // Get total sales for today
        $stmt_sales = $pdo->prepare("
            SELECT COALESCE(SUM(liters_sold), 0) as total_sales
            FROM fuel_transactions
            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = ?
              AND DATE(transaction_date) = CURDATE()
              AND status = 'Validated'
        ");
        $stmt_sales->execute([$station_id, $fuel_key]);
        $sales = (float)$stmt_sales->fetchColumn();
        
        // Get calibration adjustments for today
        $stmt_cal = $pdo->prepare("
            SELECT COALESCE(SUM(liters), 0) as total_calibration
            FROM fuel_adjustments
            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = ?
              AND DATE(adjustment_date) = CURDATE()
              AND adjustment_type = 'calibration'
        ");
        $stmt_cal->execute([$station_id, $fuel_key]);
        $calibration = (float)$stmt_cal->fetchColumn();
        
        // Calculate inventory flow
        $beginning = (float)($inv['beginning_balance'] ?? 0);
        $total_available = $beginning + $purchases;
        $ending_balance = $total_available - $sales - $calibration;
        $actual_dip = (float)($inv['actual_dip_reading'] ?? $ending_balance);
        $variance = $actual_dip - $ending_balance;
        
        // Determine status based on variance
        $abs_variance = abs($variance);
        if ($abs_variance <= 5) {
            $status = 'OK';
            $status_color = '#28a745';
        } elseif ($abs_variance <= 20) {
            $status = 'Minor Variance';
            $status_color = '#ffc107';
        } else {
            $status = 'Discrepancy';
            $status_color = '#dc3545';
        }
        
        $tanker_data[] = [
            'config'          => $tank,
            'beginning'       => $beginning,
            'purchases'       => $purchases,
            'total_available' => $total_available,
            'sales'           => $sales,
            'calibration'     => $calibration,
            'ending_balance'  => $ending_balance,
            'actual_dip'      => $actual_dip,
            'variance'        => $variance,
            'status'          => $status,
            'status_color'    => $status_color,
            'last_dip_date'   => $inv['last_dip_date'] ?? null,
        ];
        
    } catch (Exception $e) {
        // If error, add with zero values
        $tanker_data[] = [
            'config'          => $tank,
            'beginning'       => 0,
            'purchases'       => 0,
            'total_available' => 0,
            'sales'           => 0,
            'calibration'     => 0,
            'ending_balance'  => 0,
            'actual_dip'      => 0,
            'variance'        => 0,
            'status'          => 'No Data',
            'status_color'    => '#6c757d',
            'last_dip_date'   => null,
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>17-Tanker Fuel Inventory Estate - Petron Management System</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="background:#f8f9fa;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;margin:0;padding:0;">

<!-- Simple Top Bar -->
<div style="background:#002F70;color:#fff;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 4px rgba(0,0,0,.1);">
    <div style="display:flex;align-items:center;gap:12px;">
        <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron" style="height:32px;">
        <div>
            <div style="font-size:14px;font-weight:700;">Petron Station Management System</div>
            <div style="font-size:11px;opacity:0.9;"><?= htmlspecialchars($me['station_name'] ?? 'Station') ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;">
        <span style="font-size:13px;"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($me['full_name'] ?? 'User') ?></span>
        <a href="logout.php" style="color:#fff;text-decoration:none;font-size:13px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Main Content Area -->
<div style="max-width:1800px;margin:0 auto;padding:24px;">

<style>
.estate-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.estate-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:9px; }
.estate-subtitle { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.estate-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.estate-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .13s; height:36px; white-space:nowrap; }
.estate-btn-back { background:#6c757d; color:#fff; } .estate-btn-back:hover { background:#545b62; color:#fff; }
/* Table */
.estate-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; margin-bottom:20px; }
.estate-card-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.estate-card-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.estate-tbl-wrap { width:100%; overflow:hidden; }
.estate-tbl { width:100%; table-layout:auto; border-collapse:collapse; font-size:11px; }
.estate-tbl thead tr { background:#002F70; }
.estate-tbl thead th { padding:10px 8px; text-align:left; font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; white-space:normal; line-height:1.3; vertical-align:top; }
.estate-tbl thead th.r { text-align:right; }
.estate-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.estate-tbl tbody tr:hover { background:#eff6ff; }
.estate-tbl tbody td { padding:8px; color:#334155; vertical-align:middle; white-space:nowrap; line-height:1.5; }
.estate-tbl tbody td.r { text-align:right; }
.status-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600; white-space:nowrap; }
.var-pos { color:#dc3545; font-weight:600; }
.var-neg { color:#28a745; font-weight:600; }
.var-zero { color:#6c757d; }
</style>

<div class="estate-head">
    <div>
        <h1><i class="fas fa-gas-pump"></i> 17-Tanker Fuel Inventory Estate</h1>
        <div class="estate-subtitle">BEGINNING + PURCHASES - SALES - CALIBRATION = ENDING vs ACTUAL DIP</div>
    </div>
    <div class="estate-actions">
        <a href="staff_inventory_fuel.php" class="estate-btn estate-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="estate-card">
    <div class="estate-card-hd">
        <h3 class="estate-card-title"><i class="fas fa-table"></i> 17 Tanker Inventory Flow (Today: <?= date('M d, Y') ?>)</h3>
        <span style="font-size:11px;color:#64748b;">Real-time inventory tracking with variance detection</span>
    </div>
    <div class="estate-tbl-wrap">
        <table class="estate-tbl">
            <thead>
                <tr>
                    <th>Fuel Type</th>
                    <th>Tanker Reference</th>
                    <th class="r">Beginning</th>
                    <th class="r">Purchases</th>
                    <th class="r">Total Available</th>
                    <th class="r">Sales</th>
                    <th class="r">Calibration</th>
                    <th class="r">Ending Balance</th>
                    <th class="r">Actual Dip</th>
                    <th class="r">Variance</th>
                    <th>Status</th>
                    <th>Last Dip</th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($tanker_data)): ?>
                <tr>
                    <td colspan="12">
                        <div class="empty-state">
                            <i class="fas fa-gas-pump"></i>
                            <p>No inventory data available</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($tanker_data as $data): ?>
                <tr>
                    <td><?= htmlspecialchars($data['config']['fuel_type']) ?></td>
                    <td style="font-weight:600;color:#002F70;"><?= htmlspecialchars($data['config']['label']) ?></td>
                    <td class="r"><?= number_format($data['beginning'], 2) ?> L</td>
                    <td class="r"><?= number_format($data['purchases'], 2) ?> L</td>
                    <td class="r" style="font-weight:600;color:#002F70;"><?= number_format($data['total_available'], 2) ?> L</td>
                    <td class="r"><?= number_format($data['sales'], 2) ?> L</td>
                    <td class="r"><?= number_format($data['calibration'], 2) ?> L</td>
                    <td class="r" style="font-weight:600;"><?= number_format($data['ending_balance'], 2) ?> L</td>
                    <td class="r" style="font-weight:700;color:#002F70;"><?= number_format($data['actual_dip'], 2) ?> L</td>
                    <td class="r">
<?php
    $var = $data['variance'];
    $absVar = abs($var);
    if ($absVar < 0.01) {
        echo '<span class="var-zero">0.0 L</span>';
    } elseif ($var > 0) {
        echo '<span class="var-neg">+' . number_format($var, 2) . ' L</span>';
    } else {
        echo '<span class="var-pos">' . number_format($var, 2) . ' L</span>';
    }
?>
                    </td>
                    <td>
                        <span class="status-badge" style="background-color:<?= htmlspecialchars($data['status_color']) ?>15;color:<?= htmlspecialchars($data['status_color']) ?>;">
                            <?= htmlspecialchars($data['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:10px;color:#64748b;">
                        <?= $data['last_dip_date'] ? date('M d, h:i A', strtotime($data['last_dip_date'])) : '—' ?>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Auto-refresh every 5 minutes
setInterval(function(){
    location.reload();
}, 300000);

// Print functionality
function printTable(){
    window.print();
}

// Export to CSV
function exportCSV(){
    const table = document.querySelector('.estate-tbl');
    let csv = [];
    
    // Headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    csv.push(headers.join(','));
    
    // Data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => {
            row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    });
    
    // Download
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = '17_Tanker_Inventory_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<style media="print">
.estate-head .estate-actions { display:none; }
.estate-btn { display:none; }
body { background:#fff; }
.estate-card { box-shadow:none; border:1px solid #000; }
</style>

</div><!-- End Main Content -->

<script>
// Auto-refresh every 5 minutes (300000ms)
setTimeout(function() {
    location.reload();
}, 300000);

// Esc key handler
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'staff_inventory_fuel.php';
    }
});
</script>

</body>
</html>
