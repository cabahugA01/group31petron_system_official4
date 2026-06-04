<?php
// Staff Layout Template - Includes sidebar navigation and main content area
function renderStaffLayout($title, $content, $current_page = 'dashboard') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> - Staff Portal</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f8f9fa;
                color: #2c3e50;
            }
            
            .staff-container {
                display: flex;
                min-height: 100vh;
            }
            
            .staff-sidebar {
                width: 250px;
                background: #2c3e50;
                color: white;
                position: fixed;
                left: 0;
                top: 0;
                height: 100vh;
                overflow-y: auto;
                z-index: 1001;
            }
            
            .sidebar-header {
                padding: 20px;
                background: #34495e;
                border-bottom: 1px solid #4a5f7a;
                text-align: center;
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
            
            .has-submenu .submenu {
                list-style: none;
                margin: 0;
                padding: 0;
                background: #34495e;
                display: none;
            }
            
            .has-submenu.open .submenu {
                display: block;
            }
            
            .has-submenu .submenu li {
                border-bottom: 1px solid #4a5f7a;
            }
            
            .has-submenu .submenu a {
                padding: 12px 20px 12px 50px;
                font-size: 14px;
            }
            
            .submenu-toggle i.fa-chevron-down {
                float: right;
                transition: transform 0.3s ease;
            }
            
            .has-submenu.open .submenu-toggle i.fa-chevron-down {
                transform: rotate(180deg);
            }
            
            .main-content {
                margin-left: 250px;
                flex: 1;
                background: #f8f9fa;
                position: relative;
                z-index: 999;
            }
            
            .staff-header {
                background: white;
                padding: 20px 30px;
                border-bottom: 1px solid #e9ecef;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            
            .staff-header h1 {
                margin: 0;
                color: #2c3e50;
                font-size: 24px;
                font-weight: 600;
            }
            
            .staff-header .subtitle {
                color: #7f8c8d;
                margin-top: 5px;
            }
            
            .staff-content {
                padding: 30px;
            }
            
            .card {
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                border: 1px solid #e9ecef;
                margin-bottom: 20px;
            }
            
            .card-header {
                padding: 20px;
                border-bottom: 1px solid #e9ecef;
                font-weight: 600;
                color: #2c3e50;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .btn {
                padding: 8px 16px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                font-weight: 500;
                transition: all 0.3s ease;
            }
            
            .btn-primary {
                background: #3498db;
                color: white;
            }
            
            .btn-primary:hover {
                background: #2980b9;
            }
            
            .btn-success {
                background: #27ae60;
                color: white;
            }
            
            .btn-success:hover {
                background: #229954;
            }
            
            .btn-warning {
                background: #f39c12;
                color: white;
            }
            
            .btn-warning:hover {
                background: #e67e22;
            }
            
            .btn-danger {
                background: #e74c3c;
                color: white;
            }
            
            .btn-danger:hover {
                background: #c0392b;
            }
            
            .table {
                width: 100%;
                border-collapse: collapse;
                background: white;
            }
            
            .table th,
            .table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #e9ecef;
            }
            
            .table th {
                background: #f8f9fa;
                font-weight: 600;
                color: #2c3e50;
            }
            
            .badge {
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
                display: inline-block;
            }
            
            .badge-success {
                background: #d4edda;
                color: #155724;
            }
            
            .badge-warning {
                background: #fff3cd;
                color: #856404;
            }
            
            .badge-danger {
                background: #f8d7da;
                color: #721c24;
            }
            
            .alert {
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            
            .alert-success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .alert-warning {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            
            .alert-danger {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
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
                
                .staff-content {
                    padding: 15px;
                }
            }
        </style>
    </head>
    <body>
        <div class="staff-container">
            <?php include __DIR__ . '/staff_sidebar.php'; ?>
            
            <div class="main-content">
                <div class="staff-header">
                    <h1><?php echo htmlspecialchars($title); ?></h1>
                    <div class="subtitle">Staff Portal - Petron Station Management</div>
                </div>
                
                <div class="staff-content">
                    <?php echo $content; ?>
                </div>
            </div>
        </div>
        
        <script src="../assets/js/scroll_toggle_button.js"></script>
        <script>
            // Sidebar toggle functionality
            document.addEventListener('DOMContentLoaded', function() {
                const toggles = document.querySelectorAll(".submenu-toggle");
                toggles.forEach(toggle => {
                    toggle.addEventListener("click", function(e) {
                        e.preventDefault();
                        const parent = this.closest(".has-submenu");
                        parent.classList.toggle("open");
                    });
                });
                
                // Set active menu item
                const currentPath = window.location.pathname;
                const menuLinks = document.querySelectorAll('.sidebar-menu a');
                menuLinks.forEach(link => {
                    if (link.getAttribute('href') === currentPath.split('/').pop()) {
                        link.closest('li').classList.add('active');
                    }
                });
                
                // Initialize scroll toggle button for main content (right side above footer)
                console.log('Initializing main content scroll toggle...');
                
                // Create a scroll toggle button for the main content area
                const mainScrollBtn = new ScrollToggleButton({
                    containerSelector: '.main-content, .staff-content, body',
                    buttonId: 'mainScrollToggleBtn',
                    buttonClass: 'main-scroll-toggle-btn',
                    position: 'bottom-right',
                    iconSize: '16px',
                    buttonSize: '40px',
                    showThreshold: 100
                });
                
                // Position button above footer (adjust bottom position)
                setTimeout(() => {
                    const btn = document.getElementById('mainScrollToggleBtn');
                    if (btn) {
                        btn.style.bottom = '80px'; // Position above footer
                        btn.style.right = '20px';
                        
                        // Make button visible
                        btn.classList.add('visible');
                        btn.style.display = 'flex';
                        btn.style.opacity = '1';
                    }
                }, 500);
            });
        </script>
    </body>
    </html>
    <?php
}
?>
