<?php
// RBAC-Based Menu Generation
// Master menu array with all possible items and their permission requirements
$master_menu = [
    // Dashboard - Everyone gets some form of dashboard
    ['id'=>'dashboard','label'=>'Dashboard','ico'=>'fas fa-tachometer-alt','href'=>'staff_dashboard.php', 'permissions'=>['view_dashboard'], 'station_specific'=>false],

    // Manager Dashboard - dedicated command center for manager role
    ['id'=>'manager_dashboard','label'=>'Dashboard','ico'=>'fas fa-gauge-high','href'=>'manager_dashboard.php','permissions'=>['approve_transactions','manage_job_orders'],'station_specific'=>true],
    
    // Transactions & POS - Managers and Staff only (Admin/Owner excluded)
    // Staff → staff_transactions_hub.php | Manager → pending_transactions.php (NEW validation page)
    ['id'=>'transactions','label'=>'Transactions','ico'=>'fas fa-exchange-alt','href'=>'staff_transactions_hub.php?section=merchandise','permissions'=>['create_transactions', 'view_transactions', 'approve_transactions'],'station_specific'=>true],

    // Job Orders tab removed - redundant, already available in Transactions hub
    
    // Fuel Management - Managers handle operations, Staff do encoding
    ['id'=>'fuel','label'=>'Fuel Management','ico'=>'fas fa-gas-pump','href'=>'#','permissions'=>['manage_fuel', 'encode_fuel', 'view_fuel_variance'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'staff_fuel_deliveries_sub', 'label'=>'Record Fuel Delivery',         'href'=>'staff_fuel_deliveries.php',               'permissions'=>['encode_fuel','create_transactions'], 'desc'=>'Encode actual fuel delivery details (Invoice number, fuel type, liters, tanker number).'],
        ['id'=>'staff_fuel_del_history',     'label'=>'Fuel Deliveries History',      'href'=>'staff_fuel_deliveries_history.php',          'permissions'=>['encode_fuel','create_transactions'], 'desc'=>'View all fuel delivery records with manager approval status (Pending, Approved, Rejected).'],
        ['id'=>'staff_fuel_transactions',   'label'=>'Fuel Transactions (pump readings)', 'href'=>'staff_transactions_hub.php?section=fuel', 'permissions'=>['encode_fuel','create_transactions']],
    ]],
    
    // Deliveries Management - Staff (Merchandise ONLY — Fuel is under Fuel Management)
    ['id'=>'staff_deliveries','label'=>'Merchandise Deliveries','ico'=>'fas fa-boxes','href'=>'#','permissions'=>['manage_inventory','view_inventory','encode_fuel','create_transactions'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'staff_record_del',          'label'=>'Record Delivery Receipt',      'href'=>'staff_record_delivery.php',         'permissions'=>['manage_inventory','encode_fuel','create_transactions'], 'desc'=>'Encode actual delivery details (DR number, Batch ID, received items, quantity).'],
        ['id'=>'staff_delivery_history',    'label'=>'Merchandise Deliveries History', 'href'=>'staff_delivery_history.php',      'permissions'=>['manage_inventory','encode_fuel','create_transactions'], 'desc'=>'View all encoded merchandise deliveries with manager approval status.'],
    ]],
    
    // Deliveries Management - Manager (Merchandise Validation & History)
    ['id'=>'manager_deliveries','label'=>'Merchandise Deliveries','ico'=>'fas fa-truck-loading','href'=>'manager_deliveries.php','permissions'=>['approve_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_del_record',       'label'=>'Record Deliveries',      'href'=>'manager_deliveries.php?section=record',       'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_del_history',      'label'=>'Delivery History',       'href'=>'manager_deliveries.php?section=history',      'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_del_discrepancies','label'=>'Discrepancies/Variance', 'href'=>'manager_deliveries.php?section=discrepancies','permissions'=>['approve_transactions','manage_job_orders']],
    ]],
    
    // Inventory - Staff access / Manager has own sub-items via override below
    // NOTE: Stock-In is NOT listed for staff. Inventory is updated automatically by the system
    // after Manager approval — staff cannot manually perform stock-in encoding.
    ['id'=>'inventory','label'=>'Inventory','ico'=>'fas fa-warehouse','href'=>'staff_inventory_merchandise.php','permissions'=>['view_inventory','manage_inventory'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'inv_merch',        'label'=>'Merchandise Inventory',  'href'=>'staff_inventory_merchandise.php',  'permissions'=>['view_inventory'], 'desc'=>'Manage merchandise items and monitor stock levels.'],
        ['id'=>'inv_fuel',         'label'=>'Fuel Inventory',         'href'=>'staff_inventory_fuel.php',         'permissions'=>['view_inventory'], 'desc'=>'Record fuel pump readings and deliveries with Batch ID.'],
        ['id'=>'inv_stock_request','label'=>'Stock Request',          'href'=>'staff_stock_requests.php',         'permissions'=>['view_inventory','manage_inventory'], 'desc'=>'View system-generated requests for low or out-of-stock items.'],
        ['id'=>'inv_history',      'label'=>'Inventory History',      'href'=>'staff_inventory_history.php',      'permissions'=>['view_inventory'], 'desc'=>'Track the lifecycle of requests, deliveries, and stock updates.'],
    ]],

    // Product Management - Manager (view/manage products & pricing)
    ['id'=>'product_management','label'=>'Product Management','ico'=>'fas fa-boxes','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory','approve_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_prod_merchandise','label'=>'Merchandise Products','href'=>'manager_product_merchandise.php','permissions'=>['manage_inventory','view_inventory']],
        ['id'=>'mgr_prod_fuel',       'label'=>'Fuel Products',       'href'=>'manager_product_fuel.php',       'permissions'=>['manage_inventory','view_inventory']],
        ['id'=>'mgr_prod_services',   'label'=>'Service Types',       'href'=>'manager_service_types.php',      'permissions'=>['manage_inventory','view_inventory','manage_job_orders']],
        ['id'=>'mgr_prod_prices',     'label'=>'Approve Prices',      'href'=>'manager_approve_prices.php',     'permissions'=>['approve_transactions','manage_job_orders']],
    ]],

    // Customers - Staff access
    ['id'=>'customers','label'=>'Customers','ico'=>'fas fa-users','href'=>'customers.php','permissions'=>['create_transactions','view_transactions'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'customer_add',     'label'=>'Add New Customer',  'href'=>'customers.php?section=add',     'permissions'=>['create_transactions']],
        ['id'=>'customer_list',    'label'=>'Customer List',     'href'=>'customers.php?section=list',    'permissions'=>['create_transactions']],
        ['id'=>'customer_history', 'label'=>'Customer History',  'href'=>'customers.php?section=history', 'permissions'=>['create_transactions']],
    ]],

    // Customers - Manager access (separate page with approval/oversight)
    ['id'=>'mgr_customers','label'=>'Customers','ico'=>'fas fa-users','href'=>'manager_customers.php','permissions'=>['approve_transactions','view_transactions','manage_job_orders'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'mgr_cust_add',     'label'=>'Add New Customer',  'href'=>'manager_customers.php?section=add',      'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_cust_list',    'label'=>'Customer List',     'href'=>'manager_customers.php?section=records',  'permissions'=>['approve_transactions','view_transactions']],
        ['id'=>'mgr_cust_balances','label'=>'Customer Balances', 'href'=>'manager_customers.php?section=balances', 'permissions'=>['approve_transactions','manage_job_orders']],
        ['id'=>'mgr_cust_history', 'label'=>'Customer History',  'href'=>'manager_customers.php?section=history',  'permissions'=>['view_transactions','manage_job_orders']],
        ['id'=>'mgr_cust_validation','label'=>'Pending Approvals','href'=>'manager_customers.php?section=validation','permissions'=>['approve_transactions','manage_job_orders']],
    ]],

    // Calendar - Staff & Manager
    ['id'=>'calendar','label'=>'Calendar','ico'=>'fas fa-calendar-alt','href'=>'staff_calendar.php','permissions'=>['view_dashboard','create_transactions','encode_fuel','manage_job_orders','create_job_orders','approve_transactions'],'station_specific'=>true],

    // Reports - Staff, Manager, Admin
    ['id'=>'reports','label'=>'Reports','ico'=>'fas fa-chart-bar','href'=>'staff_reports.php','permissions'=>['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports'],'station_specific'=>true,'sub_items'=>[
        ['id'=>'report_daily_sales',      'label'=>'Sales Reports',                    'href'=>'staff_fuel_sales_summary.php',       'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_jo_tracker',       'label'=>'Job Orders Reports',               'href'=>'staff_job_orders_report.php',  'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_deliveries',       'label'=>'Deliveries Reports',               'href'=>'staff_deliveries_report.php',           'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_payments',         'label'=>'Payments Reports',                 'href'=>'staff_payments_report.php',    'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_customers',        'label'=>'Customer Reports',                 'href'=>'staff_customers_report.php',   'permissions'=>['view_personal_reports','view_operational_reports']],
        ['id'=>'report_activity',         'label'=>'Activity Reports',                 'href'=>'staff_activity_report.php',    'permissions'=>['view_personal_reports']],
    ]],
    
    // ── SUPERADMIN / DEVELOPER SIDEBAR ──────────────────────────────────────────
    // Exact 9-item spec. Developer-focused, no business ops.
    // Audit trail lives ONLY in System Logs & Audit — not duplicated elsewhere.

    // 1. System Dashboard
    ['id'=>'super_admin_dashboard','label'=>'System Dashboard','ico'=>'fas fa-server','href'=>'super_admin_dashboard.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 2. Admin Management
    ['id'=>'admin_management','label'=>'Admin Management','ico'=>'fas fa-user-shield','href'=>'superadmin_admin_management.php','permissions'=>['manage_all_users'],'station_specific'=>false],

    // 3. Module Configuration
    ['id'=>'module_config','label'=>'Module Configuration','ico'=>'fas fa-sliders-h','href'=>'module_configuration.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 4. Database Management (Tabbed Interface)
    ['id'=>'database_management','label'=>'Database Management','ico'=>'fas fa-database','href'=>'database_management.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 6. Audit Trail  ← ONLY place for audit trail
    // 6. Integration Settings
    ['id'=>'integration_settings','label'=>'Integration Settings','ico'=>'fas fa-plug','href'=>'superadmin_integration_settings.php?section=pos_import','permissions'=>['manage_stations', 'approve_transactions'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'int_pos_import',      'label'=>'POS Import Config',   'href'=>'superadmin_integration_settings.php?section=pos_import',      'permissions'=>['manage_stations', 'approve_transactions']],
        ['id'=>'int_api_connections', 'label'=>'API Connections',     'href'=>'superadmin_integration_settings.php?section=api_connections', 'permissions'=>['manage_stations', 'approve_transactions']],
        ['id'=>'int_git_workflow',    'label'=>'Git Workflow',        'href'=>'superadmin_integration_settings.php?section=git_workflow',    'permissions'=>['manage_stations', 'approve_transactions']],
        ['id'=>'int_external_sync',   'label'=>'External System Sync','href'=>'superadmin_integration_settings.php?section=external_sync',   'permissions'=>['manage_stations', 'approve_transactions']],
    ]],

    // 7. System Settings
    ['id'=>'system_settings','label'=>'System Settings','ico'=>'fas fa-cog','href'=>'superadmin_system_settings.php','permissions'=>['manage_stations'],'station_specific'=>false],

    // 8. Reports (Developer View)
    ['id'=>'superadmin_reports','label'=>'Reports','ico'=>'fas fa-chart-line','href'=>'reports_technical.php','permissions'=>['manage_stations'],'station_specific'=>false,'sub_items'=>[
        ['id'=>'rpt_dev_technical', 'label'=>'Technical Reports',  'href'=>'reports_technical.php',      'permissions'=>['manage_stations']],
        ['id'=>'rpt_dev_security',  'label'=>'Security Reports',   'href'=>'reports_security.php',       'permissions'=>['manage_stations']],
        ['id'=>'rpt_dev_audit',     'label'=>'Dev Audit Reports',  'href'=>'reports_developer_audit.php','permissions'=>['manage_stations']],
    ]],

    // 9. Audit Trail
    ['id'=>'audit_trail','label'=>'Audit Trail','ico'=>'fas fa-history','href'=>'superadmin_audit_trail.php','permissions'=>['manage_stations'],'station_specific'=>false],

    ];

// Filter menu items based on user role and permissions
function filter_menu_by_permissions($menu_items, $user_role) {
    $filtered_menu = [];
    $user_permissions = get_user_permissions($user_role);
    $staff_hidden_report_items = ['sales_reports', 'inventory_reports', 'customer_reports', 'fuel_variance_report', 'shift_reports', 'profit_loss'];
    $staff_hidden_parent_items = ['staff', 'users', 'inventory_manager', 'admin_oversight', 'mgr_customers', 'manager_deliveries', 'product_management'];
    $staff_hidden_sub_items    = ['job_create', 'customer_linkage'];
    $admin_hidden_parent_items = ['stations', 'transactions', 'job_orders', 'fuel', 'customers', 'mgr_customers', 'inventory', 'inventory_manager', 'purchase_orders'];
    $admin_hidden_sub_items = ['stock_requests']; // Hide staff stock requests from admin (staff_stock_in removed from all staff menus)
    $manager_hidden_sub_items = ['stock_requests', 'fuel_variance_report', 'fuel_reading_tracker', 'fuel_calibration_logs', 'fuel_stock_levels', 'fuel_variance_reports']; // Hide old fuel items from manager (replaced by new sub-menu)
    $manager_hidden_parent_items = ['purchase_orders', 'customers']; // Hide staff customers from manager - Manager now has Reports
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
                'ico' => 'fas fa-chart-line',
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
            // 4. Transactions — Admin Oversight with sub-menu
            [
                'id'               => 'admin_transactions',
                'label'            => 'Transactions',
                'ico'              => 'fas fa-receipt',
                'href'             => '#',
                'permissions'      => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'desc'             => 'Provide centralized oversight of transaction operations, ensuring transaction accuracy, compliance, and accountability throughout the system.',
                'sub_items'        => [
                    ['id' => 'admin_all_transactions',         'label' => 'All Transactions',         'href' => 'admin_all_transactions.php',             'ico' => 'fas fa-list-alt',   'permissions' => ['view_all_reports'], 'desc' => 'Monitor and review all transaction records from all operational shifts and staff accounts.'],
                    ['id' => 'admin_transaction_adjustments',  'label' => 'Transaction Adjustments',  'href' => 'admin_transaction_adjustments.php',      'ico' => 'fas fa-sliders-h',  'permissions' => ['view_all_reports'], 'desc' => 'Review transaction modifications performed by managers and verify adjustment records.'],
                    ['id' => 'admin_voided_transactions',      'label' => 'Voided Transactions',      'href' => 'admin_voided_transactions.php',          'ico' => 'fas fa-ban',        'permissions' => ['view_all_reports'], 'desc' => 'Review cancelled transactions and monitor void activities for compliance and operational control.'],
                ],
            ],
            // 5. Fuel Management — Admin Oversight Module
            [
                'id' => 'admin_fuel_management',
                'label' => 'Fuel Management',
                'ico' => 'fas fa-gas-pump',
                'href' => 'admin_fuel_transactions_oversight.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    ['id' => 'admin_fuel_transactions_oversight', 'label' => 'Fuel Transactions Oversight', 'href' => 'admin_fuel_transactions_oversight.php', 'permissions' => ['view_all_reports'], 'desc' => 'Monitor validated pump readings for compliance.'],
                    ['id' => 'admin_fuel_deliveries_oversight', 'label' => 'Fuel Deliveries Oversight', 'href' => 'admin_fuel_deliveries_oversight.php', 'permissions' => ['view_all_reports'], 'desc' => 'Oversee validated supplier deliveries and stock updates.'],
                    ['id' => 'admin_fuel_adjustments_oversight', 'label' => 'Adjustments Oversight', 'href' => 'admin_fuel_adjustments_oversight.php', 'permissions' => ['view_all_reports'], 'desc' => 'Track manager adjustments for audit and transparency.'],
                    ['id' => 'admin_pump_master_oversight', 'label' => 'Pump Master Oversight', 'href' => 'admin_pump_master_oversight.php', 'permissions' => ['view_all_reports'], 'desc' => 'View calibration records and audit trail logs.'],
                ],
            ],
            // 6. Merchandise Deliveries Oversight — Admin Oversight Module
            [
                'id' => 'admin_merchandise_deliveries',
                'label' => 'Merchandise Deliveries Oversight',
                'ico' => 'fas fa-truck-loading',
                'href' => 'admin_merchandise_deliveries_oversight.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 7. Inventory Management — Admin Oversight Module
            [
                'id' => 'admin_inventory',
                'label' => 'Inventory',
                'ico' => 'fas fa-boxes',
                'href' => 'admin_inventory_merchandise.php',
                'permissions' => ['view_all_reports', 'view_operational_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    ['id' => 'admin_inventory_merchandise', 'label' => 'Merchandise Inventory', 'href' => 'admin_inventory_merchandise.php', 'ico' => 'fas fa-box', 'permissions' => ['view_all_reports'], 'desc' => 'Monitor merchandise stock, pricing, and stock alerts.'],
                    ['id' => 'admin_inventory_fuel', 'label' => 'Fuel Inventory', 'href' => 'admin_inventory_fuel.php', 'ico' => 'fas fa-gas-pump', 'permissions' => ['view_all_reports'], 'desc' => 'Monitor fuel levels and submit discrepancy corrections.'],
                    ['id' => 'admin_purchase_orders', 'label' => 'Purchase Orders Oversight', 'href' => 'admin_purchase_orders.php', 'ico' => 'fas fa-file-invoice-dollar', 'permissions' => ['view_all_reports', 'view_operational_reports'], 'desc' => 'Review, validate, approve/reject POs.'],
                    ['id' => 'admin_inventory_history', 'label' => 'Inventory History', 'href' => 'admin_inventory_history.php', 'ico' => 'fas fa-history', 'permissions' => ['view_all_reports'], 'desc' => 'Full audit log of all fuel and merchandise inventory movements.'],
                ],
            ],
            // 8. Product & Pricing Overview — Standalone Admin Module
            [
                'id'          => 'admin_product_pricing',
                'label'       => 'Product & Pricing Overview',
                'ico'         => 'fas fa-tags',
                'href'        => 'admin_set_prices.php',
                'permissions' => ['manage_system_settings', 'view_all_reports'],
                'station_specific' => true,
                'desc'        => 'Consolidated product list, current prices, price change validation, inventory snapshot.',
            ],
            // 9. Customers — Admin Oversight Module
            [
                'id'               => 'admin_customers',
                'label'            => 'Customers',
                'ico'              => 'fas fa-users',
                'href'             => 'admin_customer_management.php',
                'permissions'      => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items'        => [
                    [
                        'id'          => 'adm_cust_list',
                        'label'       => 'Customer List',
                        'href'        => 'admin_customer_management.php?section=list',
                        'permissions' => ['view_all_reports'],
                        'desc'        => 'View and manage customer profiles within assigned station.',
                    ],
                    [
                        'id'          => 'adm_cust_balances',
                        'label'       => 'Customer Balances',
                        'href'        => 'admin_customer_management.php?section=balances',
                        'permissions' => ['view_all_reports'],
                        'desc'        => 'Monitor receivables and outstanding balances within assigned station.',
                    ],
                    [
                        'id'          => 'adm_cust_history',
                        'label'       => 'Customer History',
                        'href'        => 'admin_customer_management.php?section=history',
                        'permissions' => ['view_all_reports'],
                        'desc'        => 'View transaction history within assigned station.',
                    ],
                    // Customer Oversight - Admin & SuperAdmin
                    [
                        'id'          => 'adm_cust_oversight',
                        'label'       => 'Customer Oversight',
                        'href'        => 'admin_customer_management.php?section=oversight',
                        'permissions' => ['view_all_reports'],
                        'desc'        => 'Manage customer records, assign/re-map across stations, delete/archive.',
                    ],
                ],
            ],
            // 9. Calendar
            [
                'id' => 'admin_calendar',
                'label' => 'Calendar',
                'ico' => 'fas fa-calendar-check',
                'href' => 'admin_calendar.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
            ],
            // 10. Reports - Complete Admin Reports
            [
                'id' => 'admin_reports',
                'label' => 'Reports',
                'ico' => 'fas fa-chart-bar',
                'href' => 'admin_reports.php',
                'permissions' => ['view_all_reports', 'view_dashboard'],
                'station_specific' => true,
                'sub_items' => [
                    [
                        'id' => 'rpt_operations',
                        'label' => 'Operations Reports',
                        'href' => 'admin_reports.php',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Shift Reports, Daily Consolidation, Fuel Inventory, Merchandise Inventory, Job Orders.',
                    ],
                    [
                        'id' => 'rpt_finance',
                        'label' => 'Finance Reports',
                        'href' => 'admin_finance_reports.php',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Payments, Suppliers, Financial Payables & Reconciliation.',
                    ],
                    [
                        'id' => 'rpt_compliance',
                        'label' => 'Compliance Reports',
                        'href' => 'admin_compliance_reports.php',
                        'permissions' => ['view_all_reports'],
                        'desc' => 'Activity Logs, Audit Trail, Calendar & Schedule.',
                    ],
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
            }
            
            // Manager gets manager_reports.php with Operations Reports sub-menu
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['href'] = 'manager_reports.php';
                $filtered_item['sub_items'] = [
                    [
                        'id'          => 'mgr_operations_reports',
                        'label'       => 'Operations Reports',
                        'href'        => 'manager_reports.php',
                        'ico'         => 'fas fa-chart-line',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Shift Reports, Daily Consolidation, Fuel Inventory, Merchandise Inventory, Job Orders with validation.'
                    ],
                    [
                        'id'          => 'mgr_finance_reports',
                        'label'       => 'Finance Reports',
                        'href'        => 'manager_finance_reports.php',
                        'ico'         => 'fas fa-file-invoice-dollar',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Payments breakdown, Supplier deliveries & payables, Financial reconciliation with validation.'
                    ],
                    [
                        'id'          => 'mgr_compliance_reports',
                        'label'       => 'Compliance Reports',
                        'href'        => 'manager_compliance_reports.php',
                        'ico'         => 'fas fa-shield-alt',
                        'permissions' => ['view_operational_reports', 'approve_transactions', 'manage_job_orders'],
                        'desc'        => 'Activity Logs, Audit Trail, Calendar & Schedule monitoring with validation and compliance tracking.'
                    ]
                ];
                // Add to menu immediately and skip further processing
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'staff' && ($item['id'] ?? '') === 'dashboard') {
                $filtered_item['href'] = 'staff_dashboard.php';
            }

            // Staff Transactions: sub-menu with History and Receipts only
            // (New Transaction, Job Order Tracker, Merchandise History handled by tabs inside the hub page)
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href']      = 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise';
                $filtered_item['label']     = 'Transactions';
                $filtered_item['sub_items'] = [
                    ['id' => 'staff_new_transaction',     'label' => 'New Transaction',     'href' => 'staff_transactions_hub.php?section=merchandise&active_tab=merchandise', 'ico' => 'fas fa-plus-circle',   'permissions' => ['create_transactions'], 'desc' => 'Create and process new job order, merchandise, or combined transactions.'],
                    ['id' => 'staff_transaction_history', 'label' => 'Transaction History', 'href' => 'staff_transactions_hub.php?section=history',                           'ico' => 'fas fa-history',        'permissions' => ['create_transactions'], 'desc' => 'View and track previously encoded transactions and payment records.'],
                    ['id' => 'staff_receipts',            'label' => 'Receipts',            'href' => 'receipts.php',                                                         'ico' => 'fas fa-file-invoice',   'permissions' => ['create_transactions'], 'desc' => 'View, reprint, and manage generated transaction receipts.'],
                ];
            }

            // Job Orders sidebar item removed for staff — encode & tracker live inside Transactions
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'job_orders') {
                continue;
            }

            // Staff Fuel Management: already defined correctly in master menu, skip old override
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'fuel') {
                // Use master menu sub_items as-is (Fuel Deliveries + Fuel Transactions)
                $filtered_menu[] = $filtered_item;
                continue;
            }


            if ($user_role === 'manager' && ($item['id'] ?? '') === 'transactions') {
                $filtered_item['href']  = '#';
                $filtered_item['label'] = 'Transactions';
                $filtered_item['sub_items'] = [
                    ['id' => 'validated_transactions_manager', 'label' => 'All Transactions',        'href' => 'manager_validated_transactions.php',             'ico' => 'fas fa-check-double',    'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'View and monitor all transactions encoded by staff across operational shifts.'],
                    ['id' => 'manager_transaction_adjustments','label' => 'Transaction Adjustments', 'href' => 'manager_transaction_monitoring.php',             'ico' => 'fas fa-sliders-h',       'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'Review and manage transaction corrections, modifications, and adjustment records.'],
                    ['id' => 'manager_voided_transactions',    'label' => 'Voided Transactions',     'href' => 'voided_transactions.php',                        'ico' => 'fas fa-ban',             'permissions' => ['view_transactions','approve_transactions'], 'desc' => 'Manage cancelled or invalid transactions with complete audit trail and inventory restoration.'],
                ];
            }

            // Job Orders — removed as standalone for manager, now lives under Transactions
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'job_orders') {
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'inventory') {
                $filtered_item['label']    = 'Inventory';
                $filtered_item['href']     = '#';
                $filtered_item['ico']      = 'fas fa-boxes';
                $filtered_item['sub_items'] = [
                    ['id' => 'mgr_inv_merch',    'label' => 'Merchandise Inventory',  'href' => 'manager_inventory_merchandise.php',      'ico' => 'fas fa-box',              'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_inv_fuel',     'label' => 'Fuel Inventory',         'href' => 'manager_inventory_fuel.php',             'ico' => 'fas fa-gas-pump',         'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_stock_review', 'label' => 'Stock Request Review',   'href' => 'manager_stock_request_review.php',       'ico' => 'fas fa-clipboard-check',  'permissions' => ['manage_inventory', 'view_inventory']],
                    ['id' => 'mgr_inv_movement', 'label' => 'Inventory Movement History', 'href' => 'manager_inventory_movement_history.php', 'ico' => 'fas fa-history',         'permissions' => ['manage_inventory', 'view_inventory']],
                ];
                $filtered_menu[] = $filtered_item;
                
                // Add standalone Product & Pricing Overview after Inventory
                $filtered_menu[] = [
                    'id' => 'mgr_product_pricing',
                    'label' => 'Product & Pricing Overview',
                    'ico' => 'fas fa-tags',
                    'href' => 'manager_set_prices.php',
                    'permissions' => ['manage_inventory', 'view_inventory'],
                    'station_specific' => true,
                    'desc' => 'View consolidated product list, current prices, and inventory snapshot.'
                ];
                
                // Add standalone Master Data Requests after Product & Pricing
                $filtered_menu[] = [
                    'id' => 'mgr_master_data_requests',
                    'label' => 'Master Data Requests',
                    'ico' => 'fas fa-clipboard-check',
                    'href' => 'master_data_requests.php',
                    'permissions' => ['approve_transactions', 'manage_job_orders'],
                    'station_specific' => true,
                    'desc' => 'Review and approve staff requests for new vehicle types, service types, and products.'
                ];
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'fuel') {
                $filtered_item['href'] = 'manager_fuel_transaction_validation.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'fuel_transactions_validation', 'label'=>'Fuel Transaction Validation',  'href'=>'manager_fuel_transaction_validation.php',         'permissions'=>['manage_fuel'], 'desc'=>'Review and validate staff‑encoded pump readings.'],
                    ['id'=>'fuel_deliveries_validation',   'label'=>'Fuel Deliveries Validation',   'href'=>'manager_fuel_deliveries_validation.php',          'permissions'=>['manage_fuel'], 'desc'=>'Approve or return supplier delivery receipts.'],
                    ['id'=>'fuel_adjustments',              'label'=>'Adjustments',                  'href'=>'manager_fuel_adjustments.php',                    'permissions'=>['manage_fuel'], 'desc'=>'Apply corrections for tank levels, stock, or price changes.'],
                    ['id'=>'fuel_pump_master',              'label'=>'Pump Master',                  'href'=>'manager_fuel_pump_master.php',                    'permissions'=>['manage_fuel'], 'desc'=>'Manage calibration values for accurate pump readings.'],
                ];
                // Add directly — skip the generic sub-item filter below
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'reports') {
                $filtered_item['label'] = 'Reports';
                $filtered_item['ico']   = 'fas fa-chart-bar';
                $filtered_item['href']  = 'manager_reports.php';
                $filtered_item['sub_items'] = [];
                $filtered_menu[] = $filtered_item;
                continue;
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'staff_deliveries') {
                continue; // Hide Staff's Record Delivery from Manager
            }

            if ($user_role === 'manager' && ($item['id'] ?? '') === 'manager_deliveries') {
                $filtered_item['label'] = 'Merchandise Deliveries Validation';
                $filtered_item['ico']   = 'fas fa-truck-loading';
                $filtered_item['href']  = 'manager_merchandise_deliveries.php';
                $filtered_item['sub_items'] = [
                    ['id'=>'mgr_del_verify',  'label'=>'Verify Deliveries',  'href'=>'manager_merchandise_deliveries.php?tab=manage&action=verify',   'permissions'=>['manage_inventory','view_inventory'], 'desc'=>'Review and verify staff-encoded merchandise delivery receipts.'],
                    ['id'=>'mgr_del_reject',  'label'=>'Reject Deliveries',  'href'=>'manager_merchandise_deliveries.php?tab=manage&action=reject',   'permissions'=>['manage_inventory','view_inventory'], 'desc'=>'Reject or return delivery records to staff for correction.'],
                    ['id'=>'mgr_del_history', 'label'=>'Delivery History',   'href'=>'manager_merchandise_deliveries.php?tab=history',                'permissions'=>['manage_inventory','view_inventory'], 'desc'=>'View history of all verified, rejected, and resolved deliveries.'],
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
                    ['id'=>'mgr_prod_merchandise', 'label'=>'Merchandise Products', 'href'=>'manager_product_merchandise.php',  'permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_prod_fuel',        'label'=>'Fuel Products',        'href'=>'manager_product_fuel.php',         'permissions'=>['manage_inventory','view_inventory']],
                    ['id'=>'mgr_prod_prices',      'label'=>'Price History',        'href'=>'manager_approve_prices.php',       'permissions'=>['approve_transactions','manage_job_orders']],
                    ['id'=>'mgr_prod_adjustment',  'label'=>'Adjustment',           'href'=>'manager_fuel_adjustments.php',     'permissions'=>['manage_inventory','manage_fuel']],
                ];
                $force_direct_link = false;
            }



            // Product Management is for Manager only — hide from Staff
            if ($user_role === 'staff' && ($item['id'] ?? '') === 'product_management') {
                continue;
            }

            // Hide Product Management for Manager — replaced by standalone Product & Pricing Overview
            if ($user_role === 'manager' && ($item['id'] ?? '') === 'product_management') {
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
            if (($item['id'] ?? '') === 'database_management'
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Logs & Audit — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['system_logs','sla_audit_trail','sla_error_tracking','sla_export_logs','sla_developer_log'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // Integration Settings — SuperAdmin / Developer / Admin can view (Manager excluded)
            if (in_array(($item['id'] ?? ''), ['integration_settings','int_pos_import','int_api_connections','int_git_workflow','int_external_sync'], true)
                && !in_array($user_role, ['superadmin', 'developer', 'admin'], true)) {
                continue;
            }

            // Reports (Developer View) — SuperAdmin / Developer only
            if (in_array(($item['id'] ?? ''), ['superadmin_reports','rpt_dev_technical','rpt_dev_security','rpt_dev_audit'], true)
                && !in_array($user_role, ['superadmin', 'developer'], true)) {
                continue;
            }

            // System Settings — SuperAdmin / Developer only
            if (($item['id'] ?? '') === 'system_settings'
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

                    // Hide SuperAdmin-only items from Admin
                    if ($user_role === 'admin' && !empty($sub_item['superadmin_only'])) {
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