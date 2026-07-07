<?php
$page_id = 'archived_users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$my_role = $me['role'] ?? 'staff';

// Access Control: ONLY Super Admin can access this page
if ($my_role !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// --- ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // Restore User (Undelete)
        if ($action === 'restore_user') {
            $id = $_POST['user_id'];
            
            // Restore the user
            $stmt = $pdo->prepare("UPDATE users SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE id = ?");
            $stmt->execute([$id]);
            
            // Log restoration
            log_activity($pdo, $me['id'], 'Restore User', "Restored deleted user #$id");
            $msg = "✅ User restored successfully. The user can now log in again.";
        }
        
    } catch (Exception $e) {
        $msg = "❌ " . $e->getMessage();
    }
}

// --- FETCH DELETED USERS ---
$deleted_users = [];
$stmt = $pdo->query("SELECT u.*, s.name as station_name, deleter.username as deleted_by_username 
                     FROM users u 
                     LEFT JOIN stations s ON u.station_id = s.id 
                     LEFT JOIN users deleter ON u.deleted_by = deleter.id
                     WHERE u.is_deleted = 1 
                     ORDER BY u.deleted_at DESC");
$deleted_users = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-archive"></i> Archived / Deleted Users</h1>
        <div class="sub">View and manage soft-deleted users. Only Super Admin can access this page.</div>
    </div>
    <div class="actions">
        <a href="/group31petron_system_official4/public/users.php" class="btn ghost">
            <i class="fas fa-arrow-left"></i> Back to User Management
        </a>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: <?php echo strpos($msg, '❌') !== false ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo strpos($msg, '❌') !== false ? '#721c24' : '#155724'; ?>;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Archived Users Table -->
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name / Username</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Station</th>
                    <th>Deleted At</th>
                    <th>Deleted By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($deleted_users as $u): 
                    $roleClass = $u['role'] === 'manager' ? 'primary' : 'secondary';
                ?>
                <tr style="background-color: #f8f9fa;">
                    <td>
                        <div style="font-weight:bold; color: #6c757d;"><?php echo htmlspecialchars($u['name']); ?></div>
                        <div class="muted" style="font-size:0.85em;">@<?php echo htmlspecialchars($u['username']); ?></div>
                    </td>
                    <td><span class="badge bg-<?php echo $roleClass; ?>"><?php echo ucfirst($u['role']); ?></span></td>
                    <td><?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                    <td>
                        <?php 
                        if ($u['deleted_at']) {
                            echo date('M d, Y H:i:s', strtotime($u['deleted_at']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($u['deleted_by_username'] ?? 'Unknown'); ?></td>
                    <td>
                        <div style="display:flex; gap:5px;">
                            <button class="btn small success" onclick="restoreUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" title="Restore User">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($deleted_users)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;">
                            <i class="fas fa-inbox fa-3x" style="color:#ccc; margin-bottom:10px;"></i><br>
                            No archived users found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Deletion Audit Log -->
<div class="card" style="margin-top:20px;">
    <h3 style="margin-bottom:15px;"><i class="fas fa-history"></i> Deletion Audit Log</h3>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Deleted By</th>
                    <th>Deleted At</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $auditStmt = $pdo->query("SELECT * FROM user_deletions ORDER BY deleted_at DESC LIMIT 50");
                $audit_logs = $auditStmt->fetchAll();
                
                foreach($audit_logs as $log):
                ?>
                <tr>
                    <td><?php echo $log['user_id']; ?></td>
                    <td>@<?php echo htmlspecialchars($log['username']); ?></td>
                    <td><?php echo htmlspecialchars($log['name']); ?></td>
                    <td><span class="badge bg-secondary"><?php echo ucfirst($log['role']); ?></span></td>
                    <td>@<?php echo htmlspecialchars($log['deleted_by_username']); ?></td>
                    <td><?php echo date('M d, Y H:i:s', strtotime($log['deleted_at'])); ?></td>
                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($audit_logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:20px;">
                            No deletion history found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FORM: Restore User (Hidden) -->
<form method="post" id="restoreForm" style="display:none;">
    <input type="hidden" name="action" value="restore_user">
    <input type="hidden" name="user_id" id="restore_user_id">
</form>

<script>
function restoreUser(id, username) {
    if(confirm('Restore user "' + username + '"?\n\nThis will:\n- Undelete the user\n- Allow login access again\n- Show in regular user lists\n\nContinue?')) {
        document.getElementById('restore_user_id').value = id;
        document.getElementById('restoreForm').submit();
    }
}
</script>

<style>
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; color: white; }
    .bg-primary { background: #007bff; }
    .bg-secondary { background: #6c757d; }
    .bg-success { background: #28a745; }
    .bg-danger { background: #dc3545; }
    .btn.small { padding: 4px 8px; font-size: 0.85em; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
