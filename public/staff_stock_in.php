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

// Stock-In is NO LONGER a manual staff function.
// Inventory is updated automatically by the system after Manager approval.
// Staff are redirected away; this page is retained for Manager/Admin audit access only.
if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $_SESSION['inv_notice'] = 'Stock-In is handled automatically by the system after Manager verification. You do not need to encode stock-in manually.';
    header('Location: staff_inventory_merchandise.php'); exit;
}
if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}
if (empty($_GET['legacy'])) {
    header('Location: manager_stock_in.php');
    exit;
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
    // Fetch MERCHANDISE deliveries from deliveries_oversight table
    // These are deliveries that Admin has processed/validated and are ready for stock-in
    $stmt = $pdo->prepare("
        SELECT 
            do2.id AS delivery_id,
            do2.delivery_ref,
            do2.delivery_type,
            do2.supplier,
            do2.product,
            do2.quantity,
            do2.unit,
            do2.delivery_date,
            do2.source_ref AS po_number,
            do2.expected_quantity,
            do2.actual_quantity,
            do2.damaged_quantity,
            do2.unit_price,
            do2.payable_amount,
            do2.discrepancy_type,
            do2.admin_notes,
            do2.admin_action_at,
            do2.batch_id,
            do2.dr_number,
            ip.id AS product_id,
            ip.sku,
            ip.category,
            ip.unit_cost,
            COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
            CONCAT(u_enc.first_name, ' ', u_enc.last_name) AS encoded_by_name,
            CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name
        FROM deliveries_oversight do2
        LEFT JOIN inventory_products ip 
               ON ip.product_name = do2.product AND ip.category != 'Fuel'
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = do2.station_id
        LEFT JOIN users u_enc ON do2.encoded_by = u_enc.id
        LEFT JOIN users u_adm ON do2.admin_id = u_adm.id
        WHERE do2.station_id = ?
          AND do2.delivery_type = 'merchandise'
          AND do2.status IN ('Ready for Stock-In', 'Verified', 'Validated', 'Partial Delivery', 'Damaged Items')
          AND NOT EXISTS (
              SELECT 1 FROM merchandise_stock_in msi 
              WHERE msi.po_number = do2.delivery_ref 
                 OR msi.batch_ref = do2.batch_id
          )
        ORDER BY do2.manager_action_at ASC, do2.delivery_date ASC
    ");
    $stmt->execute([$station_id]);
    $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch FUEL deliveries from deliveries_oversight table
    $stmtFuel = $pdo->prepare("
        SELECT
            do2.id AS delivery_id,
            do2.delivery_ref,
            do2.delivery_type,
            do2.supplier,
            do2.product AS fuel_type,
            do2.quantity AS delivery_liters,
            do2.unit,
            do2.delivery_date,
            do2.source_ref AS po_number,
            do2.expected_quantity,
            do2.actual_quantity,
            do2.damaged_quantity,
            do2.unit_price,
            do2.payable_amount,
            do2.discrepancy_type,
            do2.admin_notes AS notes,
            do2.admin_action_at,
            do2.batch_id,
            do2.dr_number AS invoice_no,
            do2.dr_number AS tanker_number,
            CONCAT(u_enc.first_name, ' ', u_enc.last_name) AS encoded_by_name,
            CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name
        FROM deliveries_oversight do2
        LEFT JOIN users u_enc ON do2.encoded_by = u_enc.id
        LEFT JOIN users u_adm ON do2.admin_id = u_adm.id
        WHERE do2.station_id = ?
          AND do2.delivery_type = 'fuel'
          AND do2.status IN ('Ready for Stock-In', 'Validated', 'Partial Delivery', 'Damaged Items')
          AND NOT EXISTS (
              SELECT 1 FROM fuel_stock_in fsi 
              WHERE fsi.delivery_ref = do2.delivery_ref 
                 OR fsi.batch_ref = do2.batch_id
          )
        ORDER BY do2.manager_action_at ASC, do2.delivery_date ASC
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

// Deliveries History tab removed - all stock-in records go directly to Stock-In History

// ── Fetch stock-in history (server-side, last 30 days) - COMBINED ────────────
$history_rows = [];
$hist_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$hist_date_to   = $_GET['date_to']   ?? date('Y-m-d');
try {
    // Fetch BOTH merchandise and fuel stock-in records combined
    $stmt = $pdo->prepare("
        SELECT 
            'merchandise' AS type,
            msi.id,
            msi.batch_ref,
            msi.po_number AS reference,
            msi.product_name AS product,
            msi.sku,
            msi.category,
            msi.qty_ordered AS qty_expected,
            msi.qty_received,
            msi.qty_variance,
            msi.condition_flag,
            msi.stock_before,
            msi.stock_after,
            msi.remarks,
            msi.encoded_at,
            u.name AS encoded_by_name,
            NULL AS fuel_type,
            NULL AS level_before,
            NULL AS level_after,
            COALESCE(ip.size, 'Piece') AS unit
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        LEFT JOIN inventory_products ip ON msi.product_id = ip.id
        WHERE msi.station_id = ? AND DATE(msi.encoded_at) BETWEEN ? AND ?
        
        UNION ALL
        
        SELECT 
            'fuel' AS type,
            fsi.id,
            fsi.batch_ref,
            fsi.invoice_no AS reference,
            fsi.fuel_type AS product,
            NULL AS sku,
            'Fuel' AS category,
            fsi.qty_expected,
            fsi.qty_received,
            fsi.qty_variance,
            fsi.condition_flag,
            NULL AS stock_before,
            NULL AS stock_after,
            fsi.remarks,
            fsi.encoded_at,
            u.name AS encoded_by_name,
            fsi.fuel_type,
            fsi.level_before,
            fsi.level_after,
            'L' AS unit
        FROM fuel_stock_in fsi
        LEFT JOIN users u ON fsi.encoded_by = u.id
        WHERE fsi.station_id = ? AND DATE(fsi.encoded_at) BETWEEN ? AND ?
        
        ORDER BY encoded_at DESC
        LIMIT 100
    ");
    $stmt->execute([$station_id, $hist_date_from, $hist_date_to, $station_id, $hist_date_from, $hist_date_to]);
    $history_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Stock-In History Error: " . $e->getMessage());
}

$flash_ok  = $_SESSION['si_ok']  ?? null; unset($_SESSION['si_ok']);
$flash_err = $_SESSION['si_err'] ?? null; unset($_SESSION['si_err']);

include __DIR__ . '/../partials/header.php';
?>
<div class="stock-page">
<style>
:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--orange:#fd7e14;--gray:#6c757d;}
.si-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;}
.si-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.si-card-title{font-size:1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.si-card-body{padding:18px 20px;}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;white-space:nowrap;background:none !important;padding:0 !important;border:none !important;text-transform:uppercase;}
.badge-pending{color:#fd7e14 !important;}
.badge-done{color:#28a745 !important;}
.badge-good{color:#28a745 !important;}
.badge-damaged{color:#dc3545 !important;}
.badge-short{color:#dc3545 !important;}
.badge-excess{color:#17a2b8 !important;}
.badge-ready{color:#fd7e14 !important;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-primary{background:var(--blue);color:#fff;}.btn-primary:hover{background:#001F4F;}
.btn-success{background:var(--green);color:#fff;}.btn-success:hover{background:#218838;}
.btn-outline{background:transparent;color:var(--blue);border:1px solid var(--blue) !important;}.btn-outline:hover{background:var(--blue);color:#fff;}
.btn-sm{padding:5px 12px;font-size:12px;}
.flash-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.flash-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.empty-state{text-align:center;padding:48px;color:var(--gray);}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.3;}
.tab-nav{display:flex;gap:10px;padding:0 4px;margin-bottom:22px;flex-wrap:wrap;border-bottom:none;}
.tab-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:all 0.15s;text-decoration:none;
          background:#ffffff !important;border:1px solid #002F6C !important;color:#002F6C !important;}
.tab-btn.active{background:#002F6C !important;color:#ffffff !important;}
.tab-btn:hover{background:#002F6C !important;color:#ffffff !important;}
.po-item{background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.po-item-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;gap:8px;}
.po-meta{font-size:12px;color:var(--gray);margin-bottom:12px;display:flex;flex-wrap:wrap;gap:12px;}
.table-wrap{overflow-x:hidden;-webkit-overflow-scrolling:touch;}
.si-table{width:100%;border-collapse:collapse;font-size:13px;}
.si-table th{background:#002F70;padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#fff;border-bottom:none;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.si-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;text-align:center;}
.si-table tbody tr:hover td{background:#eff6ff;}
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
.hist-table th{background:#002F70;padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#fff;border-bottom:none;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.hist-table td{padding:7px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;text-align:center;}
.hist-table tbody tr:hover td{background:#eff6ff;}

/* Protect txn-btn from global header button color override */
.txn-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; gap:6px !important; padding:7px 14px !important; border-radius:4px !important; font-size:11px !important; font-weight:600 !important; cursor:pointer !important; border:1px solid transparent !important; transition:all .2s ease-in-out !important; text-decoration:none !important; white-space:nowrap !important; box-sizing:border-box !important; background-color:#ffffff !important; background:#ffffff !important; }
.txn-btn.primary   { color:#00264D !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #00264D !important; }
.txn-btn.primary:hover   { background-color:#00264D !important; background:#00264D !important; color:#ffffff !important; }
.txn-btn.secondary { color:#475569 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #475569 !important; }
.txn-btn.secondary:hover { background-color:#475569 !important; background:#475569 !important; color:#ffffff !important; }
.txn-btn.success   { color:#16a34a !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #16a34a !important; }
.txn-btn.success:hover   { background-color:#16a34a !important; background:#16a34a !important; color:#ffffff !important; }
.txn-btn.warning   { color:#b45309 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #b45309 !important; }
.txn-btn.warning:hover   { background-color:#b45309 !important; background:#b45309 !important; color:#ffffff !important; }
.txn-btn.danger    { color:#dc2626 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #dc2626 !important; }
.txn-btn.danger:hover    { background-color:#dc2626 !important; background:#dc2626 !important; color:#ffffff !important; }
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-dolly"></i> Stock-In</h1>
    <div class="sub">ENCODE ACTUAL DELIVERIES RECEIVED WITH BATCH ID TO UPDATE INVENTORY.</div>
  </div>
</div>


<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>


<!-- Tabs (only Pending Stock-In and Stock-In History) -->
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
  <a href="staff_stock_in.php?tab=history" class="tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>">
    <i class="fas fa-history"></i> Stock-In History (All)
  </a>
</div>

<!-- Category Switcher Buttons -->
<div style="margin-bottom: 22px; display: flex; gap: 8px;">
  <a href="staff_stock_in.php?tab=<?= $active_tab ?>&type=merch" class="txn-btn <?= $type_filter === 'merch' ? 'primary' : 'secondary' ?>">
    <i class="fas fa-boxes"></i> Merchandise
    <?php if (count($pending_pos) > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($pending_pos) ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_stock_in.php?tab=<?= $active_tab ?>&type=fuel" class="txn-btn <?= $type_filter === 'fuel' ? 'primary' : 'secondary' ?>">
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
      <span style="font-size:13px;">All admin-validated fuel deliveries have been stocked in, or no deliveries have been approved yet.</span>
    </div>
  <?php else: ?>
    <?php foreach ($pending_fuel as $fd):
      // Map deliveries_oversight fields for fuel
      $delivery_id = (int)$fd['delivery_id'];
      $qty_expected = (float)((float)$fd['actual_quantity'] ?: $fd['delivery_liters']);
    ?>
    <div class="po-item" id="fuel-item-<?= $delivery_id ?>">
      <div class="po-item-header">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--blue);">
            <i class="fas fa-gas-pump"></i> <?= htmlspecialchars($fd['delivery_ref'] ?? 'Fuel Delivery') ?>
          </div>
          <div style="font-size:15px;font-weight:700;color:#222;margin-top:3px;">
            <?= htmlspecialchars($fd['fuel_type'] ?? '') ?>
          </div>
        </div>
        <span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Awaiting Stock-In</span>
      </div>
      <div class="po-meta">
        <span><i class="fas fa-truck-container"></i> Supplier: <strong><?= htmlspecialchars($fd['supplier'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-truck"></i> DR/Tanker: <strong><?= htmlspecialchars($fd['tanker_number'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-file-invoice"></i> Invoice: <strong><?= htmlspecialchars($fd['invoice_no'] ?? 'N/A') ?></strong></span>
        <span><i class="fas fa-shopping-cart"></i> Expected Volume: <strong><?= number_format($qty_expected, 2) ?> L</strong></span>
        <?php if ($fd['encoded_by_name']): ?><span><i class="fas fa-user-edit"></i> Encoded by: <?= htmlspecialchars($fd['encoded_by_name']) ?></span><?php endif; ?>
        <?php if ($fd['admin_name']): ?><span><i class="fas fa-user-shield"></i> Admin: <?= htmlspecialchars($fd['admin_name']) ?></span><?php endif; ?>
        <?php if ($fd['delivery_date']): ?><span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($fd['delivery_date'])) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($fd['admin_notes'])): ?>
      <div style="font-size:12px;color:#6c757d;margin-bottom:10px;"><i class="fas fa-comment"></i> Admin note: <?= htmlspecialchars($fd['admin_notes']) ?></div>
      <?php endif; ?>
      <?php if (!empty($fd['discrepancy_type']) && $fd['discrepancy_type'] !== ''): ?>
      <div class="warn-box">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Discrepancy Detected:</strong> <?= htmlspecialchars($fd['discrepancy_type']) ?>
        <?php if ((float)$fd['damaged_quantity'] > 0): ?>
          | Damaged: <strong><?= number_format((float)$fd['damaged_quantity'], 2) ?> L</strong>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <strong>Stock-In Instruction:</strong> Enter the actual received liters. Admin validated quantity: <strong><?= number_format($qty_expected, 2) ?> L</strong>. Capture any shortages, damages, or excess accurately to match tank levels.
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
                <input type="text" id="fuel-batch-<?= $delivery_id ?>" placeholder="e.g. FB-001" maxlength="50"
                       value="<?= htmlspecialchars($fd['batch_id'] ?? '') ?>"
                       style="width:130px;font-family:monospace;font-weight:700;font-size:12px;text-transform:uppercase;border:1px solid #dee2e6;border-radius:5px;padding:6px 8px;"
                       oninput="this.value=this.value.toUpperCase()">
              </td>
              <td>
                <input type="number" id="fuel-cost-<?= $delivery_id ?>" min="0" step="0.01" 
                       value="<?= number_format((float)($fd['unit_price'] ?? 0), 2) ?>"
                       placeholder="0.00" style="width:90px;"
                       title="Unit cost per litre for this batch (for FIFO costing)">
              </td>
              <td style="text-align:center;font-weight:700;"><?= number_format($qty_expected, 2) ?> L</td>
              <td>
                <input type="number" id="fuel-qty-<?= $delivery_id ?>" min="0" step="0.01" value="<?= $qty_expected ?>"
                       oninput="updateFuelVariance(<?= $delivery_id ?>, <?= $qty_expected ?>)" style="width: 120px;">
              </td>
              <td>
                <select id="fuel-cond-<?= $delivery_id ?>" onchange="updateFuelVariance(<?= $delivery_id ?>, <?= $qty_expected ?>)">
                  <option value="Good">Good</option>
                  <option value="Damaged">Damaged</option>
                  <option value="Short">Short</option>
                  <option value="Excess">Excess</option>
                </select>
              </td>
              <td id="fuel-var-<?= $delivery_id ?>" class="variance-zero">0.00 L</td>
              <td><input type="text" id="fuel-rem-<?= $delivery_id ?>" placeholder="Optional notes..." style="width: 160px;"></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button class="txn-btn success" id="fuel-btn-<?= $delivery_id ?>"
                onclick="submitFuelStockIn(<?= $delivery_id ?>, <?= $qty_expected ?>)">
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
      <span style="font-size:13px;">Deliveries appear here after Admin validates them. Waiting for Admin validation, or all validated deliveries have already been stocked in.</span>
    </div>
  <?php else: ?>
    <?php foreach ($pending_pos as $po):
      $delivery_id = (int)($po['delivery_id'] ?? 0);
      $product_id  = (int)($po['product_id'] ?? 0);
      $qty_ordered = (int)((float)$po['expected_quantity'] ?: $po['quantity']);
      $actual_qty  = (int)((float)$po['actual_quantity'] ?: $qty_ordered);
      $unit_cost   = (float)($po['unit_price'] ?: $po['unit_cost'] ?: 0);
      $cur_stock   = (int)($po['current_stock'] ?? 0);
      $unit        = format_merch_unit($po['unit'] ?? 'pcs');
      
      // Determine discrepancy badge
      $disc_type = $po['discrepancy_type'] ?? '';
      $flag_colors = [''=>'#28a745','Partial'=>'#856404','Damaged'=>'#dc3545','Rejected'=>'#6c757d','Mixed'=>'#6f42c1'];
      $flag_color  = $flag_colors[$disc_type] ?? '#28a745';
    ?>
    <div class="po-item" id="po-item-<?= $delivery_id ?>">
      <div class="po-item-header">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--blue);">
            <i class="fas fa-file-invoice"></i> <?= htmlspecialchars($po['delivery_ref'] ?? $po['po_number'] ?? 'Delivery') ?>
          </div>
          <div style="font-size:15px;font-weight:700;color:#222;margin-top:3px;">
            <?= htmlspecialchars($po['product'] ?? $po['product_name'] ?? '') ?>
          </div>
        </div>
        <span class="badge badge-pending"><i class="fas fa-clock"></i> Awaiting Stock-In</span>
      </div>
      <div class="po-meta">
        <?php if ($po['sku']): ?><span><i class="fas fa-barcode"></i> SKU: <?= htmlspecialchars($po['sku']) ?></span><?php endif; ?>
        <?php if ($po['category']): ?><span><i class="fas fa-tag"></i> <?= htmlspecialchars($po['category']) ?></span><?php endif; ?>
        <span><i class="fas fa-boxes"></i> Current Stock: <strong><?= number_format($cur_stock) ?> <?= htmlspecialchars($unit) ?></strong></span>
        <span><i class="fas fa-shopping-cart"></i> Expected: <strong><?= number_format($qty_ordered) ?> <?= htmlspecialchars($unit) ?></strong></span>
        <span><i class="fas fa-clipboard-check"></i> Admin Actual Qty: <strong><?= number_format($actual_qty) ?> <?= htmlspecialchars($unit) ?></strong></span>
        <?php if (!empty($po['unit_price'])): ?><span><i class="fas fa-money-bill-wave"></i> Unit Price: <strong>₱<?= number_format((float)$po['unit_price'], 2) ?></strong></span><?php endif; ?>
        <?php if ($po['encoded_by_name']): ?><span><i class="fas fa-user-edit"></i> Encoded by: <?= htmlspecialchars($po['encoded_by_name']) ?></span><?php endif; ?>
        <?php if ($po['admin_name']): ?><span><i class="fas fa-user-shield"></i> Admin: <?= htmlspecialchars($po['admin_name']) ?></span><?php endif; ?>
        <?php if ($po['admin_action_at']): ?><span><i class="fas fa-calendar-check"></i> <?= date('M d, Y H:i', strtotime($po['admin_action_at'])) ?></span><?php endif; ?>
      </div>
      <!-- Admin validation summary -->
      <?php if ($disc_type): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:#495057;">Discrepancy:</span>
        <span style="color:<?= $flag_color ?>;font-size:12px;font-weight:700;text-transform:uppercase;">
          <?= htmlspecialchars($disc_type) ?>
        </span>
        <?php if ((float)($po['damaged_quantity'] ?? 0) > 0): ?>
        <span style="font-size:12px;color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> Damaged: <strong><?= number_format((float)$po['damaged_quantity'], 2) ?></strong></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($po['admin_notes'])): ?>
      <div style="font-size:12px;color:#6c757d;margin-bottom:10px;"><i class="fas fa-comment"></i> Admin note: <?= htmlspecialchars($po['admin_notes']) ?></div>
      <?php endif; ?>
      <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <strong>Manual Encode Required:</strong> Enter the <em>actual</em> quantity received. Admin validated quantity: <strong><?= number_format($actual_qty) ?> <?= htmlspecialchars($unit) ?></strong>. Capture shortages, damages, or excess accurately.
      </div>
      <!-- Batch ID display / override -->
      <div style="background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <label style="font-size:12px;font-weight:700;color:#002F70;white-space:nowrap;margin:0;"><i class="fas fa-tag"></i> Batch ID:</label>
        <input type="text" id="batch-<?= $delivery_id ?>" value="<?= htmlspecialchars($po['batch_id'] ?? '') ?>"
               placeholder="Enter Batch ID (e.g. BATCH-001)"
               style="font-family:monospace;font-size:0.9rem;padding:5px 10px;border:1px solid #bcd2ee;border-radius:5px;color:#002F70;font-weight:700;">
        <?php if (!empty($po['batch_id'])): ?>
        <span style="font-size:11px;color:#6c757d;"><i class="fas fa-info-circle"></i> Pre-filled from delivery. Edit if different from actual.</span>
        <?php else: ?>
        <span style="font-size:11px;color:#dc3545;"><i class="fas fa-exclamation-circle"></i> No Batch ID — please enter one below.</span>
        <?php endif; ?>
      </div>
      <div class="table-wrap" style="margin-bottom:12px;">
        <table class="si-table">
          <thead>
            <tr>
              <th>Product</th><th>SKU</th><th>Expected (<?= htmlspecialchars($unit) ?>)</th>
              <th>Actual Received (<?= htmlspecialchars($unit) ?>) *</th><th>Condition *</th>
              <th>Unit Cost</th><th>Variance (<?= htmlspecialchars($unit) ?>)</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong><?= htmlspecialchars($po['product'] ?? $po['product_name'] ?? '') ?></strong></td>
              <td><code style="font-size:11px;"><?= htmlspecialchars($po['sku'] ?? '') ?></code></td>
              <td style="text-align:center;font-weight:700;"><?= number_format($qty_ordered) ?></td>
              <td>
                <input type="number" id="qty-<?= $delivery_id ?>" min="0" value="<?= $actual_qty ?>"
                       oninput="updateVariance(<?= $delivery_id ?>, <?= $qty_ordered ?>, '<?= $unit ?>')">
              </td>
              <td>
                <select id="cond-<?= $delivery_id ?>" onchange="updateVariance(<?= $delivery_id ?>, <?= $qty_ordered ?>, '<?= $unit ?>')">
                  <option value="Good">Good</option>
                  <option value="Damaged">Damaged</option>
                  <option value="Short">Short</option>
                  <option value="Excess">Excess</option>
                </select>
              </td>
              <td>&#8369;<?= number_format($unit_cost, 2) ?></td>
              <td id="var-<?= $delivery_id ?>" class="variance-zero">0 <?= htmlspecialchars($unit) ?></td>
              <td><input type="text" id="rem-<?= $delivery_id ?>" placeholder="Optional notes..."></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button class="txn-btn success" id="btn-<?= $delivery_id ?>"
                onclick="submitStockIn(<?= $delivery_id ?>, <?= $product_id ?>, <?= $qty_ordered ?>, <?= $unit_cost ?>)">
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
<?php endif; ?>

<?php if ($active_tab === 'history'): ?>
<!-- ══ STOCK-IN HISTORY TAB (COMBINED FUEL + MERCHANDISE) ══ -->
<div class="si-card">
  <div class="si-card-head">
    <div class="si-card-title"><i class="fas fa-history"></i> Stock-In History (Fuel + Merchandise)</div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-left:auto;">
      <form method="get" action="staff_stock_in.php" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0;">
        <input type="hidden" name="tab" value="history">
        <input type="date" name="date_from" value="<?= htmlspecialchars($hist_date_from) ?>"
               style="padding:6px 10px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;height:36px;">
        <span style="font-size:12px;color:var(--gray);">to</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($hist_date_to) ?>"
               style="padding:6px 10px;border:1px solid #dee2e6;border-radius:5px;font-size:12px;height:36px;">
        <button type="submit" class="txn-btn primary" style="height:36px;"><i class="fas fa-filter"></i> Filter</button>
      </form>
      <?php
      $export_table_id       = 'stockInHistoryTable';
      $export_filename       = 'stock_in_history_' . date('Ymd');
      $export_title          = 'Stock-In History';
      $export_rows_select_id = 'stockInRowsLimit';
      $export_default_rows   = 25;
      require __DIR__ . '/../partials/export_buttons.php';
      ?>
    </div>
  </div>
  <div class="si-card-body">
    <?php if (empty($history_rows)): ?>
      <div class="empty-state">
        <i class="fas fa-history"></i>
        <strong>No stock-in records found.</strong><br>
        <span style="font-size:13px;">Try a different date range or encode your first stock-in.</span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="hist-table" id="stockInHistoryTable">
          <thead>
            <tr>
              <th>Type</th>
              <th>Batch Ref</th>
              <th>Date & Time</th>
              <th>Reference</th>
              <th>Product</th>
              <th>SKU / Category</th>
              <th>Expected</th>
              <th>Received</th>
              <th>Variance</th>
              <th>Condition</th>
              <th>Stock Before</th>
              <th>Stock After</th>
              <th>Encoded By</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history_rows as $r):
              $is_fuel = ($r['type'] === 'fuel');
              $v = $is_fuel ? (float)$r['qty_variance'] : (int)$r['qty_variance'];
              $vc = $v > 0 ? 'variance-pos' : ($v < 0 ? 'variance-neg' : 'variance-zero');
              $cf = strtolower($r['condition_flag'] ?? 'good');
              $type_badge = $is_fuel ? 'badge-pending' : 'badge-done';
              $type_icon = $is_fuel ? 'fas fa-gas-pump' : 'fas fa-boxes';
              $hist_unit = $is_fuel ? 'Liters (L)' : format_merch_unit($r['unit'] ?? 'Piece');
            ?>
            <tr>
              <td>
                <span class="badge <?= $type_badge ?>" style="font-size:10px;">
                  <i class="<?= $type_icon ?>"></i> <?= $is_fuel ? 'Fuel' : 'Merch' ?>
                </span>
              </td>
              <td><code style="font-size:11px;"><?= htmlspecialchars($r['batch_ref'] ?? '') ?></code></td>
              <td style="white-space:nowrap;font-size:12px;"><?= $r['encoded_at'] ? date('M d, Y H:i', strtotime($r['encoded_at'])) : '' ?></td>
              <td><?= htmlspecialchars($r['reference'] ?? '—') ?></td>
              <td><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
              <td>
                <?php if ($is_fuel): ?>
                  <span style="font-size:11px;color:#6c757d;">Fuel</span>
                <?php else: ?>
                  <?php if ($r['sku']): ?><code style="font-size:11px;"><?= htmlspecialchars($r['sku']) ?></code><br><?php endif; ?>
                  <span style="font-size:11px;color:#6c757d;"><?= htmlspecialchars($r['category'] ?? '') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($is_fuel): ?>
                  <?= number_format($r['qty_expected'], 2) ?> <?= $hist_unit ?>
                <?php else: ?>
                  <?= number_format($r['qty_expected']) ?> <?= $hist_unit ?>
                <?php endif; ?>
              </td>
              <td style="font-weight:700;">
                <?php if ($is_fuel): ?>
                  <?= number_format($r['qty_received'], 2) ?> <?= $hist_unit ?>
                <?php else: ?>
                  <?= number_format($r['qty_received']) ?> <?= $hist_unit ?>
                <?php endif; ?>
              </td>
              <td class="<?= $vc ?>">
                <?php if ($is_fuel): ?>
                  <?= ($v >= 0 ? '+' : '') . number_format($v, 2) ?> <?= $hist_unit ?>
                <?php else: ?>
                  <?= ($v >= 0 ? '+' : '') . number_format($v) ?> <?= $hist_unit ?>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $cf ?>"><?= htmlspecialchars($r['condition_flag'] ?? '') ?></span></td>
              <td style="font-weight:600;">
                <?php if ($is_fuel): ?>
                  <?= number_format($r['level_before'] ?? 0, 2) ?> <?= $hist_unit ?>
                <?php else: ?>
                  <?= number_format($r['stock_before'] ?? 0) ?> <?= $hist_unit ?>
                <?php endif; ?>
              </td>
              <td style="font-weight:700;color:#28a745;">
                <?php if ($is_fuel): ?>
                  <?= number_format($r['level_after'] ?? 0, 2) ?> <?= $hist_unit ?>
                <?php else: ?>
                  <?= number_format($r['stock_after'] ?? 0) ?> <?= $hist_unit ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($r['encoded_by_name'] ?? '') ?></td>
              <td style="font-size:11px;color:#6c757d;max-width:140px;"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="stockInHistoryPagination" style="margin-top:10px;"></div>
      <div style="margin-top:10px;font-size:12px;color:var(--gray);">
        Showing <?= count($history_rows) ?> record(s) from <?= htmlspecialchars($hist_date_from) ?> to <?= htmlspecialchars($hist_date_to) ?>.
        <a href="staff_stock_in.php?tab=history&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" style="margin-left:8px;">This month</a> |
        <a href="staff_stock_in.php?tab=history&date_from=<?= date('Y-m-d', strtotime('-30 days')) ?>&date_to=<?= date('Y-m-d') ?>" style="margin-left:8px;">Last 30 days</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div id="flashMsg" style="display:none;position:fixed;top:24px;right:24px;padding:13px 20px;border-radius:8px;color:#fff;font-weight:600;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:340px;"></div>

<script>
function updateVariance(poId, qtyOrdered, unit) {
    var qty = parseInt(document.getElementById('qty-' + poId).value) || 0;
    var v = qty - qtyOrdered;
    var el = document.getElementById('var-' + poId);
    var unitLabel = unit ? ' ' + unit : '';
    el.textContent = (v >= 0 ? '+' : '') + v + unitLabel;
    el.className = v > 0 ? 'variance-pos' : (v < 0 ? 'variance-neg' : 'variance-zero');
}

function submitStockIn(deliveryId, productId, qtyOrdered, unitCost) {
    var qtyReceived = parseInt(document.getElementById('qty-' + deliveryId).value) || 0;
    var condition   = document.getElementById('cond-' + deliveryId).value;
    var remarks     = document.getElementById('rem-' + deliveryId).value.trim();
    var batchId     = (document.getElementById('batch-' + deliveryId) || {}).value || '';

    if (qtyReceived < 0) { showToast('Quantity received cannot be negative.', 'err'); return; }
    if (!batchId.trim()) {
        if (!confirm('No Batch ID entered. Submit anyway?')) return;
    }

    var msg = 'Submit Stock-In?\n\nBatch ID: ' + (batchId || '(none)') + '\nActual Received: ' + qtyReceived + '\nCondition: ' + condition;
    if (condition === 'Damaged' || condition === 'Short') {
        msg += '\n\nNOTE: ' + condition + ' items will NOT be added to inventory.';
    }
    if (!confirm(msg)) return;

    var btn = document.getElementById('btn-' + deliveryId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    fetch('../backend/api/merchandise_stock_in.php?action=submit_stock_in', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            delivery_id: deliveryId,  // NEW: Pass delivery_id from deliveries_oversight
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
            var item = document.getElementById('po-item-' + deliveryId);
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

function submitFuelStockIn(deliveryId, qtyExpected) {
    var qtyReceived = parseFloat(document.getElementById('fuel-qty-' + deliveryId).value) || 0;
    var condition   = document.getElementById('fuel-cond-' + deliveryId).value;
    var remarks     = document.getElementById('fuel-rem-' + deliveryId).value.trim();
    var batchIdEl   = document.getElementById('fuel-batch-' + deliveryId);
    var batchId     = batchIdEl ? batchIdEl.value.trim().toUpperCase() : '';
    var unitCostEl  = document.getElementById('fuel-cost-' + deliveryId);
    var unitCost    = unitCostEl ? (parseFloat(unitCostEl.value) || 0) : 0;

    if (!batchId) { showToast('Batch ID is required before submitting.', 'err'); if (batchIdEl) batchIdEl.focus(); return; }
    if (qtyReceived < 0) { showToast('Quantity received cannot be negative.', 'err'); return; }

    var msg = 'Submit Fuel Stock-In?\n\nBatch ID: ' + batchId + '\nActual Received: ' + qtyReceived.toFixed(2) + ' L\nCondition: ' + condition;
    if (unitCost > 0) msg += '\nUnit Cost: ₱' + unitCost.toFixed(2) + '/L';
    if (condition === 'Damaged' || condition === 'Short') {
        msg += '\n\nNOTE: ' + condition + ' fuel losses will NOT be added to inventory tank levels.';
    }
    if (!confirm(msg)) return;

    var btn = document.getElementById('fuel-btn-' + deliveryId);
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    fetch('../backend/api/merchandise_stock_in.php?action=submit_fuel_stock_in', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            oversight_delivery_id: deliveryId,  // NEW: Pass oversight delivery_id
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
            var item = document.getElementById('fuel-item-' + deliveryId);
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
    if (window.showPetronFlash) {
        window.showPetronFlash(msg, type === 'ok' ? 'success' : 'error');
        return;
    }
    var el = document.getElementById('flashMsg');
    el.style.background = type === 'ok' ? '#28a745' : '#dc3545';
    el.textContent = msg;
    el.style.display = 'block';
    if (type === 'ok') setTimeout(function() { el.style.display = 'none'; }, 5000);
}
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('stockInHistoryTable')) {
        setupTablePagination('stockInHistoryTable', 'stockInRowsLimit', 'stockInHistoryPagination', 25);
    }
});
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>
