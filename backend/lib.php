<?php
// Simple JSON-based storage helpers (no DB required)
function data_path($file){ return __DIR__ . '/../data/' . $file; }

function read_json($file, $default){
  $path = data_path($file);
  if(!file_exists($path)) return $default;
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return $data === null ? $default : $data;
}

function write_json($file, $data){
  $path = data_path($file);
  $tmp = $path . '.tmp';
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
  rename($tmp, $path);
}

function json_response($data, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function require_login(){
  if(session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  if(empty($_SESSION['user'])){
    // If called from /backend/*, return JSON 401 to avoid fetch() HTML redirects.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if(strpos($script, '/backend/') !== false){
      json_response(['ok'=>false,'error'=>'Unauthorized'], 401);
    }
    // Redirect to the app's login page (index.php) in a way that works even when
    // the app is deployed inside a subfolder (e.g., /petron-pos/index.php).
    //
    // Examples:
    //  - /petron-pos/dashboard.php  -> /petron-pos/index.php
    //  - /petron-pos/partials/...   -> /petron-pos/index.php (included pages)
    //  - /petron-pos/backend/...    -> JSON 401 handled above
    $root = '';
    if(($pos = strpos($script, '/backend/')) !== false){
      $root = substr($script, 0, $pos);
    }elseif(($pos = strpos($script, '/auth/')) !== false){
      $root = substr($script, 0, $pos);
    }else{
      $root = rtrim(dirname($script), '/\\');
    }
    if($root === '' || $root === '.') $root = '/';
    $loginUrl = rtrim($root, '/') . '/index.php';
    header('Location: ' . $loginUrl);
    exit;
  }
}


function normalize_role($role){
  $r = strtolower(trim((string)$role));
  if($r === 'superadmin' || $r === 'super admin') return 'Super Admin';
  if($r === 'developer' || $r === 'dev') return 'Developer';
  if($r === 'admin' || $r === 'admin/manager' || $r === 'station admin') return 'Admin';
  if($r === 'manager') return 'Manager';
  // Remove operations_staff - all operational roles are now 'staff'
  return 'Staff';
}

// Canonical role key used for menus/routing.
// Keeps compatibility with the existing normalize_role() labels above.
function role_key($role){
  $r = strtolower(trim((string)$role));
  if(in_array($r, ['superadmin','super admin','super_admin'])) return 'superadmin';
  if(in_array($r, ['developer','dev'])) return 'developer';
  if(in_array($r, ['admin','station admin','station_admin'])) return 'admin';
  if(in_array($r, ['manager','supervisor','manager / supervisor','manager/supervisor','supervisor/manager'])) return 'manager';
  // Remove operations_staff handling - all staff roles use 'staff'
  return 'staff';
}

/**
 * Get shift time range label based on a timestamp or time string
 * Returns: "6:00 AM - 2:00 PM", "2:00 PM - 12:00 AM", or "12:00 AM - 6:00 AM"
 * 
 * @param string|int $datetime DateTime string or Unix timestamp
 * @return string Shift time range label
 */
function get_shift_label($datetime) {
    $time = is_numeric($datetime) ? date('H:i:s', $datetime) : date('H:i:s', strtotime($datetime));
    $hour = (int)substr($time, 0, 2);
    
    // Shift 1: 6:00 AM - 2:00 PM (06:00 - 13:59)
    if ($hour >= 6 && $hour < 14) {
        return '6:00 AM - 2:00 PM';
    }
    // Shift 2: 2:00 PM - 12:00 AM (14:00 - 23:59)
    elseif ($hour >= 14 && $hour <= 23) {
        return '2:00 PM - 12:00 AM';
    }
    // Night Shift: 12:00 AM - 6:00 AM (00:00 - 05:59)
    else {
        return '12:00 AM - 6:00 AM';
    }
}

/**
 * SQL CASE statement for determining shift based on time column
 * Use this in SQL queries to auto-assign shift labels
 * 
 * @param string $time_column The datetime/time column name
 * @return string SQL CASE statement
 */
function get_shift_sql_case($time_column) {
    return "CASE
        WHEN TIME($time_column) >= '06:00:00' AND TIME($time_column) < '14:00:00' 
            THEN '6:00 AM - 2:00 PM'
        WHEN TIME($time_column) >= '14:00:00' AND TIME($time_column) <= '23:59:59'
            THEN '2:00 PM - 12:00 AM'
        ELSE '12:00 AM - 6:00 AM'
    END";
}

// ── Module Configuration Helpers ─────────────────────────────
// Maps module_key → page_id values that belong to that module.
// Used by sidebar filtering and page-level gate checks.
define('MODULE_PAGE_MAP', [
    'transactions'    => ['transactions', 'staff_transactions_hub', 'transactions_variance', 'transactions_shift'],
    'job_orders'      => ['job_orders'],
    'fuel_management' => ['fuel', 'fuel_readings', 'fuel_inventory', 'reconciliation',
                          'fuel_monitoring', 'fuel_shift_processing', 'manager_fuel_management'],
    'calendar'        => ['calendar', 'staff_calendar', 'admin_calendar'],
    'reports'         => ['reports', 'staff_reports', 'manager_reports', 'admin_reports',
                          'reports_audit_admin', 'report_job_orders', 'report_transactions',
                          'report_customers', 'report_activity'],
]);

// Maps module_key → sidebar item IDs that belong to that module.
define('MODULE_MENU_MAP', [
    // ── Core Operational Modules ──────────────────────────────────────────────
    'transactions'          => [
        'transactions', 'admin_transactions', 'fuel_merch_transactions', 'variance_alerts',
        'shift_transactions_view', 'merchandise_transaction', 'shift_transactions',
        'staff_fuel_transactions', 'pending_transactions', 'pending_transactions_manager',
        'validated_transactions_manager', 'ato_oversight_dashboard', 'ato_variance_reports'
    ],
    'job_orders'            => [
        'job_orders', 'job_encode', 'job_tracker', 'report_jo_tracker', 'mgr_prod_services',
        'mgr_report_joborders', 'rpt_job_orders'
    ],
    'fuel_management'       => [
        'fuel', 'admin_fuel_management', 'staff_fuel_deliveries_sub', 'staff_fuel_del_history',
        'staff_fuel_transactions', 'admin_fuel_transactions_oversight', 'admin_fuel_deliveries_oversight',
        'admin_fuel_adjustments_oversight', 'admin_pump_master_oversight', 'fuel_transactions_validation',
        'fuel_deliveries_validation', 'fuel_adjustments', 'fuel_pump_master', 'mgr_prod_fuel',
        'admin_inventory_fuel'
    ],
    // merchandise = Merchandise Deliveries (staff/manager recording)
    'merchandise'           => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    // merchandise_deliveries = alias used in station_modules table — same sidebar items
    'merchandise_deliveries' => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    // deliveries = generic alias used in station_modules table
    'deliveries'            => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    'inventory'             => [
        'inventory', 'admin_inventory', 'inv_merch', 'inv_fuel',
        'inv_stock_request', 'staff_stock_in', 'mgr_stock_in', 'inv_history', 'mgr_prod_merchandise',
        'mgr_prod_prices', 'mgr_inv_merch', 'mgr_inv_fuel', 'mgr_inv_stock_request',
        'mgr_inv_po_gen', 'mgr_del_validate', 'admin_inventory_merchandise', 'admin_purchase_orders',
        'admin_stock_requests_monitor', 'admin_stock_in_oversight', 'admin_inventory_history',
        'admin_product_pricing'
    ],
    'product_management'    => [
        'product_management', 'mgr_prod_merchandise', 'mgr_prod_fuel', 'mgr_prod_services',
        'mgr_prod_prices', 'mgr_prod_adjustment', 'admin_product_pricing'
    ],
    'purchase_orders'       => [
        'mgr_inv_po_gen', 'admin_purchase_orders', 'purchase_orders'
    ],
    'calendar'              => [
        'calendar', 'admin_calendar'
    ],
    'reports'               => [
        'reports', 'admin_reports', 'manager_reports', 'report_daily_sales', 'report_deliveries',
        'report_payments', 'report_customers', 'report_activity', 'rpt_operations', 'rpt_finance',
        'rpt_compliance', 'mgr_operations_reports', 'mgr_finance_reports', 'mgr_compliance_reports',
        'rpt_sales', 'rpt_balances', 'rpt_deliveries', 'rpt_staff', 'rpt_audit', 'mgr_report_sales',
        'mgr_report_balances', 'mgr_report_deliveries', 'mgr_report_staff', 'mgr_report_validation',
        'mgr_report_audit'
    ],
    'customers'             => [
        'customers', 'customer_add', 'customer_list', 'customer_history'
    ],
    'payments'              => [
        // Payments module controls payment method related items in Transactions
        // No dedicated sidebar item — toggling this hides the entire transactions flow
        'transactions', 'admin_transactions', 'pending_transactions_manager', 'validated_transactions_manager'
    ],
    'staff_management'      => [
        // Staff Management controls the staff oversight/attendance sidebar items
        'staff_oversight_admin', 'users'
    ],
    'admin_unlock'          => [
        // Admin Unlock is a privilege module — no direct sidebar item, controls override access
    ],
]);

/**
 * Load all module enabled states from DB into a static cache.
 * Returns associative array: ['transactions' => true, 'job_orders' => false, ...]
 * Falls back to all-enabled if DB is unavailable.
 */
function get_module_states(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $pdo;
    
    // Default modules (all enabled by default) — must include every key used in station_modules table
    $cache = [
        'transactions'           => true,
        'job_orders'             => true,
        'fuel_management'        => true,
        'merchandise'            => true,
        'merchandise_deliveries' => true,
        'deliveries'             => true,
        'payments'               => true,
        'inventory'              => true,
        'product_management'     => true,
        'purchase_orders'        => true,
        'calendar'               => true,
        'reports'                => true,
        'customers'              => true,
        'staff_management'       => true,
        'admin_unlock'           => true,
    ];

    if (!isset($pdo)) return $cache;

    // ══════════════════════════════════════════════════════════════
    // STATION-DEPENDENT MODULE CONFIGURATION
    // Get module states for the current user's station
    // ══════════════════════════════════════════════════════════════
    
    try {
        // Get current user's station_id dynamically
        $station_id = user_station_id();
        
        if ($station_id) {
            // Query station_modules table for this station's configuration
            $stmt = $pdo->prepare("
                SELECT module_key, is_enabled 
                FROM station_modules 
                WHERE station_id = ?
            ");
            $stmt->execute([$station_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            foreach ($rows as $key => $val) {
                $cache[$key] = (bool)(int)$val;
            }
        } else {
            // Fallback: Try old module_settings table (if exists)
            $rows = $pdo->query("SELECT module_key, is_enabled FROM module_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $key => $val) {
                $cache[$key] = (bool)(int)$val;
            }
        }
    } catch (Exception $e) { 
        /* table may not exist yet — use defaults */ 
    }

    return $cache;
}

/**
 * Check if a specific module is enabled.
 * SuperAdmin and Developer always see everything regardless of module state.
 */
function is_module_enabled(string $module_key): bool {
    $states = get_module_states();
    return $states[$module_key] ?? true; // default enabled if unknown
}

/**
 * Check if a user has access to a specific module based on their station.
 * This function checks station-dependent module configuration.
 * 
 * @param int $user_id User ID to check
 * @param string $module_key Module key (e.g., 'fuel_management', 'inventory')
 * @return bool True if module is enabled for user's station
 */
function hasModuleAccess(int $user_id, string $module_key): bool {
    global $pdo;
    
    if (!isset($pdo)) return true; // Default to enabled if no DB
    
    try {
        // Get user's role and station
        $stmt = $pdo->prepare("SELECT role, station_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        // SuperAdmin and Developer always have access to all modules
        $role = role_key($user['role'] ?? '');
        if (in_array($role, ['superadmin', 'developer'], true)) {
            return true;
        }
        
        // Check if user has a station assigned
        $station_id = $user['station_id'] ?? null;
        if (!$station_id) return true; // No station = assume enabled
        
        // Query station_modules table
        $stmt = $pdo->prepare("
            SELECT is_enabled 
            FROM station_modules 
            WHERE station_id = ? AND module_key = ?
        ");
        $stmt->execute([$station_id, $module_key]);
        $is_enabled = $stmt->fetchColumn();
        
        // Return the module status (default to enabled if not found)
        return ($is_enabled !== false) ? (bool)(int)$is_enabled : true;
        
    } catch (Exception $e) {
        // Table may not exist yet, default to enabled
        return true;
    }
}

/**
 * Get a specific module config value.
 */
function get_module_setting(string $module_key, string $config_key, $default = null) {
    global $pdo;
    if (!isset($pdo)) return $default;
    try {
        $stmt = $pdo->prepare("SELECT config_value, config_type FROM module_config WHERE module_key=? AND config_key=? LIMIT 1");
        $stmt->execute([$module_key, $config_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        switch ($row['config_type']) {
            case 'boolean': return (bool)(int)$row['config_value'];
            case 'integer': return (int)$row['config_value'];
            case 'decimal': return (float)$row['config_value'];
            default:        return $row['config_value'];
        }
    } catch (Exception $e) { return $default; }
}

/**
 * Render a "module disabled" page and exit.
 * Called from page entry points when a module is disabled.
 * The header has NOT been included yet when this is called.
 */
function render_module_disabled_page(string $module_name): void {
    global $page_id;
    // Include the full layout (header + sidebar)
    include __DIR__ . '/../partials/header.php';
    echo '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;text-align:center;padding:40px 20px;">';
    echo '<div style="width:80px;height:80px;border-radius:50%;background:rgba(204,0,0,.08);display:flex;align-items:center;justify-content:center;margin-bottom:20px;">';
    echo '<i class="fas fa-ban" style="font-size:36px;color:#cc0000;opacity:.6;"></i>';
    echo '</div>';
    echo '<h2 style="font-size:20px;font-weight:700;color:#00264D;margin:0 0 10px;text-transform:uppercase;">Module Disabled</h2>';
    echo '<p style="font-size:14px;color:#666;max-width:420px;margin:0 0 24px;line-height:1.6;">The <strong>' . htmlspecialchars($module_name) . '</strong> module has been disabled by the SuperAdmin. Contact your system administrator to re-enable it.</p>';
    echo '<a href="javascript:history.back()" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#00264D;color:#fff;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;">';
    echo '<i class="fas fa-arrow-left"></i> Go Back</a>';
    echo '</div>';
    include __DIR__ . '/../partials/footer.php';
    exit;
}

/**
 * RBAC Permission Matrix
 * Defines what each role can access in the system
 * Based on: Managers handle day-to-day operations, Admins provide oversight
 */

/**
 * Get user permissions based on role
 * Returns array of permission strings that the role has access to
 */
function get_user_permissions($role) {
    $role = role_key($role);
    
    $permissions = [];
    
    switch($role) {
        case 'superadmin':
            // Superadmin: Developer and system administration only
            $permissions = [
                // Basic access
                'view_dashboard',
                
                // System administration permissions (developer only)
                'manage_system_settings',
                'view_audit_logs',
                'developer_access',
                
                // User management (full access)
                'manage_all_users',
                
                // Station management
                'manage_stations'
            ];
            break;
        case 'developer':
            // Developer: special system-level access for developer support and maintenance
            $permissions = [
                'view_dashboard',
                'manage_system_settings',
                'view_audit_logs',
                'developer_access',
                'manage_all_users',
                'manage_stations'
            ];
            break;
            
        case 'admin':
            // Admin: View-only access to inventory, customers, station-related, and admin reports
            $permissions = [
                // Basic access
                'view_dashboard',
                'view_calendar',

                // Transactions - view only
                'view_transactions',

                // Fuel management - view only
                'view_fuel_variance',

                // Inventory - view only
                'view_inventory',

                // Station-related
                'manage_stations',
                'manage_users_station',

                // Admin reports
                'view_all_reports',
                'view_financial_reports',
                'view_nationwide_reports',
                'export_data'
            ];
            break;
            
        case 'manager':
            // Manager: Approval, oversight, and managerial reports
            $permissions = [
                'manage_staff_oversight',
                'assign_shifts',
                'view_team_reports',
                // Basic access
                'view_dashboard',
                'view_calendar',

                // Transaction approvals and viewing
                'view_transactions',
                'approve_transactions',
                'handle_approvals',

                // Fuel management
                'manage_fuel',

                // Inventory oversight
                'view_inventory',
                'manage_inventory',

                // Staff management
                'manage_staff',

                // User management (station-scoped, staff only enforced in users.php)
                'manage_users_station',

                // Job order management
                'manage_job_orders',

                // Managerial reports (operational oversight)
                'view_operational_reports',
                
                // Purchase orders and deliveries management
                'manage_purchase_orders',
                'manage_deliveries',

                // Audit trail — station-scoped (staff + manager actions only)
                'view_audit_logs_station',
            ];
            break;
            
        case 'staff':
                // Staff: Operational access (excluding system admin and financial reports)
                $permissions = [
                    'manage_staff_oversight',
                // Basic access
                'view_dashboard',
                'view_calendar',
                
                // Transaction permissions
                'create_transactions',
                
                // Job order permissions  
                'create_job_orders', 'manage_job_orders',
                
                // Fuel management permissions
                'encode_fuel', 'manage_fuel',
                
                // Inventory permissions
                'manage_inventory', 'view_inventory',
                
                // Customer management permissions
                'manage_customers', 'manage_customers_basic',
                
                // Staff management permissions
                'manage_staff', 'manage_shifts',
                
                // Report permissions (staff-related only)
                'view_personal_reports', 'view_operational_reports',
                
                // User management permissions (station only)
                'manage_users_station',
                
                // Additional operational permissions
                'export_data', 'audit_oversight', 'manage_pricing', 'manage_pricing_station',
                'unlock_records', 'view_audit_logs_station'
            ];
            break;
    }
    
    return $permissions;
}

/**
 * Check if user can access a specific menu item
 */
function user_can_access_menu($menu_id, $user_role, $required_permissions = []) {
    $user_permissions = get_user_permissions($user_role);
    
    // If no specific permissions required, allow access
    if (empty($required_permissions)) {
        return true;
    }
    
    // Check if user has any of the required permissions
    foreach ($required_permissions as $permission) {
        if (in_array($permission, $user_permissions)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Check if user has station-specific access vs global access
 */
function is_station_specific_role($role) {
    $role = role_key($role);
    return in_array($role, ['admin', 'manager', 'staff']);
}

/**
 * Check if user can access global (all stations) data
 */
function has_global_access($role) {
    $role = role_key($role);
    return $role === 'superadmin';
}

// Individual capability functions for specific features

function can_manage_users($role) {
    return user_can_access_menu('users', $role, ['manage_all_users', 'manage_users_station']);
}

function can_approve_transactions($role) {
    return user_can_access_menu('approvals', $role, ['approve_transactions']);
}

function can_manage_inventory($role) {
    return user_can_access_menu('inventory', $role, ['manage_inventory', 'view_inventory', 'receive_inventory']);
}

function can_manage_fuel($role) {
    return user_can_access_menu('fuel', $role, ['manage_fuel', 'encode_fuel']);
}

function can_view_reports($role, $report_type = 'basic') {
    $permissions = [];
    switch($report_type) {
        case 'financial':
            $permissions = ['view_financial_reports', 'view_all_reports'];
            break;
        case 'operational':
            $permissions = ['view_operational_reports', 'view_all_reports'];
            break;
        case 'audit':
            $permissions = ['view_audit_logs', 'view_audit_logs_station'];
            break;
        default:
            $permissions = ['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports'];
    }
    
    return user_can_access_menu('reports', $role, $permissions);
}

function can_manage_job_orders($role) {
    return user_can_access_menu('job_orders', $role, ['manage_job_orders', 'create_job_orders']);
}

function can_manage_customers($role) {
    return user_can_access_menu('customers', $role, ['manage_customers', 'manage_customers_basic']);
}
function is_manager_or_above(){
  $u = current_user();
  if(!$u) return false;
  $role = role_key($u['role'] ?? 'staff');
  return in_array($role, ['manager', 'admin', 'superadmin']);
}

/**
 * Require Manager or Super Admin access
 * Throws 403 if user is not Manager or above
 */
function require_manager_or_above(){
  require_login();
  if(!is_manager_or_above()){
    json_response(['ok'=>false,'error'=>'Manager privileges required'], 403);
  }
}

/**
 * Check if Manager can finalize a record
 * Manager can finalize if not already finalized by someone else
 */
function can_manager_finalize(PDO $pdo, string $table, int $record_id): bool {
  try {
    $stmt = $pdo->prepare("SELECT is_locked, finalized_by FROM {$table} WHERE id = ?");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$record) return false;
    
    // Can finalize if not locked
    return $record['is_locked'] == 0;
  } catch(Exception $e) {
    return false;
  }
}

function role_rank($role){
  $role = normalize_role($role);
  if($role === 'Super Admin') return 3;
  if($role === 'Admin') return 2;
  if($role === 'Manager') return 2;
  return 1; // Staff
}

function current_user(){
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  return $_SESSION['user'] ?? null;
}

function has_role_at_least($minRole){
  $u = current_user();
  $ur = $u ? role_rank($u['role'] ?? 'Staff') : 0;
  return $ur >= role_rank($minRole);
}

function require_role($minRole){
  require_login();
  if(!has_role_at_least($minRole)){
    json_response(['ok'=>false,'error'=>'Forbidden'], 403);
  }
}

function user_station_id(){
  $u = current_user();
  if(!$u) return null;

  // Refresh station_id from database to ensure latest data after migrations
  // This ensures users who were migrated to a new station see the correct station
  try {
    global $pdo;
    if($pdo && isset($u['id'])) {
      $stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
      $stmt->execute([$u['id']]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      if($result && isset($result['station_id'])) {
        // Update session with latest station_id
        $_SESSION['user']['station_id'] = $result['station_id'];
        return $result['station_id'];
      }
    }
  } catch(Exception $e) {
    // Fall back to session value if DB query fails
  }

  return $u['station_id'] ?? null;
}

/**
 * Get the name of the current user's assigned station.
 * Returns the station name string, or an empty string if not found.
 */
function user_station_name(): string {
  global $pdo;
  $station_id = user_station_id();
  if (!$station_id || !isset($pdo)) return '';
  try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    return (string)($stmt->fetchColumn() ?: '');
  } catch (Exception $e) {
    return '';
  }
}

/**
 * Format merchandise unit of measure to official standardized labels.
 * Standard UOM list:
 *   Piece (pc), Bottle, Can, Tube, Pack, Box, Roll, Set, Gallon,
 *   Liter, Milliliter (mL), Kilogram (kg), Gram (g),
 *   Drum, Pail, Sack, Carton, Case, Pair
 */
function format_merch_unit($unit) {
    $u = strtolower(trim((string)$unit));
    // Piece
    if (in_array($u, ['pcs','pc','piece','pieces'])) return 'Piece (pc)';
    // Bottle
    if (in_array($u, ['bot','btl','bottle','bottles','jar','jars'])) return 'Bottle';
    // Can
    if (in_array($u, ['can','cnt','cans'])) return 'Can';
    // Tube
    if (in_array($u, ['tube','tubes','tbe'])) return 'Tube';
    // Pack
    if (in_array($u, ['pack','packs','pkg','pkgs','packet','packets','sachet','sachets','sct','bag','bags'])) return 'Pack';
    // Box
    if (in_array($u, ['box','bx','boxes'])) return 'Box';
    // Roll
    if (in_array($u, ['roll','rolls','rll'])) return 'Roll';
    // Set
    if (in_array($u, ['set','sets'])) return 'Set';
    // Gallon
    if (in_array($u, ['gal','gallon','gallons'])) return 'Gallon';
    // Liter
    if (in_array($u, ['ltr','litre','litres','liter','liters','l'])) return 'Liter';
    // Milliliter
    if (in_array($u, ['ml','milliliter','milliliters','millilitre','millilitres'])) return 'Milliliter (mL)';
    // Kilogram
    if (in_array($u, ['kg','kilogram','kilograms'])) return 'Kilogram (kg)';
    // Gram
    if (in_array($u, ['g','gram','grams'])) return 'Gram (g)';
    // Drum
    if (in_array($u, ['drum','drums'])) return 'Drum';
    // Pail
    if (in_array($u, ['pail','pails','tub','tubs'])) return 'Pail';
    // Sack
    if (in_array($u, ['sack','sacks'])) return 'Sack';
    // Carton
    if (in_array($u, ['carton','cartons','ctn'])) return 'Carton';
    // Case
    if (in_array($u, ['case','cases'])) return 'Case';
    // Pair
    if (in_array($u, ['pair','pairs','pr'])) return 'Pair';
    // Empty → default to Piece
    if ($u === '') return 'Piece (pc)';
    // Fallback: capitalize first letter
    return ucfirst($unit);
}

function today_key(){
  return date('Y-m-d');
}

function money($n){
  return number_format((float)$n, 2, '.', ',');
}

/**
 * Render a "no station assigned" page and exit.
 * Call this after require_login() when station_id is 0/null for a station-scoped role.
 */
function render_no_station_page(string $back_url = 'admin_dashboard.php'): void {
    if (!headers_sent()) {
        // Include header only if not already included
        $header = __DIR__ . '/../partials/header.php';
        if (file_exists($header)) {
            require_once $header;
        }
    }
    echo '
    <div style="max-width:520px;margin:80px auto;text-align:center;padding:40px 36px;
                background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);">
        <i class="fas fa-map-marker-alt" style="font-size:3rem;color:#CC0000;margin-bottom:16px;display:block;"></i>
        <h2 style="color:#00264D;margin:0 0 10px;font-size:1.3rem;">No Station Assigned</h2>
        <p style="color:#666;font-size:.93rem;line-height:1.6;margin:0 0 24px;">
            Your account has not been assigned to a station yet.<br>
            Please contact your <strong>SuperAdmin</strong> to assign you to a station before you can access this page.
        </p>
        <a href="' . htmlspecialchars($back_url) . '"
           style="display:inline-flex;align-items:center;gap:8px;background:#00264D;color:#fff;
                  padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>
    </div>';
    $footer = __DIR__ . '/../partials/footer.php';
    if (file_exists($footer)) {
        require_once $footer;
    }
    exit;
}

function log_activity($pdo, $user_id, $action, $details) {
  try {
    if(!($pdo instanceof PDO)) return;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
  } catch (Exception $e) { /* Fail silently to not disrupt flow */ }
}

function darken_color($hex, $percent = 20) {
    $hex = ltrim($hex, '#');
    $r = (int)hexdec(substr($hex, 0, 2)) * (100 - $percent) / 100;
    $g = (int)hexdec(substr($hex, 2, 2)) * (100 - $percent) / 100;
    $b = (int)hexdec(substr($hex, 4, 2)) * (100 - $percent) / 100;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

function get_theme_preview_css($config) {
    $primary = $config['primary_color'] ?? '#002F6C';
    return "/* Theme Preview Override */ .theme-preview { --primary-color: {$primary}; --gradient-primary: linear-gradient(135deg, {$primary}, " . darken_color($primary, 20) . "); }";
}
function is_shift_finalized(PDO $pdo, int $station_id, string $date, string $shift): bool {
  try {
    $stmt = $pdo->prepare("SELECT status FROM shift_reports WHERE station_id=? AND report_date=? AND shift=? LIMIT 1");
    $stmt->execute([$station_id, $date, $shift]);
    $st = $stmt->fetchColumn();
    return ($st === 'finalized');
  } catch(Exception $e) {
    return false;
  }
}

// --- RBAC HELPERS ---
function rbac_is_backend_request(): bool {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (strpos($script, '/backend/') !== false) || (strpos($script, '\\backend\\') !== false);
}

function rbac_default_matrix(): array {
  // Default permissions if DB/JSON not available
  $modules = [
    'dashboard.view',
    'users.create_admin','users.approve_manager','users.reset_passwords','users.deactivate','rbac.manage',
    'inventory.receiving','inventory.stock','reconciliation.fuel_reports',
    'pos.process_sales','receivables.manage','shift_reports.view',
    'joborder.create','joborder.assign_mechanics','joborder.parts_tracking','joborder.billing',
    'audit.logins','audit.password_changes','audit.account_actions','audit.recon_approvals','audit.settings_changes',
    'security.rbac_enforcement','security.account_lockouts','security.password_policy','security.unauthorized_attempts',
    'reports.daily','reports.monthly','reports.receivables_aging','reports.fuel_variance','reports.nationwide'
  ];
  $all = array_fill_keys($modules, 1);
  return [
    'superadmin' => $all,
    'admin' => [
      'dashboard.view'=>1,'users.reset_passwords'=>1,'users.deactivate'=>1,
      'inventory.receiving'=>1,'inventory.stock'=>1,
      'reconciliation.fuel_reports'=>1,
      'pos.process_sales'=>1,'receivables.manage'=>1,'shift_reports.view'=>1,
      'joborder.create'=>1,'joborder.assign_mechanics'=>1,'joborder.parts_tracking'=>1,'joborder.billing'=>1,
      'audit.logins'=>1,'audit.password_changes'=>1,'audit.account_actions'=>1,'audit.recon_approvals'=>1,
      'reports.daily'=>1,'reports.monthly'=>1
    ],
    'manager' => [
      'dashboard.view'=>1,'inventory.stock'=>1,'reconciliation.fuel_reports'=>1,'shift_reports.view'=>1,'audit.logins'=>1,'reports.daily'=>1
    ],
    'mechanic' => [ 'dashboard.view'=>1,'joborder.parts_tracking'=>1 ],
    'staff' => [ 'dashboard.view'=>1,'pos.process_sales'=>1 ]
  ];
}

function rbac_permissions(): array {
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!empty($_SESSION['rbac_cache']) && is_array($_SESSION['rbac_cache'])) {
    $ts = (int)($_SESSION['rbac_cache']['ts'] ?? 0);
    if ($ts > (time() - 60)) return $_SESSION['rbac_cache']['data'];
  }

  $matrix = [];
  // Try DB
  try {
    global $pdo; // available in most pages after db_connect.php
    if ($pdo) {
      $stmt = $pdo->query("SELECT role, permission, allowed FROM role_permissions");
      $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($rows as $r) {
        $role = strtolower(trim($r['role']));
        if (!isset($matrix[$role])) $matrix[$role] = [];
        if ((int)$r['allowed'] === 1) $matrix[$role][$r['permission']] = 1;
      }
    }
  } catch(Exception $e) { /* ignore */ }

  if (empty($matrix)) {
    // Try JSON fallback
    $json = read_json('permissions.json', []);
    if (!empty($json['data']) && is_array($json['data'])) {
      // Normalize keys to lowercase roles
      foreach ($json['data'] as $role => $perms) {
        $matrix[strtolower(trim($role))] = $perms;
      }
    }
  }

  if (empty($matrix)) {
    $matrix = rbac_default_matrix();
  }

  $_SESSION['rbac_cache'] = ['ts' => time(), 'data' => $matrix];
  return $matrix;
}

function can(string $permission): bool {
  $u = current_user();
  $role = strtolower(trim((string)($u['role'] ?? 'staff')));
  if ($role === 'superadmin' || $role === 'super admin') return true;
  $perm = rbac_permissions();
  return !empty($perm[$role][$permission]);
}

function rbac_forbidden_html(string $message = 'Access denied by RBAC policy.'){
  http_response_code(403);
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
     . '<title>403 Forbidden</title>'
     . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fa;margin:0;padding:40px;} .card{max-width:720px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05);} .h{margin:0;padding:16px 20px;border-bottom:1px solid #e5e7eb;font-size:18px;color:#111827;} .b{padding:18px 20px;color:#4b5563;} .b a{color:#002F6C;text-decoration:none;border:1px solid #002F6C;padding:6px 10px;border-radius:4px;}</style></head><body>'
     . '<div class="card"><div class="h">403 Forbidden</div><div class="b">' . htmlspecialchars($message) . '<div style="margin-top:12px;"><a href="index.php">Go to Home</a></div></div></div>'
     . '</body></html>';
  exit;
}

function require_permission(string $permission){
  require_login();
  if (can($permission)) return; // allowed
  // Log and respond
  try {
    global $pdo; $u = current_user();
    if (isset($pdo) && $pdo) log_activity($pdo, $u['id'] ?? 0, 'RBAC Deny', "Denied '$permission' on " . ($_SERVER['REQUEST_URI'] ?? ''));
  } catch(Exception $e) { }

  if (rbac_is_backend_request()) {
    json_response(['ok'=>false, 'error'=>'Forbidden', 'permission'=>$permission], 403);
  }
  rbac_forbidden_html();
}

function generateSecurePassword(int $length = 12): string {
  // Allowed symbols: _ . - ! @ #
  $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $lower   = 'abcdefghijklmnopqrstuvwxyz';
  $digits  = '0123456789';
  $symbols = '_.-!@#';
  $all     = $upper . $lower . $digits . $symbols;

  // Guarantee at least one of each required type
  $password  = $upper[random_int(0, strlen($upper) - 1)];
  $password .= $lower[random_int(0, strlen($lower) - 1)];
  $password .= $digits[random_int(0, strlen($digits) - 1)];
  $password .= $symbols[random_int(0, strlen($symbols) - 1)];

  // Fill remaining characters
  for ($i = 4; $i < $length; $i++) {
    $password .= $all[random_int(0, strlen($all) - 1)];
  }

  return str_shuffle($password);
}

/**
 * Create role-specific notifications for users
 * @param PDO $pdo Database connection
 * @param string $targetRole Target role (superadmin, admin, manager, staff)
 * @param string $type Notification type (success, warning, error, info)
 * @param string $title Notification title
 * @param string $message Notification message
 * @param int|null $specificUserId Optional specific user ID within the role
 * @return int Number of notifications created
 */
function create_role_notification($pdo, $targetRole, $type, $title, $message, $specificUserId = null) {
  try {
    ensure_notifications_table($pdo);
    $sql = "INSERT INTO notifications (user_id, type, title, message) 
            SELECT u.id, ?, ?, ? FROM users u 
            WHERE u.role = ? AND u.status = 'Active'";
    $params = [$type, $title, $message, $targetRole];
    
    if ($specificUserId) {
      $sql .= " AND u.id = ?";
      $params[] = $specificUserId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
  } catch (Exception $e) {
    error_log("Failed to create role notification: " . $e->getMessage());
    return 0;
  }
}

function ensure_notifications_table(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    type         ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
    title        VARCHAR(255) NOT NULL,
    message      TEXT NULL,
    event_type   VARCHAR(80) NOT NULL DEFAULT 'general',
    severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    source_key   VARCHAR(200) NULL,
    redirect_url VARCHAR(500) NULL,
    status       ENUM('unread','read') NOT NULL DEFAULT 'unread',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at      TIMESTAMP NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $existing = [];
  $stmt = $pdo->query("SHOW COLUMNS FROM notifications");
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existing[strtolower($col['Field'])] = true;
  }

  $columns = [
    'type'         => "ALTER TABLE notifications ADD COLUMN type ENUM('success','warning','error','info') NOT NULL DEFAULT 'info' AFTER user_id",
    'title'        => "ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT 'Notification' AFTER type",
    'message'      => "ALTER TABLE notifications ADD COLUMN message TEXT NULL AFTER title",
    'event_type'   => "ALTER TABLE notifications ADD COLUMN event_type VARCHAR(80) NOT NULL DEFAULT 'general' AFTER message",
    'severity'     => "ALTER TABLE notifications ADD COLUMN severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER event_type",
    'source_key'   => "ALTER TABLE notifications ADD COLUMN source_key VARCHAR(200) NULL AFTER severity",
    'redirect_url' => "ALTER TABLE notifications ADD COLUMN redirect_url VARCHAR(500) NULL AFTER source_key",
    'status'       => "ALTER TABLE notifications ADD COLUMN status ENUM('unread','read') NOT NULL DEFAULT 'unread' AFTER redirect_url",
    'created_at'   => "ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status",
    'read_at'      => "ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL AFTER created_at",
  ];
  foreach ($columns as $name => $sql) {
    if (empty($existing[$name])) {
      try { $pdo->exec($sql); } catch (Exception $e) {}
    }
  }

  $indexes = [];
  try {
    $stmt = $pdo->query("SHOW INDEX FROM notifications");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
      $indexes[$idx['Key_name']] = true;
    }
  } catch (Exception $e) {}

  foreach ([
    'idx_notif_user_status' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_user_status (user_id, status)",
      ['idx_notif_user_status', 'idx_user_status']
    ],
    'idx_notif_created_at' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_created_at (created_at)",
      ['idx_notif_created_at', 'idx_created_at']
    ],
    'idx_notif_event_type' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_event_type (event_type)",
      ['idx_notif_event_type', 'idx_event_type']
    ],
    'idx_notif_source_key' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_source_key (source_key)",
      ['idx_notif_source_key', 'idx_source_key']
    ],
  ] as $name => [$sql, $aliases]) {
    $hasIndex = false;
    foreach ($aliases as $alias) {
      if (!empty($indexes[$alias])) {
        $hasIndex = true;
        break;
      }
    }
    if (!$hasIndex) {
      try { $pdo->exec($sql); } catch (Exception $e) {}
    }
  }

  $ready = true;
}

/**
 * Get role-specific notification types and messages
 * @param string $role User role
 * @return array Role-specific notification configurations
 */
function get_role_notification_types($role) {
  $notifications = [
    'superadmin' => [
      'system_alerts' => ['type' => 'error', 'title' => 'System Alert', 'message' => 'Critical system issue detected'],
      'security_breaches' => ['type' => 'error', 'title' => 'Security Alert', 'message' => 'Security breach detected'],
      'database_issues' => ['type' => 'error', 'title' => 'Database Alert', 'message' => 'Database connectivity issue'],
      'failed_logins' => ['type' => 'warning', 'title' => 'Failed Login', 'message' => 'Multiple failed login attempts detected'],
      'admin_actions' => ['type' => 'info', 'title' => 'Admin Activity', 'message' => 'Administrative action performed']
    ],
    'admin' => [
      'user_management' => ['type' => 'info', 'title' => 'User Management', 'message' => 'User account changes made'],
      'station_management' => ['type' => 'info', 'title' => 'Station Management', 'message' => 'Station configuration updated'],
      'reconciliation_required' => ['type' => 'warning', 'title' => 'Reconciliation Required', 'message' => 'Daily reconciliation pending'],
      'inventory_alerts' => ['type' => 'warning', 'title' => 'Inventory Alert', 'message' => 'Low stock levels detected']
    ],
    'manager' => [
      'staff_performance' => ['type' => 'info', 'title' => 'Staff Performance', 'message' => 'Staff performance metrics available'],
      'shift_management' => ['type' => 'info', 'title' => 'Shift Management', 'message' => 'Shift scheduling update'],
      'pump_readings' => ['type' => 'info', 'title' => 'Pump Readings', 'message' => 'Pump readings require approval'],
      'customer_issues' => ['type' => 'warning', 'title' => 'Customer Issue', 'message' => 'Customer complaint or issue reported'],
      'maintenance_required' => ['type' => 'warning', 'title' => 'Maintenance', 'message' => 'Equipment maintenance required']
    ],
    'staff' => [
      'task_assignments' => ['type' => 'info', 'title' => 'Task Assignment', 'message' => 'New task assigned to you'],
      'shift_reminders' => ['type' => 'info', 'title' => 'Shift Reminder', 'message' => 'Upcoming shift reminder'],
      'training_required' => ['type' => 'info', 'title' => 'Training Required', 'message' => 'Training module needs completion'],
      'schedule_changes' => ['type' => 'info', 'title' => 'Schedule Change', 'message' => 'Your work schedule has been updated'],
      'performance_feedback' => ['type' => 'info', 'title' => 'Performance Feedback', 'message' => 'New performance feedback available']
    ]
  ];
  
  return $notifications[$role] ?? [];
}

// ── Audit Trail Helper ────────────────────────────────────────────────────────
// Call this from any page/action to write a row into audit_logs.
// $pdo must be available in the calling scope (global or passed in).
function write_audit_log($pdo, $action_type, $action_details, $entity_type = null, $entity_id = null, $log_type = 'system', $status = 'Success') {
    try {
        if (!$pdo) return;
        $user_id = null;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['id'])) {
            $user_id = (int)$_SESSION['user']['id'];
        }
        $ip = $_SERVER['HTTP_CLIENT_IP']
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $pdo->prepare("INSERT INTO audit_logs
            (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$user_id, $log_type, $action_type, $action_details, $entity_type, $entity_id, $status, $ip, $ua]);
    } catch (Exception $e) { /* silent — never block the main action */ }
}

/**
 * FIFO-based stock level deduction from merchandise batches and inventory
 */
function fifo_deduct_stock(PDO $pdo, int $station_id, $product_id_or_name, float $qty): void {
    if ($qty <= 0) return;

    $product_id = 0;
    if (is_numeric($product_id_or_name) && (int)$product_id_or_name > 0) {
        $product_id = (int)$product_id_or_name;
    } else {
        // Look up by product_name
        try {
            $stmt = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
            $stmt->execute([$product_id_or_name]);
            $product_id = (int)$stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    if ($product_id <= 0) {
        // If we still can't find a product ID, fallback to name-based direct deduction on station_inventory
        if (is_string($product_id_or_name) && $product_id_or_name !== '') {
            try {
                $pdo->prepare("UPDATE station_inventory SET stock_level = GREATEST(stock_level - ?, 0), last_updated = NOW() WHERE product_name = ? AND station_id = ?")
                    ->execute([$qty, $product_id_or_name, $station_id]);
            } catch (Exception $e) {}
        }
        return;
    }

    // 1. Deduct from station_inventory.stock_level
    $deductStmt = $pdo->prepare("
        UPDATE station_inventory
        SET stock_level = GREATEST(stock_level - ?, 0),
            last_updated = NOW()
        WHERE station_id = ? AND product_id = ?
    ");
    $deductStmt->execute([$qty, $station_id, $product_id]);

    // 2. Deduct from inventory_products.stock (fallback table)
    try {
        $deductGlobalStmt = $pdo->prepare("
            UPDATE inventory_products
            SET stock = GREATEST(stock - ?, 0)
            WHERE id = ?
        ");
        $deductGlobalStmt->execute([$qty, $product_id]);
    } catch (Exception $e) {}

    // 3. Deduct from merchandise_batches using FIFO/LIFO based on dynamic config
    try {
        $fifo_enabled = get_module_setting('inventory', 'fifo_enabled', true);
        $order = $fifo_enabled ? "date_received ASC, id ASC" : "date_received DESC, id DESC";
        
        $qty_needed = $qty;
        $batchesStmt = $pdo->prepare("
            SELECT id, remaining_qty 
            FROM merchandise_batches 
            WHERE product_id = ? AND station_id = ? AND status = 'Active' AND remaining_qty > 0 
            ORDER BY {$order}
        ");
        $batchesStmt->execute([$product_id, $station_id]);
        $batches = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($batches as $batch) {
            if ($qty_needed <= 0) break;
            $batch_id = $batch['id'];
            $remaining = (float)$batch['remaining_qty'];

            if ($remaining > $qty_needed) {
                $new_remaining = $remaining - $qty_needed;
                $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_remaining, $batch_id]);
                $qty_needed = 0;
            } else {
                $qty_needed -= $remaining;
                $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = 0, status = 'depleted', updated_at = NOW() WHERE id = ?")
                    ->execute([$batch_id]);
            }
        }
    } catch (Exception $e) {
        error_log("FIFO batch deduction failed for product $product_id: " . $e->getMessage());
    }
}

function get_system_logo_url($station_id = null) {
    global $pdo;
    if ($station_id === null) {
        $station_id = user_station_id() ?: 0;
    }
    
    $default_logo = 'assets/img/Petron Logo.png';
    try {
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_logo' AND station_id = ?");
            $stmt->execute([$station_id]);
            $val = $stmt->fetchColumn();
            if ($val) {
                return $val;
            }
            if ($station_id > 0) {
                $stmt->execute([0]);
                $val = $stmt->fetchColumn();
                if ($val) {
                    return $val;
                }
            }
        }
    } catch (Exception $e) {}
    
    return $default_logo;
}

define('TANK_CONFIG_17', [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL - 1',       'tank'=>'UGT #1',  'tanker_num'=>1,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL - 2',       'tank'=>'UGT #2',  'tanker_num'=>2,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'UGT #3',  'tanker_num'=>3,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 1',  'tank'=>'UGT #4',  'tanker_num'=>4,  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'UGT #5',  'tanker_num'=>5,  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
    ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 2',  'tank'=>'UGT #6',  'tanker_num'=>6,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'UGT #7',  'tanker_num'=>7,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
]);

function get_tank_config() {
    return TANK_CONFIG_17;
}

function get_tanks_by_fuel_type($fuel_type) {
    $tanks = [];
    foreach (TANK_CONFIG_17 as $tank) {
        if (strtolower($tank['fuel_type']) === strtolower($fuel_type)) {
            $tanks[] = $tank;
        }
    }
    return $tanks;
}

function get_tank_by_ugt($ugt_no) {
    foreach (TANK_CONFIG_17 as $tank) {
        if ((int)$tank['tanker_num'] === (int)$ugt_no) {
            return $tank;
        }
    }
    return null;
}
?>
