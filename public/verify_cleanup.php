<?php
// ============================================================
// Verify Station Cleanup Results
// public/verify_cleanup.php
// Check if invalid stations were successfully deleted
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

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Cleanup Verification</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #28a745, #20873a);
            color: #fff;
            padding: 30px 40px;
        }
        .header h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }
        .content {
            padding: 40px;
        }
        .section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }
        .section:last-child {
            border-bottom: none;
        }
        .section h2 {
            font-size: 20px;
            color: #00264d;
            margin: 0 0 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: #f8f9fa;
            padding: 24px;
            border-radius: 10px;
            text-align: center;
            border: 2px solid #e0e0e0;
        }
        .stat-box.success {
            background: #d4edda;
            border-color: #28a745;
        }
        .stat-box.warning {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .stat-box.danger {
            background: #f8d7da;
            border-color: #dc3545;
        }
        .stat-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .stat-value.green { color: #28a745; }
        .stat-value.red { color: #dc3545; }
        .stat-value.blue { color: #007bff; }
        .stat-value.orange { color: #ff9800; }
        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: #007bff;
            color: #fff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
            color: #fff;
        }
        .btn-success:hover {
            background: #20873a;
        }
        .btn-danger {
            background: #dc3545;
            color: #fff;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert.warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        .alert.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 16px;
            border-radius: 8px;
            overflow:hidden;
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-check-circle"></i> Station Cleanup Verification</h1>
        <p>Verify that invalid stations were successfully deleted from the database</p>
    </div>

    <div class="content">

        <!-- SECTION 1: OVERALL STATISTICS -->
        <div class="section">
            <h2><i class="fas fa-chart-bar"></i> Overall Statistics</h2>
            
            <?php
            try {
                // Get total stations
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations");
                $total_stations = $stmt->fetchColumn();

                // Get stations with "PETRON" in name
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE LOWER(name) LIKE '%petron%'");
                $petron_stations = $stmt->fetchColumn();

                // Get stations with admins
                $stmt = $pdo->query("SELECT COUNT(DISTINCT station_id) FROM users WHERE station_id IS NOT NULL AND role = 'admin'");
                $stations_with_admins = $stmt->fetchColumn();

                // Get stations with coordinates
                $stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
                $stations_with_coords = $stmt->fetchColumn();

                // Detect if cleanup was successful
                $cleanup_success = $total_stations <= 50 || ($petron_stations / max($total_stations, 1)) > 0.8;
            ?>
            
            <div class="stats-grid">
                <div class="stat-box <?php echo $cleanup_success ? 'success' : 'warning'; ?>">
                    <div class="stat-value blue"><?php echo $total_stations; ?></div>
                    <div class="stat-label">Total Stations</div>
                </div>
                <div class="stat-box success">
                    <div class="stat-value green"><?php echo $petron_stations; ?></div>
                    <div class="stat-label">PETRON Stations</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value orange"><?php echo $stations_with_admins; ?></div>
                    <div class="stat-label">With Assigned Admins</div>
                </div>
                <div class="stat-box <?php echo $stations_with_coords > 0 ? 'success' : 'warning'; ?>">
                    <div class="stat-value <?php echo $stations_with_coords > 0 ? 'green' : 'red'; ?>"><?php echo $stations_with_coords; ?></div>
                    <div class="stat-label">With GPS Coordinates</div>
                </div>
            </div>

            <?php if ($cleanup_success): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <strong>Cleanup Successful!</strong> Your database appears clean with <?php echo $total_stations; ?> stations.
            </div>
            <?php else: ?>
            <div class="alert warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Cleanup May Be Incomplete</strong> - You have <?php echo $total_stations; ?> total stations but only <?php echo $petron_stations; ?> are PETRON stations.
            </div>
            <?php endif; ?>

            <?php
            } catch (Exception $e) {
                echo '<div class="alert danger"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- SECTION 2: INVALID STATIONS CHECK -->
        <div class="section">
            <h2><i class="fas fa-search"></i> Invalid Stations Check</h2>
            
            <?php
            try {
                // Find remaining invalid stations
                $stmt = $pdo->query("
                    SELECT id, name, location, status
                    FROM stations 
                    WHERE (
                        -- Not a PETRON station
                        LOWER(name) NOT LIKE '%petron%'
                        
                        -- Or has gibberish name
                        OR (name REGEXP '^[a-z]+$' AND CHAR_LENGTH(name) < 15)
                        
                        -- Or has no/empty location
                        OR location IS NULL 
                        OR location = ''
                        OR location = 'NULL'
                        
                        -- Or is test data
                        OR LOWER(name) LIKE '%test%'
                        OR LOWER(name) LIKE '%dummy%'
                        OR LOWER(name) LIKE '%sample%'
                    )
                    ORDER BY id
                    LIMIT 50
                ");
                $invalid_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $invalid_count = count($invalid_stations);

                if ($invalid_count === 0): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Perfect!</strong> No invalid stations found. Database is clean!
                </div>
                <?php else: ?>
                <div class="alert warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Found <?php echo $invalid_count; ?> invalid station(s)</strong> - These should be deleted.
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Issue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invalid_stations as $station): 
                            $issues = [];
                            if (stripos($station['name'], 'petron') === false) $issues[] = 'Not PETRON';
                            if (preg_match('/^[a-z]+$/', $station['name']) && strlen($station['name']) < 15) $issues[] = 'Gibberish';
                            if (empty($station['location'])) $issues[] = 'No location';
                            if (stripos($station['name'], 'test') !== false || stripos($station['name'], 'dummy') !== false) $issues[] = 'Test data';
                        ?>
                        <tr>
                            <td><?php echo $station['id']; ?></td>
                            <td><?php echo htmlspecialchars($station['name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($station['location'] ?? 'N/A', 0, 50)); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $station['status'] === 'Active' ? 'success' : 'danger'; ?>">
                                    <?php echo $station['status']; ?>
                                </span>
                            </td>
                            <td><?php echo implode(', ', $issues); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif;

            } catch (Exception $e) {
                echo '<div class="alert danger"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- SECTION 3: VALID STATIONS SAMPLE -->
        <div class="section">
            <h2><i class="fas fa-list"></i> Sample of Valid Stations</h2>
            
            <?php
            try {
                // Get sample of valid stations
                $stmt = $pdo->query("
                    SELECT id, name, location, status, latitude, longitude
                    FROM stations 
                    WHERE LOWER(name) LIKE '%petron%'
                    ORDER BY id
                    LIMIT 20
                ");
                $valid_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($valid_stations) === 0): ?>
                <div class="alert warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>No valid PETRON stations found!</strong> This might indicate a problem.
                </div>
                <?php else: ?>
                <div class="alert info">
                    <i class="fas fa-info-circle"></i>
                    Showing first 20 valid PETRON stations (out of <?php echo $petron_stations; ?> total)
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Coordinates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($valid_stations as $station): ?>
                        <tr>
                            <td><?php echo $station['id']; ?></td>
                            <td><?php echo htmlspecialchars($station['name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($station['location'] ?? 'N/A', 0, 50)); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $station['status'] === 'Active' ? 'success' : 'danger'; ?>">
                                    <?php echo $station['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($station['latitude'] && $station['longitude']): ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check"></i> Has GPS
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-times"></i> No GPS
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif;

            } catch (Exception $e) {
                echo '<div class="alert danger"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>
        </div>

        <!-- SECTION 4: RECOMMENDED ACTIONS -->
        <div class="section">
            <h2><i class="fas fa-tasks"></i> Recommended Next Steps</h2>
            
            <?php if ($invalid_count > 0): ?>
            <div class="alert warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Action Required:</strong> You still have <?php echo $invalid_count; ?> invalid station(s) that should be deleted.
            </div>
            <ul style="margin:20px 0;padding-left:24px;">
                <li>Go to <strong>Cleanup Tool</strong> to delete remaining invalid stations</li>
                <li>Review the list carefully before deleting</li>
                <li>Make sure to backup your database first</li>
            </ul>
            <?php elseif ($stations_with_coords === 0): ?>
            <div class="alert info">
                <i class="fas fa-info-circle"></i>
                <strong>Next Step:</strong> Add GPS coordinates to your stations for accurate map display.
            </div>
            <ul style="margin:20px 0;padding-left:24px;">
                <li>Go to <strong>Geocoding Tool</strong> to add coordinates automatically</li>
                <li>Or manually edit station records to add latitude/longitude</li>
            </ul>
            <?php else: ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <strong>All Done!</strong> Your database is clean and ready to use.
            </div>
            <ul style="margin:20px 0;padding-left:24px;">
                <li>View your stations on the interactive map</li>
                <li>Assign admins to stations as needed</li>
                <li>Monitor station status and admin assignments</li>
            </ul>
            <?php endif; ?>
        </div>

        <!-- NAVIGATION ACTIONS -->
        <div class="actions">
            <?php if ($invalid_count > 0): ?>
            <a href="cleanup_stations.php" class="btn btn-danger">
                <i class="fas fa-trash-alt"></i> Go to Cleanup Tool
            </a>
            <?php endif; ?>
            
            <?php if ($stations_with_coords === 0): ?>
            <a href="geocode_stations.php" class="btn btn-primary">
                <i class="fas fa-map-pin"></i> Add GPS Coordinates
            </a>
            <?php endif; ?>
            
            <a href="superadmin_admin_map.php" class="btn btn-success">
                <i class="fas fa-map"></i> View Map
            </a>
            
            <a href="superadmin_admin_management.php" class="btn btn-primary">
                <i class="fas fa-list"></i> Admin Management
            </a>
            
            <button onclick="window.location.reload()" class="btn" style="background:#6c757d;color:#fff;">
                <i class="fas fa-sync"></i> Refresh
            </button>
        </div>

        <!-- SQL COMMANDS REFERENCE -->
        <div class="section" style="border-bottom:none;margin-top:40px;">
            <h2><i class="fas fa-code"></i> Quick SQL Commands</h2>
            <p style="color:#666;margin-bottom:16px;">
                Run these in phpMyAdmin → SQL tab if you need to manually check or clean the database:
            </p>
            
            <h3 style="font-size:14px;margin:20px 0 10px;">Check total stations:</h3>
            <pre>SELECT COUNT(*) as total FROM stations;</pre>

            <h3 style="font-size:14px;margin:20px 0 10px;">Check valid PETRON stations:</h3>
            <pre>SELECT COUNT(*) as petron_stations 
FROM stations 
WHERE LOWER(name) LIKE '%petron%';</pre>

            <h3 style="font-size:14px;margin:20px 0 10px;">Find stations with gibberish names:</h3>
            <pre>SELECT id, name, location 
FROM stations 
WHERE name REGEXP '^[a-z]+$' 
AND CHAR_LENGTH(name) < 15;</pre>

            <h3 style="font-size:14px;margin:20px 0 10px;">View all station names (first 50):</h3>
            <pre>SELECT id, name, location, status 
FROM stations 
ORDER BY name 
LIMIT 50;</pre>
        </div>

    </div>
</div>

</body>
</html>
