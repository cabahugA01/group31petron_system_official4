<?php
/**
 * Staff Inventory History
 * Track inventory movements, stock-in records, deliveries, and inventory changes
 */
$page_id = 'inv_history';
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

// Fetch merchandise inventory movements
$merch_history = [];
$fuel_history = [];
$msg = '';

try {
    // Query merchandise stock-in records
    $stmt = $pdo->prepare("
        SELECT 
            'Stock-In' as action_type,
            msi.id,
            msi.po_id,
            msi.product_id,
            msi.product_name as item_name,
            msi.sku as item_sku,
            msi.category as item_category,
            msi.qty_received as quantity_received,
            msi.batch_ref as batch_number,
            msi.encoded_by,
            msi.encoded_at,
            'Completed' as status
        FROM merchandise_stock_in msi
        WHERE msi.station_id = ?
        ORDER BY msi.encoded_at DESC
        LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $merch_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise history: ' . $e->getMessage();
}

try {
    // Query fuel stock-in records
    $stmt = $pdo->prepare("
        SELECT 
            'Fuel Stock-In' as action_type,
            fsi.id,
            fsi.delivery_id,
            fsi.fuel_type as item_name,
            fsi.invoice_no as item_sku,
            'Fuel' as item_category,
            fsi.qty_received as quantity_received,
            fsi.batch_ref as batch_number,
            fsi.encoded_by,
            fsi.encoded_at,
            'Completed' as status
        FROM fuel_stock_in fsi
        WHERE fsi.station_id = ?
        ORDER BY fsi.encoded_at DESC
        LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $fuel_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg .= ($msg ? ' | ' : '') . 'Error loading fuel history: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef;
    margin-bottom: 20px;
}
.inv-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 8px;
}
.inv-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #002F70;
    display: flex;
    align-items: center;
    gap: 8px;
}
.inv-card-body {
    padding: 20px;
}
.sbadge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.sbadge-completed {
    background: #d4edda;
    color: #155724;
}
.sbadge-pending {
    background: #fff3cd;
    color: #856404;
}
/* Tabs */
.hist-tabs {
    display: flex;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 20px;
}
.hist-tab {
    padding: 10px 22px;
    font-size: 13px;
    font-weight: 600;
    color: #6c757d;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    display: flex;
    align-items: center;
    gap: 7px;
    user-select: none;
}
.hist-tab:hover {
    color: #002F70;
}
.hist-tab.active {
    color: #002F70;
    border-bottom-color: #002F70;
}
.hist-tab .tab-count {
    background: #e9ecef;
    color: #495057;
    font-size: 11px;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 10px;
}
.hist-tab.active .tab-count {
    background: #002F70;
    color: #fff;
}
.hist-tab-panel {
    display: none;
}
.hist-tab-panel.active {
    display: block;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-history"></i> Inventory History</h1>
        <div class="sub">TRACK INVENTORY MOVEMENTS, STOCK-IN RECORDS, AND DELIVERIES.</div>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/staff_inventory_summary.php'; ?>

<!-- Tabs -->
<div class="hist-tabs">
    <div class="hist-tab active" onclick="switchTab('merchandise')" id="tab-merchandise">
        <i class="fas fa-boxes"></i> Merchandise
        <span class="tab-count"><?php echo count($merch_history); ?></span>
    </div>
    <div class="hist-tab" onclick="switchTab('fuel')" id="tab-fuel">
        <i class="fas fa-gas-pump"></i> Fuel
        <span class="tab-count"><?php echo count($fuel_history); ?></span>
    </div>
</div>

<!-- MERCHANDISE TAB -->
<div class="hist-tab-panel active" id="panel-merchandise">
<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">
            <i class="fas fa-boxes"></i> Merchandise Inventory Movements
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'merchHistoryTable';
            $export_filename       = 'merch_inventory_history_' . date('Ymd');
            $export_title          = 'Merchandise Inventory History';
            $export_rows_select_id = 'merchHistoryRowsLimit';
            $export_default_rows   = 25;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="table" id="merchHistoryTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Action Type</th>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Batch Number</th>
                        <th>Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($merch_history)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:36px;color:#6c757d;">
                            <i class="fas fa-inbox" style="font-size:2.5em;display:block;margin-bottom:10px;opacity:.3;"></i>
                            No merchandise inventory movements yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($merch_history as $record): ?>
                    <tr>
                        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$record['id']; ?></td>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?php echo date('M d, Y g:i A', strtotime($record['encoded_at'])); ?>
                        </td>
                        <td>
                            <span class="sbadge" style="background:#e3f2fd;color:#0d47a1;">
                                <i class="fas fa-arrow-down"></i> <?php echo htmlspecialchars($record['action_type']); ?>
                            </span>
                        </td>
                        <td><code style="font-size:11px;"><?php echo htmlspecialchars($record['item_sku'] ?? '—'); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($record['item_name'] ?? '—'); ?></strong></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($record['item_category'] ?? '—'); ?></td>
                        <td><code style="font-size:11px;color:#002F70;"><?php echo htmlspecialchars($record['batch_number'] ?? '—'); ?></code></td>
                        <td style="text-align:center;font-weight:700;color:#28a745;">
                            +<?php echo number_format((float)$record['quantity_received'], 0); ?>
                        </td>
                        <td>
                            <span class="sbadge sbadge-completed">
                                <i class="fas fa-check"></i> <?php echo htmlspecialchars($record['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="merchHistoryPagination" style="margin-top:10px;"></div>
    </div>
</div>
</div>

<!-- FUEL TAB -->
<div class="hist-tab-panel" id="panel-fuel">
<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">
            <i class="fas fa-gas-pump"></i> Fuel Inventory Movements
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'fuelHistoryTable';
            $export_filename       = 'fuel_inventory_history_' . date('Ymd');
            $export_title          = 'Fuel Inventory History';
            $export_rows_select_id = 'fuelHistoryRowsLimit';
            $export_default_rows   = 25;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="table" id="fuelHistoryTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Action Type</th>
                        <th>Invoice No.</th>
                        <th>Fuel Type</th>
                        <th>Batch Number</th>
                        <th>Quantity (L)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_history)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:36px;color:#6c757d;">
                            <i class="fas fa-inbox" style="font-size:2.5em;display:block;margin-bottom:10px;opacity:.3;"></i>
                            No fuel inventory movements yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fuel_history as $record): ?>
                    <tr>
                        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$record['id']; ?></td>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?php echo date('M d, Y g:i A', strtotime($record['encoded_at'])); ?>
                        </td>
                        <td>
                            <span class="sbadge" style="background:#fff3cd;color:#856404;">
                                <i class="fas fa-gas-pump"></i> <?php echo htmlspecialchars($record['action_type']); ?>
                            </span>
                        </td>
                        <td><code style="font-size:11px;"><?php echo htmlspecialchars($record['item_sku'] ?? '—'); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($record['item_name'] ?? '—'); ?></strong></td>
                        <td><code style="font-size:11px;color:#002F70;"><?php echo htmlspecialchars($record['batch_number'] ?? '—'); ?></code></td>
                        <td style="text-align:center;font-weight:700;color:#28a745;">
                            +<?php echo number_format((float)$record['quantity_received'], 2); ?> L
                        </td>
                        <td>
                            <span class="sbadge sbadge-completed">
                                <i class="fas fa-check"></i> <?php echo htmlspecialchars($record['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="fuelHistoryPagination" style="margin-top:10px;"></div>
    </div>
</div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.hist-tab').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.hist-tab-panel').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
    history.replaceState(null, '', '#tab-' + tab);
}

document.addEventListener('DOMContentLoaded', function() {
    // Restore tab from URL hash
    var hash = window.location.hash;
    if (hash === '#tab-fuel') {
        switchTab('fuel');
    } else {
        switchTab('merchandise');
    }
    
    setupTablePagination('merchHistoryTable', 'merchHistoryRowsLimit', 'merchHistoryPagination', 25);
    setupTablePagination('fuelHistoryTable', 'fuelHistoryRowsLimit', 'fuelHistoryPagination', 25);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
