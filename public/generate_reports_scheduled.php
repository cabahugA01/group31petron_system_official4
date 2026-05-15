<?php
/**
 * AUTOMATED REPORT GENERATION SYSTEM
 * Run this file via cron job or background task
 * Corrected for actual database schema
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Allow command line execution
if (php_sapi_name() !== 'cli' && !isset($_GET['key'])) {
    die("This script can only be run via CLI or with valid API key.\n");
}

$api_key = $_GET['key'] ?? null;
$valid_key = 'petron_secure_key_2026';

if ($_GET['key'] ?? null) {
    if ($api_key !== $valid_key) {
        die("Invalid API key.\n");
    }
}

$report_type = $argv[1] ?? $_GET['type'] ?? 'daily';
$date = date('Y-m-d');
$timestamp = date('Y-m-d H:i:s');

echo "[" . date('Y-m-d H:i:s') . "] Starting automated report generation: $report_type\n";

try {
    // Create reports_cache table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_type VARCHAR(50) NOT NULL,
        station_id INT NULL,
        report_date DATE NOT NULL,
        report_time TIME NOT NULL,
        data LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME,
        KEY idx_type_date (report_type, report_date),
        KEY idx_station_date (station_id, report_date)
    )");

    // ====================================
    // DAILY REPORTS GENERATION
    // ====================================
    if ($report_type === 'daily' || $report_type === 'all') {
        echo "[" . date('Y-m-d H:i:s') . "] Generating daily reports...\n";
        
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active'");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stations as $station) {
            $sales_data = [];
            $fuel_data = [];
            $jobs_data = [];
            $credit_data = [];
            
            // Sales Report
            try {
                $sql = "SELECT COUNT(*) as transactions, COALESCE(SUM(total), 0) as total_sales
                        FROM sales WHERE station_id = ? AND sale_date = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$station['id'], $date]);
                $sales_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                echo "    Note: Sales data unavailable\n";
            }
            
            // Fuel Report
            try {
                $sql = "SELECT p.name as fuel_type, COALESCE(i.stock_level, 0) as stock_level
                        FROM station_inventory i
                        JOIN products p ON i.product_id = p.id
                        JOIN product_types pt ON p.type_id = pt.id
                        WHERE i.station_id = ? AND pt.name = 'fuel'";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$station['id']]);
                $fuel_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                echo "    Note: Fuel data unavailable\n";
            }
            
            // Job Orders Report
            try {
                $sql = "SELECT status, COUNT(*) as count, COALESCE(SUM(total_cost), 0) as total_value
                        FROM job_orders WHERE station_id = ? AND DATE(created_at) = ?
                        GROUP BY status";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$station['id'], $date]);
                $jobs_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                echo "    Note: Job order data unavailable\n";
            }
            
            // Customer Credit Report
            try {
                $sql = "SELECT COUNT(DISTINCT customer_id) as total_customers,
                        COALESCE(SUM(CASE WHEN type = 'Debit' THEN amount ELSE 0 END), 0) as total_charges,
                        COALESCE(SUM(CASE WHEN type = 'Credit' THEN amount ELSE 0 END), 0) as total_payments
                        FROM customer_ledger WHERE DATE(date) = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$date]);
                $credit_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                echo "    Note: Customer credit data unavailable\n";
            }
            
            $report_data = [
                'station_id' => $station['id'],
                'station_name' => $station['name'],
                'report_date' => $date,
                'generated_at' => $timestamp,
                'sales' => $sales_data,
                'fuel' => $fuel_data,
                'job_orders' => $jobs_data,
                'customer_credit' => $credit_data
            ];
            
            // Cache the report
            try {
                $sql = "REPLACE INTO reports_cache (report_type, station_id, report_date, report_time, data, expires_at)
                        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'daily',
                    $station['id'],
                    $date,
                    date('H:i:s'),
                    json_encode($report_data)
                ]);
                
                echo "  ✓ Daily report cached for Station #" . $station['id'] . " (" . $station['name'] . ")\n";
            } catch (Exception $e) {
                echo "  ✗ Error caching report: " . $e->getMessage() . "\n";
            }
        }
    }

    // ====================================
    // AM SHIFT REPORT
    // ====================================
    if ($report_type === 'shift_am' || $report_type === 'all') {
        echo "[" . date('Y-m-d H:i:s') . "] Generating AM shift reports...\n";
        
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active'");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stations as $station) {
            try {
                $sql = "SELECT COUNT(*) as transactions, COALESCE(SUM(total), 0) as total_sales
                        FROM sales
                        WHERE station_id = ? AND sale_date = ? 
                        AND HOUR(created_at) >= 6 AND HOUR(created_at) < 14";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$station['id'], $date]);
                $am_sales = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $report_data = [
                    'station_id' => $station['id'],
                    'station_name' => $station['name'],
                    'shift' => 'AM',
                    'shift_time' => '6:00 - 13:59',
                    'report_date' => $date,
                    'generated_at' => $timestamp,
                    'transactions' => $am_sales['transactions'] ?? 0,
                    'total_sales' => $am_sales['total_sales'] ?? 0
                ];
                
                $sql = "REPLACE INTO reports_cache (report_type, station_id, report_date, report_time, data, expires_at)
                        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'shift_am',
                    $station['id'],
                    $date,
                    '14:00:00',
                    json_encode($report_data)
                ]);
                
                echo "  ✓ AM shift report cached for Station #" . $station['id'] . "\n";
            } catch (Exception $e) {
                echo "  ✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }

    // ====================================
    // PM SHIFT REPORT
    // ====================================
    if ($report_type === 'shift_pm' || $report_type === 'all') {
        echo "[" . date('Y-m-d H:i:s') . "] Generating PM shift reports...\n";
        
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active'");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stations as $station) {
            try {
                $sql = "SELECT COUNT(*) as transactions, COALESCE(SUM(total), 0) as total_sales
                        FROM sales
                        WHERE station_id = ? AND sale_date = ? 
                        AND HOUR(created_at) >= 14 AND HOUR(created_at) < 22";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$station['id'], $date]);
                $pm_sales = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $report_data = [
                    'station_id' => $station['id'],
                    'station_name' => $station['name'],
                    'shift' => 'PM',
                    'shift_time' => '14:00 - 21:59',
                    'report_date' => $date,
                    'generated_at' => $timestamp,
                    'transactions' => $pm_sales['transactions'] ?? 0,
                    'total_sales' => $pm_sales['total_sales'] ?? 0
                ];
                
                $sql = "REPLACE INTO reports_cache (report_type, station_id, report_date, report_time, data, expires_at)
                        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'shift_pm',
                    $station['id'],
                    $date,
                    '22:00:00',
                    json_encode($report_data)
                ]);
                
                echo "  ✓ PM shift report cached for Station #" . $station['id'] . "\n";
            } catch (Exception $e) {
                echo "  ✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }

    // Log the generation
    try {
        $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address) 
                VALUES (0, 'Automated Report Generation', ?, 'SYSTEM')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["Generated $report_type reports on $timestamp"]);
    } catch (Exception $e) {
        // Silently fail if logging not available
    }

    echo "[" . date('Y-m-d H:i:s') . "] ✅ Report generation completed successfully!\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>
