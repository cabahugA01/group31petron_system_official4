<?php
$page_id = 'notifications';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['staff', 'admin', 'manager', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Handle form submissions
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'unread'");
        $stmt->execute([$me['id']]);
        $notice = '✅ All notifications marked as read';
    }
}

$status_filter = $_GET['filter'] ?? 'all';

$notifications = [];
try {
    $sql = "SELECT n.* FROM notifications n WHERE n.user_id = ?";
    $params = [$me['id']];
    
    if ($status_filter === 'unread') {
        $sql .= " AND n.status = 'unread'";
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 30;
    $offset = ($page - 1) * $per_page;
    
    $sql .= " ORDER BY n.created_at DESC LIMIT $per_page OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $notifications = [];
}

$new_notifs = [];
$earlier_notifs = [];
$today_str = date('Y-m-d');

foreach ($notifications as $n) {
    $date_str = date('Y-m-d', strtotime($n['created_at']));
    if ($n['status'] === 'unread' || $date_str === $today_str) {
        $new_notifs[] = $n;
    } else {
        $earlier_notifs[] = $n;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Notifications page ── */
body[data-page="notifications"] .main {
    background: #f0f2f5 !important;
}

/* Reset any conflicting styles on wrapper */
.notif-page-wrap {
    width: 100%;
    padding: 20px 0 60px;
    box-sizing: border-box;
}

.notif-page-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #dde3ee;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    width: 100%;
    max-width: 900px;
    margin: 0 auto;
    min-height: 70vh;
    overflow: hidden;
    box-sizing: border-box;
}

/* Page header */
.notif-page-hdr {
    padding: 22px 28px 18px;
    border-bottom: 2px solid #f0f2f5;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.notif-page-title {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.notif-page-title i {
    color: #002F6C;
    font-size: 22px;
}

.notif-mark-all-btn {
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 600;
    border-radius: 8px;
    border: 1.5px solid #002F6C;
    background: #fff;
    color: #002F6C;
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}
.notif-mark-all-btn:hover {
    background: #002F6C;
    color: #fff;
}

/* Filter tabs */
.notif-filter-bar {
    display: flex;
    gap: 10px;
    padding: 14px 28px;
    background: #f8fafc;
    border-bottom: 1px solid #e8ecf2;
}

.notif-filter-pill {
    padding: 8px 20px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none !important;
    border: 2px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    transition: all 0.18s;
    display: inline-block;
}
.notif-filter-pill.active,
.notif-filter-pill:hover {
    background: #002F6C;
    color: #fff !important;
    border-color: #002F6C;
}

/* Section headings */
.notif-section-label {
    padding: 14px 28px 6px;
    font-size: 12px;
    font-weight: 800;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Individual notification row */
.notif-row {
    display: table;
    width: 100%;
    max-width: 100%;
    table-layout: fixed;    /* columns NEVER exceed 100% total */
    border-collapse: collapse;
    cursor: pointer;
    text-decoration: none !important;
    box-sizing: border-box;
}

.notif-row:hover .notif-row-inner {
    background: #f8fafc;
}

.notif-row.is-unread .notif-row-inner {
    background: #eff6ff;
}
.notif-row.is-unread:hover .notif-row-inner {
    background: #dbeafe;
}

.notif-row-inner {
    display: table-row;
    border-radius: 10px;
    transition: background 0.18s;
}

.notif-cell-avatar,
.notif-cell-content,
.notif-cell-meta {
    display: table-cell;
    vertical-align: middle;
    padding: 14px 0;
}

.notif-cell-avatar {
    width: 76px;
    padding-left: 20px;
    padding-right: 12px;
}

.notif-cell-content {
    /* Takes all remaining space — overflow-hidden so text can't push table wider */
    padding-right: 16px;
    word-break: break-word;
    overflow-wrap: break-word;
    overflow: hidden;
    max-width: 0;       /* trick: with table-layout:fixed, forces cell to use remaining space */
}

.notif-cell-meta {
    width: 1%;
    white-space: nowrap;
    padding-right: 20px;
    text-align: right;
}

/* Avatar */
.notif-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: linear-gradient(135deg, #e8edf5 0%, #d0d9e8 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 2px solid #fff;
    box-shadow: 0 2px 6px rgba(0,47,108,0.1);
    flex-shrink: 0;
    position: relative;
}

.notif-avatar img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.notif-avatar-icon {
    font-size: 20px;
    color: #94a3b8;
}

.notif-type-badge {
    position: absolute;
    bottom: -3px;
    right: -3px;
    width: 20px;
    height: 20px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    color: #fff;
    font-size: 9px;
}

/* Content area */
.notif-title-text {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 3px;
    line-height: 1.4;
}

.notif-msg-text {
    font-size: 13px;
    color: #64748b;
    line-height: 1.5;
}

/* Meta: time + unread dot */
.notif-time-badge {
    display: inline-block;
    font-size: 12px;
    font-weight: 700;
    color: #3b82f6;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 20px;
    padding: 3px 10px;
}

.notif-row:not(.is-unread) .notif-time-badge {
    color: #94a3b8;
    background: #f1f5f9;
    border-color: #e2e8f0;
}

.notif-unread-dot {
    display: block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #3b82f6;
    margin: 6px 0 0 auto;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
}

/* Dividers between rows */
.notif-row + .notif-row .notif-row-inner {
    border-top: 1px solid #f1f5f9;
}

/* Empty state */
.notif-empty {
    padding: 80px 20px;
    text-align: center;
    color: #94a3b8;
}
.notif-empty i {
    font-size: 56px;
    color: #cbd5e1;
    display: block;
    margin-bottom: 16px;
}
.notif-empty p {
    font-size: 16px;
    margin: 0;
}

/* Load more */
.notif-load-more {
    display: block;
    margin: 20px 28px;
    padding: 14px 0;
    text-align: center;
    background: #f8fafc;
    color: #002F6C;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none !important;
    border: 2px solid #dde3ee;
    transition: all 0.18s;
}
.notif-load-more:hover {
    background: #002F6C;
    color: #fff !important;
    border-color: #002F6C;
}

/* Badge colours */
.ntb-transaction { background: #3b82f6; }
.ntb-joborder    { background: #f59e0b; }
.ntb-inventory   { background: #ef4444; }
.ntb-customer    { background: #8b5cf6; }
.ntb-delivery    { background: #10b981; }
.ntb-calendar    { background: #06b6d4; }
.ntb-report      { background: #64748b; }
.ntb-warning     { background: #f59e0b; }
.ntb-error       { background: #ef4444; }
.ntb-success     { background: #10b981; }
.ntb-default     { background: #002F6C; }
</style>

<div class="notif-page-wrap">
    <div class="notif-page-card">

        <!-- Header -->
        <div class="notif-page-hdr">
            <h1 class="notif-page-title">
                <i class="fas fa-bell"></i>
                Notifications
            </h1>
            <button class="notif-mark-all-btn" onclick="markAllRead()">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
        </div>

        <!-- Filter pills -->
        <div class="notif-filter-bar">
            <a href="?filter=all"    class="notif-filter-pill <?php echo $status_filter === 'all'    ? 'active' : ''; ?>">All</a>
            <a href="?filter=unread" class="notif-filter-pill <?php echo $status_filter === 'unread' ? 'active' : ''; ?>">Unread</a>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="notif-empty">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
        <?php else: ?>

            <?php if (!empty($new_notifs)): ?>
                <div class="notif-section-label">New</div>
                <?php foreach ($new_notifs as $n) renderNotifRow($n); ?>
            <?php endif; ?>

            <?php if (!empty($earlier_notifs)): ?>
                <div class="notif-section-label">Earlier</div>
                <?php foreach ($earlier_notifs as $n) renderNotifRow($n); ?>
            <?php endif; ?>

            <?php if (count($notifications) == 30): ?>
                <a href="?filter=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="notif-load-more">
                    <i class="fas fa-chevron-down"></i> See previous notifications
                </a>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<form id="actionForm" method="post" style="display:none;">
    <input type="hidden" name="action" id="formAction">
    <input type="hidden" name="notification_id" id="formNotifId">
</form>

<script>
async function handleNotificationClick(id, url) {
    try {
        const fd = new FormData();
        fd.append('notification_id', id);
        await fetch('../backend/api/notifications_api.php?action=mark_read', { method: 'POST', body: fd });
    } catch (e) { console.error(e); }
    if (url && url !== '#') {
        const resolved = typeof window.resolveRedirectUrl === 'function' ? window.resolveRedirectUrl(url) : url;
        window.location.href = resolved;
    } else {
        window.location.reload();
    }
}

function markAllRead() {
    if (confirm('Mark all notifications as read?')) {
        document.getElementById('formAction').value = 'mark_all_read';
        document.getElementById('actionForm').submit();
    }
}
</script>

<?php
function renderNotifRow($n) {
    $unread  = $n['status'] === 'unread';
    $url     = !empty($n['redirect_url']) ? htmlspecialchars($n['redirect_url']) : '#';
    $onClick = "handleNotificationClick({$n['id']}, '" . addslashes($url) . "')";

    // Time ago
    $diff = max(0, time() - strtotime($n['created_at']));
    if      ($diff < 60)     $ago = max(1,$diff).'s';
    elseif  ($diff < 3600)   $ago = floor($diff/60).'m';
    elseif  ($diff < 86400)  $ago = floor($diff/3600).'h';
    elseif  ($diff < 604800) $ago = floor($diff/86400).'d';
    else                     $ago = floor($diff/604800).'w';

    // Icon & colour
    $evt = $n['event_type'] ?? '';
    $badgeClass = 'ntb-default';
    $faIcon     = 'bell';
    if      ($evt === 'transaction')                   { $badgeClass = 'ntb-transaction'; $faIcon = 'shopping-cart'; }
    elseif  (in_array($evt,['joborder','job_order']))  { $badgeClass = 'ntb-joborder';    $faIcon = 'tools'; }
    elseif  ($evt === 'inventory')                     { $badgeClass = 'ntb-inventory';   $faIcon = 'boxes'; }
    elseif  ($evt === 'customer')                      { $badgeClass = 'ntb-customer';    $faIcon = 'user'; }
    elseif  ($evt === 'delivery')                      { $badgeClass = 'ntb-delivery';    $faIcon = 'truck'; }
    elseif  ($evt === 'calendar')                      { $badgeClass = 'ntb-calendar';    $faIcon = 'calendar-alt'; }
    elseif  ($evt === 'report')                        { $badgeClass = 'ntb-report';      $faIcon = 'file-alt'; }
    else {
        $t = $n['type'] ?? '';
        if      ($t === 'success') { $badgeClass = 'ntb-success'; $faIcon = 'check'; }
        elseif  ($t === 'warning') { $badgeClass = 'ntb-warning'; $faIcon = 'exclamation'; }
        elseif  ($t === 'error')   { $badgeClass = 'ntb-error';   $faIcon = 'times'; }
    }

    $rowClass = $unread ? 'notif-row is-unread' : 'notif-row';
    $title    = htmlspecialchars($n['title']   ?? '');
    $message  = htmlspecialchars($n['message'] ?? '');

    echo <<<HTML
<div class="{$rowClass}" onclick="{$onClick}">
    <div class="notif-row-inner">
        <div class="notif-cell-avatar">
            <div class="notif-avatar">
                <img src="../assets/img/Petron Logo.png" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\\'fas fa-building notif-avatar-icon\\'></i>';" />
                <div class="notif-type-badge {$badgeClass}">
                    <i class="fas fa-{$faIcon}"></i>
                </div>
            </div>
        </div>
        <div class="notif-cell-content">
            <div class="notif-title-text">{$title}</div>
            <div class="notif-msg-text">{$message}</div>
        </div>
        <div class="notif-cell-meta">
            <span class="notif-time-badge">{$ago}</span>
HTML;
    if ($unread) {
        echo '<span class="notif-unread-dot"></span>';
    }
    echo <<<HTML
        </div>
    </div>
</div>
HTML;
}

include __DIR__ . '/../partials/footer.php';
?>
