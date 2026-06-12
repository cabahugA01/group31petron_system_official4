<?php
/**
 * Admin Reports - Quick Test & Validation Script
 * Run this to verify all report files and API endpoints are accessible
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// Simulate admin user for testing
$_SESSION['user_id'] = 1; // Change to your admin user ID
$_SESSION['role'] = 'admin';
$_SESSION['station_id'] = 1; // Change to your station ID

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports - Test Suite</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #003366 0%, #004d99 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .test-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .test-section h2 {
            color: #003366;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #003366;
        }
        
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .test-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s;
        }
        
        .test-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .test-item.success {
            border-left: 4px solid #28a745;
            background: #f0fdf4;
        }
        
        .test-item.error {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
        }
        
        .test-item.pending {
            border-left: 4px solid #fd7e14;
            background: #fffbf0;
        }
        
        .test-item h3 {
            font-size: 16px;
            margin-bottom: 8px;
            color: #003366;
        }
        
        .test-item .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }
        
        .status.success {
            background: #28a745;
            color: white;
        }
        
        .status.error {
            background: #dc3545;
            color: white;
        }
        
        .status.pending {
            background: #fd7e14;
            color: white;
        }
        
        .test-item .file-path {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
            font-family: 'Courier New', monospace;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #003366;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .btn:hover {
            background: #002244;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn.success {
            background: #28a745;
        }
        
        .btn.success:hover {
            background: #218838;
        }
        
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .summary-card .number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .summary-card.success .number {
            color: #28a745;
        }
        
        .summary-card.error .number {
            color: #dc3545;
        }
        
        .summary-card.total .number {
            color: #003366;
        }
        
        .summary-card .label {
            font-size: 14px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-check-circle"></i> Admin Reports - Test Suite</h1>
            <p>Validation and testing for all 12 admin report sections</p>
        </div>
        
        <div class="summary">
            <div class="summary-card total">
                <div class="number" id="totalTests">0</div>
                <div class="label">Total Tests</div>
            </div>
            <div class="summary-card success">
                <div class="number" id="successTests">0</div>
                <div class="label">Passed</div>
            </div>
            <div class="summary-card error">
                <div class="number" id="failedTests">0</div>
                <div class="label">Failed</div>
            </div>
        </div>
        
        <!-- Report Files Test -->
        <div class="test-section">
            <h2><i class="fas fa-file-code"></i> Report Files Validation</h2>
            <p style="color: #666; margin-bottom: 15px;">Checking if all 12 report section files exist and are accessible</p>
            <div class="test-grid" id="reportFilesGrid">
                <!-- Will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- API Endpoints Test -->
        <div class="test-section">
            <h2><i class="fas fa-plug"></i> API Endpoints Validation</h2>
            <p style="color: #666; margin-bottom: 15px;">Testing all backend API endpoints for connectivity and response</p>
            <div class="test-grid" id="apiEndpointsGrid">
                <!-- Will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Actions -->
        <div class="test-section">
            <h2><i class="fas fa-rocket"></i> Actions</h2>
            <a href="admin_reports.php" class="btn success">
                <i class="fas fa-chart-bar"></i> Open Admin Reports
            </a>
            <button onclick="runAllTests()" class="btn">
                <i class="fas fa-sync"></i> Re-run Tests
            </button>
            <button onclick="testAPIEndpoints()" class="btn">
                <i class="fas fa-plug"></i> Test APIs
            </button>
        </div>
    </div>
    
    <script>
        const reportFiles = [
            { name: 'Shift Reports', file: 'admin_shift_reports.php' },
            { name: 'Daily Consolidation', file: 'admin_daily_consolidation.php' },
            { name: 'Fuel Inventory', file: 'admin_fuel_inventory.php' },
            { name: 'Merchandise Inventory', file: 'admin_merchandise_inventory.php' },
            { name: 'Job Orders', file: 'admin_job_orders.php' },
            { name: 'Payments', file: 'admin_payments.php' },
            { name: 'Customers', file: 'admin_customers.php' },
            { name: 'Suppliers', file: 'admin_suppliers.php' },
            { name: 'Financial', file: 'admin_financial.php' },
            { name: 'Activity Log', file: 'admin_activity_log.php' },
            { name: 'Audit Trail', file: 'admin_audit_trail.php' },
            { name: 'Calendar & Schedule', file: 'admin_calendar_schedule.php' }
        ];
        
        const apiEndpoints = [
            { name: 'Shift Reports', action: 'get_shift_reports' },
            { name: 'Daily Consolidation', action: 'get_daily_consolidation' },
            { name: 'Fuel Inventory', action: 'get_fuel_inventory' },
            { name: 'Merchandise Inventory', action: 'get_merchandise_inventory' },
            { name: 'Job Orders', action: 'get_job_orders' },
            { name: 'Payments', action: 'get_payments' },
            { name: 'Customers', action: 'get_customers' },
            { name: 'Suppliers', action: 'get_suppliers' },
            { name: 'Financial', action: 'get_financial' },
            { name: 'Activity Log', action: 'get_activity_log' },
            { name: 'Audit Trail', action: 'get_audit_trail' },
            { name: 'Calendar & Schedule', action: 'get_calendar_schedule' }
        ];
        
        let totalTests = 0;
        let successTests = 0;
        let failedTests = 0;
        
        function updateSummary() {
            document.getElementById('totalTests').textContent = totalTests;
            document.getElementById('successTests').textContent = successTests;
            document.getElementById('failedTests').textContent = failedTests;
        }
        
        function testReportFiles() {
            const grid = document.getElementById('reportFilesGrid');
            grid.innerHTML = '';
            
            reportFiles.forEach(report => {
                totalTests++;
                
                const item = document.createElement('div');
                item.className = 'test-item pending';
                item.innerHTML = `
                    <h3><i class="fas fa-file-alt"></i> ${report.name}</h3>
                    <div class="file-path">reports/${report.file}</div>
                    <span class="status pending">Testing...</span>
                `;
                grid.appendChild(item);
                
                // Test file existence
                fetch(`reports/${report.file}`, { method: 'HEAD' })
                    .then(response => {
                        if (response.ok) {
                            item.className = 'test-item success';
                            item.querySelector('.status').className = 'status success';
                            item.querySelector('.status').textContent = '✓ File Exists';
                            successTests++;
                        } else {
                            item.className = 'test-item error';
                            item.querySelector('.status').className = 'status error';
                            item.querySelector('.status').textContent = '✗ Not Found';
                            failedTests++;
                        }
                        updateSummary();
                    })
                    .catch(() => {
                        item.className = 'test-item error';
                        item.querySelector('.status').className = 'status error';
                        item.querySelector('.status').textContent = '✗ Error';
                        failedTests++;
                        updateSummary();
                    });
            });
        }
        
        function testAPIEndpoints() {
            const grid = document.getElementById('apiEndpointsGrid');
            grid.innerHTML = '';
            
            const today = new Date().toISOString().split('T')[0];
            
            apiEndpoints.forEach(endpoint => {
                totalTests++;
                
                const item = document.createElement('div');
                item.className = 'test-item pending';
                item.innerHTML = `
                    <h3><i class="fas fa-plug"></i> ${endpoint.name}</h3>
                    <div class="file-path">action=${endpoint.action}</div>
                    <span class="status pending">Testing...</span>
                `;
                grid.appendChild(item);
                
                // Test API endpoint
                fetch(`../backend/api/admin_reports_api.php?action=${endpoint.action}&date_start=${today}&date_end=${today}`)
                    .then(response => response.json())
                    .then(result => {
                        if (result.success !== undefined) {
                            item.className = 'test-item success';
                            item.querySelector('.status').className = 'status success';
                            item.querySelector('.status').textContent = '✓ API Working';
                            successTests++;
                        } else {
                            item.className = 'test-item error';
                            item.querySelector('.status').className = 'status error';
                            item.querySelector('.status').textContent = '✗ Invalid Response';
                            failedTests++;
                        }
                        updateSummary();
                    })
                    .catch(error => {
                        item.className = 'test-item error';
                        item.querySelector('.status').className = 'status error';
                        item.querySelector('.status').textContent = '✗ Connection Error';
                        failedTests++;
                        updateSummary();
                    });
            });
        }
        
        function runAllTests() {
            totalTests = 0;
            successTests = 0;
            failedTests = 0;
            updateSummary();
            
            testReportFiles();
            setTimeout(testAPIEndpoints, 1000);
        }
        
        // Auto-run tests on page load
        window.addEventListener('DOMContentLoaded', () => {
            runAllTests();
        });
    </script>
</body>
</html>
