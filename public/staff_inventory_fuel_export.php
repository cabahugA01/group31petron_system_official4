<?php
/**
 * STAFF FUEL INVENTORY PDF EXPORT
 * Generates a printable PDF report with proper formatting
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'superadmin', 'developer'])) {
    die('Unauthorized access');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Get Station Info
$station_name = 'Petron Station';
$station_address = '';
try {
    $st = $pdo->prepare("SELECT name, address, location FROM stations WHERE id = ? LIMIT 1");
    $st->execute([$station_id]);
    $station = $st->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        $station_name = $station['name'] ?: $station_name;
        $station_address = $station['address'] ?: ($station['location'] ?: '');
    }
} catch (Exception $e) {}

$generated_by = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($generated_by === '') $generated_by = $me['username'] ?? 'Staff';

// Get Tank Configuration
$TANK_CONFIG_17 = get_tank_config();

// Fetch fuel_inventory
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// Build fuel inventory data
$fuel_data = [];
$stats = [
    'total_tanks' => 0,
    'normal_tanks' => 0,
    'low_tanks' => 0,
    'critical_tanks' => 0,
    'total_capacity' => 0,
    'current_volume' => 0
];

foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key = strtolower(trim($tc['fuel_type']));
    $fi = $fi_lookup[$ft_key] ?? null;
    
    $capacity = (float)($fi['capacity'] ?? $tc['capacity'] ?? 10000);
    $current_level = (float)($fi['current_level'] ?? $fi['current_stock'] ?? 0);
    $reorder_level = (float)($tc['reorder_level'] ?? ($capacity * 0.25));
    
    $avail_pct = $capacity > 0 ? ($current_level / $capacity) * 100 : 0;
    
    // Determine status
    if ($current_level <= 0) {
        $status = 'EMPTY';
        $status_class = 'status-critical';
        $stats['critical_tanks']++;
    } elseif ($current_level <= ($reorder_level * 0.5)) {
        $status = 'CRITICAL';
        $status_class = 'status-critical';
        $stats['critical_tanks']++;
    } elseif ($current_level <= $reorder_level) {
        $status = 'LOW';
        $status_class = 'status-low';
        $stats['low_tanks']++;
    } else {
        $status = 'NORMAL';
        $status_class = 'status-ok';
        $stats['normal_tanks']++;
    }
    
    $stats['total_tanks']++;
    $stats['total_capacity'] += $capacity;
    $stats['current_volume'] += $current_level;
    
    $fuel_data[] = [
        'ugt_no' => $tc['ugt_no'] ?? '—',
        'fuel_type' => $tc['fuel_type'],
        'capacity' => $capacity,
        'reorder_level' => $reorder_level,
        'current_level' => $current_level,
        'avail_pct' => $avail_pct,
        'status' => $status,
        'status_class' => $status_class,
        'last_updated' => $fi['last_updated'] ?? null
    ];
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fuel Inventory Report - <?= date('Y-m-d') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            font-family: Arial, sans-serif;
            color: #000;
            font-size: 11px;
            line-height: 1.3;
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
            margin-bottom: 14px;
        }
        .report-header h1 {
            font-size: 16px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 3px;
            text-transform: uppercase;
        }
        .report-header h2 {
            font-size: 13px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        .report-header .address {
            font-size: 10px;
            color: #555;
            margin-bottom: 8px;
        }
        
        /* Report Info Grid */
        .report-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            margin-bottom: 16px;
            font-size: 10px;
            border: 1px solid #cbd5e1;
            padding: 10px;
            background: #f8fafc;
            border-radius: 4px;
        }
        .info-item {
            display: flex;
        }
        .info-label {
            font-weight: bold;
            min-width: 110px;
            color: #333;
        }
        .info-value {
            color: #000;
        }
        
        /* Fuel Inventory Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
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
        th.center {
            text-align: center;
        }
        th.right {
            text-align: right;
        }
        td {
            padding: 6px 6px;
            border: 1px solid #cbd5e1;
            color: #000;
            font-size: 10px;
        }
        td.center {
            text-align: center;
        }
        td.right {
            text-align: right;
        }
        tbody tr:nth-child(even) {
            background: #f8fafc;
        }
        
        /* Status colors */
        .status-ok { color: #059669; font-weight: bold; }
        .status-low { color: #d97706; font-weight: bold; }
        .status-critical { color: #dc2626; font-weight: bold; }
        
        /* Footer Summary */
        .summary-section {
            border: 2px solid #002F70;
            border-radius: 6px;
            padding: 14px;
            margin-top: 20px;
            background: #f8fafc;
        }
        .summary-section h3 {
            font-size: 12px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 12px;
            text-transform: uppercase;
            border-bottom: 2px solid #002F70;
            padding-bottom: 6px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            font-size: 10px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dotted #cbd5e1;
        }
        .summary-label {
            font-weight: 600;
            color: #555;
        }
        .summary-value {
            font-weight: bold;
            color: #000;
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
        
        /* Print Styles */
        @media print {
            @page {
                margin: 0.5in;
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
            
            table {
                font-size: 9px !important;
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
        <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <button class="btn-close" onclick="window.close()">✖ Close Window</button>
    </div>
    
    <!-- Report Header -->
    <div class="report-header">
        <h1>PETRON STATION MANAGEMENT SYSTEM</h1>
        <h2><?= htmlspecialchars($station_name) ?></h2>
        <div class="address"><?= htmlspecialchars($station_address) ?></div>
        <h2>FUEL INVENTORY REPORT</h2>
    </div>
    
    <!-- Report Information -->
    <div class="report-info">
        <div class="info-item">
            <span class="info-label">Report Date:</span>
            <span class="info-value"><?= date('F d, Y') ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Generated By:</span>
            <span class="info-value"><?= htmlspecialchars($generated_by) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Generated Date & Time:</span>
            <span class="info-value"><?= date('F d, Y h:i A') ?></span>
        </div>
    </div>
    
    <!-- Fuel Inventory Table -->
    <table>
        <thead>
            <tr>
                <th>UGT No.</th>
                <th>Fuel Type</th>
                <th class="right">Capacity (L)</th>
                <th class="right">Reorder Level</th>
                <th class="right">Current Level</th>
                <th class="center">Available %</th>
                <th class="center">Status</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fuel_data)): ?>
                <tr><td colspan="8" style="text-align: center; color: #888; padding: 20px;">No fuel inventory data found.</td></tr>
            <?php else: ?>
                <?php foreach ($fuel_data as $item): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($item['ugt_no']) ?></strong></td>
                        <td><strong><?= htmlspecialchars($item['fuel_type']) ?></strong></td>
                        <td class="right"><?= number_format($item['capacity'], 2) ?></td>
                        <td class="right"><?= number_format($item['reorder_level'], 2) ?></td>
                        <td class="right"><strong><?= number_format($item['current_level'], 2) ?></strong></td>
                        <td class="center"><?= number_format($item['avail_pct'], 1) ?>%</td>
                        <td class="center <?= $item['status_class'] ?>"><?= $item['status'] ?></td>
                        <td><?= $item['last_updated'] ? date('M d, Y h:i A', strtotime($item['last_updated'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Footer Summary -->
    <div class="summary-section">
        <h3>Summary</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-label">Total UGT Tanks</span>
                <span class="summary-value"><?= $stats['total_tanks'] ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Normal Tanks</span>
                <span class="summary-value" style="color: #059669;"><?= $stats['normal_tanks'] ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Total Fuel Capacity</span>
                <span class="summary-value"><?= number_format($stats['total_capacity'], 0) ?> L</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Low Tanks</span>
                <span class="summary-value" style="color: #d97706;"><?= $stats['low_tanks'] ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Critical Tanks</span>
                <span class="summary-value" style="color: #dc2626;"><?= $stats['critical_tanks'] ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Current Fuel Volume</span>
                <span class="summary-value"><?= number_format($stats['current_volume'], 0) ?> L</span>
            </div>
        </div>
    </div>
    
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
