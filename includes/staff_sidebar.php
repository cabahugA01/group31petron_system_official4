<?php
// Staff Sidebar Navigation - Only includes modules specified by user
function getStaffSidebar($current_page = 'dashboard') {
    $sidebar = [
        'dashboard' => [
            'icon' => 'fas fa-tachometer-alt',
            'title' => 'Dashboard',
            'url' => 'staff_dashboard.php'
        ],
        'transactions' => [
            'icon' => 'fas fa-shopping-cart',
            'title' => 'Transactions',
            'url' => 'staff_transactions.php',
            'description' => 'Fuel & Merchandise transactions with auto-pull and auto-compute'
        ],
        'job_orders' => [
            'icon' => 'fas fa-wrench',
            'title' => 'Job Orders',
            'url' => 'joborder.php',
            'description' => 'Encode, track status, link to receivables'
        ],
                'fuel_management' => [
            'icon' => 'fas fa-gas-pump',
            'title' => 'Fuel Management',
            'url' => 'fuel_readings_encoding.php',
            'description' => 'Fuel readings validation, inventory oversight, reconciliation'
        ],
                'calendar' => [
            'icon' => 'fas fa-calendar-alt',
            'title' => 'Calendar',
            'url' => 'staff_calendar.php',
            'description' => 'Staff schedules, color coding, tasks/events, sync with transactions'
        ],
        'reports' => [
            'icon' => 'fas fa-chart-bar',
            'title' => 'Reports',
            'url' => 'staff_reports.php',
            'description' => 'Station performance, staff reports, compliance reports'
        ],
            ];
    
    echo '<nav class="staff-sidebar" id="staffSidebar">';
    echo '<div class="sidebar-header">';
    echo '<button class="sidebar-toggle" id="sidebarToggle">';
    echo '<i class="fas fa-bars"></i>';
    echo '</button>';
    echo '<h4><i class="fas fa-user-tie"></i> Staff Portal</h4>';
    echo '</div>';
    
    echo '<ul class="sidebar-menu">';
    foreach ($sidebar as $key => $item) {
        $active = ($current_page === $key) ? 'active' : '';
        $hasSubmenu = isset($item['submenu']) && !empty($item['submenu']);
        
        echo '<li class="' . $active . '">';
        
        if ($hasSubmenu) {
            echo '<a href="' . $item['url'] . '" class="has-submenu" onclick="toggleSubmenu(event, \'' . $key . '\')">';
            echo '<i class="' . $item['icon'] . '"></i>';
            echo '<span>' . $item['title'] . '</span>';
            echo '<i class="fas fa-chevron-down submenu-arrow"></i>';
            if (isset($item['description'])) {
                echo '<small>' . $item['description'] . '</small>';
            }
            echo '</a>';
            
            echo '<ul class="submenu" id="submenu-' . $key . '">';
            foreach ($item['submenu'] as $subKey => $subItem) {
                echo '<li>';
                echo '<a href="' . $subItem['url'] . '">';
                echo '<i class="' . $subItem['icon'] . '"></i>';
                echo '<span>' . $subItem['title'] . '</span>';
                if (isset($subItem['description'])) {
                    echo '<small>' . $subItem['description'] . '</small>';
                }
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<a href="' . $item['url'] . '">';
            echo '<i class="' . $item['icon'] . '"></i>';
            echo '<span>' . $item['title'] . '</span>';
            if (isset($item['description'])) {
                echo '<small>' . $item['description'] . '</small>';
            }
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
    width: 280px;
    height: 100vh;
    background: #2c3e50;
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    z-index: 1000;
    transition: transform 0.3s ease;
}

.staff-sidebar.collapsed {
    transform: translateX(-280px);
}

.sidebar-toggle {
    position: absolute;
    right: 15px;
    top: 15px !important;
    background: #3498db;
    border: none;
    color: white;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    transition: all 0.3s ease;
    z-index: 1001;
}

.sidebar-toggle:hover {
    background: #2980b9;
    transform: scale(1.1);
}

.sidebar-toggle i {
    font-size: 16px;
}

.sidebar-header {
    padding: 20px;
    background: #34495e;
    border-bottom: 1px solid #4a5f7a;
    position: relative;
}

.sidebar-header h4 {
    margin: 0;
    color: white;
    font-size: 18px;
    font-weight: 600;
}

.sidebar-menu {
    list-style: none;
    margin: 0;
    padding: 0;
}

.sidebar-menu > li {
    border-bottom: 1px solid #4a5f7a;
}

.sidebar-menu a {
    display: block;
    padding: 15px 20px;
    color: #bdc3c7;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
}

.sidebar-menu a:hover {
    background: #34495e;
    color: white;
}

.sidebar-menu a i {
    width: 20px;
    margin-right: 10px;
    text-align: center;
}

.sidebar-menu a span {
    display: block;
    font-weight: 500;
    font-size: 13px;
    line-height: 1.2;
}

.sidebar-menu a small {
    display: block;
    font-size: 11px;
    color: #95a5a6;
    margin-top: 3px;
    line-height: 1.3;
}

.sidebar-menu li.active > a {
    background: #3498db;
    color: white;
}

.sidebar-menu li.active a {
    background: #3498db;
    color: white;
}

.sidebar-menu li.active a i {
    color: white;
}

/* Submenu styles */
.submenu {
    list-style: none;
    margin: 0;
    padding: 0;
    background: #34495e;
    display: none;
    border-left: 3px solid #3498db;
}

.submenu li {
    border-bottom: 1px solid #4a5f7a;
}

.submenu a {
    padding: 12px 20px 12px 50px;
    color: #bdc3c7;
    text-decoration: none;
    display: block;
    transition: all 0.3s ease;
    font-size: 12px;
}

.submenu a:hover {
    background: #2c3e50;
    color: white;
    padding-left: 55px;
}

.submenu a i {
    width: 18px;
    margin-right: 8px;
    font-size: 11px;
}

.submenu a span {
    font-weight: 400;
}

.submenu a small {
    font-size: 10px;
    color: #95a5a6;
    margin-top: 2px;
}

.has-submenu {
    position: relative;
}

.submenu-arrow {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.3s ease;
    font-size: 10px;
}

.submenu-open .submenu-arrow {
    transform: translateY(-50%) rotate(180deg);
}

/* Main content adjustment */
.main-content {
    margin-left: 280px;
    min-height: 100vh;
    background: #f8f9fa;
    transition: margin-left 0.3s ease;
}

.main-content.expanded {
    margin-left: 0;
}

@media (max-width: 768px) {
    .staff-sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .sidebar-toggle {
        right: 10px;
        top: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const staffSidebar = document.getElementById('staffSidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebarToggle && staffSidebar) {
        sidebarToggle.addEventListener('click', function() {
            staffSidebar.classList.toggle('collapsed');
            
            if (mainContent) {
                mainContent.classList.toggle('expanded');
            }
            
            // Toggle icon between bars and times
            const icon = this.querySelector('i');
            if (staffSidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
    }
    
    // Handle submenu toggle
    window.toggleSubmenu = function(event, menuKey) {
        event.preventDefault();
        
        const submenu = document.getElementById('submenu-' + menuKey);
        const menuItem = event.target.closest('li');
        const arrow = menuItem.querySelector('.submenu-arrow');
        
        if (submenu) {
            const isVisible = submenu.style.display === 'block';
            
            // Close all other submenus
            document.querySelectorAll('.submenu').forEach(function(otherSubmenu) {
                if (otherSubmenu !== submenu) {
                    otherSubmenu.style.display = 'none';
                    otherSubmenu.closest('li').classList.remove('submenu-open');
                }
            });
            
            // Toggle current submenu
            if (isVisible) {
                submenu.style.display = 'none';
                menuItem.classList.remove('submenu-open');
            } else {
                submenu.style.display = 'block';
                menuItem.classList.add('submenu-open');
            }
        }
        
        return false;
    };

        
        
        
    });
</script>
