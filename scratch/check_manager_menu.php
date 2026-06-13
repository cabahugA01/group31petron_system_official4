<?php
$role = 'manager';
require_once __DIR__ . '/../public/db_connect.php';

// Mock user and session variables to check get_user_permissions
function get_user_permissions($role) {
    // Basic permissions mapped to manager
    return ['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports', 'view_dashboard', 'approve_transactions', 'manage_job_orders', 'manage_inventory', 'view_inventory', 'manage_fuel'];
}
function get_module_states() {
    return [];
}

require_once __DIR__ . '/../partials/rbac_menu.php';

echo "=== FILTERED MENU FOR MANAGER ===\n";
foreach ($items as $item) {
    echo "ID: {$item['id']} | Label: {$item['label']} | Href: {$item['href']}\n";
}
