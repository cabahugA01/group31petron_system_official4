<?php
$pmaPdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");
$dbPdo  = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$dbName = 'petron_pos_db_secure';

// 1. Get all tables in database
$stmt = $dbPdo->query("SHOW TABLES");
$allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Define layout map
$layout = [
    // === ZONE 1: CORE_ORGANIZATION ===
    'stations'                   => [50, 50],
    'ph_regions'                 => [50, 420],
    'employee_documents'         => [50, 790],
    'users'                      => [380, 50],
    'mechanics'                  => [380, 420],
    'login_attempts'             => [380, 790],
    'user_preferences'           => [710, 50],
    'user_form_drafts'           => [710, 420],
    'password_reset_tokens'      => [710, 790],

    // === ZONE 2: FUEL_MANAGEMENT ===
    'fuel_types'                 => [1150, 50],
    'fuel_pricing'               => [1480, 50],
    'fuel_price_log'             => [1810, 50],
    'fuel_price_history'         => [2140, 50],

    'fuel_inventory'             => [1150, 420],
    'fuel_pumps'                 => [1480, 420],
    'fuel_batches'               => [1810, 420],
    'fuel_suppliers'             => [2140, 420],

    'fuel_deliveries'            => [1150, 790],
    'fuel_stock_in'              => [1480, 790],
    'fuel_purchase_orders'       => [1810, 790],
    'fuel_management_config'     => [2140, 790],

    'fuel_stock_requests'        => [1150, 1160],
    'fuel_stock_request_audit'   => [1480, 1160],
    'fuel_adjustments'           => [1810, 1160],
    'fuel_calibration_records'   => [2140, 1160],

    'fuel_sales_closing'         => [1150, 1530],
    'fuel_transactions'          => [1480, 1530],
    'fuel_variance_reports'      => [1810, 1530],
    'fuel_config_history'        => [2140, 1530],
    'fuel_status_history'        => [2470, 1530],

    // === ZONE 3: MERCHANDISE_INVENTORY ===
    'categories'                 => [2600, 50],
    'product_categories'         => [2930, 50],
    'product_types'              => [3260, 50],
    'products'                   => [3590, 50],

    'inventory_products'         => [2600, 420],
    'station_inventory'          => [2930, 420],
    'merchandise_batches'        => [3260, 420],
    'merchandise_stock_in'       => [3590, 420],

    'suppliers'                  => [2600, 790],
    'purchase_orders'            => [2930, 790],
    'purchase_order_items'       => [3260, 790],
    'deliveries_oversight'       => [3590, 790],

    'stock_requests'             => [2600, 1160],
    'stock_request_audit'        => [2930, 1160],
    'merchandise_adjustments'    => [3260, 1160],
    'inventory_logs'             => [3590, 1160],

    'pending_price_approvals'    => [2600, 1530],
    'product_price_history'      => [2930, 1530],
    'product_config_history'     => [3260, 1530],
    'product_status_history'     => [3590, 1530],
    'master_data_requests'       => [3920, 1530],

    // === ZONE 4: POS_TRANSACTIONS ===
    'merchandise_transactions'       => [50, 1950],
    'merchandise_transaction_items'   => [380, 1950],
    'merchandise_transaction_audit'   => [710, 1950],
    'voided_transactions'            => [1040, 1950],

    'transaction_adjustments'        => [50, 2320],
    'transaction_requests'           => [380, 2320],
    'payment_methods'                => [710, 2320],
    'payment_audit_log'              => [1040, 2320],

    'shifts'                         => [50, 2690],
    'shift_periods'                  => [380, 2690],
    'labor_sessions'                 => [710, 2690],

    // === ZONE 5: SERVICES_JOB_ORDERS ===
    'service_categories'         => [1450, 1950],
    'service_rates'              => [1780, 1950],
    'service_fee_history'        => [2110, 1950],

    'vehicle_types'              => [1450, 2320],
    'vehicle_inspection_items'   => [1780, 2320],
    'service_parts_mapping'      => [2110, 2320],

    'job_orders'                 => [1450, 2690],
    'job_order_parts'            => [1780, 2690],
    'job_order_service_types'    => [2110, 2690],

    // === ZONE 6: CUSTOMERS_LOYALTY ===
    'customers'                      => [2550, 1950],
    'customer_vehicles'              => [2880, 1950],
    'customer_timeline'              => [3210, 1950],

    'customer_credit_transactions'   => [2550, 2320],
    'customer_accounts_receivable'   => [2880, 2320],
    'customer_requests'              => [3210, 2320],

    'loyalty_programs'               => [2550, 2690],
    'loyalty_accounts'               => [2880, 2690],
    'loyalty_transactions'           => [3210, 2690],

    // === ZONE 7: SYSTEM_AUDITING_CONFIG ===
    'system_config'              => [50, 3100],
    'system_settings'            => [380, 3100],
    'system_settings_audit'      => [710, 3100],
    'module_settings'            => [1040, 3100],
    'module_config'              => [1370, 3100],
    'module_config_audit'        => [1700, 3100],
    'module_station_config'      => [2030, 3100],
    'ui_config'                  => [2360, 3100],
    'manager_color_config'       => [2690, 3100],

    'staff_color_config'         => [50, 3470],
    'staff_event_types'          => [380, 3470],
    'staff_calendar_events'      => [710, 3470],
    'manager_meetings'           => [1040, 3470],
    'admin_compliance_deadlines' => [1370, 3470],
    'notifications'              => [1700, 3470],
    'activity_logs'              => [2030, 3470],
    'audit_logs'                 => [2360, 3470],
    'audit_trail'                => [2690, 3470],

    'integration_audit'          => [50, 3840],
    'error_tracking_logs'        => [380, 3840],
    'system_error_logs'          => [710, 3840],
    'sys_health_report_log'      => [1040, 3840],
    'database_backups'           => [1370, 3840],
    'restore_logs'               => [1700, 3840],
    'variance_alerts'            => [2030, 3840],
    'adjustment_history'         => [2360, 3840],
    'adjustment_types'           => [2690, 3840],
];

// Clean existing records for this DB
$pmaPdo->prepare("DELETE FROM pma__table_coords WHERE db_name = ?")->execute([$dbName]);
$pmaPdo->prepare("DELETE FROM pma__pdf_pages WHERE db_name = ?")->execute([$dbName]);

// Create PDF page
$pStmt = $pmaPdo->prepare("INSERT INTO pma__pdf_pages (db_name, page_descr) VALUES (?, ?)");
$pStmt->execute([$dbName, 'Petron Station Management Schema (3NF)']);
$pageNr = (int)$pmaPdo->lastInsertId();

echo "Created Designer Page ID: $pageNr ('Petron Station Management Schema (3NF)')\n";

$insStmt = $pmaPdo->prepare("
    INSERT INTO pma__table_coords (db_name, table_name, pdf_page_number, x, y)
    VALUES (?, ?, ?, ?, ?)
");

$savedCount = 0;
foreach ($allTables as $tbl) {
    if (isset($layout[$tbl])) {
        list($x, $y) = $layout[$tbl];
    } else {
        // Fallback for any new unmapped table
        $x = 3000;
        $y = 3840;
    }

    // Insert for default view (page 0)
    $insStmt->execute([$dbName, $tbl, 0, $x, $y]);
    // Insert for saved page view ($pageNr)
    $insStmt->execute([$dbName, $tbl, $pageNr, $x, $y]);
    $savedCount++;
}

echo "Successfully arranged $savedCount tables in phpMyAdmin Designer with NO OVERLAPPING coordinates!\n";

// Update pma__designer_settings
$settings = [
    'snap_to_grid' => '0',
    'angular_direct' => 'direct',
    'side_menu' => 'false',
    'small_big' => 'v',
    'pin_text' => 'true'
];
$pmaPdo->prepare("REPLACE INTO pma__designer_settings (username, settings_data) VALUES ('root', ?)")
       ->execute([json_encode($settings)]);

echo "Updated Designer visual settings.\n";
