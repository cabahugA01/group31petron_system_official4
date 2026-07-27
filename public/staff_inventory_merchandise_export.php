<?php
/**
 * STAFF MERCHANDISE INVENTORY PDF EXPORT
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
$user_role = $me['role'] ?? 'Staff';

// Fetch Merchandise Inventory
$merch_inventory = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.name AS name,
               COALESCE(pc.name, 'General') AS category_name,
               p.description,
               COALESCE(si.price, p.price, si.cost, p.cost, 0) AS price,
               COALESCE(NULLIF(p.sku, ''), CONCAT('P', LPAD(p.id, 4, '0'))) AS sku,
               COALESCE(NULLIF(si.status, ''), NULLIF(p.status, ''), 'active') AS status,
               COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), 'pcs') AS unit,
               COALESCE(si.stock_level, p.current_stock, 0) AS stock_level,
               COALESCE(NULLIF(si.capacity, 0), NULLIF(p.capacity, 0), NULLIF(p.max_stock_level, 0), 480) AS capacity,
               COALESCE(NULLIF(si.reorder_level, 0), NULLIF(p.min_stock_level, 0), 10) AS reorder_level,
               si.last_updated AS last_updated
        FROM products p
        LEFT JOIN product_categories pc
               ON pc.id = p.category_id
        LEFT JOIN station_inventory si
               ON si.product_id = p.id AND si.station_id = ?
        WHERE LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
        ORDER BY COALESCE(pc.name, 'General'), p.name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("
            SELECT ip.id,
                   ip.product_name AS name,
                   ip.category     AS category_name,
                   ip.unit_price   AS price,
                   COALESCE(NULLIF(ip.sku, ''), CONCAT('P', LPAD(ip.id, 4, '0'))) AS sku,
                   ip.status,
                   COALESCE(si.unit, 'pcs')     AS unit,
                   COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
                   COALESCE(si.capacity, 0)              AS capacity,
                   COALESCE(si.reorder_level, 10)        AS reorder_level,
                   si.last_updated AS last_updated
            FROM inventory_products ip
            LEFT JOIN station_inventory si
                   ON si.product_id = ip.id AND si.station_id = ?
            WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
            ORDER BY ip.category, ip.product_name
        ");
        $stmt->execute([$station_id]);
        $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $fallback_err) {
        die('Error loading merchandise: ' . $fallback_err->getMessage());
    }
}

// Calculate statistics
$stats = [
    'total' => 0,
    'available' => 0,
    'low_stock' => 0,
    'critical' => 0,
    'out_of_stock' => 0,
    'total_qty' => 0
];

$low_stock_items = [];
$critical_items = [];

foreach ($merch_inventory as &$item) {
    $item['unit'] = format_merch_unit($item['unit'] ?? 'pcs');
    $stock = (float)($item['stock_level'] ?? 0);
    $reorder = (float)($item['reorder_level'] ?? 10);
    
    $stats['total']++;
    $stats['total_qty'] += $stock;
    
    if ($stock <= 0) {
        $item['status_label'] = 'OUT OF STOCK';
        $stats['out_of_stock']++;
    } elseif ($stock <= ($reorder * 0.5)) {
        $item['status_label'] = 'CRITICAL';
        $stats['critical']++;
        $critical_items[] = $item;
    } elseif ($stock <= $reorder) {
        $item['status_label'] = 'LOW STOCK';
        $stats['low_stock']++;
        $low_stock_items[] = $item;
    } else {
        $item['status_label'] = 'AVAILABLE';
        $stats['available']++;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Merchandise Inventory Report - <?= date('Y-m-d') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            font-family: Arial, sans-serif;
            color: #000;
            font-size: 10px;
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
            font-size: 15px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        .report-header h2 {
            font-size: 13px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .report-header .address {
            font-size: 9px;
            color: #555;
            margin-bottom: 10px;
        }
        
        /* Report Info Grid */
        .report-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            margin-bottom: 14px;
            font-size: 9px;
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
            min-width: 95px;
            color: #333;
        }
        .info-value {
            color: #000;
        }
        
        /* Inventory Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 8px;
        }
        thead {
            background: #002F70;
        }
        th {
            color: white;
            padding: 7px 5px;
            text-align: left;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            border: 1px solid #001f4d;
        }
        th.center {
            text-align: center;
        }
        td {
            padding: 5px 5px;
            border: 1px solid #cbd5e1;
            color: #000;
            font-size: 8px;
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
        
        /* Status badges */
        .status-ok { color: #059669; font-weight: bold; }
        .status-low { color: #d97706; font-weight: bold; }
        .status-critical { color: #dc2626; font-weight: bold; }
        .status-out { color: #dc2626; font-weight: bold; }
        
        /* Summary Section */
        .summary-section {
            border: 2px solid #002F70;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .summary-section h3 {
            font-size: 11px;
            font-weight: bold;
            color: #002F70;
            margin-bottom: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 5px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            font-size: 9px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
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
        
        /* Low Stock Section */
        .alert-section {
            margin-top: 20px;
            page-break-before: always;
        }
        .alert-section h3 {
            font-size: 11px;
            font-weight: bold;
            color: #dc2626;
            margin-bottom: 8px;
            text-transform: uppercase;
            padding: 8px;
            background: #fee2e2;
            border-left: 4px solid #dc2626;
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
                margin: 0.4in;
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
            
            table {
                font-size: 7px !important;
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
                padding: 4px 3px !important;
                font-size: 7px !important;
            }
            
            .alert-section {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <!-- No Print Buttons -->
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">Print</button>
        <button class="btn-close" onclick="window.close()">✖ Close Window</button>
    </div>
    
    <!-- Report Header -->
    <div class="report-header">
        <h1>PETRON STATION & SERVICE CENTER</h1>
        <div class="address"><?= htmlspecialchars($station_address) ?></div>
        <h2>MERCHANDISE INVENTORY REPORT</h2>
    </div>
    
    <!-- Report Information -->
    <div class="report-info">
        <div class="info-item">
            <span class="info-label">Generated By:</span>
            <span class="info-value"><?= htmlspecialchars($generated_by) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Generated On:</span>
            <span class="info-value"><?= date('F d, Y h:i A') ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Role:</span>
            <span class="info-value"><?= htmlspecialchars($user_role) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Station:</span>
            <span class="info-value"><?= htmlspecialchars($station_name) ?></span>
        </div>
    </div>
    
    <!-- Inventory Summary -->
    <div class="summary-section">
        <h3>Inventory Summary</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-label">Total Products</span>
                <span class="summary-value"><?= number_format($stats['total']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Available</span>
                <span class="summary-value" style="color: #059669;"><?= number_format($stats['available']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Total Inventory Quantity</span>
                <span class="summary-value"><?= number_format($stats['total_qty']) ?> pcs</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Low Stock</span>
                <span class="summary-value" style="color: #d97706;"><?= number_format($stats['low_stock']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Critical</span>
                <span class="summary-value" style="color: #dc2626;"><?= number_format($stats['critical']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Out of Stock</span>
                <span class="summary-value" style="color: #dc2626;"><?= number_format($stats['out_of_stock']) ?></span>
            </div>
        </div>
    </div>
    
    <!-- Inventory Table -->
    <table>
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product</th>
                <th>Category</th>
                <th>UOM</th>
                <th class="center">Current Stock</th>
                <th class="center">Reorder Level</th>
                <th class="center">Status</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($merch_inventory)): ?>
                <tr><td colspan="8" style="text-align: center; color: #888; padding: 20px;">No merchandise inventory found.</td></tr>
            <?php else: ?>
                <?php foreach ($merch_inventory as $item): 
                    $status_class = '';
                    if ($item['status_label'] === 'AVAILABLE') $status_class = 'status-ok';
                    elseif ($item['status_label'] === 'LOW STOCK') $status_class = 'status-low';
                    elseif ($item['status_label'] === 'CRITICAL') $status_class = 'status-critical';
                    else $status_class = 'status-out';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td><?= htmlspecialchars($item['category_name'] ?? 'Merchandise') ?></td>
                        <td class="center"><?= htmlspecialchars($item['unit']) ?></td>
                        <td class="center"><strong><?= number_format($item['stock_level']) ?></strong></td>
                        <td class="center"><?= number_format($item['reorder_level']) ?></td>
                        <td class="center <?= $status_class ?>"><?= $item['status_label'] ?></td>
                        <td><?= $item['last_updated'] ? date('M d, Y h:i A', strtotime($item['last_updated'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Low Stock Items Section -->
    <?php if (!empty($low_stock_items)): ?>
    <div class="alert-section">
        <h3>⚠ LOW STOCK ITEMS</h3>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="center">Current Stock</th>
                    <th class="center">Reorder Level</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td><?= htmlspecialchars($item['category_name'] ?? 'Merchandise') ?></td>
                        <td class="center status-low"><strong><?= number_format($item['stock_level']) ?></strong></td>
                        <td class="center"><?= number_format($item['reorder_level']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Critical Items Section -->
    <?php if (!empty($critical_items)): ?>
    <div class="alert-section">
        <h3>🚨 CRITICAL ITEMS</h3>
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th class="center">Remaining Qty</th>
                    <th class="center">Reorder Level</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($critical_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td class="center status-critical"><strong><?= number_format($item['stock_level']) ?></strong></td>
                        <td class="center"><?= number_format($item['reorder_level']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
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
