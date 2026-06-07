<?php
/**
 * AUTO UPDATE USERS TABLE
 * This script will clean up and standardize the users table
 * Run this ONCE: http://localhost/group31petron_system_official4/database/auto_update_users.php
 */

// Prevent multiple executions
$lock_file = __DIR__ . '/users_updated.lock';
if (file_exists($lock_file)) {
    die("
    <h2>⚠️ Users table already updated!</h2>
    <p>This script has already been executed on: " . file_get_contents($lock_file) . "</p>
    <p>If you need to run it again, delete the file: <code>database/users_updated.lock</code></p>
    ");
}

require_once __DIR__ . '/../public/db_connect.php';

echo "<html><head><title>Update Users Table</title></head><body>";
echo "<h1>🔄 Updating Users Table Structure</h1>";
echo "<pre>";

try {
    $pdo->beginTransaction();
    
    echo "Starting update process...\n\n";
    
    // Get current structure
    $stmt = $pdo->query("DESCRIBE users");
    $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Current fields: " . implode(", ", $fields) . "\n\n";
    echo "─────────────────────────────────────────────\n\n";
    
    // STEP 1: Drop unnecessary columns
    echo "STEP 1: Removing unnecessary fields...\n";
    
    $to_drop = ['emp_id', 'hourly_rate', 'must_change_password', 'force_password_reset', 
                'is_deleted', 'deleted_at', 'deleted_by', 'remarks', 'profile_picture', 'name'];
    
    foreach ($to_drop as $col) {
        if (in_array($col, $fields)) {
            echo "  ✓ Dropping '$col'... ";
            $pdo->exec("ALTER TABLE `users` DROP COLUMN `$col`");
            echo "DONE\n";
        } else {
            echo "  - '$col' not found (skip)\n";
        }
    }
    
    echo "\n";
    
    // STEP 2: Rename columns
    echo "STEP 2: Renaming columns...\n";
    
    if (in_array('phone', $fields)) {
        echo "  ✓ Renaming 'phone' to 'phone_number'... ";
        $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `phone` `phone_number` VARCHAR(20) NULL");
        echo "DONE\n";
    } else {
        echo "  - 'phone' already renamed or doesn't exist\n";
    }
    
    if (in_array('password', $fields)) {
        echo "  ✓ Renaming 'password' to 'password_hash'... ";
        $pdo->exec("ALTER TABLE `users` CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL");
        echo "DONE\n";
    } else {
        echo "  - 'password' already renamed or doesn't exist\n";
    }
    
    echo "\n";
    
    // STEP 3: Update field types
    echo "STEP 3: Updating field types...\n";
    
    echo "  ✓ Updating 'first_name'... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `first_name` VARCHAR(100) NOT NULL");
    echo "DONE\n";
    
    echo "  ✓ Updating 'last_name'... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `last_name` VARCHAR(100) NOT NULL");
    echo "DONE\n";
    
    echo "  ✓ Updating 'username'... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `username` VARCHAR(100) NOT NULL");
    echo "DONE\n";
    
    echo "  ✓ Updating 'role' to ENUM... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff'");
    echo "DONE\n";
    
    echo "  ✓ Updating 'status' to ENUM... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active'");
    echo "DONE\n";
    
    echo "  ✓ Updating 'created_at'... ";
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    echo "DONE\n";
    
    echo "\n";
    
    // STEP 4: Add unique indexes
    echo "STEP 4: Adding indexes...\n";
    
    try {
        $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `uk_username` (`username`)");
        echo "  ✓ Added unique index on 'username'\n";
    } catch (PDOException $e) {
        echo "  - Index 'uk_username' already exists\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `uk_email` (`email`)");
        echo "  ✓ Added unique index on 'email'\n";
    } catch (PDOException $e) {
        echo "  - Index 'uk_email' already exists\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE `users` ADD UNIQUE INDEX `uk_phone_number` (`phone_number`)");
        echo "  ✓ Added unique index on 'phone_number'\n";
    } catch (PDOException $e) {
        echo "  - Index 'uk_phone_number' already exists\n";
    }
    
    echo "\n";
    echo "─────────────────────────────────────────────\n\n";
    
    // Get final structure
    $stmt = $pdo->query("DESCRIBE users");
    $final_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "FINAL STRUCTURE:\n\n";
    printf("%-20s %-30s %-10s %-15s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($final_fields as $field) {
        printf("%-20s %-30s %-10s %-15s\n", 
            $field['Field'], 
            $field['Type'], 
            $field['Null'], 
            $field['Key']
        );
    }
    
    echo "\n";
    echo "─────────────────────────────────────────────\n\n";
    
    // Commit changes
    $pdo->commit();
    
    // Create lock file
    file_put_contents($lock_file, date('Y-m-d H:i:s'));
    
    echo "✅ SUCCESS! Users table has been updated!\n\n";
    echo "Final fields (" . count($final_fields) . "):\n";
    foreach ($final_fields as $field) {
        echo "  • " . $field['Field'] . "\n";
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  USERS TABLE UPDATE COMPLETE!\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    echo "You can now close this window.\n";
    echo "The script has been locked to prevent re-execution.\n\n";
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "No changes were made to the database.\n";
}

echo "</pre></body></html>";
?>
