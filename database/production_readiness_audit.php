<?php
/**
 * PRODUCTION READINESS AUDIT
 * Comprehensive check for Product & Pricing Management System
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║         PRODUCT & PRICING MANAGEMENT - PRODUCTION AUDIT           ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$errors = [];
$warnings = [];
$passed = 0;
$total = 0;

// ═══ 1. DATABASE STRUCTURE CHECKS ═══
echo "═══ DATABASE STRUCTURE CHECKS ═══\n";

$total++;
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $required_tables = ['fuel_inventory', 'inventory_products', 'job_order_service_types', 'pending_price_approvals', 'stations', 'users'];
    $missing = array_diff($required_tables, $tables);
    
    if (empty($missing)) {
        echo "  ✓ All required tables exist\n";
        $passed++;
    } else {
        $errors[] = "Missing tables: " . implode(', ', $missing);
        echo "  ✗ Missing tables: " . implode(', ', $missing) . "\n";
    }
} catch (Exception $e) {
    $errors[] = "Database check failed: " . $e->getMessage();
    echo "  ✗ Database check failed\n";
}

// Check foreign key constraints
$total++;
try {
    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pending_price_approvals' 
        AND COLUMN_NAME = 'product_id'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($fks)) {
        echo "  ✓ No problematic FK constraints on product_id\n";
        $passed++;
    } else {
        $errors[] = "Foreign key constraint on product_id exists (will cause errors with polymorphic references)";
        echo "  ✗ FK constraint on product_id found (ISSUE!)\n";
    }
} catch (Exception $e) {
    $warnings[] = "Could not check FK constraints: " . $e->getMessage();
    echo "  ! Could not check FK constraints\n";
}

// ═══ 2. DATA INTEGRITY CHECKS ═══
echo "\n═══ DATA INTEGRITY CHECKS ═══\n";

// Check service types
$total++;
try {
    $serviceCount = $pdo->query("SELECT COUNT(*) FROM job_order_service_types WHERE active = 1")->fetchColumn();
    if ($serviceCount > 0) {
        echo "  ✓ Service types loaded: {$serviceCount} services\n";
        $passed++;
    } else {
        $errors[] = "No active service types found!";
        echo "  ✗ No active service types!\n";
    }
} catch (Exception $e) {
    $errors[] = "Could not check service types: " . $e->getMessage();
    echo "  ✗ Service types check failed\n";
}

// Check for orphaned pending approvals
$total++;
try {
    // Fuel orphans
    $orphanedFuel = $pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals p
        LEFT JOIN fuel_inventory f ON p.product_id = f.id
        WHERE p.product_type IN ('fuel', 'fuel_inventory')
        AND f.id IS NULL
        AND p.status = 'pending'
    ")->fetchColumn();
    
    // Merchandise orphans
    $orphanedMerch = $pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals p
        LEFT JOIN inventory_products i ON p.product_id = i.id
        WHERE p.product_type = 'merchandise'
        AND i.id IS NULL
        AND p.status = 'pending'
    ")->fetchColumn();
    
    // Service orphans
    $orphanedService = $pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals p
        LEFT JOIN job_order_service_types s ON p.product_id = s.id
        WHERE p.product_type = 'service_type'
        AND s.id IS NULL
        AND p.status = 'pending'
    ")->fetchColumn();
    
    $totalOrphaned = $orphanedFuel + $orphanedMerch + $orphanedService;
    
    if ($totalOrphaned == 0) {
        echo "  ✓ No orphaned pending approvals\n";
        $passed++;
    } else {
        $warnings[] = "Found {$totalOrphaned} orphaned pending approvals (fuel: {$orphanedFuel}, merch: {$orphanedMerch}, service: {$orphanedService})";
        echo "  ! Found {$totalOrphaned} orphaned pending approvals\n";
    }
} catch (Exception $e) {
    $warnings[] = "Could not check orphaned records: " . $e->getMessage();
    echo "  ! Could not check orphaned records\n";
}

// ═══ 3. CODE QUALITY CHECKS ═══
echo "\n═══ CODE QUALITY CHECKS ═══\n";

// Check manager_set_prices.php
$total++;
$managerFile = __DIR__ . '/../public/manager_set_prices.php';
if (file_exists($managerFile)) {
    $content = file_get_contents($managerFile);
    
    // Check if fetching from database
    if (strpos($content, 'FROM fuel_inventory') !== false && 
        strpos($content, 'FROM inventory_products') !== false &&
        strpos($content, 'FROM job_order_service_types') !== false) {
        echo "  ✓ manager_set_prices.php loads data from database\n";
        $passed++;
    } else {
        $errors[] = "manager_set_prices.php may not be loading all data from database";
        echo "  ✗ manager_set_prices.php database queries issue\n";
    }
    
    // Check for hardcoded arrays
    $total++;
    $hardcoded = preg_match('/\$fuel_products\s*=\s*\[\s*["\']/', $content) ||
                 preg_match('/\$merch_products\s*=\s*\[\s*["\']/', $content) ||
                 preg_match('/\$service_types\s*=\s*\[\s*["\']/', $content);
    
    if (!$hardcoded) {
        echo "  ✓ No hardcoded product arrays in manager_set_prices.php\n";
        $passed++;
    } else {
        $errors[] = "Found hardcoded product arrays in manager_set_prices.php";
        echo "  ✗ Hardcoded arrays found!\n";
    }
} else {
    $errors[] = "manager_set_prices.php not found";
    echo "  ✗ manager_set_prices.php not found\n";
    $total++;
}

// Check admin_set_prices.php
$total++;
$adminFile = __DIR__ . '/../public/admin_set_prices.php';
if (file_exists($adminFile)) {
    $content = file_get_contents($adminFile);
    
    // Check if fetching from database
    if (strpos($content, 'FROM fuel_inventory') !== false && 
        strpos($content, 'FROM inventory_products') !== false &&
        strpos($content, 'FROM job_order_service_types') !== false) {
        echo "  ✓ admin_set_prices.php loads data from database\n";
        $passed++;
    } else {
        $errors[] = "admin_set_prices.php may not be loading all data from database";
        echo "  ✗ admin_set_prices.php database queries issue\n";
    }
    
    // Check for hardcoded arrays
    $total++;
    $hardcoded = preg_match('/\$fuel_products\s*=\s*\[\s*["\']/', $content) ||
                 preg_match('/\$merch_products\s*=\s*\[\s*["\']/', $content) ||
                 preg_match('/\$service_types\s*=\s*\[\s*["\']/', $content);
    
    if (!$hardcoded) {
        echo "  ✓ No hardcoded product arrays in admin_set_prices.php\n";
        $passed++;
    } else {
        $errors[] = "Found hardcoded product arrays in admin_set_prices.php";
        echo "  ✗ Hardcoded arrays found!\n";
    }
} else {
    $errors[] = "admin_set_prices.php not found";
    echo "  ✗ admin_set_prices.php not found\n";
    $total++;
}

// ═══ 4. FUNCTIONALITY CHECKS ═══
echo "\n═══ FUNCTIONALITY CHECKS ═══\n";

// Check if products exist
$total++;
try {
    $fuelCount = $pdo->query("SELECT COUNT(*) FROM fuel_inventory WHERE status = 'active'")->fetchColumn();
    $merchCount = $pdo->query("SELECT COUNT(*) FROM inventory_products WHERE LOWER(COALESCE(status,'active')) != 'inactive'")->fetchColumn();
    $serviceCount = $pdo->query("SELECT COUNT(*) FROM job_order_service_types WHERE active = 1")->fetchColumn();
    
    if ($fuelCount > 0 || $merchCount > 0 || $serviceCount > 0) {
        echo "  ✓ Products exist (Fuel: {$fuelCount}, Merch: {$merchCount}, Services: {$serviceCount})\n";
        $passed++;
    } else {
        $warnings[] = "No products found in any category";
        echo "  ! No products found\n";
    }
} catch (Exception $e) {
    $errors[] = "Could not check products: " . $e->getMessage();
    echo "  ✗ Product check failed\n";
}

// Check pending approvals functionality
$total++;
try {
    $pendingCount = $pdo->query("SELECT COUNT(*) FROM pending_price_approvals WHERE status = 'pending'")->fetchColumn();
    echo "  ✓ Pending approvals table working ({$pendingCount} pending)\n";
    $passed++;
} catch (Exception $e) {
    $errors[] = "Pending approvals table issue: " . $e->getMessage();
    echo "  ✗ Pending approvals check failed\n";
}

// ═══ 5. SECURITY CHECKS ═══
echo "\n═══ SECURITY CHECKS ═══\n";

// Check if files have access control
$total++;
$managerContent = file_exists($managerFile) ? file_get_contents($managerFile) : '';
$adminContent = file_exists($adminFile) ? file_get_contents($adminFile) : '';

if (strpos($managerContent, "if (\$role !== 'manager')") !== false ||
    strpos($managerContent, "require_login()") !== false) {
    echo "  ✓ manager_set_prices.php has access control\n";
    $passed++;
} else {
    $warnings[] = "manager_set_prices.php may lack proper access control";
    echo "  ! Access control check unclear for manager file\n";
}

$total++;
if (strpos($adminContent, "in_array(\$role, ['admin', 'superadmin'])") !== false ||
    strpos($adminContent, "require_login()") !== false) {
    echo "  ✓ admin_set_prices.php has access control\n";
    $passed++;
} else {
    $warnings[] = "admin_set_prices.php may lack proper access control";
    echo "  ! Access control check unclear for admin file\n";
}

// ═══ SUMMARY ═══
echo "\n╔════════════════════════════════════════════════════════════════════╗\n";
echo "║                          AUDIT SUMMARY                             ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

$percentage = $total > 0 ? round(($passed / $total) * 100) : 0;

echo "Tests Passed: {$passed} / {$total} ({$percentage}%)\n";
echo "Errors: " . count($errors) . "\n";
echo "Warnings: " . count($warnings) . "\n\n";

if (!empty($errors)) {
    echo "═══ ERRORS (MUST FIX) ═══\n";
    foreach ($errors as $i => $error) {
        echo "  " . ($i + 1) . ". {$error}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "═══ WARNINGS (REVIEW) ═══\n";
    foreach ($warnings as $i => $warning) {
        echo "  " . ($i + 1) . ". {$warning}\n";
    }
    echo "\n";
}

// Final verdict
echo "═══ PRODUCTION READINESS ═══\n";
if (count($errors) == 0 && $percentage >= 90) {
    echo "  ✓✓✓ READY FOR PRODUCTION ✓✓✓\n";
    echo "  System is database-driven and ready for use.\n";
    exit(0);
} elseif (count($errors) == 0 && $percentage >= 75) {
    echo "  ⚠ MOSTLY READY - Review warnings\n";
    echo "  System will work but has some warnings to address.\n";
    exit(0);
} else {
    echo "  ✗ NOT READY - Fix errors first\n";
    echo "  Critical issues found. Fix before deploying.\n";
    exit(1);
}
?>
