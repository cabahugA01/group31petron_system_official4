<?php
// RBAC-Based Menu Generation
// Master menu array with all possible items and their permission requirements
$master_menu = [
    // Dashboard - Everyone gets some form of dashboard
    ['id'=>'dashboard','label'=>'Dashboard','ico'=>'fas fa-tachometer-alt','href'=>'staff_dashboard.php', 'permissions'=>['view_dashboard'], 'station_specific'=>false],

    // Manager Dashboard - dedicated command center for manager role
    ['id'=>'manager_dashboard','label'=>'Dashboard','ico'=>'fas fa-gauge-high','href'=>'manager_dashboard.php','permissions'=>['approve_transactions','manage_job_orders'],'station_specific'=>true],
    
    // Transactions & POS - Managers and Staff only (Admin/Owner excluded)
    ['id'=>'transactions','label'=>'Transactions','ico'=>'fas fa-shopping-cart','href'=>'staff_transactions_hub.php?section=merchandise','permissions'=>['create_transactions', 'view_transactions', 'approve_transactions'],'station_specific'=>true],

        
    // Job Orders - Managers handle operations, Staff create
    ['id'=>'job_orders','label'=>'Job Orders','ico'=>'fas fa-wrench','href'=>'staff_transactions_hub.php?section=merchandise&active_tab=encode_jo','permissions'=>['manage_job_orders', 'create_job_orders'],'station_specific'=>true],
    
    // Fuel Management - Managers handle operations, Staff do encoding
    ['id'=>'fuel','label'=>'Fuel Management','ico'=>'fas fa-gas-pump','href'=>'fuel_readings_encoding.php','permissions'=>['manage_fuel', 'encode_fuel', 'view_fuel_variance'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'fuel_readings','label'=>'Fuel Readings','ico'=>'fas fa-tachometer-alt','href'=>'fuel_management.php','permissions'=>['encode_fuel']],
        ['id'=>'fuel_inventory','label'=>'Fuel Inventory','ico'=>'fas fa-database','href'=>'fuel_inventory.php','permissions'=>['encode_fuel']],
        ['id'=>'fuel_deliveries','label'=>'Fuel Deliveries','ico'=>'fas fa-truck','href'=>'staff_fuel_deliveries.php','permissions'=>['encode_fuel']],
    ]],
    
    // Inventory - Staff access / Manager has own sub-items via override below
    ['id'=>'inventory','label'=>'Inventory','ico'=>'fas fa-warehouse','href'=>'staff_inventory.php','permissions'=>['view_inventory','manage_inventory'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'inv_fuel',    'label'=>'Fuel Inventory',       'href'=>'staff_inventory.php#fuel',    'permissions'=>['view_inventory']],
        ['id'=>'inv_merch',   'label'=>'Merchandise Inventory','href'=>'staff_inventory.php#merch',   'permissions'=>['view_inventory']],
        ['id'=>'inv_history', 'label'=>'Inventory History',    'href'=>'staff_inventory.php#history', 'permissions'=>['view_inventory']],
    ]],

    
    // Customers - Staff access
    ['id'=>'customers','label'=>'Customers','ico'=>'fas fa-users','href'=>'customers.php','permissions'=>['create_transactions','view_transactions'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'customer_encode',   'label'=>'Customer List',           'href'=>'customers.php?section=encode',   'permissions'=>['create_transactions','view_transactions']],
        ['id'=>'customer_history',  'label'=>'Customer History',        'href'=>'customers.php?section=history',  'permissions'=>['view_transactions']],
        ['id'=>'customer_linkage',  'label'=>'Transaction Linkage',     'href'=>'customers.php?section=linkage',  'permissions'=>['create_transactions']],
    ]],

    // Customers - Manager access (separate page with approval/oversight)
    ['id'=>'mgr_customers','label'=>'Customers','ico'=>'fas fa-users','href'=>'manager_customers.php','permissions'=>['approve_transactions','view_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_cust_records',      'label'=>'Customer Records',         'href'=>'manager_customers.php?section=records',       'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_cust_balances',     'label'=>'Balances Monitoring',      'href'=>'manager_customers.php?section=balances',      'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_cust_validation',   'label'=>'Validation & Oversight',   'href'=>'manager_customers.php?section=validation',    'permissions'=>['approve_transactions','manage_job_orders']],
    ]],
    
    // Deliveries Management - Staff (Merchandise ONLY — Fuel is under Fuel Management)
    ['id'=>'staff_deliveries','label'=>'Merchandise Deliveries','ico'=>'fas fa-boxes','href'=>'#','permissions'=>['manage_inventory','view_inventory','encode_fuel','create_transactions'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'staff_record_del', 'label'=>'Record Delivery',        'href'=>'staff_record_delivery.php',  'permissions'=>['manage_inventory','encode_fuel','create_transactions']],
        ['id'=>'staff_del_manage', 'label'=>'Delivery History',        'href'=>'staff_delivery_history.php', 'permissions'=>['manage_inventory','encode_fuel','create_transactions']],
    ]],

    // Deliveries Management - Manager (Validation & History)
    ['id'=>'manager_deliveries','label'=>'Deliveries Management','ico'=>'fas fa-truck','href'=>'manager_deliveries.php','permissions'=>['approve_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_del_manage',  'label'=>'Manage Deliveries', 'href'=>'manager_deliveries.php?section=manage',  'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_del_history', 'label'=>'Delivery History',  'href'=>'manager_deliveries.php?section=history', 'permissions'=>['approve_transactions','manage_job_orders']],
    ]],

    // Product Management - Manager (view/manage products & pricing)
    ['id'=>'product_management','label'=>'Product Management','ico'=>'fas fa-boxes','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory','approve_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_prod_merchandise','label'=>'Merchandise Products','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory']],
        ['id'=>'mgr_prod_fuel',       'label'=>'Fuel Products',       'href'=>'manager_product_fuel.php',       'permissions'=>['manage_inventory','view_inventory']],
        ['id'=>'mgr_prod_prices',     'label'=>'Approve Prices',      'href'=>'manager_approve_prices.php',     'permissions'=>['approve_transactions','manage_job_orders']],
    ]],

    // Calendar - Staff & Manager
    ['id'=>'calendar','label'=>'Calendar','ico'=>'fas fa-calendar-alt','href'=>'staff_calendar.php','permissions'=>['view_dashboard','create_transactions','encode_fuel','manage_job_orders','create_job_orders','approve_transactions'],'station_specific'=>true],

    // Reports - Different access levels
    ['id'=>'reports','label'=>'Reports','ico'=>'fas fa-chart-bar','href'=>'staff_reports.php','permissions'=>['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'report_daily_sales',       'label'=>'Daily Sales Summary',               'href'=>'staff_reports.php?view=daily_sales',        'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_jo_tracker',        'label'=>'Job Order Tracker Report',          'href'=>'staff_reports.php?view=jo_tracker',         'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_activity',          'label'=>'Personal Activity Report',          'href'=>'staff_reports.php?view=personal_activity',  'permissions'=>['view_personal_reports']],
        ['id'=>'report_fuel_meter',        'label'=>'Meter Reading Report',              'href'=>'staff_reports.php?view=meter_readings',     'permissions'=>['encode_fuel','view_personal_reports']],
        ['id'=>'report_fuel_deliveries',   'label'=>'Fuel Deliveries Report',            'href'=>'staff_reports.php?view=fuel_deliveries',    'permissions'=>['encode_fuel','view_personal_reports']],
        ['id'=>'report_merch_deliveries',  'label'=>'Merchandise Deliveries Report',     'href'=>'staff_reports.php?view=merch_deliveries',   'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_inv_movement',      'label'=>'Inventory Movement Report',         'href'=>'staff_reports.php?view=inventory_movement', 'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_payment_status',    'label'=>'Payment Status Report',             'href'=>'staff_reports.php?view=payment_status',     'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_cust_linkage',      'label'=>'Customer Transaction Linkage Report','href'=>'staff_reports.php?view=customer_linkage',   'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_audit_trail',       'label'=>'Audit Trail Report',                'href'=>'staff_reports.php?view=audit_trail',        'permissions'=>['view_personal_reports','view_operational_reports']],
    ]],
    
    // ── SUPERADMIN / DEVELOPER SIDEBAR ──────────────────────────────────────────
    // Exact 9-item spec. Developer-focused, no business ops.
    // Audit trail lives ONLY in System Logs & Audit — not duplicated elsewhere.

    // 1. System Dashboard
    ['id'=>'super_admin_dashboard','label'=>'System Dashboard','ico'=>'fas fa-server','href'=>'super_admin_dashboard.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 2. Admin Management
    ['id'=>'admin_management','label'=>'Admin Management','ico'=>'fas fa-user-shield','href'=>'superadmin_admin_management.php','permissions'=>['manage_all_users'],'station_specific'=>false],

    // 3. Station Assignment
    ['id'=>'station_management','label'=>'Station Assignment','ico'=>'fas fa-map-marker-alt','href'=>'superadmin_station_management.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 4. Module Configuration
    ['id'=>'module_config','label'=>'Module Configuration','ico'=>'fas fa-sliders-h','href'=>'module_configuration.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 5. Database Management
    ['id'=>'database_management','label'=>'Database Management','ico'=>'fas fa-database','href'=>'superadmin_database_management.php?section=view_tables','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'dbm_view_tables', 'label'=>'View Tables',       'href'=>'superadmin_database_management.php?section=view_tables',  'permissions'=>['manage_stations']],
        ['id'=>'dbm_maintenance', 'label'=>'Maintenance Scripts','href'=>'superadmin_database_management.php?section=maintenance', 'permissions'=>['manage_stations']],
        ['id'=>'dbm_soft_delete', 'label'=>'Soft Delete Records','href'=>'superadmin_database_management.php?section=soft_delete', 'permissions'=>['manage_stations']],
    ]],

    // 6. System Logs & Audit  ← ONLY place for audit trail
    ['id'=>'system_logs','label'=>'System Logs & Audit','ico'=>'fas fa-shield-alt','href'=>'superadmin_system_logs.php?section=audit_trail','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'sla_audit_trail',   'label'=>'Audit Trail',       'href'=>'superadmin_system_logs.php?section=audit_trail',   'permissions'=>['manage_stations']],
        ['id'=>'sla_error_tracking','label'=>'Error Tracking',    'href'=>'superadmin_system_logs.php?section=error_tracking', 'permissions'=>['manage_stations']],
        ['id'=>'sla_export_logs',   'label'=>'Export Logs',       'href'=>'superadmin_system_logs.php?section=export_logs',    'permissions'=>['manage_stations']],
        ['id'=>'sla_developer_log', 'label'=>'SuperAdmin Audit',  'href'=>'superadmin_system_logs.php?section=developer_log',  'permissions'=>['manage_stations']],
    ]],

    // 7. Integration Settings
    ['id'=>'integration_settings','label'=>'Integration Settings','ico'=>'fas fa-plug','href'=>'superadmin_integration_settings.php?section=pos_import','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'int_pos_import',    'label'=>'POS Import Config', 'href'=>'superadmin_integration_settings.php?section=pos_import',    'permissions'=>['manage_stations']],
        ['id'=>'int_api_endpoints', 'label'=>'API Endpoints',     'href'=>'superadmin_integration_settings.php?section=api_endpoints', 'permissions'=>['manage_stations']],
        ['id'=>'int_sync_rules',    'label'=>'Sync Rules',        'href'=>'superadmin_integration_settings.php?section=sync_rules',    'permissions'=>['manage_stations']],
    ]],

    // 8. Reports (Developer View)
    ['id'=>'superadmin_reports','label'=>'Reports (Dev View)','ico'=>'fas fa-chart-line','href'=>'superadmin_reports.php','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'rpt_dev_technical', 'label'=>'Technical Reports',  'href'=>'superadmin_reports.php?section=technical',      'permissions'=>['manage_stations']],
        ['id'=>'rpt_dev_security',  'label'=>'Security Reports',   'href'=>'superadmin_reports.php?section=security',       'permissions'=>['manage_stations']],
        ['id'=>'rpt_dev_audit',     'label'=>'Dev Audit Reports',  'href'=>'superadmin_reports.php?section=developer_audit','permissions'=>['manage_stations']],
    ]],

    // 9. System Settings
    ['id'=>'system_settings','label'=>'System Settings','ico'=>'fas fa-cog','href'=>'superadmin_system_settings.php','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'ss_logo',          'label'=>'Logo Management',    'href'=>'superadmin_system_settings.php#step-logo',          'permissions'=>['manage_stations']],
        ['id'=>'ss_theme',         'label'=>'Color Theme / UI',   'href'=>'superadmin_system_settings.php#step-theme',         'permissions'=>['manage_stations']],
        ['id'=>'ss_layout',        'label'=>'Sidebar & Cards',    'href'=>'superadmin_system_settings.php#step-layout',        'permissions'=>['manage_stations']],
        ['id'=>'ss_accessibility', 'label'=>'Accessibility',      'href'=>'superadmin_system_settings.php#step-accessibility', 'permissions'=>['manage_stations']],
    ]],

    ];

// Filter menu items based on user role and permissions
function filter_menu_by_permissions($menu_items, $user_role) {
    $filtered_menu = [];
    $user_permissions = get_user_permissions($user_role);
    $staff_hidden_report_items = ['sales_reports', 'inventory_reports', 'customer_reports', 'fuel_variance_report', 'shift_reports', 'profit_loss'];
    $staff_hidden_parent_items = ['staff', 'users', 'inventory_manager', 'admin_oversight', 'mgr_customers', 'manager_deliveries'];
    $staff_hidden_sub_items    = ['job_create'];
    $admin_hidden_parent_items = ['stations', 'transactions', 'job_orders', 'fuel', 'customers', 'mgr_customers', 'inventory', 'inventory_manager', 'purchase_orders'];
    $admin_hidden_sub_items = ['stock_requests']; // Hide staff stock requests from admin
    $manager_hidden_sub_items = ['stock_requests', 'fuel_variance_report', 'fuel_reading_tracker', 'fuel_calibration_logs', 'fuel_stock_levels', 'fuel_variance_reports']; // Hide old fuel items from manager (replaced by new sub-menu)
    $manager_hidden_parent_items = ['purchase_orders', 'customers']; // Hide staff customers from manager (manager has own pages)
    // Hide manager-only items from staff/admin
    // Audit Trail is a standalone top-level item injected after Reports for manager role
    
    // Admin Sidebar Navigation (custom, per request)
    // Note: this only changes what Admin sees in the sidebar; it does not change any page/module behavior.
    if ($user_role === 'admin') {
        return [
            // 1. Dashboard
            [
                'id' => 'admin_dashboard',
                'label' => 'Dashboard',
                'ico' => 'fas fa-tachometer-alt',
                'href' => 'admin_dashboard.php',
                'permissions' => ['view_dashboard'],
                'station_specific' => false,
            ],
            // 2. User Management
            [
                'id' => 'users',
                'label' => 'User Management',
                'ico' => 'fas fa-user-cog',
                'href' => 'users.php',
                'permissions' => ['manage_all_users', 'manage_users_station'],
                'station_specific' => false,
            ],
            // 3. Staff Oversight
            [
                'id' => 'staff_oversight_admin',
                'label' => 'Staff Oversight',
                'ico' => 'fas fa-users-cog',
                'href' => 'admin_staff_oversight.php',
                'permissions' => ['manage_staff_oversight', 'view_all_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 4. Transactions Oversight (read-only: Fuel, Merchandise, Job Orders) — tabs handled inside the page
            [
                'id' => 'admin_transactions_oversight',
                'label' => 'Transactions Oversight',
                'ico' => 'fas fa-eye',
                'href' => 'admin_transactions_oversight.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 5. Product & Pricing Management
            [
                'id' => 'product_pricing',
                'label' => 'Product & Pricing Management',
                'ico' => 'fas fa-tags',
                'href' => 'admin_set_prices.php',
                'permissions' => ['manage_system_settings', 'view_all_reports', 'view_dashboard'],
                'station_specific' => false,
            ],
            // 6. Purchase Orders
            [
                'id' => 'purchase_orders_admin',
                'label' => 'Purchase Orders',
                'ico' => 'fas fa-file-invoice-dollar',
                'href' => 'purchase_orders.php',
                'permissions' => ['view_all_reports', 'view_operational_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 7. Deliveries Oversight
            [
                'id' => 'deliveries_oversight',
                'label' => 'Deliveries Oversight',
                'ico' => 'fas fa-truck',
                'href' => 'admin_deliveries_oversight.php',
                'permissions' => ['view_all_reports', 'view_operational_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 8. Calendar
            [
                'id' => 'admin_calendar',
                'label' => 'Calendar',
                'ico' => 'fas fa-calendar-check',
                'href' => 'admin_calendar.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 9. Stock Requests Oversight
            [
                'id' => 'stock_requests_admin',
                'label' => 'Stock Requests',
                'ico' => 'fas fa-boxes',
                'href' => 'stock_requests_admin.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    ['id' => 'sr_admin_requests', 'label' => 'All Requests',  'href' => 'stock_requests_admin.php?tab=requests', 'permissions' => ['view_all_reports']],
                    ['id' => 'sr_admin_audit',    'label' => 'Audit Trail',   'href' => 'stock_requests_admin.php?tab=audit',    'permissions' => ['view_all_reports']],
                ],
            ],
            // 10. Reports & Audit Trail
            [
                'id' => 'reports_audit_admin',
                'label' => 'Reports & Audit Trail',
                'ico' => 'fas fa-chart-bar',
                'href' => 'admin_reports_audit.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    ['id' => 'rpt_sales',      'label' => 'Sales Reports',       'href' => 'admin_reports_audit.php?tab=sales',       'permissions' => ['view_all_reports']],
                    ['id' => 'rpt_variance',   'label' => 'Variance Reports',    'href' => 'admin_reports_audit.php?tab=variance',    'permissions' => ['view_all_reports']],
                    ['id' => 'rpt_receivable', 'label' => 'Accounts Receivable', 'href' => 'admin_reports_audit.php?tab=balances',    'permissions' => ['view_all_reports']],
                    ['id' => 'rpt_audit',      'label' => 'Audit Logs',          'href' => 'admin_reports_audit.php?tab=audit',       'permissions' => ['view_all_reports']],
                ],
            ],
        ];
    }
    
    foreach ($menu_items as $item) {
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'users') {
                continue;
            }

            // Manager gets manager_dashboard instead of generic dashboard
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'dashboard') {
                continue; // skip generic dashboard for manager
            }
            // Non-managers don't see manager_dashboard
            if ($user_role !== 'manager' && ($item['id'] ?? '') === 'manager_dashboard') {
                continue;
            }

            if ($user_role === 'staff' && in_array($item['id'] ?? '', $staff_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'admin' && in_array($item['id'] ?? '', $admin_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'admin' && in_array($item['id'] ?? '', $admin_hidden_sub_items, true)) {
                continue;
            }

            if ($user_role === 'manager' && in_array($item['id'] ?? '', $manager_hidden_parent_items, true)) {
                continue;
            }

            if ($user_role === 'manager' && in_array($item['id'] ?? '', $manager_hidden_sub_items, true)) {
                continue;
            }

            // Check if user has permission for this menu item
        $required_permissions = $item['permissions'] ?? [];
        $has_permission = false;
        
        // If no permissions required, allow access
        if (empty($required_permissions)) {
            $has_permission = true;
        } else {
            // Check if user has any of the required permissions
            foreach ($required_permissions as $permission) {
                if (in_array($permission, $user_permissions)) {
                    $has_permission = true;
                    break;
                }
            }
        }
        
        if ($has_permission) {
            $filtered_item = $item;
            $force_direct_link = false;

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'manager_dashboard') {
                $filtered_item['href'] = 'manager_dashboard.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'staff') {
                $filtered_item['sub_items'] = [[
                    'id' => 'staff_create_user',
                    'label' => 'Create User',
                    'href' => 'users.php?view=create',
                    'permissions' => ['manage_users_station']
                ]];
            }

            if ($user_role === 'staff' && ($item['id'] ?? '') === 'dashboard') {
                $filtered_item['href'] = 'staff_dashboard.php';
            }

            if ($user_role === 'staff' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href'] = 'staff_transactions_hub.php';
                $filtered_item['ico']  = 'fas fa-exchange-alt';
                $filtered_item['sub_items'] = [
                    ['id'=>'merchandise_transaction', 'label'=>'Merchandise Transaction', 'href'=>'staff_transactions_hub.php?section=merchandise&active_tab=merchandise', 'permissions'=>['create_transactions']],
                    ['id'=>'shift_transactions',      'label'=>'Shift History',           'href'=>'staff_transactions_hub.php?section=history',                            'permissions'=>['create_transactions']],
                ];
            }

            // Job Orders sidebar item removed for staff — encode & tracker live inside Transactions
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'job_orders') {
                continue;
            }

            if ($user_role === 'staff' && ($item['id'] ?? '') === 'fuel') {
                $filtered_item['href'] = '#';
                $filtered_item['sub_items'] = [
                    ['id'=>'staff_fuel_transactions', 'label'=>'Fuel Transactions', 'href'=>'staff_transactions_hub.php?section=fuel', 'permissions'=>['encode_fuel','create_transactions']],
                    ['id'=>'staff_fuel_deliveries',   'label'=>'Fuel Deliveries',   'href'=>'staff_fuel_deliveries.php',               'permissions'=>['encode_fuel','create_transactions']],
                ];
                $filtered_menu[] = $filtered_item;
                continue;
            }


            if ($user_role === 'manager' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href'] = 'transactions.php';
                $filtered_item['label'] = 'Transactions';
                $filtered_item['sub_items'] = [
                    ['id'=>'pending_transactions',    'label'=>'Pending Merchandise/Service',  'href'=>'transactions.php',              'permissions'=>['view_transactions','approve_transactions']],
                    ['id'=>'job_order_approval',      'label'=>'Job Order Approval',           'href'=>'transactions.php?tab=jo',       'permissions'=>['view_transactions','approve_transactions','manage_job_orders']],
                    ['id'=>'variance_alerts',         'label'=>'Variance Alerts',              'href'=>'transactions_variance.php',     'permissions'=>['view_transactions','approve_transactions']],
                    ['id'=>'shift_transactions_view', 'label'=>'Shift Transactions View',      'href'=>'transactions_shift.php',        'permissions'=>['view_transactions','approve_transactions']],
                ];
            }

            // Job Orders no longer has its own sidebar item — it lives inside Transactions
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'job_orders') {
                continue; // skip — already merged into Transactions sub-menu above
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'inventory') {
                $filtered_item['href'] = 'manager_inventory_fuel.php';
                $filtered_item['ico']  = 'fas fa-warehouse';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_inv_fuel',          'label'=>'Fuel Inventory',         'href'=>'manager_inventory_fuel.php',          'permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_inv_merch',          'label'=>'Merchandise Inventory',  'href'=>'manager_inventory_merchandise.php',   'permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_inv_requests',       'label'=>'Purchase Requests',      'href'=>'manager_inventory_stock_requests.php','permissions'=>['manage_inventory','view_inventory']],
                ];
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['href'] = '#';
                $filtered_item['sub_items'] = [
                    ['id'=>'report_sales',        'label'=>'Sales Volume & Amount Report', 'href'=>'manager_reports.php?section=sales',          'permissions'=>['view_team_reports']],
                    ['id'=>'report_job_orders',   'label'=>'Job Orders Report',            'href'=>'manager_reports.php?section=job_orders',     'permissions'=>['view_team_reports']],
                    ['id'=>'report_balances',     'label'=>'Customer Balances',            'href'=>'manager_reports.php?section=balances',       'permissions'=>['view_team_reports']],
                    ['id'=>'report_deliveries',   'label'=>'Deliveries Report',            'href'=>'manager_reports.php?section=deliveries',     'permissions'=>['view_team_reports']],
                    ['id'=>'report_staff',        'label'=>'Staff Performance',            'href'=>'manager_reports.php?section=staff',          'permissions'=>['view_team_reports']],
                    ['id'=>'report_validation',   'label'=>'Validation Logs',              'href'=>'manager_reports.php?section=validation',     'permissions'=>['view_team_reports']],
                    ['id'=>'report_variance',     'label'=>'Variance Reports',             'href'=>'manager_reports.php?section=variance',       'permissions'=>['view_team_reports']],
                    ['id'=>'report_meter',        'label'=>'Validated Meter Reading',      'href'=>'manager_reports.php?section=meter_readings', 'permissions'=>['view_team_reports']],
                    ['id'=>'report_inventory',    'label'=>'Inventory Reports',            'href'=>'manager_reports.php?section=inventory',      'permissions'=>['view_team_reports']],
                    ['id'=>'report_price_logs',   'label'=>'Price Change Logs',            'href'=>'manager_reports.php?section=price_logs',     'permissions'=>['view_team_reports']],
                ];
                // keep $force_direct_link = false so sub-menu renders
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'fuel') {
                $filtered_item['href'] = 'manager_fuel_management_complete.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'fuel_transactions', 'label'=>'Fuel Transactions', 'href'=>'manager_fuel_management_complete.php#fuel-transactions', 'permissions'=>['manage_fuel']],
                    ['id'=>'fuel_deliveries',   'label'=>'Fuel Deliveries',   'href'=>'manager_fuel_management_complete.php#fuel-deliveries',   'permissions'=>['manage_fuel']],
                    ['id'=>'fuel_adjustments',  'label'=>'Adjustments',       'href'=>'manager_fuel_management_complete.php#adjustments',       'permissions'=>['manage_fuel']],
                    ['id'=>'fuel_pump_master',  'label'=>'Pump Master',       'href'=>'manager_fuel_management_complete.php#pump-master',       'permissions'=>['manage_fuel']],
                ];
                // Add directly — skip the generic sub-item filter below
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['href'] = 'manager_reports.php';
                $filtered_item['label'] = 'Reports';
                $filtered_item['ico']   = 'fas fa-chart-bar';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_report_sales',        'label'=>'Sales Volume & Amount Report', 'href'=>'manager_reports.php?section=sales',        'permissions'=>['view_operational_reports','view_financial_reports']],
                    ['id'=>'mgr_report_joborders',     'label'=>'Job Orders Report',      'href'=>'manager_reports.php?section=job_orders',   'permissions'=>['view_operational_reports','manage_job_orders']],
                    ['id'=>'mgr_report_balances',      'label'=>'Customer Balances',      'href'=>'manager_reports.php?section=balances',     'permissions'=>['view_operational_reports','view_financial_reports']],
                    ['id'=>'mgr_report_deliveries',    'label'=>'Deliveries Report',      'href'=>'manager_reports.php?section=deliveries',   'permissions'=>['view_operational_reports']],
                    ['id'=>'mgr_report_staff',         'label'=>'Staff Performance',      'href'=>'manager_reports.php?section=staff',        'permissions'=>['view_operational_reports','manage_job_orders']],
                    ['id'=>'mgr_report_validation',    'label'=>'Validation Logs',        'href'=>'manager_reports.php?section=validation',   'permissions'=>['view_operational_reports','approve_transactions']],
                    // ── Extra Reports ────────────────────────────────────────────
                    ['id'=>'mgr_report_variance',      'label'=>'Variance Reports',       'href'=>'manager_reports.php?section=variance',     'permissions'=>['view_operational_reports','manage_fuel']],
                    ['id'=>'mgr_report_meter',         'label'=>'Validated Meter Reading','href'=>'manager_reports.php?section=meter_readings', 'permissions'=>['view_operational_reports','manage_fuel']],
                    ['id'=>'mgr_report_inventory',     'label'=>'Inventory Reports',      'href'=>'manager_reports.php?section=inventory',    'permissions'=>['view_operational_reports']],
                    ['id'=>'mgr_report_price_logs',    'label'=>'Price Change Logs',      'href'=>'manager_reports.php?section=price_logs',   'permissions'=>['view_operational_reports']],
                ];
                $filtered_menu[] = $filtered_item;

                // ── Audit Trail — standalone top-level item after Reports ──
                $filtered_menu[] = [
                    'id'               => 'mgr_audit_trail',
                    'label'            => 'Audit Trail',
                    'ico'              => 'fas fa-shield-halved',
                    'href'             => 'manager_reports.php?section=audit_trail',
                    'permissions'      => ['approve_transactions','manage_job_orders'],
                    'station_specific' => true,
                    'sub_items'        => [],
                ];
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'purchase_orders') {
                $filtered_item['href'] = 'manager_purchase_orders.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'staff_deliveries') {
                continue; // Hide Staff's Record Delivery from Manager
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'manager_deliveries') {
                $filtered_item['label'] = 'Deliveries Management';
                $filtered_item['href'] = 'manager_merchandise_deliveries.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_del_manage',  'label'=>'Manage Deliveries',  'href'=>'manager_merchandise_deliveries.php', 'permissions'=>[]],
                    ['id'=>'mgr_del_history', 'label'=>'Delivery History',   'href'=>'manager_delivery_history.php',       'permissions'=>[]],
                ];
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'calendar') {
                $filtered_item['label'] = 'Calendar';
                $filtered_item['href']  = 'manager_calendar.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'product_management') {
                $filtered_item['label'] = 'Product Management';
                $filtered_item['ico']   = 'fas fa-boxes';
                $filtered_item['href']  = 'manager_product_merchandise.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_prod_merchandise','label'=>'Merchandise Products','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_prod_fuel',       'label'=>'Fuel Products',       'href'=>'manager_product_fuel.php',       'permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_prod_prices',     'label'=>'Approve Prices',      'href'=>'manager_approve_prices.php',     'permissions'=>['approve_transactions','manage_job_orders']],
                ];
                $force_direct_link = false;
            }



            // Product Management is for Manager only — hide from Staff
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'product_management') {
                continue;
            }

            // Admin/Owner specific navigation - direct to unified dashboard
            if (in_array($user_role, ['admin', 'owner']) && ($item['id'] ?? '') === 'dashboard') {
                $filtered_item['href'] = 'dashboard.php';
                $filtered_item['label'] = 'Admin Dashboard';
                $force_direct_link = true;
            }

            // Admin/Owner gets direct access to oversight items
            if (in_array($user_role, ['admin', 'owner']) && ($item['id'] ?? '') === 'admin_oversight') {
                $filtered_item['href'] = 'admin_validated_entries.php';
                $force_direct_link = true;
            }

            if ($user_role === 'superadmin' && ($item['id'] ?? '') === 'users') {
                $filtered_item['href'] = 'users.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }
            
            if ($user_role === 'superadmin' && ($item['id'] ?? '') === 'stations') {
                $filtered_item['href'] = 'station_assignment.php';
                $filtered_item['sub_items'] = [];
                $force_direct_link = true;
            }

            // Restrict Developer Reports to SuperAdmin/Developer only
            if (($item['id'] ?? '') === 'developer_reports' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Restrict Reports (Developer View) to SuperAdmin/Developer only — now handled in combined block above

            // Calendar — hide from SuperAdmin / Developer (no calendar module for superadmin)
            if (($item['id'] ?? '') === 'calendar' && in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // SuperAdmin Dashboard — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'super_admin_dashboard' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Admin Management — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'admin_management' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Station Assignment — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'station_management'
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Module Configuration — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'module_config' && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Database Management — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['database_management','dbm_view_tables','dbm_maintenance','dbm_soft_delete'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Logs & Audit — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['system_logs','sla_audit_trail','sla_error_tracking','sla_export_logs','sla_developer_log'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Integration Settings — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['integration_settings','int_pos_import','int_api_endpoints','int_sync_rules'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Reports (Developer View) — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['superadmin_reports','rpt_dev_technical','rpt_dev_security','rpt_dev_audit'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Settings — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['system_settings','ss_logo','ss_theme','ss_layout','ss_accessibility'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            if ($force_direct_link) {
                $filtered_menu[] = $filtered_item;
                continue;
            }
            
            // Filter sub-items if they exist
            if (!empty($item['sub_items'])) {
                $filtered_sub_items = [];
                foreach ($item['sub_items'] as $sub_item) {
                    if ($user_role === 'staff' && in_array($sub_item['id'] ?? '', $staff_hidden_report_items, true)) {
                        continue;
                    }

                    if ($user_role === 'staff' && in_array($sub_item['id'] ?? '', $staff_hidden_sub_items, true)) {
                        continue;
                    }

                    // Hide staff stock requests from managers
                    if ($user_role === 'manager' && in_array($sub_item['id'] ?? '', $manager_hidden_sub_items, true)) {
                        continue;
                    }

                    // Hide staff stock requests from admin
                    if ($user_role === 'admin' && in_array($sub_item['id'] ?? '', $admin_hidden_sub_items, true)) {
                        continue;
                    }

                    $sub_required_permissions = $sub_item['permissions'] ?? [];
                    $has_sub_permission = false;
                    
                    if (empty($sub_required_permissions)) {
                        $has_sub_permission = true;
                    } else {
                        foreach ($sub_required_permissions as $permission) {
                            if (in_array($permission, $user_permissions)) {
                                $has_sub_permission = true;
                                break;
                            }
                        }
                    }
                    
                    if ($has_sub_permission) {
                        $filtered_sub_items[] = $sub_item;
                    }
                }
                
                // Only include parent if it has sub-items or is directly accessible
                if (!empty($filtered_sub_items) || $item['href'] !== '#') {
                    $filtered_item['sub_items'] = $filtered_sub_items;
                    $filtered_menu[] = $filtered_item;
                }
            } else {
                $filtered_menu[] = $filtered_item;
            }
        }
    }
    
    return $filtered_menu;
}

// Get filtered menu items for current user
$items = filter_menu_by_permissions($master_menu, $role);

// ── Module-based sidebar filtering ───────────────────────────
// SuperAdmin and Developer always see all items regardless of module state.
// For all other roles, hide nav items whose module is disabled.
if (!in_array($role, ['superadmin', 'developer'], true)) {
    $module_states   = get_module_states();
    $module_menu_map = defined('MODULE_MENU_MAP') ? MODULE_MENU_MAP : [];

    // Build a flat set of disabled item IDs
    $disabled_item_ids = [];
    foreach ($module_menu_map as $module_key => $item_ids) {
        if (empty($module_states[$module_key])) {
            foreach ($item_ids as $id) {
                $disabled_item_ids[$id] = true;
            }
        }
    }

    if (!empty($disabled_item_ids)) {
        $filtered = [];
        foreach ($items as $item) {
            $item_id = $item['id'] ?? '';
            if (isset($disabled_item_ids[$item_id])) continue;

            // Filter sub-items too
            if (!empty($item['sub_items'])) {
                $item['sub_items'] = array_values(array_filter(
                    $item['sub_items'],
                    fn($sub) => !isset($disabled_item_ids[$sub['id'] ?? ''])
                ));
                // If parent had sub-items and all are gone, and href is '#', skip parent
                if (empty($item['sub_items']) && ($item['href'] ?? '') === '#') continue;
            }
            $filtered[] = $item;
        }
        $items = $filtered;
    }
}

// Visual indicators removed to keep UI clean
// Future enhancement: Could add subtle CSS classes instead of emoji icons
?>