<?php
$lib_path = __DIR__ . '/../backend/lib.php';
$content = file_get_contents($lib_path);

// Cut at start of checkRolePermission
$pos = strpos($content, "function checkRolePermission");
if ($pos === false) {
    echo "Could not find checkRolePermission\n";
    exit(1);
}

$clean_header = substr($content, 0, $pos);

$tail = <<<'PHP'
function checkRolePermission($role, $module, $action) {
    $role = strtolower(trim((string)$role));
    $module = strtolower(trim((string)$module));
    $action = strtolower(trim((string)$action));
    
    // Superadmin has unrestricted root access
    if (in_array($role, ['superadmin', 'developer'])) {
        return true;
    }
    
    // Admin access
    if ($role === 'admin') {
        // Admin has oversight across all station operational modules
        $restricted_actions = ['superadmin_config', 'database_drop'];
        return !in_array($action, $restricted_actions);
    }
    
    // Manager access
    if ($role === 'manager') {
        $manager_modules = [
            'transactions', 'job_orders', 'fuel', 'fuel_management',
            'inventory', 'product_management', 'purchase_orders',
            'calendar', 'reports', 'customers', 'deliveries', 'dashboard'
        ];
        $manager_actions = [
            'view', 'review', 'approve', 'reject', 'validate',
            'create_po', 'adjust', 'export', 'print', 'record'
        ];
        return in_array($module, $manager_modules) && in_array($action, $manager_actions);
    }
    
    // Staff access (operational)
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        $staff_allowed = [
            'transactions' => ['view', 'create', 'encode'],
            'job_orders'   => ['view', 'create'],
            'fuel'         => ['view', 'encode_readings', 'encode'],
            'inventory'    => ['view', 'request_stock', 'record_delivery'],
            'calendar'     => ['view', 'create_event'],
            'reports'      => ['view_personal', 'view_shift'],
            'customers'    => ['view', 'search'],
            'dashboard'    => ['view']
        ];
        return isset($staff_allowed[$module]) && in_array($action, $staff_allowed[$module]);
    }
    
    return false;
}
}

/**
 * UT-106: validateFuelReading
 * Validates meter reading inputs: non-blank, numeric, non-negative, and logical progression
 */
if (!function_exists('validateFuelReading')) {
function validateFuelReading($beginning, $ending, $calibration = 0.0) {
    if (!is_numeric($beginning) || !is_numeric($ending)) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Meter readings must be valid numeric values'];
    }
    
    $beg = (float)$beginning;
    $end = (float)$ending;
    $cal = is_numeric($calibration) ? (float)$calibration : 0.0;
    
    if ($beg < 0 || $end < 0 || $cal < 0) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Readings and calibration cannot be negative'];
    }
    
    if ($end < $beg) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Ending reading cannot be less than beginning reading without mechanical rollover'];
    }
    
    $volume = round($end - $beg - $cal, 2);
    if ($volume < 0) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Calibration exceeds gross volume dispensed'];
    }
    
    return [
        'valid' => true,
        'volume' => $volume,
        'gross_volume' => round($end - $beg, 2),
        'calibration' => $cal,
        'error' => null
    ];
}
}

/**
 * UT-107: formatCurrencyInput
 * Formats manual monetary inputs into standard 2-decimal localized format
 */
if (!function_exists('formatCurrencyInput')) {
function formatCurrencyInput($value) {
    if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
        return '0.00';
    }
    
    // Strip commas or currency symbols
    $cleaned = str_replace([',', '₱', 'PHP', ' '], '', (string)$value);
    if (!is_numeric($cleaned)) {
        return '0.00';
    }
    
    return number_format((float)$cleaned, 2, '.', ',');
}
}

/**
 * Category breakdown helper for sidebar drawer badges and header bell sync
 */
if (!function_exists('get_category_unread_counts')) {
function get_category_unread_counts(PDO $pdo, int $user_id, string $role = '', int $station_id = 0): array {
    $counts = [
        'transactions'  => 0,
        'fuel'          => 0,
        'inventory'     => 0,
        'customers'     => 0,
        'prod_pricing'  => 0,
        'reports'       => 0,
        'notifications' => 0
    ];

    $safe_count = function(string $sql, array $params = []) use ($pdo) {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    $counts['notifications'] = $safe_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'", [$user_id]);

    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // SERVICE STAFF
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (created_by = ? OR user_id = ? OR assigned_mechanic_id = ?) AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
            [$station_id, $user_id, $user_id, $user_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        );

    } elseif (in_array($role, ['manager', 'supervisor'])) {
        // MANAGER
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','reviewed')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(adjustment_type,'')) LIKE '%calibration%' AND LOWER(COALESCE(status,'')) IN ('pending','pending review')",
            [$station_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Approved','Pending Stock-In')",
            [$station_id]
        );

        $counts['customers']     = $safe_count(
            "SELECT COUNT(*) FROM customers WHERE station_id = ? AND LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','for review')",
            [$station_id]
        );
        $counts['mgr_customers'] = $counts['customers'];

    } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
        // ADMIN
        $admin_crit_stock = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.critical_level, ip.critical_level, 10)",
            []
        );
        $admin_pos = $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending Admin Review', 'Submitted', 'Pending Approval')",
            []
        );
        $admin_inv_total = $admin_crit_stock + $admin_pos;
        $counts['inventory']       = $admin_inv_total;
        $counts['admin_inventory'] = $admin_inv_total;

        $admin_price_change = $safe_count(
            "SELECT COUNT(*) FROM pending_price_approvals WHERE status = 'pending'",
            []
        );
        $counts['prod_pricing']          = $admin_price_change;
        $counts['mgr_product_pricing']   = $admin_price_change;
        $counts['admin_product_pricing'] = $admin_price_change;

        $admin_system_alerts = $safe_count(
            "SELECT COUNT(*) FROM notifications WHERE severity IN ('critical','error') AND status = 'unread'",
            []
        );
        $counts['reports']       = $admin_system_alerts;
        $counts['admin_reports'] = $admin_system_alerts;

        // Fuel Management Oversight
        $admin_fuel_txns = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE LOWER(COALESCE(status,'')) IN ('verified','validated','approved','adjusted','pending')",
            []
        );
        $admin_fuel_deliv = $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE (type = 'fuel' OR LOWER(COALESCE(item_category,'')) IN ('fuel','fuels')) AND (delivery_validated = 1 OR status IN ('Validated','Delivered','Pending Admin Review','Submitted'))",
            []
        );
        $admin_fuel_reqs = $safe_count(
            "SELECT COUNT(*) FROM fuel_stock_requests WHERE LOWER(COALESCE(status,'')) IN ('pending','pending manager review','pending admin review','approved','submitted')",
            []
        );
        $admin_fuel_adj = $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE LOWER(COALESCE(status,'')) IN ('pending','verified','reviewed','approved')",
            []
        );
        $admin_fuel_total = $admin_fuel_txns + $admin_fuel_deliv + $admin_fuel_reqs + $admin_fuel_adj;
        $counts['fuel']                  = $admin_fuel_total;
        $counts['admin_fuel']            = $admin_fuel_total;
        $counts['admin_fuel_management'] = $admin_fuel_total;
    }

    return $counts;
}
}

// ═══════════════════════════════════════════════════════════════════════════
// CENTRAL NOTIFICATION HELPER — notify()
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('notify')) {
function notify(
    PDO    $pdo,
    int    $user_id,
    string $role,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    string $shift_period = ''
): void {
    if ($user_id <= 0) return;
    try {
        static $migrated = false;
        if (!$migrated) {
            foreach ([
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS recipient_role VARCHAR(30) NULL AFTER user_id",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_type VARCHAR(80) NULL AFTER redirect_url",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id INT NULL AFTER reference_type",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS shift_period VARCHAR(20) NULL AFTER reference_id",
            ] as $ddl) {
                try { $pdo->exec($ddl); } catch (Throwable $e) {}
            }
            $migrated = true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications
                (user_id, recipient_role, type, event_type, severity, title, message,
                 source_key, redirect_url, reference_type, reference_id, shift_period, status)
            SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unread'
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM notifications WHERE user_id = ? AND source_key = ?
            )
        ");
        $stmt->execute([
            $user_id, $role, $type, $event_type, $severity, $title, $message,
            $source_key, $redirect_url,
            $ref_type ?: null, $ref_id > 0 ? $ref_id : null, $shift_period ?: null,
            $user_id, $source_key
        ]);
    } catch (Throwable $e) {
        error_log('notify() error: ' . $e->getMessage());
    }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_manager() — find station's manager user(s) and notify them all
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_manager')) {
function notify_manager(
    PDO    $pdo,
    int    $station_id,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    string $shift_period = ''
): void {
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? AND LOWER(role) IN ('manager','supervisor') AND status = 'Active'");
        $stmt->execute([$station_id]);
        $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($managers as $mgr_id) {
            notify($pdo, (int)$mgr_id, 'manager', $type, $event_type, $severity,
                   $title, $message, $source_key . '_m' . $mgr_id,
                   $redirect_url, $ref_type, $ref_id, $shift_period);
        }
    } catch (Throwable $e) { error_log('notify_manager error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_admin() — notify all admin users (system-wide)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_admin')) {
function notify_admin(
    PDO    $pdo,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    int    $station_id = 0
): void {
    try {
        $sql = $station_id
            ? "SELECT id FROM users WHERE station_id = ? AND LOWER(role) IN ('admin') AND status = 'Active'"
            : "SELECT id FROM users WHERE LOWER(role) IN ('admin') AND status = 'Active'";
        $params = $station_id ? [$station_id] : [];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adm_id) {
            notify($pdo, (int)$adm_id, 'admin', $type, $event_type, $severity,
                   $title, $message, $source_key . '_a' . $adm_id,
                   $redirect_url, $ref_type, $ref_id);
        }
    } catch (Throwable $e) { error_log('notify_admin error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_superadmin() — system-level only
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_superadmin')) {
function notify_superadmin(
    PDO    $pdo,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0
): void {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('superadmin','developer') AND status = 'Active'");
        $sa_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sa_ids as $sa_id) {
            notify($pdo, (int)$sa_id, 'superadmin', $type, $event_type, $severity,
                   $title, $message, $source_key . '_sa' . $sa_id,
                   $redirect_url, $ref_type, $ref_id);
        }
    } catch (Throwable $e) { error_log('notify_superadmin error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notification_redirect_url() — build clickable URL from reference_type + role
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notification_redirect_url')) {
function notification_redirect_url(string $ref_type, int $ref_id, string $role): string {
    $id = $ref_id > 0 ? "?id={$ref_id}" : '';
    $map = [
        // Staff
        'stock_request' => [
            'staff'    => "staff_stock_requests.php{$id}",
            'manager'  => "manager_inventory_stock_requests.php{$id}",
            'admin'    => "admin_approve_stock_requests.php{$id}",
        ],
        'master_data_request' => [
            'staff'    => "staff_requests.php{$id}",
            'manager'  => "manager_review_stock_requests.php{$id}",
            'admin'    => "admin_stock_requests_monitor.php{$id}",
        ],
        'void_request' => [
            'staff'    => "voided_transactions.php{$id}",
            'manager'  => "manager_validated_transactions.php{$id}",
            'admin'    => "admin_voided_transactions.php{$id}",
        ],
        'transaction_adjustment' => [
            'staff'    => "staff_fuel_sales_report.php{$id}",
            'manager'  => "manager_validated_transactions.php{$id}",
            'admin'    => "admin_voided_transactions.php{$id}",
        ],
        'fuel_transaction' => [
            'staff'    => "staff_fuel_sales_closing.php",
            'manager'  => "staff_fuel_sales_closing.php",
            'admin'    => "staff_fuel_sales_closing.php",
        ],
        'fuel_sales_closing' => [
            'staff'    => "staff_fuel_sales_closing.php",
            'manager'  => "staff_fuel_sales_closing.php",
            'admin'    => "staff_fuel_sales_closing.php",
        ],
        'job_order' => [
            'staff'    => "job_order_detail.php{$id}",
            'manager'  => "manager_job_orders.php{$id}",
            'admin'    => "manager_job_orders.php{$id}",
        ],
        'purchase_order' => [
            'staff'    => "admin_stock_confirmation.php{$id}",
            'manager'  => "admin_stock_confirmation.php{$id}",
            'admin'    => "admin_stock_confirmation.php{$id}",
        ],
        'user_account' => [
            'superadmin' => "superadmin_admin_management.php{$id}",
            'admin'      => "superadmin_admin_management.php{$id}",
        ],
    ];
    return $map[$ref_type][$role] ?? $map[$ref_type]['staff'] ?? 'notifications.php';
}
}
PHP;

file_put_contents($lib_path, $clean_header . $tail . "\n");
echo "lib.php updated successfully.\n";
