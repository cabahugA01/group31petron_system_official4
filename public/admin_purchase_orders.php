<?php
$page_id = 'admin_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// Ensure admin finalization and batch columns exist
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS po_number VARCHAR(100) NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS po_number VARCHAR(100) NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

// Ensure 'Admin Finalized' and 'Rejected by Admin' exist in the ENUM
try {
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status
        ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received',
             'Cancelled','Pending Admin Validation','Official','Approved PO',
             'Admin Finalized','Rejected by Admin')
        DEFAULT 'Pending Admin Validation'");
} catch (Exception $e) {}
// Backfill any empty-status rows that are already admin-finalized
try {
    $pdo->exec("UPDATE purchase_orders SET status='Admin Finalized' WHERE admin_finalized=1 AND (status='' OR status IS NULL)");
} catch (Exception $e) {}

$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $po_type = $_POST['po_type'] ?? '';
    $po_date = $_POST['po_date'] ?? '';

    if ($action === 'finalize_batch') {
        $batch_id    = trim($_POST['batch_id_override'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $items_input = $_POST['items'] ?? []; // Array of [id => [qty, price]]

        if (empty($batch_id)) {
            $_SESSION['err'] = 'Batch ID / PO Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Get or create Petron supplier ID
            $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
            if (!$sup_id) {
                $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                $sup_id = $pdo->lastInsertId();
            }

            foreach ($items_input as $item_id => $data) {
                $qty = (float)($data['qty'] ?? 0);
                $price = (float)($data['price'] ?? 0);
                $total = round($qty * $price, 2);

                if ($qty <= 0) {
                    throw new Exception("Quantity must be greater than zero for all items.");
                }

                if ($po_type === 'merch') {
                    // Fetch PO record to check
                    $stmt_fetch = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=? AND status='Pending Admin Validation' AND admin_finalized=0");
                    $stmt_fetch->execute([$item_id, $station_id]);
                    $po_rec = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
                    if (!$po_rec) {
                        throw new Exception("Pending Merchandise request item #{$item_id} not found.");
                    }

                    // Update purchase_orders
                    $pdo->prepare("
                        UPDATE purchase_orders
                        SET quantity = ?,
                            unit_price = ?,
                            total_amount = ?,
                            admin_finalized = 1,
                            admin_id = ?,
                            approved_by = ?,
                            admin_notes = ?,
                            admin_finalized_at = NOW(),
                            batch_id = ?,
                            po_number = ?,
                            supplier_id = ?,
                            supplier_name = 'Petron Corporation',
                            status = 'Admin Finalized',
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty, $price, $total, $me['id'], $me['id'], $admin_notes, $batch_id, $batch_id, $sup_id, $item_id]);

                    // Sync to deliveries_oversight
                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, created_at, updated_at
                        ) VALUES (
                            'merchandise', ?, ?, 'Petron Corporation', ?, ?, 'pcs',
                            CURDATE(), ?, 'Expected Delivery', ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        'MDR-' . date('Ymd') . '-' . rand(1000, 9999),
                        $batch_id,
                        $po_rec['product_name'],
                        $qty,
                        $po_rec['station_id'],
                        $batch_id,
                        $admin_notes
                    ]);
                } else if ($po_type === 'fuel') {
                    // Fetch fuel PO record
                    $stmt_fetch = $pdo->prepare("
                        SELECT fpo.*, ft.name AS fuel_name
                        FROM fuel_purchase_orders fpo
                        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                        WHERE fpo.id=? AND fpo.station_id=? AND fpo.status IN ('Pending Admin Validation', 'Pending')
                    ");
                    $stmt_fetch->execute([$item_id, $station_id]);
                    $po_rec = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
                    if (!$po_rec) {
                        throw new Exception("Pending Fuel request item #{$item_id} not found.");
                    }

                    // Update fuel_purchase_orders
                    $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET volume = ?,
                            unit_price = ?,
                            total_amount = ?,
                            status = 'Approved PO',
                            approved_by = ?,
                            approved_at = NOW(),
                            batch_id = ?,
                            po_number = ?,
                            supplier_id = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty, $price, $total, $me['id'], $batch_id, $batch_id, $sup_id, $admin_notes, $item_id]);

                    // Sync to deliveries_oversight
                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, created_at, updated_at
                        ) VALUES (
                            'fuel', ?, ?, 'Petron Corporation', ?, ?, 'L',
                            CURDATE(), ?, 'Expected Delivery', ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        'FDR-' . date('Ymd') . '-' . rand(1000, 9999),
                        $batch_id,
                        $po_rec['fuel_name'],
                        $qty,
                        $po_rec['station_id'],
                        $batch_id,
                        $admin_notes
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Admin Finalize PO Batch', "Grouped PO for date {$po_date} finalized as batch {$batch_id} with " . count($items_input) . " item(s).");
            $pdo->commit();
            $_SESSION['ok'] = "Batch {$batch_id} finalized and forwarded to Expected Deliveries.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }

    if ($action === 'reject_batch') {
        $reason = trim($_POST['reject_reason'] ?? '');
        if (empty($reason)) {
            $_SESSION['err'] = 'Rejection reason is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($po_type === 'merch') {
                // Fetch items for this date
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM purchase_orders
                    WHERE station_id = ? AND type = 'merch' AND DATE(created_at) = ? AND status = 'Pending Admin Validation' AND admin_finalized = 0
                ");
                $stmt->execute([$station_id, $po_date]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $pdo->prepare("
                        UPDATE purchase_orders
                        SET status = 'Rejected by Admin',
                            admin_notes = ?,
                            admin_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$reason, $me['id'], $item['id']]);
                }
            } else if ($po_type === 'fuel') {
                // Fetch items for this date
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM fuel_purchase_orders
                    WHERE station_id = ? AND DATE(created_at) = ? AND status IN ('Pending Admin Validation', 'Pending')
                ");
                $stmt->execute([$station_id, $po_date]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET status = 'Rejected',
                            notes = ?,
                            approved_by = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$reason, $me['id'], $item['id']]);
                }
            }

            log_activity($pdo, $me['id'], 'Admin Reject PO Batch', "Grouped PO for date {$po_date} rejected. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['ok'] = "Batch requests for date {$po_date} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }
}

// ── FETCH PENDING DATA ────────────────────────────────────────────────────────
$pending_merch_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, u_mgr.name AS manager_name,
               sr.item_sku, sr.item_category, sr.remarks AS sr_remarks, sr.current_stock
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch'
          AND po.status = 'Pending Admin Validation' AND po.admin_finalized = 0
        ORDER BY po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_merch_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group in PHP
$grouped_pending_merch = [];
foreach ($pending_merch_items as $item) {
    $date = date('Y-m-d', strtotime($item['created_at']));
    $grouped_pending_merch[$date][] = $item;
}

$pending_fuel_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name AS fuel_name, u_mgr.name AS manager_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_mgr ON fpo.created_by = u_mgr.id
        WHERE fpo.station_id = ? AND fpo.status IN ('Pending Admin Validation', 'Pending')
        ORDER BY fpo.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_fuel_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group in PHP
$grouped_pending_fuel = [];
foreach ($pending_fuel_items as $item) {
    $date = date('Y-m-d', strtotime($item['created_at']));
    $grouped_pending_fuel[$date][] = $item;
}

// Total pending batches count
$total_pending_count = count($grouped_pending_merch) + count($grouped_pending_fuel);

// ── FETCH HISTORY DATA ────────────────────────────────────────────────────────
$finalized_merch_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, u_mgr.name AS manager_name, u_adm.name AS admin_name,
               sr.item_sku
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id = u_adm.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.admin_finalized = 1
        ORDER BY po.admin_finalized_at DESC
    ");
    $stmt->execute([$station_id]);
    $finalized_merch_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group by DATE(created_at) — one row per date, one Print PO button per date
$grouped_finalized_merch = [];
foreach ($finalized_merch_items as $item) {
    $date_key = date('Y-m-d', strtotime($item['created_at']));
    $grouped_finalized_merch[$date_key][] = $item;
}

$finalized_fuel_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name AS fuel_name, u_mgr.name AS manager_name, u_adm.name AS admin_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_mgr ON fpo.created_by = u_mgr.id
        LEFT JOIN users u_adm ON fpo.approved_by = u_adm.id
        WHERE fpo.station_id = ? AND fpo.status NOT IN ('Pending Admin Validation', 'Pending', 'Draft')
        ORDER BY fpo.approved_at DESC
    ");
    $stmt->execute([$station_id]);
    $finalized_fuel_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group by DATE(created_at) — one row per date, one Print PO button per date
$grouped_finalized_fuel = [];
foreach ($finalized_fuel_items as $item) {
    $date_key = date('Y-m-d', strtotime($item['created_at']));
    $grouped_finalized_fuel[$date_key][] = $item;
}

// Fetch deliveries_oversight status index for fuel complete check
$fuel_oversight_status = [];
try {
    $dos = $pdo->query("SELECT source_ref, status FROM deliveries_oversight WHERE delivery_type='fuel'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dos as $do) {
        $fuel_oversight_status[strtolower(trim($do['source_ref']))] = $do['status'];
    }
} catch(Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--gray:#6c757d;}

/* Alerts Repositioned to Viewport Top */
.flash-ok {
    position: fixed !important;
    top: 24px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
    background: #d4edda !important;
    color: #155724 !important;
    border: 1px solid #c3e6cb !important;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15) !important;
    min-width: 320px !important;
    max-width: 90% !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 12px 20px !important;
    border-radius: 8px !important;
    animation: slideDownAlert 0.3s ease-out !important;
}
.flash-err {
    position: fixed !important;
    top: 24px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
    background: #f8d7da !important;
    color: #721c24 !important;
    border: 1px solid #f5c6cb !important;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15) !important;
    min-width: 320px !important;
    max-width: 90% !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 12px 20px !important;
    border-radius: 8px !important;
    animation: slideDownAlert 0.3s ease-out !important;
}
@keyframes slideDownAlert {
    from { top: -60px; opacity: 0; }
    to { top: 24px; opacity: 1; }
}

.po-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:18px;overflow:hidden;}
.po-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.po-card-title{font-size:1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.po-card-body{padding:18px 20px;}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-finalized{background:#d4edda;color:#155724;}
.badge-rejected{background:#f8d7da;color:#721c24;}
.badge-stockin{background:#cce5ff;color:#004085;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none;}
.btn-finalize{background:var(--blue);color:#fff;}.btn-finalize:hover{background:#001F4F;}
.btn-reject{background:var(--red);color:#fff;}.btn-reject:hover{background:#c82333;}
.btn-print{background:#6c757d;color:#fff;}.btn-print:hover{background:#545b62;}
.btn-sm{padding:5px 12px;font-size:12px;}

.btn-sub {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
}
.btn-sub.active {
    background: var(--blue);
    color: #fff;
    border-color: var(--blue);
}
.btn-sub:hover {
    background: #e2e8f0;
}
.btn-sub.active:hover {
    background: #001F4F;
}

.empty-state{text-align:center;padding:48px;color:var(--gray);}
.empty-state i{font-size:3rem;display:block;margin-bottom:12px;opacity:.3;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:500px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h3{margin:0 0 16px;font-size:1.05rem;color:var(--blue);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-group textarea,.form-group input{width:100%;padding:9px 11px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;}
.form-group textarea:focus,.form-group input:focus{outline:none;border-color:var(--blue);}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;}
.info-box{background:#e8f4fd;border-left:4px solid var(--blue);border-radius:6px;padding:10px 14px;font-size:12px;color:var(--blue);line-height:1.6;margin-bottom:14px;}

.tab-nav{display:flex;gap:0;border-bottom:2px solid #e9ecef;margin-bottom:22px;}
.tab-btn{padding:10px 24px;background:none;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}

/* Standardized Responsive Table CSS */
.table-wrap {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}
.standard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}
.standard-table th {
    background: #002F70;
    color: #fff;
    font-weight: 600;
    padding: 12px 16px;
    border: none;
}
.standard-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
}
.standard-table tbody tr:hover {
    background-color: #f8fafc;
}
.standard-table tbody tr:last-child td {
    border-bottom: none;
}
.form-input {
    border: 1px solid #bcd2ee;
    border-radius: 5px;
    padding: 6px 10px;
    color: var(--blue);
    font-weight: bold;
}
.form-input:focus {
    outline: none;
    border-color: var(--blue);
}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-file-invoice"></i> Purchase Orders Oversight</h1>
    <div class="sub">Review Manager-approved PO requests grouped by date, finalize as official POs, and print documents.</div>
  </div>
  <div class="header-actions">
    <button onclick="location.reload()" class="btn btn-sub"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_ok) ?></div>
<?php endif; ?>
<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>

<!-- Main Navigation Tabs -->
<div class="tab-nav">
  <button class="tab-btn active" id="tabPendingBtn" onclick="switchMainTab('pending', this)">
    <i class="fas fa-clock"></i> Pending Finalization
    <?php if ($total_pending_count > 0): ?>
      <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;margin-left:4px;"><?= $total_pending_count ?></span>
    <?php endif; ?>
  </button>
  <button class="tab-btn" id="tabHistoryBtn" onclick="switchMainTab('history', this)">
    <i class="fas fa-history"></i> Finalized History
  </button>
</div>

<!-- ==================== PENDING CONTAINER ==================== -->
<div id="main-tab-pending" class="main-tab-content">
  <!-- Sub Tabs pending -->
  <div style="display: flex; gap: 8px; margin-bottom: 16px;">
    <button class="btn btn-sm btn-sub active" id="pendingSubMerchBtn" onclick="switchSubTab('pending', 'merch', this)">
      <i class="fas fa-box"></i> Merchandise
      <?php if (count($grouped_pending_merch) > 0): ?>
        <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($grouped_pending_merch) ?></span>
      <?php endif; ?>
    </button>
    <button class="btn btn-sm btn-sub" id="pendingSubFuelBtn" onclick="switchSubTab('pending', 'fuel', this)">
      <i class="fas fa-gas-pump"></i> Fuel
      <?php if (count($grouped_pending_fuel) > 0): ?>
        <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($grouped_pending_fuel) ?></span>
      <?php endif; ?>
    </button>
  </div>

  <!-- PENDING MERCHANDISE -->
  <div id="pending-merch-view" class="sub-tab-content">
    <div class="po-card">
      <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-boxes"></i> Merchandise POs Pending Finalization</div>
      </div>
      <div class="po-card-body">
        <?php if (empty($grouped_pending_merch)): ?>
          <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <strong>No pending merchandise POs.</strong>
            <p style="margin-top:5px;font-size:13px;color:var(--gray);">All merchandise purchase requests are finalized.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="standard-table">
              <thead>
                <tr>
                  <th>Request Date</th>
                  <th>Station</th>
                  <th style="text-align:center;">Total Items</th>
                  <th>Requested By</th>
                  <th>Status</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grouped_pending_merch as $date => $items): 
                    $mgr = $items[0]['manager_name'] ?? '—';
                    $items_json = json_encode($items);
                ?>
                  <tr>
                    <td><strong><?= date('F d, Y', strtotime($date)) ?></strong></td>
                    <td>Petron Station</td>
                    <td style="text-align:center;font-weight:bold;"><?= count($items) ?></td>
                    <td><?= htmlspecialchars($mgr) ?></td>
                    <td><span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending Finalization</span></td>
                    <td style="text-align:right;white-space:nowrap;gap:6px;">
                      <button class="btn btn-finalize btn-sm" onclick='openFinalizeMerch(<?= json_encode($date) ?>, <?= htmlspecialchars($items_json, ENT_QUOTES, 'UTF-8') ?>)'>
                        <i class="fas fa-check-circle"></i> Review &amp; Finalize
                      </button>
                      <button class="btn btn-reject btn-sm" onclick="openReject('merch', <?= json_encode($date) ?>)">
                        <i class="fas fa-times-circle"></i> Reject
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PENDING FUEL -->
  <div id="pending-fuel-view" class="sub-tab-content" style="display:none;">
    <div class="po-card">
      <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-gas-pump"></i> Fuel POs Pending Finalization</div>
      </div>
      <div class="po-card-body">
        <?php if (empty($grouped_pending_fuel)): ?>
          <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <strong>No pending fuel POs.</strong>
            <p style="margin-top:5px;font-size:13px;color:var(--gray);">All fuel purchase requests are finalized.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="standard-table">
              <thead>
                <tr>
                  <th>Request Date</th>
                  <th>Station</th>
                  <th>Fuel Products Requested</th>
                  <th style="text-align:right;">Total Volume</th>
                  <th>Requested By</th>
                  <th>Status</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grouped_pending_fuel as $date => $items): 
                    $mgr = $items[0]['manager_name'] ?? '—';
                    $fuels = [];
                    $tot_vol = 0;
                    foreach ($items as $it) {
                        $fuels[] = $it['fuel_name'];
                        $tot_vol += $it['volume'];
                    }
                    $items_json = json_encode($items);
                ?>
                  <tr>
                    <td><strong><?= date('F d, Y', strtotime($date)) ?></strong></td>
                    <td>Petron Station</td>
                    <td><?= htmlspecialchars(implode(', ', array_unique($fuels))) ?></td>
                    <td style="text-align:right;font-weight:bold;"><?= number_format($tot_vol, 2) ?> L</td>
                    <td><?= htmlspecialchars($mgr) ?></td>
                    <td><span class="badge badge-pending"><i class="fas fa-hourglass-half"></i> Pending Finalization</span></td>
                    <td style="text-align:right;white-space:nowrap;gap:6px;">
                      <button class="btn btn-finalize btn-sm" onclick='openFinalizeFuel(<?= json_encode($date) ?>, <?= htmlspecialchars($items_json, ENT_QUOTES, 'UTF-8') ?>)'>
                        <i class="fas fa-check-circle"></i> Review &amp; Finalize
                      </button>
                      <button class="btn btn-reject btn-sm" onclick="openReject('fuel', <?= json_encode($date) ?>)">
                        <i class="fas fa-times-circle"></i> Reject
                      </button>
                    </td>
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

<!-- ==================== HISTORY CONTAINER ==================== -->
<div id="main-tab-history" class="main-tab-content" style="display:none;">
  <!-- Sub Tabs history -->
  <div style="display: flex; gap: 8px; margin-bottom: 16px;">
    <button class="btn btn-sm btn-sub active" id="historySubMerchBtn" onclick="switchSubTab('history', 'merch', this)">
      <i class="fas fa-box"></i> Merchandise
    </button>
    <button class="btn btn-sm btn-sub" id="historySubFuelBtn" onclick="switchSubTab('history', 'fuel', this)">
      <i class="fas fa-gas-pump"></i> Fuel
    </button>
  </div>

  <!-- HISTORY MERCHANDISE -->
  <div id="history-merch-view" class="sub-tab-content">
    <div class="po-card">
      <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-history"></i> Finalized Merchandise PO History</div>
      </div>
      <div class="po-card-body">
        <?php if (empty($grouped_finalized_merch)): ?>
          <div class="empty-state">
            <i class="fas fa-history"></i>
            <strong>No finalized merchandise PO records found.</strong>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="standard-table">
              <thead>
                <tr>
                  <th>Request Date</th>
                  <th>PO Number</th>
                  <th>Station</th>
                  <th style="text-align:center;">Total Items</th>
                  <th style="text-align:right;">Total Cost</th>
                  <th>Finalized By</th>
                  <th>Stock-In Status</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grouped_finalized_merch as $date_key => $items): 
                    $req_date = date('F d, Y', strtotime($date_key));
                    $admin = $items[0]['admin_name'] ?? '—';
                    // Derive display PO number: prefer batch_id, then po_number, then default
                    $display_po = '';
                    foreach ($items as $it) {
                        if (!empty($it['batch_id'])) { $display_po = $it['batch_id']; break; }
                        if (!empty($it['po_number'])) { $display_po = $it['po_number']; break; }
                    }
                    if (!$display_po) $display_po = 'POM-' . date('Ymd', strtotime($date_key));
                    $tot_cost = 0;
                    $all_done = true;
                    $any_done = false;
                    foreach ($items as $it) {
                        $tot_cost += $it['total_amount'];
                        if ($it['stock_in_done'] == 1) {
                            $any_done = true;
                        } else {
                            $all_done = false;
                        }
                    }
                    
                    if ($all_done) {
                        $st_badge = '<span class="badge badge-finalized"><i class="fas fa-check"></i> Stocked In</span>';
                    } elseif ($any_done) {
                        $st_badge = '<span class="badge badge-stockin"><i class="fas fa-dolly-flatbed"></i> Partial Stock-In</span>';
                    } else {
                        $st_badge = '<span class="badge badge-pending"><i class="fas fa-truck"></i> Expected Delivery</span>';
                    }
                ?>
                  <tr>
                    <td><?= $req_date ?></td>
                    <td><code style="font-size:12px;font-weight:700;"><?= htmlspecialchars($display_po) ?></code></td>
                    <td>Petron Station</td>
                    <td style="text-align:center;font-weight:bold;"><?= count($items) ?></td>
                    <td style="text-align:right;font-weight:bold;">&#8369;<?= number_format($tot_cost, 2) ?></td>
                    <td><?= htmlspecialchars($admin) ?></td>
                    <td><?= $st_badge ?></td>
                    <td style="text-align:right;">
                      <a href="print_po_new.php?date=<?= urlencode($date_key) ?>&type=merch" target="_blank" class="btn btn-print btn-sm">
                        <i class="fas fa-print"></i> Print PO
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- HISTORY FUEL -->
  <div id="history-fuel-view" class="sub-tab-content" style="display:none;">
    <div class="po-card">
      <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-history"></i> Approved Fuel PO History</div>
      </div>
      <div class="po-card-body">
        <?php if (empty($grouped_finalized_fuel)): ?>
          <div class="empty-state">
            <i class="fas fa-history"></i>
            <strong>No approved fuel PO records found.</strong>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="standard-table">
              <thead>
                <tr>
                  <th>Request Date</th>
                  <th>PO Number</th>
                  <th>Station</th>
                  <th style="text-align:right;">Total Volume</th>
                  <th style="text-align:right;">Total Cost</th>
                  <th>Approved By</th>
                  <th>Stock-In Status</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($grouped_finalized_fuel as $date_key => $items): 
                    $req_date = date('F d, Y', strtotime($date_key));
                    $admin = $items[0]['admin_name'] ?? '—';
                    // Derive display PO number from item data
                    $display_po_fuel = '';
                    foreach ($items as $it) {
                        if (!empty($it['batch_id'])) { $display_po_fuel = $it['batch_id']; break; }
                        if (!empty($it['po_number'])) { $display_po_fuel = $it['po_number']; break; }
                    }
                    if (!$display_po_fuel) $display_po_fuel = 'POF-' . date('Ymd', strtotime($date_key));
                    $tot_vol = 0;
                    $tot_cost = 0;
                    foreach ($items as $it) {
                        $tot_vol += $it['volume'];
                        $tot_cost += $it['total_amount'];
                    }

                    // Check deliveries_oversight status using actual po_number from items
                    $po_key = strtolower(trim($display_po_fuel));
                    $oversight_st = $fuel_oversight_status[$po_key] ?? 'Expected Delivery';
                    
                    if (strtolower($oversight_st) === 'stock-in complete') {
                        $st_badge = '<span class="badge badge-finalized"><i class="fas fa-check"></i> Stocked In</span>';
                    } elseif (strtolower($oversight_st) === 'awaiting stock-in') {
                        $st_badge = '<span class="badge badge-stockin"><i class="fas fa-dolly-flatbed"></i> Awaiting Stock-In</span>';
                    } else {
                        $st_badge = '<span class="badge badge-pending"><i class="fas fa-truck"></i> Expected Delivery</span>';
                    }
                ?>
                  <tr>
                    <td><?= $req_date ?></td>
                    <td><code style="font-size:12px;font-weight:700;"><?= htmlspecialchars($display_po_fuel) ?></code></td>
                    <td>Petron Station</td>
                    <td style="text-align:right;font-weight:bold;"><?= number_format($tot_vol, 2) ?> L</td>
                    <td style="text-align:right;font-weight:bold;">&#8369;<?= number_format($tot_cost, 2) ?></td>
                    <td><?= htmlspecialchars($admin) ?></td>
                    <td><?= $st_badge ?></td>
                    <td style="text-align:right;">
                      <a href="print_po_new.php?date=<?= urlencode($date_key) ?>&type=fuel" target="_blank" class="btn btn-print btn-sm">
                        <i class="fas fa-print"></i> Print PO
                      </a>
                    </td>
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

<!-- ==================== FINALIZE MODAL ==================== -->
<div id="finalizeModal" class="modal-overlay">
  <div class="modal-box" style="width: 700px; max-width: 95vw;">
    <h3 id="modalTitle"><i class="fas fa-file-signature"></i> Finalize Purchase Order Batch</h3>
    <form method="POST" id="finalizeForm">
      <input type="hidden" name="action" value="finalize_batch">
      <input type="hidden" id="modalPoType" name="po_type" value="">
      <input type="hidden" id="modalPoDate" name="po_date" value="">
      
      <div class="info-box">
        <i class="fas fa-info-circle"></i> Review quantities and unit prices for this request batch. Adjust them if there are discrepancies.
      </div>
      
      <div class="form-group">
        <label>Batch ID / PO Number</label>
        <input type="text" id="modalBatchId" name="batch_id_override" required style="font-family:monospace; font-weight:bold;">
      </div>
      
      <div style="max-height: 250px; overflow-y: auto; margin-bottom: 15px; border: 1px solid #dee2e6; border-radius: 6px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead style="background:#f1f5f9; position:sticky; top:0; z-index:1;">
            <tr>
              <th style="padding:10px 8px; text-align:left; border-bottom:1px solid #dee2e6;">Product</th>
              <th style="padding:10px 8px; text-align:center; width:100px; border-bottom:1px solid #dee2e6;">Qty/Volume</th>
              <th style="padding:10px 8px; text-align:right; width:120px; border-bottom:1px solid #dee2e6;">Unit Price (₱)</th>
              <th style="padding:10px 8px; text-align:right; width:120px; border-bottom:1px solid #dee2e6;">Total (₱)</th>
            </tr>
          </thead>
          <tbody id="modalItemsBody">
            <!-- Dynamically populated -->
          </tbody>
        </table>
      </div>
      
      <div class="form-group">
        <label>Admin Notes / Remarks</label>
        <textarea name="admin_notes" rows="2" placeholder="Optional notes..."></textarea>
      </div>
      
      <div class="modal-actions">
        <button type="button" class="btn btn-sub" onclick="closeModal('finalizeModal')">Cancel</button>
        <button type="submit" class="btn btn-finalize">Finalize PO</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== REJECT MODAL ==================== -->
<div id="rejectModal" class="modal-overlay">
  <div class="modal-box">
    <h3><i class="fas fa-times-circle"></i> Reject Request Batch</h3>
    <form method="POST" id="rejectForm">
      <input type="hidden" name="action" value="reject_batch">
      <input type="hidden" id="rejectPoType" name="po_type" value="">
      <input type="hidden" id="rejectPoDate" name="po_date" value="">
      
      <div class="form-group">
        <label>Reason for Rejection *</label>
        <textarea name="reject_reason" required rows="3" placeholder="Enter reason..."></textarea>
      </div>
      
      <div class="modal-actions">
        <button type="button" class="btn btn-sub" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-reject">Submit Reject</button>
      </div>
    </form>
  </div>
</div>

<script>
// Tab Navigation State Management
function switchMainTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('.main-tab-content').forEach(el => el.style.display = 'none');
    document.getElementById('main-tab-' + tab).style.display = 'block';
    
    // Automatically trigger the correct sub-tab default active display
    if (tab === 'pending') {
        var activeSub = document.querySelector('#main-tab-pending .btn-sub.active');
        if (activeSub) activeSub.click();
    } else {
        var activeSub = document.querySelector('#main-tab-history .btn-sub.active');
        if (activeSub) activeSub.click();
    }
}

function switchSubTab(parent, type, btn) {
    // Deactivate sibling buttons
    var container = btn.closest('.main-tab-content');
    container.querySelectorAll('.btn-sub').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
    
    // Hide all sibling views
    container.querySelectorAll('.sub-tab-content').forEach(el => el.style.display = 'none');
    
    // Show target view
    document.getElementById(parent + '-' + type + '-view').style.display = 'block';
}

// Modal open/close actions
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

var rowInputs = {};
function recalcRowTotal(itemId) {
    if (!rowInputs[itemId]) {
        var qtyInput = document.querySelector('input[name="items[' + itemId + '][qty]"]');
        var priceInput = document.querySelector('input[name="items[' + itemId + '][price]"]');
        rowInputs[itemId] = { qtyInput: qtyInput, priceInput: priceInput };
    }
    var q = parseFloat(rowInputs[itemId].qtyInput.value) || 0;
    var p = parseFloat(rowInputs[itemId].priceInput.value) || 0;
    document.getElementById('row-total-' + itemId).textContent = '₱' + formatNumber((q * p).toFixed(2));
}

function openFinalizeMerch(date, items) {
    rowInputs = {};
    document.getElementById('modalPoType').value = 'merch';
    document.getElementById('modalPoDate').value = date;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-boxes"></i> Finalize Merchandise PO Batch &mdash; ' + date;
    
    // Default Batch ID: POM-YYYYMMDD
    var formattedDate = date.replace(/-/g, '');
    document.getElementById('modalBatchId').value = 'POM-' + formattedDate;
    
    var html = '';
    items.forEach(function(item) {
        var total = (item.quantity * item.unit_price).toFixed(2);
        html += '<tr>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee;">' +
                '<strong>' + escapeHtml(item.product_name) + '</strong><br>' +
                '<span style="font-size:11px; color:#6c757d;">SKU: ' + escapeHtml(item.item_sku || '') + ' | Cat: ' + escapeHtml(item.item_category || '') + '</span>' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:center;">' +
                '<input type="number" step="any" name="items[' + item.id + '][qty]" value="' + item.quantity + '" class="form-input" style="width:80px; text-align:center; padding:4px;" oninput="recalcRowTotal(' + item.id + ')">' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:right;">' +
                '<input type="number" step="any" name="items[' + item.id + '][price]" value="' + item.unit_price + '" class="form-input" style="width:100px; text-align:right; padding:4px;" oninput="recalcRowTotal(' + item.id + ')">' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:right; font-weight:bold;" id="row-total-' + item.id + '">₱' + formatNumber(total) + '</td>' +
        '</tr>';
    });
    
    document.getElementById('modalItemsBody').innerHTML = html;
    document.getElementById('finalizeModal').classList.add('show');
}

function openFinalizeFuel(date, items) {
    rowInputs = {};
    document.getElementById('modalPoType').value = 'fuel';
    document.getElementById('modalPoDate').value = date;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-gas-pump"></i> Finalize Fuel PO Batch &mdash; ' + date;
    
    // Default Batch ID: POF-YYYYMMDD
    var formattedDate = date.replace(/-/g, '');
    document.getElementById('modalBatchId').value = 'POF-' + formattedDate;
    
    var html = '';
    items.forEach(function(item) {
        var total = (item.volume * item.unit_price).toFixed(2);
        html += '<tr>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee;">' +
                '<strong>' + escapeHtml(item.fuel_name) + '</strong>' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:center;">' +
                '<input type="number" step="any" name="items[' + item.id + '][qty]" value="' + item.volume + '" class="form-input" style="width:80px; text-align:center; padding:4px;" oninput="recalcRowTotal(' + item.id + ')">' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:right;">' +
                '<input type="number" step="any" name="items[' + item.id + '][price]" value="' + item.unit_price + '" class="form-input" style="width:100px; text-align:right; padding:4px;" oninput="recalcRowTotal(' + item.id + ')">' +
            '</td>' +
            '<td style="padding:10px 8px; border-bottom:1px solid #eee; text-align:right; font-weight:bold;" id="row-total-' + item.id + '">₱' + formatNumber(total) + '</td>' +
        '</tr>';
    });
    
    document.getElementById('modalItemsBody').innerHTML = html;
    document.getElementById('finalizeModal').classList.add('show');
}

function openReject(type, date) {
    document.getElementById('rejectPoType').value = type;
    document.getElementById('rejectPoDate').value = date;
    document.getElementById('rejectModal').classList.add('show');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
