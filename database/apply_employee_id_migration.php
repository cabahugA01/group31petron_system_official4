<?php
/**
 * Migration: Ensure employee_id column exists on users table and backfill existing users
 */
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "Starting migration: Verify and apply employee_id...\n";

    // 1. Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
    $col = $stmt->fetch();

    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL AFTER id");
        echo "✓ Column 'employee_id' added to users table.\n";
    } else {
        echo "✓ Column 'employee_id' already exists.\n";
    }

    // 2. Backfill any user that does not have an employee_id
    $users = $pdo->query("SELECT id, role, employee_id FROM users WHERE employee_id IS NULL OR employee_id = '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($users)) {
        echo "Found " . count($users) . " user(s) needing employee_id backfill.\n";
        
        $updateStmt = $pdo->prepare("UPDATE users SET employee_id = ? WHERE id = ?");
        
        foreach ($users as $u) {
            $role = strtolower(trim((string)($u['role'] ?? 'staff')));
            $prefix = 'STF';
            if ($role === 'superadmin') $prefix = 'SA';
            elseif ($role === 'admin') $prefix = 'ADM';
            elseif ($role === 'manager') $prefix = 'MGR';

            // Find highest current sequence for this prefix
            $seqStmt = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ? ORDER BY employee_id DESC LIMIT 1");
            $seqStmt->execute([$prefix . '-%']);
            $last_id = $seqStmt->fetchColumn();

            $num = 1;
            if ($last_id) {
                $parts = explode('-', $last_id);
                $num = ((int)end($parts)) + 1;
            }

            $newEmpId = $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
            $updateStmt->execute([$newEmpId, $u['id']]);
            echo "  Assigned {$newEmpId} to User ID #{$u['id']} ({$role})\n";
        }
    } else {
        echo "✓ All users already have valid employee_id assigned.\n";
    }

    echo "\nDone!\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
