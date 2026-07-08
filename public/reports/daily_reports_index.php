<?php
/**
 * DAILY REPORTS INDEX PAGE
 * Landing page for all daily report types
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: ../dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

$report_date = trim($_GET['report_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = date('Y-m-d');

$page_id = 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reports - <?= htmlspecialchars($station_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #00264D 0%, #003366 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        
        .header h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .date-selector {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .date-selector label {
            font-weight: 600;
            color: #00264D;
        }
        
        .date-selector input[type="date"] {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            flex: 1;
            min-width: 200px;
        }
        
        .date-selector button {
            padding: 10px 24px;
            background: #CC0000;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .date-selector button:hover {
            background: #a00000;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(204, 0, 0, 0.3);
        }
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border-left: 5px solid #CC0000;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }
        
        .report-card-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #CC0000, #ff3333);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            margin-bottom: 20px;
        }
        
        .report-card-title {
            font-size: 20px;
            font-weight: 700;
            color: #00264D;
            margin-bottom: 10px;
        }
        
        .report-card-description {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .report-card-time {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #999;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        
        .report-card-time i {
            color: #CC0000;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: white;
            color: #00264D;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .back-button:hover {
            background: #f8f9fa;
            transform: translateX(-5px);
        }
        
        .section-title {
            color: white;
            font-size: 24px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .reports-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 28px;
            }
            
            .date-selector {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../staff_fuel_sales_summary.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
        
        <div class="header">
            <h1><i class="fas fa-calendar-day"></i> Daily Reports</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
        </div>
        
        <div class="date-selector">
            <label for="report_date"><i class="fas fa-calendar"></i> Select Date:</label>
            <input type="date" id="report_date" value="<?= htmlspecialchars($report_date) ?>" max="<?= date('Y-m-d') ?>">
            <button onclick="loadReports()">
                <i class="fas fa-search"></i> Load Reports
            </button>
            <button onclick="document.getElementById('report_date').value='<?= date('Y-m-d') ?>'; loadReports();">
                <i class="fas fa-calendar-day"></i> Today
            </button>
        </div>
        
        <h2 class="section-title"><i class="fas fa-gas-pump"></i> Fuel Sales Reports</h2>
        <div class="reports-grid">
            <a href="staff_shift_fuel_report.php?shift=shift1&report_date=<?= urlencode($report_date) ?>" class="report-card">
                <div class="report-card-icon">
                    <i class="fas fa-sun"></i>
                </div>
                <div class="report-card-title">Shift 1 Fuel Sales Report</div>
                <div class="report-card-description">
                    Complete fuel sales analysis for the morning shift including meter readings, 
                    volume sales, payment breakdown, and inventory movement.
                </div>
                <div class="report-card-time">
                    <i class="fas fa-clock"></i>
                    <span>6:00 AM – 2:00 PM</span>
                </div>
            </a>
            
            <a href="staff_shift_fuel_report.php?shift=shift2&report_date=<?= urlencode($report_date) ?>" class="report-card">
                <div class="report-card-icon">
                    <i class="fas fa-moon"></i>
                </div>
                <div class="report-card-title">Shift 2 Fuel Sales Report</div>
                <div class="report-card-description">
                    Complete fuel sales analysis for the afternoon shift including meter readings, 
                    volume sales, payment breakdown, and inventory movement.
                </div>
                <div class="report-card-time">
                    <i class="fas fa-clock"></i>
                    <span>2:00 PM – 10:00 PM</span>
                </div>
            </a>
            
            <a href="staff_shift_fuel_report.php?shift=24hour&report_date=<?= urlencode($report_date) ?>" class="report-card">
                <div class="report-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="report-card-title">24-Hour Fuel Summary</div>
                <div class="report-card-description">
                    Consolidated fuel sales report combining all shifts for the entire day. 
                    Includes total volume, revenue, and comprehensive payment analysis.
                </div>
                <div class="report-card-time">
                    <i class="fas fa-clock"></i>
                    <span>Full Day Summary</span>
                </div>
            </a>
        </div>
        
        <h2 class="section-title"><i class="fas fa-shopping-cart"></i> Merchandise & Services</h2>
        <div class="reports-grid">
            <a href="staff_daily_merchandise_service_report.php?report_date=<?= urlencode($report_date) ?>" class="report-card">
                <div class="report-card-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="report-card-title">Daily Merchandise & Service Sales</div>
                <div class="report-card-description">
                    Complete breakdown of all merchandise sales and service income for the day. 
                    Includes product categories, quantities, pricing, and payment methods.
                </div>
                <div class="report-card-time">
                    <i class="fas fa-clock"></i>
                    <span>All Day Transactions</span>
                </div>
            </a>
        </div>
    </div>
    
    <script>
        function loadReports() {
            const date = document.getElementById('report_date').value;
            if (date) {
                window.location.href = '?report_date=' + encodeURIComponent(date);
            }
        }
        
        // Allow Enter key to load reports
        document.getElementById('report_date').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loadReports();
            }
        });
    </script>
</body>
</html>
