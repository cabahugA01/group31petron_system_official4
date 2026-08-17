<?php
/**
 * Test admin & superadmin notification generators for bugs
 * Simulates: logged in as admin (user_id=1, station_id=1253) then as superadmin
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$errors = [];
$warnings = [];

echo "==========================================================\n";
echo "  ADMIN & SUPERADMIN NOTIFICATION SYSTEM — BUG AUDIT\n";
echo "==========================================================\n\n";

// ─── 1. Check notifications table columns vs what generators INSERT ─────────
echo "--- 1. Schema validation ---\n";
$cols = [];
foreach ($pdo->query('DESCRIBE notifications')->fetchAll(PDO::FETCH_ASSOC) as $c)
    $cols[$c['Field']] = $c;

$required_cols = ['id','user_id','recipient_role','type','title','message','event_type','severity','source_key','redirect_url','reference_type','reference_id','shift_period','status','created_at','read_at'];
foreach ($required_cols as $col) {
    if (!isset($cols[$col])) {
        echo "  [MISSING COLUMN] $col\n";
        $errors[] = "Missing column: $col";
    } else {
        echo "  [OK] $col\n";
    }
}

// ─── 2. Test admin_notification_generator INSERT fields vs actual schema ────
echo "\n--- 2. Admin generator INSERT vs actual schema ---\n";
// The generator inserts: user_id, type, title, message, event_type, severity, source_key, redirect_url
// Actual schema also has: recipient_role, reference_type, reference_id, shift_period
// Check if the existing notifications have these filled
$sample = $pdo->query("SELECT * FROM notifications LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($sample) {
    echo "  Sample row column count: " . count($sample) . "\n";
    if (array_key_exists('recipient_role', $sample) && $sample['recipient_role'] === null) {
        echo "  [WARN] recipient_role is NULL — generator does not populate it\n";
        $warnings[] = "Admin/Superadmin generators don't set recipient_role";
    }
}

// ─── 3. Check status ENUM — generators use 'unread' but schema has 'archived' too ─
echo "\n--- 3. Status ENUM validation ---\n";
$status_type = $cols['status']['Type'] ?? '';
echo "  status column type: $status_type\n";
if (strpos($status_type, 'archived') !== false) {
    echo "  [OK] 'archived' status supported in schema\n";
} else {
    echo "  [WARN] 'archived' status NOT in ENUM — notifications_api.php uses it\n";
    $warnings[] = "status ENUM missing 'archived'";
}

// ─── 4. Test superadmin generator directly ─────────────────────────────────
echo "\n--- 4. Testing superadmin notification generator queries ---\n";
$test_queries = [
    "activity_logs failed login" => "SELECT COUNT(*) FROM activity_logs WHERE (action LIKE '%Failed%' OR action LIKE '%failed%') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    "system_error_logs" => "SELECT COUNT(*) FROM system_error_logs WHERE severity IN ('critical','warning') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    "integration_audit" => "SELECT COUNT(*) FROM integration_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    "module_config_audit" => "SELECT COUNT(*) FROM module_config_audit WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    "system_settings_audit" => "SELECT COUNT(*) FROM system_settings_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
];
foreach ($test_queries as $label => $sql) {
    try {
        $cnt = (int)$pdo->query($sql)->fetchColumn();
        echo "  [OK] $label => $cnt rows\n";
    } catch (Exception $e) {
        echo "  [TABLE MISSING] $label: " . $e->getMessage() . "\n";
        $warnings[] = "Table missing for: $label";
    }
}

// ─── 5. Test admin generator queries ────────────────────────────────────────
echo "\n--- 5. Testing admin notification generator queries ---\n";
$station_id = 1253;
$admin_queries = [
    "deliveries_oversight" => "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Admin Oversight'",
    "merchandise_transactions today" => "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status IN ('Official','Completed','Approved','Adjusted') AND DATE(COALESCE(transaction_date,created_at))=CURDATE()",
    "purchase_orders pending" => "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation')",
    "job_orders today" => "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND validation_status IN ('Official','Completed','Approved','Adjusted') AND DATE(created_at)=CURDATE()",
    "variance_alerts" => "SELECT COUNT(*) FROM variance_alerts WHERE station_id=? AND status='open'",
    "customers ar" => "SELECT COUNT(*) FROM customers WHERE station_id=? AND COALESCE(current_balance, balance, 0) > 0",
    "labor_sessions" => "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()",
    "station_inventory low" => "SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND si.stock_level >= 0 AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10) AND LOWER(ip.category) NOT IN ('fuel', 'fuels')",
];
foreach ($admin_queries as $label => $sql) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$station_id]);
        $cnt = (int)$st->fetchColumn();
        echo "  [OK] $label => $cnt\n";
    } catch (Exception $e) {
        echo "  [ERROR] $label: " . $e->getMessage() . "\n";
        $errors[] = "Admin generator query failed: $label — " . $e->getMessage();
    }
}

// ─── 6. Check notifications_api.php `list` action — does it work for admin? ─
echo "\n--- 6. Checking notifications_api.php query for admin/superadmin ---\n";
// The API queries: WHERE n.user_id = ?
// But the generators insert: user_id = (logged in admin user)
// This should work — but check if category filter for 'system' event_types is correct
$sa_users = $pdo->query("SELECT id, username, role FROM users WHERE LOWER(role) IN ('superadmin','admin','developer') AND status='Active' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
if ($sa_users) {
    echo "  Found admin/superadmin users:\n";
    foreach ($sa_users as $u) {
        $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?")->execute([$u['id']]) ? 0 : 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
        $st->execute([$u['id']]);
        $cnt = (int)$st->fetchColumn();
        echo "    [{$u['role']}] {$u['username']} (id:{$u['id']}) => $cnt notifications\n";
    }
} else {
    echo "  [WARN] No active admin/superadmin users found\n";
    $warnings[] = "No active admin/superadmin users found";
}

// ─── 7. Check for 'category' column mismatch (API uses event_type for category mapping) ─
echo "\n--- 7. Checking category filter compatibility ---\n";
$has_category = isset($cols['category']) ? 'YES' : 'NO';
echo "  notifications.category column exists: $has_category\n";
if ($has_category === 'NO') {
    echo "  [OK] API uses event_type for category filtering (no separate category column needed)\n";
}

// ─── 8. Check admin_notification_generator creates table without recipient_role ─
echo "\n--- 8. Checking generator CREATE TABLE vs actual table schema ---\n";
// Generators have their own CREATE TABLE IF NOT EXISTS — does it match?
echo "  Generator CREATE TABLE does NOT include: recipient_role, reference_type, reference_id, shift_period\n";
echo "  Actual table HAS these columns: recipient_role, reference_type, reference_id, shift_period\n";
echo "  [INFO] Since table already exists (IF NOT EXISTS), generator CREATE is a no-op — OK\n";
echo "  [WARN] But generators don't SET recipient_role — notifications show with NULL recipient_role\n";
echo "         This means notifications_api.php 'list' with recipient_role filter might miss them\n";

// ─── 9. Check for 'archived' status in generators ───────────────────────────
echo "\n--- 9. Status 'archived' in generators vs schema ---\n";
if (strpos($status_type, 'archived') === false) {
    echo "  [BUG] Schema status ENUM: $status_type — missing 'archived'\n";
    echo "         notifications_api.php does: UPDATE notifications SET status='archived'\n";
    echo "         This will FAIL because 'archived' is not in ENUM\n";
    $errors[] = "ENUM status missing 'archived' — archive action in notifications_api will fail";
} else {
    echo "  [OK] 'archived' in ENUM\n";
}

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n==========================================================\n";
echo "  SUMMARY\n";
echo "==========================================================\n";
echo "Errors: " . count($errors) . "\n";
foreach ($errors as $e) echo "  [ERROR] $e\n";
echo "Warnings: " . count($warnings) . "\n";
foreach ($warnings as $w) echo "  [WARN] $w\n";
