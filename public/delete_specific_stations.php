<?php
// ============================================================
// Delete Specific Stations by Name or ID
// public/delete_specific_stations.php
// Quick tool to delete specific stations
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

$message = '';
$deleted = [];

// List of stations to delete (by name or ID)
$stations_to_delete = [
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
    // Add more here if needed
];

// Process deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $pdo->beginTransaction();
        
        // Build query to find these stations
        $placeholders = implode(',', array_fill(0, count($stations_to_delete), '?'));
        
        // First, check if any have assigned admins
        $stmt = $pdo->prepare("
            SELECT s.id, s.name, COUNT(u.id) as admin_count
            FROM stations s
            LEFT JOIN users u ON u.station_id = s.id AND u.role = 'admin'
            WHERE s.name IN ($placeholders)
            GROUP BY s.id, s.name
        ");
        $stmt->execute($stations_to_delete);
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($stations)) {
            $message = "No stations found with these names.";
        } else {
            $has_admins = array_filter($stations, fn($s) => $s['admin_count'] > 0);
            
            if (!empty($has_admins)) {
                $names = implode(', ', array_column($has_admins, 'name'));
                $pdo->rollBack();
                $message = "Error: Cannot delete stations with assigned admins: $names. Unassign them first.";
            } else {
                // Get IDs for deletion
                $ids = array_column($stations, 'id');
                $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
                
                // Delete related inventory first
                try {
                    $stmt = $pdo->prepare("DELETE FROM inventory WHERE station_id IN ($id_placeholders)");
                    $stmt->execute($ids);
                } catch (Exception $e) {
                    // Table might not exist
                }
                
                // Delete stations
                $stmt = $pdo->prepare("DELETE FROM stations WHERE id IN ($id_placeholders)");
                $stmt->execute($ids);
                $deleted_count = $stmt->rowCount();
                
                $pdo->commit();
                
                // Log deletion
                $deleted_names = implode(', ', array_column($stations, 'name'));
                log_activity($pdo, $me['id'], 'Specific Station Deletion', "Deleted $deleted_count stations: $deleted_names");
                
                $deleted = $stations;
                $message = "Successfully deleted $deleted_count station(s) permanently!";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Error: " . $e->getMessage();
    }
}

// Find stations that match the names
$matching_stations = [];
try {
    $placeholders = implode(',', array_fill(0, count($stations_to_delete), '?'));
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.location, s.status,
               (SELECT COUNT(*) FROM users WHERE station_id = s.id AND role = 'admin') as admin_count
        FROM stations s
        WHERE s.name IN ($placeholders)
        ORDER BY s.name
    ");
    $stmt->execute($stations_to_delete);
    $matching_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Specific Stations</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            background: linear-gradient(135deg, #dc3545, #c82333);
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
        .alert.danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: all .2s;
        }
        .btn-danger {
            background: #dc3545;
            color: #fff;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-success { background: #d4edda; color: #155724; }
        .station-list {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .station-list h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #666;
        }
        .station-list ul {
            margin: 0;
            padding-left: 20px;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .warning-box h3 {
            margin: 0 0 10px;
            color: #856404;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-trash-alt"></i> Delete Specific Stations</h1>
        <p>Permanently delete the specified stations from the database</p>
    </div>

    <div class="content">
        <?php if ($message): ?>
        <div class="alert <?php echo strpos($message, 'Error') !== false ? 'danger' : (strpos($message, 'Successfully') !== false ? 'success' : 'info'); ?>">
            <i class="fas fa-<?php echo strpos($message, 'Error') !== false ? 'exclamation-circle' : (strpos($message, 'Successfully') !== false ? 'check-circle' : 'info-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($deleted)): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <strong>Deleted Stations:</strong>
            <ul style="margin:10px 0 0;padding-left:20px;">
                <?php foreach ($deleted as $station): ?>
                <li><?php echo htmlspecialchars($station['name']); ?> (ID: <?php echo $station['id']; ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Target Stations -->
        <div class="station-list">
            <h3>Target Stations to Delete:</h3>
            <ul>
                <?php foreach ($stations_to_delete as $name): ?>
                <li><strong><?php echo htmlspecialchars($name); ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if (!empty($matching_stations)): ?>
        
        <div class="warning-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Warning: Permanent Deletion</h3>
            <p><strong>This will permanently delete the following stations:</strong></p>
            <ul>
                <?php foreach ($matching_stations as $station): ?>
                <li>
                    <strong><?php echo htmlspecialchars($station['name']); ?></strong> 
                    (ID: <?php echo $station['id']; ?>)
                    <?php if ($station['admin_count'] > 0): ?>
                    <span class="badge badge-warning">Has <?php echo $station['admin_count']; ?> admin(s)</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin-top:10px;"><strong>This action cannot be undone!</strong></p>
        </div>

        <!-- Station Details -->
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Admins</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matching_stations as $station): ?>
                <tr>
                    <td><?php echo $station['id']; ?></td>
                    <td><?php echo htmlspecialchars($station['name']); ?></td>
                    <td><?php echo htmlspecialchars(substr($station['location'] ?? 'N/A', 0, 50)); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $station['status'] === 'Active' ? 'success' : 'danger'; ?>">
                            <?php echo $station['status']; ?>
                        </span>
                    </td>
                    <td><?php echo $station['admin_count']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Confirmation Form -->
        <form method="POST" onsubmit="return confirm('Are you ABSOLUTELY SURE? This will permanently delete these stations!');">
            <div class="actions">
                <button type="submit" name="confirm_delete" value="1" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete These Stations Permanently
                </button>
                <a href="verify_cleanup.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancel / Go to Verification
                </a>
            </div>
        </form>

        <?php else: ?>
        
        <div class="alert info">
            <i class="fas fa-info-circle"></i>
            No matching stations found in the database. They may have already been deleted.
        </div>

        <div class="actions">
            <a href="verify_cleanup.php" class="btn btn-secondary">
                <i class="fas fa-check-circle"></i> Go to Verification
            </a>
            <a href="cleanup_stations.php" class="btn btn-secondary">
                <i class="fas fa-trash"></i> Go to Cleanup Tool
            </a>
        </div>

        <?php endif; ?>

        <!-- Navigation -->
        <div style="margin-top:40px;padding-top:30px;border-top:1px solid #e0e0e0;text-align:center;">
            <a href="verify_cleanup.php" class="btn btn-secondary" style="background:#28a745;color:#fff;">
                <i class="fas fa-check-circle"></i> Verify Cleanup Status
            </a>
            <a href="superadmin_admin_map.php" class="btn btn-secondary" style="margin-left:10px;">
                <i class="fas fa-map"></i> View Map
            </a>
        </div>
    </div>
</div>

</body>
</html>
