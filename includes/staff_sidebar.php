<?php
/**
 * Staff Sidebar Navigation
 * Matches the RBAC menu spec exactly:
 *   Dashboard | Transactions | Fuel Management | Inventory |
 *   Customers | Merchandise Deliveries | Calendar | Reports
 *
 * NOTE: This file is a legacy helper. The canonical sidebar is rendered
 * by partials/header.php via partials/rbac_menu.php.
 * Keep this in sync with rbac_menu.php.
 */
function getStaffSidebar($current_page = 'dashboard') {
    $sidebar = [
        'dashboard' => [
            'icon'  => 'fas fa-tachometer-alt',
            'title' => 'Dashboard',
            'url'   => 'staff_dashboard.php',
        ],
        'transactions' => [
            'icon'  => 'fas fa-exchange-alt',
            'title' => 'Transactions',
            'url'   => 'staff_transactions_hub.php?section=merchandise',
        ],
        'fuel' => [
            'icon'  => 'fas fa-gas-pump',
            'title' => 'Fuel Management',
            'url'   => 'staff_transactions_hub.php?section=fuel',
        ],
        'inventory' => [
            'icon'  => 'fas fa-warehouse',
            'title' => 'Inventory',
            'url'   => 'staff_inventory_merchandise.php',
            'submenu' => [
                'inv_merch' => [
                    'icon'  => 'fas fa-boxes',
                    'title' => 'Merchandise Inventory',
                    'url'   => 'staff_inventory_merchandise.php',
                ],
                'inv_fuel' => [
                    'icon'  => 'fas fa-database',
                    'title' => 'Fuel Inventory',
                    'url'   => 'staff_inventory_fuel.php',
                ],
                'inv_record_delivery' => [
                    'icon'  => 'fas fa-truck-loading',
                    'title' => 'Record Delivery',
                    'url'   => 'staff_record_delivery.php',
                ],
            ],
        ],

        'calendar' => [
            'icon'  => 'fas fa-calendar-alt',
            'title' => 'Calendar',
            'url'   => 'staff_calendar.php',
        ],
        'reports' => [
            'icon'  => 'fas fa-chart-bar',
            'title' => 'Reports',
            'url'   => 'staff_reports.php',
            'submenu' => [
                'report_sales' => [
                    'icon'  => 'fas fa-dollar-sign',
                    'title' => 'Sales Reports',
                    'url'   => 'staff_fuel_sales_summary.php',
                ],
                'report_fuel_sales_summary' => [
                    'icon'  => 'fas fa-gas-pump',
                    'title' => 'Fuel Sales Summary',
                    'url'   => 'staff_fuel_sales_summary.php',
                ],
                'report_job_orders' => [
                    'icon'  => 'fas fa-wrench',
                    'title' => 'Job Orders Reports',
                    'url'   => 'staff_job_orders_report.php',
                ],
                'report_deliveries' => [
                    'icon'  => 'fas fa-clipboard-check',
                    'title' => 'Fuel Reconciliation Report',
                    'url'   => 'staff_deliveries_report.php',
                ],
                'report_payments' => [
                    'icon'  => 'fas fa-exchange-alt',
                    'title' => 'Shift Turnover Report',
                    'url'   => 'staff_payments_report.php',
                ],
                'report_customers' => [
                    'icon'  => 'fas fa-users',
                    'title' => 'Customer Reports',
                    'url'   => 'staff_customers_report.php',
                ],
                'report_activity' => [
                    'icon'  => 'fas fa-chart-line',
                    'title' => 'My Activity Report',
                    'url'   => 'staff_activity_report.php',
                ],
            ],
        ],
    ];

    echo '<nav class="staff-sidebar" id="staffSidebar">';
    echo '<div class="sidebar-header">';
    echo '<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">';
    echo '<i class="fas fa-bars"></i>';
    echo '</button>';
    echo '<h4><i class="fas fa-user-tie"></i> Staff Portal</h4>';
    echo '</div>';

    echo '<ul class="sidebar-menu">';
    foreach ($sidebar as $key => $item) {
        $active     = ($current_page === $key) ? 'active' : '';
        $hasSubmenu = isset($item['submenu']) && !empty($item['submenu']);

        echo '<li class="' . $active . '">';

        if ($hasSubmenu) {
            echo '<a href="' . htmlspecialchars($item['url']) . '" class="has-submenu" onclick="toggleSubmenu(event,\'' . $key . '\')">';
            echo '<i class="' . htmlspecialchars($item['icon']) . '"></i>';
            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
            echo '<i class="fas fa-chevron-down submenu-arrow"></i>';
            echo '</a>';

            echo '<ul class="submenu" id="submenu-' . $key . '">';
            foreach ($item['submenu'] as $subKey => $subItem) {
                echo '<li>';
                echo '<a href="' . htmlspecialchars($subItem['url']) . '">';
                echo '<i class="' . htmlspecialchars($subItem['icon']) . '"></i>';
                echo '<span>' . htmlspecialchars($subItem['title']) . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<a href="' . htmlspecialchars($item['url']) . '">';
            echo '<i class="' . htmlspecialchars($item['icon']) . '"></i>';
            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
            echo '</a>';
        }

        echo '</li>';
    }
    echo '</ul>';
    echo '</nav>';
}
?>

<style>
.staff-sidebar {
    width: 250px;
    height: 100vh;
    background: var(--petron-blue, #00264D);
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    z-index: 1000;
    transition: transform 0.3s ease;
}

.staff-sidebar.collapsed {
    transform: translateX(-250px);
}

.sidebar-toggle {
    position: absolute;
    right: 15px;
    top: 15px;
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    z-index: 1001;
}

.sidebar-toggle:hover {
    background: rgba(255,255,255,0.25);
    transform: scale(1.1);
}

.sidebar-header {
    padding: 20px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
}

.sidebar-header h4 {
    margin: 0;
    color: white;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sidebar-menu {
    list-style: none;
    margin: 0;
    padding: 0;
}

.sidebar-menu > li {
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    transition: all 0.2s ease;
    position: relative;
    font-size: 13px;
    font-weight: 500;
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

.sidebar-menu a i:first-child {
    width: 18px;
    text-align: center;
    font-size: 14px;
    flex-shrink: 0;
}

.sidebar-menu li.active > a {
    background: var(--petron-red, #CC0000);
    color: white;
}

/* Submenu */
.submenu {
    list-style: none;
    margin: 0;
    padding: 0;
    background: rgba(0,0,0,0.15);
    border-left: 3px solid rgba(255,255,255,0.2);
    display: none;
}

.submenu li {
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.submenu a {
    padding: 9px 18px 9px 42px;
    font-size: 12px;
    font-weight: 400;
    color: rgba(255,255,255,0.75);
}

.submenu a:hover {
    background: rgba(255,255,255,0.08);
    color: white;
    padding-left: 46px;
}

.has-submenu {
    position: relative;
}

.submenu-arrow {
    position: absolute !important;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px !important;
    transition: transform 0.3s ease;
    width: auto !important;
}

.submenu-open .submenu-arrow {
    transform: translateY(-50%) rotate(180deg);
}

/* Main content offset */
.main-content {
    margin-left: 250px;
    min-height: 100vh;
    background: #f8f9fa;
    transition: margin-left 0.3s ease;
}

.main-content.expanded {
    margin-left: 0;
}

@media (max-width: 768px) {
    .staff-sidebar { width: 100%; height: auto; position: relative; }
    .main-content  { margin-left: 0; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle  = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('staffSidebar');
    const main    = document.querySelector('.main-content');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            if (main) main.classList.toggle('expanded');
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        });
    }

    window.toggleSubmenu = function (event, key) {
        event.preventDefault();
        const sub  = document.getElementById('submenu-' + key);
        const item = event.target.closest('li');
        if (!sub) return false;

        const open = sub.style.display === 'block';

        // Close all other submenus
        document.querySelectorAll('.submenu').forEach(function (s) {
            if (s !== sub) {
                s.style.display = 'none';
                s.closest('li').classList.remove('submenu-open');
            }
        });

        sub.style.display = open ? 'none' : 'block';
        item.classList.toggle('submenu-open', !open);
        return false;
    };
});
</script>
