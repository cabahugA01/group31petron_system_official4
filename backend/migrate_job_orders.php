<?php
/**
 * Database Migration Script
 * Applies Job Order Management enhancements
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
    <title>Job Order System Migration</title>
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
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Job Order Management System Migration</h1>
        <p>This will enhance the database to support staff-driven, admin-supervised job orders.</p>
        
        <?php
        try {
            echo "<div class='status info'>Starting migration...</div>";
            
            // Read SQL migration file
            $sql_file = __DIR__ . '/../sql/job_order_enhancements.sql';
            if (!file_exists($sql_file)) {
                throw new Exception("Migration SQL file not found");
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
            $errors = [];
            
            foreach ($statements as $index => $statement) {
                if (empty(trim($statement))) continue;
                
                try {
                    $pdo->exec($statement);
                    $success_count++;
                    
                    // Show first line of each successful statement
                    $first_line = strtok($statement, "\n");
                    echo "<div class='status success'>✓ Statement " . ($index + 1) . ": " . htmlspecialchars(substr($first_line, 0, 60)) . "...</div>";
                    
                } catch (PDOException $e) {
                    // Ignore errors for columns/constraints that already exist
                    if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                        strpos($e->getMessage(), 'Duplicate key') !== false ||
                        strpos($e->getMessage(), 'already exists') !== false) {
                        echo "<div class='status warning'>⚠ Already exists: " . htmlspecialchars(substr(strtok($statement, "\n"), 0, 60)) . "...</div>";
                    } else {
                        $error_count++;
                        $errors[] = [
                            'statement' => $statement,
                            'error' => $e->getMessage()
                        ];
                        echo "<div class='status error'>✗ Error in statement " . ($index + 1) . ": " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                }
            }
            
            echo "<hr style='margin: 20px 0;'>";
            echo "<div class='status success'><strong>✓ Migration Complete!</strong></div>";
            echo "<div class='status info'>";
            echo "<strong>Summary:</strong><br>";
            echo "• Successful: " . $success_count . " statements<br>";
            echo "• Errors: " . $error_count . " statements<br>";
            echo "• Total: " . count($statements) . " statements<br>";
            echo "</div>";
            
            if ($error_count > 0) {
                echo "<div class='status error'>";
                echo "<strong>Errors encountered:</strong><br>";
                foreach ($errors as $err) {
                    echo "<pre>" . htmlspecialchars($err['error']) . "</pre>";
                }
                echo "</div>";
            }
            
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
            
        } catch (Exception $e) {
            echo "<div class='status error'>";
            echo "<strong>Migration Failed:</strong><br>";
            echo htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>
        
        <a href="../public/joborder.php" class="btn">Go to Job Orders</a>
        <a href="../public/dashboard.php" class="btn" style="background: #6c757d;">Back to Dashboard</a>
    </div>
</body>
</html>
