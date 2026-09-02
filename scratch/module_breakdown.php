<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$modules = [
    'CORE_ORGANIZATION' => ['ph_regions', 'stations', 'users', 'roles', 'mechanics', 'user_preferences', 'user_form_drafts', 'employee_documents', 'login_attempts', 'password_reset_tokens'],
    'FUEL_MANAGEMENT' => ['fuel_types', 'fuel_pricing', 'fuel_price_log', 'fuel_price_history', 'fuel_inventory', 'fuel_pumps', 'fuel_batches', 'fuel_deliveries', 'fuel_stock_in', 'fuel_stock_requests', 'fuel_stock_request_audit', 'fuel_adjustments', 'fuel_calibration_records', 'fuel_variance_reports', 'fuel_config_history', 'fuel_status_history', 'fuel_management_config', 'fuel_purchase_orders', 'fuel_suppliers', 'fuel_sales_closing', 'fuel_transactions'],
    'MERCHANDISE_INVENTORY' => ['categories', 'product_categories', 'product_types', 'products', 'inventory_products', 'station_inventory', 'merchandise_batches', 'merchandise_stock_in', 'merchandise_adjustments', 'stock_requests', 'stock_request_audit', 'purchase_orders', 'purchase_order_items', 'suppliers', 'deliveries_oversight', 'inventory_logs', 'product_price_history', 'product_config_history', 'product_status_history', 'pending_price_approvals', 'master_data_requests'],
    'POS_TRANSACTIONS' => ['merchandise_transactions', 'merchandise_transaction_items', 'merchandise_transaction_audit', 'voided_transactions', 'transaction_adjustments', 'transaction_requests', 'payment_methods', 'payment_audit_log', 'shifts', 'shift_periods', 'labor_sessions'],
    'SERVICES_JOB_ORDERS' => ['service_categories', 'service_rates', 'service_fee_history', 'service_parts_mapping', 'vehicle_types', 'vehicle_inspection_items', 'job_orders', 'job_order_parts', 'job_order_service_types'],
    'CUSTOMERS_LOYALTY' => ['customers', 'customer_vehicles', 'customer_credit_transactions', 'customer_accounts_receivable', 'customer_timeline', 'customer_requests', 'loyalty_programs', 'loyalty_accounts', 'loyalty_transactions'],
    'SYSTEM_AUDITING_CONFIG' => ['system_config', 'system_settings', 'system_settings_audit', 'module_settings', 'module_config', 'module_config_audit', 'module_station_config', 'ui_config', 'manager_color_config', 'staff_color_config', 'staff_event_types', 'staff_calendar_events', 'manager_meetings', 'admin_compliance_deadlines', 'notifications', 'activity_logs', 'audit_logs', 'audit_trail', 'integration_audit', 'error_tracking_logs', 'system_error_logs', 'sys_health_report_log', 'database_backups', 'restore_logs', 'variance_alerts', 'adjustment_history', 'adjustment_types']
];

$assigned = [];
foreach ($modules as $mod => $tbls) {
    echo "=== MODULE: $mod (" . count($tbls) . " tables) ===\n";
    foreach ($tbls as $tbl) {
        if (in_array($tbl, $tables)) {
            $assigned[] = $tbl;
            echo "  ✓ $tbl\n";
        } else {
            echo "  ✗ $tbl (NOT in DB)\n";
        }
    }
    echo "\n";
}

$unassigned = array_diff($tables, $assigned);
if (!empty($unassigned)) {
    echo "=== UNASSIGNED TABLES ===\n";
    print_r($unassigned);
}
