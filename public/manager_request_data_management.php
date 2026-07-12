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
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, 'Unknown Staff') as requester_name,
            COALESCE(CONCAT(rev.first_name, ' ', rev.last_name), rev.name, '') as reviewer_name,
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
    error_log('Request Data Management query error: ' . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.page-head.txn-page-head h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--petron-blue, #00264D) !important;
    margin: 0 !important;
    display: flex;
    align-items: center;
    gap: 8px;
}
.page-head.txn-page-head .sub {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
}

/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .05);
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val { color: #0369a1; }
.txn-kpi-card.purple .txn-kpi-val { color: #7c3aed; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.orange .txn-kpi-val { color: #ea580c; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }

.filters-form {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.filters-form > div {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filters-form label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.filters-form .inp {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    outline: none;
    min-width: 140px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.filters-form .inp:focus {
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 14px;
    height: 38px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor:pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    background: #fff;
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

.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.table-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.table-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.tbl-requests {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}
.tbl-requests th {
    background: #002F70;
    color: #fff;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 10px 18px;
    border-bottom: 2px solid #001a3d;
}
.tbl-requests td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.tbl-requests tr:hover {
    background: #f8fafc;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.badge-pending { background: #fef3c7; color: #d97706; }
.badge-approved { background: #d1fae5; color: #059669; }
.badge-rejected { background: #fee2e2; color: #dc2626; }

.badge-cat {
    background: #f1f5f9;
    color: #475569;
}
.badge-cat-vehicle { background: #eff6ff; color: #1d4ed8; }
.badge-cat-merchandise { background: #fdf2f8; color: #be185d; }
.badge-cat-service { background: #f5f3ff; color: #6d28d9; }

.payload-list {
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 12px;
}
.payload-list li {
    margin-bottom: 4px;
}
.payload-list strong {
    color: #475569;
}

.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 10000;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-content {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    overflow: hidden;
    animation: modalSlideUp 0.2s ease-out;
}
@keyframes modalSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
}
.modal-body {
    padding: 20px;
    font-size: 13px;
    color: #334155;
}
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<!-- Header -->
<div class="page-head txn-page-head">
    <div>
        <h1><i class="fas fa-clipboard-list"></i> Request Data Management</h1>
        <div class="sub">Review and process master data requests submitted by staff members for Products, Services, and Vehicles.</div>
    </div>
</div>

<!-- KPIs -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Pending Requests</div>
        <div class="txn-kpi-val"><?= number_format($kpi_pending) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Approved Today</div>
        <div class="txn-kpi-val"><?= number_format($kpi_approved_today) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle"></i> Rejected Today</div>
        <div class="txn-kpi-val"><?= number_format($kpi_rejected_today) ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-database"></i> Total Requests</div>
        <div class="txn-kpi-val"><?= number_format($kpi_total) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filters-form">
    <div>
        <label>Category</label>
        <select name="category" class="inp">
            <option value="">All Requests</option>
            <option value="Vehicle" <?= $f_category === 'Vehicle' ? 'selected' : '' ?>>Vehicle</option>
            <option value="Merchandise Product" <?= $f_category === 'Merchandise Product' ? 'selected' : '' ?>>Merchandise Product</option>
            <option value="Service Type" <?= $f_category === 'Service Type' ? 'selected' : '' ?>>Service Type</option>
        </select>
    </div>
    <div>
        <label>Status</label>
        <select name="status" class="inp">
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
    <div>
        <label>Search</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="inp" placeholder="Request No, keywords...">
    </div>
    <div style="flex-direction: row; gap: 8px;">
        <button type="submit" class="btn-action btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="manager_request_data_management.php" class="btn-action btn-secondary" style="text-decoration:none;"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<!-- Requests Table -->
<div class="table-card">
    <div class="table-card-head">
        <div class="table-card-title"><i class="fas fa-list-ul" style="margin-right: 6px;"></i> Master Data Request Log</div>
    </div>
    <div class="table-responsive">
        <table class="tbl-requests">
            <thead>
                <tr>
                    <th>Req No.</th>
                    <th>Category</th>
                    <th>Requester</th>
                    <th>Requested Details</th>
                    <th>Status</th>
                    <th>Date Submitted</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fas fa-inbox" style="font-size: 28px; display: block; margin-bottom: 10px;"></i>
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
                        $statusClass = 'badge-pending';
                        if ($row['status'] === 'Approved') $statusClass = 'badge-approved';
                        elseif ($row['status'] === 'Rejected') $statusClass = 'badge-rejected';
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['request_no']) ?></strong></td>
                            <td><span class="badge <?= $catClass ?>"><?= htmlspecialchars($row['category']) ?></span></td>
                            <td>
                                <div><?= htmlspecialchars($row['requester_name']) ?></div>
                            </td>

                            <td>
                                <ul class="payload-list">
                                    <?php if ($row['category'] === 'Merchandise Product'): ?>
                                        <li><strong>Product Name:</strong> <?= htmlspecialchars($payload['product_name'] ?? '—') ?></li>
                                        <li><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? '—') ?></li>
                                        <li><strong>Unit:</strong> <?= htmlspecialchars($payload['unit'] ?? '—') ?></li>
                                        <?php if (!empty($payload['suggested_price'])): ?>
                                            <li><strong>Suggested Price:</strong> ₱<?= number_format($payload['suggested_price'], 2) ?></li>
                                        <?php endif; ?>
                                        <?php if (!empty($payload['brand'])): ?>
                                            <li><strong>Brand:</strong> <?= htmlspecialchars($payload['brand']) ?></li>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Service Type'): ?>
                                        <li><strong>Service Name:</strong> <?= htmlspecialchars($payload['service_name'] ?? '—') ?></li>
                                        <li><strong>Category:</strong> <?= htmlspecialchars($payload['category'] ?? '—') ?></li>
                                        <li><strong>Suggested Price:</strong> ₱<?= number_format($payload['suggested_price'] ?? 0, 2) ?></li>
                                        <?php if (!empty($payload['estimated_duration'])): ?>
                                            <li><strong>Est. Duration:</strong> <?= htmlspecialchars($payload['estimated_duration']) ?></li>
                                        <?php endif; ?>
                                    <?php elseif ($row['category'] === 'Vehicle'): ?>
                                        <li><strong>Brand:</strong> <?= htmlspecialchars($payload['vehicle_brand'] ?? '—') ?></li>
                                        <li><strong>Model:</strong> <?= htmlspecialchars($payload['vehicle_model'] ?? '—') ?></li>
                                        <li><strong>Type:</strong> <?= htmlspecialchars($payload['vehicle_type'] ?? '—') ?></li>
                                        <li><strong>Fuel:</strong> <?= htmlspecialchars($payload['fuel_type'] ?? '—') ?></li>
                                    <?php endif; ?>

                                    <?php if (!empty($payload['remarks'])): ?>
                                        <li style="margin-top: 4px; font-style: italic; color: #64748b;">
                                            <strong>Remarks:</strong> "<?= htmlspecialchars($payload['remarks']) ?>"
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                                <?php if ($row['status'] === 'Rejected' && !empty($row['rejection_reason'])): ?>
                                    <div style="font-size: 11px; color: #ef4444; max-width: 200px; margin-top: 4px;">
                                        Reason: <?= htmlspecialchars($row['rejection_reason']) ?>
                                    </div>
                                <?php elseif ($row['status'] === 'Approved' && !empty($row['reviewer_name'])): ?>
                                    <div style="font-size: 10px; color: #64748b; margin-top: 4px;">
                                        By: <?= htmlspecialchars($row['reviewer_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></td>
                            <td style="text-align: right;">
                                <?php if ($row['status'] === 'Pending'): ?>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <button class="btn-action btn-primary" style="height: 32px; padding: 0 10px; font-size: 12px;" 
                                                onclick='openApprovalModal(<?= json_encode($row) ?>)'>
                                            <i class="fas fa-check"></i> Process
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:12px; font-style:italic;">Processed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Approval/Rejection Modal -->
<div id="processModal" class="modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title" id="processModalTitle">Process Request</span>
            <button onclick="closeProcessModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#94a3b8;">×</button>
        </div>
        <div class="modal-body">
            <div id="requestDetailsContainer" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
                <!-- Filled dynamically -->
            </div>

            <!-- Error message container -->
            <div id="processErrorMsg" style="display:none; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; padding:10px 12px; color:#991b1b; margin-bottom:14px; font-size:12px;"></div>

            <!-- Action Selector -->
            <div style="margin-bottom:14px;">
                <label style="font-weight:600; color:#475569; display:block; margin-bottom:5px;">Manager Decision</label>
                <div style="display:flex; gap:12px;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="radio" name="decision" value="approve" checked onchange="toggleDecisionView()"> Approve & Save
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                        <input type="radio" name="decision" value="reject" onchange="toggleDecisionView()"> Reject Request
                    </label>
                </div>
            </div>

            <!-- Fields Editor (for Approval modification) -->
            <div id="fieldsEditorContainer" style="margin-top:14px;">
                <div style="font-weight:600; color:#475569; margin-bottom:10px; font-size:12px; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                    Edit/Confirm Details before Insertion
                </div>
                <div id="dynamicFieldsInputs" style="display:flex; flex-direction:column; gap:10px;">
                    <!-- Filled dynamically based on Category -->
                </div>
            </div>

            <!-- Rejection Reason Container -->
            <div id="rejectionReasonContainer" style="display:none; margin-top:14px;">
                <label style="font-weight:600; color:#475569; display:block; margin-bottom:5px;">Rejection Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="rejectionReasonInput" class="inp" style="width:100%; height:80px; padding:8px 10px; resize:none;" placeholder="Explain why the request is rejected..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-action btn-secondary" onclick="closeProcessModal()">Cancel</button>
            <button id="btnSubmitDecision" class="btn-action btn-primary" onclick="submitDecision()">Submit Decision</button>
        </div>
    </div>
</div>

<script>
let currentRequest = null;

function openApprovalModal(req) {
    currentRequest = req;
    const payload = JSON.parse(req.data_payload);
    
    // Set Title
    document.getElementById('processModalTitle').textContent = `Process Request ${req.request_no}`;
    
    // Clear Error
    setProcessError('');

    // Setup Details Container
    let detailsHtml = `
        <div style="font-size:12px; margin-bottom:8px;">
            <strong>Requester:</strong> ${req.requester_name} (${req.station_name || 'General'})<br>
            <strong>Submitted:</strong> ${req.created_at}
        </div>
    `;
    document.getElementById('requestDetailsContainer').innerHTML = detailsHtml;

    // Reset Decision radio buttons to 'approve'
    document.querySelector('input[name="decision"][value="approve"]').checked = true;
    
    // Generate inputs for fields editor
    const editorDiv = document.getElementById('dynamicFieldsInputs');
    editorDiv.innerHTML = '';

    if (req.category === 'Merchandise Product') {
        editorDiv.innerHTML = `
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Product Name</label>
                <input type="text" id="edit_product_name" class="inp" style="width:100%;" value="${escapeHtml(payload.product_name || '')}">
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Category</label>
                <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(payload.category || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Unit</label>
                    <input type="text" id="edit_unit" class="inp" style="width:100%;" value="${escapeHtml(payload.unit || 'pc')}">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Suggested Price (₱)</label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%;" value="${payload.suggested_price || ''}">
                </div>
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Brand</label>
                <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(payload.brand || '')}">
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Remarks / Description</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    } else if (req.category === 'Service Type') {
        editorDiv.innerHTML = `
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Service Name</label>
                <input type="text" id="edit_service_name" class="inp" style="width:100%;" value="${escapeHtml(payload.service_name || '')}">
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Category</label>
                <input type="text" id="edit_category" class="inp" style="width:100%;" value="${escapeHtml(payload.category || 'Others')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Suggested Price (₱)</label>
                    <input type="number" step="0.01" id="edit_suggested_price" class="inp" style="width:100%;" value="${payload.suggested_price || 0}">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Est. Duration</label>
                    <input type="text" id="edit_duration" class="inp" style="width:100%;" value="${escapeHtml(payload.estimated_duration || '')}">
                </div>
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Remarks</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    } else if (req.category === 'Vehicle') {
        editorDiv.innerHTML = `
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Vehicle Brand</label>
                <input type="text" id="edit_brand" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_brand || '')}">
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Vehicle Model</label>
                <input type="text" id="edit_model" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_model || '')}">
            </div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Vehicle Type</label>
                    <input type="text" id="edit_type" class="inp" style="width:100%;" value="${escapeHtml(payload.vehicle_type || '')}">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:600; color:#475569;">Fuel Type</label>
                    <select id="edit_fuel_type" class="inp" style="width:100%;">
                        <option value="Gasoline" ${payload.fuel_type === 'Gasoline' ? 'selected' : ''}>Gasoline</option>
                        <option value="Diesel" ${payload.fuel_type === 'Diesel' ? 'selected' : ''}>Diesel</option>
                        <option value="Electric" ${payload.fuel_type === 'Electric' ? 'selected' : ''}>Electric</option>
                        <option value="Hybrid" ${payload.fuel_type === 'Hybrid' ? 'selected' : ''}>Hybrid</option>
                    </select>
                </div>
            </div>
            <div>
                <label style="font-size:11px; font-weight:600; color:#475569;">Remarks</label>
                <input type="text" id="edit_remarks" class="inp" style="width:100%;" value="${escapeHtml(payload.remarks || '')}">
            </div>
        `;
    }

    // Reset Rejection Inputs
    document.getElementById('rejectionReasonInput').value = '';
    
    // Toggle view
    toggleDecisionView();

    // Show Modal
    document.getElementById('processModal').style.display = 'flex';
}

function closeProcessModal() {
    document.getElementById('processModal').style.display = 'none';
    currentRequest = null;
}

function setProcessError(msg) {
    const errBox = document.getElementById('processErrorMsg');
    errBox.textContent = msg;
    errBox.style.display = msg ? 'block' : 'none';
}

function toggleDecisionView() {
    const decision = document.querySelector('input[name="decision"]:checked').value;
    if (decision === 'approve') {
        document.getElementById('fieldsEditorContainer').style.display = 'block';
        document.getElementById('rejectionReasonContainer').style.display = 'none';
    } else {
        document.getElementById('fieldsEditorContainer').style.display = 'none';
        document.getElementById('rejectionReasonContainer').style.display = 'block';
    }
}

async function submitDecision() {
    if (!currentRequest) return;
    setProcessError('');

    const decision = document.querySelector('input[name="decision"]:checked').value;
    const btn = document.getElementById('btnSubmitDecision');

    let postData = {
        id: currentRequest.id,
        action: decision
    };

    if (decision === 'reject') {
        const reason = document.getElementById('rejectionReasonInput').value.trim();
        if (!reason) {
            setProcessError('Please specify a rejection reason.');
            return;
        }
        postData.rejection_reason = reason;
    } else {
        // Build modified payload
        let modifiedPayload = {};
        if (currentRequest.category === 'Merchandise Product') {
            const prodName = document.getElementById('edit_product_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const unit = document.getElementById('edit_unit').value.trim();
            const price = parseFloat(document.getElementById('edit_suggested_price').value);
            const brand = document.getElementById('edit_brand').value.trim();
            const remarks = document.getElementById('edit_remarks').value.trim();

            if (!prodName) { setProcessError('Product Name is required.'); return; }
            if (!category) { setProcessError('Category is required.'); return; }
            if (!unit) { setProcessError('Unit is required.'); return; }

            modifiedPayload = {
                product_name: prodName,
                category: category,
                unit: unit,
                suggested_price: isNaN(price) ? null : price,
                brand: brand || null,
                remarks: remarks || null
            };
        } else if (currentRequest.category === 'Service Type') {
            const servName = document.getElementById('edit_service_name').value.trim();
            const category = document.getElementById('edit_category').value.trim();
            const price = parseFloat(document.getElementById('edit_suggested_price').value);
            const duration = document.getElementById('edit_duration').value.trim();
            const remarks = document.getElementById('edit_remarks').value.trim();

            if (!servName) { setProcessError('Service Name is required.'); return; }
            if (!category) { setProcessError('Category is required.'); return; }
            if (isNaN(price) || price < 0) { setProcessError('Suggested price must be valid positive amount.'); return; }

            modifiedPayload = {
                service_name: servName,
                category: category,
                suggested_price: price,
                estimated_duration: duration || null,
                remarks: remarks || null
            };
        } else if (currentRequest.category === 'Vehicle') {
            const brand = document.getElementById('edit_brand').value.trim();
            const model = document.getElementById('edit_model').value.trim();
            const type = document.getElementById('edit_type').value.trim();
            const fuel = document.getElementById('edit_fuel_type').value;
            const remarks = document.getElementById('edit_remarks').value.trim();

            if (!brand) { setProcessError('Vehicle Brand is required.'); return; }
            if (!model) { setProcessError('Vehicle Model is required.'); return; }
            if (!type) { setProcessError('Vehicle Type is required.'); return; }

            modifiedPayload = {
                vehicle_brand: brand,
                vehicle_model: model,
                vehicle_type: type,
                fuel_type: fuel,
                remarks: remarks || null
            };
        }
        postData.modified_data = modifiedPayload;
    }

    // Disable button & show spinner
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const response = await fetch('../backend/api/approve_master_data_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(postData)
        });
        const result = await response.json();

        if (result.success) {
            closeProcessModal();
            // Reload page to reflect changes & show alert
            window.location.href = 'manager_request_data_management.php?success=' + encodeURIComponent(result.message);
        } else {
            setProcessError(result.error || 'Failed to submit decision.');
            btn.disabled = false;
            btn.innerHTML = 'Submit Decision';
        }
    } catch (err) {
        setProcessError('Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = 'Submit Decision';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text
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

<?php
require_once __DIR__ . '/../partials/footer.php';
?>
