<?php
// ============================================================
// Station Geocoding Tool
// public/geocode_stations.php
// Convert station addresses to actual coordinates
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

// Process geocoding request
$message = '';
$geocoded_count = 0;
$failed_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'geocode_all') {
        // Get stations without coordinates
        $stmt = $pdo->query("
            SELECT id, name, location 
            FROM stations 
            WHERE (latitude IS NULL OR longitude IS NULL) 
            AND location IS NOT NULL 
            AND location != ''
            ORDER BY name
            LIMIT 50
        ");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stations as $station) {
            $address = $station['location'] . ', Philippines';
            $coords = geocodeAddress($address);
            
            if ($coords) {
                $pdo->prepare("UPDATE stations SET latitude = ?, longitude = ? WHERE id = ?")
                    ->execute([$coords['lat'], $coords['lng'], $station['id']]);
                $geocoded_count++;
                usleep(100000); // 0.1 second delay to avoid rate limiting
            } else {
                $failed_count++;
            }
        }
        
        $message = "Geocoded: $geocoded_count stations. Failed: $failed_count";
    }
    
    if ($_POST['action'] === 'geocode_single') {
        $station_id = (int)$_POST['station_id'];
        $address = trim($_POST['address']) . ', Philippines';
        
        $coords = geocodeAddress($address);
        if ($coords) {
            $pdo->prepare("UPDATE stations SET latitude = ?, longitude = ? WHERE id = ?")
                ->execute([$coords['lat'], $coords['lng'], $station_id]);
            $message = "Success! Lat: {$coords['lat']}, Lng: {$coords['lng']}";
        } else {
            $message = "Failed to geocode address.";
        }
    }
}

// Simple geocoding function using Nominatim (free, no API key needed)
function geocodeAddress($address) {
    $address = urlencode($address);
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1";
    
    $options = [
        'http' => [
            'header' => "User-Agent: Petron Station Management System\r\n"
        ]
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data[0])) {
            return [
                'lat' => (float)$data[0]['lat'],
                'lng' => (float)$data[0]['lon']
            ];
        }
    }
    
    return null;
}

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) FROM stations");
$total_stations = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$with_coords = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM stations WHERE (latitude IS NULL OR longitude IS NULL) AND location IS NOT NULL AND location != ''");
$can_geocode = $stmt->fetchColumn();

// Get sample stations without coordinates
$stmt = $pdo->query("
    SELECT id, name, location 
    FROM stations 
    WHERE (latitude IS NULL OR longitude IS NULL) 
    AND location IS NOT NULL 
    AND location != ''
    ORDER BY name
    LIMIT 20
");
$sample_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geocode Stations</title>
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
            background: linear-gradient(135deg, #00264D, #003D7A);
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
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
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
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success {
            background: #e8f5e9;
            border: 1px solid #81c784;
            color: #2e7d32;
        }
        .alert.info {
            background: #e3f2fd;
            border: 1px solid #64b5f6;
            color: #1565c0;
        }
        .alert.warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #00264D;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn:hover {
            background: #001a3d;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
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
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .instructions {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #2196f3;
            margin-bottom: 30px;
        }
        .instructions h3 {
            margin: 0 0 10px;
            color: #1565c0;
        }
        .instructions ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            font-size: 20px;
            color: #00264D;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #00264D;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-map-pin"></i> Station Geocoding Tool</h1>
        <p>Convert station addresses to actual GPS coordinates</p>
    </div>

    <div class="content">
        <?php if ($message): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_stations; ?></div>
                <div class="stat-label">Total Stations</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $with_coords; ?></div>
                <div class="stat-label">With Coordinates</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $can_geocode; ?></div>
                <div class="stat-label">Can Be Geocoded</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format(($with_coords / max($total_stations, 1)) * 100, 1); ?>%</div>
                <div class="stat-label">Completion</div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="instructions">
            <h3><i class="fas fa-info-circle"></i> How to Use This Tool</h3>
            <ol>
                <li><strong>Automatic Geocoding:</strong> Click "Geocode 50 Stations" to automatically convert addresses to coordinates (processes 50 at a time)</li>
                <li><strong>Manual Geocoding:</strong> Use the form below to geocode individual stations</li>
                <li><strong>Rate Limiting:</strong> The tool uses free OpenStreetMap API with a 0.1 second delay between requests</li>
                <li><strong>Verification:</strong> After geocoding, check the map to verify locations are correct</li>
            </ol>
            <p style="margin-top:12px;"><strong>Note:</strong> For best results, ensure station addresses in database include city and complete information.</p>
        </div>

        <!-- Batch Geocoding -->
        <div class="section">
            <h2><i class="fas fa-rocket"></i> Batch Geocoding</h2>
            <p style="margin-bottom:16px;">Automatically geocode stations that have addresses but no coordinates.</p>
            
            <form method="POST" action="" onsubmit="return confirm('This will geocode up to 50 stations. Continue?');">
                <input type="hidden" name="action" value="geocode_all">
                <button type="submit" class="btn" <?php echo $can_geocode == 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-map-marked-alt"></i> Geocode 50 Stations
                </button>
                <?php if ($can_geocode == 0): ?>
                <span style="margin-left:10px;color:#28a745;font-weight:600;">
                    <i class="fas fa-check-circle"></i> All stations with addresses have coordinates!
                </span>
                <?php else: ?>
                <span style="margin-left:10px;color:#666;">
                    <?php echo $can_geocode; ?> stations need geocoding
                </span>
                <?php endif; ?>
            </form>
        </div>

        <!-- Manual Geocoding -->
        <div class="section">
            <h2><i class="fas fa-edit"></i> Manual Geocoding</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="geocode_single">
                <div class="form-group">
                    <label>Select Station</label>
                    <select name="station_id" required>
                        <option value="">-- Choose Station --</option>
                        <?php foreach ($sample_stations as $s): ?>
                        <option value="<?php echo $s['id']; ?>">
                            <?php echo htmlspecialchars($s['name']); ?> 
                            (<?php echo htmlspecialchars(substr($s['location'], 0, 50)); ?>...)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Address (will append ', Philippines')</label>
                    <input type="text" name="address" placeholder="e.g., 123 Ayala Avenue, Makati City" required>
                </div>
                <button type="submit" class="btn">
                    <i class="fas fa-search-location"></i> Geocode This Station
                </button>
            </form>
        </div>

        <!-- Sample Stations -->
        <?php if (!empty($sample_stations)): ?>
        <div class="section">
            <h2><i class="fas fa-list"></i> Sample Stations Without Coordinates</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Station Name</th>
                        <th>Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sample_stations as $s): ?>
                    <tr>
                        <td><?php echo $s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td><?php echo htmlspecialchars($s['location']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Navigation -->
        <div style="margin-top:40px;padding-top:30px;border-top:1px solid #e0e0e0;text-align:center;">
            <a href="superadmin_admin_map.php" class="btn btn-secondary">
                <i class="fas fa-map"></i> View Map
            </a>
            <a href="superadmin_admin_management.php" class="btn btn-secondary" style="margin-left:10px;">
                <i class="fas fa-arrow-left"></i> Back to Admin Management
            </a>
        </div>
    </div>
</div>

</body>
</html>
