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

// ── Fetch approved price history from activity_logs ───────────────────────────
// Show all products where Admin proposed a price — including already approved ones.
$history = [];
try {
    // Pull every "Propose Price" log entry (these are the admin-proposed prices)
    $stmt = $pdo->query("
        SELECT al.id, al.details, al.created_at,
               u.full_name AS proposed_by
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.action IN ('Propose Price', 'Approve Price', 'Set Price', 'Update Price', 'Price Updated')
        ORDER BY al.created_at DESC
        LIMIT 200
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as $log) {
        $d = $log['details'] ?? '';

        // Parse: "PROPOSED: Product ID X | Old Cost: Y → New Cost: Z | Old Price: A → New Price: B"
        // or similar patterns
        $prod_id       = null;
        $product_name  = null;
        $sku           = null;
        $old_cost      = null;
        $new_cost      = null;
        $old_price     = null;
        $new_price     = null;

        if (preg_match('/Product ID (\d+)/', $d, $m)) $prod_id = (int)$m[1];
        if (preg_match('/Old Cost:\s*([\d.]+)/', $d, $m))  $old_cost  = (float)$m[1];
        if (preg_match('/New Cost:\s*([\d.]+)/', $d, $m))  $new_cost  = (float)$m[1];
        if (preg_match('/Old Price:\s*([\d.]+)/', $d, $m)) $old_price = (float)$m[1];
        if (preg_match('/New Price:\s*([\d.]+)/', $d, $m)) $new_price = (float)$m[1];

        // Get product name & sku from DB if we have a product ID
        if ($prod_id) {
            $ps = $pdo->prepare("SELECT product_name, sku, product_type FROM inventory_products WHERE id = ?");
            $ps->execute([$prod_id]);
            $pr = $ps->fetch(PDO::FETCH_ASSOC);
            if ($pr) {
                $product_name = $pr['product_name'];
                $sku          = $pr['sku'];
                $product_type = $pr['product_type'] ?? 'merchandise';
            }
        }

        // Skip entries we can't parse meaningfully
        if (!$product_name || ($new_cost === null && $new_price === null)) continue;

        $history[] = [
            'product_name'  => $product_name,
            'sku'           => $sku ?? '—',
            'product_type'  => $product_type ?? 'merchandise',
            'old_cost'      => $old_cost,
            'new_cost'      => $new_cost,
            'old_price'     => $old_price,
            'new_price'     => $new_price,
            'proposed_by'   => $log['proposed_by'] ?? '—',
            'approved_at'   => $log['created_at'],
            'action'        => $log['action'] ?? 'Propose Price',
        ];
    }
} catch (Exception $e) {
    $history = [];
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
.card-body { padding:0; overflow-x:auto; }

/* Table */
.ph-table { width:100%; border-collapse:collapse; min-width:800px; }
.ph-table thead th {
    background:#002F70;
    color:#fff;
    font-weight:600;
    padding:13px 14px;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:0.3px;
    font-size:11px;
    white-space:nowrap;
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
.tab-btn { background: transparent; border: none; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #6c757d; cursor: pointer; border-bottom: 3px solid transparent; transition: all .2s; }
.tab-btn:hover { color: #002F70; background: #f8f9fa; }
.tab-btn.active { color: #002F70; border-bottom-color: #002F70; background: #f0f7ff; }
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
    </div>

    <!-- Fuel Tab -->
    <div class="tab-content active" id="fuelTab">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-gas-pump"></i> Fuel Price History</h3>
                <span class="rec-count"><?php echo count(array_filter($history, fn($h) => ($h['product_type'] ?? '') === 'fuel')); ?> record(s)</span>
            </div>
            <div class="card-body">
                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th>SKU</th>
                            <th>Cost Change</th>
                            <th>Price Change</th>
                            <th>Proposed By</th>
                            <th>Event</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $fuel_history = array_filter($history, fn($h) => ($h['product_type'] ?? '') === 'fuel');
                        if (empty($fuel_history)): 
                        ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-gas-pump"></i>
                                    <strong>No fuel price history yet</strong>
                                    <span>Fuel price changes proposed by Admin will appear here once recorded.</span>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($fuel_history as $h): ?>
                        <?php
                            // Cost change
                            if ($h['old_cost'] !== null && $h['new_cost'] !== null) {
                                $cost_higher = $h['new_cost'] > $h['old_cost'];
                                $cost_html = '<div class="price-change">'
                                    . '<span class="price-old">₱' . number_format($h['old_cost'], 2) . '</span>'
                                    . '<span class="price-arrow">→</span>'
                                    . '<span class="price-new' . ($cost_higher ? ' higher' : '') . '">₱' . number_format($h['new_cost'], 2) . '</span>'
                                    . '</div>';
                            } elseif ($h['new_cost'] !== null) {
                                $cost_html = '<span class="price-same">₱' . number_format($h['new_cost'], 2) . '</span>';
                            } else {
                                $cost_html = '<span style="color:#9ca3af;">—</span>';
                            }

                            // Price change
                            if ($h['old_price'] !== null && $h['new_price'] !== null) {
                                $price_higher = $h['new_price'] > $h['old_price'];
                                $price_html = '<div class="price-change">'
                                    . '<span class="price-old">₱' . number_format($h['old_price'], 2) . '</span>'
                                    . '<span class="price-arrow">→</span>'
                                    . '<span class="price-new' . ($price_higher ? ' higher' : '') . '">₱' . number_format($h['new_price'], 2) . '</span>'
                                    . '</div>';
                            } elseif ($h['new_price'] !== null) {
                                $price_html = '<span class="price-same">₱' . number_format($h['new_price'], 2) . '</span>';
                            } else {
                                $price_html = '<span style="color:#9ca3af;">—</span>';
                            }

                            // Action badge
                            $action_lower = strtolower($h['action']);
                            if (str_contains($action_lower, 'approve')) {
                                $badge_class = 'action-approve';
                                $badge_label = '✓ Approved';
                            } elseif (str_contains($action_lower, 'propose')) {
                                $badge_class = 'action-propose';
                                $badge_label = '⏳ Proposed';
                            } else {
                                $badge_class = 'action-update';
                                $badge_label = '✎ Updated';
                            }

                            $date_display = !empty($h['approved_at'])
                                ? date('M j, Y g:i A', strtotime($h['approved_at']))
                                : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['product_name']); ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($h['sku']); ?></td>
                            <td><?php echo $cost_html; ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($h['proposed_by']); ?></td>
                            <td><span class="action-badge <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span></td>
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
                <span class="rec-count"><?php echo count(array_filter($history, fn($h) => ($h['product_type'] ?? 'merchandise') === 'merchandise')); ?> record(s)</span>
            </div>
            <div class="card-body">
                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Cost Change</th>
                            <th>Price Change</th>
                            <th>Proposed By</th>
                            <th>Event</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $merch_history = array_filter($history, fn($h) => ($h['product_type'] ?? 'merchandise') === 'merchandise');
                        if (empty($merch_history)): 
                        ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-box"></i>
                                    <strong>No merchandise price history yet</strong>
                                    <span>Merchandise price changes proposed by Admin will appear here once recorded.</span>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($merch_history as $h): ?>
                        <?php
                            // Cost change
                            if ($h['old_cost'] !== null && $h['new_cost'] !== null) {
                                $cost_higher = $h['new_cost'] > $h['old_cost'];
                                $cost_html = '<div class="price-change">'
                                    . '<span class="price-old">₱' . number_format($h['old_cost'], 2) . '</span>'
                                    . '<span class="price-arrow">→</span>'
                                    . '<span class="price-new' . ($cost_higher ? ' higher' : '') . '">₱' . number_format($h['new_cost'], 2) . '</span>'
                                    . '</div>';
                            } elseif ($h['new_cost'] !== null) {
                                $cost_html = '<span class="price-same">₱' . number_format($h['new_cost'], 2) . '</span>';
                            } else {
                                $cost_html = '<span style="color:#9ca3af;">—</span>';
                            }

                            // Price change
                            if ($h['old_price'] !== null && $h['new_price'] !== null) {
                                $price_higher = $h['new_price'] > $h['old_price'];
                                $price_html = '<div class="price-change">'
                                    . '<span class="price-old">₱' . number_format($h['old_price'], 2) . '</span>'
                                    . '<span class="price-arrow">→</span>'
                                    . '<span class="price-new' . ($price_higher ? ' higher' : '') . '">₱' . number_format($h['new_price'], 2) . '</span>'
                                    . '</div>';
                            } elseif ($h['new_price'] !== null) {
                                $price_html = '<span class="price-same">₱' . number_format($h['new_price'], 2) . '</span>';
                            } else {
                                $price_html = '<span style="color:#9ca3af;">—</span>';
                            }

                            // Action badge
                            $action_lower = strtolower($h['action']);
                            if (str_contains($action_lower, 'approve')) {
                                $badge_class = 'action-approve';
                                $badge_label = '✓ Approved';
                            } elseif (str_contains($action_lower, 'propose')) {
                                $badge_class = 'action-propose';
                                $badge_label = '⏳ Proposed';
                            } else {
                                $badge_class = 'action-update';
                                $badge_label = '✎ Updated';
                            }

                            $date_display = !empty($h['approved_at'])
                                ? date('M j, Y g:i A', strtotime($h['approved_at']))
                                : '—';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($h['product_name']); ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($h['sku']); ?></td>
                            <td><?php echo $cost_html; ?></td>
                            <td><?php echo $price_html; ?></td>
                            <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($h['proposed_by']); ?></td>
                            <td><span class="action-badge <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span></td>
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
    } else {
        document.getElementById('merchandiseTab').classList.add('active');
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
