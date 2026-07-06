<?php
$page_id = 'admin_request_data_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi_total = 0; $kpi_pending = 0; $kpi_approved = 0; $kpi_rejected = 0;
try {
    $s = $pdo->query("SELECT COUNT(*) FROM master_data_requests"); $kpi_total = (int)$s->fetchColumn();
    $s = $pdo->query("SELECT COUNT(*) FROM master_data_requests WHERE status = 'Pending'"); $kpi_pending = (int)$s->fetchColumn();
    $s = $pdo->query("SELECT COUNT(*) FROM master_data_requests WHERE status = 'Approved'"); $kpi_approved = (int)$s->fetchColumn();
    $s = $pdo->query("SELECT COUNT(*) FROM master_data_requests WHERE status = 'Rejected'"); $kpi_rejected = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Filter Options (staff & managers from DB, not hardcoded) ─────────────────
$staff_list = [];
try {
    $s = $pdo->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),' '), u.username, 'Unknown') AS name
        FROM users u
        INNER JOIN master_data_requests r ON r.requested_by = u.id
        ORDER BY name
    ");
    $s->execute();
    $staff_list = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$manager_list = [];
try {
    $s = $pdo->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),' '), u.username, 'Unknown') AS name
        FROM users u
        INNER JOIN master_data_requests r ON r.reviewed_by = u.id
        ORDER BY name
    ");
    $s->execute();
    $manager_list = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_category  = trim($_GET['category']  ?? '');
$f_status    = trim($_GET['status']    ?? '');
$f_staff     = trim($_GET['staff']     ?? '');
$f_manager   = trim($_GET['manager']   ?? '');
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$f_search    = trim($_GET['search']    ?? '');

$where  = "WHERE 1=1";
$params = [];

if ($f_category !== '')  { $where .= " AND r.category = ?";             $params[] = $f_category; }
if ($f_status !== '')    { $where .= " AND r.status = ?";               $params[] = $f_status; }
if ($f_staff !== '')     { $where .= " AND r.requested_by = ?";         $params[] = (int)$f_staff; }
if ($f_manager !== '')   { $where .= " AND r.reviewed_by = ?";          $params[] = (int)$f_manager; }
if ($f_date_from !== '') { $where .= " AND DATE(r.created_at) >= ?";    $params[] = $f_date_from; }
if ($f_date_to !== '')   { $where .= " AND DATE(r.created_at) <= ?";    $params[] = $f_date_to; }
if ($f_search !== '') {
    $where .= " AND (r.request_no LIKE ? OR r.data_payload LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%";
}

// ── Fetch rows ────────────────────────────────────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            r.*,
            COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),' '), u.username, 'Unknown Staff') AS requester_name,
            COALESCE(u.role, 'staff') AS requester_role,
            COALESCE(NULLIF(TRIM(CONCAT(rev.first_name,' ',rev.last_name)),' '), rev.username, '—') AS reviewer_name,
            st.name AS station_name
        FROM master_data_requests r
        LEFT JOIN users u   ON r.requested_by = u.id
        LEFT JOIN users rev ON r.reviewed_by  = rev.id
        LEFT JOIN stations st ON r.station_id = st.id
        $where
        ORDER BY r.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Admin Request Data Management query error: ' . $e->getMessage());
}

// ── Export ────────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['excel', 'csv'])) {
    $fn = 'master_data_requests_' . date('Ymd_His');
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$fn}.xls\"");
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$fn}.csv\"");
    }
    // UTF-8 BOM for Excel CSV compatibility
    if ($export === 'csv') echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Request No.', 'Category', 'Requested Item', 'Requested By', 'Reviewed By', 'Reviewed Date', 'Status', 'Rejection Reason']);
    foreach ($rows as $r) {
        $payload = json_decode($r['data_payload'] ?? '{}', true);
        $item = $payload['product_name'] ?? $payload['service_name'] ?? trim(($payload['vehicle_brand'] ?? '') . ' ' . ($payload['vehicle_model'] ?? ''));
        fputcsv($out, [
            $r['request_no'],
            $r['category'],
            trim($item) ?: '—',
            $r['requester_name'],
            $r['reviewer_name'],
            (!empty($r['updated_at']) && $r['status'] !== 'Pending') ? date('M d, Y h:i A', strtotime($r['updated_at'])) : '—',
            $r['status'],
            $r['rejection_reason'] ?? '—',
        ]);
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #e2e8f0;}
.page-head.txn-page-head h1{font-size:22px !important;font-weight:700 !important;color:var(--petron-blue,#00264D) !important;margin:0 !important;display:flex;align-items:center;gap:8px;}
.page-head.txn-page-head .sub{font-size:13px;color:#64748b;margin-top:4px;}
/* KPI Cards */
.txn-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px;}
.txn-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:transform .15s,box-shadow .15s;}
.txn-kpi-card:hover{transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,.08);}
.txn-kpi-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:4px;display:flex;align-items:center;gap:6px;}
.txn-kpi-val{font-size:24px;font-weight:800;color:#002F70;line-height:1.1;}
.txn-kpi-card.blue   .txn-kpi-val{color:#0369a1;}
.txn-kpi-card.orange .txn-kpi-val{color:#ea580c;}
.txn-kpi-card.green  .txn-kpi-val{color:#16a34a;}
.txn-kpi-card.danger .txn-kpi-val{color:#dc2626;}
/* Filters */
.filters-form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
.filters-form>div{display:flex;flex-direction:column;gap:4px;}
.filters-form label{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;}
.filters-form .inp{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;min-width:130px;transition:border-color .15s;}
.filters-form .inp:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none;background:#fff;}
.flt-btn-primary{background:#002F70 !important;color:#fff !important;border-color:#002F70 !important;}
.flt-btn-primary:hover{background:#001f4d !important;}
.flt-btn-reset{color:#6b7280 !important;border-color:#6b7280 !important;}
.flt-btn-reset:hover{background:#6b7280 !important;color:#fff !important;}
.flt-btn-excel{color:#1d6f42 !important;border-color:#1d6f42 !important;}
.flt-btn-excel:hover{background:#1d6f42 !important;color:#fff !important;}
.flt-btn-csv{color:#0369a1 !important;border-color:#0369a1 !important;}
.flt-btn-csv:hover{background:#0369a1 !important;color:#fff !important;}
.flt-btn-pdf{color:#dc2626 !important;border-color:#dc2626 !important;}
.flt-btn-pdf:hover{background:#dc2626 !important;color:#fff !important;}
@media print{
  .page-head .export-btns,.filters-form,.flt-btn{display:none !important;}
  .card{box-shadow:none !important;border:1px solid #ccc !important;}
  .t thead th{background:#002F70 !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{font-size:11px;}
}
/* Table */
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #e9ecef;background:#f8fafc;}
.card-title{font-size:13px;font-weight:700;color:#00264D;}
.t{width:100%;border-collapse:collapse;font-size:12px;text-align:left;}
.t thead th{background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:10px 14px;border-bottom:2px solid #001a3d;}
.t tbody td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle;}
.t tbody tr:hover td{background:#eff6ff;}
/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.badge-pending{background:#fef3c7;color:#d97706;}
.badge-approved{background:#d1fae5;color:#059669;}
.badge-rejected{background:#fee2e2;color:#dc2626;}
.badge-cat-vehicle{background:#eff6ff;color:#1d4ed8;}
.badge-cat-merchandise{background:#fdf2f8;color:#be185d;}
.badge-cat-service{background:#f5f3ff;color:#6d28d9;}
/* Payload list */
.payload-list{margin:0;padding:0;list-style:none;font-size:11px;}
.payload-list li{margin-bottom:3px;}
.payload-list strong{color:#475569;}
/* View modal */
.mdr-modal-bg{display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;}
.mdr-modal{background:#fff;border-radius:16px;width:100%;max-width:540px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);overflow:hidden;animation:mdrSlide .2s ease-out;}
@keyframes mdrSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.mdr-modal-hd{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #f1f5f9;background:#f8fafc;}
.mdr-modal-title{font-size:15px;font-weight:700;color:#0f172a;}
.mdr-modal-body{padding:20px;font-size:13px;color:#334155;max-height:70vh;overflow-y:auto;}
.mdr-modal-ft{padding:14px 20px;border-top:1px solid #f1f5f9;background:#f8fafc;display:flex;justify-content:flex-end;}
.mdr-kv{display:grid;grid-template-columns:140px 1fr;gap:8px 12px;align-items:start;}
.mdr-kv dt{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;padding-top:2px;}
.mdr-kv dd{margin:0;font-size:13px;color:#1e293b;}
</style>

<!-- Header -->
<div class="page-head txn-page-head">
    <div>
        <h1><i class="fas fa-clipboard-check"></i> Master Data Oversight</h1>
        <div class="sub">View-only oversight of all staff master data requests and manager actions across all categories.</div>
    </div>
    <div class="export-btns" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="flt-btn flt-btn-excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="flt-btn flt-btn-csv">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <button onclick="window.print()" class="flt-btn flt-btn-pdf">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-database"></i> Total Requests</div>
        <div class="txn-kpi-val"><?= number_format($kpi_total) ?></div>
    </div>
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Pending</div>
        <div class="txn-kpi-val"><?= number_format($kpi_pending) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Approved</div>
        <div class="txn-kpi-val"><?= number_format($kpi_approved) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle"></i> Rejected</div>
        <div class="txn-kpi-val"><?= number_format($kpi_rejected) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters-form">
    <div>
        <label>Category</label>
        <select name="category" class="inp">
            <option value="">All Categories</option>
            <option value="Merchandise Product" <?= $f_category === 'Merchandise Product' ? 'selected' : '' ?>>Merchandise Product</option>
            <option value="Service Type"        <?= $f_category === 'Service Type'        ? 'selected' : '' ?>>Service Type</option>
            <option value="Vehicle"             <?= $f_category === 'Vehicle'             ? 'selected' : '' ?>>Vehicle</option>
        </select>
    </div>
    <div>
        <label>Status</label>
        <select name="status" class="inp">
            <option value="">All Status</option>
            <option value="Pending"  <?= $f_status === 'Pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="Approved" <?= $f_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Rejected" <?= $f_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <div>
        <label>Staff</label>
        <select name="staff" class="inp">
            <option value="">All Staff</option>
            <?php foreach ($staff_list as $st): ?>
            <option value="<?= (int)$st['id'] ?>" <?= (int)$f_staff === (int)$st['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($st['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Manager</label>
        <select name="manager" class="inp">
            <option value="">All Managers</option>
            <?php foreach ($manager_list as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)$f_manager === (int)$m['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>" class="inp">
    </div>
    <div>
        <label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>" class="inp">
    </div>
    <div>
        <label>Search</label>
        <input type="text" name="search" value="<?= htmlspecialchars($f_search) ?>" class="inp" placeholder="Req No., keyword...">
    </div>
    <div style="flex-direction:row;gap:8px;">
        <button type="submit" class="flt-btn flt-btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="admin_request_data_management.php" class="flt-btn flt-btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-list-ul" style="margin-right:6px;"></i> Master Data Request Log (<?= count($rows) ?> records)</div>
    </div>
    <div style="width:100%;overflow-x:auto;">
        <table class="t">
            <thead>
                <tr>
                    <th>Request No.</th>
                    <th>Category</th>
                    <th>Requested Item</th>
                    <th>Requested By</th>
                    <th>Reviewed By</th>
                    <th>Reviewed Date</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">
                            <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:10px;"></i>
                            No requests found matching the filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $payload    = json_decode($row['data_payload'] ?? '{}', true) ?? [];

                        // Determine requested item label
                        if ($row['category'] === 'Merchandise Product') {
                            $item_label = $payload['product_name'] ?? '—';
                        } elseif ($row['category'] === 'Service Type') {
                            $item_label = $payload['service_name'] ?? '—';
                        } else {
                            $item_label = trim(($payload['vehicle_brand'] ?? '') . ' ' . ($payload['vehicle_model'] ?? '')) ?: '—';
                        }

                        // Category badge class
                        $catClass = 'badge';
                        if ($row['category'] === 'Vehicle')              $catClass .= ' badge-cat-vehicle';
                        elseif ($row['category'] === 'Merchandise Product') $catClass .= ' badge-cat-merchandise';
                        elseif ($row['category'] === 'Service Type')     $catClass .= ' badge-cat-service';

                        // Status badge
                        $statusClass = 'badge badge-pending';
                        if ($row['status'] === 'Approved') $statusClass = 'badge badge-approved';
                        elseif ($row['status'] === 'Rejected') $statusClass = 'badge badge-rejected';

                        // Reviewed date
                        $reviewed_date = '—';
                        if (!empty($row['updated_at']) && $row['status'] !== 'Pending') {
                            $reviewed_date = date('M d, Y', strtotime($row['updated_at']));
                        }
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['request_no']) ?></strong></td>
                        <td><span class="<?= $catClass ?>"><?= htmlspecialchars($row['category']) ?></span></td>
                        <td><?= htmlspecialchars($item_label) ?></td>
                        <td><?= htmlspecialchars($row['requester_name']) ?></td>
                        <td><?= htmlspecialchars($row['reviewer_name']) ?></td>
                        <td><?= $reviewed_date ?></td>
                        <td><span class="<?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td style="text-align:right;">
                            <?php
                            $modal_data = [
                                'request_no'    => $row['request_no'],
                                'category'      => $row['category'],
                                'status'        => $row['status'],
                                'item_label'    => $item_label,
                                'requester'     => $row['requester_name'],
                                'reviewer'      => $row['reviewer_name'],
                                'reviewed_date' => $reviewed_date,
                                'date_submitted'=> date('M d, Y h:i A', strtotime($row['created_at'])),
                                'rejection_reason' => $row['rejection_reason'] ?? '—',
                                'payload'       => $payload,
                            ];
                            ?>
                            <button class="flt-btn flt-btn-reset"
                                    style="height:26px;font-size:11px;padding:0 10px;"
                                    onclick="openMdrModal(<?= htmlspecialchars(json_encode($modal_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Modal (read-only) -->
<div id="mdrModalBg" class="mdr-modal-bg" onclick="if(event.target===this)closeMdrModal()">
    <div class="mdr-modal">
        <div class="mdr-modal-hd">
            <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-clipboard-check" style="color:#1d4ed8;font-size:15px;"></i>
            </div>
            <div class="mdr-modal-title">Request Details</div>
            <button onclick="closeMdrModal()" style="margin-left:auto;background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div class="mdr-modal-body">
            <dl class="mdr-kv" id="mdrKv"></dl>
        </div>
        <div class="mdr-modal-ft">
            <button onclick="closeMdrModal()" class="flt-btn flt-btn-reset"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<script>
function openMdrModal(d) {
    const bg = document.getElementById('mdrModalBg');
    const kv = document.getElementById('mdrKv');

    // Build payload detail lines
    let payloadHtml = '';
    const p = d.payload || {};
    const fields = {
        'Product Name':      p.product_name,
        'Service Name':      p.service_name,
        'Vehicle Brand':     p.vehicle_brand,
        'Vehicle Model':     p.vehicle_model,
        'Vehicle Type':      p.vehicle_type,
        'Fuel Type':         p.fuel_type,
        'Category':          p.category,
        'Unit':              p.unit,
        'Suggested Price':   p.suggested_price ? '₱' + Number(p.suggested_price).toFixed(2) : null,
        'Brand':             p.brand,
        'Estimated Duration':p.estimated_duration,
        'Remarks':           p.remarks,
    };
    for (const [k, v] of Object.entries(fields)) {
        if (v) payloadHtml += `<dt>${k}</dt><dd>${v}</dd>`;
    }

    const statusColors = { Pending: '#d97706', Approved: '#059669', Rejected: '#dc2626' };
    const statusBg     = { Pending: '#fef3c7', Approved: '#d1fae5', Rejected: '#fee2e2' };
    const sc = statusColors[d.status] || '#64748b';
    const sb = statusBg[d.status]     || '#f1f5f9';

    kv.innerHTML = `
        <dt>Request No.</dt><dd><strong>${d.request_no}</strong></dd>
        <dt>Category</dt><dd>${d.category}</dd>
        <dt>Requested Item</dt><dd><strong>${d.item_label}</strong></dd>
        ${payloadHtml}
        <dt>Requested By</dt><dd>${d.requester}</dd>
        <dt>Date Submitted</dt><dd>${d.date_submitted}</dd>
        <dt>Status</dt><dd><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:${sb};color:${sc};">${d.status}</span></dd>
        <dt>Reviewed By</dt><dd>${d.reviewer || '—'}</dd>
        <dt>Reviewed Date</dt><dd>${d.reviewed_date}</dd>
        ${d.status === 'Rejected' ? `<dt>Rejection Reason</dt><dd style="color:#dc2626;">${d.rejection_reason}</dd>` : ''}
    `;

    bg.style.display = 'flex';
}
function closeMdrModal() {
    document.getElementById('mdrModalBg').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
