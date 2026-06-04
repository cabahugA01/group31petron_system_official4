<?php
/**
 * EXPORT VALIDATED TRANSACTIONS
 * 
 * Exports validated transactions to Excel, CSV, or PDF
 * Supports filtering by search, date range
 * 
 * Parameters:
 * - $_GET['format'] - 'excel', 'csv', or 'pdf'
 * - $_GET['search'] - Optional search filter
 * - $_GET['date_from'] - Optional date from filter
 * - $_GET['date_to'] - Optional date to filter
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';

// Verify login
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$me = current_user();
$station_id = (int) user_station_id();

// Get parameters
$format = trim($_GET['format'] ?? 'csv');
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Validate format
if (!in_array($format, ['excel', 'csv', 'pdf'])) {
    die('Invalid format');
}

// ── Helper functions ──────────────────────────────────────────────────────────
function vt_cols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[strtolower($r['Field'])] = true;
        return $map;
    } catch (Exception $e) { return []; }
}
function vt_has(array $map, string $col): bool { return isset($map[strtolower($col)]); }

$mt_cols = vt_cols($pdo, 'merchandise_transactions');
$jo_cols = vt_cols($pdo, 'job_orders');

// ── Fetch validated transactions ──────────────────────────────────────────────
$rows = [];

// Merchandise APPROVED transactions
$mt_status_col = vt_has($mt_cols, 'validation_status') ? 'mt.validation_status' : "'Approved'";
$mt_staff_col  = vt_has($mt_cols, 'staff_id') ? 'u.name' : "'Unknown'";
$mt_date_col   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";
$mt_vby_col    = vt_has($mt_cols, 'validated_by') ? 'v.name' : "'N/A'";

$mt_where = "WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) = 'approved'";
$mt_params = [$station_id];
if ($search !== '') {
    $mt_where .= " AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)";
    $mt_params[] = "%$search%"; $mt_params[] = "%$search%";
}
if ($date_from !== '') {
    $mt_where .= " AND {$mt_date_col} >= ?";
    $mt_params[] = $date_from;
}
if ($date_to !== '') {
    $mt_where .= " AND {$mt_date_col} <= ?";
    $mt_params[] = $date_to;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            mt.transaction_id AS txn_id,
            COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
            'Merchandise' AS entry_type,
            COALESCE(mt.item_sku, 'N/A') AS items_service,
            mt.total_amount AS amount,
            COALESCE(mt.payment_method,'Cash') AS payment_method,
            {$mt_date_col} AS txn_date,
            COALESCE({$mt_staff_col},'Unknown') AS staff_name,
            COALESCE({$mt_vby_col},'N/A') AS validated_by
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id = mt.staff_id
        LEFT JOIN users v ON v.id = mt.validated_by
        {$mt_where}
        ORDER BY txn_date DESC
        LIMIT 5000
    ");
    $stmt->execute($mt_params);
    $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    error_log("Export merchandise error: " . $e->getMessage());
}

// Job Orders APPROVED
$jo_status_col = vt_has($jo_cols, 'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_staff_col  = vt_has($jo_cols, 'created_by') ? 'COALESCE(jo.created_by, jo.user_id)' : 'jo.user_id';
$jo_pay_col    = vt_has($jo_cols, 'payment_method') ? 'COALESCE(jo.payment_method,\'N/A\')' : "'N/A'";
$jo_cost_col   = vt_has($jo_cols, 'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_vby_col    = vt_has($jo_cols, 'validated_by') ? 'v.name' : "'N/A'";

$jo_where = "WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE({$jo_status_col},''))) = 'approved'";
$jo_params = [$station_id];
if ($search !== '') {
    $jo_where .= " AND (jo.customer_name LIKE ? OR jo.service_type LIKE ? OR jo.vehicle_plate LIKE ?)";
    $jo_params[] = "%$search%"; $jo_params[] = "%$search%"; $jo_params[] = "%$search%";
}
if ($date_from !== '') {
    $jo_where .= " AND jo.created_at >= ?";
    $jo_params[] = $date_from;
}
if ($date_to !== '') {
    $jo_where .= " AND jo.created_at <= ?";
    $jo_params[] = $date_to;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('JO-', jo.id) AS txn_id,
            COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
            'Job Order' AS entry_type,
            CONCAT(COALESCE(jo.service_type,'Service'), 
                   CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != '' 
                        THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END) AS items_service,
            {$jo_cost_col} AS amount,
            {$jo_pay_col} AS payment_method,
            jo.created_at AS txn_date,
            COALESCE(u.name,'Unknown') AS staff_name,
            COALESCE({$jo_vby_col},'N/A') AS validated_by
        FROM job_orders jo
        LEFT JOIN users u ON u.id = {$jo_staff_col}
        LEFT JOIN users v ON v.id = jo.validated_by
        {$jo_where}
        ORDER BY jo.created_at DESC
        LIMIT 5000
    ");
    $stmt->execute($jo_params);
    $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    error_log("Export job orders error: " . $e->getMessage());
}

// Sort by date
usort($rows, fn($a, $b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));

if (count($rows) === 0) {
    die('No validated transactions to export');
}

// ── Export based on format ────────────────────────────────────────────────────
if ($format === 'csv') {
    // CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="validated_transactions_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Header
    fputcsv($output, ['Transaction ID', 'Customer', 'Type', 'Items/Service', 'Amount', 'Payment Method', 'Date/Time', 'Staff', 'Validated By']);
    
    // CSV Rows
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['txn_id'],
            $row['customer'],
            $row['entry_type'],
            $row['items_service'],
            number_format((float)$row['amount'], 2),
            $row['payment_method'],
            date('M d, Y H:i', strtotime($row['txn_date'])),
            $row['staff_name'],
            $row['validated_by']
        ]);
    }
    
    fclose($output);
    exit;
    
} elseif ($format === 'excel') {
    // Simple Excel export using HTML table (compatible without PHPSpreadsheet)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="validated_transactions_' . date('Y-m-d_His') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"><style>table { border-collapse: collapse; } td, th { border: 1px solid #ddd; padding: 8px; } th { background-color: #002F70; color: white; font-weight: bold; }</style></head>';
    echo '<body>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Transaction ID</th>';
    echo '<th>Customer</th>';
    echo '<th>Type</th>';
    echo '<th>Items/Service</th>';
    echo '<th>Amount</th>';
    echo '<th>Payment Method</th>';
    echo '<th>Date/Time</th>';
    echo '<th>Staff</th>';
    echo '<th>Validated By</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($row['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($row['items_service']) . '</td>';
        echo '<td style="text-align:right;">' . number_format((float)$row['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($row['payment_method']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($row['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['staff_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['validated_by']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
    
} elseif ($format === 'pdf') {
    // Simple PDF export using HTML (requires browser's PDF print)
    // For production, consider using TCPDF or similar library
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>';
    echo '<html><head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Validated Transactions - ' . date('Y-m-d') . '</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }';
    echo 'h1 { color: #002F70; font-size: 18px; margin-bottom: 10px; }';
    echo 'table { width: 100%; border-collapse: collapse; margin-top: 10px; }';
    echo 'th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F70; color: white; font-weight: bold; font-size: 11px; }';
    echo 'td { font-size: 10px; }';
    echo '.amount { text-align: right; font-weight: bold; color: #002F70; }';
    echo '@media print { button { display: none; } }';
    echo '</style>';
    echo '</head><body>';
    
    echo '<h1>Validated Transactions Report</h1>';
    echo '<p>Generated: ' . date('F d, Y h:i A') . ' | Total Records: ' . count($rows) . '</p>';
    
    echo '<button onclick="window.print()" style="padding:10px 20px;background:#002F70;color:white;border:none;border-radius:6px;cursor:pointer;margin-bottom:10px;">Print / Save as PDF</button>';
    
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Transaction ID</th>';
    echo '<th>Customer</th>';
    echo '<th>Type</th>';
    echo '<th>Items/Service</th>';
    echo '<th>Amount</th>';
    echo '<th>Payment</th>';
    echo '<th>Date/Time</th>';
    echo '<th>Staff</th>';
    echo '<th>Validated By</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    $total_amount = 0;
    foreach ($rows as $row) {
        $total_amount += (float)$row['amount'];
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['txn_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['customer']) . '</td>';
        echo '<td>' . htmlspecialchars($row['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars(substr($row['items_service'], 0, 40)) . '</td>';
        echo '<td class="amount">₱' . number_format((float)$row['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($row['payment_method']) . '</td>';
        echo '<td>' . date('M d, Y H:i', strtotime($row['txn_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['staff_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['validated_by']) . '</td>';
        echo '</tr>';
    }
    
    echo '<tr style="background:#f8fafc;font-weight:bold;">';
    echo '<td colspan="4" style="text-align:right;">TOTAL:</td>';
    echo '<td class="amount">₱' . number_format($total_amount, 2) . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
    
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}
