<?php
/**
 * Safe Users Table Migration Script
 * Migrates from old schema to new standardized schema
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     USERS TABLE MIGRATION TO PROPER SCHEMA                 ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

try {
    $pdo->beginTransaction();
    
    // Step 1: Check if backup already exists
    $checkBackup = $pdo->query("SHOW TABLES LIKE 'users_backup_old'");
    if ($checkBackup->rowCount() > 0) {
        throw new Exception("Backup table 'users_backup_old' already exists! Migration may have been run before.");
    }
    
    // Step 2: Get current data before migration
    $oldData = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "📊 Found " . count($oldData) . " users to migrate\n\n";
    
    // Step 3: Rename old table
    echo "🔄 Step 1: Backing up current users table...\n";
    $pdo->exec("ALTER TABLE `users` RENAME TO `users_backup_old`");
    echo "   ✅ Backup created: users_backup_old\n\n";
    
    // Step 4: Create new table with proper schema
    echo "🔄 Step 2: Creating new users table with proper schema...\n";
    $createSQL = "
    CREATE TABLE `users` (
      `user_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `first_name` VARCHAR(100) NOT NULL,
      `last_name` VARCHAR(100) NOT NULL,
      `station_id` INT(11) DEFAULT NULL,
      `email` VARCHAR(255) DEFAULT NULL UNIQUE,
      `username` VARCHAR(100) DEFAULT NULL UNIQUE,
      `phone_number` VARCHAR(15) DEFAULT NULL UNIQUE,
      `password_hash` VARCHAR(255) NOT NULL,
      `role` ENUM('SuperAdmin', 'Admin', 'Manager', 'Staff') NOT NULL DEFAULT 'Staff',
      `status` ENUM('Active', 'Locked', 'Disabled') NOT NULL DEFAULT 'Active',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `is_deleted` TINYINT(1) DEFAULT 0,
      INDEX `idx_email` (`email`),
      INDEX `idx_username` (`username`),
      INDEX `idx_phone` (`phone_number`),
      INDEX `idx_station` (`station_id`),
      INDEX `idx_role` (`role`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($createSQL);
    echo "   ✅ New table structure created\n\n";
    
    // Step 5: Migrate data
    echo "🔄 Step 3: Migrating user data...\n";
    $insertSQL = "
    INSERT INTO `users` (
        `user_id`,
        `first_name`,
        `last_name`,
        `station_id`,
        `email`,
        `username`,
        `phone_number`,
        `password_hash`,
        `role`,
        `status`,
        `created_at`,
        `updated_at`,
        `is_deleted`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($insertSQL);
    $migrated = 0;
    
    foreach ($oldData as $user) {
        // Parse names from existing data or use defaults
        $firstName = $user['first_name'] ?? 'Unknown';
        $lastName = $user['last_name'] ?? 'User';
        
        // Map role to proper ENUM
        $role = 'Staff';
        switch (strtolower($user['role'] ?? 'staff')) {
            case 'superadmin':
                $role = 'SuperAdmin';
                break;
            case 'admin':
                $role = 'Admin';
                break;
            case 'manager':
                $role = 'Manager';
                break;
            case 'staff':
                $role = 'Staff';
                break;
        }
        
        // Map status to proper ENUM
        $status = 'Active';
        switch (strtolower($user['status'] ?? 'active')) {
            case 'active':
                $status = 'Active';
                break;
            case 'inactive':
            case 'disabled':
                $status = 'Disabled';
                break;
            case 'locked':
                $status = 'Locked';
                break;
        }
        
        $stmt->execute([
            $user['id'],
            $firstName,
            $lastName,
            $user['station_id'] ?? null,
            $user['email'] ?? null,
            $user['username'] ?? null,
            $user['phone'] ?? null,
            $user['password'],
            $role,
            $status,
            $user['created_at'] ?? date('Y-m-d H:i:s'),
            $user['updated_at'] ?? date('Y-m-d H:i:s'),
            $user['is_deleted'] ?? 0
        ]);
        
        $migrated++;
        echo "   ✅ Migrated: " . ($user['username'] ?? $user['email'] ?? 'User ' . $user['id']) . " ($role)\n";
    }
    
    echo "\n✅ Successfully migrated $migrated users\n\n";
    
    // Step 6: Verify migration
    echo "🔍 Verification:\n";
    $newCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $oldCount = $pdo->query("SELECT COUNT(*) FROM users_backup_old")->fetchColumn();
    
    echo "   Old table count: $oldCount\n";
    echo "   New table count: $newCount\n";
    
    if ($newCount === $oldCount) {
        echo "   ✅ Counts match!\n\n";
    } else {
        throw new Exception("Migration count mismatch! Old: $oldCount, New: $newCount");
    }
    
    // Step 7: Show final structure
    echo "═══════════════════════════════════════════════════════════\n";
    echo "📋 MIGRATED USERS:\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $users = $pdo->query("SELECT user_id, first_name, last_name, email, username, phone_number, role, status FROM users ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        echo "👤 User ID: {$user['user_id']} | Role: {$user['role']} | Status: {$user['status']}\n";
        echo "   Name: {$user['first_name']} {$user['last_name']}\n";
        echo "   Email: " . ($user['email'] ?? 'NULL') . "\n";
        echo "   Username: " . ($user['username'] ?? 'NULL') . "\n";
        echo "   Phone: " . ($user['phone_number'] ?? 'NULL') . "\n";
        echo "   ---\n";
    }
    
    $pdo->commit();
    
    echo "\n╔════════════════════════════════════════════════════════════╗\n";
    echo "║           ✅ MIGRATION COMPLETED SUCCESSFULLY!             ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";
    
    echo "ℹ️  IMPORTANT NOTES:\n";
    echo "   • Old table backed up as: users_backup_old\n";
    echo "   • New table uses: user_id (not 'id')\n";
    echo "   • Phone field renamed to: phone_number\n";
    echo "   • Password field renamed to: password_hash\n";
    echo "   • You may need to update code references\n\n";
    
    echo "⚠️  TO ROLLBACK (if needed):\n";
    echo "   DROP TABLE users;\n";
    echo "   ALTER TABLE users_backup_old RENAME TO users;\n\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\n🔄 Rolling back changes...\n";
    
    // Try to restore from backup if it exists
    try {
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("ALTER TABLE users_backup_old RENAME TO users");
        echo "✅ Rollback successful. Original table restored.\n";
    } catch (Exception $rollbackError) {
        echo "❌ Rollback failed: " . $rollbackError->getMessage() . "\n";
    }
}
?>
