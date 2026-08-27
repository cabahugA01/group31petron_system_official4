<?php
$page_id = 'manager_request_data_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$station_filter_sql = $station_id > 0 ? 'station_id = ?' : '1=1';
$station_filter_params = $station_id > 0 ? [$station_id] : [];

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: manager_dashboard.php'); exit;
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi_pending = 0;
$kpi_approved_today = 0;
$kpi_rejected_today = 0;
$kpi_total = 0;

try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Pending'");
    $s->execute($station_filter_params);
    $kpi_pending = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Approved' AND DATE(updated_at) = CURRENT_DATE()");
    $s->execute($station_filter_params);
    $kpi_approved_today = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql} AND status = 'Rejected' AND DATE(updated_at) = CURRENT_DATE()");
    $s->execute($station_filter_params);
    $kpi_rejected_today = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM master_data_requests WHERE {$station_filter_sql}");
    $s->execute($station_filter_params);
    $kpi_total = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
// ── AJAX JSON POLLING ENDPOINT FOR MASTER DATA REQUESTS ────────────────
if (isset($_GET['ajax_mdr']) && $_GET['ajax_mdr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'pending'        => $kpi_pending,
            'approved_today' => $kpi_approved_today,
            'rejected_today' => $kpi_rejected_today,
            'total'          => $kpi_total
        ]
    ]);
    exit;
}

$f_status = trim($_GET['status'] ?? '');
$f_category = trim($_GET['category'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$valid_statuses = ['Pending', 'Approved', 'Rejected'];
$valid_categories = ['Vehicle', 'Merchandise Product', 'Service Type'];
if ($f_status !== '' && !in_array($f_status, $valid_statuses, true)) $f_status = '';
if ($f_category !== '' && !in_array($f_category, $valid_categories, true)) $f_category = '';

$where = $station_id > 0 ? "WHERE r.station_id = ?" : "WHERE 1=1";
$params = $station_id > 0 ? [$station_id] : [];

if ($f_status !== '') {
    $where .= " AND r.status = ?";
    $params[] = $f_status;
}
if ($f_category !== '') {
    $where .= " AND r.category = ?";
    $params[] = $f_category;
}
if ($date_from !== '') {
    $where .= " AND DATE(r.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(r.created_at) <= ?";
    $params[] = $date_to;
}
if ($search !== '') {
    $where .= " AND (r.request_no LIKE ? OR r.data_payload LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.name LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Staff Encoder') as requester_name,
            COALESCE(CONCAT(rev.first_name, ' ', rev.last_name), rev.name, rev.username, '') as reviewer_name,
            st.name AS station_name
        FROM master_data_requests r
        LEFT JOIN users u ON r.requested_by = u.id
        LEFT JOIN users rev ON r.reviewed_by = rev.id
        LEFT JOIN stations st ON r.station_id = st.id
        $where
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Master Data Requests query error: ' . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.stock-page {
    padding: 0 !important;
    margin: 0 !important;
    width: 100%;
    box-sizing: border-box;
}
.stock-head {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
}
.stock-title {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .txn-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.txn-kpi-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 6px;
    display: flex;
    align-items: flex-start;
    gap: 6px;
    line-height: 1.3;
}
.txn-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.yellow .txn-kpi-val { color: #d97706; }
.txn-kpi-card.green .txn-kpi-val  { color: #16a34a; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }
.txn-kpi-card.blue .txn-kpi-val   { color: #0284c7; }

.filters-form {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
.filters-form > div {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.filters-form label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.filters-form .inp, .modal-body .inp {
    height: 36px;
    padding: 0 10px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.filters-form .inp:focus, .modal-body .inp:focus {
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 14px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    background: #fff;
    text-decoration: none;
}
.btn-primary {
    background: #002F70;
    color: #fff;
    border-color: #002F70;
}
.btn-primary:hover {
    background: #001f4d;
    border-color: #001f4d;
}
.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border-color: #cbd5e1;
}
.btn-secondary:hover {
    background: #e2e8f0;
}
.btn-success {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
}
.btn-success:hover {
    background: #15803d;
}
.btn-danger {
    background: #dc2626;
    color: #fff;
    border-color: #dc2626;
}
.btn-danger:hover {
    background: #b91c1c;
}

.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    width: 100%;
    box-sizing: border-box;
}
.table-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px;
    border-bottom: 1px solid #e9ecef;
    background: #f8fafc;
}
.table-card-title {
    font-size: 13px;
    font-weight: 700;
    color: #00264D;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.tbl-requests {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    text-align: left;
}
.tbl-requests th {
    background: #002F70;
    color: #fff;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.4px;
    padding: 9px 12px;
    border-bottom: 2px solid #001a3d;
    white-space: nowrap;
}
.tbl-requests td {
    padding: 9px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.tbl-requests tr:hover td {
    background: #eff6ff;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.badge-pending  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-approved { background: #d1fae5; color: #15803d; border: 1px solid #86efac; }
.badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

.badge-cat { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.badge-cat-vehicle { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-cat-merchandise { background: #fdf2f8; color: #be185d; border: 1px solid #fbcfe8; }
.badge-cat-service { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

/* Structured Payload Display */
.payload-struct {
    display: flex;
    flex-direction: column;
    gap: 3px;
    font-size: 11.5px;
    line-height: 1.4;
}
.payload-struct div {
    color: #334155;
}
.payload-struct strong {
    color: #475569;
    font-weight: 700;
}

/* Modal */
.modal-backdrop {
    display: none;
    position: fixed;
    top: 65px;
    left: 0;
    right: 0;
    bottom: 35px;
    z-index: 999999 !important;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.modal-content {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 620px;
    max-height: 100%;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    overflow: hidden !important;
    margin: auto;
    animation: modalSlideUp 0.2s ease-out;
}
@keyframes modalSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0 !important;
    height: 54px;
    box-sizing: border-box;
}
.modal-title {
    font-size: 15px;
    font-weight: 800;
    color: #002F70;
}
.modal-body {
    padding: 16px 20px;
    font-size: 13px;
    color: #334155;
    overflow-y: auto !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-shrink: 0 !important;
    height: 58px;
    box-sizing: border-box;
}
.edit-form-grid {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.edit-form-grid label {
    font-size: 11.5px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 2px;
    display: block;
}
</style>

<!-- Header -->
<div class="stock-page">
<div class="stock-head">
    <div>
        <h1 class="stock-title"><i class="fas fa-clipboard-list"></i> Master Data Requests</h1>
    </div>
</div>

<!-- KPIs -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card yellow">
        <div class="txn-kpi-lbl"><i class="fas fa-clock" style="color:#d97706;margin-right:4px;"></i> Pending Requests</div>
        <div class="txn-kpi-val" id="mdr_kpi_pending"><?= number_format($kpi_pending) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:4px;"></i> Approved Today</div>
        <div class="txn-kpi-val" id="mdr_kpi_approved"><?= number_format($kpi_approved_today) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:4px;"></i> Rejected Today</div>
        <div class="txn-kpi-val" id="mdr_kpi_rejected"><?= number_format($kpi_rejected_today) ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-list-alt" style="color:#0284c7;margin-right:4px;"></i> Total Requests</div>
        <div class="txn-kpi-val" id="mdr_kpi_total"><?= number_format($kpi_total) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters-form">
    <div>
        <label>Category</label>
        <select name="category" class="inp" style="min-width:160px;">
            <option value="">All Requests</option>
            <option value="Vehicle" <?= $f_category === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
            <option value="Merchandise Product" <?= $f_category === 'Merchandise Product' ? 'selected' : '' ?>>Merchandise Product</option>
            <option value="Service Type" <?= $f_category === 'Service Type' ? 'selected' : '' ?>>Service Type</option>
        </select>
    </div>
    <div>
        <label>Status</label>
        <select name="status" class="inp" style="min-width:130px;">
            <option value="">All Status</option>
            <option value="Pending" <?= $f_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Approved" <?= $f_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Rejected" <?= $f_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
    </div>
    <div>
        <label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="inp">
    </div>
    <div>
        <label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="inp">
    </div>
    <div style="flex:1;min-width:260px;">
        <label>Search</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="inp" style="width:100%;" placeholder="Search Request No., Product, Service, Vehicle, Requester">
    </div>
    <div style="flex-direction: row; gap: 8px;">
        <button type="submit" class="btn-action btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="manager_request_data_management.php" class="btn-action btn-secondary"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<!-- Requests Table -->
<div class="table-card">
    <div class="table-card-head">
        <div class="table-card-title"><i class="fas fa-list-ul" style="margin-right: 6px;color:#002F70;"></i> Master Data Request Log</div>
    </div>
    <div class="table-responsive">
        <table class="tbl-requests">
            <thead>
                <tr>
                    <th style="width:10%">REQ No.</th>
                    <th style="width:12%">Category</th>
                    <th style="width:12%">Requester</th>
                    <th style="width:25%">Requested Details</th>
                    <th style="width:9%">Status</th>
                    <th style="width:11%">Date Submitted</th>
                    <th style="width:11%">Date Processed</th>
                    <th style="width:10%">Processed By</th>
                    <th style="width:8%;text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 48px; color: #94a3b8;">
                            <i class="fas fa-inbox" style="font-size: 36px; display: block; margin-bottom: 10px;"></i>
                            No requests found matching the filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): 
                        $payload = json_decode($row['data_payload'], true) ?? [];
                        
                        // Category Badge
                        $catClass = 'badge-cat';
                        if ($row['category'] === 'Vehicle') $catClass = 'badge-cat-vehicle';
                        elseif ($row['category'] === 'Merchandise Product') $catClass = 'badge-cat-merchandise';
                        elseif ($row['category'] === 'Service Type') $catClass = 'badge-cat-service';

                        // Status Badge
                        if ($row['status'] === 'Approved') {
                            $statusBadge = '<span class="badge badge-approved"><i class="fas fa-check-circle"></i> Approved</span>';
                        } elseif ($row['status'] === 'Rejected') {
                            $statusBadge = '<span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected</span>';
                        } else {
                            $statusBadge = '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>';
                        }

                        $date_processed = ($row['status'] !== 'Pending' && !empty($row['updated_at'])) ? date('M d, Y g:i A', strtotime($row['updated_at'])) : '—';
                        $processed_by   = ($row['status'] !== 'Pending' && !empty($row['reviewer_name'])) ? htmlspecialchars($row['reviewer_name']) : '—';
                    ?>
                        <tr>
                            <td><strong style="color:#002F70;font-family:monospace;"><?= htmlspecialchars($row['request_no']) ?></strong></td>
                            <td><span class="badge <?= $catClass ?>"><?= htmlspecialchars($row['category']) ?></span></td>
                            <td><strong style="font-size:12px;color:#1e293b;"><?= htmlspecialchars($row['requester_name']) ?></strong></td>

                            <td>
                                <div class="payload-struct">
                                    <?php if ($row['category'] === 'Merchandise Product'): ?>
                                        <div><strong>Product Name:</strong> <?= htmlspecialchars($payload['product_name'] ?? '—') ?></div>
                                        <div><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? 'Others') ?></div>
                                        <div><strong>Unit of Measure:</strong> <?= htmlspecialchars($payload['unit'] ?? '—') ?></div>
                                        <?php if (isset($payload['suggested_price']) && $payload['suggested_price'] !== ''): ?>
                                            <div><strong>Suggested Selling Price:</strong> &#8369;<?= number_format((float)$payload['suggested_price'], 2) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payload['brand'])): ?>
                                            <div><strong>Brand:</strong> <?= htmlspecialchars($payload['brand']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Service Type'): ?>
                                        <div><strong>Service Name:</strong> <?= htmlspecialchars($payload['service_name'] ?? '—') ?></div>
                                        <div><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? 'Others') ?></div>
                                        <div><strong>Suggested Selling Price:</strong> &#8369;<?= number_format((float)($payload['suggested_price'] ?? 0), 2) ?></div>
                                        <?php if (!empty($payload['estimated_duration'])): ?>
                                            <div><strong>Est. Duration:</strong> <?= htmlspecialchars($payload['estimated_duration']) ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Vehicle'): ?>
                                        <div><strong>Vehicle Brand:</strong> <?= htmlspecialchars($payload['vehicle_brand'] ?? '—') ?></div>
                                        <div><strong>Vehicle Model:</strong> <?= htmlspecialchars($payload['vehicle_model'] ?? '—') ?></div>
                                        <div><strong>Vehicle Type:</strong> <?= htmlspecialchars($payload['vehicle_type'] ?? '—') ?></div>
                                        <div><strong>Fuel Type:</strong> <?= htmlspecialchars($payload['fuel_type'] ?? '—') ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($payload['remarks'])): ?>
                                        <div style="margin-top:4px;color:#64748b;">
                                            <strong>Reason for Request:</strong> "<?= htmlspecialchars($payload['remarks']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?= $statusBadge ?>
                                <?php if ($row['status'] === 'Rejected' && !empty($row['rejection_reason'])): ?>
                                    <div style="font-size: 11px; color: #dc2626; margin-top: 4px;">
                                        Reason: <?= htmlspecialchars($row['rejection_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;color:#64748b;"><?= date('M d, Y g:i A', strtotime($row['created_at'])) ?></td>
                            <td style="font-size:11px;color:#64748b;"><?= $date_processed ?></td>
                            <td style="font-size:11.5px;font-weight:600;color:#334155;"><?= $processed_by ?></td>
                            <td style="text-align: center;">
                                <?php if ($row['status'] === 'Pending'): ?>
                                    <button class="btn-action btn-primary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" 
                                            onclick='openReviewModal(<?= json_encode($row) ?>)' title="Review Master Data Request">
                                        <i class="fas fa-eye"></i> Review
                                    </button>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:11.5px; font-style:italic;">Processed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Review Request Modal -->
<div id="reviewModal" class="modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title" id="reviewModalTitle"><i class="fas fa-clipboard-check" style="color:#002F70;margin-right:6px;"></i> Review Request</span>
            <button onclick="closeReviewModal()" style="background:none; border:none; cursor:pointer; font-size:22px; color:#94a3b8;">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Request Information -->
            <div style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px;">
                <i class="fas fa-info-circle"></i> Request Information
            </div>
            <div id="requestInfoBox" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:16px; font-size:12.5px; line-height:1.6;">
                <!-- Filled dynamically -->
            </div>

            <!-- Manager Decision Form -->
            <div style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px; border-bottom:2px solid #e2e8f0; padding-bottom:4px;">
                <i class="fas fa-user-check"></i> Manager Decision
            </div>
            <div style="margin-bottom:14px;">
                <div style="display:flex; gap:16px; margin-bottom:12px;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; color:#15803d;">
                        <input type="radio" name="decision" value="approve" checked onchange="toggleDecisionView()"> <i class="fas fa-check-circle"></i> Approve Request
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; color:#b91c1c;">
                        <input type="radio" name="decision" value="reject" onchange="toggleDecisionView()"> <i class="fas fa-times-circle"></i> Reject Request
                    </label>
                </div>
            </div>

            <!-- Editable Fields Editor (Shown when approving) -->
            <div id="fieldsEditorContainer" style="margin-bottom:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <div style="font-size:12px; font-weight:700; color:#002F70; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:6px; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-edit" style="color:#0284c7;"></i> Edit / Confirm Item Details Before Approval
                </div>
                <div id="dynamicFieldsInputs" class="edit-form-grid">
                    <!-- Filled dynamically based on Category -->
                </div>
            </div>

            <!-- Error message container -->
            <div id="reviewErrorMsg" style="display:none; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; padding:10px 12px; color:#991b1b; margin-bottom:14px; font-size:12px;"></div>

            <!-- Remarks / Rejection Reason -->
            <div style="margin-top:10px;">
                <label id="remarksLabel" style="font-weight:700; color:#475569; display:block; margin-bottom:5px; font-size:12px;">
                    Remarks <span style="font-weight:400;color:#64748b;">(Optional notes for approval)</span>
                </label>
                <textarea id="remarksInput" class="inp" style="width:100%; height:75px; padding:8px 10px; resize:none;" placeholder="Enter manager remarks or reason..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-action btn-secondary" onclick="closeReviewModal()">Cancel</button>
            <button id="btnApproveAction" class="btn-action btn-success" onclick="submitReviewDecision('approve')"><i class="fas fa-check-circle"></i> Approve</button>
            <button id="btnRejectAction" class="btn-action btn-danger" style="display:none;" onclick="submitReviewDecision('reject')"><i class="fas fa-times-circle"></i> Reject</button>
        </div>
    </div>
</div>

<script>
let currentRequest = null;

function openReviewModal(req) {
    currentRequest = req;
    const payload = JSON.parse(req.data_payload || '{}');
    
    // Set Title
    document.getElementById('reviewModalTitle').innerHTML = `<i class="fas fa-clipboard-check" style="color:#002F70;margin-right:6px;"></i> Review Request (${escapeHtml(req.request_no)})`;
    
    // Clear Error
    setReviewError('');

    // Request Information
    let infoHtml = `
        <div><strong>Request Number:</strong> <span style="color:#002F70;font-family:monospace;font-weight:700;">${escapeHtml(req.request_no)}</span></div>
        <div><strong>Requester:</strong> ${escapeHtml(req.requester_name)} (${escapeHtml(req.station_name || 'Station')})</div>
        <div><strong>Date Submitted:</strong> ${escapeHtml(req.created_at)}</div>
        <div><strong>Category:</strong> <span class="badge badge-cat">${escapeHtml(req.category)}</span></div>
    `;
    document.getElementById('requestInfoBox').innerHTML = infoHtml;

    // Populate Editable Fields
    const editorDiv = document.getElementById('dynamicFieldsInputs');
    editorDiv.innerHTML = '';

    if (req.category === 'Merchandise Product') {
        editorDiv.innerHTML = `
            <div>
                <label>Product Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="edit_product_name" class="inp" style="width:100%;" value="${escapeHtml(payload.product_name || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(payload.category || 'Lubricants')}">
                </div>
                <div style="flex:1;">
                    <label>Unit of Measure <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_unit" class="inp" style="width:100%;" value="${escapeHtml(payload.unit || 'Bottle')}">
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%;" value="${payload.suggested_price || ''}">
                </div>
                <div style="flex:1;">
                    <label>Brand</label>
                    <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(payload.brand || '')}">
                </div>
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    } else if (req.category === 'Service Type') {
        editorDiv.innerHTML = `
            <div>
                <label>Service Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="edit_service_name" class="inp" style="width:100%;" value="${escapeHtml(payload.service_name || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(payload.category || 'Others')}">
                </div>
                <div style="flex:1;">
                    <label>Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%;" value="${payload.suggested_price || 0}">
                </div>
            </div>
            <div>
                <label>Est. Duration</label>
                <input type="text" id="edit_duration" class="inp" style="width:100%;" value="${escapeHtml(payload.estimated_duration || '')}">
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    } else if (req.category === 'Vehicle') {
        editorDiv.innerHTML = `
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Vehicle Brand <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_brand || '')}">
                </div>
                <div style="flex:1;">
                    <label>Vehicle Model <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_model" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_model || '')}">
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Vehicle Type <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="edit_type" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_type || '')}">
                </div>
                <div style="flex:1;">
                    <label>Fuel Type</label>
                    <select id="edit_fuel_type" class="inp" style="width:100%;">
                        <option value="Gasoline" ${payload.fuel_type === 'Gasoline' ? 'selected' : ''}>Gasoline</option>
                        <option value="Diesel" ${payload.fuel_type === 'Diesel' ? 'selected' : ''}>Diesel</option>
                        <option value="Electric" ${payload.fuel_type === 'Electric' ? 'selected' : ''}>Electric</option>
                        <option value="Hybrid" ${payload.fuel_type === 'Hybrid' ? 'selected' : ''}>Hybrid</option>
                    </select>
                </div>
            </div>
            <div>
                <label>Reason for Request / Initial Notes</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    }

    // Reset Decision radio buttons to 'approve'
    document.querySelector('input[name="decision"][value="approve"]').checked = true;
    document.getElementById('remarksInput').value = '';
    
    toggleDecisionView();

    // Show Modal
    document.getElementById('reviewModal').style.display = 'flex';
}

function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
    currentRequest = null;
}

function setReviewError(msg) {
    const errBox = document.getElementById('reviewErrorMsg');
    errBox.textContent = msg;
    errBox.style.display = msg ? 'block' : 'none';
}

function toggleDecisionView() {
    const decision = document.querySelector('input[name="decision"]:checked').value;
    const btnApprove = document.getElementById('btnApproveAction');
    const btnReject  = document.getElementById('btnRejectAction');
    const remarksLabel = document.getElementById('remarksLabel');
    const fieldsEditor = document.getElementById('fieldsEditorContainer');

    if (decision === 'approve') {
        btnApprove.style.display = 'inline-flex';
        btnReject.style.display  = 'none';
        fieldsEditor.style.display = 'block';
        remarksLabel.innerHTML = 'Remarks <span style="font-weight:400;color:#64748b;">(Optional notes for approval)</span>';
    } else {
        btnApprove.style.display = 'none';
        btnReject.style.display  = 'inline-flex';
        fieldsEditor.style.display = 'none';
        remarksLabel.innerHTML = 'Rejection Reason <span style="color:#dc2626;">* (Required for Rejection)</span>';
    }
}

async function submitReviewDecision(forcedAction) {
    if (!currentRequest) return;
    setReviewError('');

    const decision = forcedAction || document.querySelector('input[name="decision"]:checked').value;
    const remarks  = document.getElementById('remarksInput').value.trim();

    let postData = {
        id: currentRequest.id,
        action: decision
    };

    if (decision === 'reject') {
        if (!remarks) {
            setReviewError('Rejection reason is required when rejecting a request.');
            return;
        }
        postData.rejection_reason = remarks;
    } else {
        // Collect edited values for approval
        let modifiedPayload = {};
        if (currentRequest.category === 'Merchandise Product') {
            const prodName = document.getElementById('edit_product_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const unit     = document.getElementById('edit_unit').value.trim();
            const price    = parseFloat(document.getElementById('edit_suggested_price').value);
            const brand    = document.getElementById('edit_brand').value.trim();
            const rem      = document.getElementById('edit_remarks').value.trim();

            if (!prodName) { setReviewError('Product Name is required.'); return; }
            if (!category) { setReviewError('Category is required.'); return; }
            if (!unit) { setReviewError('Unit of Measure is required.'); return; }
            if (isNaN(price) || price < 0) { setReviewError('Selling Price must be a valid positive amount.'); return; }

            modifiedPayload = {
                product_name: prodName,
                category: category,
                unit: unit,
                suggested_price: price,
                brand: brand || null,
                remarks: rem || null
            };
        } else if (currentRequest.category === 'Service Type') {
            const servName = document.getElementById('edit_service_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const price    = parseFloat(document.getElementById('edit_suggested_price').value);
            const duration = document.getElementById('edit_duration').value.trim();
            const rem      = document.getElementById('edit_remarks').value.trim();

            if (!servName) { setReviewError('Service Name is required.'); return; }
            if (!category) { setReviewError('Category is required.'); return; }
            if (isNaN(price) || price < 0) { setReviewError('Selling Price must be a valid positive amount.'); return; }

            modifiedPayload = {
                service_name: servName,
                category: category,
                suggested_price: price,
                estimated_duration: duration || null,
                remarks: rem || null
            };
        } else if (currentRequest.category === 'Vehicle') {
            const brand = document.getElementById('edit_brand').value.trim();
            const model = document.getElementById('edit_model').value.trim();
            const type  = document.getElementById('edit_type').value.trim();
            const fuel  = document.getElementById('edit_fuel_type').value;
            const rem   = document.getElementById('edit_remarks').value.trim();

            if (!brand) { setReviewError('Vehicle Brand is required.'); return; }
            if (!model) { setReviewError('Vehicle Model is required.'); return; }
            if (!type)  { setReviewError('Vehicle Type is required.'); return; }

            modifiedPayload = {
                vehicle_brand: brand,
                vehicle_model: model,
                vehicle_type: type,
                fuel_type: fuel,
                remarks: rem || null
            };
        }
        postData.modified_data = modifiedPayload;
        if (remarks) postData.rejection_reason = remarks;
    }

    const activeBtn = (decision === 'approve') ? document.getElementById('btnApproveAction') : document.getElementById('btnRejectAction');
    activeBtn.disabled = true;
    activeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const response = await fetch('../backend/api/approve_master_data_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(postData)
        });
        const result = await response.json();

        if (result.success) {
            closeReviewModal();
            window.location.href = 'manager_request_data_management.php?success=' + encodeURIComponent(result.message);
        } else {
            setReviewError(result.error || 'Failed to submit decision.');
            activeBtn.disabled = false;
            activeBtn.innerHTML = decision === 'approve' ? '<i class="fas fa-check-circle"></i> Approve' : '<i class="fas fa-times-circle"></i> Reject';
        }
    } catch (err) {
        setReviewError('Network error: ' + err.message);
        activeBtn.disabled = false;
        activeBtn.innerHTML = decision === 'approve' ? '<i class="fas fa-check-circle"></i> Approve' : '<i class="fas fa-times-circle"></i> Reject';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Show alert banner on success query param
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.has('success')) {
    const banner = document.createElement('div');
    banner.style.position = 'fixed';
    banner.style.top = '20px';
    banner.style.right = '20px';
    banner.style.background = '#10b981';
    banner.style.color = '#fff';
    banner.style.padding = '12px 24px';
    banner.style.borderRadius = '8px';
    banner.style.boxShadow = '0 10px 15px -3px rgba(0,0,0,0.1)';
    banner.style.zIndex = '99999';
    banner.style.fontSize = '14px';
    banner.style.fontWeight = '600';
    banner.innerHTML = `<i class="fas fa-check-circle" style="margin-right:8px;"></i> ${urlParams.get('success')}`;
    document.body.appendChild(banner);
    setTimeout(() => banner.remove(), 4000);
}
</script>

</div>
<?php
require_once __DIR__ . '/../partials/footer.php';
?>
