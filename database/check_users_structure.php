<?php
/**
 * Check Users Table Structure
 * Shows current fields vs required fields
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "═══════════════════════════════════════════════════════════\n";
echo "  USERS TABLE STRUCTURE CHECK\n";
echo "═══════════════════════════════════════════════════════════\n\n";

try {
    // Get current table structure
    $stmt = $pdo->query("DESCRIBE users");
    $current_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "CURRENT FIELDS IN DATABASE:\n";
    echo "─────────────────────────────────────────────────────────\n";
    foreach ($current_fields as $field) {
        $nullable = $field['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $field['Default'] !== null ? "DEFAULT '{$field['Default']}'" : '';
        echo sprintf("%-20s %-20s %-15s %s\n", 
            $field['Field'], 
            $field['Type'], 
            $nullable,
            $default
        );
    }
    
    echo "\n";
    echo "REQUIRED FIELDS (User's Request):\n";
    echo "─────────────────────────────────────────────────────────\n";
    
    $required = [
        'user_id' => 'Primary Key, auto-increment',
        'first_name' => "User's given name",
        'last_name' => "User's family name",
        'station_id' => 'Foreign Key to stations table',
        'email' => 'Unique, optional login identifier',
        'username' => 'Unique, optional login identifier',
        'phone_number' => 'Unique, optional login identifier',
        'password_hash' => 'Hashed password (bcrypt)',
        'role' => "ENUM ('SuperAdmin', 'Admin', 'Manager', 'Staff')",
        'status' => "ENUM ('Active', 'Locked', 'Disabled')",
        'created_at' => 'Timestamp of creation',
        'updated_at' => 'Timestamp of last update'
    ];
    
    foreach ($required as $field => $desc) {
        echo sprintf("%-20s : %s\n", $field, $desc);
    }
    
    echo "\n";
    echo "FIELD MAPPING (Current → Required):\n";
    echo "─────────────────────────────────────────────────────────\n";
    
    $mapping = [
        'id' => 'user_id (rename)',
        'first_name' => 'first_name ✓',
        'last_name' => 'last_name ✓',
        'station_id' => 'station_id ✓ (if exists)',
        'email' => 'email ✓ (if exists)',
        'username' => 'username ✓',
        'phone' => 'phone_number (rename)',
        'password' => 'password_hash (rename)',
        'role' => 'role ✓',
        'status' => 'status ✓ (if exists)',
        'created_at' => 'created_at ✓ (if exists)',
        'updated_at' => 'updated_at (add if missing)'
    ];
    
    $current_field_names = array_column($current_fields, 'Field');
    
    foreach ($mapping as $current => $target) {
        $exists = in_array($current, $current_field_names) ? '✓' : '✗';
        echo sprintf("[%s] %-20s → %s\n", $exists, $current, $target);
    }
    
    echo "\n";
    echo "ANALYSIS:\n";
    echo "─────────────────────────────────────────────────────────\n";
    
    // Check what needs to be done
    $needs_rename = [];
    $needs_add = [];
    $already_good = [];
    
    // Check for renames
    if (in_array('id', $current_field_names)) {
        $needs_rename[] = 'id → user_id';
    }
    if (in_array('phone', $current_field_names)) {
        $needs_rename[] = 'phone → phone_number';
    }
    if (in_array('password', $current_field_names)) {
        $needs_rename[] = 'password → password_hash';
    }
    
    // Check for adds
    if (!in_array('station_id', $current_field_names)) {
        $needs_add[] = 'station_id';
    }
    if (!in_array('email', $current_field_names)) {
        $needs_add[] = 'email';
    }
    if (!in_array('status', $current_field_names)) {
        $needs_add[] = 'status';
    }
    if (!in_array('created_at', $current_field_names)) {
        $needs_add[] = 'created_at';
    }
    if (!in_array('updated_at', $current_field_names)) {
        $needs_add[] = 'updated_at';
    }
    
    if (!empty($needs_rename)) {
        echo "\n⚠️  FIELDS TO RENAME:\n";
        foreach ($needs_rename as $rename) {
            echo "   - {$rename}\n";
        }
    }
    
    if (!empty($needs_add)) {
        echo "\n⚠️  FIELDS TO ADD:\n";
        foreach ($needs_add as $add) {
            echo "   - {$add}\n";
        }
    }
    
    if (empty($needs_rename) && empty($needs_add)) {
        echo "\n✅ Table structure matches requirements!\n";
    } else {
        echo "\n❌ Table structure needs updates!\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
?>
