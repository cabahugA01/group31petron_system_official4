<?php
/**
 * Staff Pump Master — View Only
 * Displays pump calibration values auto-pulled from the system.
 * Staff cannot edit calibration values; this is read-only.
 */
$page_id = 'staff_pump_master';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit;
}

// ── Fetch pump data with calibration values ───────────────────────────────────
$pumps = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            fi.fuel_type,
            COALESCE(fi.latest_calibration, 0)  AS calibration_value,
            COALESCE(fi.price_per_liter, 0)      AS price_per_liter,
            COALESCE(fi.current_level, 0)        AS current_level,
            COALESCE(fi.current_stock, 0)        AS current_stock,
            COALESCE(fi.status, 'Normal')        AS status,
            fi.last_updated,
            (SELECT fp.pump_number
             FROM fuel_pumps fp
             WHERE fp.station_id = fi.station_id
               AND fp.fuel_type_id = fi.fuel_type_id
             ORDER BY fp.pump_number ASC LIMIT 1) AS pump_number,
            (SELECT u.name
             FROM fuel_pumps fp2
             JOIN users u ON fp2.calibration_updated_by = u.id
             WHERE fp2.station_id = fi.station_id
               AND fp2.fuel_type_id = fi.fuel_type_id
             ORDER BY fp2.calibration_updated_at DESC LIMIT 1) AS last_updated_by,
            (SELECT fp3.calibration_updated_at
             FROM fuel_pumps fp3
             WHERE fp3.station_id = fi.station_id
               AND fp3.fuel_type_id = fi.fuel_type_id
             ORDER BY fp3.calibration_updated_at DESC LIMIT 1) AS calibration_updated_at
        FROM fuel_inventory fi
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type
    ");
    $stmt->execute([$station_id]);
    $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pumps = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.pump-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.pump-page-title {
    display: flex;
    align-items: center;
    gap: 14px;
}

.pump-page-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(0,47,108,.12);
    color: var(--petron-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.pump-page-title h1 {
    font-size: 20px !important;
    font-weight: 700 !important;
    color: var(--petron-blue) !important;
    margin: 0 !important;
}

.pump-page-title p {
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0;
    font-weight: 400 !important;
    text-transform: none !important;
    letter-spacing: 0 !important;
}

.readonly-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
}

.info-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    color: #1d4ed8;
    font-size: 13px;
    margin-bottom: 24px;
}

.info-banner i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }

.pump-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 18px;
}

.pump-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: hidden;
    transition: box-shadow .2s;
}

.pump-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); }

.pump-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.pump-card-header .fuel-name {
    font-size: 15px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #1e293b;
}

.pump-card-header .pump-num {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
}

.pump-status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.pump-status-dot.normal   { background: #4ade80; }
.pump-status-dot.low      { background: #fbbf24; }
.pump-status-dot.critical { background: #f87171; }

.pump-card-body { padding: 18px 20px; }

.pump-stat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}

.pump-stat-row:last-child { border-bottom: none; }

.pump-stat-label {
    color: #64748b;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 7px;
}

.pump-stat-label i { font-size: 12px; width: 14px; text-align: center; }

.pump-stat-value {
    font-weight: 700;
    color: #1e293b;
}

.pump-stat-value.calibration {
    color: #166534;
    font-size: 13px;
}

.pump-stat-value.price {
    color: var(--petron-blue);
}

.pump-card-footer {
    padding: 10px 20px;
    border-top: 1px solid #f1f5f9;
    font-size: 11px;
    color: #94a3b8;
    display: flex;
    align-items: center;
    gap: 6px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-state i { font-size: 40px; display: block; margin-bottom: 12px; }
.empty-state p { font-size: 14px; }
</style>

<div class="pump-page-header">
    <div class="pump-page-title">
        <div class="pump-page-icon"><i class="fas fa-gas-pump"></i></div>
        <div>
            <h1>Pump Master</h1>
            <p>Calibration values auto-pulled from system — view only</p>
        </div>
    </div>
    <span class="readonly-badge"><i class="fas fa-lock"></i> View Only</span>
</div>


<?php if (empty($pumps)): ?>
<div class="empty-state">
    <i class="fas fa-gas-pump"></i>
    <p>No pump data available for this station.</p>
</div>
<?php else: ?>
<div class="pump-grid">
    <?php foreach ($pumps as $pump):
        $status = strtolower($pump['status'] ?? 'normal');
        $dot_class = 'normal';
        if (str_contains($status, 'low') || str_contains($status, 'critical')) $dot_class = 'low';
        if (str_contains($status, 'out') || str_contains($status, 'empty'))    $dot_class = 'critical';
        $calib_updated = $pump['calibration_updated_at']
            ? date('M d, Y H:i', strtotime($pump['calibration_updated_at']))
            : 'Not set';
        $updated_by = $pump['last_updated_by'] ?? 'System';
    ?>
    <div class="pump-card">
        <div class="pump-card-header">
            <div>
                <div class="fuel-name"><?= htmlspecialchars($pump['fuel_type']) ?></div>
                <?php if ($pump['pump_number']): ?>
                <div class="pump-num">Pump #<?= htmlspecialchars($pump['pump_number']) ?></div>
                <?php endif; ?>
            </div>
            <div class="pump-status-dot <?= $dot_class ?>"></div>
        </div>
        <div class="pump-card-body">
            <div class="pump-stat-row">
                <span class="pump-stat-label">
                    <i class="fas fa-sliders-h"></i> Calibration Value
                </span>
                <span class="pump-stat-value calibration">
                    <?= number_format((float)$pump['calibration_value'], 3) ?> L
                </span>
            </div>
            <div class="pump-stat-row">
                <span class="pump-stat-label">
                    <i class="fas fa-tag"></i> Price per Liter
                </span>
                <span class="pump-stat-value price">
                    ₱<?= number_format((float)$pump['price_per_liter'], 2) ?>
                </span>
            </div>
            <div class="pump-stat-row">
                <span class="pump-stat-label">
                    <i class="fas fa-tint"></i> Current Stock
                </span>
                <span class="pump-stat-value">
                    <?= number_format((float)($pump['current_stock'] ?: $pump['current_level']), 2) ?> L
                </span>
            </div>
            <div class="pump-stat-row">
                <span class="pump-stat-label">
                    <i class="fas fa-circle"></i> Status
                </span>
                <span class="pump-stat-value">
                    <?= htmlspecialchars(ucfirst($pump['status'] ?? 'Normal')) ?>
                </span>
            </div>
        </div>
        <div class="pump-card-footer">
            <i class="fas fa-clock"></i>
            Calibration set <?= $calib_updated ?> by <?= htmlspecialchars($updated_by) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
