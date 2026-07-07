<?php
$page_id = 'view_inventory_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();

// Filters
$filter_type = $_GET['type'] ?? '';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Build Query
// We'll query activity_logs for inventory related actions
$sql = "SELECT al.*, u.username FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        WHERE u.station_id = ? AND (
            al.action LIKE '%Inventory%' OR 
            al.action LIKE '%Purchase Order%' OR 
            al.action LIKE '%Receive Items%' OR 
            al.action LIKE '%Reconciliation%'
        ) AND al.created_at BETWEEN ? AND ? ORDER BY al.created_at DESC";

$params = [$station_id, $start . ' 00:00:00', $end . ' 23:59:59'];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">Inventory History</h1>
        <div class="sub">Audit trail of all inventory movements</div>
    </div>
    <div class="actions">
        <button class="btn ghost" onclick="window.print()"><i class="fas fa-print"></i> Export</button>
    </div>
</div>

<section class="card" style="padding:15px; margin-bottom:20px;">
    <form method="get" style="display:flex; gap:10px; align-items:end;">
        <div>
            <label class="lbl">From</label>
            <input type="date" name="start" value="<?php echo $start; ?>" class="inp">
        </div>
        <div>
            <label class="lbl">To</label>
            <input type="date" name="end" value="<?php echo $end; ?>" class="inp">
        </div>
        <button type="submit" class="btn primary">Filter</button>
    </form>
</section>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                    <td><span class="badge"><?php echo htmlspecialchars($log['action']); ?></span></td>
                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                    <td><?php echo htmlspecialchars($log['username'] ?? 'User #' . $log['user_id']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?>
                    <tr><td colspan="4" style="text-align:center;">No history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
    .badge { background: #eee; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }
</style>
<?php include __DIR__ . '/../partials/footer.php'; ?>
