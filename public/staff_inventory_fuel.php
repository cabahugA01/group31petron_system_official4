<?php
$page_id = 'inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$fuel_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.product_name AS name,
               COALESCE(fi.price_per_liter, ip.unit_cost) AS price,
               COALESCE(fi.current_level, ip.stock)       AS stock_level,
               COALESCE(fi.capacity, 20000.00)            AS capacity
        FROM inventory_products ip
        LEFT JOIN fuel_inventory fi
               ON ip.product_name = fi.fuel_type AND fi.station_id = ?
        WHERE ip.category = 'Fuel'
        ORDER BY ip.product_name
    ");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading fuel inventory: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef; margin-bottom: 20px;
}
.inv-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px;
}
.inv-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.inv-card-body  { padding: 20px; }
.readonly-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9;
    border-radius: 20px; padding: 3px 11px; font-size: 11px; font-weight: 600;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Inventory</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Read-only monitoring</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-gas-pump"></i> Current Fuel Levels</div>
        <span class="readonly-badge"><i class="fas fa-lock"></i> Read-only &mdash; auto-pulled from Manager/Admin</span>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th>Current Level</th>
                        <th>Capacity</th>
                        <th>Fill %</th>
                        <th>Status</th>
                        <th>Price / L</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_inventory)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:28px;color:#6c757d;">
                        <i class="fas fa-gas-pump" style="font-size:2em;display:block;margin-bottom:8px;opacity:.3;"></i>
                        No fuel inventory data available.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($fuel_inventory as $fuel):
                        $fl  = (float)($fuel['stock_level'] ?? 0);
                        $cap = (float)($fuel['capacity']    ?? 1);
                        $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
                        if      ($fl  <= 0)   { $st = 'OUT OF STOCK'; $sc = '#dc3545'; }
                        elseif  ($pct <= 10)  { $st = 'CRITICAL';     $sc = '#dc3545'; }
                        elseif  ($pct <= 25)  { $st = 'LOW';          $sc = '#fd7e14'; }
                        elseif  ($fl  <= 500) { $st = 'LOW STOCK';    $sc = '#fd7e14'; }
                        else                  { $st = 'AVAILABLE';    $sc = '#28a745'; }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($fuel['name']); ?></strong></td>
                        <td><?php echo number_format($fl, 2); ?> L</td>
                        <td><?php echo number_format($cap, 2); ?> L</td>
                        <td style="min-width:130px;">
                            <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;margin-bottom:3px;">
                                <div style="width:<?php echo min(100, round($pct)); ?>%;height:100%;background:<?php echo $sc; ?>;border-radius:4px;"></div>
                            </div>
                            <small style="color:#6c757d;"><?php echo round($pct, 1); ?>%</small>
                        </td>
                        <td><span style="color:<?php echo $sc; ?>;font-weight:700;"><?php echo $st; ?></span></td>
                        <td>&#8369;<?php echo number_format($fuel['price'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p style="margin-top:14px;font-size:12px;color:#6c757d;">
            <i class="fas fa-info-circle"></i>
            Fuel levels are updated automatically from Manager/Admin deliveries and validated readings.
            To encode fuel readings, go to <strong>Fuel Management</strong>.
        </p>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
