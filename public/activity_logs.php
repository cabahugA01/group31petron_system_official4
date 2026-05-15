<?php
$page_id = 'activity_logs';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$isSuper = ($me['role'] ?? '') === 'superadmin';
$station_id = $isSuper ? ($_GET['station'] ?? '') : user_station_id();
$module_filter = $_GET['module'] ?? '';
$filter = $_GET['filter'] ?? '';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$user_filter = $_GET['user'] ?? '';

// Fetch stations for dropdown if superadmin
$stations = [];
if ($isSuper) {
    try {
        $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
}

// Build query with filters
$sql = "SELECT al.*, u.name as user_name 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE 1=1";
$params = [];

// Date range
$sql .= " AND al.created_at BETWEEN ? AND ?";
$params[] = $start.' 00:00:00';
$params[] = $end.' 23:59:59';

if ($module_filter) {
    $sql .= " AND al.action LIKE ?";
    $params[] = '%' . $module_filter . '%';
}

// Category filter (sidebar sub-links)
if ($filter === 'logins') {
    $sql .= " AND al.action LIKE '%Login%'";
} elseif ($filter === 'passwords') {
    $sql .= " AND al.action LIKE '%Password%'";
} elseif ($filter === 'reconciliation') {
    $sql .= " AND al.action LIKE '%Reconciliation%'";
}

if ($user_filter) {
    $sql .= " AND al.user_id = ?";
    $params[] = $user_filter;
}

if ($station_id && !$isSuper) {
    $sql .= " AND al.station_id = ?";
    $params[] = $station_id;
} elseif ($isSuper && $station_id) {
    $sql .= " AND al.station_id = ?";
    $params[] = $station_id;
}

$sql .= " ORDER BY COALESCE(al.created_at, al.timestamp) DESC LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activity_logs = $stmt->fetchAll();
} catch (Exception $e) {
    $activity_logs = [];
}

// Fetch users for filter dropdown
$users = [];
try {
    $q = $isSuper ? "SELECT id, name FROM users ORDER BY name" : "SELECT id, name FROM users WHERE station_id = ? ORDER BY name";
    $st = $pdo->prepare($q);
    $st->execute($isSuper ? [] : [user_station_id()]);
    $users = $st->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e){}

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Activity_Logs_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp','User','Action','Module','Details','Station','IP']);
    // re-run without LIMIT for export
    $sqlExp = str_replace('LIMIT 200','', $sql);
    $stmtExp = $pdo->prepare($sqlExp);
    $stmtExp->execute($params);
    while($row = $stmtExp->fetch(PDO::FETCH_ASSOC)){
        fputcsv($out, [
            $row['created_at'] ?? $row['timestamp'] ?? '',
            $row['user_name'] ?? 'System',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['details'] ?? '',
            $row['station_name'] ?? '',
            $row['ip_address'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<div class="page">
  <div class="page-head">
    <div>
      <h1>Activity Logs</h1>
      <div class="muted">System audit trail and user activities</div>
    </div>
    <div class="actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <?php if($isSuper): ?>
        <form method="get" style="display:flex; align-items:center; gap:10px;">
            <label class="sub">Station:</label>
            <select name="station" onchange="this.form.submit()" class="inp">
                <option value="">All Stations</option>
                <?php foreach($stations as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo ($_GET['station'] ?? '') == $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="module" value="<?php echo htmlspecialchars($module_filter); ?>">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <input type="hidden" name="start" value="<?php echo htmlspecialchars($start); ?>">
            <input type="hidden" name="end" value="<?php echo htmlspecialchars($end); ?>">
        </form>
      <?php endif; ?>

      <form method="get" style="display:flex; align-items:center; gap:10px;">
        <label class="sub">Category:</label>
        <select name="filter" class="inp" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="logins" <?php echo $filter==='logins'?'selected':''; ?>>Login Attempts</option>
          <option value="passwords" <?php echo $filter==='passwords'?'selected':''; ?>>Password Changes</option>
          <option value="reconciliation" <?php echo $filter==='reconciliation'?'selected':''; ?>>Reconciliation Approvals</option>
        </select>
        <label class="sub">Module:</label>
        <select name="module" class="inp" onchange="this.form.submit()">
            <option value="">All Modules</option>
            <option value="job_orders" <?php echo $module_filter === 'job_orders' ? 'selected' : ''; ?>>Job Orders</option>
            <option value="inventory" <?php echo $module_filter === 'inventory' ? 'selected' : ''; ?>>Inventory</option>
            <option value="sales" <?php echo $module_filter === 'sales' ? 'selected' : ''; ?>>Sales</option>
            <option value="users" <?php echo $module_filter === 'users' ? 'selected' : ''; ?>>Users</option>
            <option value="stations" <?php echo $module_filter === 'stations' ? 'selected' : ''; ?>>Stations</option>
            <option value="system" <?php echo $module_filter === 'system' ? 'selected' : ''; ?>>System</option>
        </select>
        <label class="sub">User:</label>
        <select name="user" class="inp" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach($users as $uid=>$uname): ?>
            <option value="<?php echo $uid; ?>" <?php echo $user_filter==$uid?'selected':''; ?>><?php echo htmlspecialchars($uname); ?></option>
          <?php endforeach; ?>
        </select>
        <label class="sub">From</label>
        <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="inp" />
        <label class="sub">to</label>
        <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="inp" />
        <button class="btn ghost" type="submit">Apply</button>
        <a class="btn" href="activity_logs.php?<?php echo http_build_query(array_merge($_GET,['export'=>'csv'])); ?>"><i class="fas fa-download"></i> Export CSV</a>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:20px;">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>User</th>
            <th>Action</th>
            <th>Details</th>
            <th>Station</th>
            <th>IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($activity_logs as $log): ?>
          <tr>
            <td>
              <?php echo date('M d, Y', strtotime($log['timestamp'])); ?><br>
              <small><?php echo date('H:i:s', strtotime($log['timestamp'])); ?></small>
            </td>
            <td>
              <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?><br>
              <small>ID: <?php echo $log['user_id']; ?></small>
            </td>
            <td>
              <span class="badge" style="background:var(--blue); color:white;">
                <?php echo htmlspecialchars($log['action']); ?>
              </span><br>
              <small><?php echo htmlspecialchars($log['module']); ?></small>
            </td>
            <td>
              <?php echo htmlspecialchars($log['details']); ?>
              <?php if($log['data']): ?>
                <br><small class="muted">Data: <?php echo htmlspecialchars(substr($log['data'], 0, 100)); ?>...</small>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($log['station_name'] ?? 'N/A'); ?></td>
            <td>
              <small><?php echo htmlspecialchars($log['ip_address']); ?></small><br>
              <small><?php echo htmlspecialchars($log['user_agent'] ?? ''); ?></small>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($activity_logs)): ?>
          <tr>
            <td colspan="6" style="text-align:center; padding:30px;">
              <div class="empty">
                <div class="empty-ico"><i class="fas fa-history"></i></div>
                <div class="muted">No activity logs found</div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
