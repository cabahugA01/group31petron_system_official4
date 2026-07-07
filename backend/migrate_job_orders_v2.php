<?php
/**
 * Database Migration Script - Version 2
 * Applies Job Order Management enhancements
 * Fixed: Handles existing foreign key constraints
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Require admin privileges
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Please login first");
}

$user = current_user();
if (!in_array($user['role'], ['admin', 'superadmin'])) {
    die("Admin privileges required");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Job Order System Migration v2</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #002F6C; margin-bottom: 10px; }
        .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .btn { display: inline-block; padding: 12px 24px; background: #002F6C; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #004080; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Job Order Management System Migration v2</h1>
        <p><strong>Version 2:</strong> Fixed foreign key constraint issues</p>
        
        <?php
        try {
            echo "<div class='status info'>Starting migration...</div>";
            
            // Try new SQL file first (v2 with fixes), fallback to original
            $sql_file_v2 = __DIR__ . '/../sql/job_order_enhancements_v2.sql';
            $sql_file_v1 = __DIR__ . '/../sql/job_order_enhancements.sql';
            
            $sql_file = file_exists($sql_file_v2) ? $sql_file_v2 : $sql_file_v1;
            $version = file_exists($sql_file_v2) ? 'v2 (Fixed)' : 'v1 (Original)';
            
            echo "<div class='status info'>Using migration file: " . basename($sql_file) . " ($version)</div>";
            
            if (!file_exists($sql_file)) {
                throw new Exception("Migration SQL file not found at: $sql_file");
            }
            
            $sql = file_get_contents($sql_file);
            
            // Split into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
                }
            );
            
            echo "<div class='status info'>Found " . count($statements) . " SQL statements to execute</div>";
            
            $success_count = 0;
            $error_count = 0;
            $warning_count = 0;
            $errors = [];
            
            foreach ($statements as $index => $statement) {
                if (empty(trim($statement))) continue;
                
                try {
                    // Handle prepared statements differently
                    if (stripos($statement, 'PREPARE') !== false || 
                        stripos($statement, 'EXECUTE') !== false ||
                        stripos($statement, 'DEALLOCATE') !== false ||
                        stripos($statement, 'SET @') !== false) {
                        $pdo->exec($statement);
                        $success_count++;
                    } else {
                        $pdo->exec($statement);
                        $success_count++;
                    }
                    
                    // Show first line of each successful statement
                    $first_line = strtok($statement, "\n");
                    $preview = htmlspecialchars(substr($first_line, 0, 80));
                    echo "<div class='status success'>✓ Statement " . ($index + 1) . ": " . $preview . "...</div>";
                    
                } catch (PDOException $e) {
                    $error_msg = $e->getMessage();
                    
                    // Check if it's a "safe" error (already exists, duplicate, etc.)
                    $safe_errors = [
                        'Duplicate column',
                        'Duplicate key',
                        'already exists',
                        'Duplicate entry',
                        "Can't DROP",
                        'Unknown column',
                        'Duplicate index'
                    ];
                    
                    $is_safe_error = false;
                    foreach ($safe_errors as $safe_error) {
                        if (stripos($error_msg, $safe_error) !== false) {
                            $is_safe_error = true;
                            break;
                        }
                    }
                    
                    if ($is_safe_error) {
                        $warning_count++;
                        $preview = htmlspecialchars(substr(strtok($statement, "\n"), 0, 60));
                        echo "<div class='status warning'>⚠ Already exists: " . $preview . "...</div>";
                    } else {
                        $error_count++;
                        $errors[] = [
                            'statement' => $statement,
                            'error' => $error_msg
                        ];
                        $preview = htmlspecialchars(substr(strtok($statement, "\n"), 0, 60));
                        echo "<div class='status error'>✗ Error in statement " . ($index + 1) . ": " . $preview . "<br>";
                        echo "<small>" . htmlspecialchars($error_msg) . "</small></div>";
                    }
                }
            }
            
            echo "<hr style='margin: 20px 0;'>";
            
            if ($error_count > 0) {
                echo "<div class='status error'><strong>⚠️ Migration Completed with Errors</strong></div>";
            } else {
                echo "<div class='status success'><strong>✓ Migration Complete!</strong></div>";
            }
            
            echo "<div class='status info'>";
            echo "<strong>Summary:</strong><br>";
            echo "• Successful: " . $success_count . " statements<br>";
            echo "• Warnings: " . $warning_count . " statements (already exist)<br>";
            echo "• Errors: " . $error_count . " statements<br>";
            echo "• Total: " . count($statements) . " statements<br>";
            echo "</div>";
            
            if ($error_count > 0) {
                echo "<div class='status error'>";
                echo "<strong>Errors encountered:</strong><br>";
                foreach ($errors as $err) {
                    echo "<details style='margin: 10px 0;'>";
                    echo "<summary style='cursor: pointer;'>View error details</summary>";
                    echo "<pre>" . htmlspecialchars($err['error']) . "</pre>";
                    echo "<pre>" . htmlspecialchars($err['statement']) . "</pre>";
                    echo "</details>";
                }
                echo "</div>";
                
                echo "<div class='status warning'>";
                echo "<strong>💡 How to Fix:</strong><br>";
                echo "1. If foreign key error (errno: 121), run this SQL manually:<br>";
                echo "<pre>";
                echo "-- Drop existing constraints\n";
                echo "ALTER TABLE job_orders DROP FOREIGN KEY IF EXISTS fk_job_reviewed_by;\n";
                echo "ALTER TABLE job_orders DROP FOREIGN KEY IF EXISTS fk_job_approved_by;\n\n";
                echo "-- Then re-run this migration\n";
                echo "</pre>";
                echo "2. Or run the SQL from: <code>sql/job_order_enhancements_v2.sql</code> manually in phpMyAdmin<br>";
                echo "</div>";
            } else {
                echo "<div class='status success'>";
                echo "<strong>✓ Database is now ready for enhanced job order management!</strong><br><br>";
                echo "<strong>New Features Available:</strong><br>";
                echo "• Staff-driven job order creation<br>";
                echo "• Admin review and validation<br>";
                echo "• Automatic approval requirement detection<br>";
                echo "• Mechanic workload validation<br>";
                echo "• Inventory deduction tracking<br>";
                echo "• Comprehensive billing calculation<br>";
                echo "• Status tracking (Pending → Reviewed → In Progress → Completed)<br>";
                echo "</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<strong>Migration Failed:</strong><br>";
            echo htmlspecialchars($e->getMessage());
            echo "</div>";
            
            echo "<div class='status warning'>";
            echo "<strong>💡 Troubleshooting:</strong><br>";
            echo "1. Check database connection in <code>public/db_connect.php</code><br>";
            echo "2. Verify user has ALTER TABLE privileges<br>";
            echo "3. Check if SQL file exists<br>";
            echo "4. Try running SQL manually in phpMyAdmin<br>";
            echo "</div>";
        }
        ?>
        
        <a href="../public/joborder.php" class="btn">Go to Job Orders</a>
        <a href="../public/dashboard.php" class="btn" style="background: #6c757d;">Back to Dashboard</a>
        <a href="migrate_job_orders_v2.php" class="btn" style="background: #28a745;">Retry Migration</a>
    </div>
</body>
</html>
