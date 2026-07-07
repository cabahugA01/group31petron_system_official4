<?php
/**
 * Inventory Permission Helper Functions
 * Provides centralized permission checking for inventory operations
 */

require_once __DIR__ . '/../app/master_data/roles_permissions/rbac.php';

/**
 * Check if current user can view inventory
 * @param string $type 'fuel' or 'merchandise'
 * @return bool
 */
function can_view_inventory($type = 'merchandise') {
    if ($type === 'fuel') {
        return has_permission(VIEW_FUEL_INVENTORY);
    }
    return has_permission(VIEW_MERCHANDISE_INVENTORY);
}

/**
 * Check if current user can submit stock requests
 * @return bool
 */
function can_submit_stock_request() {
    return has_permission(SUBMIT_STOCK_REQUEST);
}

/**
 * Check if current user can approve stock requests
 * @return bool
 */
function can_approve_stock_request() {
    return has_permission(APPROVE_STOCK_REQUEST);
}

/**
 * Check if current user can generate purchase orders
 * @return bool
 */
function can_generate_purchase_order() {
    return has_permission(GENERATE_PURCHASE_ORDER);
}

/**
 * Check if current user can receive deliveries
 * @return bool
 */
function can_receive_deliveries() {
    return has_permission(RECEIVE_DELIVERIES);
}

/**
 * Check if current user can perform stock-in operations
 * @return bool
 */
function can_stock_in() {
    return has_permission(STOCK_IN_INVENTORY);
}

/**
 * Check if current user can make inventory adjustments
 * @return bool
 */
function can_adjust_inventory() {
    return has_permission(INVENTORY_ADJUSTMENT);
}

/**
 * Check if current user can conduct inventory counts
 * @return bool
 */
function can_conduct_inventory_count() {
    return has_permission(INVENTORY_COUNT);
}

/**
 * Check if current user can monitor inventory adjustments (Admin)
 * @return bool
 */
function can_monitor_adjustments() {
    return has_permission(MONITOR_INVENTORY_ADJUSTMENTS);
}

/**
 * Check if current user can rollback inventory adjustments (Admin)
 * @return bool
 */
function can_rollback_adjustments() {
    return has_permission(ROLLBACK_INVENTORY_ADJUSTMENTS);
}

/**
 * Check if current user can view audit trail
 * @return bool
 */
function can_view_audit_trail() {
    return has_permission(VIEW_INVENTORY_AUDIT_TRAIL);
}

/**
 * Check if current user can backup inventory
 * @return bool
 */
function can_backup_inventory() {
    return has_permission(BACKUP_INVENTORY);
}

/**
 * Check if current user can generate inventory reports
 * @return bool
 */
function can_generate_inventory_reports() {
    return has_permission(GENERATE_INVENTORY_REPORTS) || 
           has_permission(VIEW_INVENTORY_REPORTS_ADMIN);
}

/**
 * Check if current user can export inventory reports
 * @return bool
 */
function can_export_inventory_reports() {
    return has_permission(EXPORT_INVENTORY_REPORTS) || 
           has_permission(EXPORT_INVENTORY_REPORTS_ADMIN);
}

/**
 * Get user's inventory role label
 * @return string
 */
function get_inventory_role_label() {
    $user = current_user();
    $role = role_key($user['role'] ?? 'staff');
    
    switch ($role) {
        case 'staff':
        case 'cashier':
        case 'pump_attendant':
            return 'Inventory Monitor';
        case 'manager':
            return 'Inventory Manager';
        case 'admin':
        case 'administrator':
            return 'Inventory Overseer';
        case 'superadmin':
        case 'developer':
            return 'System Administrator';
        default:
            return 'User';
    }
}

/**
 * Get allowed inventory actions for current user
 * @return array
 */
function get_allowed_inventory_actions() {
    $actions = [];
    
    // View actions (all roles)
    if (can_view_inventory('fuel')) {
        $actions[] = 'view_fuel_inventory';
    }
    if (can_view_inventory('merchandise')) {
        $actions[] = 'view_merchandise_inventory';
    }
    if (has_permission(VIEW_INVENTORY_DETAILS)) {
        $actions[] = 'view_details';
    }
    if (has_permission(VIEW_INVENTORY_HISTORY)) {
        $actions[] = 'view_history';
    }
    if (has_permission(LOW_STOCK_MONITORING)) {
        $actions[] = 'monitor_low_stock';
    }
    
    // Request actions (Staff + Manager)
    if (can_submit_stock_request()) {
        $actions[] = 'submit_stock_request';
    }
    
    // Manager operational actions
    if (can_approve_stock_request()) {
        $actions[] = 'approve_stock_request';
    }
    if (can_generate_purchase_order()) {
        $actions[] = 'generate_purchase_order';
    }
    if (can_receive_deliveries()) {
        $actions[] = 'receive_deliveries';
    }
    if (can_stock_in()) {
        $actions[] = 'stock_in';
    }
    if (can_adjust_inventory()) {
        $actions[] = 'adjust_inventory';
    }
    if (can_conduct_inventory_count()) {
        $actions[] = 'inventory_count';
    }
    if (can_generate_inventory_reports()) {
        $actions[] = 'generate_reports';
    }
    if (can_export_inventory_reports()) {
        $actions[] = 'export_reports';
    }
    
    // Admin oversight actions
    if (can_monitor_adjustments()) {
        $actions[] = 'monitor_adjustments';
    }
    if (can_rollback_adjustments()) {
        $actions[] = 'rollback_adjustments';
    }
    if (can_view_audit_trail()) {
        $actions[] = 'view_audit_trail';
    }
    if (can_backup_inventory()) {
        $actions[] = 'backup_inventory';
    }
    
    return $actions;
}

/**
 * Render access denied page for inventory
 * @param string $action The action that was denied
 */
function render_inventory_access_denied($action = '') {
    $user = current_user();
    $role = get_inventory_role_label();
    
    $action_label = !empty($action) ? " ($action)" : '';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Access Denied - Inventory</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .denied-box {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .denied-icon {
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #dc2626, #b91c1c);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                color: white;
                font-size: 40px;
            }
            h1 {
                font-size: 24px;
                color: #1e293b;
                margin: 0 0 12px;
            }
            .role-badge {
                display: inline-block;
                background: #f1f5f9;
                color: #475569;
                padding: 6px 14px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 600;
                margin-bottom: 20px;
            }
            .message {
                color: #64748b;
                font-size: 15px;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .permissions-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 20px;
                text-align: left;
                margin-bottom: 24px;
            }
            .permissions-box h3 {
                font-size: 14px;
                color: #334155;
                margin: 0 0 12px;
                font-weight: 600;
            }
            .permissions-box ul {
                margin: 0;
                padding-left: 20px;
                font-size: 13px;
                color: #64748b;
                line-height: 1.8;
            }
            .btn-back {
                display: inline-block;
                background: linear-gradient(135deg, #002F70, #00264D);
                color: white;
                padding: 12px 28px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                transition: transform 0.2s;
            }
            .btn-back:hover {
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="denied-box">
            <div class="denied-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Access Denied</h1>
            <div class="role-badge"><?= htmlspecialchars($role) ?></div>
            <div class="message">
                You do not have permission to access this inventory function<?= $action_label ?>.<br>
                Your current role has the following inventory permissions:
            </div>
            <div class="permissions-box">
                <h3>Your Allowed Actions:</h3>
                <ul>
                    <?php
                    $allowed = get_allowed_inventory_actions();
                    if (empty($allowed)) {
                        echo '<li>No inventory permissions assigned</li>';
                    } else {
                        foreach ($allowed as $a) {
                            echo '<li>' . htmlspecialchars(ucwords(str_replace('_', ' ', $a))) . '</li>';
                        }
                    }
                    ?>
                </ul>
            </div>
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </body>
    </html>
    <?php
    exit;
}

/**
 * Require specific inventory permission or show access denied
 * @param string $permission Permission constant name
 * @param string $action Action label for denied page
 */
function require_inventory_permission($permission, $action = '') {
    if (!has_permission($permission)) {
        render_inventory_access_denied($action);
    }
}

/**
 * Check multiple permissions (OR logic)
 * @param array $permissions Array of permission constants
 * @return bool
 */
function has_any_inventory_permission($permissions) {
    foreach ($permissions as $perm) {
        if (has_permission($perm)) {
            return true;
        }
    }
    return false;
}

/**
 * Check multiple permissions (AND logic)
 * @param array $permissions Array of permission constants
 * @return bool
 */
function has_all_inventory_permissions($permissions) {
    foreach ($permissions as $perm) {
        if (!has_permission($perm)) {
            return false;
        }
    }
    return true;
}
?>
