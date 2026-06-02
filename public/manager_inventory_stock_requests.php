<?php
$page_id = 'mgr_inv_stock_request';
require_once __DIR__ . "/../backend/lib.php";
require_once __DIR__ . "/db_connect.php";
require_login();

$me         = current_user();
$role       = role_key($me["role"] ?? "");
$station_id = user_station_id();

if (!in_array($role, ["manager", "admin", "superadmin"])) {
    header("Location: dashboard.php");
    exit;
}

// Ensure fuel_stock_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL, station_id INT NOT NULL,
        fuel_type VARCHAR(100) NOT NULL,
        current_level DECIMAL(12,2) NOT NULL DEFAULT 0,
        capacity DECIMAL(12,2) NOT NULL DEFAULT 0,
        stock_status VARCHAR(30) NOT NULL DEFAULT 'LOW',
        requested_liters DECIMAL(12,2) NOT NULL,
        remarks TEXT,
        status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
        approved_liters DECIMAL(12,2) NULL,
        manager_id INT NULL, manager_notes TEXT NULL,
        processed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_request_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        performed_by INT NOT NULL,
        performed_by_role VARCHAR(50) NOT NULL,
        old_status VARCHAR(30) NULL, new_status VARCHAR(30) NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ignored) {}

// Handle Fuel POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'fuel') {
    $action = $_POST['action'] ?? '';
    $req_id = (int)($_POST['request_id'] ?? 0);

    if ($action === 'approve' && $req_id > 0) {
        $approved_liters = (float)($_POST['approved_liters'] ?? 0);
        $manager_notes   = trim($_POST['manager_notes'] ?? '');
        if ($approved_liters > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($req && strtolower($req['status']) === 'pending') {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE fuel_stock_requests SET status='Approved', approved_liters=?, manager_id=?, manager_notes=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
                        ->execute([$approved_liters, $me['id'], $manager_notes, $req_id]);
                    $note = "Approved: {$req['requested_liters']} L → {$approved_liters} L of {$req['fuel_type']}. Manager: {$me['name']}.";
                    if ($manager_notes) $note .= " Notes: {$manager_notes}";
                    $pdo->prepare("INSERT INTO fuel_stock_request_audit (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes) VALUES (?, 'Approved', ?, ?, 'Pending', 'Approved', ?)")
                        ->execute([$req_id, $me['id'], $role, $note]);
                    $pdo->commit();
                    $_SESSION['success'] = "Fuel request approved. {$approved_liters} L of {$req['fuel_type']} confirmed.";
                } else {
                    $_SESSION['error'] = 'Request not found or already processed.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Approved liters must be greater than 0.';
        }
    } elseif ($action === 'reject' && $req_id > 0) {
        $manager_notes = trim($_POST['manager_notes'] ?? '');
        if (!empty($manager_notes)) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($req && strtolower($req['status']) === 'pending') {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE fuel_stock_requests SET status='Rejected', manager_id=?, manager_notes=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
                        ->execute([$me['id'], $manager_notes, $req_id]);
                    $pdo->prepare("INSERT INTO fuel_stock_request_audit (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes) VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)")
                        ->execute([$req_id, $me['id'], $role, "Rejected by {$me['name']}. Reason: {$manager_notes}"]);
                    $pdo->commit();
                    $_SESSION['success'] = 'Fuel request rejected successfully.';
                } else {
                    $_SESSION['error'] = 'Request not found or already processed.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
    }
    header('Location: manager_inventory_stock_requests.php?main_tab=fuel');
    exit;
}

// Fetch fuel requests
$fuel_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT fsr.*, u.name AS staff_name, m.name AS manager_name
        FROM fuel_stock_requests fsr
        JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        WHERE fsr.station_id = ?
        ORDER BY CASE fsr.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 WHEN 'Rejected' THEN 3 END, fsr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $fuel_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$fuel_pending = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Pending'));

$active_main_tab = $_GET['main_tab'] ?? 'merch';
$active_sub_tab  = $_GET['tab'] ?? 'pending';

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

include __DIR__ . "/../partials/header.php";
?>
<style>
/* ── Table wrapper ── */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto; }
/* ── Table ── */
.po-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
.po-table thead th { background:#002F70; color:#fff; padding:12px 14px; text-align:left; font-weight:600; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:11px 14px; vertical-align:middle; color:#333; }
/* ── Status badges — plain text, no background color ── */
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; color:#333; }
.badge-pending          { color:#002F70; }
.badge-pending-delivery { color:#002F70; }
.badge-approved         { color:#28a745; }
.badge-rejected         { color:#dc3545; }
.badge-other            { color:#6c757d; }
/* Legacy sbadge aliases (used in JS-rendered rows) */
.sbadge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; }
.sbadge-pending              { color:#002F70; }
.sbadge-approved             { color:#28a745; }
.sbadge-rejected             { color:#dc3545; }
.sbadge-forwarded-to-admin   { color:#6c757d; }
/* ── Action buttons ── */
.btn-action { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:0.82rem; font-weight:600; text-decoration:none; transition:opacity 0.2s; white-space:nowrap; margin-bottom:3px; }
.btn-action:hover { opacity:0.85; }
.btn-approve { background:#28a745; color:#fff; }
.btn-reject  { background:#dc3545; color:#fff; }
.btn-view    { background:#6c757d; color:#fff; }
.btn-primary { background:#002F70; color:#fff; }
/* ── Page header ── */
.page-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:6px; }
.page-head h1 { margin:0 0 2px; font-size:1.4rem; font-weight:700; color:#002F70; }
.page-head .sub { font-size:0.8rem; color:#6c757d; }
/* ── Alerts ── */
.inv-alert { display:flex; align-items:center; gap:10px; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; font-weight:500; }
.inv-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.inv-alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
/* ── Empty state ── */
.empty-state { text-align:center; padding:70px 20px; color:#666; }
.empty-state i { font-size:3.5rem; color:#002F70; margin-bottom:18px; display:block; opacity:0.5; }
.empty-state h3 { font-size:1.2rem; font-weight:700; color:#333; margin:0 0 8px; }
.empty-state p { font-size:0.9rem; max-width:420px; margin:0 auto; line-height:1.6; }
/* ── Main type tabs ── */
.main-tab-nav { display:flex; gap:0; border-bottom:2px solid #e9ecef; margin-bottom:22px; }
.main-tab-btn { padding:11px 26px; background:none; border:none; border-bottom:3px solid transparent; font-size:14px; font-weight:600; color:#6c757d; cursor:pointer; margin-bottom:-2px; transition:all .15s; display:flex; align-items:center; gap:7px; }
.main-tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.main-tab-btn:hover  { color:#002F70; }
.main-tab-badge { background:#dc3545; color:#fff; border-radius:10px; padding:1px 7px; font-size:11px; }
/* ── Sub tabs ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e9ecef; margin-bottom:20px; }
.tab-btn { padding:10px 22px; background:none; border:none; border-bottom:3px solid transparent; font-size:14px; font-weight:600; color:#6c757d; cursor:pointer; margin-bottom:-2px; transition:all .15s; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }
/* ── Card wrapper (kept for layout) ── */
.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); margin-bottom:24px; overflow:hidden; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body { padding:0; }
/* ── Modals ── */
.modal-overlay { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; margin:0; padding:0; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 32px rgba(0,0,0,0.18); animation:modalIn .2s ease; position:relative; z-index:10000; padding:28px; }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #e9ecef; }
.modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888; line-height:1; padding:0 4px; }
.modal-close:hover { color:#333; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; padding-top:14px; border-top:1px solid #e9ecef; }
.field-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.field-group label { display:block; margin-bottom:5px; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.field-group input[type=text], .field-group input[type=number], .field-group textarea { width:100%; padding:9px 11px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.field-group input[readonly] { background:#f8f9fa; color:#6c757d; }
.field-group input[type=number]:focus, .field-group textarea:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.12); }
.field-group textarea { resize:vertical; }
.qty-preview { display:flex; align-items:center; gap:10px; background:#f0f4ff; border:1px solid #c5d3f0; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:13px; }
.qty-preview .arrow { color:#002F70; font-size:16px; font-weight:700; }
.qty-old { color:#6c757d; text-decoration:line-through; }
.qty-new { color:#002F70; font-weight:700; font-size:15px; }
.info-box { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#002F70; line-height:1.6; }
/* ── Summary cards ── */
.summary-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
.sum-card { flex:1; min-width:110px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.sum-card-num { font-size:26px; font-weight:800; color:#002F70; }
.sum-card-lbl { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
.sum-approved .sum-card-num { color:#28a745; }
.sum-rejected .sum-card-num { color:#dc3545; }
/* ── Actions cell: stacked buttons ── */
.actions-cell { display:flex; flex-direction:column; gap:4px; min-width:110px; }
.actions-cell .btn-action { width:100%; justify-content:center; margin-bottom:0; }
</style>

<div class="page-head">
    <div>
        <h1><i class="fas fa-shopping-cart"></i> Purchase Requests</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Review, approve or reject staff purchase requests</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($flash_success): ?>
<div class="inv-alert inv-alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="inv-alert inv-alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_error); ?></div>
<?php endif; ?>
<div id="flashMsg" style="display:none;" class="inv-alert"></div>

<!-- ── MAIN TYPE TABS ── -->
<div class="main-tab-nav">
    <button class="main-tab-btn <?php echo $active_main_tab === 'merch' ? 'active' : ''; ?>" onclick="switchMainTab('merch', this)">
        <i class="fas fa-boxes"></i> Merchandise Stock Requests
        <span class="main-tab-badge" id="merchMainBadge" style="display:none;"></span>
    </button>
    <button class="main-tab-btn <?php echo $active_main_tab === 'fuel' ? 'active' : ''; ?>" onclick="switchMainTab('fuel', this)">
        <i class="fas fa-gas-pump"></i> Fuel Stock Requests
        <?php if ($fuel_pending > 0): ?>
            <span class="main-tab-badge"><?php echo $fuel_pending; ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════
     MERCHANDISE TAB
     ══════════════════════════════════════════════════════════ -->
<div id="tab-merch" style="display:<?php echo $active_main_tab === 'merch' ? 'block' : 'none'; ?>;">

    <!-- Sub tabs: Pending / History -->
    <div class="tab-nav">
        <button class="tab-btn active" id="mSubPendingBtn" onclick="switchMerchSub('pending', this)">
            <i class="fas fa-clock"></i> Pending
            <span id="pendingBadge" style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;display:none;"></span>
        </button>
        <button class="tab-btn" id="mSubHistoryBtn" onclick="switchMerchSub('history', this)">
            <i class="fas fa-history"></i> History
        </button>
    </div>

    <!-- Pending -->
    <div id="merch-pending-tab">
        <div class="inv-card">
            <div class="inv-card-head">
                <div class="inv-card-title"><i class="fas fa-clock"></i> Pending Requests</div>
                <span style="font-size:12px;color:#6c757d;">Click <strong>Approve</strong> or <strong>Reject</strong> to process each request.</span>
            </div>
            <div class="inv-card-body">
                <div class="po-table-wrap">
                    <table class="po-table" id="pendingTable">
                        <thead>
                            <tr>
                                <th>#</th><th>Date</th><th>Staff</th><th>SKU</th><th>Product</th>
                                <th>Category</th><th>Current Stock</th><th>Qty Requested</th>
                                <th>Remarks</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingBody">
                            <tr><td colspan="10" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- History -->
    <div id="merch-history-tab" style="display:none;">
        <div class="inv-card">
            <div class="inv-card-head">
                <div class="inv-card-title"><i class="fas fa-history"></i> Processed Requests</div>
            </div>
            <div class="inv-card-body">
                <div class="po-table-wrap">
                    <table class="po-table" id="historyTable">
                        <thead>
                            <tr>
                                <th>#</th><th>Date</th><th>Staff</th><th>Product</th>
                                <th>Qty Requested</th><th>Qty Approved</th><th>Status</th>
                                <th>PO Number</th><th>Pipeline</th><th>Manager Notes</th><th>Processed On</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody">
                            <tr><td colspan="10" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     FUEL TAB
     ══════════════════════════════════════════════════════════ -->
<?php
$fuel_pending_rows  = array_filter($fuel_requests, fn($r) => $r['status'] === 'Pending');
$fuel_history_rows  = array_filter($fuel_requests, fn($r) => $r['status'] !== 'Pending');
$fuel_approved_cnt  = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Approved'));
$fuel_rejected_cnt  = count(array_filter($fuel_requests, fn($r) => $r['status'] === 'Rejected'));
?>
<div id="tab-fuel" style="display:<?php echo $active_main_tab === 'fuel' ? 'block' : 'none'; ?>;">

    <!-- Sub tabs: Pending / History -->
    <div class="tab-nav">
        <button class="tab-btn active" id="fSubPendingBtn" onclick="switchFuelSub('pending', this)">
            <i class="fas fa-clock"></i> Pending
            <?php if ($fuel_pending > 0): ?>
                <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"><?php echo $fuel_pending; ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn" id="fSubHistoryBtn" onclick="switchFuelSub('history', this)">
            <i class="fas fa-history"></i> History
        </button>
    </div>

    <!-- Fuel Pending -->
    <div id="fuel-pending-tab">
        <div class="inv-card" style="padding:0;">
            <div class="po-table-wrap">
                <table class="po-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Staff</th><th>Fuel Type</th>
                            <th>Current Level</th><th>Requested (L)</th><th>Remarks</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fuel_pending_rows as $req):
                            $stockClr = in_array($req['stock_status'] ?? '', ['OUT OF STOCK','CRITICAL']) ? '#dc3545' : '#6c757d';
                        ?>
                        <tr>
                            <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                            <td style="font-size:12px;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                            <td><strong><?php echo htmlspecialchars($req['fuel_type']); ?></strong></td>
                            <td>
                                <?php echo number_format($req['current_level'], 2); ?> L
                                <span style="color:<?php echo $stockClr; ?>;font-size:11px;font-weight:700;display:block;"><?php echo htmlspecialchars($req['stock_status'] ?? ''); ?></span>
                            </td>
                            <td style="font-weight:700;text-align:center;"><?php echo number_format($req['requested_liters'], 2); ?></td>
                            <td style="font-size:12px;color:#6c757d;max-width:160px;">
                                <?php echo $req['remarks'] ? htmlspecialchars($req['remarks']) : '<span style="color:#adb5bd;">—</span>'; ?>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <button class="btn-action btn-approve" onclick="openFuelApprove(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['fuel_type'], ENT_QUOTES); ?>', <?php echo $req['requested_liters']; ?>)">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn-action btn-reject" onclick="openFuelReject(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['fuel_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fuel_pending_rows)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:#6c757d;">
                            <i class="fas fa-check-circle" style="font-size:2em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>
                            <strong>All caught up!</strong><br>No pending fuel stock requests.
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Fuel History -->
    <div id="fuel-history-tab" style="display:none;">
        <div class="inv-card" style="padding:0;">
            <div class="po-table-wrap">
                <table class="po-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Staff</th><th>Fuel Type</th>
                            <th>Requested (L)</th><th>Approved (L)</th><th>Status</th>
                            <th>Manager Notes</th><th>Processed On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fuel_history_rows as $req):
                            $st  = $req['status'] ?? 'Unknown';
                            $badgeCls = 'status-badge badge-' . strtolower($st);
                        ?>
                        <tr>
                            <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                            <td style="font-size:12px;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                            <td><strong><?php echo htmlspecialchars($req['fuel_type']); ?></strong></td>
                            <td style="text-align:center;color:#6c757d;"><?php echo number_format($req['requested_liters'], 2); ?></td>
                            <td style="text-align:center;">
                                <?php if ($req['approved_liters'] !== null): ?>
                                    <strong style="color:#28a745;font-size:14px;"><?php echo number_format($req['approved_liters'], 2); ?></strong>
                                <?php else: ?><span style="color:#adb5bd;">—</span><?php endif; ?>
                            </td>
                            <td><span class="<?php echo $badgeCls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                            <td style="font-size:12px;color:#495057;max-width:200px;">
                                <?php echo $req['manager_notes'] ? htmlspecialchars($req['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?>
                            </td>
                            <td style="font-size:12px;color:#6c757d;">
                                <?php echo $req['processed_at'] ? date('M d, Y H:i', strtotime($req['processed_at'])) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fuel_history_rows)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:28px;color:#6c757d;">No processed fuel requests yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MERCHANDISE APPROVE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="approveModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Stock Request</div>
            <button class="modal-close" onclick="closeApprove()">&times;</button>
        </div>
        <div class="field-grid">
            <div class="field-group"><label>SKU</label><input type="text" id="appSku" readonly></div>
            <div class="field-group"><label>Product</label><input type="text" id="appName" readonly></div>
            <div class="field-group"><label>Category</label><input type="text" id="appCategory" readonly></div>
            <div class="field-group"><label>Requested By</label><input type="text" id="appStaff" readonly></div>
            <div class="field-group"><label>Current Stock</label><input type="text" id="appCurStock" readonly></div>
            <div class="field-group"><label>Qty Requested by Staff</label><input type="text" id="appReqQty" readonly></div>
        </div>
        <div class="qty-preview">
            <i class="fas fa-boxes" style="color:#002F70;"></i>
            <span>Staff requested:</span><span class="qty-old" id="appPreviewOld">—</span>
            <span class="arrow">→</span>
            <span>Manager approves:</span><span class="qty-new" id="appPreviewNew">—</span>
            <span style="color:#6c757d;font-size:11px;">units</span>
        </div>
        <div class="field-grid">
            <div class="field-group" style="grid-column:1/-1;">
                <label>Approved Quantity <span style="color:#dc3545;">*</span></label>
                <input type="number" id="appQty" min="1" required placeholder="Enter approved quantity..."
                       style="font-size:16px;font-weight:700;color:#002F70;">
            </div>
        </div>
        <div class="field-group" style="margin-bottom:16px;">
            <label>Manager Notes</label>
            <textarea id="appNotes" rows="3" placeholder="Optional: reason for adjustment, notes for staff..."></textarea>
        </div>
        <div class="info-box">
            <i class="fas fa-info-circle"></i> <strong>On Approve:</strong><br>
            &bull; Request status &rarr; <strong>Forwarded to Admin</strong><br>
            &bull; Purchase Order auto-generated &rarr; Pending Admin Validation<br>
            &bull; Audit trail logged: Manager ID, qty, timestamp
        </div>
        <div id="appError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>
        <div class="modal-footer">
            <button type="button" onclick="closeApprove()" style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
            <button type="button" id="appSubmitBtn" onclick="submitApprove()" style="padding:9px 22px;background:#28a745;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-check"></i> Approve
            </button>
        </div>
    </div>
</div>

<!-- MERCHANDISE REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Stock Request</div>
            <button class="modal-close" onclick="closeReject()">&times;</button>
        </div>
        <div class="field-group" style="margin-bottom:8px;">
            <label>Product</label>
            <input type="text" id="rejName" readonly style="width:100%;padding:9px;border:1px solid #dee2e6;border-radius:6px;background:#f8f9fa;color:#6c757d;">
        </div>
        <div class="field-group" style="margin-bottom:16px;">
            <label>Rejection Reason <span style="color:#dc3545;">*</span></label>
            <textarea id="rejNotes" rows="4" placeholder="Required: explain why this request is being rejected..."
                      style="width:100%;padding:9px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;resize:vertical;"></textarea>
        </div>
        <div id="rejError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>
        <div class="modal-footer">
            <button type="button" onclick="closeReject()" style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
            <button type="button" id="rejSubmitBtn" onclick="submitReject()" style="padding:9px 22px;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-times"></i> Reject
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     FUEL APPROVE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="fuelApproveModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head" style="background:#28a745;border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:18px 24px;">
            <div class="modal-title" style="color:#fff;"><i class="fas fa-check-circle"></i> Approve Fuel Request</div>
            <button class="modal-close" onclick="closeFuelApprove()" style="color:#fff;opacity:.8;">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="form_type" value="fuel">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="fuelApproveId">
            <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#155724;margin-bottom:6px;">Fuel Type</div>
                <div style="font-weight:700;color:#155724;font-size:16px;" id="fuelApproveFuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Approved Liters <span style="color:red;">*</span></label>
                <input type="number" name="approved_liters" id="fuelApproveLiters" step="0.01" min="0.01" required
                       style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Manager Notes</label>
                <textarea name="manager_notes" rows="3" placeholder="Optional notes..."
                          style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" style="background:#28a745;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;cursor:pointer;"><i class="fas fa-check"></i> Confirm Approve</button>
                <button type="button" onclick="closeFuelApprove()" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- FUEL REJECT MODAL -->
<div class="modal-overlay" id="fuelRejectModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head" style="background:#dc3545;border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:18px 24px;">
            <div class="modal-title" style="color:#fff;"><i class="fas fa-times-circle"></i> Reject Fuel Request</div>
            <button class="modal-close" onclick="closeFuelReject()" style="color:#fff;opacity:.8;">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="form_type" value="fuel">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="fuelRejectId">
            <div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#721c24;margin-bottom:6px;">Fuel Type</div>
                <div style="font-weight:700;color:#721c24;font-size:16px;" id="fuelRejectFuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Rejection Reason <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="3" required placeholder="Explain why this request is rejected..."
                          style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" style="background:#dc3545;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;cursor:pointer;"><i class="fas fa-times"></i> Confirm Reject</button>
                <button type="button" onclick="closeFuelReject()" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
var currentRequestId = null;

// ── Move modals to body on load ───────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", function() {
    ["approveModal","rejectModal","fuelApproveModal","fuelRejectModal"].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    loadMerchRequests();
});

// ── Main tab switching ────────────────────────────────────────────────────────
function switchMainTab(tab, btn) {
    document.getElementById("tab-merch").style.display = tab === "merch" ? "block" : "none";
    document.getElementById("tab-fuel").style.display  = tab === "fuel"  ? "block" : "none";
    document.querySelectorAll(".main-tab-btn").forEach(function(b) { b.classList.remove("active"); });
    btn.classList.add("active");
    if (tab === "merch") loadMerchRequests();
}

// ── Merch sub-tab switching ───────────────────────────────────────────────────
function switchMerchSub(tab, btn) {
    document.getElementById("merch-pending-tab").style.display = tab === "pending" ? "block" : "none";
    document.getElementById("merch-history-tab").style.display = tab === "history" ? "block" : "none";
    document.querySelectorAll(".tab-btn").forEach(function(b) { b.classList.remove("active"); });
    btn.classList.add("active");
}

// ── Fuel sub-tab switching ────────────────────────────────────────────────────
function switchFuelSub(tab, btn) {
    document.getElementById("fuel-pending-tab").style.display = tab === "pending" ? "block" : "none";
    document.getElementById("fuel-history-tab").style.display = tab === "history" ? "block" : "none";
    document.querySelectorAll("#tab-fuel .tab-btn").forEach(function(b) { b.classList.remove("active"); });
    btn.classList.add("active");
}

// ── Load merchandise requests via AJAX ────────────────────────────────────────
function loadMerchRequests() {
    fetch("../backend/api/stock_request.php?action=get_requests")
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var requests = data.requests || [];
        var pending  = requests.filter(function(r) { return r.status === "Pending"; });
        var history  = requests.filter(function(r) { return r.status !== "Pending"; });

        var badge = document.getElementById("pendingBadge");
        if (pending.length > 0) { badge.textContent = pending.length; badge.style.display = "inline"; }
        else { badge.style.display = "none"; }

        var mainBadge = document.getElementById("merchMainBadge");
        if (pending.length > 0) { mainBadge.textContent = pending.length; mainBadge.style.display = "inline"; }
        else { mainBadge.style.display = "none"; }

        renderPending(pending);
        renderHistory(history);
    })
    .catch(function() {
        document.getElementById("pendingBody").innerHTML = "<tr><td colspan=\"10\" style=\"text-align:center;padding:30px;color:#dc3545;\">Error loading requests.</td></tr>";
    });
}

function renderPending(rows) {
    var tbody = document.getElementById("pendingBody");
    if (rows.length === 0) {
        tbody.innerHTML = "<tr><td colspan=\"10\" style=\"text-align:center;padding:40px;color:#6c757d;\"><i class=\"fas fa-check-circle\" style=\"font-size:2em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;\"></i><strong>All caught up!</strong><br>No pending stock requests.</td></tr>";
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        return "<tr>" +
            "<td style=\"color:#6c757d;font-size:12px;\">#" + r.id + "</td>" +
            "<td style=\"font-size:12px;\">" + fmtDate(r.created_at) + "</td>" +
            "<td>" + esc(r.staff_name || "") + "</td>" +
            "<td><code style=\"font-size:11px;\">" + esc(r.item_sku || "") + "</code></td>" +
            "<td><strong>" + esc(r.item_name) + "</strong></td>" +
            "<td style=\"font-size:12px;\">" + esc(r.item_category || "") + "</td>" +
            "<td style=\"text-align:center;\">" + r.current_stock + "</td>" +
            "<td style=\"text-align:center;font-weight:700;color:#002F70;font-size:15px;\">" + r.requested_quantity + "</td>" +
            "<td style=\"font-size:12px;color:#6c757d;max-width:150px;\">" + (r.remarks ? esc(r.remarks) : "<span style=\"color:#adb5bd;\">—</span>") + "</td>" +
            "<td><div class=\"actions-cell\">" +
                "<button class=\"btn-action btn-approve\" onclick=\"openApprove(" + r.id + ",\'" + esc(r.item_name) + "\',\'" + esc(r.item_sku||"") + "\',\'" + esc(r.item_category||"") + "\'," + r.current_stock + "," + r.requested_quantity + ",\'" + esc(r.staff_name||"") + "\')\"><i class=\"fas fa-check\"></i> Approve</button>" +
                "<button class=\"btn-action btn-reject\" onclick=\"openReject(" + r.id + ",\'" + esc(r.item_name) + "\')\"><i class=\"fas fa-times\"></i> Reject</button>" +
            "</div></td>" +
        "</tr>";
    }).join("");
}

function renderHistory(rows) {
    var tbody = document.getElementById("historyBody");
    if (rows.length === 0) {
        tbody.innerHTML = "<tr><td colspan=\"11\" style=\"text-align:center;padding:28px;color:#6c757d;\">No processed requests yet.</td></tr>";
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        var st  = r.status || "Unknown";
        var badgeCls = "status-badge badge-" + st.toLowerCase().replace(/ /g,"-");
        var qtyApproved = (r.approved_quantity !== null && r.approved_quantity !== undefined)
            ? "<strong style=\"color:#28a745;font-size:14px;\">" + r.approved_quantity + "</strong>" +
              (parseInt(r.approved_quantity) !== parseInt(r.requested_quantity) ? " <span style=\"font-size:10px;color:#6c757d;\">adjusted</span>" : "")
            : "<span style=\"color:#adb5bd;\">—</span>";
        var poCol = r.po_number ? "<code style=\"font-size:11px;color:#002F70;\">" + esc(r.po_number) + "</code>" : "<span style=\"color:#adb5bd;\">—</span>";

        // Pipeline status column
        var pipeCol = "";
        if (r.stock_in_done == 1 || r.stock_in_done === "1") {
            pipeCol = "<span class=\"status-badge badge-approved\"><i class=\"fas fa-check-double\"></i> Stocked In</span>";
            if (r.stock_in_at) pipeCol += "<br><small style=\"color:#6c757d;\">" + fmtDate(r.stock_in_at) + "</small>";
        } else if ((r.admin_finalized == 1 || r.admin_finalized === "1") && (r.delivery_validated == 1 || r.delivery_validated === "1")) {
            pipeCol = "<span class=\"status-badge badge-other\"><i class=\"fas fa-dolly\"></i> Awaiting Stock-In</span>";
        } else if (r.admin_finalized == 1 || r.admin_finalized === "1") {
            pipeCol = "<span class=\"status-badge badge-pending\"><i class=\"fas fa-clipboard-check\"></i> Awaiting Delivery</span>";
        } else if (r.po_number) {
            pipeCol = "<span class=\"status-badge badge-other\"><i class=\"fas fa-file-invoice\"></i> PO Pending Admin</span>";
        } else if (st === "Rejected") {
            pipeCol = "<span class=\"status-badge badge-rejected\"><i class=\"fas fa-times\"></i> Rejected</span>";
        } else {
            pipeCol = "<span style=\"color:#adb5bd;\">—</span>";
        }

        return "<tr>" +
            "<td style=\"color:#6c757d;font-size:12px;\">#" + r.id + "</td>" +
            "<td style=\"font-size:12px;\">" + fmtDate(r.created_at) + "</td>" +
            "<td>" + esc(r.staff_name || "") + "</td>" +
            "<td><strong>" + esc(r.item_name) + "</strong></td>" +
            "<td style=\"text-align:center;color:#6c757d;\">" + r.requested_quantity + "</td>" +
            "<td style=\"text-align:center;\">" + qtyApproved + "</td>" +
            "<td><span class=\"" + badgeCls + "\">" + esc(st) + "</span></td>" +
            "<td style=\"font-size:11px;\">" + poCol + "</td>" +
            "<td>" + pipeCol + "</td>" +
            "<td style=\"font-size:12px;color:#495057;max-width:180px;\">" + (r.manager_notes ? esc(r.manager_notes) : "<span style=\"color:#adb5bd;\">—</span>") + "</td>" +
            "<td style=\"font-size:12px;color:#6c757d;\">" + (r.processed_at ? fmtDate(r.processed_at) : fmtDate(r.updated_at || r.created_at)) + "</td>" +
        "</tr>";
    }).join("");
}

// ── Merchandise Approve modal ─────────────────────────────────────────────────
function openApprove(id, name, sku, category, curStock, reqQty, staffName) {
    currentRequestId = id;
    document.getElementById("appSku").value      = sku;
    document.getElementById("appName").value     = name;
    document.getElementById("appCategory").value = category;
    document.getElementById("appStaff").value    = staffName;
    document.getElementById("appCurStock").value = curStock + " units";
    document.getElementById("appReqQty").value   = reqQty + " units";
    document.getElementById("appQty").value      = reqQty;
    document.getElementById("appPreviewOld").textContent = reqQty;
    document.getElementById("appPreviewNew").textContent = reqQty;
    document.getElementById("appNotes").value    = "";
    document.getElementById("appError").style.display = "none";
    document.getElementById("appSubmitBtn").disabled = false;
    document.getElementById("appSubmitBtn").innerHTML = "<i class=\"fas fa-check\"></i> Approve";
    document.getElementById("approveModal").classList.add("open");
    setTimeout(function() { document.getElementById("appQty").focus(); }, 100);
}
function closeApprove() { document.getElementById("approveModal").classList.remove("open"); }
document.getElementById("appQty").addEventListener("input", function() {
    var v = parseInt(this.value) || 0;
    document.getElementById("appPreviewNew").textContent = v > 0 ? v : "—";
});
document.getElementById("approveModal").addEventListener("click", function(e) { if (e.target === this) closeApprove(); });

function submitApprove() {
    var qty   = parseInt(document.getElementById("appQty").value) || 0;
    var notes = document.getElementById("appNotes").value.trim();
    if (qty <= 0) { showErr("appError", "Please enter a valid approved quantity."); return; }

    var btn = document.getElementById("appSubmitBtn");
    btn.disabled = true;
    btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Processing...";
    document.getElementById("appError").style.display = "none";

    fetch("../backend/api/stock_request.php?action=approve", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: currentRequestId, approved_quantity: qty, manager_notes: notes })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeApprove();
            showFlash("success", "&#10003; Request #" + currentRequestId + " approved. " + (res.po_number ? "PO <strong>" + res.po_number + "</strong> generated." : ""));
            loadMerchRequests();
        } else {
            showErr("appError", res.message || "Failed to approve.");
            btn.disabled = false;
            btn.innerHTML = "<i class=\"fas fa-check\"></i> Approve";
        }
    })
    .catch(function() {
        showErr("appError", "Network error. Please try again.");
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fas fa-check\"></i> Approve";
    });
}

// ── Merchandise Reject modal ──────────────────────────────────────────────────
function openReject(id, name) {
    currentRequestId = id;
    document.getElementById("rejName").value  = name;
    document.getElementById("rejNotes").value = "";
    document.getElementById("rejError").style.display = "none";
    document.getElementById("rejSubmitBtn").disabled = false;
    document.getElementById("rejSubmitBtn").innerHTML = "<i class=\"fas fa-times\"></i> Reject";
    document.getElementById("rejectModal").classList.add("open");
    setTimeout(function() { document.getElementById("rejNotes").focus(); }, 100);
}
function closeReject() { document.getElementById("rejectModal").classList.remove("open"); }
document.getElementById("rejectModal").addEventListener("click", function(e) { if (e.target === this) closeReject(); });

function submitReject() {
    var notes = document.getElementById("rejNotes").value.trim();
    if (!notes) { showErr("rejError", "Rejection reason is required."); return; }

    var btn = document.getElementById("rejSubmitBtn");
    btn.disabled = true;
    btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Processing...";
    document.getElementById("rejError").style.display = "none";

    fetch("../backend/api/stock_request.php?action=reject", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: currentRequestId, manager_notes: notes })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeReject();
            showFlash("success", "&#10003; Request #" + currentRequestId + " rejected.");
            loadMerchRequests();
        } else {
            showErr("rejError", res.message || "Failed to reject.");
            btn.disabled = false;
            btn.innerHTML = "<i class=\"fas fa-times\"></i> Reject";
        }
    })
    .catch(function() {
        showErr("rejError", "Network error. Please try again.");
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fas fa-times\"></i> Reject";
    });
}

// ── Fuel modal openers ────────────────────────────────────────────────────────
function openFuelApprove(id, fuel, liters) {
    document.getElementById("fuelApproveId").value = id;
    document.getElementById("fuelApproveFuel").textContent = fuel;
    document.getElementById("fuelApproveLiters").value = "";
    document.getElementById("fuelApproveModal").classList.add("open");
    setTimeout(function() { document.getElementById("fuelApproveLiters").focus(); }, 100);
}
function closeFuelApprove() { document.getElementById("fuelApproveModal").classList.remove("open"); }
document.getElementById("fuelApproveModal").addEventListener("click", function(e) { if (e.target === this) closeFuelApprove(); });

function openFuelReject(id, fuel) {
    document.getElementById("fuelRejectId").value = id;
    document.getElementById("fuelRejectFuel").textContent = fuel;
    document.getElementById("fuelRejectModal").classList.add("open");
}
function closeFuelReject() { document.getElementById("fuelRejectModal").classList.remove("open"); }
document.getElementById("fuelRejectModal").addEventListener("click", function(e) { if (e.target === this) closeFuelReject(); });

// ── Helpers ───────────────────────────────────────────────────────────────────
function showFlash(type, msg) {
    var el = document.getElementById("flashMsg");
    el.className = "inv-alert inv-alert-" + type;
    el.innerHTML = "<i class=\"fas fa-" + (type === "success" ? "check-circle" : "times-circle") + "\"></i><span>" + msg + "</span>";
    el.style.display = "flex";
    setTimeout(function() { el.style.display = "none"; }, 6000);
}
function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = "block";
}
function esc(str) {
    return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");
}
function fmtDate(ds) {
    if (!ds) return "—";
    var d = new Date(ds);
    return d.toLocaleDateString("en-PH", {month:"short",day:"numeric",year:"numeric"}) + " " +
           d.toLocaleTimeString("en-PH", {hour:"2-digit",minute:"2-digit"});
}
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") { closeApprove(); closeReject(); closeFuelApprove(); closeFuelReject(); }
});
</script>

<?php include __DIR__ . "/../partials/footer.php"; ?>
