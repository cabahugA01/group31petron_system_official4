<?php
/**
 * Manager Audit Trail
 * Logs all validation actions: approve, reject, adjust
 * Immutable logs for transparency, accountability, and compliance
 */
$page_id = 'mgr_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

// Access control - Manager only
if (!in_array($role, ['manager', 'supervisor'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php'); exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from  = $_GET['date_from']  ?? date('Y-m-d', strtotime('-30 days'));
$date_to    = $_GET['date_to']    ?? date('Y-m-d');
$module     = trim($_GET['module'] ?? '');
$action     = trim($_GET['action'] ?? '');
$search     = trim($_GET['search'] ?? '');

// ── Export to Excel/CSV ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="manager_audit_trail_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'Timestamp', 'User ID', 'Manager Name', 'Role', 'Action Type', 'Module', 'Entity Type', 'Entity ID', 'Action Details', 'Status']);

    $sql = "SELECT al.id, al.created_at, al.user_id, u.name AS user_name, u.role,
                   al.action_type, al.log_type AS module, al.entity_type, al.entity_id,
                   al.action_details, al.status
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.user_id = ?
              AND DATE(al.created_at) BETWEEN ? AND ?
              AND al.action_type IN ('Approve', 'Reject', 'Adjust', 'Validate', 'Return')";
    $params = [$me['id'], $date_from, $date_to];
    
    if ($module !== '') { $sql .= " AND al.log_type = ?"; $params[] = $module; }
    if ($action !== '') { $sql .= " AND al.action_type = ?"; $params[] = $action; }
    if ($search !== '') { $sql .= " AND (al.action_details LIKE ? OR al.entity_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    
    $sql .= " ORDER BY al.created_at DESC";
    
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['id'],
                $row['created_at'],
                $row['user_id'],
                $row['user_name'],
                $row['role'],
                $row['action_type'],
                $row['module'],
                $row['entity_type'],
                $row['entity_id'],
                $row['action_details'],
                $row['status']
            ]);
        }
    } catch (Exception $e) {}
    fclose($out); exit;
}

// ── Fetch Audit Logs from audit_logs table ──────────────────────────────────
$audit_logs = [];
$total_logs = 0;
try {
    $sql = "SELECT al.id, al.created_at, al.user_id, u.name AS user_name, u.role,
                   al.action_type, al.log_type AS module, al.entity_type, al.entity_id,
                   al.action_details, al.status
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.user_id = ?
              AND DATE(al.created_at) BETWEEN ? AND ?
              AND al.action_type IN ('Approve', 'Reject', 'Adjust', 'Validate', 'Return')";
    $params = [$me['id'], $date_from, $date_to];
    
    if ($module !== '') { $sql .= " AND al.log_type = ?"; $params[] = $module; }
    if ($action !== '') { $sql .= " AND al.action_type = ?"; $params[] = $action; }
    if ($search !== '') { $sql .= " AND (al.action_details LIKE ? OR al.entity_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 500";
    
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_logs = count($audit_logs);
} catch (Exception $e) {
    $_SESSION['error'] = 'Could not load audit logs: ' . $e->getMessage();
}

// ── Action type counts ────────────────────────────────────────────────────────
$approve_count = count(array_filter($audit_logs, fn($log) => strtolower($log['action_type']) === 'approve' || strtolower($log['action_type']) === 'validate'));
$reject_count  = count(array_filter($audit_logs, fn($log) => strtolower($log['action_type']) === 'reject' || strtolower($log['action_type']) === 'return'));
$adjust_count  = count(array_filter($audit_logs, fn($log) => strtolower($log['action_type']) === 'adjust'));

include __DIR__ . '/../partials/header.php';
?>

<style>
:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--orange:#fd7e14;--gray:#6c757d;}
.audit-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;}
.audit-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.audit-card-title{font-size:1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.audit-card-body{padding:18px 20px;}
.summary-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.sum-card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);border-left:4px solid #dee2e6;}
.sum-card-num{font-size:2rem;font-weight:700;margin-bottom:4px;}
.sum-card-lbl{font-size:12px;text-transform:uppercase;color:var(--gray);font-weight:600;letter-spacing:.5px;}
.sum-card-total{border-left-color:var(--blue);}.sum-card-total .sum-card-num{color:var(--blue);}
.sum-card-approve{border-left-color:var(--green);}.sum-card-approve .sum-card-num{color:var(--green);}
.sum-card-reject{border-left-color:var(--red);}.sum-card-reject .sum-card-num{color:var(--red);}
.sum-card-adjust{border-left-color:var(--orange);}.sum-card-adjust .sum-card-num{color:var(--orange);}
.filter-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;}
.filter-group{display:flex;flex-direction:column;gap:6px;}
.filter-label{font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;}
.filter-input,.filter-select{padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;width:100%;}
.filter-input:focus,.filter-select:focus{outline:none;border-color:var(--blue);}
.btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .2s;}
.btn-primary{background:var(--blue);color:#fff;}.btn-primary:hover{background:#001F4F;}
.btn-secondary{background:#6c757d;color:#fff;}.btn-secondary:hover{background:#545b62;}
.btn-success{background:var(--green);color:#fff;}.btn-success:hover{background:#218838;}
.audit-table{width:100%;border-collapse:collapse;font-size:13px;}
.audit-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--gray);border-bottom:2px solid #dee2e6;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.audit-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.audit-table tr:hover td{background:#f8f9fa;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-approve{background:#d4edda;color:#155724;}
.badge-reject{background:#f8d7da;color:#721c24;}
.badge-adjust{background:#fff3cd;color:#856404;}
.notice-box{background:#e8f4fd;border-left:4px solid var(--blue);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--blue);display:flex;align-items:center;gap:10px;}
.notice-box i{font-size:18px;}
.empty-state{text-align:center;padding:60px 20px;color:var(--gray);}
.empty-state i{font-size:3rem;margin-bottom:12px;opacity:.3;}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-shield-alt"></i> Audit Trail</h1>
        <div class="sub">Personal compliance record — All validation actions logged with timestamps and details</div>
    </div>
</div>

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="sum-card sum-card-total">
        <div class="sum-card-num"><?= $total_logs ?></div>
        <div class="sum-card-lbl">Total Logs</div>
    </div>
    <div class="sum-card sum-card-approve">
        <div class="sum-card-num"><?= $approve_count ?></div>
        <div class="sum-card-lbl">Approved</div>
    </div>
    <div class="sum-card sum-card-reject">
        <div class="sum-card-num"><?= $reject_count ?></div>
        <div class="sum-card-lbl">Rejected</div>
    </div>
    <div class="sum-card sum-card-adjust">
        <div class="sum-card-num"><?= $adjust_count ?></div>
        <div class="sum-card-lbl">Adjusted</div>
    </div>
</div>

<!-- Notice -->
<div class="notice-box">
    <i class="fas fa-lock"></i>
    <div>
        <strong>Read-only logs.</strong> All validation actions (approve, reject, adjust) are automatically logged. 
        These records are immutable and cannot be edited or deleted. Used for transparency, accountability, and compliance.
    </div>
</div>

<!-- Filters -->
<div class="audit-card">
    <div class="audit-card-head">
        <div class="audit-card-title"><i class="fas fa-filter"></i> Filter Audit Logs</div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>" class="btn btn-success btn-sm">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
    </div>
    <div class="audit-card-body">
        <form method="get" action="manager_audit_trail.php">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="filter-input" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="filter-input" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-cube"></i> Module</label>
                    <select name="module" class="filter-select">
                        <option value="">All Modules</option>
                        <option value="transactions" <?= $module==='transactions'?'selected':'' ?>>Transactions</option>
                        <option value="fuel_management" <?= $module==='fuel_management'?'selected':'' ?>>Fuel Management</option>
                        <option value="inventory" <?= $module==='inventory'?'selected':'' ?>>Inventory</option>
                        <option value="deliveries" <?= $module==='deliveries'?'selected':'' ?>>Deliveries</option>
                        <option value="customer_management" <?= $module==='customer_management'?'selected':'' ?>>Customer Management</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-bolt"></i> Action Type</label>
                    <select name="action" class="filter-select">
                        <option value="">All Actions</option>
                        <option value="Approve" <?= $action==='Approve'?'selected':'' ?>>✅ Approve</option>
                        <option value="Reject" <?= $action==='Reject'?'selected':'' ?>>❌ Reject</option>
                        <option value="Adjust" <?= $action==='Adjust'?'selected':'' ?>>🔧 Adjust</option>
                        <option value="Validate" <?= $action==='Validate'?'selected':'' ?>>✓ Validate</option>
                        <option value="Return" <?= $action==='Return'?'selected':'' ?>>↩ Return</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="filter-input" placeholder="Search details or ID...">
                </div>
                <div class="filter-group" style="justify-content:flex-end;">
                    <label class="filter-label">&nbsp;</label>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                        <a href="manager_audit_trail.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Audit Logs Table -->
<div class="audit-card">
    <div class="audit-card-head">
        <div class="audit-card-title"><i class="fas fa-list"></i> Audit Logs (<?= $total_logs ?> records)</div>
    </div>
    <div style="overflow-x:auto;">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Log ID</th>
                    <th>Timestamp</th>
                    <th>User ID</th>
                    <th>Manager Name</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Entity Type</th>
                    <th>Entity ID</th>
                    <th>Action Details</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($audit_logs)): ?>
                <tr>
                    <td colspan="11" style="padding:0;">
                        <div class="empty-state">
                            <i class="fas fa-shield-alt"></i>
                            <div style="font-weight:600;font-size:16px;margin-bottom:8px;">No audit logs found</div>
                            <div style="font-size:13px;">No validation actions recorded for the selected filters</div>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($audit_logs as $log):
                        $act = strtolower($log['action_type']);
                        if (in_array($act, ['approve','validate'])) {
                            $badge_class = 'badge-approve';
                            $icon = 'fa-check-circle';
                        } elseif (in_array($act, ['reject','return'])) {
                            $badge_class = 'badge-reject';
                            $icon = 'fa-times-circle';
                        } else {
                            $badge_class = 'badge-adjust';
                            $icon = 'fa-edit';
                        }
                    ?>
                    <tr>
                        <td style="font-family:monospace;font-size:11px;color:#888;">#<?= $log['id'] ?></td>
                        <td style="font-size:12px;white-space:nowrap;"><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                        <td style="font-family:monospace;font-size:11px;color:#888;"><?= $log['user_id'] ?></td>
                        <td style="font-weight:600;font-size:13px;"><?= htmlspecialchars($log['user_name'] ?? 'N/A') ?></td>
                        <td style="font-size:12px;color:var(--gray);"><?= htmlspecialchars($log['role'] ?? 'Manager') ?></td>
                        <td>
                            <span class="badge <?= $badge_class ?>">
                                <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($log['action_type']) ?>
                            </span>
                        </td>
                        <td style="font-size:12px;text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $log['module'] ?? 'N/A')) ?></td>
                        <td style="font-size:12px;color:var(--gray);"><?= htmlspecialchars($log['entity_type'] ?? '—') ?></td>
                        <td style="font-family:monospace;font-size:12px;font-weight:600;"><?= htmlspecialchars($log['entity_id'] ?? '—') ?></td>
                        <td style="font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" 
                            title="<?= htmlspecialchars($log['action_details'] ?? '') ?>">
                            <?= htmlspecialchars($log['action_details'] ?: '—') ?>
                        </td>
                        <td style="font-size:11px;color:<?= $log['status']==='Success'?'#28a745':'#dc3545' ?>;font-weight:600;">
                            <?= htmlspecialchars($log['status'] ?? 'Success') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:16px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;color:#6c757d;">
    <i class="fas fa-info-circle"></i> 
    Showing <?= number_format($total_logs) ?> audit logs from <?= date('M d, Y', strtotime($date_from)) ?> to <?= date('M d, Y', strtotime($date_to)) ?>.
    These logs are immutable and maintained for compliance and audit purposes.
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
