<?php
/**
 * PREVIEW USERS TABLE CHANGES
 * Shows what will be changed WITHOUT making changes
 * Run: http://localhost/group31petron_system_official4/database/preview_changes.php
 */

require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Users Table Changes</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 30px;
            padding-left: 44px;
        }
        .section {
            margin-bottom: 35px;
        }
        .section-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: #4a5568;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        tr:last-child td {
            border-bottom: none;
        }
        tr:hover {
            background: #f7fafc;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-keep { background: #c6f6d5; color: #22543d; }
        .badge-delete { background: #fed7d7; color: #742a2a; }
        .badge-rename { background: #feebc8; color: #7c2d12; }
        .badge-add { background: #bee3f8; color: #2c5282; }
        .badge-update { background: #e9d8fd; color: #44337a; }
        .field-name {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #2d3748;
        }
        .field-type {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #718096;
        }
        .arrow {
            color: #a0aec0;
            font-weight: bold;
            padding: 0 8px;
        }
        .action-box {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .action-box h3 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 12px;
        }
        .action-box p {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .stat-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 8px;
        }
        .stat-label {
            color: #718096;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .warning {
            background: #fffaf0;
            border-left: 4px solid #ed8936;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .warning strong {
            color: #c05621;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Preview Users Table Changes</h1>
        <p class="subtitle">This shows what WILL be changed without making any changes yet</p>

<?php
try {
    // Required fields
    $required = ['id', 'first_name', 'last_name', 'station_id', 'email', 
                 'username', 'phone_number', 'password_hash', 'role', 
                 'status', 'created_at', 'updated_at'];
    
    // Get current structure
    $stmt = $pdo->query("DESCRIBE users");
    $fields_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_fields = array_column($fields_data, 'Field');
    
    // Analyze changes
    $to_delete = array_diff($current_fields, $required);
    $to_add = array_diff($required, $current_fields);
    $to_rename = [];
    
    // Check for renames
    if (in_array('phone', $current_fields) && !in_array('phone_number', $current_fields)) {
        $to_rename[] = ['phone', 'phone_number'];
        $to_add = array_diff($to_add, ['phone_number']);
    }
    if (in_array('password', $current_fields) && !in_array('password_hash', $current_fields)) {
        $to_rename[] = ['password', 'password_hash'];
        $to_add = array_diff($to_add, ['password_hash']);
    }
    
    // Count existing required fields
    $existing_required = array_intersect($required, $current_fields);
    
    echo '<div class="section">';
    echo '<div class="section-title">📊 Current Status</div>';
    echo '<div class="stats">';
    echo '<div class="stat-card"><div class="stat-number">' . count($current_fields) . '</div><div class="stat-label">Current Fields</div></div>';
    echo '<div class="stat-card"><div class="stat-number">12</div><div class="stat-label">Target Fields</div></div>';
    echo '<div class="stat-card"><div class="stat-number">' . count($to_delete) . '</div><div class="stat-label">To Delete</div></div>';
    echo '<div class="stat-card"><div class="stat-number">' . count($to_rename) . '</div><div class="stat-label">To Rename</div></div>';
    echo '</div>';
    echo '</div>';
    
    // Show fields to DELETE
    if (!empty($to_delete)) {
        echo '<div class="section">';
        echo '<div class="section-title">🗑️ Fields to DELETE</div>';
        echo '<table>';
        echo '<thead><tr><th>Field Name</th><th>Type</th><th>Action</th></tr></thead>';
        echo '<tbody>';
        foreach ($fields_data as $field) {
            if (in_array($field['Field'], $to_delete)) {
                echo '<tr>';
                echo '<td class="field-name">' . htmlspecialchars($field['Field']) . '</td>';
                echo '<td class="field-type">' . htmlspecialchars($field['Type']) . '</td>';
                echo '<td><span class="badge badge-delete">Will Delete</span></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    
    // Show fields to RENAME
    if (!empty($to_rename)) {
        echo '<div class="section">';
        echo '<div class="section-title">✏️ Fields to RENAME</div>';
        echo '<table>';
        echo '<thead><tr><th>Old Name</th><th></th><th>New Name</th><th>Action</th></tr></thead>';
        echo '<tbody>';
        foreach ($to_rename as $rename) {
            echo '<tr>';
            echo '<td class="field-name">' . htmlspecialchars($rename[0]) . '</td>';
            echo '<td class="arrow">→</td>';
            echo '<td class="field-name">' . htmlspecialchars($rename[1]) . '</td>';
            echo '<td><span class="badge badge-rename">Will Rename</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    
    // Show fields to ADD
    if (!empty($to_add)) {
        echo '<div class="section">';
        echo '<div class="section-title">➕ Fields to ADD</div>';
        echo '<table>';
        echo '<thead><tr><th>Field Name</th><th>Type</th><th>Action</th></tr></thead>';
        echo '<tbody>';
        foreach ($to_add as $field_name) {
            $type = 'VARCHAR(255)';
            if ($field_name === 'created_at') $type = 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
            elseif ($field_name === 'updated_at') $type = 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP';
            elseif ($field_name === 'status') $type = "ENUM('Active','Locked','Disabled')";
            elseif ($field_name === 'station_id') $type = 'INT(11) NULL';
            
            echo '<tr>';
            echo '<td class="field-name">' . htmlspecialchars($field_name) . '</td>';
            echo '<td class="field-type">' . htmlspecialchars($type) . '</td>';
            echo '<td><span class="badge badge-add">Will Add</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    
    // Show fields to KEEP
    echo '<div class="section">';
    echo '<div class="section-title">✅ Fields to KEEP (No Changes)</div>';
    echo '<table>';
    echo '<thead><tr><th>Field Name</th><th>Type</th><th>Status</th></tr></thead>';
    echo '<tbody>';
    foreach ($fields_data as $field) {
        if (in_array($field['Field'], $existing_required) && !in_array($field['Field'], array_column($to_rename, 0))) {
            echo '<tr>';
            echo '<td class="field-name">' . htmlspecialchars($field['Field']) . '</td>';
            echo '<td class="field-type">' . htmlspecialchars($field['Type']) . '</td>';
            echo '<td><span class="badge badge-keep">Keep</span></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
    
    // Final structure preview
    echo '<div class="section">';
    echo '<div class="section-title">🎯 FINAL Structure (After Changes)</div>';
    echo '<table>';
    echo '<thead><tr><th>#</th><th>Field Name</th><th>Description</th></tr></thead>';
    echo '<tbody>';
    $descriptions = [
        'id' => 'Primary Key, auto-increment',
        'first_name' => "User's given name",
        'last_name' => "User's family name",
        'station_id' => 'Foreign Key to stations',
        'email' => 'Unique login identifier (optional)',
        'username' => 'Unique login identifier',
        'phone_number' => 'Unique login identifier (optional)',
        'password_hash' => 'Hashed password (bcrypt)',
        'role' => "ENUM ('SuperAdmin', 'Admin', 'Manager', 'Staff')",
        'status' => "ENUM ('Active', 'Locked', 'Disabled')",
        'created_at' => 'Timestamp of creation',
        'updated_at' => 'Timestamp of last update',
    ];
    $i = 1;
    foreach ($required as $field_name) {
        echo '<tr>';
        echo '<td>' . $i++ . '</td>';
        echo '<td class="field-name">' . htmlspecialchars($field_name) . '</td>';
        echo '<td>' . htmlspecialchars($descriptions[$field_name] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    
    // Action box
    echo '<div class="action-box">';
    echo '<h3>🚀 Ready to Apply Changes?</h3>';
    
    if (count($to_delete) > 0 || count($to_rename) > 0 || count($to_add) > 0) {
        echo '<div class="warning">';
        echo '<strong>⚠️ Warning:</strong> The script will modify your database structure. ';
        echo 'Make sure you have a backup first!';
        echo '</div>';
        
        echo '<p><strong>What will happen:</strong></p>';
        echo '<ul style="margin-left: 20px; margin-bottom: 15px; line-height: 1.8;">';
        if (count($to_delete) > 0) {
            echo '<li>Delete ' . count($to_delete) . ' unused field(s)</li>';
        }
        if (count($to_rename) > 0) {
            echo '<li>Rename ' . count($to_rename) . ' field(s) for clarity</li>';
        }
        if (count($to_add) > 0) {
            echo '<li>Add ' . count($to_add) . ' missing field(s)</li>';
        }
        echo '<li>Update field types and constraints</li>';
        echo '<li>Result: Exactly 12 standardized fields ✅</li>';
        echo '</ul>';
        
        echo '<p><strong>Your login system will continue working</strong> because it uses smart field detection!</p>';
        echo '<a href="guarantee_users_structure.php" class="btn">✓ Run Update Script</a>';
    } else {
        echo '<p style="color: #38a169; font-weight: 600; font-size: 18px;">✅ Your users table already has the perfect structure!</p>';
        echo '<p>No changes needed. All 12 fields are present and correctly named.</p>';
    }
    echo '</div>';
    
} catch (PDOException $e) {
    echo '<div style="background: #fed7d7; color: #742a2a; padding: 20px; border-radius: 8px; border-left: 4px solid #e53e3e;">';
    echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>

    </div>
</body>
</html>
