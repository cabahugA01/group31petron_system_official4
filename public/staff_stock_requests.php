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

// ── Handle Request Cancellation ──────────────────────────────────────────
if (isset($_GET['cancel_id']) && isset($_GET['type'])) {
    $cancel_id = (int)$_GET['cancel_id'];
    $type = $_GET['type']; // 'fuel' or 'merch'
    
    $success = false;
    $error = '';
    
    if ($type === 'fuel') {
        // Verify it is pending and belongs to this staff
        $stmt = $pdo->prepare("SELECT id FROM fuel_stock_requests WHERE id = ? AND staff_id = ? AND status = 'Pending'");
        $stmt->execute([$cancel_id, $me['id']]);
        if ($stmt->fetch()) {
            $upd = $pdo->prepare("DELETE FROM fuel_stock_requests WHERE id = ?");
            $upd->execute([$cancel_id]);
            $success = true;
        } else {
            $error = "Only pending fuel requests can be cancelled.";
        }
    } else {
        // Verify it is pending and belongs to this staff
        $stmt = $pdo->prepare("SELECT id, item_name FROM stock_requests WHERE id = ? AND staff_id = ? AND status = 'Pending'");
        $stmt->execute([$cancel_id, $me['id']]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($req) {
            $pdo->beginTransaction();
            try {
                // Delete the request
                $del = $pdo->prepare("DELETE FROM stock_requests WHERE id = ?");
                $del->execute([$cancel_id]);
                
                // Delete associated purchase orders if any
                $del_po = $pdo->prepare("DELETE FROM purchase_orders WHERE request_id = ? AND type = 'merch'");
                $del_po->execute([$cancel_id]);
                
                // Audit log
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $detail = "Stock request cancelled by staff | Request #{$cancel_id} | Item: {$req['item_name']}";
                    $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'inventory', 'Cancel', ?, 'stock_requests', ?, 'Success', ?, ?, NOW())")
                        ->execute([$me['id'], $detail, $cancel_id, $ip, $ua]);
                } catch (Exception $e) {}
                
                $pdo->commit();
                $success = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to cancel request: " . $e->getMessage();
            }
        } else {
            $error = "Only pending stock requests can be cancelled.";
        }
    }
    
    if ($success) {
        $_SESSION['success_msg'] = "Request cancelled successfully.";
    } else {
        $_SESSION['error_msg'] = $error ?: "Unauthorized or invalid request.";
    }
    header("Location: staff_stock_requests.php" . ($type === 'merch' ? '#tab-merch' : '#tab-fuel'));
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
            sr.item_id,
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
            sr.updated_at,
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
            fsr.updated_at,
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

// ── Fetch active merchandise products for request dropdown ──────────────────
$all_products = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.id, ip.product_name AS name, ip.sku, ip.category, COALESCE(si.stock_level, ip.stock, 0) AS stock_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category, '')) != 'fuel' AND LOWER(COALESCE(ip.status, 'active')) = 'active'
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch active fuel products for request dropdown ─────────────────────────
$all_fuels = [];
try {
    $stmt = $pdo->prepare("
        SELECT fuel_type, current_level, capacity
        FROM fuel_inventory
        WHERE station_id = ?
        ORDER BY fuel_type
    ");
    $stmt->execute([$station_id]);
    $all_fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fallback proxy data for fuels if fuel_inventory is empty
if (empty($all_fuels)) {
    $tank_config = [
        ['fuel_type' => 'Diesel', 'current_level' => 12500.00, 'capacity' => 20000.00],
        ['fuel_type' => 'Kerosene', 'current_level' => 4500.00, 'capacity' => 10000.00],
        ['fuel_type' => 'Turbo Diesel', 'current_level' => 16000.00, 'capacity' => 24000.00],
        ['fuel_type' => 'XCS Plus', 'current_level' => 8900.00, 'capacity' => 20000.00],
        ['fuel_type' => 'XTRA UNL', 'current_level' => 11000.00, 'capacity' => 20000.00]
    ];
    $all_fuels = $tank_config;
}

// ── Per-tab summary stats ─────────────────────────────────────────────────
$fuel_stats  = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
$merch_stats = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];

foreach ($fuel_requests as $r) {
    $st = strtolower($r['status'] ?? 'pending');
    $fuel_stats['total']++;
    if ($st === 'pending') $fuel_stats['pending']++;
    elseif (in_array($st, ['approved','validated','completed'])) $fuel_stats['approved']++;
    elseif ($st === 'rejected') $fuel_stats['rejected']++;
}
foreach ($merch_requests as $r) {
    $st = strtolower($r['status'] ?? 'pending');
    $merch_stats['total']++;
    if ($st === 'pending') $merch_stats['pending']++;
    elseif (in_array($st, ['approved','validated','forwarded to admin','completed'])) $merch_stats['approved']++;
    elseif ($st === 'rejected') $merch_stats['rejected']++;
}

// ── Fuel tank label helper ────────────────────────────────────────────────
function get_fuel_tank_label($fuel_type) {
    $ft = strtolower(trim($fuel_type));
    if ($ft === 'diesel')       return 'Tank #1 – #6';
    if ($ft === 'kerosene')     return 'Tank #7';
    if ($ft === 'turbo diesel') return 'Tank #8 – #9';
    if ($ft === 'xcs plus')     return 'Tank #10 – #13';
    if ($ft === 'xtra unl')     return 'Tank #14 – #17';
    return '—';
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.ssr-wrap { max-width:1300px; margin:0 auto; padding:16px 24px 24px 24px; }

/* Header */
.ssr-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:18px; border-bottom:1px solid #e2e8f0; margin-bottom:20px; }
.ssr-title h1 { font-size:24px; font-weight:700; margin:0 0 4px 0; color:#002F70; display:flex; align-items:center; gap:10px; }
.ssr-title p  { font-size:13px; color:#6c757d; margin:0; text-transform:uppercase; letter-spacing:0.3px; }

/* Alert messages styling */
.ssr-alert { border-radius:8px; padding:12px 18px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:10px; }
.ssr-alert-success { background:#e6fffa; color:#0f766e; border:1px solid #99f6e4; }
.ssr-alert-error { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }

/* Metrics Summary Cards */
.ssr-stats-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; }
.ssr-stat-card { background:#fff; border-radius:12px; padding:18px 20px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,.04); display:flex; align-items:center; gap:16px; transition:transform 0.15s ease, box-shadow 0.15s ease; }
.ssr-stat-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.07); }
.ssr-stat-icon { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.ssr-stat-info { min-width:0; }
.ssr-stat-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:3px; }
.ssr-stat-val { font-size:26px; font-weight:800; line-height:1; color:#0f172a; }

/* Status-specific card borders and colors */
.card-pending  { border-left:4px solid #f59e0b; }
.card-pending  .ssr-stat-icon { background:#fffbeb; color:#d97706; }
.card-approved { border-left:4px solid #10b981; }
.card-approved .ssr-stat-icon { background:#ecfdf5; color:#059669; }
.card-rejected { border-left:4px solid #ef4444; }
.card-rejected .ssr-stat-icon { background:#fef2f2; color:#dc2626; }
.card-total    { border-left:4px solid #3b82f6; }
.card-total    .ssr-stat-icon { background:#eff6ff; color:#2563eb; }

/* Tabs Layout */
.ssr-tabs { display:flex; border-bottom:2px solid #e2e8f0; margin-bottom:20px; }
.ssr-tab  { padding:12px 24px; font-size:13.5px; font-weight:700; color:#64748b; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:color .15s, border-color .15s; display:flex; align-items:center; gap:8px; user-select:none; }
.ssr-tab:hover  { color:#002F70; }
.ssr-tab.active { color:#002F70; border-bottom-color:#002F70; }
.ssr-tab .tab-count { background:#f1f5f9; color:#475569; font-size:11px; font-weight:700; padding:1px 8px; border-radius:12px; }
.ssr-tab.active .tab-count { background:#002F70; color:#fff; }

.ssr-tab-panel { display:none; }
.ssr-tab-panel.active { display:block; }

/* Table and Card Container styling */
.ssr-card { background:#fff; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,.04); border:1px solid #e2e8f0; margin-bottom:24px; overflow:hidden; }
.ssr-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; gap:12px; background:#fafafa; }
.ssr-card-title { font-size:.95rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.ssr-card-body { padding:20px; }

/* Status Badges */
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; border:1px solid transparent; text-transform:uppercase; text-align:center; }
.sbadge-pending            { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.sbadge-approved           { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.sbadge-validated          { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.sbadge-rejected           { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.sbadge-forwarded-to-admin { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.sbadge-completed          { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }

/* Standard Flter Row style */
.ssr-filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
.ssr-filter-row select, .ssr-filter-row input { padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; background:#fff; height:38px; outline:none; }
.ssr-filter-row select:focus, .ssr-filter-row input:focus { border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.1); }

/* Table custom classes for strict Petron outline design system */
.table { width:100%; border-collapse:collapse; text-align:left; }
.table thead th { background:#002F70; color:#ffffff; padding:12px 10px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
.table tbody td { padding:12px 10px; border-bottom:1px solid #e2e8f0; font-size:12.5px; vertical-align:middle; color:#334155; }
.table tbody tr:hover td { background:#f8fafc; }

/* Table Action Buttons layout - Vertical list */
.action-stack { display:flex; flex-direction:column; gap:4px; align-items:stretch; width:130px; }

/* Outline Buttons design styles */
.flt-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; height:32px; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:700; cursor:pointer; background:#fff; border:1px solid #cbd5e1; transition:all 0.15s ease-in-out; text-decoration:none; white-space:nowrap; box-sizing:border-box; width:100%; }
.flt-btn-primary   { color:#002F70; border-color:#002F70; }
.flt-btn-primary:hover   { background:#002F70; color:#fff; }
.flt-btn-secondary { color:#64748b; border-color:#cbd5e1; }
.flt-btn-secondary:hover { background:#f1f5f9; color:#334155; }
.flt-btn-danger    { color:#dc2626; border-color:#fca5a5; }
.flt-btn-danger:hover    { background:#dc2626; color:#fff; border-color:#dc2626; }
.flt-btn-info      { color:#0284c7; border-color:#7dd3fc; }
.flt-btn-info:hover      { background:#0284c7; color:#fff; border-color:#0284c7; }

/* Modals layout structures */
.ssr-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; }
.ssr-overlay.open { display:flex; }
.ssr-modal-box { background:#fff; border-radius:12px; padding:24px; width:520px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04); animation:modalIn 0.15s ease-out; }
@keyframes modalIn { from { opacity:0; transform:scale(0.96); } to { opacity:1; transform:scale(1); } }
.ssr-modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
.ssr-modal-title { font-size:16px; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.ssr-modal-close { background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; }
.ssr-modal-close:hover { color:#475569; }
.ssr-modal-foot { display:flex; gap:10px; justify-content:flex-end; align-items:center; margin-top:20px; padding-top:14px; border-top:1px solid #e2e8f0; }

/* Field elements style */
.ssr-field { margin-bottom:14px; }
.ssr-field label { display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.3px; }
.ssr-field select, .ssr-field input, .ssr-field textarea { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#334155; box-sizing:border-box; outline:none; }
.ssr-field select:focus, .ssr-field input:focus, .ssr-field textarea:focus { border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.1); }
.ssr-field textarea { resize:vertical; min-height:80px; }

/* Details modal layout grid */
.ssr-details-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:16px; }
.ssr-detail-row { display:flex; flex-direction:column; gap:3px; }
.ssr-detail-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#64748b; }
.ssr-detail-val { font-size:13.5px; font-weight:600; color:#0f172a; }
.ssr-detail-full { grid-column: span 2; }
.ssr-detail-notes { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px; font-size:12.5px; color:#334155; margin-top:4px; line-height:1.5; }
</style>

<div class="ssr-wrap">

    <!-- Header -->
    <div class="ssr-header">
        <div class="ssr-title">
            <h1><i class="fas fa-history"></i> Stock Requests</h1>
            <p>History &amp; tracking of your submitted stock requests. To create a new request, use the <strong>Stock Request</strong> button in Fuel Inventory or Merchandise Inventory.</p>
        </div>
    </div>

    <!-- Alert Notices -->
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="ssr-alert ssr-alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?= htmlspecialchars($_SESSION['success_msg']) ?></div>
        </div>
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="ssr-alert ssr-alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= htmlspecialchars($_SESSION['error_msg']) ?></div>
        </div>
        <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="ssr-alert ssr-alert-success">
            <i class="fas fa-check-circle"></i>
            <div>Stock request submitted successfully and is now pending review.</div>
        </div>
    <?php endif; ?>



    <!-- Tabs Navigation -->
    <div class="ssr-tabs">
        <div class="ssr-tab active" onclick="switchTab('fuel')" id="tab-fuel">
            <i class="fas fa-gas-pump"></i> Fuel Requests
            <span class="tab-count"><?= count($fuel_requests) ?></span>
        </div>
        <div class="ssr-tab" onclick="switchTab('merch')" id="tab-merch">
            <i class="fas fa-shopping-basket"></i> Merchandise Requests
            <span class="tab-count"><?= count($merch_requests) ?></span>
        </div>
    </div>

    <!-- ══ FUEL PANEL ══ -->
    <div class="ssr-tab-panel active" id="panel-fuel">

        <!-- Per-Tab Summary Cards -->
        <div class="ssr-stats-row" style="margin-bottom:20px;">
            <div class="ssr-stat-card card-total">
                <div class="ssr-stat-icon"><i class="fas fa-list"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Total Requests</div>
                    <div class="ssr-stat-val"><?= $fuel_stats['total'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-pending">
                <div class="ssr-stat-icon"><i class="fas fa-clock"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Pending</div>
                    <div class="ssr-stat-val"><?= $fuel_stats['pending'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-approved">
                <div class="ssr-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Approved</div>
                    <div class="ssr-stat-val"><?= $fuel_stats['approved'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-rejected">
                <div class="ssr-stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Rejected</div>
                    <div class="ssr-stat-val"><?= $fuel_stats['rejected'] ?></div>
                </div>
            </div>
        </div>

        <div class="ssr-card">
            <div class="ssr-card-head">
                <div class="ssr-card-title"><i class="fas fa-gas-pump"></i> Fuel Requests Log</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php
                    $export_table_id       = 'fuelSrTable';
                    $export_filename       = 'fuel_stock_requests_' . date('Ymd');
                    $export_title          = 'Fuel Stock Requests';
                    $export_rows_select_id = 'fuelSrRowsLimit';
                    $export_default_rows   = 10;
                    require __DIR__ . '/../partials/export_buttons.php';
                    ?>
                </div>
            </div>
            <div class="ssr-card-body">
                <div class="ssr-filter-row">
                    <input type="text" id="fuelSearch" placeholder="Search fuel type..." style="width:220px;" onkeyup="applyFuelFilters()">
                    <select id="fuelStatusFilter" onchange="applyFuelFilters()">
                        <option value="">All Status</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                    <input type="date" id="fuelDateFilter" onchange="applyFuelFilters()">
                </div>

                <div style="overflow-x:auto;">
                    <table class="table" id="fuelSrTable">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Date</th>
                                <th>Tank</th>
                                <th>Fuel Type</th>
                                <th>Current Level (L)</th>
                                <th>Requested Liters</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fuelTableBody">
                            <?php if (empty($fuel_requests)): ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;padding:48px;color:#64748b;">
                                        <i class="fas fa-gas-pump" style="font-size:3em;display:block;margin-bottom:12px;opacity:.2;"></i>
                                        <strong>No fuel requests submitted yet.</strong><br>
                                        <span style="font-size:12px;color:#94a3b8;margin-top:6px;display:block;">Use the <strong>Stock Request</strong> button in <a href="staff_inventory_fuel.php" style="color:#002F70;">Fuel Inventory</a> to submit a request.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fuel_requests as $r):
                                    $st = htmlspecialchars($r['status'] ?? 'Pending');
                                    $s_cls = 'sbadge sbadge-' . strtolower(str_replace(' ', '-', $st));
                                    $created = date('Y-m-d', strtotime($r['created_at']));
                                    $tank_lbl = get_fuel_tank_label($r['fuel_type']);
                                    $updated  = !empty($r['updated_at']) ? date('M d, Y H:i', strtotime($r['updated_at'])) : date('M d, Y H:i', strtotime($r['created_at']));
                                ?>
                                    <tr class="fuel-req-row"
                                        data-product="<?= htmlspecialchars(strtolower($r['fuel_type'])) ?>"
                                        data-status="<?= $st ?>"
                                        data-date="<?= $created ?>">
                                        <td><strong>#<?= (int)$r['id'] ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                                        <td style="font-size:11.5px;color:#475569;"><?= htmlspecialchars($tank_lbl) ?></td>
                                        <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
                                        <td><?= number_format((float)$r['current_level'], 2) ?> L</td>
                                        <td style="font-weight:700;color:#002F70;"><?= number_format((float)$r['requested_liters'], 2) ?> L</td>
                                        <td><span class="<?= $s_cls ?>"><?= $st ?></span></td>
                                        <td style="font-size:11.5px;color:#64748b;"><?= $updated ?></td>
                                        <td>
                                            <button class="flt-btn flt-btn-info" onclick='viewRequest("fuel", <?= json_encode($r, ENT_QUOTES) ?>)'>
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="fuelSrPagination" style="margin-top:12px;"></div>
            </div>
        </div>
    </div>

    <!-- ══ MERCHANDISE PANEL ══ -->
    <div class="ssr-tab-panel" id="panel-merch">

        <!-- Per-Tab Summary Cards -->
        <div class="ssr-stats-row" style="margin-bottom:20px;">
            <div class="ssr-stat-card card-total">
                <div class="ssr-stat-icon"><i class="fas fa-list"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Total Requests</div>
                    <div class="ssr-stat-val"><?= $merch_stats['total'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-pending">
                <div class="ssr-stat-icon"><i class="fas fa-clock"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Pending</div>
                    <div class="ssr-stat-val"><?= $merch_stats['pending'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-approved">
                <div class="ssr-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Approved</div>
                    <div class="ssr-stat-val"><?= $merch_stats['approved'] ?></div>
                </div>
            </div>
            <div class="ssr-stat-card card-rejected">
                <div class="ssr-stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="ssr-stat-info">
                    <div class="ssr-stat-label">Rejected</div>
                    <div class="ssr-stat-val"><?= $merch_stats['rejected'] ?></div>
                </div>
            </div>
        </div>

        <div class="ssr-card">
            <div class="ssr-card-head">
                <div class="ssr-card-title"><i class="fas fa-shopping-basket"></i> Merchandise Requests Log</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php
                    $export_table_id       = 'merchSrTable';
                    $export_filename       = 'merch_stock_requests_' . date('Ymd');
                    $export_title          = 'Merchandise Stock Requests';
                    $export_rows_select_id = 'merchSrRowsLimit';
                    $export_default_rows   = 10;
                    require __DIR__ . '/../partials/export_buttons.php';
                    ?>
                </div>
            </div>
            <div class="ssr-card-body">
                
                <!-- Filters Row -->
                <div class="ssr-filter-row">
                    <input type="text" id="merchSearch" placeholder="Search product..." style="width:220px;" onkeyup="applyMerchFilters()">
                    <select id="merchStatusFilter" onchange="applyMerchFilters()">
                        <option value="">All Status</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Forwarded to Admin">Forwarded to Admin</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                    <input type="date" id="merchDateFilter" onchange="applyMerchFilters()">
                </div>

                <div style="overflow-x:auto;">
                    <table class="table" id="merchSrTable">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Requested Qty</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="merchTableBody">
                            <?php if (empty($merch_requests)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:48px;color:#64748b;">
                                        <i class="fas fa-inbox" style="font-size:3em;display:block;margin-bottom:12px;opacity:.2;"></i>
                                        <strong>No merchandise requests submitted yet.</strong><br>
                                        <span style="font-size:12px;color:#94a3b8;margin-top:6px;display:block;">Use the <strong>Stock Request</strong> button in <a href="staff_inventory_merchandise.php" style="color:#002F70;">Merchandise Inventory</a> to submit a request.</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($merch_requests as $r):
                                    $st = htmlspecialchars($r['status'] ?? 'Pending');
                                    $st_key = strtolower(str_replace([' ', '/'], '-', $st));
                                    $s_cls = 'sbadge sbadge-' . $st_key;
                                    $created = date('Y-m-d', strtotime($r['created_at']));
                                    $updated  = !empty($r['updated_at']) ? date('M d, Y H:i', strtotime($r['updated_at'])) : date('M d, Y H:i', strtotime($r['created_at']));
                                ?>
                                    <tr class="merch-req-row" 
                                        data-product="<?= htmlspecialchars(strtolower($r['item_name'])) ?>"
                                        data-status="<?= $st ?>"
                                        data-date="<?= $created ?>">
                                        <td><strong>#<?= (int)$r['id'] ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($r['item_name']) ?></strong><br>
                                            <span style="font-size:11px;color:#64748b;">SKU: <code><?= htmlspecialchars($r['item_sku'] ?? '&mdash;') ?></code> | Cat: <?= htmlspecialchars($r['item_category'] ?? '') ?></span>
                                        </td>
                                        <td><?= number_format((int)$r['current_stock']) ?> pcs</td>
                                        <td style="font-weight:700;color:#002F70;"><?= number_format((int)$r['requested_quantity']) ?> pcs</td>
                                        <td><span class="<?= $s_cls ?>"><?= $st ?></span></td>
                                        <td style="font-size:11.5px;color:#64748b;"><?= $updated ?></td>
                                        <td>
                                            <button class="flt-btn flt-btn-info" onclick='viewRequest("merch", <?= json_encode($r, ENT_QUOTES) ?>)'>
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="merchSrPagination" style="margin-top:12px;"></div>
            </div>
        </div>
    </div>

</div>

<!-- ══ DETAILS VIEW MODAL ══ -->
<div class="ssr-overlay" id="viewModal">
    <div class="ssr-modal-box">
        <div class="ssr-modal-head">
            <div class="ssr-modal-title"><i class="fas fa-info-circle"></i> Request Details</div>
            <button class="ssr-modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        
        <div class="ssr-details-grid" id="modalDetailsContent">
            <!-- Populated dynamically via JS -->
        </div>

        <div class="ssr-modal-foot">
            <button class="flt-btn flt-btn-secondary" style="width:100px;" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>



<script>


function closeViewModal() {
    document.getElementById('viewModal').classList.remove('open');
}

// View details loader
function viewRequest(type, req) {
    var contentEl = document.getElementById('modalDetailsContent');
    var html = '';
    
    var category = type === 'fuel' ? 'Fuel' : 'Merchandise';
    var product = type === 'fuel' ? req.fuel_type : req.item_name;
    var current = type === 'fuel' ? parseFloat(req.current_level).toLocaleString() + ' L' : parseInt(req.current_stock).toLocaleString() + ' pcs';
    var requested = type === 'fuel' ? parseFloat(req.requested_liters).toLocaleString() + ' L' : parseInt(req.requested_quantity).toLocaleString() + ' pcs';
    
    var approved = '—';
    if (type === 'fuel' && req.approved_liters !== null) {
        approved = parseFloat(req.approved_liters).toLocaleString() + ' L';
    } else if (type === 'merch' && req.approved_quantity !== null) {
        approved = parseInt(req.approved_quantity).toLocaleString() + ' pcs';
    }
    
    var formattedDate = new Date(req.created_at).toLocaleString();
    var manager = req.manager_name ? req.manager_name : '—';
    
    var statusKey = req.status.toLowerCase().replace(/[\s\/]+/g, '-');
    var badgeClass = 'sbadge sbadge-' + statusKey;

    html = `
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Request ID</div>
            <div class="ssr-detail-val">#${req.id}</div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Status</div>
            <div class="ssr-detail-val"><span class="${badgeClass}">${req.status}</span></div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Category</div>
            <div class="ssr-detail-val">${category}</div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Date Submitted</div>
            <div class="ssr-detail-val">${formattedDate}</div>
        </div>
        <div class="ssr-detail-row ssr-detail-full">
            <div class="ssr-detail-label">Product Name / Item</div>
            <div class="ssr-detail-val" style="font-size:15px; color:#002F70;"><strong>${product}</strong></div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Current Stock Level</div>
            <div class="ssr-detail-val">${current}</div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Requested Qty</div>
            <div class="ssr-detail-val" style="color:#002F70; font-weight:700;">${requested}</div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Approved Qty</div>
            <div class="ssr-detail-val" style="color:#0f766e; font-weight:700;">${approved}</div>
        </div>
        <div class="ssr-detail-row">
            <div class="ssr-detail-label">Reviewing Manager</div>
            <div class="ssr-detail-val">${manager}</div>
        </div>
        <div class="ssr-detail-row ssr-detail-full">
            <div class="ssr-detail-label">Staff Remarks</div>
            <div class="ssr-detail-notes">${req.remarks ? escHtml(req.remarks) : '<span style="color:#94a3b8;">No remarks submitted.</span>'}</div>
        </div>
        <div class="ssr-detail-row ssr-detail-full">
            <div class="ssr-detail-label">Manager/Admin Notes</div>
            <div class="ssr-detail-notes" style="border-left: 3px solid #002F70; background: #f0f4f8;">${req.manager_notes ? escHtml(req.manager_notes) : '<span style="color:#94a3b8;">Awaiting review notes.</span>'}</div>
        </div>
    `;
    
    contentEl.innerHTML = html;
    document.getElementById('viewModal').classList.add('open');
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}



// Print request slip
function printRequest(type, req) {
    var printWindow = window.open('', '_blank', 'width=800,height=600');
    
    var category = type === 'fuel' ? 'Fuel' : 'Merchandise';
    var product = type === 'fuel' ? req.fuel_type : req.item_name;
    var current = type === 'fuel' ? parseFloat(req.current_level).toLocaleString() + ' L' : parseInt(req.current_stock).toLocaleString() + ' pcs';
    var requested = type === 'fuel' ? parseFloat(req.requested_liters).toLocaleString() + ' L' : parseInt(req.requested_quantity).toLocaleString() + ' pcs';
    
    var approved = '—';
    if (type === 'fuel' && req.approved_liters !== null) {
        approved = parseFloat(req.approved_liters).toLocaleString() + ' L';
    } else if (type === 'merch' && req.approved_quantity !== null) {
        approved = parseInt(req.approved_quantity).toLocaleString() + ' pcs';
    }
    
    var html = `
    <html>
    <head>
        <title>Stock Request Slip #${req.id}</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 40px; color: #334155; line-height: 1.6; }
            .header { text-align: center; border-bottom: 2px solid #002F70; padding-bottom: 15px; margin-bottom: 25px; }
            .header h1 { color: #002F70; margin: 0 0 5px; font-size: 24px; letter-spacing: 0.5px; }
            .header p { margin: 0; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 600; }
            .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; }
            .meta-item { font-size: 13.5px; }
            .meta-label { font-weight: bold; color: #64748b; text-transform: uppercase; font-size: 11px; margin-bottom: 3px; letter-spacing: 0.3px; }
            .meta-val { font-size: 14px; font-weight: 600; color: #0f172a; }
            .details-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            .details-table th { background: #002F70; color: #ffffff; text-align: left; padding: 12px; border: 1px solid #cbd5e1; font-size: 11.5px; font-weight: bold; text-transform: uppercase; }
            .details-table td { padding: 12px; border: 1px solid #cbd5e1; font-size: 13.5px; }
            .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; border: 1px solid transparent; }
            .status-pending { background: #fffbeb; color: #b45309; border-color: #fde68a; }
            .status-approved { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
            .status-rejected { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
            .section-title { font-size: 13px; font-weight: bold; text-transform: uppercase; border-bottom: 2px solid #cbd5e1; padding-bottom: 6px; margin-bottom: 15px; color: #002F70; letter-spacing: 0.5px; }
            .notes-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; font-size: 13px; margin-bottom: 40px; }
            .notes-box p { margin: 0 0 10px; }
            .notes-box p:last-child { margin-bottom: 0; }
            .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; margin-top: 60px; text-align: center; }
            .sig-line { border-top: 1.5px solid #475569; margin-top: 40px; padding-top: 6px; font-size: 12.5px; font-weight: bold; color: #475569; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>PETRON STATION</h1>
            <p>Official Stock Request Slip</p>
        </div>
        
        <div class="meta-grid">
            <div class="meta-item">
                <div class="meta-label">Request ID</div>
                <div class="meta-val">#${req.id}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Date Submitted</div>
                <div class="meta-val">${new Date(req.created_at).toLocaleString()}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Category</div>
                <div class="meta-val">${category}</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Current Status</div>
                <div class="meta-val">
                    <span class="status-badge status-${req.status.toLowerCase().replace(/[\s\/]+/g, '-')}">${req.status}</span>
                </div>
            </div>
        </div>
        
        <div class="section-title">Item Details</div>
        <table class="details-table">
            <thead>
                <tr>
                    <th>Product Description</th>
                    <th>Current Stock Level</th>
                    <th>Requested Qty</th>
                    <th>Approved Qty</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>${product}</strong></td>
                    <td>${current}</td>
                    <td><strong style="color:#002F70;">${requested}</strong></td>
                    <td><strong style="color:#0f766e;">${approved}</strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="section-title">Remarks & Notes</div>
        <div class="notes-box">
            <p><strong>Staff Encoder Remarks:</strong><br>
            ${req.remarks ? escHtml(req.remarks) : '<span style="color:#94a3b8;">None</span>'}</p>
            <p><strong>Manager/Admin Approver Notes:</strong><br>
            ${req.manager_notes ? escHtml(req.manager_notes) : '<span style="color:#94a3b8;">Awaiting review comments.</span>'}</p>
        </div>
        
        <div class="signatures">
            <div>
                <div class="sig-line">Requested By (Staff Encoder)</div>
            </div>
            <div>
                <div class="sig-line">Approved By (Station Manager)</div>
            </div>
        </div>
        
        <script>
            window.onload = function() {
                window.print();
                window.onafterprint = function() {
                    window.close();
                };
            };
        <\/script>
    </body>
    </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
}

// Filters implementation for Fuel Requests
function applyFuelFilters() {
    var query = document.getElementById('fuelSearch').value.toLowerCase().trim();
    var status = document.getElementById('fuelStatusFilter').value;
    var date = document.getElementById('fuelDateFilter').value;
    
    var rows = document.querySelectorAll('#fuelTableBody .fuel-req-row');
    rows.forEach(function(row) {
        var product = row.dataset.product;
        var rstatus = row.dataset.status;
        var rdate = row.dataset.date;
        
        var matchQuery = !query || product.indexOf(query) !== -1;
        var matchStatus = !status || rstatus === status;
        var matchDate = !date || rdate === date;
        
        if (matchQuery && matchStatus && matchDate) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Filters implementation for Merchandise Requests
function applyMerchFilters() {
    var query = document.getElementById('merchSearch').value.toLowerCase().trim();
    var status = document.getElementById('merchStatusFilter').value;
    var date = document.getElementById('merchDateFilter').value;
    
    var rows = document.querySelectorAll('#merchTableBody .merch-req-row');
    rows.forEach(function(row) {
        var product = row.dataset.product;
        var rstatus = row.dataset.status;
        var rdate = row.dataset.date;
        
        var matchQuery = !query || product.indexOf(query) !== -1;
        var matchStatus = !status || rstatus === status;
        var matchDate = !date || rdate === date;
        
        if (matchQuery && matchStatus && matchDate) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Tab Switching
function switchTab(tab) {
    document.querySelectorAll('.ssr-tab').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.ssr-tab-panel').forEach(function(el) { el.classList.remove('active'); });
    
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
    
    history.replaceState(null, '', '#tab-' + tab);
}

document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash === '#tab-merch') {
        switchTab('merch');
    } else {
        switchTab('fuel');
    }
    
    // Setup client table pagination
    setupTablePagination('fuelSrTable', 'fuelSrRowsLimit', 'fuelSrPagination', 10);
    setupTablePagination('merchSrTable', 'merchSrRowsLimit', 'merchSrPagination', 10);
});

// Close viewModal on clicking overlay background
document.querySelectorAll('.ssr-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            closeViewModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
