<?php
$page_id = 'mgr_prod_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me       = current_user();
$role     = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Manager only
if (!in_array($role, ['manager', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

// ── Fetch approved price history from pending_price_approvals ──────────────────
// Show all approved price changes from Admin
$fuel_history = [];
$merch_history = [];
$service_history = [];

try {
    // ── Fuel Price History ──────────────────────────────────────────────────────
    $stmt_fuel = $pdo->query("
        SELECT 
            p.id,
            p.old_price,
            p.new_price,
            p.old_cost,
            p.new_cost,
            p.created_at,
            p.updated_at,
            f.fuel_type as product_name,
            f.id as product_id,
            COALESCE(m.username, 'Manager') as proposed_by,
            COALESCE(a.username, 'Admin') as approved_by
        FROM pending_price_approvals p
        INNER JOIN fuel_inventory f ON f.id = p.product_id
        LEFT JOIN users m ON m.id = p.manager_id
        LEFT JOIN users a ON a.id = p.admin_id
        WHERE p.product_type IN ('fuel', 'fuel_inventory')
          AND p.status = 'approved'
        ORDER BY p.updated_at DESC
        LIMIT 100
    ");
    $fuel_history = $stmt_fuel->fetchAll(PDO::FETCH_ASSOC);
    
    // ── Merchandise Price History ───────────────────────────────────────────────
    $stmt_merch = $pdo->query("
        SELECT 
            p.id,
            p.old_price,
            p.new_price,
            p.old_cost,
            p.new_cost,
            p.created_at,
            p.updated_at,
            i.product_name,
            i.sku,
            i.id as product_id,
            COALESCE(m.username, 'Manager') as proposed_by,
            COALESCE(a.username, 'Admin') as approved_by
        FROM pending_price_approvals p
        INNER JOIN inventory_products i ON i.id = p.product_id
        LEFT JOIN users m ON m.id = p.manager_id
        LEFT JOIN users a ON a.id = p.admin_id
        WHERE p.product_type = 'merchandise'
          AND p.status = 'approved'
        ORDER BY p.updated_at DESC
        LIMIT 100
    ");
    $merch_history = $stmt_merch->fetchAll(PDO::FETCH_ASSOC);
    
    // ── Service Type Price History ──────────────────────────────────────────────
    $stmt_services = $pdo->query("
        SELECT 
            p.id,
            p.old_price,
            p.new_price,
            p.status,
            p.created_at,
            p.updated_at,
            s.id as service_id,
            s.service_name,
            s.service_key,
            s.service_price as current_price,
            COALESCE(m.username, 'Manager') as proposed_by,
            COALESCE(a.username, 'Admin') as approved_by
        FROM pending_price_approvals p
        INNER JOIN job_order_service_types s ON s.id = p.product_id
        LEFT JOIN users m ON m.id = p.manager_id
        LEFT JOIN users a ON a.id = p.admin_id
        WHERE p.product_type = 'service_type'
          AND p.status = 'approved'
        ORDER BY p.updated_at DESC
        LIMIT 100
    ");
    $service_history = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $fuel_history = [];
    $merch_history = [];
    $service_history = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* === Price History — Read-Only View === */
.ph-wrap { max-width:1200px; margin:0 auto; padding:0 4px 32px; }

.page-head-box { margin-bottom:24px; }
.page-head-box h1 { font-size:1.5rem; font-weight:800; color:#002F70; display:flex; align-items:center; gap:10px; margin:0 0 4px; }
.page-head-box .sub { font-size:0.78rem; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px; }

/* Info banner */
.info-banner {
    background: linear-gradient(135deg, #e8f4fd 0%, #f0f7ff 100%);
    border: 1px solid #b8d9f5;
    border-left: 4px solid #002F70;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 13px;
    color: #1a3a5c;
}
.info-banner i { font-size: 16px; color: #002F70; margin-top: 1px; flex-shrink: 0; }

/* Card */
.card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.07); border:1px solid #e4e8ef; overflow:hidden; }
.card-header { padding:16px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.card-header h3 { font-size:15px; font-weight:700; color:#002F70; margin:0; display:flex; align-items:center; gap:8px; }
.rec-count { font-size:12px; color:#6c757d; background:#f8f9fa; border:1px solid #e9ecef; padding:4px 10px; border-radius:20px; }
.card-body { padding:0; overflow:hidden; }

/* Table */
.ph-table { width:100%; border-collapse:collapse; }
.ph-table thead th {
    background:#002F70;
    color:#fff;
    font-weight:600;
    padding:13px 14px;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:0.3px;
    font-size:11px;
    border:none;
}
.ph-table tbody td {
    vertical-align:middle;
    padding:11px 14px;
    border-bottom:1px solid #f0f2f5;
    font-size:13px;
    color:#333;
}
.ph-table tbody tr:last-child td { border-bottom:none; }
.ph-table tbody tr:hover td { background:#f5f9ff; }

/* Price change display */
.price-change { display:flex; align-items:center; gap:6px; }
.price-old { color:#9ca3af; text-decoration:line-through; font-size:12px; }
.price-arrow { color:#9ca3af; font-size:10px; }
.price-new { color:#059669; font-weight:700; }
.price-new.higher { color:#b45309; }
.price-same { color:#374151; font-weight:600; }

/* Type badge */
.type-badge { display:inline-block; padding:2px 9px; border-radius:12px; font-size:10px; font-weight:700; white-space:nowrap; }
.type-fuel  { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
.type-merchandise { background:#ede9fe; color:#5b21b6; border:1px solid #c4b5fd; }

/* Action badge (read-only status label) */
.action-badge { display:inline-block; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; }
.action-propose { background:#fef3c7; color:#92400e; }
.action-approve { background:#d1fae5; color:#065f46; }
.action-update  { background:#e0e7ff; color:#3730a3; }

.date-col { font-size:12px; color:#6c757d; white-space:nowrap; }

/* Empty state */
.empty-state { text-align:center; padding:56px 24px; color:#9ca3af; }
.empty-state i { font-size:3rem; display:block; margin-bottom:14px; opacity:.3; }
.empty-state strong { display:block; font-size:15px; color:#6c757d; margin-bottom:6px; }
.empty-state span { font-size:12px; }

/* Tabs */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e9ecef; margin-bottom: 20px; }
.tab-btn { background: #f8f9fa; border: none; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #475569 !important; cursor: pointer; border-bottom: 3px solid transparent; transition: all .2s; }
.tab-btn:hover { color: #002F70 !important; background: rgba(0,47,108,0.1); }
.tab-btn.active { background: #002F70 !important; color: #ffffff !important; border-bottom-color: #002F70; font-weight: 800; }
.tab-btn.active i { color: #ffffff !important; }
.tab-btn i { margin-right: 8px; }

.tab-content { display: none; }
.tab-content.active { display: block; }
</style>

<div class="ph-wrap">

    <!-- Page Header -->
    <div class="page-head-box">
        <h1><i class="fas fa-history" style="color:#059669;"></i> Price History</h1>
        <div class="sub">Manager — Approved price changes from Admin's Product &amp; Pricing Overview</div>
    </div>

    <!-- Info Banner -->
    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <div>
            This page shows <strong>all price proposals submitted by the Admin</strong> — including cost and selling price changes for both fuel and merchandise products.
            These are <strong>read-only</strong>. Price approvals are finalized by Admin in the <em>Product &amp; Pricing Overview</em>.
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('fuel')">
            <i class="fas fa-gas-pump"></i> Fuel Prices
        </button>
        <button class="tab-btn" onclick="switchTab('merchandise')">
            <i class="fas fa-box"></i> Merchandise Prices
        </button>
        <button class="tab-btn" onclick="switchTab('services')">
            <i class="fas fa-wrench"></i> Service Types
        </button>
    </div>

    <!-- Fuel Tab -->
    <div class="tab-content active" id="fuelTab">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-gas-pump"></i> Fuel Price History</h3>
                <span class="rec-count"><?php echo count($fuel_history); ?> record(s)</span>
            </div>
            <div class="card-body">
                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th>Old Price</th>
                            <th>New Price</th>
                            <th>Change</th>
                            <th>Proposed By</th>
                            <th>Approved By</th>
                            <th>Date Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fuel_history)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-gas-pump"></i>
                                    <strong>No fuel price history yet</strong>
                                    <span>Fuel price changes approved by Admin will appear here once recorded.</span>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($fuel_history as $h): ?>
                        <?php
                            $old_price = (float)($h['old_price'] ?? 0);
                            $new_price = (float)($h['new_price'] ?? 0);
                            $price_diff = $new_price - $old_price;
                            $price_diff_pct = $old_price > 0 ? (($price_diff / $old_price) * 100) : 0;
                            $price_higher = $price_diff > 0;
                            
                            $date_display = !empty($h['updated_at'])
                                ? date('M j, Y g:i A', strtotime($h['updated_at']))
                                : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['product_name']); ?></strong></td>
                            <td><span class="price-old">₱<?php echo number_format($old_price, 2); ?></span></td>
                            <td><span class="price-new<?php echo $price_higher ? ' higher' : ''; ?>">₱<?php echo number_format($new_price, 2); ?></span></td>
                            <td>
                                <div style="color:<?php echo $price_higher ? '#b45309' : '#059669'; ?>;font-weight:700;">
                                    <?php echo $price_higher ? '+' : ''; ?>₱<?php echo number_format(abs($price_diff), 2); ?>
                                </div>
                                <div style="font-size:10px;color:<?php echo $price_higher ? '#b45309' : '#059669'; ?>;">
                                    (<?php echo number_format(abs($price_diff_pct), 1); ?>%)
                                </div>
                            </td>
                            <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($h['proposed_by']); ?></td>
                            <td style="font-size:12px;color:#059669;font-weight:600;"><?php echo htmlspecialchars($h['approved_by']); ?></td>
                            <td class="date-col"><?php echo $date_display; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Merchandise Tab -->
    <div class="tab-content" id="merchandiseTab">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-box"></i> Merchandise Price History</h3>
                <span class="rec-count"><?php echo count($merch_history); ?> record(s)</span>
            </div>
            <div class="card-body">
                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Old Cost</th>
                            <th>New Cost</th>
                            <th>Old Price</th>
                            <th>New Price</th>
                            <th>Proposed By</th>
                            <th>Approved By</th>
                            <th>Date Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($merch_history)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="fas fa-box"></i>
                                    <strong>No merchandise price history yet</strong>
                                    <span>Merchandise price changes approved by Admin will appear here once recorded.</span>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($merch_history as $h): ?>
                        <?php
                            $old_cost = (float)($h['old_cost'] ?? 0);
                            $new_cost = (float)($h['new_cost'] ?? 0);
                            $old_price = (float)($h['old_price'] ?? 0);
                            $new_price = (float)($h['new_price'] ?? 0);
                            
                            $cost_higher = $new_cost > $old_cost;
                            $price_higher = $new_price > $old_price;
                            
                            $date_display = !empty($h['updated_at'])
                                ? date('M j, Y g:i A', strtotime($h['updated_at']))
                                : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['product_name']); ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($h['sku'] ?? '—'); ?></td>
                            <td><span class="price-old">₱<?php echo number_format($old_cost, 2); ?></span></td>
                            <td><span class="price-new<?php echo $cost_higher ? ' higher' : ''; ?>">₱<?php echo number_format($new_cost, 2); ?></span></td>
                            <td><span class="price-old">₱<?php echo number_format($old_price, 2); ?></span></td>
                            <td><span class="price-new<?php echo $price_higher ? ' higher' : ''; ?>">₱<?php echo number_format($new_price, 2); ?></span></td>
                            <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($h['proposed_by']); ?></td>
                            <td style="font-size:12px;color:#059669;font-weight:600;"><?php echo htmlspecialchars($h['approved_by']); ?></td>
                            <td class="date-col"><?php echo $date_display; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Service Types Tab -->
    <div class="tab-content" id="servicesTab">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-wrench"></i> Service Type Price History</h3>
                <span class="rec-count"><?php echo count($service_history); ?> record(s)</span>
            </div>
            <div class="card-body">
                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Service Key</th>
                            <th>Price Change</th>
                            <th>Current Price</th>
                            <th>Proposed By</th>
                            <th>Approved By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($service_history)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-wrench"></i>
                                    <strong>No service type price history yet</strong>
                                    <span>Service type price changes approved by Admin will appear here once recorded.</span>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($service_history as $h): ?>
                        <?php
                            // Price change
                            $old_price = (float)$h['old_price'];
                            $new_price = (float)$h['new_price'];
                            $current_price = (float)$h['current_price'];
                            $price_higher = $new_price > $old_price;
                            
                            $price_html = '<div class="price-change">'
                                . '<span class="price-old">₱' . number_format($old_price, 2) . '</span>'
                                . '<span class="price-arrow">→</span>'
                                . '<span class="price-new' . ($price_higher ? ' higher' : '') . '">₱' . number_format($new_price, 2) . '</span>'
                                . '</div>';
                            
                            $price_diff = $new_price - $old_price;
                            $price_diff_pct = $old_price > 0 ? (($price_diff / $old_price) * 100) : 0;
                            
                            $date_display = !empty($h['updated_at'])
                                ? date('M j, Y g:i A', strtotime($h['updated_at']))
                                : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['service_name']); ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($h['service_key']); ?></td>
                            <td>
                                <?php echo $price_html; ?>
                                <div style="font-size:10px;color:<?php echo $price_higher ? '#b45309' : '#059669'; ?>;margin-top:2px;">
                                    <?php echo $price_higher ? '+' : ''; ?>₱<?php echo number_format(abs($price_diff), 2); ?>
                                    (<?php echo number_format(abs($price_diff_pct), 1); ?>%)
                                </div>
                            </td>
                            <td><span class="price-same">₱<?php echo number_format($current_price, 2); ?></span></td>
                            <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($h['proposed_by'] ?? 'Manager'); ?></td>
                            <td style="font-size:12px;color:#059669;font-weight:600;"><?php echo htmlspecialchars($h['approved_by'] ?? 'Admin'); ?></td>
                            <td class="date-col"><?php echo $date_display; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.tab-btn').classList.add('active');
    
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    if (tab === 'fuel') {
        document.getElementById('fuelTab').classList.add('active');
    } else if (tab === 'merchandise') {
        document.getElementById('merchandiseTab').classList.add('active');
    } else if (tab === 'services') {
        document.getElementById('servicesTab').classList.add('active');
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
