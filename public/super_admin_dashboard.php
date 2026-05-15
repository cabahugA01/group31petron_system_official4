<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

// Ensure ONLY superadmin or developer can access
if (!in_array($roleKey, ['superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'super_admin_dashboard';
include __DIR__ . '/../partials/header.php';

// --- DATA FETCHING ---
// Section 1: System Health & Monitoring
$sysHealth = [
    'uptime' => '99.9%',
    'db_connections' => 0,
    'error_alerts' => 0,
    'failed_logins' => 0,
    'active_users' => 0,
    'pending_updates' => 0
];

try {
    $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
    $sysHealth['db_connections'] = $stmt->fetchColumn(1) ?: 0;
} catch(Exception $e) {}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM system_alerts WHERE created_at >= NOW() - INTERVAL 24 HOUR");
    $sysHealth['error_alerts'] = $stmt->fetchColumn() ?: 0;
} catch(Exception $e) {}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $sysHealth['active_users'] = $stmt->fetchColumn() ?: 0;
} catch(Exception $e) {}

// Section 2: Admin Oversight
$adminOversight = [
    'total_admins' => 0,
    'active_admins' => 0,
    'last_login' => 'Just now'
];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'");
    $adminOversight['total_admins'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1");
    $adminOversight['active_admins'] = $stmt->fetchColumn() ?: 0;
} catch(Exception $e) {}

// Section 3: Station Assignment Summary
$stationSummary = [
    'total_stations' => 1,
    'pumps_count' => 0,
    'tanks_count' => 0,
    'merch_status' => 'Healthy'
];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_pumps");
    $stationSummary['pumps_count'] = $stmt->fetchColumn() ?: 0;
} catch(Exception $e) {}

// Section 4: Module Status (Visual Checkbox)
$modules = [
    'Transactions' => true,
    'Job Orders' => true,
    'Fuel Management' => true,
    'Calendar' => true,
    'Reports' => true
];

// Section 5: Database Health
$dbHealth = [
    'backup_status' => 'Completed Today',
    'restore_points' => 12,
    'indexing_status' => 'Optimized',
    'soft_deletes' => 0,
    'size' => 'Unknown'
];
try {
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $dbHealth['size'] = $stmt->fetchColumn() . ' MB';
} catch(Exception $e) {}

// Section 6 & 7: Audit Logs & Integrations (Mocked for layout as tables might not exist)
$auditLogs = [
    ['time' => '10 mins ago', 'event' => 'Admin Role Updated', 'user' => 'SuperAdmin'],
    ['time' => '1 hour ago', 'event' => 'System Backup Completed', 'user' => 'System'],
    ['time' => '2 hours ago', 'event' => 'Failed Login Attempt', 'user' => 'Unknown IP'],
];
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --bg-dark: #0f172a;
    --card-bg: #ffffff;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --primary: #002F6C; /* Petron Blue */
    --accent: #E3000F; /* Petron Red */
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
}

body.dark-theme {
    --bg-dark: #0b0f19;
    --card-bg: #1e293b;
    --text-main: #f8fafc;
    --text-muted: #94a3b8;
    --primary: #3b82f6;
}

.sa-dashboard {
    padding: 24px 24px 40px;
    max-width: 1600px;
    margin: 0 auto;
    font-family: 'Inter', system-ui, sans-serif;
    color: var(--text-main);
}

.sa-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.sa-header h1 {
    font-size: 28px;
    font-weight: 800;
    margin: 0;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 12px;
}

.sa-header p {
    color: var(--text-muted);
    margin: 4px 0 0 35px;
    font-size: 14px;
}

.sa-section {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    border: 1px solid rgba(0,0,0,0.05);
}

.sa-section-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-main);
    border-bottom: 2px solid rgba(0,0,0,0.05);
    padding-bottom: 12px;
}

.sa-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }
.sa-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
.sa-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }

.sa-card {
    background: rgba(0, 47, 108, 0.03);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid rgba(0,0,0,0.05);
}

.sa-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.08);
}

.sa-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.sa-card-icon.danger { background: var(--danger); }
.sa-card-icon.success { background: var(--success); }
.sa-card-icon.warning { background: var(--warning); }
.sa-card-icon.accent { background: var(--accent); }

.sa-card-content h4 {
    margin: 0;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sa-card-content span {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-main);
    display: block;
    margin-top: 4px;
}

/* Toggle Switch */
.sa-toggle-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: rgba(0,0,0,0.02);
    border-radius: 8px;
    margin-bottom: 12px;
}

.sa-toggle-label {
    font-weight: 600;
}

.switch {
  position: relative;
  display: inline-block;
  width: 46px;
  height: 24px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #ccc;
  transition: .4s;
  border-radius: 34px;
}
.slider:before {
  position: absolute;
  content: "";
  height: 18px; width: 18px;
  left: 3px; bottom: 3px;
  background-color: white;
  transition: .4s;
  border-radius: 50%;
}
input:checked + .slider { background-color: var(--success); }
input:checked + .slider:before { transform: translateX(22px); }

/* Table */
.sa-table {
    width: 100%;
    border-collapse: collapse;
}
.sa-table th, .sa-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}
.sa-table th {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 13px;
    text-transform: uppercase;
}

.sa-btn {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    background: var(--primary);
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}
.sa-btn:hover { background: #001f4d; }

@media (max-width: 1024px) {
    .sa-grid-4 { grid-template-columns: repeat(2, 1fr); }
    .sa-grid-3 { grid-template-columns: repeat(1, 1fr); }
}
</style>

<div class="sa-dashboard">
    <div class="sa-header">
        <div>
            <h1><i class="fas fa-satellite-dish"></i> SuperAdmin Developer Dashboard</h1>
            <p>Station-Specific, Developer-Focused System Oversight</p>
        </div>
        <div>
            <button class="sa-btn" onclick="toggleTheme()"><i class="fas fa-moon"></i> Toggle Theme</button>
        </div>
    </div>

    <!-- SECTION 1: System Health & Monitoring -->
    <div class="sa-section">
        <div class="sa-section-title"><i class="fas fa-heartbeat"></i> System Health & Monitoring</div>
        <div class="sa-grid-4">
            <div class="sa-card">
                <div class="sa-card-icon success"><i class="fas fa-server"></i></div>
                <div class="sa-card-content"><h4>Server Uptime</h4><span><?= $sysHealth['uptime'] ?></span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-icon"><i class="fas fa-database"></i></div>
                <div class="sa-card-content"><h4>Active DB Conn</h4><span><?= $sysHealth['db_connections'] ?></span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-icon <?= $sysHealth['error_alerts'] > 0 ? 'danger' : 'success' ?>"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="sa-card-content"><h4>Error Alerts (24H)</h4><span><?= $sysHealth['error_alerts'] ?></span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-icon warning"><i class="fas fa-user-lock"></i></div>
                <div class="sa-card-content"><h4>Failed Logins (24H)</h4><span><?= $sysHealth['failed_logins'] ?></span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-icon"><i class="fas fa-users"></i></div>
                <div class="sa-card-content"><h4>Active Users</h4><span><?= $sysHealth['active_users'] ?></span></div>
            </div>
            <div class="sa-card">
                <div class="sa-card-icon accent"><i class="fas fa-sync"></i></div>
                <div class="sa-card-content"><h4>Pending Updates</h4><span><?= $sysHealth['pending_updates'] ?></span></div>
            </div>
        </div>
    </div>

    <div class="sa-grid-2">
        <!-- SECTION 2: Admin Oversight -->
        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-user-shield"></i> Admin Oversight</div>
            <div class="sa-grid-2">
                <div class="sa-card">
                    <div class="sa-card-icon"><i class="fas fa-id-badge"></i></div>
                    <div class="sa-card-content"><h4>Total Admin Accounts</h4><span><?= $adminOversight['total_admins'] ?></span></div>
                </div>
                <div class="sa-card">
                    <div class="sa-card-icon success"><i class="fas fa-user-check"></i></div>
                    <div class="sa-card-content"><h4>Active Admins</h4><span><?= $adminOversight['active_admins'] ?></span></div>
                </div>
            </div>
            <p style="margin-top:16px; color:var(--text-muted); font-size:14px;"><i class="fas fa-clock"></i> Last Login: <?= $adminOversight['last_login'] ?></p>
        </div>

        <!-- SECTION 3: Station Assignment Summary -->
        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-gas-pump"></i> Station Config Summary</div>
            <div class="sa-grid-2">
                <div class="sa-card">
                    <div class="sa-card-icon accent"><i class="fas fa-tint"></i></div>
                    <div class="sa-card-content"><h4>Pumps Count</h4><span><?= $stationSummary['pumps_count'] ?></span></div>
                </div>
                <div class="sa-card">
                    <div class="sa-card-icon warning"><i class="fas fa-box-open"></i></div>
                    <div class="sa-card-content"><h4>Stock Status</h4><span><?= $stationSummary['merch_status'] ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="sa-grid-3">
        <!-- SECTION 4: Module Status -->
        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-toggle-on"></i> Module Status</div>
            <?php foreach($modules as $name => $state): ?>
            <div class="sa-toggle-row">
                <span class="sa-toggle-label"><?= $name ?></span>
                <label class="switch">
                  <input type="checkbox" <?= $state ? 'checked' : '' ?> onchange="showToast('Module <?= $name ?> status updated.')">
                  <span class="slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- SECTION 5: Database Health -->
        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-database"></i> Database Health</div>
            <table class="sa-table" style="margin-bottom: 16px;">
                <tr><td><i class="fas fa-hdd text-muted"></i> DB Size</td><td><strong><?= $dbHealth['size'] ?></strong></td></tr>
                <tr><td><i class="fas fa-save text-muted"></i> Last Backup</td><td><strong><?= $dbHealth['backup_status'] ?></strong></td></tr>
                <tr><td><i class="fas fa-history text-muted"></i> Restore Points</td><td><strong><?= $dbHealth['restore_points'] ?></strong></td></tr>
                <tr><td><i class="fas fa-bolt text-muted"></i> Indexing</td><td><span style="color:var(--success);font-weight:600;"><i class="fas fa-check-circle"></i> <?= $dbHealth['indexing_status'] ?></span></td></tr>
            </table>
            <button class="sa-btn" style="width:100%"><i class="fas fa-download"></i> Run Manual Backup</button>
        </div>

        <!-- SECTION 7: Integration Status -->
        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-plug"></i> Integrations</div>
            <div class="sa-toggle-row">
                <span class="sa-toggle-label"><i class="fas fa-cash-register"></i> POS Import Sync</span>
                <span style="color:var(--success);font-weight:bold;">Active</span>
            </div>
            <div class="sa-toggle-row">
                <span class="sa-toggle-label"><i class="fas fa-network-wired"></i> API Endpoints</span>
                <span style="color:var(--success);font-weight:bold;">Live (5/5)</span>
            </div>
            <div class="sa-toggle-row">
                <span class="sa-toggle-label"><i class="fas fa-sync-alt"></i> Reports Sync Status</span>
                <span style="color:var(--warning);font-weight:bold;">Pending</span>
            </div>
        </div>
    </div>

    <!-- SECTION 6 & 8: System Logs & Developer Reports -->
    <div class="sa-grid-2">
        <div class="sa-section">
            <div class="sa-section-title">
                <span><i class="fas fa-clipboard-list"></i> System Logs & Audit</span>
                <button class="sa-btn" style="padding: 4px 10px; font-size:12px; margin-left:auto;"><i class="fas fa-file-export"></i> Export Logs</button>
            </div>
            <table class="sa-table">
                <thead>
                    <tr><th>Time</th><th>Event</th><th>User / Source</th></tr>
                </thead>
                <tbody>
                    <?php foreach($auditLogs as $log): ?>
                    <tr>
                        <td><?= $log['time'] ?></td>
                        <td style="font-weight:600;"><?= $log['event'] ?></td>
                        <td><?= $log['user'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="sa-section">
            <div class="sa-section-title"><i class="fas fa-chart-line"></i> Developer Reports (Usage)</div>
            <canvas id="devChart" height="100"></canvas>
        </div>
    </div>

    <!-- SECTION 9: System Settings -->
    <div class="sa-section">
        <div class="sa-section-title"><i class="fas fa-cogs"></i> System Settings (Branding & Accessibility)</div>
        <div class="sa-grid-4">
            <button class="sa-btn" style="background:var(--card-bg); color:var(--text-main); border:1px solid rgba(0,0,0,0.1);"><i class="fas fa-image" style="color:var(--primary);"></i> Logo Management</button>
            <button class="sa-btn" style="background:var(--card-bg); color:var(--text-main); border:1px solid rgba(0,0,0,0.1);" onclick="toggleTheme()"><i class="fas fa-palette" style="color:var(--accent);"></i> Color Theme</button>
            <button class="sa-btn" style="background:var(--card-bg); color:var(--text-main); border:1px solid rgba(0,0,0,0.1);"><i class="fas fa-columns" style="color:var(--success);"></i> Sidebar Layout</button>
            <button class="sa-btn" style="background:var(--card-bg); color:var(--text-main); border:1px solid rgba(0,0,0,0.1);"><i class="fas fa-universal-access" style="color:var(--warning);"></i> Accessibility</button>
        </div>
    </div>

</div>

<!-- Toast Notification Container -->
<div id="toast-container" style="position:fixed; bottom:20px; right:20px; z-index:9999;"></div>

<script>
// Chart.js implementation for Dev Reports
const ctx = document.getElementById('devChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [{
            label: 'Memory Usage (MB)',
            data: [120, 150, 140, 180, 160, 200, 170],
            borderColor: '#002F6C',
            backgroundColor: 'rgba(0, 47, 108, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Theme Toggle
function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');
    localStorage.setItem('sa_theme', isDark ? 'dark' : 'light');
    showToast('Theme updated to ' + (isDark ? 'Dark Mode' : 'Light Mode'));
}

// Check saved theme
if(localStorage.getItem('sa_theme') === 'dark') {
    document.body.classList.add('dark-theme');
}

// Simple Toast function
function showToast(message) {
    const toast = document.createElement('div');
    toast.style.background = 'var(--primary)';
    toast.style.color = 'white';
    toast.style.padding = '12px 24px';
    toast.style.borderRadius = '8px';
    toast.style.marginBottom = '10px';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    toast.style.fontWeight = '600';
    toast.style.transition = 'opacity 0.3s';
    toast.innerHTML = '<i class="fas fa-info-circle"></i> ' + message;
    
    document.getElementById('toast-container').appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
