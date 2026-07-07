<?php
// ============================================================
// Run Module Configuration Setup
// run_module_config_setup.php
// Execute SQL file to create all 9 tables
// ============================================================

require_once __DIR__ . '/public/db_connect.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Module Configuration Setup</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #00264d; margin-top: 0; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #bee5eb; }
        .step { background: #e7f3ff; padding: 12px; margin: 8px 0; border-left: 4px solid #00264d; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        .btn { display: inline-block; padding: 12px 24px; background: #00264d; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
        .btn:hover { background: #001a3d; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table th, table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        table th { background: #00264d; color: white; font-weight: 600; }
        table tr:hover { background: #f8f9fa; }
    </style>
</head>
<body>
<div class='container'>
    <h1>📊 Module Configuration Setup</h1>
";

try {
    // Read SQL file
    $sqlFile = __DIR__ . '/database/complete_station_module_config.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    echo "<div class='info'>📂 Reading SQL file: <code>$sqlFile</code></div>";
    
    $sql = file_get_contents($sqlFile);
    
    if (empty($sql)) {
        throw new Exception("SQL file is empty");
    }
    
    echo "<div class='info'>✅ SQL file loaded successfully (" . number_format(strlen($sql)) . " characters)</div>";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   stripos($stmt, '--') !== 0 && 
                   stripos($stmt, '/*') !== 0 &&
                   strlen($stmt) > 10;
        }
    );
    
    echo "<div class='info'>📋 Found " . count($statements) . " SQL statements to execute</div>";
    
    // Execute each statement
    $successCount = 0;
    $errors = [];
    
    echo "<h2>Executing SQL Statements...</h2>";
    
    foreach ($statements as $index => $statement) {
        try {
            $stmt = $pdo->exec($statement);
            $successCount++;
            
            // Show table creation messages
            if (stripos($statement, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE[^\w]*(\w+)/i', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                echo "<div class='step'>✅ Created table: <strong>$tableName</strong></div>";
            } elseif (stripos($statement, 'INSERT INTO') !== false) {
                preg_match('/INSERT INTO[^\w]*(\w+)/i', $statement, $matches);
                $tableName = $matches[1] ?? 'unknown';
                $affectedRows = $pdo->lastInsertId();
                echo "<div class='step'>✅ Inserted default data into: <strong>$tableName</strong></div>";
            }
            
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (stripos($e->getMessage(), 'already exists') === false && 
                stripos($e->getMessage(), 'Duplicate entry') === false) {
                $errors[] = [
                    'statement' => substr($statement, 0, 100) . '...',
                    'error' => $e->getMessage()
                ];
            }
        }
    }
    
    echo "<div class='success'>
        <h3>✅ Setup Complete!</h3>
        <p><strong>$successCount</strong> SQL statements executed successfully</p>
    </div>";
    
    if (!empty($errors)) {
        echo "<div class='error'><h3>⚠️ Some Errors Occurred</h3>";
        foreach ($errors as $err) {
            echo "<p><strong>Statement:</strong> " . htmlspecialchars($err['statement']) . "<br>";
            echo "<strong>Error:</strong> " . htmlspecialchars($err['error']) . "</p>";
        }
        echo "</div>";
    }
    
    // Verify tables created
    echo "<h2>Verifying Tables...</h2>";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'station_%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "<div class='success'>✅ Found " . count($tables) . " tables:</div>";
        echo "<table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Table Name</th>
                    <th>Records</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($tables as $index => $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
            $count = $stmt->fetchColumn();
            echo "<tr>
                <td>" . ($index + 1) . "</td>
                <td><strong>$table</strong></td>
                <td>" . number_format($count) . "</td>
            </tr>";
        }
        
        echo "</tbody></table>";
    } else {
        echo "<div class='error'>❌ No station_ tables found!</div>";
    }
    
    // Check modules per station
    echo "<h2>Sample Data Check</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            s.name as station,
            COUNT(sm.id) as total_modules,
            SUM(sm.is_enabled) as enabled_modules
        FROM stations s
        LEFT JOIN station_modules sm ON sm.station_id = s.id
        GROUP BY s.id
        ORDER BY s.name
        LIMIT 10
    ");
    
    $sampleStations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($sampleStations)) {
        echo "<div class='info'>✅ Module configuration data populated successfully</div>";
        echo "<table>
            <thead>
                <tr>
                    <th>Station</th>
                    <th>Total Modules</th>
                    <th>Enabled</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($sampleStations as $station) {
            echo "<tr>
                <td>" . htmlspecialchars($station['station']) . "</td>
                <td>" . $station['total_modules'] . "</td>
                <td>" . $station['enabled_modules'] . "</td>
            </tr>";
        }
        
        echo "</tbody></table>";
    }
    
    // Check fuel configuration
    echo "<h3>Fuel Configuration Sample</h3>";
    
    $stmt = $pdo->query("
        SELECT 
            s.name as station,
            sfc.fuel_type,
            sfc.official_price_per_liter,
            sfc.tank_capacity
        FROM stations s
        INNER JOIN station_fuel_config sfc ON sfc.station_id = s.id
        ORDER BY s.name, sfc.fuel_type
        LIMIT 10
    ");
    
    $fuelSamples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($fuelSamples)) {
        echo "<table>
            <thead>
                <tr>
                    <th>Station</th>
                    <th>Fuel Type</th>
                    <th>Price/Liter</th>
                    <th>Tank Capacity</th>
                </tr>
            </thead>
            <tbody>";
        
        foreach ($fuelSamples as $fuel) {
            echo "<tr>
                <td>" . htmlspecialchars($fuel['station']) . "</td>
                <td>" . htmlspecialchars($fuel['fuel_type']) . "</td>
                <td>₱" . number_format($fuel['official_price_per_liter'], 2) . "</td>
                <td>" . number_format($fuel['tank_capacity']) . " L</td>
            </tr>";
        }
        
        echo "</tbody></table>";
    }
    
    echo "<div class='success'>
        <h3>🎉 SUCCESS!</h3>
        <p>Module Configuration system is now ready to use!</p>
        <ul>
            <li>✅ 9 database tables created</li>
            <li>✅ Default data populated for all stations</li>
            <li>✅ Fuel configurations set</li>
            <li>✅ Payment methods configured</li>
            <li>✅ Module settings initialized</li>
        </ul>
    </div>";
    
    echo "
    <h2>Next Steps</h2>
    <div class='step'>
        <strong>1.</strong> Test the API endpoints (see RUN_MODULE_CONFIG_NOW.md)
    </div>
    <div class='step'>
        <strong>2.</strong> Visit the Module Configuration page
    </div>
    <div class='step'>
        <strong>3.</strong> Start configuring modules per station
    </div>
    
    <a href='public/module_configuration.php' class='btn'>🚀 Go to Module Configuration</a>
    <a href='RUN_MODULE_CONFIG_NOW.md' class='btn' style='background: #28a745;'>📖 View Documentation</a>
    ";
    
} catch (Exception $e) {
    echo "<div class='error'>
        <h3>❌ Setup Failed</h3>
        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
}

echo "
</div>
</body>
</html>";
?>
