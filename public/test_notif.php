<?php
$page_id = 'notifications';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
$me = ['id' => 1, 'role' => 'staff'];

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
    /* Modern Professional Notifications UI */
    body[data-page="notifications"] .main {
        padding: 20px 24px;
        background-color: #f5f7fa;
        overflow-x: hidden;
    }
    
    .fb-notifications-wrapper {
        display: flex;
        justify-content: center;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        width: 100%;
    }

    .fb-notifications-container {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        width: 100%;
        max-width: 1000px; /* Reduced slightly for better centered layout */
        min-height: 80vh;
        padding-bottom: 24px;
        border: 1px solid #e5e7eb;
        margin: 0 auto;
    }
    
    .fb-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 28px 24px 20px;
        border-bottom: 2px solid #f1f3f5;
    }
    
    .fb-title {
        font-size: 32px;
        font-weight: 800;
        color: #1a202c;
        margin: 0;
        letter-spacing: -0.5px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .fb-title i {
        color: #002F6C;
        font-size: 28px;
    }
    
    .fb-header-actions {
        display: flex;
        gap: 10px;
    }

    .fb-icon-btn {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 14px;
    }
    .fb-icon-btn:hover {
        background: #002F6C;
        color: white;
        border-color: #002F6C;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 47, 108, 0.2);
    }

    .fb-filters {
        display: flex;
        padding: 16px 24px;
        gap: 12px;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
    }

    .fb-filter-pill {
        padding: 10px 18px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
        border: 2px solid transparent;
    }

    .fb-filter-pill.active {
        background: #002F6C;
        color: white;
        box-shadow: 0 2px 8px rgba(0, 47, 108, 0.25);
    }

    .fb-filter-pill:not(.active) {
        background: white;
        color: #64748b;
        border-color: #e5e7eb;
    }

    .fb-filter-pill:not(.active):hover {
        background: #f1f5f9;
        color: #002F6C;
        border-color: #002F6C;
    }

    .fb-group-title {
        padding: 16px 24px 8px;
        font-size: 13px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-top: 8px;
    }

    .fb-notif-item {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: flex-start; /* Better alignment for multi-line text */
        padding: 16px 24px;
        cursor: pointer;
        text-decoration: none;
        position: relative;
        transition: all 0.2s;
        border-radius: 12px;
        margin: 6px 16px;
        border: 2px solid transparent;
        width: calc(100% - 32px); /* Ensure it stays inside the container */
        box-sizing: border-box;
    }
    
    .fb-notif-item:hover {
        background: #f8fafc;
        border-color: #e5e7eb;
        transform: translateX(4px);
    }
    
    .fb-notif-item.unread {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    .fb-notif-item.unread:hover {
        background: #dbeafe;
        border-color: #93c5fd;
    }

    .fb-avatar-container {
        position: relative;
        width: 60px;
        height: 60px;
        flex-shrink: 0;
        margin-right: 16px;
    }

    .fb-avatar {
        width: 100%;
        height: 100%;
        border-radius: 12px;
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        border: 2px solid white;
    }

    .fb-avatar i {
        font-size: 26px;
        color: #94a3b8;
    }
    .fb-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .fb-badge {
        position: absolute;
        bottom: -4px;
        right: -4px;
        width: 32px;
        height: 32px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3px solid #fff;
        color: #fff;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }

    .badge-transaction { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .badge-joborder { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .badge-inventory { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .badge-customer { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
    .badge-delivery { background: linear-gradient(135deg, #10b981, #059669); }
    .badge-calendar { background: linear-gradient(135deg, #06b6d4, #0891b2); }
    .badge-report { background: linear-gradient(135deg, #64748b, #475569); }
    .badge-default { background: linear-gradient(135deg, #002F6C, #001f4d); }

    .fb-badge i {
        font-size: 13px;
    }

    .fb-content {
        flex: 1;
        min-width: 0; /* Important for flex-child text truncation */
        width: 100%;
        line-height: 1.5;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding-right: 20px;
    }

    .fb-message {
        font-size: 15px;
        color: #1e293b;
        margin-bottom: 0;
        word-break: normal;
        overflow-wrap: anywhere;
    }

    .fb-message strong {
        font-weight: 700;
        color: #0f172a;
    }
    
    .fb-message-detail {
        color: #64748b;
        font-size: 14px;
        margin-top: 4px;
        line-height: 1.4;
    }

    .fb-time {
        font-size: 13px;
        color: #3b82f6;
        font-weight: 700;
        margin-left: auto;
        white-space: nowrap;
        flex-shrink: 0;
        padding: 4px 12px;
        background: #eff6ff;
        border-radius: 20px;
        border: 1px solid #bfdbfe;
    }

    .fb-notif-item:not(.unread) .fb-time {
        color: #94a3b8;
        font-weight: 600;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .fb-unread-dot {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: #3b82f6;
        flex-shrink: 0;
        margin-left: 12px;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
    }

    .fb-empty {
        padding: 80px 20px;
        text-align: center;
        color: #94a3b8;
        font-size: 16px;
    }
    
    .fb-empty i {
        font-size: 64px;
        color: #cbd5e1;
        margin-bottom: 16px;
        display: block;
    }

    .fb-load-more {
        display: block;
        margin: 24px 16px 0;
        padding: 14px 0;
        text-align: center;
        background: #f8fafc;
        color: #002F6C;
        border-radius: 10px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
        border: 2px solid #e5e7eb;
    }
    .fb-load-more:hover {
        background: #002F6C;
        color: white;
        border-color: #002F6C;
        box-shadow: 0 4px 12px rgba(0, 47, 108, 0.2);
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .fb-notifications-wrapper {
            padding: 16px;
        }
        .fb-header {
            padding: 20px 16px;
        }
        .fb-title {
            font-size: 24px;
        }
        .fb-filters {
            padding: 12px 16px;
        }
        .fb-notif-item {
            padding: 14px 16px;
            margin: 4px 12px;
        }
        .fb-avatar-container {
            width: 50px;
            height: 50px;
        }
        .fb-badge {
            width: 28px;
            height: 28px;
        }
    }
</style>

<div class="fb-notifications-wrapper">
    <div class="fb-notifications-container">
        
        <div class="fb-header">
            <h1 class="fb-title">
                <i class="fas fa-bell"></i>
                Notifications
            </h1>
            <div class="fb-header-actions">
                <button class="fb-icon-btn" onclick="markAllRead()" title="Mark all as read">
                    <i class="fas fa-check-double"></i>
                </button>
            </div>
        </div>
        
        <div class="fb-filters">
            <a href="?filter=all" class="fb-filter-pill <?php echo $status_filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?filter=unread" class="fb-filter-pill <?php echo $status_filter === 'unread' ? 'active' : ''; ?>">Unread</a>
        </div>
        
        <?php if (empty($notifications)): ?>
            <div class="fb-empty">
                <i class="fas fa-bell-slash"></i>
                <p>You have no notifications</p>
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
            const resolvedUrl = typeof window.resolveRedirectUrl === 'function' ? window.resolveRedirectUrl(url) : url;
            window.location.href = resolvedUrl;
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
    elseif ($evt === 'joborder' || $evt === 'job_order') { $badgeClass = 'badge-joborder'; $faIcon = 'tools'; }
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
                    <img src="../assets/img/Petron Logo.png" style="width: 36px; height: 36px; object-fit: contain;" onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<i class=\\\'fas fa-building\\\'></i>\';" />
                </div>
                <div class="fb-badge ' . $badgeClass . '">
                    <i class="fas fa-' . $faIcon . '"></i>
                </div>
            </div>
            <div class="fb-content">
                <div class="fb-message">
                    <strong>' . htmlspecialchars($n['title']) . '</strong>
                </div>
                <div class="fb-message-detail">' . htmlspecialchars($n['message']) . '</div>
            </div>
            <div class="fb-time">' . $ago . '</div>';
            
    if ($unread) {
        echo '<div class="fb-unread-dot"></div>';
    }
            
    echo '</div>';
}

include __DIR__ . '/../partials/footer.php'; 
?>

