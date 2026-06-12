<?php
/**
 * ADMIN REPORTS MODULE
 * Comprehensive reporting system with shift segregation
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$page_id = 'admin_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Only Admin and SuperAdmin can access
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// Get current section
$valid_sections = [
    'shift_reports',
    'daily_consolidation', 
    'fuel_inventory',
    'merchandise_inventory',
    'job_orders',
    'payments',
    'customers',
    'suppliers',
    'financial',
    'activity_log',
    'audit_trail',
    'calendar_schedule'
];

$section = trim($_GET['section'] ?? 'shift_reports');
if (!in_array($section, $valid_sections)) {
    $section = 'shift_reports';
}

// Date range handling — default to last active date
$range = strtolower(trim($_GET['range'] ?? 'latest'));
$today = date('Y-m-d');

// Find latest date with any transaction data
$latest_date = $today;
try {
    $ld1 = $pdo->prepare("SELECT MAX(DATE(transaction_date)) FROM fuel_transactions WHERE station_id=?");
    $ld1->execute([$station_id]); $d1 = $ld1->fetchColumn();
    $ld2 = $pdo->prepare("SELECT MAX(DATE(created_at)) FROM merchandise_transactions WHERE station_id=?");
    $ld2->execute([$station_id]); $d2 = $ld2->fetchColumn();
    $ld3 = $pdo->prepare("SELECT MAX(DATE(created_at)) FROM job_orders WHERE station_id=?");
    $ld3->execute([$station_id]); $d3 = $ld3->fetchColumn();
    if ($d1) $latest_date = $d1;
    elseif ($d2) $latest_date = $d2;
    elseif ($d3) $latest_date = $d3;
} catch (Exception $e) {}

switch ($range) {
    case 'week':
        $date_start = date('Y-m-d', strtotime('monday this week'));
        $date_end = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $date_start = date('Y-m-01');
        $date_end = date('Y-m-t');
        break;
    case 'custom':
        $date_start = trim($_GET['start'] ?? $latest_date);
        $date_end = trim($_GET['end'] ?? $latest_date);
        break;
    case 'today':
        $date_start = $today;
        $date_end = $today;
        break;
    default: // latest
        $date_start = $latest_date;
        $date_end = $latest_date;
        break;
}

// Get station name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Main Container */
    .main-content {
        margin-left: 0;
        padding: 20px;
        background: #f5f5f5;
    }
    
    /* Page Header */
    .reports-header {
        background: white;
        padding: 20px 30px;
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .reports-header h1 {
        margin: 0 0 5px 0;
        color: #003366;
        font-size: 28px;
        font-weight: 700;
    }
    
    .reports-header p {
        margin: 0;
        color: #666666;
        font-size: 14px;
    }

    /* Date Range Filter */
    .date-filter {
        background: white;
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    
    .date-filter label {
        font-weight: 600;
        color: #003366;
    }
    
    .date-filter select,
    .date-filter input {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
    }
    
    .date-filter button {
        padding: 8px 20px;
        background: #003366;
        color: white;
        border: none;
        border-radius: 5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .date-filter button:hover {
        background: #002244;
    }
    
    /* Report Content */
    .report-content {
        background: white;
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .report-content h2 {
        margin: 0 0 20px 0;
        color: #003366;
        font-size: 22px;
        font-weight: 700;
        padding-bottom: 10px;
        border-bottom: 2px solid #003366;
    }
    
    /* Summary Cards */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .summary-card {
        background: linear-gradient(135deg, #003366 0%, #004d99 100%);
        padding: 20px;
        border-radius: 10px;
        color: white;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    
    .summary-card.red {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    }
    
    .summary-card.green {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    }
    
    .summary-card.orange {
        background: linear-gradient(135deg, #fd7e14 0%, #e8590c 100%);
    }
    
    .summary-card-label {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 5px;
    }
    
    .summary-card-value {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
    }
    
    .summary-card-subtitle {
        font-size: 12px;
        opacity: 0.8;
    }
    
    /* Charts */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .chart-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        border: 1px solid #e0e0e0;
    }
    
    .chart-card h3 {
        margin: 0 0 15px 0;
        color: #003366;
        font-size: 18px;
        font-weight: 600;
    }
    
    /* Tables */
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    .report-table thead tr {
        background: #003366;
        color: white;
    }
    
    .report-table th,
    .report-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .report-table tbody tr:hover {
        background: #f7f7f7;
    }
    
    /* Export Button */
    .export-btn {
        padding: 10px 20px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 5px;
        font-weight: 600;
        cursor: pointer;
        float: right;
        margin-bottom: 15px;
    }
    
    .export-btn:hover {
        background: #218838;
    }
    
    /* Shift Tabs */
    .shift-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .shift-tab {
        padding: 12px 30px;
        background: #f7f7f7;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .shift-tab.active {
        background: #003366;
        color: white;
    }
    
    .shift-tab:hover {
        background: #e8e8e8;
    }
    
    .shift-tab.active:hover {
        background: #002244;
    }
</style>

<div class="main-content">
    <!-- Page Header -->
    <div class="reports-header">
        <h1><i class="fas fa-chart-bar"></i> Admin Reports & Analytics</h1>
        <p><?= htmlspecialchars($station_name) ?> - Comprehensive Reporting System</p>
    </div>
    

    <!-- Date Range Filter -->
    <div class="date-filter">
        <label>Date Range:</label>
        <select id="rangeSelect" onchange="updateDateRange()">
            <option value="latest" <?= $range === 'latest' ? 'selected' : '' ?>>Latest Active Date (<?= $latest_date ?>)</option>
            <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This Week</option>
            <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
        </select>
        
        <div id="customDates" style="display: <?= $range === 'custom' ? 'flex' : 'none' ?>; gap: 10px;">
            <input type="date" id="startDate" value="<?= $date_start ?>" />
            <input type="date" id="endDate" value="<?= $date_end ?>" />
        </div>
        
        <button onclick="applyFilter()">Apply Filter</button>
        <button onclick="exportReport()" style="background: #28a745; margin-left: auto;">
            <i class="fas fa-download"></i> Export
        </button>
    </div>
    
    <!-- Report Content -->
    <div class="report-content">
        <?php
        $report_file = __DIR__ . "/reports/admin_{$section}.php";
        if (file_exists($report_file)) {
            include $report_file;
        } else {
            echo '<div style="text-align:center;padding:60px;color:#999;"><i class="fas fa-tools" style="font-size:40px;display:block;margin-bottom:12px;"></i>';
            echo '<h2 style="color:#003366;">'.ucwords(str_replace('_',' ',$section)).'</h2>';
            echo '<p>This report section will be available soon.</p></div>';
        }
        ?>
    </div>
</div>

<script>
function updateDateRange() {
    const range = document.getElementById('rangeSelect').value;
    const customDates = document.getElementById('customDates');
    customDates.style.display = range === 'custom' ? 'flex' : 'none';
}

function applyFilter() {
    const range = document.getElementById('rangeSelect').value;
    const section = new URLSearchParams(window.location.search).get('section') || 'shift_reports';
    let url = `?section=${section}&range=${range}`;
    
    if (range === 'custom') {
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        url += `&start=${start}&end=${end}`;
    }
    
    window.location.href = url;
}

function exportReport() {
    alert('Export functionality will be implemented. This will generate PDF/Excel reports.');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
