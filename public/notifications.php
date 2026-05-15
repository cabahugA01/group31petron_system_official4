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
    
    if ($action === 'mark_read') {
        $notification_id = $_POST['notification_id'] ?? '';
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $me['id']]);
        $notice = '✅ Notification marked as read';
    }
    
    elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'unread'");
        $stmt->execute([$me['id']]);
        $notice = '✅ All notifications marked as read';
    }
    
    elseif ($action === 'delete_notification') {
        $notification_id = $_POST['notification_id'] ?? '';
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $me['id']]);
        $notice = '✅ Notification deleted successfully';
    }
    
    elseif ($action === 'batch_delete') {
        $notification_ids = $_POST['notification_ids'] ?? [];
        if (!empty($notification_ids)) {
            $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($notification_ids, [$me['id']]);
            $stmt->execute($params);
            $notice = '✅ Selected notifications deleted successfully';
        }
    }
    
    elseif ($action === 'batch_mark_read') {
        $notification_ids = $_POST['notification_ids'] ?? [];
        if (!empty($notification_ids)) {
            $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($notification_ids, [$me['id']]);
            $stmt->execute($params);
            $notice = '✅ Selected notifications marked as read';
        }
    }
}

// Get filter parameters
$type_filter = $_GET['type'] ?? '';
$date_range = $_GET['date_range'] ?? '';
$search = $_GET['search'] ?? '';

// Parse date range
$start_date = '';
$end_date = '';
if ($date_range) {
    $dates = explode(' to ', $date_range);
    $start_date = $dates[0] ?? '';
    $end_date = $dates[1] ?? $start_date;
}

// Set default date range if none provided
if (!$date_range) {
    $today = new DateTime();
    $lastWeek = new DateTime($today->format('Y-m-d'));
    $lastWeek->sub(new DateInterval('P7D'));
    $start_date = $lastWeek->format('Y-m-d');
    $end_date = $today->format('Y-m-d');
    $date_range = "$start_date to $end_date";
}

// Fetch notifications with role-based filtering
$notifications = [];
$total_notifications = 0;

try {
    // Base query for role-specific notifications
    $sql = "SELECT n.* FROM notifications n 
            INNER JOIN users u ON n.user_id = u.id 
            WHERE n.user_id = ? AND u.role = ?";
    $params = [$me['id'], $me['role']];
    
    if ($type_filter) {
        $sql .= " AND n.type = ?";
        $params[] = $type_filter;
    }
    
    if ($start_date && $end_date) {
        $sql .= " AND DATE(n.created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    if ($search) {
        $sql .= " AND (n.title LIKE ? OR n.message LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Get total count
    $count_sql = str_replace("SELECT n.*", "SELECT COUNT(*)", $sql);
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_notifications = $stmt->fetchColumn();
    
    // Add pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $sql .= " ORDER BY n.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Create notifications table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('success', 'warning', 'error', 'info') NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT,
        status ENUM('read', 'unread') DEFAULT 'unread',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Create role-specific sample notifications
    $role_notifications = get_role_notification_types($role);
    $sample_notifications = [];
    
    foreach ($role_notifications as $key => $notif) {
        $sample_notifications[] = [
            'user_id' => $me['id'], 
            'type' => $notif['type'], 
            'title' => $notif['title'], 
            'message' => $notif['message']
        ];
    }
    
    // Add some generic notifications if role-specific ones are few
    if (count($sample_notifications) < 3) {
        $generic_notifications = [
            ['user_id' => $me['id'], 'type' => 'success', 'title' => 'Export Completed', 'message' => 'Your data export was completed successfully'],
            ['user_id' => $me['id'], 'type' => 'info', 'title' => 'System Update', 'message' => 'New system features are now available']
        ];
        $sample_notifications = array_merge($sample_notifications, $generic_notifications);
    }
    
    foreach ($sample_notifications as $notif) {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$notif['user_id'], $notif['type'], $notif['title'], $notif['message']]);
    }
    
    // Re-fetch notifications with role-based filtering
    $stmt = $pdo->prepare("SELECT n.* FROM notifications n 
                           INNER JOIN users u ON n.user_id = u.id 
                           WHERE n.user_id = ? AND u.role = ? 
                           ORDER BY n.created_at DESC LIMIT 20");
    $stmt->execute([$me['id'], $me['role']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_notifications = count($notifications);
}

include __DIR__ . '/../partials/header.php';
?>

<style>
    /* Notifications Page Styles */
    .notifications-container {
        padding: 24px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 32px;
    }
    
    .page-title {
        font-size: 28px;
        font-weight: 800;
        color: var(--petron-blue);
        margin-bottom: 8px;
    }
    
    .page-subtitle {
        color: var(--muted);
        font-size: 14px;
    }
    
    .filter-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .filter-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    
    .filter-buttons {
        display: flex;
        gap: 12px;
    }
    
    .batch-actions {
        display: flex;
        gap: 8px;
    }
    
    .table-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    
    .table-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
    }
    
    .table-container {
        overflow-x: auto;
    }
    
    .notifications-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    .notifications-table thead th {
        background: #f8fafc;
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        color: var(--muted);
        font-size: 12px;
        border-bottom: 1px solid var(--line);
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .notifications-table tbody td {
        padding: 16px;
        border-bottom: 1px solid var(--line);
        font-size: 14px;
    }
    
    .notifications-table tbody tr.unread {
        background: rgba(59, 130, 246, 0.05);
    }
    
    .notifications-table tbody tr:hover {
        background: #f8fafc;
    }
    
    .notification-type-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: bold;
        color: white;
    }
    
    .notification-type-icon i {
        font-size: 14px;
        margin: 0;
        padding: 0;
        line-height: 1;
    }
    
    .icon-success {
        background: #16a34a;
    }
    
    .icon-warning {
        background: #d97706;
    }
    
    .icon-error {
        background: #dc2626;
    }
    
    .icon-info {
        background: #2563eb;
    }
    
    .notification-content {
        flex: 1;
    }
    
    .notification-title {
        font-weight: 600;
        color: var(--text);
        margin-bottom: 4px;
    }
    
    .notification-message {
        color: var(--muted);
        font-size: 13px;
        line-height: 1.4;
    }
    
    .notification-time {
        color: var(--muted);
        font-size: 12px;
        white-space: nowrap;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid;
    }
    
    .status-unread {
        background: rgba(37, 99, 235, 0.1);
        color: #2563eb;
        border-color: rgba(37, 99, 235, 0.2);
    }
    
    .status-read {
        background: rgba(107, 114, 128, 0.1);
        color: #6b7280;
        border-color: rgba(107, 114, 128, 0.2);
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 12px;
    }
    
    .btn-icon:hover {
        background: #f8fafc;
    }
    
    .btn-icon.read {
        color: #2563eb;
        border-color: rgba(37, 99, 235, 0.2);
    }
    
    .btn-icon.delete {
        color: #dc2626;
        border-color: rgba(220, 38, 38, 0.2);
    }
    
    .btn-icon.info {
        color: #6b7280;
        border-color: rgba(107, 114, 128, 0.2);
    }
    
    .checkbox-cell {
        width: 40px;
    }
    
    .form-checkbox {
        width: 16px;
        height: 16px;
        border: 1px solid var(--line);
        border-radius: 4px;
        cursor: pointer;
    }
    
    .modal {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }
    
    .modal.show {
        display: flex;
    }
    
    .modal-content {
        background: var(--card);
        border-radius: 16px;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    
    .modal-header {
        padding: 24px 24px 16px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text);
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: #f8fafc;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--line);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .btn {
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary {
        background: var(--petron-blue);
        color: white;
    }
    
    .btn-primary:hover {
        background: #002455;
    }
    
    .btn-secondary {
        background: #f8fafc;
        color: var(--muted);
        border: 1px solid var(--line);
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }
    
    .btn-danger {
        background: #dc2626;
        color: white;
    }
    
    .btn-danger:hover {
        background: #b91c1c;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-label {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }
    
    .form-input, .form-select {
        padding: 10px 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        font-size: 14px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
    }
    
    .form-input:focus, .form-select:focus {
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
    }
    
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 16px;
        box-shadow: var(--shadow);
        display: none;
        align-items: center;
        gap: 12px;
        z-index: 2000;
        min-width: 300px;
    }
    
    .toast.show {
        display: flex;
    }
    
    .toast.success {
        border-left: 4px solid #16a34a;
    }
    
    .toast.error {
        border-left: 4px solid #dc2626;
    }
    
    .toast.info {
        border-left: 4px solid #2563eb;
    }
    
    .toast-icon {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: white;
        flex-shrink: 0;
    }
    
    .toast.success .toast-icon {
        background: #16a34a;
    }
    
    .toast.error .toast-icon {
        background: #dc2626;
    }
    
    .toast.info .toast-icon {
        background: #2563eb;
    }
    
    .toast-message {
        flex: 1;
        font-size: 14px;
        color: var(--text);
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        padding: 20px;
    }
    
    .pagination-btn {
        padding: 8px 12px;
        border: 1px solid var(--line);
        background: #fff;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s;
    }
    
    .pagination-btn:hover {
        background: #f8fafc;
    }
    
    .pagination-btn.active {
        background: var(--petron-blue);
        color: white;
        border-color: var(--petron-blue);
    }
    
    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: var(--muted);
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.3;
        color: var(--muted);
    }
    
    .empty-icon i {
        font-size: 48px;
        margin: 0;
        padding: 0;
    }
    
    .empty-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .empty-description {
        font-size: 14px;
        margin-bottom: 24px;
    }
</style>

<div class="notifications-container">
    <?php if ($notice): ?>
        <div class="toast success show" id="noticeToast">
            <div class="toast-icon"><i class="fas fa-check"></i></div>
            <div class="toast-message"><?php echo htmlspecialchars($notice); ?></div>
        </div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            Notifications 
            <span style="font-size: 16px; font-weight: 500; color: var(--muted); text-transform: capitalize;">
                (<?php echo htmlspecialchars($role); ?>)
            </span>
        </h1>
        <p class="page-subtitle">Manage your <?php echo htmlspecialchars($role); ?> system notifications and alerts</p>
    </div>
    
    <!-- Filter Card -->
    <div class="filter-card">
        <form method="get">
            <div class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="success" <?php echo $type_filter === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="warning" <?php echo $type_filter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="error" <?php echo $type_filter === 'error' ? 'selected' : ''; ?>>Error</option>
                        <option value="info" <?php echo $type_filter === 'info' ? 'selected' : ''; ?>>Info</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <input type="text" name="date_range" class="form-input" value="<?php echo htmlspecialchars($date_range); ?>" placeholder="YYYY-MM-DD to YYYY-MM-DD">
                </div>
            </div>
            <div class="filter-actions">
                <div class="filter-buttons">
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Reset</button>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
                <div class="batch-actions">
                    <button type="button" class="btn btn-secondary" onclick="markAllRead()">
                        <i class="fas fa-check"></i> Mark All as Read
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Table Card -->
    <div class="table-card">
        <div class="table-header">
            <h2 class="table-title">Notifications (<?php echo $total_notifications; ?>)</h2>
            <div class="batch-actions" id="batchActions" style="display: none;">
                <button type="button" class="btn btn-secondary" onclick="batchMarkRead()">
                    <i class="fas fa-check"></i> Mark as Read
                </button>
                <button type="button" class="btn btn-danger" onclick="batchDelete()">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
        <div class="table-container">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-bell"></i></div>
                    <div class="empty-title">No notifications found</div>
                    <div class="empty-description">You're all caught up! No notifications match your criteria.</div>
                </div>
            <?php else: ?>
                <table class="notifications-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell">
                                <input type="checkbox" class="form-checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>Type</th>
                            <th>Title / Message</th>
                            <th>Timestamp</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notification): ?>
                            <tr class="<?php echo $notification['status'] === 'unread' ? 'unread' : ''; ?>">
                                <td class="checkbox-cell">
                                    <input type="checkbox" class="form-checkbox notification-checkbox" value="<?php echo $notification['id']; ?>" onchange="updateBatchActions()">
                                </td>
                                <td>
                                    <div class="notification-type-icon icon-<?php echo $notification['type']; ?>">
                                        <i class="fas fa-<?php
                                        $icons = [
                                            'success' => 'check-circle',
                                            'warning' => 'exclamation-triangle',
                                            'error' => 'times-circle',
                                            'info' => 'info-circle'
                                        ];
                                        echo $icons[$notification['type']] ?? 'info-circle';
                                        ?>"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="notification-time">
                                        <?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $notification['status']; ?>">
                                        <?php echo ucfirst($notification['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($notification['status'] === 'unread'): ?>
                                            <button class="btn-icon read" onclick="markAsRead(<?php echo $notification['id']; ?>)" title="Mark as Read">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-icon info" onclick="viewDetails(<?php echo $notification['id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="deleteNotification(<?php echo $notification['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_notifications > 20): ?>
            <div class="pagination">
                <?php
                $total_pages = ceil($total_notifications / 20);
                $current_page = max(1, intval($_GET['page'] ?? 1));
                
                // Previous button
                if ($current_page > 1):
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" class="pagination-btn">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                
                <!-- Page numbers -->
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="pagination-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <!-- Next button -->
                <?php if ($current_page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" class="pagination-btn">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Notification Details Modal -->
<div class="modal" id="detailsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Notification Details</h3>
            <button class="modal-close" onclick="closeDetailsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="detailsContent">
            <!-- Content will be loaded here -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Close</button>
            <button type="button" class="btn btn-primary" id="markReadBtn" onclick="markAsReadFromModal()">Mark as Read</button>
        </div>
    </div>
</div>

<!-- Batch Actions Form (hidden) -->
<form method="post" id="batchForm" style="display: none;">
    <input type="hidden" name="action" id="batchAction">
    <input type="hidden" name="notification_ids" id="batchIds">
</form>

<!-- Single Action Form (hidden) -->
<form method="post" id="singleActionForm" style="display: none;">
    <input type="hidden" name="action" id="singleAction">
    <input type="hidden" name="notification_id" id="singleId">
</form>

<script>
// Global variables
let currentNotificationId = null;

// Toggle select all checkboxes
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.notification-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateBatchActions();
}

// Update batch actions visibility
function updateBatchActions() {
    const checkboxes = document.querySelectorAll('.notification-checkbox:checked');
    const batchActions = document.getElementById('batchActions');
    
    if (checkboxes.length > 0) {
        batchActions.style.display = 'flex';
    } else {
        batchActions.style.display = 'none';
    }
}

// Mark notification as read
function markAsRead(id) {
    document.getElementById('singleAction').value = 'mark_read';
    document.getElementById('singleId').value = id;
    document.getElementById('singleActionForm').submit();
}

// Mark all notifications as read
function markAllRead() {
    if (confirm('Are you sure you want to mark all notifications as read?')) {
        document.getElementById('singleAction').value = 'mark_all_read';
        document.getElementById('singleActionForm').submit();
    }
}

// Batch mark as read
function batchMarkRead() {
    const checkboxes = document.querySelectorAll('.notification-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        showToast('Please select notifications to mark as read', 'error');
        return;
    }
    
    document.getElementById('batchAction').value = 'batch_mark_read';
    document.getElementById('batchIds').value = ids.join(',');
    document.getElementById('batchForm').submit();
}

// Batch delete
function batchDelete() {
    const checkboxes = document.querySelectorAll('.notification-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        showToast('Please select notifications to delete', 'error');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${ids.length} notification(s)?`)) {
        document.getElementById('batchAction').value = 'batch_delete';
        document.getElementById('batchIds').value = ids.join(',');
        document.getElementById('batchForm').submit();
    }
}

// Delete single notification
function deleteNotification(id) {
    if (confirm('Are you sure you want to delete this notification?')) {
        document.getElementById('singleAction').value = 'delete_notification';
        document.getElementById('singleId').value = id;
        document.getElementById('singleActionForm').submit();
    }
}

// View notification details
function viewDetails(id) {
    currentNotificationId = id;
    
    // Fetch notification details
    fetch(`get_notification.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            const detailsContent = document.getElementById('detailsContent');
            const icons = {
                'success': 'fa-check-circle',
                'warning': 'fa-exclamation-triangle',
                'error': 'fa-times-circle',
                'info': 'fa-info-circle'
            };
            
            detailsContent.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div class="notification-type-icon icon-${data.type}">
                        <i class="fas ${icons[data.type] || 'fa-info-circle'}"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 18px; font-weight: 700;">${data.title}</h4>
                        <p style="margin: 4px 0 0 0; color: var(--muted); font-size: 14px;">
                            ${new Date(data.created_at).toLocaleString()}
                        </p>
                    </div>
                </div>
                <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border-left: 4px solid var(--petron-blue);">
                    <p style="margin: 0; line-height: 1.6;">${data.message}</p>
                </div>
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--line);">
                    <div style="display: flex; justify-content: space-between; font-size: 14px;">
                        <span><strong>Status:</strong> <span class="status-badge status-${data.status}">${data.status}</span></span>
                        <span><strong>Type:</strong> ${data.type}</span>
                    </div>
                </div>
            `;
            
            // Show/hide mark as read button
            const markReadBtn = document.getElementById('markReadBtn');
            if (data.status === 'unread') {
                markReadBtn.style.display = 'inline-flex';
            } else {
                markReadBtn.style.display = 'none';
            }
            
            document.getElementById('detailsModal').classList.add('show');
        })
        .catch(error => {
            console.error('Error fetching notification details:', error);
            showToast('Error loading notification details', 'error');
        });
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('show');
    currentNotificationId = null;
}

// Mark as read from modal
function markAsReadFromModal() {
    if (currentNotificationId) {
        markAsRead(currentNotificationId);
    }
}

// Reset filters
function resetFilters() {
    window.location.href = 'notifications.php';
}

// Show toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type} show`;
    
    const icons = {
        'success': 'fa-check',
        'error': 'fa-times',
        'info': 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <div class="toast-icon"><i class="fas ${icons[type] || 'fa-info-circle'}"></i></div>
        <div class="toast-message">${message}</div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Auto-hide notice toast
<?php if ($notice): ?>
setTimeout(() => {
    const noticeToast = document.getElementById('noticeToast');
    if (noticeToast) {
        noticeToast.remove();
    }
}, 3000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
