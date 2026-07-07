<?php
/**
 * INSTALLATION TEST PAGE
 * Verify that Job Orders Report is properly installed
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Orders Report - Installation Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        h1 {
            color: #667eea;
            margin-bottom: 30px;
            text-align: center;
        }
        .test-item {
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .test-item.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .test-item.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .icon {
            font-size: 24px;
        }
        .success .icon { color: #28a745; }
        .error .icon { color: #dc3545; }
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin: 20px auto;
            text-align: center;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-container {
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Installation Verification</h1>
        
        <?php
        $main_file = __DIR__ . '/staff_job_orders_report.php';
        $backend_file = __DIR__ . '/../backend/export_job_orders_report.php';
        $sidebar_file = __DIR__ . '/../includes/staff_sidebar.php';
        
        $tests = [];
        
        // Test 1: Main report file exists
        $tests[] = [
            'name' => 'Main Report File',
            'pass' => file_exists($main_file),
            'message' => file_exists($main_file) 
                ? 'staff_job_orders_report.php exists (' . number_format(filesize($main_file)) . ' bytes)'
                : 'Main report file not found'
        ];
        
        // Test 2: Backend export file exists
        $tests[] = [
            'name' => 'Backend Export File',
            'pass' => file_exists($backend_file),
            'message' => file_exists($backend_file)
                ? 'export_job_orders_report.php exists'
                : 'Backend export file not found'
        ];
        
        // Test 3: Sidebar navigation updated
        $sidebar_content = file_exists($sidebar_file) ? file_get_contents($sidebar_file) : '';
        $has_link = strpos($sidebar_content, 'staff_job_orders_report.php') !== false;
        
        $tests[] = [
            'name' => 'Navigation Link',
            'pass' => $has_link,
            'message' => $has_link
                ? 'Sidebar menu updated with correct link'
                : 'Sidebar link not found'
        ];
        
        // Test 4: File is readable
        $tests[] = [
            'name' => 'File Permissions',
            'pass' => is_readable($main_file),
            'message' => is_readable($main_file)
                ? 'File is readable by web server'
                : 'File permission issue detected'
        ];
        
        // Display results
        $all_passed = true;
        foreach ($tests as $test) {
            $class = $test['pass'] ? 'success' : 'error';
            $icon = $test['pass'] ? '✓' : '✗';
            if (!$test['pass']) $all_passed = false;
            
            echo "<div class='test-item $class'>";
            echo "<span class='icon'>$icon</span>";
            echo "<div>";
            echo "<strong>{$test['name']}</strong><br>";
            echo "<small>{$test['message']}</small>";
            echo "</div>";
            echo "</div>";
        }
        ?>
        
        <?php if ($all_passed): ?>
        <div class="btn-container">
            <a href="staff_job_orders_report.php" class="btn">
                🚀 Open Job Orders Report
            </a>
        </div>
        <p style="text-align: center; margin-top: 20px; color: #28a745; font-weight: bold;">
            ✅ ALL TESTS PASSED - System is Ready!
        </p>
        <?php else: ?>
        <p style="text-align: center; margin-top: 20px; color: #dc3545; font-weight: bold;">
            ⚠️ Some tests failed - Please check installation
        </p>
        <?php endif; ?>
        
        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
        
        <h3 style="color: #333; margin-bottom: 15px;">Installation Summary</h3>
        <ul style="color: #666; line-height: 1.8;">
            <li>📁 Main Report: <code>public/staff_job_orders_report.php</code></li>
            <li>🔧 Backend API: <code>backend/export_job_orders_report.php</code></li>
            <li>🗂️ Navigation: <code>includes/staff_sidebar.php</code></li>
            <li>📖 Documentation: <code>docs/JOB_ORDERS_REPORT_README.md</code></li>
        </ul>
        
        <p style="margin-top: 20px; font-size: 14px; color: #666; text-align: center;">
            Access via: <strong>Reports → Job Orders Reports</strong>
        </p>
    </div>
</body>
</html>
