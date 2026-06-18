<?php
/**
 * STAFF CUSTOMERS REPORT
 * Complete customer management tracking with shift summaries
 * Plain black & white design, structured tabular format
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
$date_start = trim($_GET['date_start'] ?? date('Y-m-01'));
$date_end = trim($_GET['date_end'] ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) $date_end = $today;

// Helper functions
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Check available tables
$has_customers = table_exists($pdo, 'customers');

// Initialize data
$customers = [];
$shift1_new_customers = 0;
$shift1_transactions = 0;
$shift2_new_customers = 0;
$shift2_transactions = 0;

// ============================================================
// FETCH CUSTOMERS
// ============================================================
if ($has_customers) {
    try {
        $sql = "SELECT 
                    c.id,
                    c.name,
                    COALESCE(c.contact_number, c.phone, '—') AS contact_number,
                    COALESCE(c.address, '—') AS address,
                    COALESCE(c.status, 'active') AS status,
                    COALESCE(c.credit_limit, 0) AS credit_limit,
                    COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                    c.created_at,
                    NULL AS encoder
            FROM customers c
            WHERE c.station_id = ? 
              AND DATE(c.created_at) BETWEEN ? AND ?
            ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate shift totals
        foreach ($customers as $customer) {
            $time = date('H:i:s', strtotime($customer['created_at']));
            $hour = (int)date('H', strtotime($customer['created_at']));
            
            // Shift 1: 6AM - 2PM (06:00 - 14:00)
            // Shift 2: 2PM - 10PM (14:00 - 22:00)
            if ($hour >= 6 && $hour < 14) {
                $shift1_new_customers++;
            } else {
                $shift2_new_customers++;
            }
        }
        
        // Get transaction counts per shift
        if (table_exists($pdo, 'fuel_transactions')) {
            $trans_sql = "SELECT 
                            COUNT(*) as count,
                            CASE 
                                WHEN HOUR(transaction_date) >= 6 AND HOUR(transaction_date) < 14 THEN 'shift1'
                                ELSE 'shift2'
                            END as shift
                        FROM fuel_transactions
                        WHERE station_id = ? 
                          AND DATE(transaction_date) BETWEEN ? AND ?
                        GROUP BY shift";
            $trans_stmt = $pdo->prepare($trans_sql);
            $trans_stmt->execute([$station_id, $date_start, $date_end]);
            $trans_data = $trans_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($trans_data as $td) {
                if ($td['shift'] === 'shift1') {
                    $shift1_transactions = (int)$td['count'];
                } else {
                    $shift2_transactions = (int)$td['count'];
                }
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error fetching customers: " . $e->getMessage();
    }
}

// Calculate totals
$total_new_customers = $shift1_new_customers + $shift2_new_customers;
$total_transactions = $shift1_transactions + $shift2_transactions;

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Customers_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Customers Report</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Header
    echo '<h1>CUSTOMERS REPORT</h1>';
    echo '<p>' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p>';
    echo '<br/>';
    
    // CUSTOMERS TABLE
    echo '<h2>CUSTOMER LIST</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Name</th>';
    echo '<th>Contact Number</th>';
    echo '<th>Address</th>';
    echo '<th>Status</th>';
    echo '<th>Credit Limit</th>';
    echo '<th>Balance</th>';
    echo '<th>Created At</th>';
    echo '<th>Encoder</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($customers) > 0) {
        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($customer['id']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['name']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['contact_number'] ?? '—') . '</td>';
            echo '<td>' . htmlspecialchars($customer['address'] ?? '—') . '</td>';
            echo '<td class="text-center">' . strtoupper($customer['status'] ?? 'active') . '</td>';
            echo '<td class="text-right">₱' . number_format($customer['credit_limit'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($customer['outstanding_balance'], 2) . '</td>';
            echo '<td>' . date('M d, Y H:i', strtotime($customer['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($customer['encoder'] ?? 'N/A') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">No customers found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Shift 1 Summary
    echo '<h3>SHIFT 1 CUSTOMER ACTIVITY (6AM - 2PM)</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Activity</th>';
    echo '<th>Count</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr><td>New Customers Added</td><td class="text-right font-bold">' . $shift1_new_customers . '</td></tr>';
    echo '<tr><td>Customer Transactions Processed</td><td class="text-right font-bold">' . $shift1_transactions . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Shift 2 Summary
    echo '<h3>SHIFT 2 CUSTOMER ACTIVITY (2PM - 10PM)</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Activity</th>';
    echo '<th>Count</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr><td>New Customers Added</td><td class="text-right font-bold">' . $shift2_new_customers . '</td></tr>';
    echo '<tr><td>Customer Transactions Processed</td><td class="text-right font-bold">' . $shift2_transactions . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Overall Summary
    echo '<h3>OVERALL DAILY SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Activity</th>';
    echo '<th>Total Count</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr><td>Total New Customers</td><td class="text-right font-bold">' . $total_new_customers . '</td></tr>';
    echo '<tr><td>Total Customer Transactions</td><td class="text-right font-bold">' . $total_transactions . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    exit;
}

$page_title = "Customers Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 15px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    
    .header h1 {
        font-size: 22px;
        margin: 0 0 8px 0;
        font-weight: 700;
        color: #000;
    }
    
    .header p {
        font-size: 12px;
        color: #000;
        margin: 3px 0;
    }
    
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .date-controls {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 12px;
    }
    
    .date-controls label {
        font-weight: 700;
        color: #000;
    }
    
    .date-controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #000;
        font-size: 12px;
    }
    
    .btn {
        padding: 7px 14px;
        border-radius: 4px;
        background: #ffffff;
        border: 1px solid #00264D;
        color: #00264D;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .2s;
        white-space: nowrap;
    }
    
    .btn:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .btn-primary {
        border-color: #00264D;
        color: #00264D;
    }
    
    .btn-primary:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .btn-success {
        border-color: #16a34a;
        color: #16a34a;
    }
    
    .btn-success:hover {
        background: #16a34a;
        color: #ffffff;
    }
    
    .btn-secondary {
        border-color: #475569;
        color: #475569;
    }
    
    .btn-secondary:hover {
        background: #475569;
        color: #ffffff;
    }
    
    .print-area {
        background: #fff;
    }
    
    .content {
        padding: 15px 20px;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 20px 0 10px 0;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    
    .table-container {
        overflow-x: visible;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #000;
        font-size: 10px;
        table-layout: fixed;
    }
    
    thead { background: #fff; color: #000; }
    th { padding: 6px 4px; text-align: left; font-weight: 700; font-size: 9px; text-transform: uppercase; border: 1px solid #000; white-space: nowrap; }
    td { padding: 5px 4px; border: 1px solid #000; font-size: 10px; white-space: nowrap; }
    tbody tr { background: #fff; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    .shift-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
    .shift-box { background: #fff; padding: 15px; border: 1px solid #000; }
    .shift-box h3 { font-size: 14px; color: #000; margin: 0 0 10px 0; font-weight: 700; border-bottom: 1px solid #000; padding-bottom: 8px; text-transform: uppercase; }
    .shift-box table { font-size: 11px; }
    .shift-box td { padding: 6px 4px; border: none; border-bottom: 1px solid #ddd; }
    
    @media print {
        @page { size: legal portrait; margin: 0.5in 0.4in; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important; top: 0 !important; left: 0 !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
        }
        html, body { margin: 0 !important; padding: 0 !important; background: white !important; }
        .container, .content { margin: 0 !important; padding: 0 !important; }

        /* ── Kill ALL icons ── */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .table-container { overflow: visible !important; width: 100% !important; text-align: center !important; }
        table { width: 95% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; table-layout: auto !important; margin: 0 auto 8px auto !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { font-size: 10px !important; padding: 6px 8px !important; border: 1px solid #000 !important; background: #fff !important; color: #000 !important; font-weight: 700 !important; text-align: center !important; white-space: nowrap !important; }
        td { font-size: 9px !important; padding: 5px 8px !important; border: 1px solid #000 !important; white-space: nowrap !important; vertical-align: top !important; }
    }
</style>

<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls">
        <label><strong>From:</strong></label>
        <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
        <label><strong>To:</strong></label>
        <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            <i class="fa-solid fa-filter"></i> Apply
        </button>
    </div>
    
    <div>
        <a href="?export=excel&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>" class="btn btn-success">
            <i class="fa-solid fa-file-excel"></i> Export Excel
        </a>
        <button class="btn btn-secondary" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <div class="container">
        <div class="header">
            <h1>CUSTOMERS REPORT</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
            <p><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> - <?= date('F d, Y', strtotime($date_end)) ?></p>
        </div>
        
        <div class="content">
            <div class="section-title">CUSTOMER LIST</div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>NAME</th>
                            <th>CONTACT NUMBER</th>
                            <th>ADDRESS</th>
                            <th>STATUS</th>
                            <th>CREDIT LIMIT</th>
                            <th>BALANCE</th>
                            <th>CREATED AT</th>
                            <th>ENCODER</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($customers) > 0): foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($customer['id']) ?></strong></td>
                            <td><?= htmlspecialchars($customer['name']) ?></td>
                            <td><?= htmlspecialchars($customer['contact_number'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($customer['address'] ?? '—') ?></td>
                            <td class="text-center"><?= strtoupper($customer['status'] ?? 'active') ?></td>
                            <td class="text-right">₱<?= number_format($customer['credit_limit'], 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($customer['outstanding_balance'], 2) ?></td>
                            <td><?= date('M d, Y H:i', strtotime($customer['created_at'])) ?></td>
                            <td><?= htmlspecialchars($customer['encoder'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="9" style="text-align: center; padding: 40px;">No customers found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section-title">SHIFT SUMMARY</div>
            <div class="shift-summary">
                <div class="shift-box">
                    <h3>SHIFT 1 (6AM - 2PM)</h3>
                    <table>
                        <tbody>
                            <tr><td><strong>New Customers Added:</strong></td><td class="text-right font-bold"><?= $shift1_new_customers ?></td></tr>
                            <tr><td><strong>Customer Transactions:</strong></td><td class="text-right font-bold"><?= $shift1_transactions ?></td></tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="shift-box">
                    <h3>SHIFT 2 (2PM - 10PM)</h3>
                    <table>
                        <tbody>
                            <tr><td><strong>New Customers Added:</strong></td><td class="text-right font-bold"><?= $shift2_new_customers ?></td></tr>
                            <tr><td><strong>Customer Transactions:</strong></td><td class="text-right font-bold"><?= $shift2_transactions ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="section-title">OVERALL DAILY SUMMARY</div>
            <div class="shift-box" style="max-width: 600px; margin: 0 auto;">
                <table>
                    <tbody>
                        <tr><td><strong>Total New Customers:</strong></td><td class="text-right font-bold"><?= $total_new_customers ?></td></tr>
                        <tr><td><strong>Total Customer Transactions:</strong></td><td class="text-right font-bold"><?= $total_transactions ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const dateStart = document.getElementById('date_start').value;
    const dateEnd = document.getElementById('date_end').value;
    window.location.href = `?date_start=${dateStart}&date_end=${dateEnd}`;
}
document.getElementById('date_start').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
document.getElementById('date_end').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
