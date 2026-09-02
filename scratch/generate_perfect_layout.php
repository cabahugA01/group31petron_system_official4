<?php
$dbPdo  = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$pmaPdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");

$dbName = 'petron_pos_db_secure';

// 1. Get exact column counts for all tables
$stmt = $dbPdo->query("
    SELECT TABLE_NAME, COUNT(*) as col_count 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = '$dbName' 
    GROUP BY TABLE_NAME
");
$colCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Helper function to calculate table pixel height
function getTableHeight($tableName, $colCounts) {
    $cols = $colCounts[$tableName] ?? 8;
    // phpMyAdmin table box: Header ~45px, each column ~24px, Footer/Padding ~30px
    return 45 + ($cols * 24) + 30;
}

// 2. Define Master Column Structure (25 Columns)
$masterColumns = [
    // --- MODULE 1: CORE & ORGANIZATION ---
    0 => ['stations', 'ph_regions', 'employee_documents'],
    1 => ['users', 'mechanics', 'login_attempts'],
    2 => ['user_preferences', 'user_form_drafts', 'password_reset_tokens'],

    // --- MODULE 2: FUEL MANAGEMENT ---
    3 => ['fuel_types', 'fuel_inventory', 'fuel_deliveries', 'fuel_sales_closing'],
    4 => ['fuel_pricing', 'fuel_pumps', 'fuel_stock_in', 'fuel_transactions'],
    5 => ['fuel_price_log', 'fuel_batches', 'fuel_purchase_orders', 'fuel_variance_reports'],
    6 => ['fuel_price_history', 'fuel_suppliers', 'fuel_management_config', 'fuel_stock_requests', 'fuel_stock_request_audit', 'fuel_adjustments', 'fuel_calibration_records', 'fuel_config_history', 'fuel_status_history'],

    // --- MODULE 3: MERCHANDISE INVENTORY ---
    7  => ['products', 'inventory_products', 'stock_requests', 'pending_price_approvals'],
    8  => ['product_categories', 'station_inventory', 'stock_request_audit', 'product_price_history'],
    9  => ['categories', 'product_types', 'merchandise_batches', 'purchase_orders', 'purchase_order_items', 'product_config_history'],
    10 => ['suppliers', 'merchandise_stock_in', 'merchandise_adjustments', 'deliveries_oversight', 'inventory_logs', 'product_status_history', 'master_data_requests'],

    // --- MODULE 4: POS & TRANSACTIONS ---
    11 => ['merchandise_transactions', 'shifts'],
    12 => ['merchandise_transaction_items', 'payment_methods', 'shift_periods'],
    13 => ['merchandise_transaction_audit', 'voided_transactions', 'transaction_adjustments', 'transaction_requests', 'payment_audit_log', 'labor_sessions'],

    // --- MODULE 5: SERVICES & JOB ORDERS ---
    14 => ['job_orders', 'service_categories'],
    15 => ['job_order_parts', 'service_rates', 'vehicle_types'],
    16 => ['job_order_service_types', 'service_fee_history', 'service_parts_mapping', 'vehicle_inspection_items'],

    // --- MODULE 6: CUSTOMERS & LOYALTY ---
    17 => ['customers', 'customer_vehicles'],
    18 => ['customer_credit_transactions', 'customer_accounts_receivable', 'customer_timeline', 'customer_requests'],
    19 => ['loyalty_programs', 'loyalty_accounts', 'loyalty_transactions'],

    // --- MODULE 7: SYSTEM AUDITING & CONFIGURATION ---
    20 => ['system_settings', 'system_config', 'system_settings_audit', 'notifications'],
    21 => ['module_settings', 'module_config', 'module_config_audit', 'module_station_config', 'ui_config'],
    22 => ['manager_color_config', 'staff_color_config', 'staff_event_types', 'staff_calendar_events', 'manager_meetings', 'admin_compliance_deadlines'],
    23 => ['activity_logs', 'audit_logs', 'audit_trail', 'integration_audit', 'variance_alerts'],
    24 => ['error_tracking_logs', 'system_error_logs', 'sys_health_report_log', 'database_backups', 'restore_logs', 'adjustment_history', 'adjustment_types']
];

// Clean existing records in PMA
$pmaPdo->prepare("DELETE FROM pma__table_coords WHERE db_name = ?")->execute([$dbName]);
$pmaPdo->prepare("DELETE FROM pma__pdf_pages WHERE db_name = ?")->execute([$dbName]);

// Calculate Master Layout Coordinates
$masterCoords = [];
$startX = 50;
$colWidth = 430; // 430px column width ensures no horizontal collision
$verticalGutter = 90; // 90px clean vertical buffer between boxes

foreach ($masterColumns as $colIdx => $tableList) {
    $currentX = $startX + ($colIdx * $colWidth);
    $currentY = 50;

    foreach ($tableList as $tbl) {
        $masterCoords[$tbl] = [$currentX, $currentY];
        $h = getTableHeight($tbl, $colCounts);
        $currentY += $h + $verticalGutter;
    }
}

// 3. Create Master Page (Page 1) and Default Page (Page 0)
$pStmt = $pmaPdo->prepare("INSERT INTO pma__pdf_pages (db_name, page_descr) VALUES (?, ?)");
$pStmt->execute([$dbName, '01. Full System Schema (All 107 Tables)']);
$masterPageNr = (int)$pmaPdo->lastInsertId();

$insStmt = $pmaPdo->prepare("
    INSERT INTO pma__table_coords (db_name, table_name, pdf_page_number, x, y)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($masterCoords as $tbl => $pos) {
    list($x, $y) = $pos;
    // Page 0 (initial designer load)
    $insStmt->execute([$dbName, $tbl, 0, $x, $y]);
    // Page 1 (Master Page)
    $insStmt->execute([$dbName, $tbl, $masterPageNr, $x, $y]);
}

echo "Created Master Page ($masterPageNr) with " . count($masterCoords) . " tables.\n";

// 4. Create Modular Sub-Pages for ultra-focused viewing
$subModules = [
    '02. Core Organization & Users' => [
        ['stations', 'ph_regions', 'employee_documents'],
        ['users', 'mechanics', 'login_attempts'],
        ['user_preferences', 'user_form_drafts', 'password_reset_tokens']
    ],
    '03. Fuel Management & Inventory' => [
        ['fuel_types', 'fuel_inventory', 'fuel_deliveries', 'fuel_sales_closing'],
        ['fuel_pricing', 'fuel_pumps', 'fuel_stock_in', 'fuel_transactions'],
        ['fuel_price_log', 'fuel_batches', 'fuel_purchase_orders', 'fuel_variance_reports'],
        ['fuel_price_history', 'fuel_suppliers', 'fuel_management_config', 'fuel_stock_requests', 'fuel_stock_request_audit', 'fuel_adjustments', 'fuel_calibration_records', 'fuel_config_history', 'fuel_status_history']
    ],
    '04. Merchandise & Inventory' => [
        ['products', 'inventory_products', 'stock_requests', 'pending_price_approvals'],
        ['product_categories', 'station_inventory', 'stock_request_audit', 'product_price_history'],
        ['categories', 'product_types', 'merchandise_batches', 'purchase_orders', 'purchase_order_items', 'product_config_history'],
        ['suppliers', 'merchandise_stock_in', 'merchandise_adjustments', 'deliveries_oversight', 'inventory_logs', 'product_status_history', 'master_data_requests']
    ],
    '05. POS, Transactions & Shifts' => [
        ['merchandise_transactions', 'shifts'],
        ['merchandise_transaction_items', 'payment_methods', 'shift_periods'],
        ['merchandise_transaction_audit', 'voided_transactions', 'transaction_adjustments', 'transaction_requests', 'payment_audit_log', 'labor_sessions']
    ],
    '06. Services & Job Orders' => [
        ['job_orders', 'service_categories'],
        ['job_order_parts', 'service_rates', 'vehicle_types'],
        ['job_order_service_types', 'service_fee_history', 'service_parts_mapping', 'vehicle_inspection_items']
    ],
    '07. Customers & Loyalty' => [
        ['customers', 'customer_vehicles'],
        ['customer_credit_transactions', 'customer_accounts_receivable', 'customer_timeline', 'customer_requests'],
        ['loyalty_programs', 'loyalty_accounts', 'loyalty_transactions']
    ],
    '08. System Config & Auditing' => [
        ['system_settings', 'system_config', 'system_settings_audit', 'notifications'],
        ['module_settings', 'module_config', 'module_config_audit', 'module_station_config', 'ui_config'],
        ['manager_color_config', 'staff_color_config', 'staff_event_types', 'staff_calendar_events', 'manager_meetings', 'admin_compliance_deadlines'],
        ['activity_logs', 'audit_logs', 'audit_trail', 'integration_audit', 'variance_alerts'],
        ['error_tracking_logs', 'system_error_logs', 'sys_health_report_log', 'database_backups', 'restore_logs', 'adjustment_history', 'adjustment_types']
    ]
];

foreach ($subModules as $pageTitle => $colGroups) {
    $pStmt->execute([$dbName, $pageTitle]);
    $subPageNr = (int)$pmaPdo->lastInsertId();
    
    $subStartX = 50;
    $subColWidth = 430;
    
    foreach ($colGroups as $subColIdx => $tblList) {
        $subX = $subStartX + ($subColIdx * $subColWidth);
        $subY = 50;
        
        foreach ($tblList as $t) {
            $insStmt->execute([$dbName, $t, $subPageNr, $subX, $subY]);
            $h = getTableHeight($t, $colCounts);
            $subY += $h + 90;
        }
    }
    echo "Created Page $subPageNr: '$pageTitle'\n";
}

// 5. Update PMA Designer Settings
$settings = [
    'snap_to_grid' => '0',
    'angular_direct' => 'direct',
    'side_menu' => 'false',
    'small_big' => 'v',
    'pin_text' => 'true'
];
$pmaPdo->prepare("REPLACE INTO pma__designer_settings (username, settings_data) VALUES ('root', ?)")
       ->execute([json_encode($settings)]);

echo "Successfully generated and saved all dynamic layouts with ZERO collisions!\n";
