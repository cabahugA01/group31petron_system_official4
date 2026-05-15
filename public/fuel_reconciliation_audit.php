<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Check if user is logged in and has appropriate role
require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    die("Access Denied");
}

$page_title = 'Fuel Reconciliation Audit Trail';
include __DIR__ . '/../partials/header.php';

// Handle filter parameters
$date_range = $_GET['date_range'] ?? '';
$station_filter = $_GET['station'] ?? '';
$status_filter = $_GET['status'] ?? '';
$action_filter = $_GET['action'] ?? '';

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

// Fetch audit trail data
$audit_data = [];
try {
    $where_conditions = ["fraal.performed_at BETWEEN ? AND ?"];
    $params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    
    // Station filter
    if (!empty($station_filter) && $station_filter !== 'all') {
        $where_conditions[] = "frs.station_id = ?";
        $params[] = $station_filter;
    }
    
    // Status filter
    if (!empty($status_filter) && $status_filter !== 'all') {
        $where_conditions[] = "frs.status = ?";
        $params[] = $status_filter;
    }
    
    // Action filter
    if (!empty($action_filter) && $action_filter !== 'all') {
        $where_conditions[] = "fraal.action_type = ?";
        $params[] = $action_filter;
    }
    
    // Role-based access
    if ($role === 'staff') {
        $where_conditions[] = "fraal.performed_by = ?";
        $params[] = $u['id'];
    } elseif ($role === 'manager') {
        $where_conditions[] = "frs.station_id = ?";
        $params[] = $station_id;
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            fraal.*,
            frs.station_id,
            s.name as station_name,
            u.name as performed_by_name,
            u.role as performed_by_role,
            CASE fraal.action_type
                WHEN 'started' THEN '🚀 Reconciliation Started'
                WHEN 'pump_readings_recorded' THEN '📊 Pump Readings Recorded'
                WHEN 'delivery_compared' THEN '🚚 Delivery Compared'
                WHEN 'variance_identified' THEN '⚠️ Variance Identified'
                WHEN 'submitted' THEN '📤 Submitted for Approval'
                WHEN 'approved' THEN '✅ Approved by Manager'
                WHEN 'rejected' THEN '❌ Rejected by Manager'
                WHEN 'locked' THEN '🔒 Reconciliation Locked'
                WHEN 'adjusted' THEN '🔧 Inventory Adjusted'
                ELSE fraal.action_type
            END as action_display
        FROM fuel_reconciliation_audit_log fraal
        LEFT JOIN fuel_reconciliation_sessions frs ON fraal.reconciliation_id = frs.id
        LEFT JOIN stations s ON frs.station_id = s.id
        LEFT JOIN users u ON fraal.performed_by = u.id
        WHERE $where_sql
        ORDER BY fraal.performed_at DESC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $audit_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error fetching audit data: " . $e->getMessage();
}

// Get stations for filter
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stations = [];
}

// Get statistics
$stats = [];
try {
    $where_conditions = ["performed_at BETWEEN ? AND ?"];
    $params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    
    if ($role === 'staff') {
        $where_conditions[] = "performed_by = ?";
        $params[] = $u['id'];
    } elseif ($role === 'manager') {
        $where_conditions[] = "station_id = ?";
        $params[] = $station_id;
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            COUNT(*) as total_actions,
            COUNT(CASE WHEN action_type = 'approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN action_type = 'rejected' THEN 1 END) as rejected_count,
            COUNT(CASE WHEN action_type = 'variance_identified' THEN 1 END) as variance_count,
            COUNT(CASE WHEN action_type = 'locked' THEN 1 END) as locked_count
        FROM fuel_reconciliation_audit_log fraal
        LEFT JOIN fuel_reconciliation_sessions frs ON fraal.reconciliation_id = frs.id
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $stats = [
        'total_actions' => 0,
        'approved_count' => 0,
        'rejected_count' => 0,
        'variance_count' => 0,
        'locked_count' => 0
    ];
}
?>

<style>
.audit-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    background: var(--bg);
    min-height: calc(100vh - 110px);
}

.audit-header {
    text-align: center;
    margin-bottom: 30px;
}

.audit-title {
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 10px;
}

.audit-subtitle {
    color: var(--muted);
    font-size: 14px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card);
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: var(--blue);
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-section {
    background: var(--card);
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-input, .filter-select {
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
}

.filter-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn {
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #003366;
}

.btn-secondary {
    background: var(--muted);
    color: var(--text);
}

.btn-secondary:hover {
    background: #6c757d;
}

.audit-table {
    background: var(--card);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.table-container {
    overflow-x: auto;
}

.audit-table table {
    width: 100%;
    border-collapse: collapse;
}

.audit-table th {
    background: var(--bg);
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: var(--muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border);
}

.audit-table td {
    padding: 15px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 14px;
}

.audit-table tr:hover {
    background: var(--bg);
}

.action-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}

.action-started { background: #e3f2fd; color: #1976d2; }
.action-recorded { background: #f3e5f5; color: #7b1fa2; }
.action-compared { background: #e8f5e8; color: #388e3c; }
.action-variance { background: #fff3e0; color: #f57c00; }
.action-submitted { background: #e1f5fe; color: #0288d1; }
.action-approved { background: #e8f5e9; color: #2e7d32; }
.action-rejected { background: #ffebee; color: #c62828; }
.action-locked { background: #fce4ec; color: #c2185b; }
.action-adjusted { background: #f3e5f5; color: #7b1fa2; }

.role-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.role-staff { background: #e3f2fd; color: #1976d2; }
.role-manager { background: #e8f5e8; color: #388e3c; }
.role-admin { background: #fff3e0; color: #f57c00; }
.role-superadmin { background: #fce4ec; color: #c2185b; }

.timestamp {
    font-size: 12px;
    color: var(--muted);
}

.export-section {
    margin-top: 30px;
    text-align: center;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .audit-table {
        font-size: 12px;
    }
    
    .audit-table th,
    .audit-table td {
        padding: 10px;
    }
}
</style>

<div class="audit-container">
    <div class="audit-header">
        <h1 class="audit-title">🔍 Fuel Reconciliation Audit Trail</h1>
        <p class="audit-subtitle">Complete audit compliance and operational transparency tracking</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['total_actions']); ?></div>
            <div class="stat-label">Total Actions</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['approved_count']); ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['variance_count']); ?></div>
            <div class="stat-label">Variances Found</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['locked_count']); ?></div>
            <div class="stat-label">Locked Reports</div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="get">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label">Date Range</label>
                    <input type="text" name="date_range" class="filter-input" 
                           value="<?php echo htmlspecialchars($date_range); ?>"
                           placeholder="YYYY-MM-DD to YYYY-MM-DD">
                </div>
                
                <?php if (in_array($role, ['admin', 'superadmin'])): ?>
                <div class="filter-group">
                    <label class="filter-label">Station</label>
                    <select name="station" class="filter-select">
                        <option value="all">All Stations</option>
                        <?php foreach ($stations as $station): ?>
                            <option value="<?php echo $station['id']; ?>" 
                                    <?php echo $station_filter == $station['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($station['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select">
                        <option value="all">All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending_approval" <?php echo $status_filter == 'pending_approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="locked" <?php echo $status_filter == 'locked' ? 'selected' : ''; ?>>Locked</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Action Type</label>
                    <select name="action" class="filter-select">
                        <option value="all">All Actions</option>
                        <option value="started" <?php echo $action_filter == 'started' ? 'selected' : ''; ?>>Started</option>
                        <option value="pump_readings_recorded" <?php echo $action_filter == 'pump_readings_recorded' ? 'selected' : ''; ?>>Pump Readings</option>
                        <option value="delivery_compared" <?php echo $action_filter == 'delivery_compared' ? 'selected' : ''; ?>>Delivery Compared</option>
                        <option value="variance_identified" <?php echo $action_filter == 'variance_identified' ? 'selected' : ''; ?>>Variance Identified</option>
                        <option value="submitted" <?php echo $action_filter == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="approved" <?php echo $action_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="locked" <?php echo $action_filter == 'locked' ? 'selected' : ''; ?>>Locked</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-buttons">
                <button type="button" class="btn btn-secondary" onclick="clearFilters()">Clear Filters</button>
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- Audit Table -->
    <div class="audit-table">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Reconciliation ID</th>
                        <th>Station</th>
                        <th>Performed By</th>
                        <th>Role</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($audit_data)): ?>
                        <?php foreach ($audit_data as $entry): ?>
                            <tr>
                                <td>
                                    <div class="timestamp">
                                        <?php echo date('M d, Y H:i:s', strtotime($entry['performed_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="action-badge action-<?php echo $entry['action_type']; ?>">
                                        <?php echo htmlspecialchars($entry['action_display']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($entry['reconciliation_id']): ?>
                                        <a href="fuel_reconciliation_workflow.php?reconciliation_id=<?php echo $entry['reconciliation_id']; ?>" 
                                           style="color: var(--blue); text-decoration: none;">
                                            #<?php echo $entry['reconciliation_id']; ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($entry['station_name'] ?? 'HQ'); ?></td>
                                <td><?php echo htmlspecialchars($entry['performed_by_name'] ?? 'System'); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $entry['performed_by_role']; ?>">
                                        <?php echo htmlspecialchars($entry['performed_by_role'] ?? 'system'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($entry['action_details'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($entry['ip_address'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <div class="empty-state-icon">📭</div>
                                    <h3>No Audit Records Found</h3>
                                    <p>No audit trail records match your current filters.</p>
                                    <p>Try adjusting your filters or check back later.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Export Section -->
    <div class="export-section">
        <button onclick="exportAuditTrail('excel')" class="btn btn-secondary">📊 Export to Excel</button>
        <button onclick="exportAuditTrail('pdf')" class="btn btn-secondary">📄 Export to PDF</button>
        <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Report</button>
    </div>
</div>

<script>
function clearFilters() {
    window.location.href = 'fuel_reconciliation_audit.php';
}

function exportAuditTrail(format) {
    const url = new URL(window.location);
    url.searchParams.set('export', format);
    url.searchParams.set('export_token', Date.now());
    
    window.open(url.toString(), '_blank');
}

// Auto-refresh every 30 seconds for real-time monitoring
setTimeout(function() {
    window.location.reload();
}, 30000);

// Initialize date range picker
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.querySelector('input[name="date_range"]');
    if (dateInput) {
        dateInput.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && !value.match(/^\d{4}-\d{2}-\d{2}\s+to\s+\d{4}-\d{2}-\d{2}$/)) {
                alert('Please use format: YYYY-MM-DD to YYYY-MM-DD');
                this.focus();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
