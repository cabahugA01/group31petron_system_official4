<?php
$page_id = 'notifications';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['staff', 'admin', 'manager', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

// Handle POST actions (mark_all_read, mark_read, archive)
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $notif_id = intval($_POST['notification_id'] ?? 0);

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'unread'");
        $stmt->execute([$me['id']]);
        $notice = '✅ All notifications marked as read.';
    } elseif ($action === 'mark_read' && $notif_id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $me['id']]);
        $notice = '✅ Notification marked as read.';
    } elseif ($action === 'archive' && $notif_id > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'archived' WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $me['id']]);
        $notice = '📦 Notification moved to archive.';
    }
}

// Filter parameters
$search       = trim($_GET['search'] ?? '');
$category     = strtolower(trim($_GET['category'] ?? 'all'));
$filter_type  = trim($_GET['type'] ?? 'all');
$filter_prio  = trim($_GET['priority'] ?? 'all');
$filter_stat  = trim($_GET['status'] ?? 'all');
$filter_shift = trim($_GET['shift'] ?? 'all');
$date_from    = trim($_GET['date_from'] ?? '');
$date_to      = trim($_GET['date_to'] ?? '');

$assigned_shift = trim($me['assigned_shift'] ?? '');

// Overall Counts
$counts = ['total' => 0, 'unread' => 0, 'read' => 0, 'archived' => 0];
try {
    $c_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
            SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_cnt,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_cnt
        FROM notifications 
        WHERE user_id = ?
    ");
    $c_stmt->execute([$me['id']]);
    $res = $c_stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $counts['total']    = (int)($res['total'] ?? 0);
        $counts['unread']   = (int)($res['unread'] ?? 0);
        $counts['read']     = (int)($res['read_cnt'] ?? 0);
        $counts['archived'] = (int)($res['archived_cnt'] ?? 0);
    }
} catch (Exception $e) {}

// Query Filter Build
$where  = ['n.user_id = ?'];
$params = [$me['id']];

// Staff Shift Isolation
if ($role === 'staff') {
    if ($filter_shift !== 'all' && $filter_shift !== '') {
        $where[]  = '(n.shift_period = ? OR n.shift_period IS NULL OR n.shift_period = "")';
        $params[] = $filter_shift;
    } elseif (!empty($assigned_shift) && $assigned_shift !== 'All Shifts') {
        $where[]  = '(n.shift_period = ? OR n.shift_period IS NULL OR n.shift_period = "")';
        $params[] = $assigned_shift;
    }
} elseif ($filter_shift !== 'all' && $filter_shift !== '') {
    $where[]  = '(n.shift_period = ? OR n.shift_period IS NULL OR n.shift_period = "")';
    $params[] = $filter_shift;
}

// Category filter
if ($category !== 'all' && $category !== '') {
    if ($category === 'fuel') {
        $where[] = "(n.event_type IN ('fuel_transaction','fuel_sales_closing','fuel_reading','fuel') OR n.title LIKE '%Fuel%')";
    } elseif ($category === 'inventory') {
        $where[] = "(n.event_type IN ('stock_request','purchase_order','inventory','delivery') OR n.title LIKE '%Stock%' OR n.title LIKE '%Inventory%')";
    } elseif ($category === 'transactions') {
        $where[] = "(n.event_type IN ('void_request','transaction_adjustment','transaction','job_order') OR n.title LIKE '%Void%' OR n.title LIKE '%Adjustment%' OR n.title LIKE '%Transaction%')";
    } elseif ($category === 'approvals') {
        $where[] = "(n.event_type IN ('stock_request','void_request','master_data_request','fuel_transaction') OR n.title LIKE '%Approved%' OR n.title LIKE '%Pending%' OR n.title LIKE '%Review%')";
    } elseif ($category === 'master_data') {
        $where[] = "(n.event_type IN ('master_data_request','customer_request') OR n.title LIKE '%Master Data%')";
    } elseif ($category === 'system') {
        $where[] = "(n.event_type IN ('system','system_error','security','account_lockout','unauthorized_access') OR n.title LIKE '%System%' OR n.title LIKE '%Security%')";
    }
}

if ($filter_stat !== 'all' && $filter_stat !== '') {
    $where[]  = 'n.status = ?';
    $params[] = $filter_stat;
}

if ($filter_type !== 'all' && $filter_type !== '') {
    $where[]  = '(n.event_type = ? OR n.type = ?)';
    $params[] = $filter_type;
    $params[] = $filter_type;
}

if ($filter_prio !== 'all' && $filter_prio !== '') {
    $where[]  = 'n.severity = ?';
    $params[] = $filter_prio;
}

if ($search !== '') {
    $where[]  = '(n.title LIKE ? OR n.message LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($date_from !== '') {
    $where[]  = 'DATE(n.created_at) >= ?';
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[]  = 'DATE(n.created_at) <= ?';
    $params[] = $date_to;
}

$where_clause = implode(' AND ', $where);

// Pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$notifications = [];
$total_rows    = 0;

try {
    // Total count for current query
    $count_sql = "SELECT COUNT(*) FROM notifications n WHERE {$where_clause}";
    $c_stmt = $pdo->prepare($count_sql);
    $c_stmt->execute($params);
    $total_rows = (int)$c_stmt->fetchColumn();

    // Query rows
    $sql = "SELECT n.* FROM notifications n WHERE {$where_clause} ORDER BY n.created_at DESC LIMIT {$per_page} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$nr) {
        if (empty($nr['redirect_url']) && !empty($nr['reference_type'])) {
            $nr['redirect_url'] = notification_redirect_url(
                $nr['reference_type'],
                (int)($nr['reference_id'] ?? 0),
                $role
            );
        }
    }
    unset($nr);
} catch (Exception $e) {
    $notifications = [];
}

$total_pages = max(1, ceil($total_rows / $per_page));

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Notifications Page Styling ── */
.notif-wrapper {
    padding: 0 !important;
    background: #f8fafc;
    min-height: calc(100vh - 70px);
}

.notif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
    flex-wrap: wrap;
    gap: 16px;
}

.notif-title-area h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}

.notif-title-area p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

/* Dashboard Cards */
.notif-cards-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (max-width: 992px) {
    .notif-cards-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .notif-cards-grid { grid-template-columns: 1fr; }
}

.notif-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
    text-decoration: none !important;
}

.notif-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.notif-card-val {
    font-size: 28px;
    font-weight: 800;
    line-height: 1.1;
}

.notif-card-lbl {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.notif-card-ico {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

/* Card Variations */
.notif-card.card-total .notif-card-val { color: #0f172a; }
.notif-card.card-total .notif-card-ico { background: #e2e8f0; color: #475569; }

.notif-card.card-unread .notif-card-val { color: #ef4444; }
.notif-card.card-unread .notif-card-ico { background: #fee2e2; color: #dc2626; }

.notif-card.card-read .notif-card-val { color: #10b981; }
.notif-card.card-read .notif-card-ico { background: #d1fae5; color: #059669; }

.notif-card.card-archived .notif-card-val { color: #6366f1; }
.notif-card.card-archived .notif-card-ico { background: #e0e7ff; color: #4f46e5; }

/* Filter Container */
.notif-filter-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.notif-filter-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}

@media (max-width: 1200px) {
    .notif-filter-form { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .notif-filter-form { grid-template-columns: 1fr; }
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
}

.filter-group input, .filter-group select {
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    outline: none;
    transition: border-color 0.15s ease;
    background: #fff;
}

.filter-group input:focus, .filter-group select:focus {
    border-color: #002F6C;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.1);
}

.btn-filter-submit {
    padding: 9px 18px;
    background: #0f172a;
    color: #ffffff;
    border: 1px solid #0f172a;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.btn-filter-submit:hover { background: #1e293b; border-color: #1e293b; }

.btn-filter-reset {
    padding: 9px 14px;
    background: #ffffff;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none !important;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.btn-filter-reset:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

/* Table Container */
.notif-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    overflow: hidden;
}

.notif-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.notif-table th {
    background: #f8fafc;
    padding: 14px 18px;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
}

.notif-table td {
    padding: 16px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    color: #334155;
    vertical-align: middle;
}

.notif-table tbody tr {
    transition: background 0.15s ease;
}

.notif-table tbody tr.unread-row {
    background: rgba(0, 47, 108, 0.025);
}

.notif-table tbody tr:hover {
    background: #f8fafc;
}

/* Priority Badges */
.prio-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.prio-low { background: #e0f2fe; color: #0369a1; }
.prio-medium { background: #ffedd5; color: #c2410c; }
.prio-high { background: #fee2e2; color: #b91c1c; }
.prio-critical { background: #7f1d1d; color: #ffffff; }

/* Status Badges */
.stat-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.stat-unread { background: #ef4444; color: #fff; }
.stat-read { background: #e2e8f0; color: #475569; }
.stat-archived { background: #e0e7ff; color: #4f46e5; }

/* Action Buttons */
.action-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
}

.tbl-btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    text-decoration: none !important;
    transition: all 0.15s ease;
}

.tbl-btn-open { background-color: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; }
.tbl-btn-open:hover { background-color: #dbeafe !important; color: #1d4ed8 !important; border-color: #93c5fd !important; }

.tbl-btn-read { background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #cbd5e1 !important; }
.tbl-btn-read:hover { background-color: #e2e8f0 !important; color: #0f172a !important; border-color: #94a3b8 !important; }

.tbl-btn-archive { background-color: #ffffff !important; color: #64748b !important; border: 1px solid #e2e8f0 !important; }
.tbl-btn-archive:hover { background-color: #fef2f2 !important; color: #dc2626 !important; border-color: #fca5a5 !important; }

/* Pagination */
.notif-pagination {
    padding: 16px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Modal Styling */
.modal-backdrop-custom {
    position: fixed;
    top: 0; left: 0; width: 100vw; height: 100vh;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(2px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal-content-custom {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 550px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    overflow: hidden;
}

.modal-hdr {
    padding: 20px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-hdr h3 {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.modal-body {
    padding: 24px;
}

.modal-ftr {
    padding: 16px 24px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}
</style>

<div class="notif-wrapper">

    <!-- Top Header -->
    <div class="notif-header">
        <div class="notif-title-area">
            <h1><i class="fas fa-bell"></i> Notifications Hub</h1>
            <p>View, filter, and manage your operational system alerts and activity records.</p>
        </div>
        <div>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-filter-reset" style="background-color: #f0fdf4 !important; color: #166534 !important; border: 1px solid #bbf7d0 !important;">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form>
        </div>
    </div>

    <?php if ($notice): ?>
        <div style="padding:12px 18px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px; margin-bottom:20px; font-weight:600; font-size:14px;">
            <?= htmlspecialchars($notice) ?>
        </div>
    <?php endif; ?>

    <!-- Dashboard Cards -->
    <div class="notif-cards-grid">
        <a href="notifications.php?status=all" class="notif-card card-total">
            <div>
                <div class="notif-card-val"><?= number_format($counts['total']) ?></div>
                <div class="notif-card-lbl">Total Notifications</div>
            </div>
            <div class="notif-card-ico"><i class="fas fa-layer-group"></i></div>
        </a>

        <a href="notifications.php?status=unread" class="notif-card card-unread">
            <div>
                <div class="notif-card-val"><?= number_format($counts['unread']) ?></div>
                <div class="notif-card-lbl">Unread</div>
            </div>
            <div class="notif-card-ico"><i class="fas fa-bell"></i></div>
        </a>

        <a href="notifications.php?status=read" class="notif-card card-read">
            <div>
                <div class="notif-card-val"><?= number_format($counts['read']) ?></div>
                <div class="notif-card-lbl">Read</div>
            </div>
            <div class="notif-card-ico"><i class="fas fa-check-circle"></i></div>
        </a>

        <a href="notifications.php?status=archived" class="notif-card card-archived">
            <div>
                <div class="notif-card-val"><?= number_format($counts['archived']) ?></div>
                <div class="notif-card-lbl">Archived</div>
            </div>
            <div class="notif-card-ico"><i class="fas fa-archive"></i></div>
        </a>
    </div>

    <!-- Workflow & Category Navigation Tabs -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
        <?php
        $curr_cat = $category ?: 'all';
        $curr_stat = $filter_stat ?: 'all';
        $curr_shift = $filter_shift ?: 'all';

        $tabs = [
            ['id' => 'all',          'label' => 'All',          'icon' => 'fa-layer-group', 'url' => 'notifications.php?category=all&status=all'],
            ['id' => 'unread',       'label' => 'Unread',       'icon' => 'fa-bell',        'url' => 'notifications.php?status=unread'],
            ['id' => 'read',         'label' => 'Read',         'icon' => 'fa-check-circle','url' => 'notifications.php?status=read'],
            ['id' => 'approvals',    'label' => 'Approvals',    'icon' => 'fa-stamp',       'url' => 'notifications.php?category=approvals'],
            ['id' => 'inventory',    'label' => 'Inventory',    'icon' => 'fa-boxes',       'url' => 'notifications.php?category=inventory'],
            ['id' => 'transactions', 'label' => 'Transactions', 'icon' => 'fa-receipt',     'url' => 'notifications.php?category=transactions'],
            ['id' => 'fuel',         'label' => 'Fuel',         'icon' => 'fa-gas-pump',    'url' => 'notifications.php?category=fuel'],
            ['id' => 'master_data',  'label' => 'Master Data',  'icon' => 'fa-database',    'url' => 'notifications.php?category=master_data'],
            ['id' => 'system',       'label' => 'System',       'icon' => 'fa-server',      'url' => 'notifications.php?category=system'],
        ];

        // Staff Shift Tabs
        if ($role === 'staff') {
            if ($assigned_shift === 'Shift 1' || $assigned_shift === 'All Shifts' || empty($assigned_shift)) {
                $tabs[] = ['id' => 'shift1', 'label' => 'Shift 1 Only', 'icon' => 'fa-clock', 'url' => 'notifications.php?shift=' . urlencode('Shift 1')];
            }
            if ($assigned_shift === 'Shift 2' || $assigned_shift === 'All Shifts' || empty($assigned_shift)) {
                $tabs[] = ['id' => 'shift2', 'label' => 'Shift 2 Only', 'icon' => 'fa-moon',  'url' => 'notifications.php?shift=' . urlencode('Shift 2')];
            }
        } elseif (in_array($role, ['manager', 'admin', 'superadmin'])) {
            $tabs[] = ['id' => 'shift1', 'label' => 'Shift 1', 'icon' => 'fa-clock', 'url' => 'notifications.php?shift=' . urlencode('Shift 1')];
            $tabs[] = ['id' => 'shift2', 'label' => 'Shift 2', 'icon' => 'fa-moon',  'url' => 'notifications.php?shift=' . urlencode('Shift 2')];
        }

        foreach ($tabs as $t) {
            $is_active = false;
            if ($t['id'] === 'all' && $curr_cat === 'all' && $curr_stat === 'all' && $curr_shift === 'all') $is_active = true;
            elseif ($t['id'] === 'unread' && $curr_stat === 'unread') $is_active = true;
            elseif ($t['id'] === 'read' && $curr_stat === 'read') $is_active = true;
            elseif ($t['id'] === 'shift1' && $curr_shift === 'Shift 1') $is_active = true;
            elseif ($t['id'] === 'shift2' && $curr_shift === 'Shift 2') $is_active = true;
            elseif ($curr_cat === $t['id']) $is_active = true;

            $active_style = $is_active 
                ? 'background:#002F6C; color:#fff; font-weight:700; border-color:#002F6C;' 
                : 'background:#fff; color:#475569; font-weight:600; border-color:#cbd5e1;';

            echo '<a href="' . htmlspecialchars($t['url']) . '" style="padding: 8px 16px; border-radius: 20px; font-size: 13px; text-decoration: none !important; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s ease; ' . $active_style . '">';
            echo '<i class="fas ' . $t['icon'] . '"></i> ' . htmlspecialchars($t['label']);
            echo '</a>';
        }
        ?>
    </div>

    <!-- Filters Bar -->
    <div class="notif-filter-box">
        <form method="GET" class="notif-filter-form">
            <?php if ($category !== 'all'): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
            <?php endif; ?>
            <?php if ($filter_shift !== 'all'): ?>
                <input type="hidden" name="shift" value="<?= htmlspecialchars($filter_shift) ?>">
            <?php endif; ?>
            
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" placeholder="Search keyword..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Type</label>
                <select name="type">
                    <option value="all">All Types</option>
                    <option value="transaction" <?= $filter_type === 'transaction' ? 'selected' : '' ?>>Transaction</option>
                    <option value="job_order" <?= $filter_type === 'job_order' ? 'selected' : '' ?>>Job Order</option>
                    <option value="fuel_management" <?= $filter_type === 'fuel_management' ? 'selected' : '' ?>>Fuel Management</option>
                    <option value="inventory" <?= $filter_type === 'inventory' ? 'selected' : '' ?>>Inventory</option>
                    <option value="customer" <?= $filter_type === 'customer' ? 'selected' : '' ?>>Customer</option>
                    <option value="delivery" <?= $filter_type === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                    <option value="calendar" <?= $filter_type === 'calendar' ? 'selected' : '' ?>>Calendar</option>
                    <option value="report" <?= $filter_type === 'report' ? 'selected' : '' ?>>Report</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-exclamation-circle"></i> Priority</label>
                <select name="priority">
                    <option value="all">All Priorities</option>
                    <option value="low" <?= $filter_prio === 'low' ? 'selected' : '' ?>>Low</option>
                    <option value="medium" <?= $filter_prio === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="high" <?= $filter_prio === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="critical" <?= $filter_prio === 'critical' ? 'selected' : '' ?>>Critical</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Status</label>
                <select name="status">
                    <option value="all">All Statuses</option>
                    <option value="unread" <?= $filter_stat === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read" <?= $filter_stat === 'read' ? 'selected' : '' ?>>Read</option>
                    <option value="archived" <?= $filter_stat === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn-filter-submit"><i class="fas fa-filter"></i> Filter</button>
                <a href="notifications.php" class="btn-filter-reset"><i class="fas fa-undo"></i> Reset</a>
            </div>

        </form>
    </div>

    <!-- Data Table -->
    <div class="notif-table-card">
        <table class="notif-table">
            <thead>
                <tr>
                    <th style="width: 170px;">Date & Time</th>
                    <th style="width: 160px;">Notification Type</th>
                    <th>Message</th>
                    <th style="width: 110px;">Priority</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 220px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($notifications)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px; color: #94a3b8;">
                            <i class="fas fa-bell-slash" style="font-size: 36px; margin-bottom: 12px; display: block; color: #cbd5e1;"></i>
                            No notifications match your selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($notifications as $n): 
                        $is_unread = ($n['status'] === 'unread');
                        $prio = strtolower($n['severity'] ?? 'medium');
                        $type_label = ucwords(str_replace('_', ' ', $n['event_type'] ?? 'General'));
                        $redirect = $n['redirect_url'] ?? '';
                    ?>
                        <tr class="<?= $is_unread ? 'unread-row' : '' ?>" style="cursor: pointer;" onclick="handleNotifClick(event, <?= htmlspecialchars(json_encode($n)) ?>)">
                            <td style="font-weight: 600; color: #1e293b;">
                                <?= date('M d, Y', strtotime($n['created_at'])) ?><br>
                                <span style="font-size: 11px; color: #64748b; font-weight: normal;"><?= date('h:i A', strtotime($n['created_at'])) ?></span>
                            </td>

                            <td>
                                <strong style="color: #0f172a; font-size: 13px;"><?= htmlspecialchars($type_label) ?></strong>
                            </td>

                            <td>
                                <div style="font-weight: 700; color: #002F6C; margin-bottom: 2px; font-size: 14px;">
                                    <?= htmlspecialchars($n['title']) ?>
                                    <?php if (!empty($redirect)): ?>
                                        <i class="fas fa-external-link-alt" style="font-size: 11px; margin-left: 4px; color: #3b82f6;"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="color: #475569; line-height: 1.4;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </div>
                            </td>

                            <td>
                                <span class="prio-badge prio-<?= $prio ?>">
                                    <i class="fas fa-circle" style="font-size:7px;"></i> <?= ucfirst($prio) ?>
                                </span>
                            </td>

                            <td>
                                <span class="stat-badge stat-<?= $n['status'] ?>">
                                    <?= ucfirst($n['status']) ?>
                                </span>
                            </td>

                            <td style="text-align: right;" onclick="event.stopPropagation()">
                                <div class="action-btn-group" style="justify-content: flex-end;">
                                    
                                    <!-- Open Action Button -->
                                    <?php if (!empty($redirect)): ?>
                                        <button type="button" class="tbl-btn tbl-btn-open"
                                                onclick="handleNotifClick(event, <?= htmlspecialchars(json_encode($n)) ?>)">
                                            <i class="fas fa-external-link-alt"></i> Open
                                        </button>
                                    <?php endif; ?>

                                    <!-- Mark Read Form -->
                                    <?php if ($is_unread): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                            <button type="submit" class="tbl-btn tbl-btn-read" title="Mark as Read">
                                                <i class="fas fa-check"></i> Read
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Archive Form -->
                                    <?php if ($n['status'] !== 'archived'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                            <button type="submit" class="tbl-btn tbl-btn-archive" title="Archive Notification">
                                                <i class="fas fa-archive"></i> Archive
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination Bar -->
        <?php if ($total_pages > 1): ?>
            <div class="notif-pagination">
                <div style="font-size: 13px; color: #64748b;">
                    Showing page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong> (<?= number_format($total_rows) ?> records)
                </div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn-filter-reset" style="padding: 6px 12px;">&laquo; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn-filter-reset" style="padding: 6px 12px;">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

</div>

<!-- Modal Dialog -->
<div id="notifModal" class="modal-backdrop-custom">
    <div class="modal-content-custom">
        <div class="modal-hdr">
            <h3 id="modalTitle">Notification Details</h3>
            <button type="button" onclick="closeNotifModal()" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                <span id="modalPriority" class="prio-badge prio-medium">Medium</span>
                <span id="modalDate" style="font-size:12px; color:#64748b;">Jul 29, 2026 12:00 PM</span>
            </div>
            <h4 id="modalHeading" style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 10px;">Title</h4>
            <p id="modalMessage" style="font-size:14px; color:#334155; line-height:1.5; margin:0 0 16px;">Message body...</p>
        </div>
        <div class="modal-ftr">
            <button type="button" onclick="closeNotifModal()" class="btn-filter-reset">Close</button>
            <a id="modalActionBtn" href="#" class="btn-filter-submit" style="display:none; text-decoration:none;">
                <i class="fas fa-external-link-alt"></i> Open Related Action
            </a>
        </div>
    </div>
</div>

<script>
function handleNotifClick(e, n) {
    if (e) e.stopPropagation();
    
    // Mark as read asynchronously
    if (n.status === 'unread') {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', n.id);
        fetch('backend/api/notifications_api.php?action=mark_read', {
            method: 'POST',
            body: formData
        }).catch(err => {});
    }

    if (n.redirect_url && n.redirect_url.trim() !== '' && n.redirect_url !== '#' && n.redirect_url !== 'null') {
        const targetUrl = window.resolveRedirectUrl ? window.resolveRedirectUrl(n.redirect_url) : n.redirect_url;
        window.location.href = targetUrl;
    } else {
        openNotifModal(n);
    }
}

function openNotifModal(n) {
    document.getElementById('modalTitle').textContent = (n.event_type || 'Notification').toUpperCase().replace('_', ' ');
    document.getElementById('modalHeading').textContent = n.title || 'Notification';
    document.getElementById('modalMessage').textContent = n.message || '';
    
    const prioEl = document.getElementById('modalPriority');
    const prio = (n.severity || 'medium').toLowerCase();
    prioEl.className = `prio-badge prio-${prio}`;
    prioEl.innerHTML = `<i class="fas fa-circle" style="font-size:7px;"></i> ${prio.toUpperCase()}`;

    const d = new Date(n.created_at);
    document.getElementById('modalDate').textContent = d.toLocaleString();

    const actionBtn = document.getElementById('modalActionBtn');
    if (n.redirect_url && n.redirect_url.trim() !== '' && n.redirect_url !== '#' && n.redirect_url !== 'null') {
        actionBtn.href = window.resolveRedirectUrl ? window.resolveRedirectUrl(n.redirect_url) : n.redirect_url;
        actionBtn.style.display = 'inline-flex';
    } else {
        actionBtn.style.display = 'none';
    }

    document.getElementById('notifModal').style.display = 'flex';

    // Mark as read asynchronously
    if (n.status === 'unread') {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('notification_id', n.id);
        fetch('backend/api/notifications_api.php?action=mark_read', {
            method: 'POST',
            body: formData
        }).catch(err => {});
    }
}

function closeNotifModal() {
    document.getElementById('notifModal').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
