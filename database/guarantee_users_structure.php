<?php
/**
 * GUARANTEE USERS TABLE STRUCTURE
 * This will ensure EXACTLY 12 fields, no more, no less
 * Run: http://localhost/group31petron_system_official4/database/guarantee_users_structure.php
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<!DOCTYPE html><html><head><title>Fix Users Table</title></head><body>";
echo "<h1>🔧 Fixing Users Table Structure</h1>";
echo "<pre>";

$success_count = 0;
$error_count = 0;

try {
    // Required fields
    $required = ['id', 'first_name', 'last_name', 'station_id', 'email', 
                 'username', 'phone_number', 'password_hash', 'role', 
                 'status', 'created_at', 'updated_at'];
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  STEP 1: Getting Current Structure\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $stmt = $pdo->query("DESCRIBE users");
    $fields_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_fields = array_column($fields_data, 'Field');
    
    echo "Current fields (" . count($current_fields) . "): " . implode(", ", $current_fields) . "\n\n";
    
    // Find extra fields to delete
    $extra = array_diff($current_fields, $required);
    
    if (!empty($extra)) {
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  STEP 2: Removing Extra Fields\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        
        foreach ($extra as $field) {
            try {
                echo "Dropping '$field'... ";
                $pdo->exec("ALTER TABLE `users` DROP COLUMN `$field`");
                echo "✓ SUCCESS\n";
                $success_count++;
            } catch (PDOException $e) {
                echo "✗ SKIP (already removed or error)\n";
            }
        }
        echo "\n";
    }
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  STEP 3: Renaming Fields\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    // Rename phone to phone_number
    if (in_array('phone', $current_fields)) {
        try {
            echo "Renaming 'phone' → 'phone_number'... ";
            $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL");
            echo "✓ SUCCESS\n";
            $success_count++;
        } catch (PDOException $e) {
            echo "✗ ERROR: " . $e->getMessage() . "\n";
            $error_count++;
        }
    } else {
        echo "'phone' → 'phone_number': Already renamed ✓\n";
    }
    
    // Rename password to password_hash
    if (in_array('password', $current_fields)) {
        try {
            echo "Renaming 'password' → 'password_hash'... ";
            $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL");
            echo "✓ SUCCESS\n";
            $success_count++;
        } catch (PDOException $e) {
            echo "✗ ERROR: " . $e->getMessage() . "\n";
            $error_count++;
        }
    } else {
        echo "'password' → 'password_hash': Already renamed ✓\n";
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  STEP 4: Updating Field Types\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $updates = [
        "ALTER TABLE `users` MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL" => "first_name type",
        "ALTER TABLE `users` MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL" => "last_name type",
        "ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL" => "username type",
        "ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff'" => "role ENUM",
        "ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active'" => "status ENUM",
        "ALTER TABLE `users` MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" => "created_at type",
    ];
    
    foreach ($updates as $sql => $desc) {
        try {
            echo "Updating $desc... ";
            $pdo->exec($sql);
            echo "✓ SUCCESS\n";
            $success_count++;
        } catch (PDOException $e) {
            echo "✗ SKIP\n";
        }
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  STEP 5: Final Structure\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $stmt = $pdo->query("DESCRIBE users");
    $final_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $final_names = array_column($final_fields, 'Field');
    
    printf("%-20s %-30s %-10s %-15s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 80) . "\n";
    foreach ($final_fields as $field) {
        $status = in_array($field['Field'], $required) ? "✓" : "✗";
        printf("%s %-18s %-30s %-10s %-15s\n", 
            $status,
            $field['Field'], 
            substr($field['Type'], 0, 30),
            $field['Null'], 
            $field['Key']
        );
    }
    
    echo "\n";
    echo "Total fields: " . count($final_fields) . "\n\n";
    
    // Check if we have exactly 12 required fields
    $missing = array_diff($required, $final_names);
    $extra_still = array_diff($final_names, $required);
    
    if (empty($missing) && empty($extra_still)) {
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  ✅ PERFECT! Users table has exactly 12 fields!\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        echo "All required fields present:\n";
        foreach ($required as $field) {
            echo "  ✓ $field\n";
        }
    } else {
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  ⚠️  Structure needs more work\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        
        if (!empty($missing)) {
            echo "Missing fields:\n";
            foreach ($missing as $field) {
                echo "  ✗ $field\n";
            }
            echo "\n";
        }
        
        if (!empty($extra_still)) {
            echo "Extra fields that should be removed:\n";
            foreach ($extra_still as $field) {
                echo "  ⚠ $field\n";
            }
            echo "\n";
        }
    }
    
    echo "\nOperations: $success_count successful, $error_count errors\n\n";
    
} catch (PDOException $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n\n";
}

echo "</pre></body></html>";
?>
