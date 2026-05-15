<?php
/**
 * APPROVAL HISTORY
 * 
 * Displays historical record of all approved/rejected transactions
 * Accessible to: Manager, Admin, Super Admin
 * Purpose: Audit trail and transparency of approval decisions
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'approval_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Approval History</h1>
    <div class="sub">Track all approval/rejection decisions and audit trail</div>
  </div>
</div>
<?php
$view = $_GET['view'] ?? 'all';
$status_filter = $_GET['status'] ?? '';

// Build query with parameterized statements
$where_conditions = array("a.log_type = 'approval'");
$params = array();

if ($status_filter && in_array($status_filter, ['Success', 'Failed'])) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
}

$where = "WHERE " . implode(" AND ", $where_conditions);

// Get approval records from audit_logs
$sql = "SELECT a.*, u.name as approved_by FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $where
        ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary stats
$total = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE log_type='approval'")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE log_type='approval' AND status='Success'")->fetchColumn();
$rejected = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE log_type='approval' AND status='Failed'")->fetchColumn();
?>

<div style="display:flex; gap:12px; margin-bottom:20px;">
  <div class="card" style="flex:1;">
    <div style="padding:16px; text-align:center;">
      <div style="font-size:24px; font-weight:bold; color:var(--petron-blue);"><?php echo $total; ?></div>
      <div style="color:#666;">Total Approvals</div>
    </div>
  </div>
  <div class="card" style="flex:1;">
    <div style="padding:16px; text-align:center;">
      <div style="font-size:24px; font-weight:bold; color:#28A745;"><?php echo $approved; ?></div>
      <div style="color:#666;">Approved</div>
    </div>
  </div>
  <div class="card" style="flex:1;">
    <div style="padding:16px; text-align:center;">
      <div style="font-size:24px; font-weight:bold; color:#DC3545;"><?php echo $rejected; ?></div>
      <div style="color:#666;">Rejected</div>
    </div>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-history"></i> Approval Records</div>
    <div class="muted"><?php echo $total; ?> total approval transactions</div>
  </div>
  <div style="padding:16px;">
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
      <a class="btn <?php echo !$status_filter ? 'primary' : 'ghost'; ?>" href="approval_history.php">All (<?php echo $total; ?>)</a>
      <a class="btn <?php echo $status_filter === 'Success' ? 'primary' : 'ghost'; ?>" href="approval_history.php?status=Success">Approved (<?php echo $approved; ?>)</a>
      <a class="btn <?php echo $status_filter === 'Failed' ? 'primary' : 'ghost'; ?>" href="approval_history.php?status=Failed">Rejected (<?php echo $rejected; ?>)</a>
    </div>

    <?php if (!empty($approvals)): ?>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f5f5f5; border-bottom:2px solid #ddd;">
          <th style="padding:12px; text-align:left;">ID</th>
          <th style="padding:12px; text-align:left;">Action Type</th>
          <th style="padding:12px; text-align:left;">Approved By</th>
          <th style="padding:12px; text-align:left;">Status</th>
          <th style="padding:12px; text-align:left;">Entity</th>
          <th style="padding:12px; text-align:left;">Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($approvals as $approval): ?>
        <tr style="border-bottom:1px solid #ddd;">
          <td style="padding:12px;">#<?php echo $approval['id']; ?></td>
          <td style="padding:12px;"><?php echo htmlspecialchars($approval['action_type']); ?></td>
          <td style="padding:12px;"><?php echo htmlspecialchars($approval['approved_by'] ?? 'System'); ?></td>
          <td style="padding:12px;">
            <span style="padding:4px 8px; border-radius:4px; font-size:12px; font-weight:bold; 
              <?php echo $approval['status'] === 'Success' 
                ? 'background:#d4edda; color:#155724;' 
                : 'background:#f8d7da; color:#721c24;'; ?>">
              <?php echo $approval['status']; ?>
            </span>
          </td>
          <td style="padding:12px;"><?php echo htmlspecialchars($approval['entity_type'] ?? '-'); ?></td>
          <td style="padding:12px; font-size:12px; color:#666;">
            <?php echo date('M d, Y H:i', strtotime($approval['created_at'])); ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="padding:20px; text-align:center; color:#999;">
      No approval records found.
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
