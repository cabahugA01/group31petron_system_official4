<?php
// ============================================================
// Admin Map - Database Setup Test
// public/test_map_setup.php
// Run this file to check and setup the map feature
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    die('Access denied. SuperAdmin only.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Map Setup Test</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #00264D, #003D7A);
            color: #fff;
            padding: 30px 40px;
        }
        .header h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }
        .header p {
            margin: 0;
            opacity: .9;
            font-size: 14px;
        }
        .content {
            padding: 40px;
        }
        .test-item {
            padding: 20px;
            margin-bottom: 16px;
            border-radius: 8px;
            border-left: 4px solid #ddd;
            background: #f8f9fa;
        }
        .test-item.success {
            border-left-color: #28a745;
            background: #e8f5e9;
        }
        .test-item.error {
            border-left-color: #dc3545;
            background: #ffebee;
        }
        .test-item.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .test-item h3 {
            margin: 0 0 8px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .test-item p {
            margin: 0;
            font-size: 14px;
            color: #666;
        }
        .test-item code {
            background: rgba(0,0,0,.05);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #00264D;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 20px;
        }
        .btn:hover {
            background: #001a3d;
        }
        .sql-box {
            background: #263238;
            color: #aed581;
            padding: 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow:hidden;
            margin-top: 12px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #00264D;
        }
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-map-marked-alt"></i> Admin Map Setup Test</h1>
        <p>Checking database configuration and readiness for map feature</p>
    </div>

    <div class="content">
        <?php
        $tests = [];
        $allPass = true;

        // Test 1: Database Connection
        try {
            $pdo->query("SELECT 1");
            $tests[] = [
                'status' => 'success',
                'title' => 'Database Connection',
                'message' => 'Successfully connected to database'
            ];
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Database Connection',
                'message' => 'Failed to connect: ' . $e->getMessage()
            ];
            $allPass = false;
        }

        // Test 2: Stations Table
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM stations");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $required = ['id', 'name'];
            $optional = ['location', 'latitude', 'longitude', 'region', 'contact_number', 'status'];
            
            $missing = array_diff($optional, $columns);
            
            if (empty($missing)) {
                $tests[] = [
                    'status' => 'success',
                    'title' => 'Stations Table Structure',
                    'message' => 'All columns present: ' . implode(', ', $columns)
                ];
            } else {
                $tests[] = [
                    'status' => 'warning',
                    'title' => 'Stations Table Structure',
                    'message' => 'Table exists but missing optional columns: <code>' . implode(', ', $missing) . '</code>. Run migration to add them.',
                    'sql' => 'Run: database/migrations/add_station_coordinates.sql'
                ];
            }
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Stations Table',
                'message' => 'Table not found or error: ' . $e->getMessage()
            ];
            $allPass = false;
        }

        // Test 3: Users Table
        try {
            $pdo->query("SELECT id, first_name, last_name, email, role, status, station_id FROM users LIMIT 1");
            $tests[] = [
                'status' => 'success',
                'title' => 'Users Table',
                'message' => 'Users table accessible with required columns'
            ];
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Users Table',
                'message' => 'Error: ' . $e->getMessage()
            ];
            $allPass = false;
        }

        // Test 4: Station Data
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM stations");
            $stationCount = $stmt->fetchColumn();
            
            if ($stationCount > 0) {
                $tests[] = [
                    'status' => 'success',
                    'title' => 'Station Data',
                    'message' => "Found {$stationCount} station(s) in database"
                ];
            } else {
                $tests[] = [
                    'status' => 'warning',
                    'title' => 'Station Data',
                    'message' => 'No stations found. Add stations to use the map feature.'
                ];
            }
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Station Data',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }

        // Test 5: Coordinates Data
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('latitude', $cols) && in_array('longitude', $cols)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
                $coordCount = $stmt->fetchColumn();
                
                if ($coordCount > 0) {
                    $tests[] = [
                        'status' => 'success',
                        'title' => 'Station Coordinates',
                        'message' => "{$coordCount} station(s) have coordinates"
                    ];
                } else {
                    $tests[] = [
                        'status' => 'warning',
                        'title' => 'Station Coordinates',
                        'message' => 'Coordinate columns exist but no stations have coordinates yet. Run sample_station_coordinates.sql to add them.',
                        'sql' => 'Run: database/sample_station_coordinates.sql'
                    ];
                }
            } else {
                $tests[] = [
                    'status' => 'warning',
                    'title' => 'Station Coordinates',
                    'message' => 'Latitude/longitude columns not found. Run migration first.',
                    'sql' => 'Run: database/migrations/add_station_coordinates.sql'
                ];
            }
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Station Coordinates',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }

        // Test 6: Admin Users
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $adminCount = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND (station_id IS NULL OR station_id = 0)");
            $unassignedCount = $stmt->fetchColumn();
            
            if ($adminCount > 0) {
                $tests[] = [
                    'status' => 'success',
                    'title' => 'Admin Users',
                    'message' => "Found {$adminCount} admin(s). {$unassignedCount} unassigned, " . ($adminCount - $unassignedCount) . " assigned to stations."
                ];
            } else {
                $tests[] = [
                    'status' => 'warning',
                    'title' => 'Admin Users',
                    'message' => 'No admin users found. Create admin accounts to assign them to stations.'
                ];
            }
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'error',
                'title' => 'Admin Users',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }

        // Test 7: Activity Logs
        try {
            $pdo->query("SELECT id FROM activity_logs LIMIT 1");
            $tests[] = [
                'status' => 'success',
                'title' => 'Activity Logs',
                'message' => 'Activity logging table accessible'
            ];
        } catch (Exception $e) {
            $tests[] = [
                'status' => 'warning',
                'title' => 'Activity Logs',
                'message' => 'Activity logs table not found. Admin assignments will work but won\'t be logged.'
            ];
        }

        // Display test results
        foreach ($tests as $test) {
            echo '<div class="test-item ' . $test['status'] . '">';
            echo '<h3>';
            if ($test['status'] === 'success') echo '<i class="fas fa-check-circle" style="color:#28a745;"></i>';
            if ($test['status'] === 'error') echo '<i class="fas fa-times-circle" style="color:#dc3545;"></i>';
            if ($test['status'] === 'warning') echo '<i class="fas fa-exclamation-triangle" style="color:#ffc107;"></i>';
            echo $test['title'];
            echo '</h3>';
            echo '<p>' . $test['message'] . '</p>';
            if (!empty($test['sql'])) {
                echo '<div class="sql-box">' . htmlspecialchars($test['sql']) . '</div>';
            }
            echo '</div>';
        }

        // Summary Statistics
        echo '<h2 style="margin:40px 0 20px;">Database Statistics</h2>';
        echo '<div class="stats">';
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM stations");
            $totalStations = $stmt->fetchColumn();
            echo '<div class="stat-card"><div class="stat-value">' . $totalStations . '</div><div class="stat-label">Total Stations</div></div>';
        } catch (Exception $e) {}
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $totalAdmins = $stmt->fetchColumn();
            echo '<div class="stat-card"><div class="stat-value">' . $totalAdmins . '</div><div class="stat-label">Total Admins</div></div>';
        } catch (Exception $e) {}
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND station_id IS NOT NULL AND station_id > 0");
            $assignedAdmins = $stmt->fetchColumn();
            echo '<div class="stat-card"><div class="stat-value">' . $assignedAdmins . '</div><div class="stat-label">Assigned Admins</div></div>';
        } catch (Exception $e) {}
        
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('latitude', $cols)) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE latitude IS NOT NULL");
                $withCoords = $stmt->fetchColumn();
                echo '<div class="stat-card"><div class="stat-value">' . $withCoords . '</div><div class="stat-label">With Coordinates</div></div>';
            }
        } catch (Exception $e) {}
        
        echo '</div>';

        // Action button
        if ($allPass) {
            echo '<div style="text-align:center;padding:30px 0;">';
            echo '<p style="color:#28a745;font-size:18px;font-weight:600;margin-bottom:20px;"><i class="fas fa-check-circle"></i> All Tests Passed!</p>';
            echo '<a href="superadmin_admin_map.php" class="btn"><i class="fas fa-map-marked-alt"></i> Open Admin Map</a>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:30px 0;">';
            echo '<p style="color:#dc3545;font-size:16px;font-weight:600;margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> Some tests failed. Please fix the issues above before using the map.</p>';
            echo '<a href="superadmin_admin_management.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Admin Management</a>';
            echo '</div>';
        }
        ?>

        <div style="margin-top:40px;padding-top:30px;border-top:1px solid #e0e0e0;">
            <h3 style="margin:0 0 16px;">Quick Setup Steps</h3>
            <ol style="color:#666;font-size:14px;line-height:1.8;">
                <li>Run <code>database/migrations/add_station_coordinates.sql</code> in phpMyAdmin</li>
                <li>Run <code>database/sample_station_coordinates.sql</code> to add Philippine coordinates</li>
                <li>Create admin accounts if you haven't already</li>
                <li>Refresh this page to verify all tests pass</li>
                <li>Click "Open Admin Map" to start using the feature</li>
            </ol>
        </div>

    </div>
</div>

</body>
</html>
