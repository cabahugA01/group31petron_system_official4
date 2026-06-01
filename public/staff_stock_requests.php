<?php
$page_id = 'staff_stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

if ($role !== 'staff') {
    header("Location: dashboard.php");
    exit;
}

// ── Ensure PO columns exist ──────────────────────────────────────────────────
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_validated_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS delivery_flag ENUM('OK','Short','Damaged','Excess','Mixed') NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

// ── Ensure fuel_stock_requests table exists ──────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_stock_requests (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            staff_id         INT NOT NULL,
            station_id       INT NOT NULL,
            fuel_type        VARCHAR(100) NOT NULL,
            current_level    DECIMAL(12,2) NOT NULL DEFAULT 0,
            capacity         DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_status     VARCHAR(30)   NOT NULL DEFAULT 'LOW',
            requested_liters DECIMAL(12,2) NOT NULL,
            approved_liters  DECIMAL(12,2) NULL,
            remarks          TEXT,
            status           ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
            manager_id       INT NULL,
            manager_notes    TEXT NULL,
            processed_at     TIMESTAMP NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $ignored) {}

// ── Fetch merchandise stock requests ────────────────────────────────────────
$merch_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            sr.id,
            sr.item_sku,
            sr.item_name,
            sr.item_category,
            sr.current_stock,
            sr.requested_quantity,
            sr.approved_quantity,
            sr.remarks,
            sr.status,
            sr.manager_notes,
            sr.created_at,
            m.name        AS manager_name,
            po.po_number,
            po.admin_finalized,
            po.admin_finalized_at,
            po.delivery_validated,
            po.delivery_validated_at,
            po.delivery_flag,
            po.stock_in_done,
            po.stock_in_at
        FROM stock_requests sr
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'
        WHERE sr.staff_id = ?
          AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([(int)$me['id']]);
    $merch_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $merch_requests = [];
}

// ── Fetch fuel stock requests ────────────────────────────────────────────────
$fuel_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            fsr.id,
            fsr.fuel_type,
            fsr.current_level,
            fsr.capacity,
            fsr.stock_status,
            fsr.requested_liters,
            fsr.approved_liters,
            fsr.remarks,
            fsr.status,
            fsr.manager_notes,
            fsr.created_at,
            m.name AS manager_name
        FROM fuel_stock_requests fsr
        LEFT JOIN users m ON fsr.manager_id = m.id
        WHERE fsr.staff_id = ?
        ORDER BY fsr.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([(int)$me['id']]);
    $fuel_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuel_requests = [];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.ssr-wrap { max-width:1300px; margin:0 auto; padding:16px 24px 24px 24px; }

/* Header */
.ssr-header { padding:0 0 18px 0; }
.ssr-header h1 { font-size:24px; font-weight:700; margin:0 0 4px 0; color:#002F70; }
.ssr-header p  { font-size:13px; color:#6c757d; margin:0; }

/* Tabs */
.ssr-tabs { display:flex; border-bottom:2px solid #e9ecef; margin-bottom:20px; }
.ssr-tab  { padding:10px 22px; font-size:13px; font-weight:600; color:#6c757d; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color .15s,border-color .15s; display:flex; align-items:center; gap:7px; user-select:none; }
.ssr-tab:hover  { color:#002F70; }
.ssr-tab.active { color:#002F70; border-bottom-color:#002F70; }
.ssr-tab .tab-count { background:#e9ecef; color:#495057; font-size:11px; font-weight:700; padding:1px 7px; border-radius:10px; }
.ssr-tab.active .tab-count { background:#002F70; color:#fff; }

/* Tab panels */
.ssr-tab-panel { display:none; }
.ssr-tab-panel.active { display:block; }

/* Card */
.ssr-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.ssr-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.ssr-card-title { font-size:.95rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.ssr-card-body { padding:20px; }

/* Badges — aligned with system status colors */
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending            { background:#f8d7da; color:#721c24; }
.sbadge-approved           { background:#d4edda; color:#155724; }
.sbadge-rejected           { background:#f8d7da; color:#721c24; }
.sbadge-forwarded-to-admin { background:#cce5ff; color:#004085; border:1px solid #b8daff; }
</style>

<div class="ssr-wrap">

  <!-- Header -->
  <div class="ssr-header">
    <h1><i class="fas fa-boxes" style="margin-right:9px;"></i>Stock Request History</h1>
    <p>History of stock replenishment requests for fuel and merchandise products</p>
  </div>

  <!-- Tabs -->
  <div class="ssr-tabs">
    <div class="ssr-tab active" onclick="switchTab('fuel')" id="tab-fuel">
      <i class="fas fa-gas-pump"></i> Fuel
      <span class="tab-count"><?php echo count($fuel_requests); ?></span>
    </div>
    <div class="ssr-tab" onclick="switchTab('merch')" id="tab-merch">
      <i class="fas fa-shopping-basket"></i> Merchandise
      <span class="tab-count"><?php echo count($merch_requests); ?></span>
    </div>
  </div>

  <!-- ── FUEL TAB ── -->
  <div class="ssr-tab-panel active" id="panel-fuel">
    <div class="ssr-card">
      <div class="ssr-card-head">
        <div class="ssr-card-title"><i class="fas fa-gas-pump"></i> Fuel Stock Requests</div>
        <button onclick="location.reload()" class="btn ghost" style="font-size:12px;"><i class="fas fa-sync-alt"></i> Refresh</button>
      </div>
      <div class="ssr-card-body">
        <?php if (empty($fuel_requests)): ?>
          <div style="text-align:center;padding:48px;color:#6c757d;">
            <i class="fas fa-gas-pump" style="font-size:3em;display:block;margin-bottom:12px;opacity:.2;"></i>
            <strong>No fuel stock requests yet.</strong>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Fuel Type</th>
                <th>Current Level (L)</th>
                <th>Requested (L)</th>
                <th>Approved (L)</th>
                <th>Stock Status</th>
                <th>Remarks</th>
                <th>Status</th>
                <th>Manager</th>
                <th>Manager Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fuel_requests as $r):
                $st  = $r['status'] ?? 'Pending';
                $cls = 'sbadge sbadge-' . strtolower($st);
              ?>
              <tr>
                <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$r['id']; ?></td>
                <td style="font-size:12px;white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                <td><strong><?php echo htmlspecialchars($r['fuel_type']); ?></strong></td>
                <td style="text-align:center;"><?php echo number_format((float)$r['current_level'], 2); ?></td>
                <td style="text-align:center;font-weight:700;color:#002F70;"><?php echo number_format((float)$r['requested_liters'], 2); ?></td>
                <td style="text-align:center;">
                  <?php if ($r['approved_liters'] !== null): ?>
                    <strong style="color:#155724;"><?php echo number_format((float)$r['approved_liters'], 2); ?></strong>
                  <?php else: ?>
                    <span style="color:#adb5bd;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $ss = strtolower($r['stock_status'] ?? '');
                    $ss_style = $ss === 'critical' ? 'background:#f8d7da;color:#721c24;' : 'background:#fff3cd;color:#856404;';
                  ?>
                  <span class="sbadge" style="<?php echo $ss_style; ?>"><?php echo htmlspecialchars(strtoupper($r['stock_status'] ?? 'LOW')); ?></span>
                </td>
                <td style="font-size:12px;color:#6c757d;max-width:140px;"><?php echo $r['remarks'] ? htmlspecialchars($r['remarks']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
                <td><span class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                <td style="font-size:12px;"><?php echo $r['manager_name'] ? htmlspecialchars($r['manager_name']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
                <td style="font-size:12px;color:#495057;max-width:160px;"><?php echo $r['manager_notes'] ? htmlspecialchars($r['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── MERCHANDISE TAB ── -->
  <div class="ssr-tab-panel" id="panel-merch">
    <div class="ssr-card">
      <div class="ssr-card-head">
        <div class="ssr-card-title"><i class="fas fa-shopping-basket"></i> Merchandise Stock Requests</div>
        <button onclick="location.reload()" class="btn ghost" style="font-size:12px;"><i class="fas fa-sync-alt"></i> Refresh</button>
      </div>
      <div class="ssr-card-body">
        <?php if (empty($merch_requests)): ?>
          <div style="text-align:center;padding:48px;color:#6c757d;">
            <i class="fas fa-inbox" style="font-size:3em;display:block;margin-bottom:12px;opacity:.2;"></i>
            <strong>No merchandise stock requests yet.</strong>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>SKU</th>
                <th>Product</th>
                <th>Category</th>
                <th>Remarks</th>
                <th>Request Status</th>
                <th>PO Number</th>
                <th>PO / Admin</th>
                <th>Stock-In</th>
                <th>Manager Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($merch_requests as $r):
                $st     = $r['status'] ?? 'Pending';
                $st_key = strtolower(str_replace([' ', '/'], '-', $st));
                $cls    = 'sbadge sbadge-' . $st_key;

                // Pipeline badge
                $pipe_badge = '';
                if (!empty($r['stock_in_done'])) {
                    $pipe_badge = '<span class="sbadge" style="background:#d4edda;color:#155724;"><i class="fas fa-check-double"></i> Stocked In</span>';
                    if (!empty($r['stock_in_at'])) $pipe_badge .= '<br><small style="color:#6c757d;">'.date('M d, Y', strtotime($r['stock_in_at'])).'</small>';
                } elseif (!empty($r['admin_finalized']) && !empty($r['delivery_validated'])) {
                    $pipe_badge = '<span class="sbadge" style="background:#cce5ff;color:#004085;border:1px solid #b8daff;"><i class="fas fa-dolly"></i> Awaiting Stock-In</span>';
                    if (!empty($r['admin_finalized_at'])) $pipe_badge .= '<br><small style="color:#6c757d;">Since '.date('M d', strtotime($r['admin_finalized_at'])).'</small>';
                } elseif (!empty($r['admin_finalized'])) {
                    $pipe_badge = '<span class="sbadge" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba;"><i class="fas fa-clipboard-check"></i> Awaiting Delivery Validation</span>';
                } elseif (!empty($r['po_number'])) {
                    $pipe_badge = '<span class="sbadge" style="background:#cce5ff;color:#004085;border:1px solid #b8daff;"><i class="fas fa-file-invoice"></i> PO Pending Admin</span>';
                } elseif ($st === 'Forwarded to Admin') {
                    $pipe_badge = '<span class="sbadge" style="background:#cce5ff;color:#004085;border:1px solid #b8daff;"><i class="fas fa-arrow-right"></i> Forwarded</span>';
                } elseif ($st === 'Rejected') {
                    $pipe_badge = '<span class="sbadge sbadge-rejected"><i class="fas fa-times"></i> Rejected</span>';
                } else {
                    $pipe_badge = '<span class="sbadge sbadge-pending"><i class="fas fa-clock"></i> Awaiting Manager</span>';
                }

                // Stock-In cell
                if (!empty($r['stock_in_done'])) {
                    $si_cell = '<span class="sbadge" style="background:#d4edda;color:#155724;"><i class="fas fa-check"></i> Done</span>';
                } elseif (!empty($r['admin_finalized']) && !empty($r['delivery_validated'])) {
                    $si_cell = '<a href="staff_stock_in.php" class="sbadge" style="background:#cce5ff;color:#004085;border:1px solid #b8daff;text-decoration:none;"><i class="fas fa-arrow-right"></i> Go to Stock-In</a>';
                } else {
                    $si_cell = '<span style="color:#adb5bd;font-size:12px;">—</span>';
                }
              ?>
              <tr>
                <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$r['id']; ?></td>
                <td style="font-size:12px;white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                <td><code style="font-size:11px;"><?php echo htmlspecialchars($r['item_sku'] ?? ''); ?></code></td>
                <td><strong><?php echo htmlspecialchars($r['item_name'] ?? ''); ?></strong></td>
                <td style="font-size:12px;"><?php echo htmlspecialchars($r['item_category'] ?? ''); ?></td>
                <td style="font-size:12px;color:#6c757d;max-width:140px;"><?php echo $r['remarks'] ? htmlspecialchars($r['remarks']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
                <td><span class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                <td style="font-size:12px;">
                  <?php if (!empty($r['po_number'])): ?>
                    <code style="font-size:11px;color:#002F70;"><?php echo htmlspecialchars($r['po_number']); ?></code>
                  <?php else: ?>
                    <span style="color:#adb5bd;">—</span>
                  <?php endif; ?>
                </td>
                <td><?php echo $pipe_badge; ?></td>
                <td><?php echo $si_cell; ?></td>
                <td style="font-size:12px;color:#495057;max-width:160px;"><?php echo $r['manager_notes'] ? htmlspecialchars($r['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.ssr-tab').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.ssr-tab-panel').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
    // Persist active tab in URL hash
    history.replaceState(null, '', '#tab-' + tab);
}

// Restore tab from URL hash on load
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash === '#tab-merch') {
        switchTab('merch');
    } else {
        // Default: fuel tab
        switchTab('fuel');
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
