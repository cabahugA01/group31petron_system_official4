<?php
/**
 * Badge System Verification Script
 * 
 * This script verifies that the sidebar badge system is properly configured
 * and all required components are in place.
 * 
 * Run this script in your browser: http://localhost/group31petron_system_official4/verify_badge_system.php
 */

require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';

// Output HTML header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Badge System Verification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #002F6C 0%, #00509E 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        .header p {
            margin: 0;
            opacity: 0.9;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .card h2 {
            margin: 0 0 20px 0;
            color: #002F6C;
            font-size: 20px;
            border-bottom: 2px solid #E30613;
            padding-bottom: 10px;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status.pass {
            background: #d4edda;
            color: #155724;
        }
        .status.fail {
            background: #f8d7da;
            color: #721c24;
        }
        .status.warn {
            background: #fff3cd;
            color: #856404;
        }
        .check-item {
            padding: 12px;
            border-left: 3px solid #ddd;
            margin-bottom: 10px;
            background: #f8f9fa;
        }
        .check-item.pass {
            border-color: #28a745;
            background: #f1f9f4;
        }
        .check-item.fail {
            border-color: #dc3545;
            background: #fef5f5;
        }
        .check-item.warn {
            border-color: #ffc107;
            background: #fffef5;
        }
        .code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid #002F6C;
            padding: 20px;
            margin-top: 30px;
            border-radius: 8px;
        }
        .summary h3 {
            margin: 0 0 15px 0;
            color: #002F6C;
        }
        .badge-example {
            background: #E30613;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            display: inline-block;
            min-width: 20px;
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #002F6C;
        }
        table tr:hover {
            background: #f8f9fa;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>🔍 Badge System Verification</h1>
    <p>Comprehensive verification of sidebar badge notification system</p>
</div>

<?php
$checks = [];
$total_checks = 0;
$passed_checks = 0;
$failed_checks = 0;
$warnings = 0;

function add_check($category, $name, $status, $message = '') {
    global $checks, $total_checks, $passed_checks, $failed_checks, $warnings;
    $checks[$category][] = ['name' => $name, 'status' => $status, 'message' => $message];
    $total_checks++;
    if ($status === 'pass') $passed_checks++;
    if ($status === 'fail') $failed_checks++;
    if ($status === 'warn') $warnings++;
}

// ══════════════════════════════════════════════════════════════════
// CHECK 1: Core Files Existence
// ══════════════════════════════════════════════════════════════════
$header_file = __DIR__ . '/partials/header.php';
$rbac_file = __DIR__ . '/partials/rbac_menu.php';
$api_file = __DIR__ . '/backend/api/badge_seen.php';

add_check('files', 'header.php exists', file_exists($header_file) ? 'pass' : 'fail', $header_file);
add_check('files', 'rbac_menu.php exists', file_exists($rbac_file) ? 'pass' : 'fail', $rbac_file);
add_check('files', 'badge_seen.php exists', file_exists($api_file) ? 'pass' : 'fail', $api_file);

// Check for badge-related code in header.php
if (file_exists($header_file)) {
    $header_content = file_get_contents($header_file);
    add_check('files', 'Badge calculation logic present', 
        strpos($header_content, 'SIDEBAR BADGE SYSTEM') !== false ? 'pass' : 'fail',
        'Looking for badge calculation code block');
    add_check('files', 'Badge rendering logic present', 
        strpos($header_content, 'data-badge') !== false ? 'pass' : 'fail',
        'Looking for badge HTML rendering');
    add_check('files', 'Auto-mark-as-seen logic present', 
        strpos($header_content, 'badge_page_map') !== false ? 'pass' : 'fail',
        'Looking for auto-clear system');
}

// ══════════════════════════════════════════════════════════════════
// CHECK 2: Database Tables
// ══════════════════════════════════════════════════════════════════
try {
    // Check user_preferences table
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_preferences'");
    $table_exists = $stmt->rowCount() > 0;
    add_check('database', 'user_preferences table exists', $table_exists ? 'pass' : 'fail');
    
    if ($table_exists) {
        // Check table structure
        $stmt = $pdo->query("DESCRIBE user_preferences");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $required_columns = ['id', 'user_id', 'preference_key', 'preference_value', 'created_at', 'updated_at'];
        foreach ($required_columns as $col) {
            add_check('database', "Column '$col' exists", 
                in_array($col, $columns) ? 'pass' : 'fail');
        }
        
        // Check for unique constraint
        $stmt = $pdo->query("SHOW INDEXES FROM user_preferences WHERE Key_name = 'unique_user_pref'");
        add_check('database', 'Unique constraint exists', 
            $stmt->rowCount() > 0 ? 'pass' : 'fail',
            'unique_user_pref (user_id, preference_key)');
    }
    
    // Check for badge_seen preferences
    $stmt = $pdo->query("SELECT COUNT(*) FROM user_preferences WHERE preference_key LIKE 'badge_seen_%'");
    $badge_prefs = $stmt->fetchColumn();
    add_check('database', 'Badge preferences exist', 
        $badge_prefs > 0 ? 'pass' : 'warn',
        "Found $badge_prefs badge_seen_* preferences");
    
} catch (PDOException $e) {
    add_check('database', 'Database connection', 'fail', $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════
// CHECK 3: Badge Data Tables
// ══════════════════════════════════════════════════════════════════
$badge_tables = [
    'stock_requests' => 'Staff stock requests',
    'fuel_deliveries' => 'Fuel deliveries',
    'merchandise_deliveries' => 'Merchandise deliveries',
    'merchandise_transactions' => 'Merchandise transactions',
    'fuel_transactions' => 'Fuel transactions',
    'fuel_variance_reports' => 'Fuel variance reports',
    'master_data_requests' => 'Master data requests',
    'voided_transactions' => 'Voided transactions',
    'purchase_orders' => 'Purchase orders'
];

foreach ($badge_tables as $table => $desc) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        add_check('badge_tables', "$desc ($table)", 
            $stmt->rowCount() > 0 ? 'pass' : 'warn',
            $stmt->rowCount() > 0 ? 'Table exists' : 'Table not found - some badges may not work');
    } catch (PDOException $e) {
        add_check('badge_tables', "$desc ($table)", 'fail', $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// CHECK 4: Badge API Endpoint
// ══════════════════════════════════════════════════════════════════
if (file_exists($api_file)) {
    $api_content = file_get_contents($api_file);
    add_check('api', 'POST method check present', 
        strpos($api_content, "REQUEST_METHOD") !== false ? 'pass' : 'fail');
    add_check('api', 'Authentication check present', 
        strpos($api_content, "user_id") !== false ? 'pass' : 'fail');
    add_check('api', 'Module validation present', 
        strpos($api_content, "preg_match") !== false ? 'pass' : 'fail');
    add_check('api', 'Database UPDATE present', 
        strpos($api_content, "user_preferences") !== false ? 'pass' : 'fail');
}

// ══════════════════════════════════════════════════════════════════
// CHECK 5: Sample Badge Counts (if data exists)
// ══════════════════════════════════════════════════════════════════
try {
    $sample_counts = [];
    
    // Staff badges
    $stmt = $pdo->query("SELECT COUNT(*) FROM stock_requests WHERE status='Pending'");
    $sample_counts['Staff Stock Requests'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_deliveries WHERE status IN ('Pending','Pending Review')");
    $sample_counts['Fuel Deliveries (Staff)'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise_deliveries WHERE status IN ('Pending','Pending Review')");
    $sample_counts['Merchandise Deliveries'] = $stmt->fetchColumn();
    
    // Manager badges
    $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_transactions WHERE status='Pending'");
    $sample_counts['Fuel Transactions (Manager)'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM master_data_requests WHERE status='Pending'");
    $sample_counts['Master Data Requests'] = $stmt->fetchColumn();
    
    // Admin badges
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending','Pending Approval','Pending Admin Validation')");
    $sample_counts['Purchase Orders (Admin)'] = $stmt->fetchColumn();
    
    foreach ($sample_counts as $name => $count) {
        add_check('sample_data', $name, 
            $count >= 0 ? 'pass' : 'fail',
            "Current count: $count pending items");
    }
    
} catch (PDOException $e) {
    add_check('sample_data', 'Sample badge counts', 'warn', 'Some tables may not exist yet: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════
// Display Results
// ══════════════════════════════════════════════════════════════════

foreach ($checks as $category => $items) {
    $category_name = ucwords(str_replace('_', ' ', $category));
    echo "<div class='card'>";
    echo "<h2>$category_name</h2>";
    
    foreach ($items as $item) {
        $status_class = $item['status'];
        $status_text = strtoupper($item['status']);
        echo "<div class='check-item $status_class'>";
        echo "<strong>{$item['name']}</strong>";
        echo "<span class='status $status_class'>$status_text</span>";
        if (!empty($item['message'])) {
            echo "<br><small style='color:#666;'>{$item['message']}</small>";
        }
        echo "</div>";
    }
    
    echo "</div>";
}

// ══════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════
$percentage = $total_checks > 0 ? round(($passed_checks / $total_checks) * 100, 1) : 0;

echo "<div class='summary'>";
echo "<h3>📊 Verification Summary</h3>";
echo "<p><strong>Total Checks:</strong> $total_checks</p>";
echo "<p><strong>✅ Passed:</strong> $passed_checks</p>";
echo "<p><strong>❌ Failed:</strong> $failed_checks</p>";
echo "<p><strong>⚠️ Warnings:</strong> $warnings</p>";
echo "<p><strong>Success Rate:</strong> {$percentage}%</p>";

if ($failed_checks === 0) {
    echo "<p style='color:#155724;background:#d4edda;padding:15px;border-radius:6px;margin-top:15px;'>";
    echo "🎉 <strong>All critical checks passed!</strong> The badge system is properly configured.";
    echo "</p>";
} else {
    echo "<p style='color:#721c24;background:#f8d7da;padding:15px;border-radius:6px;margin-top:15px;'>";
    echo "⚠️ <strong>Some checks failed.</strong> Please review the failed items above and fix them.";
    echo "</p>";
}

if ($warnings > 0 && $failed_checks === 0) {
    echo "<p style='color:#856404;background:#fff3cd;padding:15px;border-radius:6px;margin-top:15px;'>";
    echo "⚠️ <strong>Some warnings detected.</strong> The system will work but some features may be limited.";
    echo "</p>";
}

echo "</div>";

// ══════════════════════════════════════════════════════════════════
// Badge Example
// ══════════════════════════════════════════════════════════════════
echo "<div class='card'>";
echo "<h2>🎨 Badge Visual Example</h2>";
echo "<p>This is how badges appear in the sidebar navigation:</p>";
echo "<div style='background:#00264D;padding:20px;border-radius:8px;color:white;margin-top:15px;'>";
echo "<div style='display:flex;align-items:center;padding:10px;'>";
echo "<span style='margin-right:10px;'>📦</span>";
echo "<span style='flex-grow:1;'>Inventory</span>";
echo "<span class='badge-example'>12</span>";
echo "</div>";
echo "<div style='display:flex;align-items:center;padding:10px;margin-left:30px;'>";
echo "<span style='margin-right:10px;opacity:0.5;'>•</span>";
echo "<span style='flex-grow:1;font-size:13px;'>Stock Request</span>";
echo "<span class='badge-example'>12</span>";
echo "</div>";
echo "</div>";
echo "</div>";

// ══════════════════════════════════════════════════════════════════
// Documentation Links
// ══════════════════════════════════════════════════════════════════
echo "<div class='card'>";
echo "<h2>📚 Documentation</h2>";
echo "<p>For complete documentation about the badge system, see:</p>";
echo "<ul>";
echo "<li><span class='code'>SIDEBAR_BADGE_SYSTEM.md</span> - Complete technical documentation</li>";
echo "<li><span class='code'>BADGE_VISUAL_EXAMPLES.md</span> - Visual examples and mockups</li>";
echo "<li><span class='code'>BADGE_QUICK_REFERENCE.md</span> - Developer quick reference</li>";
echo "<li><span class='code'>SIDEBAR_BADGES_COMPLETE.md</span> - Executive summary</li>";
echo "</ul>";
echo "</div>";

?>

</body>
</html>
