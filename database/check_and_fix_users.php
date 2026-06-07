<?php
/**
 * CHECK AND FIX USERS TABLE
 * This will check current structure and fix what's needed
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "<html><head><title>Check & Fix Users Table</title></head><body>";
echo "<h1>🔍 Check & Fix Users Table</h1>";
echo "<pre>";

try {
    // Get current structure
    $stmt = $pdo->query("DESCRIBE users");
    $fields_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $fields = array_column($fields_data, 'Field');
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  CURRENT STRUCTURE\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    printf("%-20s %-30s %-10s %-15s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 80) . "\n";
    foreach ($fields_data as $field) {
        printf("%-20s %-30s %-10s %-15s\n", 
            $field['Field'], 
            $field['Type'], 
            $field['Null'], 
            $field['Key']
        );
    }
    
    echo "\n";
    echo "Total fields: " . count($fields) . "\n\n";
    
    // Required fields
    $required = [
        'id', 'first_name', 'last_name', 'station_id', 'email', 
        'username', 'phone_number', 'password_hash', 'role', 
        'status', 'created_at', 'updated_at'
    ];
    
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  CHECKING REQUIRED FIELDS\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $has_all = true;
    foreach ($required as $req) {
        if (in_array($req, $fields)) {
            echo "✓ $req - EXISTS\n";
        } else {
            echo "✗ $req - MISSING\n";
            $has_all = false;
        }
    }
    
    echo "\n";
    
    // Extra fields that should be removed
    $unwanted = array_diff($fields, $required);
    
    if (!empty($unwanted)) {
        echo "═══════════════════════════════════════════════════════════\n";
        echo "  EXTRA FIELDS TO REMOVE\n";
        echo "═══════════════════════════════════════════════════════════\n\n";
        
        foreach ($unwanted as $extra) {
            echo "⚠ $extra - Should be removed\n";
        }
        echo "\n";
    }
    
    // Check if need to rename
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  FIELDS THAT NEED RENAMING\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    $needs_rename = false;
    
    if (in_array('phone', $fields) && !in_array('phone_number', $fields)) {
        echo "⚠ 'phone' should be renamed to 'phone_number'\n";
        $needs_rename = true;
    }
    
    if (in_array('password', $fields) && !in_array('password_hash', $fields)) {
        echo "⚠ 'password' should be renamed to 'password_hash'\n";
        $needs_rename = true;
    }
    
    if (!$needs_rename && $has_all && empty($unwanted)) {
        echo "✓ No renaming needed\n";
    }
    
    echo "\n";
    
    // Summary
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  SUMMARY\n";
    echo "═══════════════════════════════════════════════════════════\n\n";
    
    if ($has_all && empty($unwanted) && !$needs_rename) {
        echo "✅ PERFECT! Your users table has the correct structure!\n";
        echo "   - All 12 required fields exist\n";
        echo "   - No extra fields\n";
        echo "   - Correct naming\n\n";
    } else {
        echo "⚠️  Your users table needs updates:\n\n";
        
        if (!$has_all) {
            echo "   - Some required fields are missing\n";
        }
        if (!empty($unwanted)) {
            echo "   - " . count($unwanted) . " extra fields should be removed\n";
        }
        if ($needs_rename) {
            echo "   - Some fields need to be renamed\n";
        }
        
        echo "\n";
        echo "Run the SQL commands in: database/RUN_THIS_PHPMYADMIN.sql\n";
        echo "Or manually fix in phpMyAdmin\n\n";
    }
    
    echo "═══════════════════════════════════════════════════════════\n\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "</pre></body></html>";
?>
