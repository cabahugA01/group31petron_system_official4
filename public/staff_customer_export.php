<?php
/**
 * STAFF CUSTOMER REPORT EXPORT - FILTERED CUSTOMERS
 * Exports the filtered customer list matching the current filter state.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    die('Unauthorized access');
}

if (!customer_can_view_all_stations($role) && !$station_id) {
    die('Error: You are not assigned to a station.');
}

// 1. Get filter inputs
$search   = trim($_GET['search'] ?? '');
$type     = trim($_GET['type'] ?? '');
$status   = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$format   = strtolower(trim($_GET['format'] ?? 'excel'));

// 2. Build where clause
$where = [];
$params = [];
customer_apply_station_scope($where, $params, 'c', $role, $station_id);

$customerIdExpr = customer_id_expr($pdo, 'c');
$displayNameExpr = customer_display_name_expr($pdo, 'c');
$firstNameExpr = customer_first_name_expr($pdo, 'c');
$middleNameExpr = customer_middle_name_expr($pdo, 'c');
$lastNameExpr = customer_last_name_expr($pdo, 'c');
$contactExpr = customer_contact_expr($pdo, 'c');
$typeExpr = customer_type_expr($pdo, 'c');
$statusExpr = customer_status_expr($pdo, 'c');
$registeredExpr = customer_registered_at_expr($pdo, 'c');

if ($search !== '') {
    $where[] = "($customerIdExpr LIKE ? OR $displayNameExpr LIKE ? OR $contactExpr LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
}
if ($type !== '' && $type !== 'registered') { $type = ''; }
if ($status !== '') {
    $where[] = "$statusExpr = ?";
    $params[] = $status;
}
if ($dateFrom !== '') {
    $where[] = "DATE($registeredExpr) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "DATE($registeredExpr) <= ?";
    $params[] = $dateTo;
}

$wc = $where ? implode(' AND ', $where) : '1=1';

// 3. Fetch customers with transaction stats
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        $customerIdExpr AS customer_id,
        $firstNameExpr AS first_name,
        $middleNameExpr AS middle_name,
        $lastNameExpr AS last_name,
        $contactExpr AS contact_number,
        $typeExpr AS customer_type,
        $statusExpr AS status,
        $registeredExpr AS registered_at,
        (
            (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND mt.station_id = c.station_id) +
            (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.id AND jo.station_id = c.station_id) +
            (SELECT COUNT(*) FROM fuel_transactions ft WHERE ft.customer_id = c.id AND ft.station_id = c.station_id)
        ) AS total_transactions,
        NULLIF(GREATEST(
            COALESCE((SELECT MAX(COALESCE(transaction_date,created_at)) FROM merchandise_transactions WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01'),
            COALESCE((SELECT MAX(created_at) FROM job_orders WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01'),
            COALESCE((SELECT MAX(COALESCE(transaction_date,created_at)) FROM fuel_transactions WHERE customer_id=c.id AND station_id=c.station_id),'2000-01-01')
        ),'2000-01-01') AS last_transaction
    FROM customers c
    WHERE $wc
    ORDER BY $registeredExpr DESC, c.id DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

function staff_export_customer_name(array $customer): string {
    return trim(implode(' ', array_filter([
        $customer['first_name'] ?? '',
        $customer['middle_name'] ?? '',
        $customer['last_name'] ?? '',
    ], fn($part) => trim((string)$part) !== '')));
}

// 4. Get Station Info
$station_name = 'Petron Station';
$station_location = '';
try {
    $st = $pdo->prepare("SELECT name, location, address FROM stations WHERE id = ? LIMIT 1");
    $st->execute([$station_id]);
    $station = $st->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        $station_name = $station['name'] ?: $station_name;
        $station_location = $station['address'] ?: ($station['location'] ?: '');
    }
} catch (Exception $e) {}

$generated_by = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($generated_by === '') $generated_by = $me['username'] ?? 'Staff';

// 5. Handle CSV Export
if ($format === 'csv') {
    $filename = 'Customers_Export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8
    fputcsv($output, ['Customer ID', 'Customer Name', 'Contact Number', 'Registration', 'Total Transactions', 'Last Transaction', 'Status', 'Date Registered']);
    foreach ($customers as $c) {
        $fullName = staff_export_customer_name($c);
        fputcsv($output, [
            $c['customer_id'],
            $fullName,
            $c['contact_number'],
            'Registered',
            $c['total_transactions'],
            $c['last_transaction'] ? date('Y-m-d H:i', strtotime($c['last_transaction'])) : 'Never',
            ucfirst($c['status']),
            date('Y-m-d H:i', strtotime($c['registered_at'])),
        ]);
    }
    fclose($output);
    exit;
}

// 6. Handle Excel Export
if ($format === 'excel') {
    $filename = 'Customers_Export_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"><style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 12px; }';
    echo 'th { background-color: #002F70; color: #ffffff; font-weight: bold; }';
    echo 'h1 { color: #002F70; font-size: 18px; margin: 5px 0; }';
    echo '</style></head><body>';
    echo '<h1>PETRON CUSTOMER LIST REPORT</h1>';
    echo '<p><strong>Station Name:</strong> ' . htmlspecialchars($station_name) . '<br>';
    echo '<strong>Branch/Address:</strong> ' . htmlspecialchars($station_location) . '<br>';
    echo '<strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '<br>';
    echo '<strong>Exported By:</strong> ' . htmlspecialchars($generated_by) . '</p>';
    
    echo '<table><thead><tr>';
    echo '<th>Customer ID</th><th>Customer Name</th><th>Contact Number</th><th>Registration</th><th>Total Transactions</th><th>Last Transaction</th><th>Status</th><th>Date Registered</th>';
    echo '</tr></thead><tbody>';
    foreach ($customers as $c) {
        $fullName = staff_export_customer_name($c);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($c['customer_id']) . '</td>';
        echo '<td>' . htmlspecialchars($fullName) . '</td>';
        echo '<td>' . htmlspecialchars($c['contact_number']) . '</td>';
        echo '<td>Registered</td>';
        echo '<td>' . htmlspecialchars($c['total_transactions']) . '</td>';
        echo '<td>' . ($c['last_transaction'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($c['last_transaction']))) : 'Never') . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($c['status'])) . '</td>';
        echo '<td>' . htmlspecialchars(date('Y-m-d H:i', strtotime($c['registered_at']))) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// 7. Handle PDF Export
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    
    // Build filter summary text
    $filterSummary = [];
    if ($search !== '') $filterSummary[] = "Search: \"$search\"";
    if ($type !== '') $filterSummary[] = "Registration: Registered";
    if ($status !== '') $filterSummary[] = "Status: " . ucfirst($status);
    if ($dateFrom !== '' && $dateTo !== '') {
        $filterSummary[] = "Date Registered: " . date('M d, Y', strtotime($dateFrom)) . " to " . date('M d, Y', strtotime($dateTo));
    } elseif ($dateFrom !== '') {
        $filterSummary[] = "Date Registered From: " . date('M d, Y', strtotime($dateFrom));
    } elseif ($dateTo !== '') {
        $filterSummary[] = "Date Registered To: " . date('M d, Y', strtotime($dateTo));
    }
    $filterText = !empty($filterSummary) ? implode(' | ', $filterSummary) : 'None';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Customer List Report - <?= date('Y-m-d') ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            html, body {
                font-family: Arial, sans-serif;
                color: #000;
                font-size: 11px;
                line-height: 1.4;
                margin: 0;
                padding: 0;
                overflow-x: hidden;
            }
            body {
                padding: 20px;
            }
            
            /* Report Header */
            .report-header {
                text-align: center;
                border-bottom: 3px solid #002F70;
                padding-bottom: 12px;
                margin-bottom: 16px;
            }
            .report-header h1 {
                font-size: 16px;
                font-weight: bold;
                color: #002F70;
                margin-bottom: 3px;
                text-transform: uppercase;
            }
            .report-header h2 {
                font-size: 14px;
                font-weight: bold;
                color: #002F70;
                margin-bottom: 10px;
                text-transform: uppercase;
            }
            
            /* Station Info Grid */
            .station-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 20px;
                margin-bottom: 14px;
                font-size: 10px;
            }
            .info-item {
                display: flex;
            }
            .info-label {
                font-weight: bold;
                min-width: 100px;
                color: #333;
            }
            .info-value {
                color: #000;
            }
            
            /* Applied Filters Section */
            .filters-section {
                background: #f8fafc;
                border: 1px solid #cbd5e1;
                border-radius: 4px;
                padding: 10px 12px;
                margin-bottom: 16px;
            }
            .filters-section h3 {
                font-size: 11px;
                font-weight: bold;
                color: #002F70;
                margin-bottom: 6px;
                text-transform: uppercase;
            }
            .filters-section p {
                font-size: 10px;
                color: #333;
                line-height: 1.5;
            }
            
            /* Customer Table */
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
                font-size: 9px;
            }
            thead {
                background: #002F70;
            }
            th {
                color: white;
                padding: 8px 6px;
                text-align: left;
                font-weight: bold;
                font-size: 9px;
                text-transform: uppercase;
                border: 1px solid #001f4d;
            }
            td {
                padding: 6px 6px;
                border: 1px solid #cbd5e1;
                color: #000;
                font-size: 9px;
            }
            tbody tr:nth-child(even) {
                background: #f8fafc;
            }
            tbody tr:hover {
                background: #e0e7ff;
            }
            

            
            /* No Print Elements */
            .no-print {
                display: flex;
                justify-content: center;
                gap: 10px;
                margin-bottom: 20px;
            }
            .no-print button {
                padding: 10px 20px;
                font-size: 13px;
                font-weight: bold;
                cursor: pointer;
                border: none;
                border-radius: 4px;
                transition: all 0.2s;
            }
            .btn-print {
                background: #002F70;
                color: white;
            }
            .btn-print:hover {
                background: #001f4d;
            }
            .btn-close {
                background: #64748b;
                color: white;
            }
            .btn-close:hover {
                background: #475569;
            }
            
            /* Hide icons */
            i, svg, .fas, .far, .fab, .fa {
                display: none !important;
            }
            
            /* Print Styles */
            @media print {
                @page {
                    margin: 0.5in;
                    size: landscape;
                }
                
                html, body {
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow-x: hidden !important;
                }
                
                body {
                    padding: 0 !important;
                }
                
                .no-print {
                    display: none !important;
                }
                
                /* Ensure table fits */
                table {
                    font-size: 8px !important;
                    page-break-inside: auto;
                }
                
                tr {
                    page-break-inside: avoid;
                    page-break-after: auto;
                }
                
                thead {
                    display: table-header-group;
                }
                
                th, td {
                    padding: 5px 4px !important;
                    font-size: 8px !important;
                }
            }
        </style>
    </head>
    <body>
        <!-- No Print Buttons -->
        <div class="no-print">
            <button class="btn-print" onclick="window.print()">Print</button>
            <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> Close Window</button>
        </div>
        
        <!-- Report Header -->
        <div class="report-header">
            <h1>PETRON STATION & SERVICE CENTER</h1>
            <h2>CUSTOMER LIST REPORT</h2>
        </div>
        
        <!-- Station & Report Information -->
        <div class="station-info">
            <div class="info-item">
                <span class="info-label">Branch:</span>
                <span class="info-value"><?= htmlspecialchars($station_name) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Generated By:</span>
                <span class="info-value"><?= htmlspecialchars($generated_by) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Address:</span>
                <span class="info-value"><?= htmlspecialchars($station_location ?: 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Print Date & Time:</span>
                <span class="info-value"><?= date('F d, Y h:i A') ?></span>
            </div>
        </div>
        
        <!-- Applied Filters Section -->
        <div class="filters-section">
            <h3>Applied Filters</h3>
            <p><?= htmlspecialchars($filterText) ?></p>
        </div>
        
        <!-- Customer Data Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">Customer ID</th>
                    <th style="width: 20%;">Customer Name</th>
                    <th style="width: 12%;">Contact No.</th>
                    <th style="width: 12%;">Registration</th>
                    <th style="width: 12%;">Total Transactions</th>
                    <th style="width: 15%;">Last Transaction</th>
                    <th style="width: 10%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #888; padding: 20px;">No customers found matching the filter criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): 
                        $fullName = staff_export_customer_name($c);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['customer_id']) ?></strong></td>
                            <td><?= htmlspecialchars($fullName) ?></td>
                            <td><?= htmlspecialchars($c['contact_number']) ?></td>
                            <td>Registered</td>
                            <td style="text-align: center;"><?= $c['total_transactions'] ?></td>
                            <td><?= $c['last_transaction'] ? date('M d, Y h:i A', strtotime($c['last_transaction'])) : 'Never' ?></td>
                            <td><?= ucfirst(htmlspecialchars($c['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <script>
            // Auto-trigger print dialog on page load
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
