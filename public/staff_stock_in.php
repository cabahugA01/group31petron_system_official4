<?php
/**
 * Staff Stock-In
 * Inventory is updated ONLY here, after Admin finalizes a PO.
 */
$page_id = 'staff_stock_in';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = (int)user_station_id();

if (!in_array($role, ['staff','cashier','pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}

// Ensure required columns exist
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_flag ENUM('OK','Short','Damaged','Excess','Mixed') NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS actual_qty_received INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

// Ensure merchandise_stock_in table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_stock_in (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        po_id          INT NULL,
        po_number      VARCHAR(100) NULL,
        station_id     INT NOT NULL,
        product_id     INT NOT NULL,
        product_name   VARCHAR(255) NOT NULL,
        sku            VARCHAR(100) NULL,
        category       VARCHAR(100) NULL,
        qty_ordered    INT NOT NULL DEFAULT 0,
        qty_received   INT NOT NULL DEFAULT 0,
        qty_variance   INT NOT NULL DEFAULT 0,
        unit_cost      DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_cost     DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks        TEXT NULL,
        stock_before   INT NOT NULL DEFAULT 0,
        stock_after    INT NOT NULL DEFAULT 0,
        encoded_by     INT NOT NULL,
        encoded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref      VARCHAR(100) NULL,
        INDEX idx_station (station_id),
        INDEX idx_encoded_at (encoded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Fetch pending deliveries (server-side) ────────────────────────────────────
$pending_pos = [];
$pending_fuel = [];
$pending_err = '';
try {
    $stmt = $pdo->prepare("
        SELECT po.id, po.po_number, po.product_name, po.quantity AS qty_ordered,
               po.unit_price, po.total_amount, po.admin_notes, po.admin_finalized_at,
               po.delivery_validated, po.delivery_validated_at, po.delivery_flag,
               po.delivery_notes, po.actual_qty_received,
               po.batch_id,
               ip.id AS product_id, ip.sku, ip.category, ip.unit_cost,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               u_mgr.name AS manager_name, u_adm.name AS admin_name,
               u_val.name AS validated_by_name,
               sr.remarks AS sr_remarks
        FROM purchase_orders po
        LEFT JOIN inventory_products ip
               ON ip.product_name = po.product_name AND ip.category != 'Fuel'
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = po.station_id
        LEFT JOIN users u_mgr ON po.created_by        = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id           = u_adm.id
        LEFT JOIN users u_val ON po.delivery_validated_by = u_val.id
        LEFT JOIN stock_requests sr ON po.request_id   = sr.id
        WHERE po.station_id = ? AND po.type = 'merch'
          AND po.admin_finalized   = 1
          AND po.delivery_validated = 1
          AND po.stock_in_done     = 0
        ORDER BY po.delivery_validated_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch approved fuel deliveries awaiting stock-in
    $stmtFuel = $pdo->prepare("
        SELECT fd.*, u.name as encoded_by_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ? AND fd.status = 'Awaiting Stock-In'
        ORDER BY fd.delivery_date ASC, fd.created_at ASC
    ");
    $stmtFuel->execute([$station_id]);
    $pending_fuel = $stmtFuel->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_err = $e->getMessage();
}

// ── Type and Active tab ────────────────────────────────────────────────────────
$type_filter = $_GET['type'] ?? 'merch';
if (!in_array($type_filter, ['merch', 'fuel'])) {
    $type_filter = 'merch';
}
$active_tab = $_GET['tab'] ?? 'pending';

// ── Fetch stock-in history (server-side, last 30 days) ────────────────────────
$history_rows = [];
$fuel_history = [];
$hist_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$hist_date_to   = $_GET['date_to']   ?? date('Y-m-d');
try {
    if ($type_filter === 'fuel') {
        $stmt = $pdo->prepare("
            SELECT fsi.*, u.name AS encoded_by_name
            FROM fuel_stock_in fsi
            LEFT JOIN users u ON fsi.encoded_by = u.id
            WHERE fsi.station_id = ? AND DATE(fsi.encoded_at) BETWEEN ? AND ?
            ORDER BY fsi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$station_id, $hist_date_from, $hist_date_to]);
        $fuel_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT msi.*, u.name AS encoded_by_name
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.station_id = ? AND DATE(msi.encoded_at) BETWEEN ? AND ?
            ORDER BY msi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$station_id, $hist_date_from, $hist_date_to]);
        $history_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$flash_ok  = $_SESSION['si_ok']  ?? null; unset($_SESSION['si_ok']);
$flash_err = $_SESSION['si_err'] ?? null; unset($_SESSION['si_err']);

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--orange:#fd7e14;--gray:#6c757d;}
.si-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;}
.si-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.si-card-title{font-size:1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.si-card-body{padding:18px 20px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-done{background:#d4edda;color:#155724;}
.badge-good{background:#d4edda;color:#155724;}
.badge-damaged{background:#f8d7da;color:#721c24;}
.badge-short{background:#fff3cd;color:#856404;}
.badge-excess{background:#cce5ff;color:#004085;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-primary{background:var(--blue);color:#fff;}.btn-primary:hover{background:#001F4F;}
.btn-success{background:var(--green);color:#fff;}.btn-success:hover{background:#218838;}
.btn-sm{padding:5px 12px;font-size:12px;}
.flash-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.flash-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.empty-state{text-align:center;padding:48px;color:var(--gray);}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.3;}
.tab-nav{display:flex;gap:0;border-bottom:2px solid #e9ecef;margin-bottom:22px;}
.tab-btn{padding:10px 24px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
.po-item{background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.po-item-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;gap:8px;}
.po-meta{font-size:12px;color:var(--gray);margin-bottom:12px;display:flex;flex-wrap:wrap;gap:12px;}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.si-table{width:100%;border-collapse:collapse;font-size:13px;min-width:760px;}
.si-table th{background:#f8f9fa;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--gray);border-bottom:2px solid #dee2e6;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.si-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.si-table input[type=number]{width:80px;padding:6px 8px;border:1px solid #dee2e6;border-radius:5px;font-size:13px;text-align:center;}
.si-table input[type=number]:focus{outline:none;border-color:var(--blue);}
.si-table select{padding:6px 8px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;}
.si-table input[type=text]{width:140px;padding:6px 8px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;}
.variance-pos{color:#28a745;font-weight:700;}
.variance-neg{color:#dc3545;font-weight:700;}
.variance-zero{color:#6c757d;}
.info-box{background:#e8f4fd;border-left:4px solid var(--blue);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--blue);line-height:1.6;margin-bottom:16px;}
.warn-box{background:#fff3cd;border-left:4px solid #fd7e14;border-radius:6px;padding:10px 14px;font-size:12px;color:#856404;line-height:1.6;margin-bottom:12px;}
.hist-table{width:100%;border-collapse:collapse;font-size:12px;}
.hist-table th{background:#f8f9fa;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--gray);border-bottom:2px solid #dee2e6;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.hist-table td{padding:7px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.hist-table tr:hover td{background:#f8f9fa;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-dolly"></i> Stock-In</h1>
    <div class="sub">ENCODE ACTUAL DELIVERIES RECEIVED WITH BATCH ID TO UPDATE INVENTORY.</div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/staff_inventory_summary.php'; ?>

<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>


<!-- Tabs (simple anchor links) -->
<div class="tab-nav">
  <a href="staff_stock_in.php?tab=pending&type=<?= $type_filter ?>" class="tab-btn <?= $active_tab === 'pending' ? 'active' : '' ?>">
    <i class="fas fa-dolly"></i> Pending Stock-In
    <?php 
      $pending_count = $type_filter === 'fuel' ? count($pending_fuel) : count($pending_pos);
      if ($pending_count > 0): 
    ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= $pending_count ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_stock_in.php?tab=history&type=<?= $type_filter ?>" class="tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>">
    <i class="fas fa-history"></i> Stock-In History
  </a>
</div>

<!-- Category Switcher Buttons -->
<div style="margin-bottom: 22px; display: flex; gap: 8px;">
  <a href="staff_stock_in.php?tab=<?= $active_tab ?>&type=merch" class="btn <?= $type_filter === 'merch' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="border-radius: 20px; font-weight:700; border: 1px solid #dee2e6; <?= $type_filter !== 'merch' ? 'background:#f8f9fa;color:#333;' : '' ?>">
    <i class="fas fa-boxes"></i> Merchandise
    <?php if (count($pending_pos) > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($pending_pos) ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_stock_in.php?tab=<?= $active_tab ?>&type=fuel" class="btn <?= $type_filter === 'fuel' ? 'btn-primary' : 'btn-outline' ?> btn-sm" style="border-radius: 20px; font-weight:700; border: 1px solid #dee2e6; <?= $type_filter !== 'fuel' ? 'background:#f8f9fa;color:#333;' : '' ?>">
    <i class="fas fa-gas-pump"></i> Fuel
    <?php if (count($pending_fuel) > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($pending_fuel) ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($active_tab === 'pending'): ?>
<!-- ══ PENDING TAB ══ -->
<?php if ($pending_err): ?>
  <div class="flash-err"><i class="fas fa-exclamation-circle"></i> Database error: <?= htmlspecialchars($pending_err) ?></div>
<?php elseif ($type_filter === 'fuel'): ?>
  <!-- ── FUEL PENDING ── -->
  <?php if (empty($pending_fuel)): ?>
    <div class="empty-state">
      <i class="fas fa-check-circle" style="color:#28a745;opacity:.5;"></i>
      <strong>No pending fuel deliveries for stock-in.</strong><br>
      <span style="font-size:13px;">All manager-validated fuel deliveries have been stocked in, or no deliveries have been approved yet.</span>
    </div>
  <?php else: ?>
    <?php foreach ($pending_fuel as $fd):
      $qty_expected = (float)$fd['delivery_liters'];
    ?>
    <div class="po-item" id="fuel-item-<?= $fd['id'] ?>">
      <div class="po-item-header">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--blue);">
            <i class="fas fa-gas-pump"></i> Fuel Delivery #<?= $fd['id'] ?>
          </div>
          <div style="font-size:15px;font-weight:700;color:#222;margin-top:3px;">
            <?= htmlspecialchars($fd['fuel_type'] ?? '') ?>
          </div>
        </div>
        <span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Awaiting Stock-In</span>
      </div>
      <div class="po-meta">
        <span><i class="fas fa-truck-container"></i> Supplier: <strong><?= htmlspecialchars($fd['supplier'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-truck"></i> Tanker: <strong><?= htmlspecialchars($fd['tanker_number'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-file-invoice"></i> Invoice: <strong><?= htmlspecialchars($fd['invoice_no'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-shopping-cart"></i> Expected Volume: <strong><?= number_format($qty_expected, 2) ?> L</strong></span>
        <?php if ($fd['encoded_by_name']): ?><span><i class="fas fa-user-edit"></i> Encoded by: <?= htmlspecialchars($fd['encoded_by_name']) ?></span><?php endif; ?>
        <?php if ($fd['delivery_date']): ?><span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($fd['delivery_date'])) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($fd['notes'])): ?>
      <div style="font-size:12px;color:#6c757d;margin-bottom:10px;"><i class="fas fa-comment"></i> Delivery note: <?= htmlspecialchars($fd['notes']) ?></div>
      <?php endif; ?>
      <div class="warn-box">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Fuel Stock-In Protection:</strong> Enter the actual received liters. The manager-validated quantity of <strong><?= number_format($qty_expected, 2) ?> L</strong> is the baseline. Capture any shortages, damages, or excess accurately to match tank levels.
      </div>
      <div class="table-wrap" style="margin-bottom:12px;">
        <table class="si-table">
          <thead>
            <tr>
              <th>Fuel Product</th><th>Supplier</th><th>Batch ID <span style="color:#dc3545;">*</span></th>
              <th>Unit Cost (₱/L)</th><th>Expected Liters</th>
              <th>Actual Received Liters *</th><th>Condition *</th>
              <th>Variance (L)</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong><?= htmlspecialchars($fd['fuel_type'] ?? '') ?></strong></td>
              <td><?= htmlspecialchars($fd['supplier'] ?? '') ?></td>
              <td>
                <input type="text" id="fuel-batch-<?= $fd['id'] ?>" placeholder="e.g. FB-001" maxlength="50"
                       style="width:130px;font-family:monospace;font-weight:700;font-size:12px;text-transform:uppercase;border:1px solid #dee2e6;border-radius:5px;padding:6px 8px;"
                       oninput="this.value=this.value.toUpperCase()">
              </td>
              <td>
                <input type="number" id="fuel-cost-<?= $fd['id'] ?>" min="0" step="0.01" value="0.00"
                       placeholder="0.00" style="width:90px;"
                       title="Unit cost per litre for this batch (for FIFO costing)">
              </td>
              <td style="text-align:center;font-weight:700;"><?= number_format($qty_expected, 2) ?> L</td>
              <td>
                <input type="number" id="fuel-qty-<?= $fd['id'] ?>" min="0" step="0.01" value="<?= $qty_expected ?>"
                       oninput="updateFuelVariance(<?= $fd['id'] ?>, <?= $qty_expected ?>)" style="width: 120px;">
              </td>
              <td>
                <select id="fuel-cond-<?= $fd['id'] ?>" onchange="updateFuelVariance(<?= $fd['id'] ?>, <?= $qty_expected ?>)">
                  <option value="Good">Good</option>
                  <option value="Damaged">Damaged</option>
                  <option value="Short">Short</option>
                  <option value="Excess">Excess</option>
                </select>
              </td>
              <td id="fuel-var-<?= $fd['id'] ?>" class="variance-zero">0.00 L</td>
              <td><input type="text" id="fuel-rem-<?= $fd['id'] ?>" placeholder="Optional notes..." style="width: 160px;"></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-success" id="fuel-btn-<?= $fd['id'] ?>"
                onclick="submitFuelStockIn(<?= $fd['id'] ?>, <?= $qty_expected ?>)">
          <i class="fas fa-check-circle"></i> Submit Fuel Stock-In
        </button>
        <span style="font-size:12px;color:var(--gray);">
          <i class="fas fa-lock"></i> Authoritative step: updates tank levels &amp; logs audit trail.
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php else: ?>
  <!-- ── MERCHANDISE PENDING ── -->
  <?php if (empty($pending_pos)): ?>
    <div class="empty-state">
      <i class="fas fa-check-circle" style="color:#28a745;opacity:.5;"></i>
      <strong>No pending deliveries for stock-in.</strong><br>
      <span style="font-size:13px;">Deliveries appear here after the Manager validates them. Waiting for Manager validation, or all validated deliveries have already been stocked in.</span>
    </div>
  <?php else: ?>
    <?php foreach ($pending_pos as $po):
      $product_id  = (int)($po['product_id'] ?? 0);
      $qty_ordered = (int)($po['qty_ordered'] ?? 0);
      $unit_cost   = (float)($po['unit_cost'] ?? 0);
      $cur_stock   = (int)($po['current_stock'] ?? 0);
      $del_flag    = $po['delivery_flag'] ?? 'OK';
      $actual_qty  = (int)($po['actual_qty_received'] ?? $qty_ordered);
      $flag_colors = ['OK'=>'#28a745','Short'=>'#856404','Damaged'=>'#dc3545','Excess'=>'#004085','Mixed'=>'#6f42c1'];
      $flag_color  = $flag_colors[$del_flag] ?? '#6c757d';
    ?>
    <div class="po-item" id="po-item-<?= $po['id'] ?>">
      <div class="po-item-header">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--blue);">
            <i class="fas fa-file-invoice"></i> <?= htmlspecialchars($po['po_number'] ?? 'Manual') ?>
          </div>
          <div style="font-size:15px;font-weight:700;color:#222;margin-top:3px;">
            <?= htmlspecialchars($po['product_name'] ?? '') ?>
          </div>
        </div>
        <span class="badge badge-pending"><i class="fas fa-clock"></i> Awaiting Stock-In</span>
      </div>
      <div class="po-meta">
        <?php if ($po['sku']): ?><span><i class="fas fa-barcode"></i> SKU: <?= htmlspecialchars($po['sku']) ?></span><?php endif; ?>
        <?php if ($po['category']): ?><span><i class="fas fa-tag"></i> <?= htmlspecialchars($po['category']) ?></span><?php endif; ?>
        <span><i class="fas fa-boxes"></i> Current Stock: <strong><?= $cur_stock ?></strong></span>
        <span><i class="fas fa-shopping-cart"></i> Ordered: <strong><?= $qty_ordered ?></strong></span>
        <span><i class="fas fa-clipboard-check"></i> Manager Actual Qty: <strong><?= $actual_qty ?></strong></span>
        <?php if ($po['validated_by_name']): ?><span><i class="fas fa-user-check"></i> Validated by: <strong><?= htmlspecialchars($po['validated_by_name']) ?></strong></span><?php endif; ?>
        <?php if ($po['delivery_validated_at']): ?><span><i class="fas fa-calendar-check"></i> <?= date('M d, Y H:i', strtotime($po['delivery_validated_at'])) ?></span><?php endif; ?>
        <?php if ($po['admin_name']): ?><span><i class="fas fa-user-shield"></i> Admin: <?= htmlspecialchars($po['admin_name']) ?></span><?php endif; ?>
      </div>
      <!-- Manager validation summary -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:#495057;">Delivery Flag:</span>
        <span style="background:<?= $flag_color ?>20;color:<?= $flag_color ?>;border:1px solid <?= $flag_color ?>40;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;">
          <?= htmlspecialchars($del_flag) ?>
        </span>
        <?php if (!empty($po['delivery_notes'])): ?>
        <span style="font-size:12px;color:#6c757d;"><i class="fas fa-comment"></i> <?= htmlspecialchars($po['delivery_notes']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($po['sr_remarks'])): ?>
      <div style="font-size:12px;color:#6c757d;margin-bottom:10px;"><i class="fas fa-comment"></i> Staff note: <?= htmlspecialchars($po['sr_remarks']) ?></div>
      <?php endif; ?>
      <div class="warn-box">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Manual Encode Required:</strong> Enter the <em>actual</em> quantity received. Capture shortages, damages, or excess accurately.
      </div>
      <!-- Batch ID display / override -->
      <div style="background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <label style="font-size:12px;font-weight:700;color:#002F70;white-space:nowrap;margin:0;"><i class="fas fa-tag"></i> Batch ID:</label>
        <input type="text" id="batch-<?= $po['id'] ?>" value="<?= htmlspecialchars($po['batch_id'] ?? '') ?>"
               placeholder="Enter Batch ID (e.g. BATCH-001)"
               style="font-family:monospace;font-size:0.9rem;padding:5px 10px;border:1px solid #bcd2ee;border-radius:5px;min-width:180px;color:#002F70;font-weight:700;">
        <?php if (!empty($po['batch_id'])): ?>
        <span style="font-size:11px;color:#6c757d;"><i class="fas fa-info-circle"></i> Pre-filled from PO. Edit if different from actual delivery.</span>
        <?php else: ?>
        <span style="font-size:11px;color:#dc3545;"><i class="fas fa-exclamation-circle"></i> No Batch ID on PO — please enter one below.</span>
        <?php endif; ?>
      </div>
      <div class="table-wrap" style="margin-bottom:12px;">
        <table class="si-table">
          <thead>
            <tr>
              <th>Product</th><th>SKU</th><th>Ordered</th>
              <th>Actual Received *</th><th>Condition *</th>
              <th>Unit Cost</th><th>Variance</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong><?= htmlspecialchars($po['product_name'] ?? '') ?></strong></td>
              <td><code style="font-size:11px;"><?= htmlspecialchars($po['sku'] ?? '') ?></code></td>
              <td style="text-align:center;font-weight:700;"><?= $qty_ordered ?></td>
              <td>
                <input type="number" id="qty-<?= $po['id'] ?>" min="0" value="<?= $actual_qty ?>"
                       oninput="updateVariance(<?= $po['id'] ?>, <?= $qty_ordered ?>)">
              </td>
              <td>
                <select id="cond-<?= $po['id'] ?>" onchange="updateVariance(<?= $po['id'] ?>, <?= $qty_ordered ?>)">
                  <option value="Good">Good</option>
                  <option value="Damaged">Damaged</option>
                  <option value="Short">Short</option>
                  <option value="Excess">Excess</option>
                </select>
              </td>
              <td>&#8369;<?= number_format($unit_cost, 2) ?></td>
              <td id="var-<?= $po['id'] ?>" class="variance-zero">0</td>
              <td><input type="text" id="rem-<?= $po['id'] ?>" placeholder="Optional notes..."></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-success" id="btn-<?= $po['id'] ?>"
                onclick="submitStockIn(<?= $po['id'] ?>, <?= $product_id ?>, <?= $qty_ordered ?>, <?= $unit_cost ?>)">
          <i class="fas fa-check-circle"></i> Submit Stock-In
        </button>
        <span style="font-size:12px;color:var(--gray);">
          <i class="fas fa-lock"></i> Updates inventory &amp; creates batch record.
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php else: ?>
<!-- ══ HISTORY TAB ══ -->
<div class="si-card">
  <div class="si-card-head">
    <div class="si-card-title"><i class="fas fa-history"></i> Stock-In History</div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-left:auto;">
      <form method="get" action="staff_stock_in.php" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0;">
        <input type="hidden" name="tab" value="history">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">
        <input type="date" name="date_from" value="<?= htmlspecialchars($hist_date_from) ?>"
               style="padding:6px 10px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;height:36px;">
        <span style="font-size:12px;color:var(--gray);">to</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($hist_date_to) ?>"
               style="padding:6px 10px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;height:36px;">
        <button type="submit" class="btn btn-primary" style="height:36px;padding:8px 14px;"><i class="fas fa-filter"></i> Filter</button>
      </form>
      <?php
      $export_table_id       = ($type_filter === 'fuel') ? 'fuelStockInHistoryTable' : 'merchStockInHistoryTable';
      $export_filename       = (($type_filter === 'fuel') ? 'fuel_stock_in_' : 'merch_stock_in_') . date('Ymd');
      $export_title          = ($type_filter === 'fuel') ? 'Fuel Stock-In History' : 'Merchandise Stock-In History';
      $export_rows_select_id = ($type_filter === 'fuel') ? 'fuelSiRowsLimit' : 'merchSiRowsLimit';
      $export_default_rows   = 25;
      require __DIR__ . '/../partials/export_buttons.php';
      ?>
    </div>
  </div>
  <div class="si-card-body">
    <?php if ($type_filter === 'fuel'): ?>
      <!-- ── FUEL HISTORY ── -->
      <?php if (empty($fuel_history)): ?>
        <div class="empty-state">
          <i class="fas fa-history"></i>
          <strong>No fuel stock-in records found.</strong><br>
          <span style="font-size:13px;">Try a different date range.</span>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="hist-table" id="fuelStockInHistoryTable">
            <thead>
              <tr>
                <th>Batch Ref</th><th>Date & Time</th><th>Delivery ID</th><th>Invoice / DR</th><th>Fuel Product</th>
                <th>Expected (L)</th><th>Received (L)</th><th>Variance (L)</th>
                <th>Condition</th><th>Level Before</th><th>Level After</th>
                <th>Encoded By</th><th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fuel_history as $r):
                $v = (float)$r['qty_variance'];
                $vc = $v > 0 ? 'variance-pos' : ($v < 0 ? 'variance-neg' : 'variance-zero');
                $cf = strtolower($r['condition_flag'] ?? 'good');
              ?>
              <tr>
                <td><code style="font-size:11px;"><?= htmlspecialchars($r['batch_ref'] ?? '') ?></code></td>
                <td style="white-space:nowrap;font-size:12px;"><?= $r['encoded_at'] ? date('M d, Y H:i', strtotime($r['encoded_at'])) : '' ?></td>
                <td>#<?= (int)$r['delivery_id'] ?></td>
                <td><?= htmlspecialchars($r['invoice_no'] ?? '&mdash;') ?></td>
                <td><strong><?= htmlspecialchars($r['fuel_type'] ?? '') ?></strong></td>
                <td style="text-align:right;"><?= number_format($r['qty_expected'], 2) ?> L</td>
                <td style="text-align:right;font-weight:700;"><?= number_format($r['qty_received'], 2) ?> L</td>
                <td style="text-align:right;" class="<?= $vc ?>"><?= ($v >= 0 ? '+' : '') . number_format($v, 2) ?> L</td>
                <td><span class="badge badge-<?= $cf ?>"><?= htmlspecialchars($r['condition_flag'] ?? '') ?></span></td>
                <td style="text-align:right;"><?= number_format($r['level_before'], 2) ?> L</td>
                <td style="text-align:right;font-weight:700;color:#28a745;"><?= number_format($r['level_after'], 2) ?> L</td>
                <td><?= htmlspecialchars($r['encoded_by_name'] ?? '') ?></td>
                <td style="font-size:11px;color:#6c757d;max-width:140px;"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="fuelStockInHistoryPagination" style="margin-top:10px;"></div>
        <div style="margin-top:10px;font-size:12px;color:var(--gray);">
          Showing <?= count($fuel_history) ?> record(s) from <?= htmlspecialchars($hist_date_from) ?> to <?= htmlspecialchars($hist_date_to) ?>.
          <a href="staff_stock_in.php?tab=history&type=fuel&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" style="margin-left:8px;">This month</a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <!-- ── MERCHANDISE HISTORY ── -->
      <?php if (empty($history_rows)): ?>
        <div class="empty-state">
          <i class="fas fa-history"></i>
          <strong>No stock-in records found.</strong><br>
          <span style="font-size:13px;">Try a different date range.</span>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="hist-table" id="merchStockInHistoryTable">
            <thead>
              <tr>
                <th>Batch Ref</th><th>Date</th><th>PO Number</th><th>Product</th>
                <th>SKU</th><th>Ordered</th><th>Received</th><th>Variance</th>
                <th>Condition</th><th>Stock Before</th><th>Stock After</th>
                <th>Encoded By</th><th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history_rows as $r):
                $v = (int)$r['qty_variance'];
                $vc = $v > 0 ? 'variance-pos' : ($v < 0 ? 'variance-neg' : 'variance-zero');
                $cf = strtolower($r['condition_flag'] ?? 'good');
              ?>
              <tr>
                <td><code style="font-size:11px;"><?= htmlspecialchars($r['batch_ref'] ?? '') ?></code></td>
                <td style="white-space:nowrap;font-size:12px;"><?= $r['encoded_at'] ? date('M d, Y', strtotime($r['encoded_at'])) : '' ?></td>
                <td><?= htmlspecialchars($r['po_number'] ?? '&mdash;') ?></td>
                <td><strong><?= htmlspecialchars($r['product_name'] ?? '') ?></strong></td>
                <td><code style="font-size:11px;"><?= htmlspecialchars($r['sku'] ?? '') ?></code></td>
                <td style="text-align:center;"><?= (int)$r['qty_ordered'] ?></td>
                <td style="text-align:center;font-weight:700;"><?= (int)$r['qty_received'] ?></td>
                <td style="text-align:center;" class="<?= $vc ?>"><?= ($v >= 0 ? '+' : '') . $v ?></td>
                <td><span class="badge badge-<?= $cf ?>"><?= htmlspecialchars($r['condition_flag'] ?? '') ?></span></td>
                <td style="text-align:center;"><?= (int)$r['stock_before'] ?></td>
                <td style="text-align:center;font-weight:700;color:#28a745;"><?= (int)$r['stock_after'] ?></td>
                <td><?= htmlspecialchars($r['encoded_by_name'] ?? '') ?></td>
                <td style="font-size:11px;color:#6c757d;max-width:140px;"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div id="merchStockInHistoryPagination" style="margin-top:10px;"></div>
        <div style="margin-top:10px;font-size:12px;color:var(--gray);">
          Showing <?= count($history_rows) ?> record(s) from <?= htmlspecialchars($hist_date_from) ?> to <?= htmlspecialchars($hist_date_to) ?>.
          <a href="staff_stock_in.php?tab=history&type=merch&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" style="margin-left:8px;">This month</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div id="flashMsg" style="display:none;position:fixed;top:24px;right:24px;padding:13px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:340px;"></div>

<script>
function updateVariance(poId, qtyOrdered) {
    var qty = parseInt(document.getElementById('qty-' + poId).value) || 0;
    var v = qty - qtyOrdered;
    var el = document.getElementById('var-' + poId);
    el.textContent = (v >= 0 ? '+' : '') + v;
    el.className = v > 0 ? 'variance-pos' : (v < 0 ? 'variance-neg' : 'variance-zero');
}

function submitStockIn(poId, productId, qtyOrdered, unitCost) {
    var qtyReceived = parseInt(document.getElementById('qty-' + poId).value) || 0;
    var condition   = document.getElementById('cond-' + poId).value;
    var remarks     = document.getElementById('rem-' + poId).value.trim();
    var batchId     = (document.getElementById('batch-' + poId) || {}).value || '';

    if (qtyReceived < 0) { showToast('Quantity received cannot be negative.', 'err'); return; }
    if (!batchId.trim()) {
        if (!confirm('No Batch ID entered. Submit anyway?')) return;
    }

    var msg = 'Submit Stock-In?\n\nBatch ID: ' + (batchId || '(none)') + '\nActual Received: ' + qtyReceived + '\nCondition: ' + condition;
    if (condition === 'Damaged' || condition === 'Short') {
        msg += '\n\nNOTE: ' + condition + ' items will NOT be added to inventory.';
    }
    if (!confirm(msg)) return;

    var btn = document.getElementById('btn-' + poId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    fetch('../backend/api/merchandise_stock_in.php?action=submit_stock_in', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            po_id: poId,
            batch_id: batchId,
            items: [{
                product_id:   productId,
                qty_received: qtyReceived,
                qty_ordered:  qtyOrdered,
                unit_cost:    unitCost,
                condition:    condition,
                remarks:      remarks
            }],
            batch_note: remarks
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Stock-In submitted! Batch: ' + (data.batch_ref || batchId || 'N/A'), 'ok');
            var item = document.getElementById('po-item-' + poId);
            if (item) {
                item.style.opacity = '0.4';
                item.style.pointerEvents = 'none';
                var b = item.querySelector('.badge');
                if (b) { b.className = 'badge badge-done'; b.innerHTML = '<i class="fas fa-check"></i> Stocked In'; }
            }
            setTimeout(function() { window.location.href = 'staff_stock_in.php?tab=history&type=merch'; }, 1800);
        } else {
            showToast(data.message || 'Error submitting stock-in.', 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Stock-In';
        }
    })
    .catch(function(e) {
        showToast('Network error. Please try again.', 'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Stock-In';
    });
}

function updateFuelVariance(fdId, qtyExpected) {
    var qty = parseFloat(document.getElementById('fuel-qty-' + fdId).value) || 0;
    var v = qty - qtyExpected;
    var el = document.getElementById('fuel-var-' + fdId);
    el.textContent = (v >= 0 ? '+' : '') + v.toFixed(2) + ' L';
    el.className = v > 0 ? 'variance-pos' : (v < 0 ? 'variance-neg' : 'variance-zero');
}

function submitFuelStockIn(fdId, qtyExpected) {
    var qtyReceived = parseFloat(document.getElementById('fuel-qty-' + fdId).value) || 0;
    var condition   = document.getElementById('fuel-cond-' + fdId).value;
    var remarks     = document.getElementById('fuel-rem-' + fdId).value.trim();
    var batchIdEl   = document.getElementById('fuel-batch-' + fdId);
    var batchId     = batchIdEl ? batchIdEl.value.trim().toUpperCase() : '';
    var unitCostEl  = document.getElementById('fuel-cost-' + fdId);
    var unitCost    = unitCostEl ? (parseFloat(unitCostEl.value) || 0) : 0;

    if (!batchId) { showToast('Batch ID is required before submitting.', 'err'); if (batchIdEl) batchIdEl.focus(); return; }
    if (qtyReceived < 0) { showToast('Quantity received cannot be negative.', 'err'); return; }

    var msg = 'Submit Fuel Stock-In?\n\nBatch ID: ' + batchId + '\nActual Received: ' + qtyReceived.toFixed(2) + ' L\nCondition: ' + condition;
    if (unitCost > 0) msg += '\nUnit Cost: ₱' + unitCost.toFixed(2) + '/L';
    if (condition === 'Damaged' || condition === 'Short') {
        msg += '\n\nNOTE: ' + condition + ' fuel losses will NOT be added to inventory tank levels.';
    }
    if (!confirm(msg)) return;

    var btn = document.getElementById('fuel-btn-' + fdId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    fetch('../backend/api/merchandise_stock_in.php?action=submit_fuel_stock_in', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            delivery_id:  fdId,
            qty_received: qtyReceived,
            condition:    condition,
            remarks:      remarks,
            batch_id:     batchId,
            unit_cost:    unitCost
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast(data.message, 'ok');
            var item = document.getElementById('fuel-item-' + fdId);
            if (item) {
                item.style.opacity = '0.4';
                item.style.pointerEvents = 'none';
                var b = item.querySelector('.badge');
                if (b) { b.className = 'badge badge-done'; b.innerHTML = '<i class="fas fa-check"></i> Stocked In'; }
            }
            setTimeout(function() { window.location.href = 'staff_stock_in.php?tab=history&type=fuel'; }, 1800);
        } else {
            showToast(data.message || 'Error submitting stock-in.', 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Fuel Stock-In';
        }
    })
    .catch(function(e) {
        showToast('Network error. Please try again.', 'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Fuel Stock-In';
    });
}

function showToast(msg, type) {
    var el = document.getElementById('flashMsg');
    el.style.background = type === 'ok' ? '#28a745' : '#dc3545';
    el.textContent = msg;
    el.style.display = 'block';
    if (type === 'ok') setTimeout(function() { el.style.display = 'none'; }, 5000);
}
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('fuelStockInHistoryTable')) {
        setupTablePagination('fuelStockInHistoryTable', 'fuelSiRowsLimit', 'fuelStockInHistoryPagination', 25);
    }
    if (document.getElementById('merchStockInHistoryTable')) {
        setupTablePagination('merchStockInHistoryTable', 'merchSiRowsLimit', 'merchStockInHistoryPagination', 25);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
