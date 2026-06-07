<?php
/**
 * FINAL USERS TABLE UPDATE
 * - Remove phone_number field
 * - Keep only 11 fields (no phone)
 * - Standardize structure
 * 
 * Run: http://localhost/group31petron_system_official4/database/update_users_final.php
 */

require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Update Users Table - Final</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 25px;
        }
        .section {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .section h2 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 15px;
        }
        .success { border-left-color: #38a169; background: #c6f6d5; }
        .error { border-left-color: #e53e3e; background: #fed7d7; }
        .warning { border-left-color: #ed8936; background: #feebc8; }
        pre {
            background: white;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            background: #4a5568;
            color: white;
            font-weight: 600;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #c6f6d5; color: #22543d; }
        .badge-error { background: #fed7d7; color: #742a2a; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Final Users Table Update</h1>

<?php
try {
    // Required fields (11 fields - NO phone_number)
    $required = [
        'user_id', 'first_name', 'last_name', 'station_id', 'email', 
        'username', 'password_hash', 'role', 'status', 'created_at', 'updated_at'
    ];
    
    echo '<div class="section">';
    echo '<h2>📊 Step 1: Current Structure</h2>';
    
    $stmt = $pdo->query("DESCRIBE users");
    $fields_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_fields = array_column($fields_data, 'Field');
    
    echo "<p>Current fields: " . count($current_fields) . "</p>";
    echo "<p>Target fields: " . count($required) . " (11 fields - NO phone)</p>";
    echo '</div>';
    
    // Check if we need to rename 'id' to 'user_id'
    $rename_id = false;
    if (in_array('id', $current_fields) && !in_array('user_id', $current_fields)) {
        $rename_id = true;
    }
    
    // Fields to delete
    $to_delete = array_diff($current_fields, $required);
    if ($rename_id) {
        $to_delete = array_diff($to_delete, ['id']); // Don't delete 'id', we'll rename it
    }
    
    // Rename operations
    echo '<div class="section">';
    echo '<h2>✏️ Step 2: Renaming Fields</h2>';
    
    if ($rename_id) {
        try {
            echo "<p>Renaming 'id' → 'user_id'... ";
            $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `id` `user_id` INT(11) AUTO_INCREMENT");
            echo "<span class='badge badge-success'>✓ SUCCESS</span></p>";
        } catch (PDOException $e) {
            echo "<span class='badge badge-error'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span></p>";
        }
    } else {
        echo "<p>'user_id' already exists ✓</p>";
    }
    
    // Rename password to password_hash
    if (in_array('password', $current_fields) && !in_array('password_hash', $current_fields)) {
        try {
            echo "<p>Renaming 'password' → 'password_hash'... ";
            $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL");
            echo "<span class='badge badge-success'>✓ SUCCESS</span></p>";
        } catch (PDOException $e) {
            echo "<span class='badge badge-error'>✗ ERROR</span></p>";
        }
    } else {
        echo "<p>'password_hash' already correct ✓</p>";
    }
    
    echo '</div>';
    
    // Delete extra fields (INCLUDING phone_number)
    if (!empty($to_delete)) {
        echo '<div class="section warning">';
        echo '<h2>🗑️ Step 3: Removing Extra Fields (Including phone_number)</h2>';
        
        foreach ($to_delete as $field) {
            try {
                echo "<p>Deleting '$field'... ";
                $pdo->exec("ALTER TABLE `users` DROP COLUMN `$field`");
                echo "<span class='badge badge-success'>✓ SUCCESS</span></p>";
            } catch (PDOException $e) {
                echo "<span class='badge badge-error'>✗ SKIP</span></p>";
            }
        }
        
        echo '</div>';
    }
    
    // Add missing fields
    echo '<div class="section">';
    echo '<h2>➕ Step 4: Adding Missing Fields</h2>';
    
    $stmt = $pdo->query("DESCRIBE users");
    $current_fields = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $missing = array_diff($required, $current_fields);
    
    if (!empty($missing)) {
        foreach ($missing as $field) {
            $sql = '';
            switch ($field) {
                case 'status':
                    $sql = "ALTER TABLE `users` ADD COLUMN `status` ENUM('Active','Locked','Disabled') NOT NULL DEFAULT 'Active'";
                    break;
                case 'created_at':
                    $sql = "ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
                    break;
                case 'updated_at':
                    $sql = "ALTER TABLE `users` ADD COLUMN `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP";
                    break;
            }
            
            if (!empty($sql)) {
                try {
                    echo "<p>Adding '$field'... ";
                    $pdo->exec($sql);
                    echo "<span class='badge badge-success'>✓ SUCCESS</span></p>";
                } catch (PDOException $e) {
                    echo "<span class='badge badge-error'>✗ SKIP</span></p>";
                }
            }
        }
    } else {
        echo "<p>All required fields exist ✓</p>";
    }
    
    echo '</div>';
    
    // Update field types
    echo '<div class="section">';
    echo '<h2>🔧 Step 5: Updating Field Types</h2>';
    
    $updates = [
        "ALTER TABLE `users` MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL" => "first_name",
        "ALTER TABLE `users` MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL" => "last_name",
        "ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(255) NULL UNIQUE" => "email",
        "ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL UNIQUE" => "username",
        "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin','Admin','Manager','Staff') NOT NULL DEFAULT 'Staff'" => "role",
        "ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Locked','Disabled') NOT NULL DEFAULT 'Active'" => "status",
    ];
    
    foreach ($updates as $sql => $desc) {
        try {
            echo "<p>Updating $desc... ";
            $pdo->exec($sql);
            echo "<span class='badge badge-success'>✓ SUCCESS</span></p>";
        } catch (PDOException $e) {
            echo "<span class='badge badge-error'>✗ SKIP</span></p>";
        }
    }
    
    echo '</div>';
    
    // Final structure
    echo '<div class="section success">';
    echo '<h2>✅ Step 6: Final Structure</h2>';
    
    $stmt = $pdo->query("DESCRIBE users");
    $final_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<table>';
    echo '<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Status</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($final_fields as $field) {
        $is_required = in_array($field['Field'], $required);
        $status = $is_required ? '<span class="badge badge-success">✓ REQUIRED</span>' : '<span class="badge badge-error">✗ EXTRA</span>';
        
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($field['Field']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($field['Type']) . '</td>';
        echo '<td>' . htmlspecialchars($field['Null']) . '</td>';
        echo '<td>' . htmlspecialchars($field['Key']) . '</td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    $final_count = count($final_fields);
    echo "<p style='margin-top: 20px;'><strong>Total fields: $final_count</strong></p>";
    
    if ($final_count === 11) {
        echo "<p style='color: #38a169; font-weight: bold; font-size: 18px; margin-top: 10px;'>✅ PERFECT! Users table has exactly 11 fields (NO phone)!</p>";
    } else {
        echo "<p style='color: #e53e3e; font-weight: bold; font-size: 18px; margin-top: 10px;'>⚠️ Table has $final_count fields. Expected 11.</p>";
    }
    
    echo '</div>';
    
    // Final checklist
    echo '<div class="section">';
    echo '<h2>📋 Final Checklist</h2>';
    echo '<pre>';
    echo "Required Fields (11):\n";
    foreach ($required as $field) {
        $exists = in_array($field, array_column($final_fields, 'Field'));
        echo ($exists ? '✓' : '✗') . " $field\n";
    }
    echo "\nRemoved Fields:\n";
    echo "✓ phone_number (NO phone login)\n";
    echo "✓ phone (if existed)\n";
    echo "✓ All other extra fields\n";
    echo '</pre>';
    echo '</div>';
    
} catch (PDOException $e) {
    echo '<div class="section error">';
    echo '<h2>❌ Fatal Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
?>

    </div>
</body>
</html>
