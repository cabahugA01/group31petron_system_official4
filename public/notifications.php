<?php
$page_id = 'notifications';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

// Allow all operational roles (including staff) to view their own notifications
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

// Get filter parameters
$status_filter = $_GET['filter'] ?? 'all'; // 'all' or 'unread'

// Fetch notifications
$notifications = [];
try {
    $sql = "SELECT n.* FROM notifications n WHERE n.user_id = ?";
    $params = [$me['id']];
    
    if ($status_filter === 'unread') {
        $sql .= " AND n.status = 'unread'";
    }
    
    // Add pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 30; // Load 30 at a time like a feed
    $offset = ($page - 1) * $per_page;
    
    $sql .= " ORDER BY n.created_at DESC LIMIT $per_page OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $notifications = [];
}

// Group notifications into 'New' (unread or from today) and 'Earlier'
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
    /* Exact Facebook-style Notifications UI */
    body {
        background-color: #f0f2f5;
    }
    
    .fb-notifications-wrapper {
        padding: 24px;
        display: flex;
        justify-content: center;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    .fb-notifications-container {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 1400px; /* Stretch left and right */
        min-height: 80vh;
        padding-bottom: 20px;
    }
    
    .fb-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 16px 12px;
    }
    
    .fb-title {
        font-size: 24px;
        font-weight: 700;
        color: #050505;
        margin: 0;
        letter-spacing: -0.5px;
    }
    
    .fb-header-actions {
        display: flex;
        gap: 8px;
    }

    .fb-icon-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #fff;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #65676B;
        cursor: pointer;
        transition: background 0.2s;
    }
    .fb-icon-btn:hover {
        background: #f0f2f5;
    }

    .fb-filters {
        display: flex;
        padding: 0 16px 12px;
        gap: 8px;
    }

    .fb-filter-pill {
        padding: 8px 12px;
        border-radius: 20px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.2s;
    }

    .fb-filter-pill.active {
        background: #e7f3ff;
        color: #1877f2;
    }

    .fb-filter-pill:not(.active) {
        background: transparent;
        color: #050505;
    }

    .fb-filter-pill:not(.active):hover {
        background: #f0f2f5;
    }

    .fb-group-title {
        padding: 8px 16px;
        font-size: 17px;
        font-weight: 600;
        color: #050505;
        margin-top: 8px;
    }

    .fb-notif-item {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        cursor: pointer;
        text-decoration: none;
        position: relative;
        transition: background 0.2s;
        border-radius: 8px;
        margin: 4px 8px;
    }
    
    .fb-notif-item:hover {
        background: #f0f2f5;
    }
    
    .fb-notif-item.unread {
        /* Unread has no background by default on FB, wait FB unread has a subtle tint? Or just the blue dot. Let's use a VERY subtle tint */
        background: #f7f9fd;
    }
    .fb-notif-item.unread:hover {
        background: #eef3f8;
    }

    .fb-avatar-container {
        position: relative;
        width: 56px;
        height: 56px;
        flex-shrink: 0;
        margin-right: 12px;
    }

    .fb-avatar {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: #e4e6eb;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    /* Use a generic system logo or avatar */
    .fb-avatar i {
        font-size: 28px;
        color: #bcc0c4;
    }
    .fb-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .fb-badge {
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff;
        color: #fff;
    }

    .badge-transaction { background: #1877f2; }
    .badge-joborder { background: #f5b041; }
    .badge-inventory { background: #e74c3c; }
    .badge-customer { background: #9b59b6; }
    .badge-delivery { background: #2ecc71; }
    .badge-calendar { background: #3498db; }
    .badge-report { background: #34495e; }
    .badge-default { background: #1877f2; }

    .fb-badge i {
        font-size: 12px;
    }

    .fb-content {
        flex: 1;
        min-width: 0;
        line-height: 1.3;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding-right: 16px;
    }

    .fb-message {
        font-size: 15px;
        color: #050505;
        margin-bottom: 4px;
        word-break: break-word;
    }

    .fb-message strong {
        font-weight: 600;
    }

    .fb-time {
        font-size: 13px;
        color: #1877f2;
        font-weight: 600;
        margin-left: auto; /* Push to the right edge */
        margin-right: 12px;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .fb-notif-item:not(.unread) .fb-time {
        color: #65676B;
        font-weight: normal;
    }

    .fb-unread-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #1877f2;
        flex-shrink: 0;
        margin-left: auto;
    }

    .fb-empty {
        padding: 40px 20px;
        text-align: center;
        color: #65676B;
        font-size: 15px;
    }

    .fb-load-more {
        display: block;
        margin: 16px 16px 0;
        padding: 8px 0;
        text-align: center;
        background: #e4e6eb;
        color: #050505;
        border-radius: 6px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.2s;
    }
    .fb-load-more:hover {
        background: #d8dadf;
    }
</style>

<div class="fb-notifications-wrapper">
    <div class="fb-notifications-container">
        
        <div class="fb-header">
            <h1 class="fb-title">Notifications</h1>
            <div class="fb-header-actions">
                <button class="fb-icon-btn" onclick="markAllRead()" title="Mark all as read">
                    <i class="fas fa-check"></i>
                </button>
            </div>
        </div>
        
        <div class="fb-filters">
            <a href="?filter=all" class="fb-filter-pill <?php echo $status_filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?filter=unread" class="fb-filter-pill <?php echo $status_filter === 'unread' ? 'active' : ''; ?>">Unread</a>
        </div>
        
        <?php if (empty($notifications)): ?>
            <div class="fb-empty">
                You have no notifications.
            </div>
        <?php else: ?>
            
            <!-- Render New -->
            <?php if (!empty($new_notifs)): ?>
                <div class="fb-group-title">New</div>
                <?php foreach ($new_notifs as $n) renderFbNotif($n); ?>
            <?php endif; ?>
            
            <!-- Render Earlier -->
            <?php if (!empty($earlier_notifs)): ?>
                <div class="fb-group-title">Earlier</div>
                <?php foreach ($earlier_notifs as $n) renderFbNotif($n); ?>
            <?php endif; ?>
            
            <?php if (count($notifications) == 30): ?>
            <a href="?filter=<?php echo $status_filter; ?>&page=<?php echo $page + 1; ?>" class="fb-load-more">
                See previous notifications
            </a>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</div>

<form id="actionForm" method="post" style="display: none;">
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
            window.location.href = url;
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
function renderFbNotif($n) {
    global $public_base_url;
    
    $unread = $n['status'] === 'unread';
    $url = !empty($n['redirect_url']) ? htmlspecialchars($n['redirect_url']) : '#';
    
    // Time formatting to match FB (e.g. 41m, 1w)
    $time_ago = strtotime($n['created_at']);
    $diff = time() - $time_ago;
    if ($diff < 60) $ago = max(1, $diff) . 's';
    elseif ($diff < 3600) $ago = floor($diff / 60) . 'm';
    elseif ($diff < 86400) $ago = floor($diff / 3600) . 'h';
    elseif ($diff < 604800) $ago = floor($diff / 86400) . 'd';
    else $ago = floor($diff / 604800) . 'w';
    
    // Setup Icon Badge and Color
    $evt = $n['event_type'] ?? '';
    $badgeClass = 'badge-default';
    $faIcon = 'bell';
    
    if ($evt === 'transaction') { $badgeClass = 'badge-transaction'; $faIcon = 'shopping-cart'; }
    elseif ($evt === 'joborder') { $badgeClass = 'badge-joborder'; $faIcon = 'tools'; }
    elseif ($evt === 'inventory') { $badgeClass = 'badge-inventory'; $faIcon = 'boxes'; }
    elseif ($evt === 'customer') { $badgeClass = 'badge-customer'; $faIcon = 'user'; }
    elseif ($evt === 'delivery') { $badgeClass = 'badge-delivery'; $faIcon = 'truck'; }
    elseif ($evt === 'calendar') { $badgeClass = 'badge-calendar'; $faIcon = 'calendar-alt'; }
    elseif ($evt === 'report') { $badgeClass = 'badge-report'; $faIcon = 'file-alt'; }
    else {
        if ($n['type'] === 'success') { $badgeClass = 'badge-delivery'; $faIcon = 'check'; }
        elseif ($n['type'] === 'warning') { $badgeClass = 'badge-joborder'; $faIcon = 'exclamation'; }
        elseif ($n['type'] === 'error') { $badgeClass = 'badge-inventory'; $faIcon = 'times'; }
    }
    
    // We can use a generic "system" avatar, or the Petron Logo
    // Let's use a nice generic system icon
    $avatarHtml = '<i class="fas fa-desktop"></i>';
    
    $onClick = "handleNotificationClick({$n['id']}, '" . addslashes($url) . "')";
    
    echo '<div class="fb-notif-item ' . ($unread ? 'unread' : '') . '" onclick="' . $onClick . '">
            <div class="fb-avatar-container">
                <div class="fb-avatar">
                    <img src="../assets/img/petron-logo.png" style="width: 32px; height: 32px; object-fit: contain;" onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<i class=\\\'fas fa-desktop\\\'></i>\';" />
                </div>
                <div class="fb-badge ' . $badgeClass . '">
                    <i class="fas fa-' . $faIcon . '"></i>
                </div>
            </div>
            <div class="fb-content" style="flex-direction: row; align-items: center; width: 100%;">
                <div class="fb-message" style="margin-bottom: 0;">
                    <strong>' . htmlspecialchars($n['title']) . '</strong><br>
                    <span style="color: #65676B; font-size: 14px;">' . htmlspecialchars($n['message']) . '</span>
                </div>
                <div class="fb-time">' . $ago . '</div>
            </div>';
            
    if ($unread) {
        echo '<div class="fb-unread-dot"></div>';
    }
            
    echo '</div>';
}

include __DIR__ . '/../partials/footer.php'; 
?>
