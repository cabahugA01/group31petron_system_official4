<?php
/**
 * Audit Trail Reports - Standalone Page
 * Complete Report Access Audit Trail
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'superadmin_reports';
$station_name = '';

// Ensure table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_access_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_name VARCHAR(100),
            report_type VARCHAR(100) COMMENT 'technical, security, developer_audit, audit_trail',
            action VARCHAR(50) COMMENT 'view, export_csv, export_pdf, print',
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_report_type (report_type),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Audit Trail Reports table creation: " . $e->getMessage());
}

// Fetch filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type_filter = $_GET['report_type'] ?? '';
$action_filter = $_GET['action'] ?? '';

// Fetch data - Report Access Audit
$query = "
    SELECT * FROM report_access_audit
    WHERE created_at BETWEEN ? AND ?
";
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

if ($report_type_filter) {
    $query .= " AND report_type = ?";
    $params[] = $report_type_filter;
}

if ($action_filter) {
    $query .= " AND action = ?";
    $params[] = $action_filter;
}

$query .= " ORDER BY created_at DESC LIMIT 200";

$stmt_audit = $pdo->prepare($query);
$stmt_audit->execute($params);
$report_accesses = $stmt_audit->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_logs,
        SUM(CASE WHEN action = 'view' THEN 1 ELSE 0 END) as report_views,
        SUM(CASE WHEN action IN ('export_csv', 'export_pdf') THEN 1 ELSE 0 END) as exports,
        COUNT(DISTINCT user_id) as unique_users
    FROM report_access_audit
    WHERE created_at BETWEEN ? AND ?
");
$stmt_stats->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Get report type breakdown
$stmt_by_type = $pdo->prepare("
    SELECT 
        report_type,
        COUNT(*) as count
    FROM report_access_audit
    WHERE created_at BETWEEN ? AND ?
    GROUP BY report_type
    ORDER BY count DESC
");
$stmt_by_type->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$by_type = $stmt_by_type->fetchAll(PDO::FETCH_ASSOC);

// Get available report types and actions for filters
$available_report_types = ['technical', 'security', 'developer_audit', 'audit_trail'];
$available_actions = ['view', 'export_csv', 'export_pdf', 'print'];

// ── AJAX JSON POLLING ENDPOINT FOR REPORT AUDIT TRAIL ──────────────────────────
if (isset($_GET['ajax_rat']) && $_GET['ajax_rat'] == '1') {
    header('Content-Type: application/json');
    $count = count($report_accesses ?? []);
    $firstRows = array_slice($report_accesses ?? [], 0, 30);
    $signature = md5(json_encode($firstRows) . '_' . $count);
    echo json_encode([
        'success'   => true,
        'count'     => $count,
        'signature' => $signature,
        'stats'     => $stats ?? []
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
:root {
    --primary-color: #003366;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    --info-color: #3b82f6;
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --radius-md: 8px;
    --radius-lg: 12px;
}

.report-container {
    padding: 0 24px 24px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

.filters-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.form-group label {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.875rem;
    display: block;
    margin-bottom: 6px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.875rem;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary { background: var(--primary-color); color: white; }
.btn-success { background: var(--success-color); color: white; }
.btn-secondary { background: #e5e7eb; color: var(--text-primary); }

.actions-bar {
    display: flex;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.report-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    margin-bottom: 24px;
}

.report-card-header {
    padding: 16px 24px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}

.report-card-title {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
}

.report-card-body {
    padding: 24px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.stat-box {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--border-color);
}

.stat-label {
    font-size: 0.813rem;
    color: var(--text-secondary);
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table thead th {
    background: #f1f5f9;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid var(--border-color);
}

.data-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
}

.data-table tbody tr:hover {
    background: var(--bg-secondary);
}

.badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }
.badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
.badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
.badge-secondary { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }
.badge-info { background: rgba(59, 130, 246, 0.1); color: var(--info-color); }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.breakdown-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.breakdown-label {
    font-weight: 600;
    text-transform: capitalize;
}

.breakdown-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--info-color);
}

@media print {
    @page { size: A4 portrait; margin: 0.3in 0.4in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    
    body { background: white !important; }
    
    .filters-card,
    .btn,
    .sidebar,
    .top-header,
    .footer-sidebar-area,
    nav,
    .no-print {
        display: none !important;
    }
    
    .report-container {
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
    }
    
    .report-card {
        page-break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    
    .data-table {
        font-size: 10px !important;
    }
    
    .data-table thead th {
        background: #f0f0f0 !important;
        border: 1px solid #000 !important;
    }
}
</style>

<div class="report-container">

    <!-- Page Header - Manager Style -->
    <div style="text-align:center;padding:0 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;margin-top:-12px;">
        <div style="font-size:20px;font-weight:800;color:#003366;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
            AUDIT TRAIL REPORTS
        </div>
        <div style="font-size:16px;font-weight:700;color:#003366;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            DEVELOPER VIEW
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            Complete Report Access Audit • Activity Logging • Compliance Tracking
        </div>
        <div style="font-size:12px;color:#334155;">
            <strong>Date:</strong>
            <?php echo date('F j, Y', strtotime($date_from)); ?>
            <?php echo $date_from !== $date_to ? ' – ' . date('F j, Y', strtotime($date_to)) : ''; ?>
        </div>
    </div>

    <div class="filters-card">
        <form method="GET">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="form-group">
                    <label>Report Type</label>
                    <select class="form-control" name="report_type">
                        <option value="">All Types</option>
                        <?php foreach ($available_report_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($report_type_filter === $type) ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Action</label>
                    <select class="form-control" name="action">
                        <option value="">All Actions</option>
                        <?php foreach ($available_actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo ($action_filter === $action) ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $action)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="actions-bar">
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
                <div>
                    <button type="button" class="btn btn-success" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    function exportToCSV() {
        const params = new URLSearchParams(window.location.search);
        const dateFrom = params.get('date_from') || '<?php echo $date_from; ?>';
        const dateTo = params.get('date_to') || '<?php echo $date_to; ?>';
        window.location.href = '../backend/api/developer_reports_api.php?action=export_csv&report_type=audit_trail&date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    </script>

    <!-- Summary Stats -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total Logs</div>
            <div class="stat-value" style="color: var(--info-color);">
                <?php echo number_format($stats['total_logs'] ?? 0); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Report Views</div>
            <div class="stat-value" style="color: var(--success-color);">
                <?php echo number_format($stats['report_views'] ?? 0); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Exports</div>
            <div class="stat-value" style="color: var(--warning-color);">
                <?php echo number_format($stats['exports'] ?? 0); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Unique Users</div>
            <div class="stat-value" style="color: var(--primary-color);">
                <?php echo number_format($stats['unique_users'] ?? 0); ?>
            </div>
        </div>
    </div>

    <!-- Report Type Breakdown -->
    <?php if (!empty($by_type)): ?>
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-chart-pie"></i> Access by Report Type</h3>
        </div>
        <div class="report-card-body">
            <div class="breakdown-grid">
                <?php foreach ($by_type as $type): ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label"><?php echo ucwords(str_replace('_', ' ', $type['report_type'])); ?></span>
                        <span class="breakdown-value"><?php echo number_format($type['count']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Complete Audit Trail -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-history"></i> Complete Audit Trail</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($report_accesses)): ?>
                <div class="empty-state">
                    <i class="fas fa-history" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No audit trail records found</p>
                </div>
            <?php else: ?>
                <div style="overflow:hidden;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Report Type</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_accesses as $access): ?>
                                <tr>
                                    <td><?php echo $access['id']; ?></td>
                                    <td><?php echo htmlspecialchars($access['user_name'] ?? 'System'); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo strtoupper(str_replace('_', ' ', $access['report_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $access['action'] === 'view' ? 'secondary' : 
                                                (strpos($access['action'], 'export') !== false ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo strtoupper(str_replace('_', ' ', $access['action'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($access['ip_address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($access['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($report_accesses) >= 200): ?>
                    <div style="margin-top: 16px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); text-align: center; color: var(--text-secondary);">
                        <i class="fas fa-info-circle"></i> Showing first 200 records. Apply filters to narrow results.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastRatSignature = null;
let lastRatCount = null;

function autoRefreshReportAuditTrail() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT')) {
        return;
    }

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_rat', '1');

    fetch(currentUrl.toString(), { cache: 'no-store', credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (lastRatSignature !== null && (lastRatSignature !== data.signature || lastRatCount !== data.count)) {
                    window.location.reload();
                }
                lastRatSignature = data.signature;
                lastRatCount = data.count;
            }
        })
        .catch(() => {});
}

setInterval(autoRefreshReportAuditTrail, 2000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
