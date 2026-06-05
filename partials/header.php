<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$user = $_SESSION['user'];

// Get current page ID from filename (only if not already set by the calling page)
if (!isset($page_id)) {
    $page_id = basename($_SERVER['PHP_SELF'], '.php');
}
// Normalize Role to ensure sidebar works correctly regardless of DB casing / naming
// Supports: manager/supervisor, staff, etc.
$role = function_exists('role_key') ? role_key($user['role'] ?? '') : strtolower(trim($user['role'] ?? 'staff'));

$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$public_pos = strpos($script_name, '/public/');
if ($public_pos !== false) {
    $app_base_path = substr($script_name, 0, $public_pos);
} else {
    $app_base_path = rtrim(dirname($script_name), '/');
}

if ($app_base_path === '' || $app_base_path === '.') {
    $app_base_path = '';
}

$public_base_url = $app_base_path . '/public';
$myStationId = user_station_id();

// --- FETCH ALERTS FOR DROPDOWN ---
$header_alerts = [];
if(in_array($role, ['superadmin','admin','manager'])){
    // 1. Failed Logins (Super Admin only)
    if($role === 'superadmin'){
        try {
            $failed_stmt = $pdo->prepare("SELECT user_id, details, created_at FROM activity_logs WHERE action = 'Login Failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (user_id IS NULL OR user_id <> ?) ORDER BY created_at DESC LIMIT 5");
            $failed_stmt->execute([(int)($user['id'] ?? 0)]);
            $failed_logins = $failed_stmt->fetchAll();
            foreach($failed_logins as $fl) {
                $details = (string)($fl['details'] ?? '');
                $searchUser = '';
                if (preg_match('/username\s*:\s*([^\s]+)/i', $details, $matches)) {
                    $searchUser = trim($matches[1]);
                }

                $failedLink = $public_base_url . '/audit_logs.php?date=today&event=' . urlencode('Login Failed');
                if ($searchUser !== '') {
                    $failedLink .= '&q=' . urlencode($searchUser);
                }

                $header_alerts[] = [
                    'msg' => "Failed Login: " . htmlspecialchars($fl['details']),
                    'time' => $fl['created_at'],
                    'link' => $failedLink
                ];
            }
        } catch(Exception $e){}
    }
    // 2. Password Expirations
    try {
        $expiring_passwords = $pdo->query("SELECT username FROM users WHERE password_expires_at < NOW() AND status = 'active' LIMIT 5")->fetchAll();
        foreach($expiring_passwords as $ep) $header_alerts[] = ['msg'=>"Password Expired: {$ep['username']}", 'time'=>'Now', 'link'=>'users.php'];
    } catch(Exception $e){}
    // 3. Reconciliation Delays (Super Admin only)
    if($role === 'superadmin'){
        try {
            // Assuming reconciliation is daily, check if today's reconciliation is missing for any station
            $today = date('Y-m-d');
            $missing_recons = $pdo->query("SELECT s.name FROM stations s LEFT JOIN reconciliation_results r ON s.id = r.station_id AND r.recon_date = '$today' WHERE r.id IS NULL LIMIT 5")->fetchAll();
            foreach($missing_recons as $mr) $header_alerts[] = ['msg'=>"Reconciliation Delay: {$mr['name']}", 'time'=>'Today', 'link'=>'reconciliation.php'];
        } catch(Exception $e){}
    }
    // 4. Anomalies Detected
    $sales_data = read_json('sales.json', []);
    foreach($sales_data as $s){
        if(($s['total'] > 10000 || $s['total'] == 0)) $header_alerts[] = ['msg'=>"Anomaly Detected: ₱".number_format($s['total']), 'time'=>$s['date']??'', 'link'=>'transactions.php'];
    }
    // 5. Inventory (keep existing)
    try {
        $inv = $pdo->query("SELECT product_name FROM inventory WHERE stock_level <= 20 LIMIT 5")->fetchAll();
        foreach($inv as $i) $header_alerts[] = ['msg'=>"Low Stock: {$i['product_name']}", 'time'=>'Now', 'link'=>'oversight.php'];
    } catch(Exception $e){}
    // 6. Pending Jobs (keep existing)
    try {
        $pjobs = $pdo->query("SELECT id FROM job_orders WHERE status='Pending' LIMIT 5")->fetchAll();
        foreach($pjobs as $j) $header_alerts[] = ['msg'=>"Pending Job #{$j['id']}", 'time'=>'Now', 'link'=>'joborder_stats.php'];
    } catch(Exception $e){}
    // 8. Pending Deliveries
    try {
        $pending_deliveries = $pdo->query("SELECT id FROM receiving WHERE status = 'pending' LIMIT 5")->fetchAll();
        foreach($pending_deliveries as $d) $header_alerts[] = ['msg'=>"Pending Delivery #{$d['id']}", 'time'=>'Now', 'link'=>'supplier_confirmation.php'];
    } catch(Exception $e){}
    // 9. Credit Warnings
    try {
        $credit_warnings = $pdo->query("SELECT name FROM customers WHERE credit_balance > 0 LIMIT 5")->fetchAll();
        foreach($credit_warnings as $cw) $header_alerts[] = ['msg'=>"Credit Warning: {$cw['name']}", 'time'=>'Now', 'link'=>'customer_credit.php'];
    } catch(Exception $e){}
    // 10. Fuel Variance (keep existing)
    $fuel_readings = read_json('fuel_readings.json', []);
    foreach($fuel_readings as $fr) {
        if(($fr['computed_liters'] ?? 0) < 0) {
             if($role !== 'superadmin' && ($fr['station_id']??'') != $myStationId) continue;
             $header_alerts[] = ['msg'=>"Fuel Variance: Station " . ($fr['station_id']??'?'), 'time'=>$fr['date']??'', 'link'=>'oversight.php'];
        }
    }
}
$header_alerts = array_slice($header_alerts, 0, 5);
$unread_alerts = count($header_alerts);

// --- BADGE LOGIC ---
$badges = [];
$station_name = '';
$current_date = date('Y-m-d');
$hour = (int)date('H');
$shift = ($hour >= 6 && $hour < 14) ? 'First Shift' : 'Second Shift';

// Get station name for all non-superadmin users
if ($myStationId && in_array($role, ['admin', 'manager', 'staff'])) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
        $stmt->execute([$myStationId]);
        $station_name = $stmt->fetchColumn() ?: 'Unknown Station';
    } catch (Exception $e) {
        $station_name = 'Unknown Station';
    }
}

// 1. Transactions / Anomalies (JSON)
if (in_array($role, ['superadmin','admin','manager'])) {
    $sales_data = read_json('sales.json', []);
    $anomalies_count = 0;
    $station_anomalies = 0;
    foreach ($sales_data as $s) {
        $amt = (float)($s['total'] ?? 0);
        if ($amt > 10000 || $amt == 0) {
            $anomalies_count++;
            if (($s['station_id'] ?? '') == $myStationId) {
                $station_anomalies++;
            }
        }
    }
    if ($role === 'superadmin') {
        $badges['transactions'] = $anomalies_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $badges['pos'] = $station_anomalies;
    }
}

// 2. Job Orders & Users (DB)
try {
    if ($role === 'superadmin') {
        $badges['joborder_stats'] = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'Pending'")->fetchColumn();
        $badges['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();
        
        // Inventory Shortages (Oversight)
        $shortages_count = $pdo->query("SELECT COUNT(*) FROM inventory WHERE stock_level <= 20")->fetchColumn();
        $badges['oversight'] = $shortages_count;

        // Reports aggregates all anomalies/action items
        $badges['reports'] = ($badges['transactions'] ?? 0) + $badges['joborder_stats'] + $shortages_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status = 'Pending'");
        $stmt->execute([$myStationId]);
        $pending_jo_count = (int)$stmt->fetchColumn();
        $badges['joborder'] = $pending_jo_count; // manager

        // Inventory Shortages
        $stmtInv = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE station_id = ? AND stock_level <= 20");
        $stmtInv->execute([$myStationId]);
        $badges['inventory'] = (int)$stmtInv->fetchColumn();

        if ($role === 'admin') {
            // Admin-specific badge keys matching sidebar item IDs
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Pending'");
                $s->execute([$myStationId]);
                $pending_tx = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_tx = 0; }
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation') AND type='merch'");
                $s->execute([$myStationId]);
                $pending_po = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_po = 0; }
            $badges['admin_transactions_oversight'] = $pending_tx + $pending_jo_count;
            $badges['purchase_orders_admin']        = $pending_po;
            // Badge for Stock-In: POs admin-finalized AND manager-validated, awaiting stock-in
            try {
                $s2 = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
                $s2->execute([$myStationId]);
                $badges['admin_stock_in'] = (int)$s2->fetchColumn();
            } catch (Exception $e) { $badges['admin_stock_in'] = 0; }
            $badges['reports_admin']                = $pending_tx + $pending_jo_count + ($badges['inventory'] ?? 0);
        } else {
            // Reports Aggregate for manager
            $badges['reports'] = ($badges['pos'] ?? 0) + $pending_jo_count + ($badges['inventory'] ?? 0);
        }
    } elseif ($role === 'staff') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE user_id = ? AND status IN ('Pending', 'In Progress', 'Awaiting Parts')");
        $stmt->execute([$user['id']]);
        $badges['joborder'] = $stmt->fetchColumn();
        // Stock-In badge: POs admin-finalized AND manager-validated, awaiting stock-in
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
            $s->execute([$myStationId]);
            $badges['staff_stock_in'] = (int)$s->fetchColumn();
        } catch (Exception $e) { $badges['staff_stock_in'] = 0; }
        // Stock requests badge: pending requests submitted by this staff
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status='Pending'");
            $s->execute([$user['id']]);
            $badges['staff_stock_requests'] = (int)$s->fetchColumn();
        } catch (Exception $e) { $badges['staff_stock_requests'] = 0; }
    }

    // Deliveries Oversight pending badge (admin)
    if ($role === 'admin' || $role === 'superadmin') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status IN ('Pending Validation','Pending Manager Approval','Confirmed')");
            $stmt->execute([$myStationId]);
            $badges['deliveries_oversight'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['deliveries_oversight'] = 0; }
    }
    // Manager deliveries pending badge
    if ($role === 'manager') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status = 'Pending Manager Approval'");
            $stmt->execute([$myStationId]);
            $badges['manager_deliveries'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['manager_deliveries'] = 0; }
    }

    // Fetch Stations for Header Filter (Super Admin)
    $header_stations = [];
    if ($role === 'superadmin') {
        try {
            $header_stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
} catch (Exception $e) { /* Tables might not exist yet */ }

// --- SYSTEM STATUS CHECK ---
$db_connection_status = 'OK';
$db_connection_color = 'var(--petron-green)';
// db_connect.php throws an exception, so if we're here, the initial connection was fine.
// This is a fallback check.
if (!isset($pdo) || !$pdo) {
    $db_connection_status = 'Error';
    $db_connection_color = 'var(--petron-red)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Petron Management System</title>
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/style.css?v=2.0.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/manager_table_design.css?v=2.0.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/manager_customer_management.css?v=2.0.2" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* Global FontAwesome Icon Visibility Fix */
    .fas, .far, .fab, .fa {
        opacity: 1 !important;
        visibility: visible !important;
        display: inline-block !important;
    }

    /* Ensure all icons in headers and cards are visible */
    .card-header i,
    .metric-icon i,
    .admin-metric-icon i,
    .stat-icon i,
    .btn i,
    button i,
    h3 i,
    h4 i {
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* Petron Station Global Theme */
    :root {
        --petron-blue: #00264D;
        --petron-red: #CC0000;
        --petron-gray: #F2F2F2;
        --petron-yellow: #FFC107;
        --petron-white: #FFFFFF;
        --petron-muted: #666666;
        --petron-success: #28A745;
        --petron-warning: #FFC107;
        --petron-danger: #DC3545;
        --petron-info: #17A2B8;
        --primary: #00264D;
        --accent: #CC0000;
        --petron-green: #28A745;
    }
    html, body { max-width: 100vw; overflow-x: hidden; } /* Sidebar Navigation */
    .sidebar { 
        background-color: var(--petron-blue) !important; 
        color: #ffffff !important;
    }
    
    /* Desktop Sidebar Layout (Header + Sidebar Integration, Fixed Footer) */
    @media (min-width: 992px) {
        body { overflow: hidden; }

        .top-header {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            height: 70px;
            z-index: 1002;
            background-color: #ffffff;
            padding: 0; /* Reset padding to handle split bg */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 70px;
            left: 0;
            bottom: 0;
            width: 250px;
            z-index: 1001;
            overflow: hidden;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--line);
            transition: width 0.3s ease;
        }
        .sidebar-menu { 
            flex: 1 1 auto; 
            overflow-y: auto;
            overflow-x: hidden;
            padding-top: 52px; /* clear the floating hamburger button */
            padding-bottom: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.3) transparent;
        }
        .sidebar-menu::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
        }
        .sidebar-menu::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        } 

        /* ── Sidebar Identity Footer ── */
        .sidebar-identity-footer {
            position: relative !important;
            bottom: auto !important;
            left: auto !important;
            width: 100% !important;
            height: 70px !important;
            flex-shrink: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 3px !important;
            padding: 6px 12px !important;
            background: rgba(0,0,0,0.35) !important;
            border-top: 1px solid rgba(255,255,255,0.12) !important;
            overflow: hidden !important;
            cursor: default !important;
            user-select: none !important;
            z-index: auto !important;
            box-sizing: border-box !important;
            transition: width 0.3s ease !important;
            text-align: center !important;
        }

        /* sidebar-menu fills remaining space, footer sits below it naturally. Increased padding so last item is fully visible. */
        .sidebar-menu {
            padding-bottom: 80px !important;
        }

        /* Avatar circle — centered above name */
        .sif-avatar {
            flex-shrink: 0 !important;
            width: 30px !important;
            height: 30px !important;
            border-radius: 50% !important;
            border: 1.5px solid rgba(255,255,255,0.4) !important;
            background: rgba(255,255,255,0.15) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            font-size: 15px !important;
            color: rgba(255,255,255,0.85) !important;
        }

        .sif-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 50% !important;
            display: block !important;
        }

        /* Name line: FIRSTNAME LASTNAME */
        .sif-name {
            font-size: 10.5px !important;
            font-weight: 800 !important;
            color: #ffffff !important;
            letter-spacing: 0.4px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.2 !important;
            max-width: 100% !important;
            display: block !important;
            text-align: center !important;
        }

        /* Separator dash + role on same line */
        .sif-role-line {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            max-width: 100% !important;
            overflow: hidden !important;
        }

        .sif-dash {
            color: rgba(255,255,255,0.35) !important;
            font-size: 9px !important;
            flex-shrink: 0 !important;
        }

        .sif-role {
            font-size: 9px !important;
            font-weight: 700 !important;
            color: rgba(255,255,255,0.55) !important;
            letter-spacing: 0.8px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.2 !important;
            text-transform: uppercase !important;
        }

        /* Collapsed: show only avatar centered, hide text */
        body.sidebar-collapsed .sidebar-identity-footer {
            width: 100% !important;
            justify-content: center !important;
            padding: 0 !important;
        }        body.sidebar-collapsed .sif-name,
        body.sidebar-collapsed .sif-role-line {
            display: none !important;
        }
        body.sidebar-collapsed .sif-avatar {
            width: 34px !important;
            height: 34px !important;
            font-size: 18px !important;
        }

        /* Sub-menus use normal document flow — they expand inline, not floating */
        .sidebar-menu > nav > div > div[id^="sub-"] {
            position: relative !important;
            z-index: 1002 !important;
            overflow: visible !important;
        }

        /* Reset nav element default spacing */
        .sidebar-menu nav.nav {
            margin: 0;
            padding: 0;
            display: block;
            gap: 0;
        }

        /* Sidebar Collapsed State */
        .sidebar.collapsed {
            width: 70px; /* Icon-only width */
        }

        .sidebar.collapsed .nav-item span:not(.ico) {
            display: none !important; /* Hide text labels */
        }

        .sidebar.collapsed .nav-item {
            justify-content: center !important; /* Center icons */
            padding: 12px 8px !important;
        }

        .sidebar.collapsed .nav-item .ico {
            margin-right: 0 !important; /* Remove icon margin */
        }

        .sidebar.collapsed .nav-item .badge,
        .sidebar.collapsed .nav-item span[style*="background:#E30613"] {
            display: none !important; /* Hide badges in collapsed state */
        }

        .sidebar.collapsed .nav-item i.fa-chevron-down {
            display: none !important; /* Hide chevron dropdown icon in collapsed state */
        }

        .sidebar.collapsed [id^="sub-"] {
            display: none !important; /* Hide submenu items in collapsed state */
        }


        /* Main content adjustments */
        .main {
            position: fixed;
            top: 70px;
            left: 250px;
            right: 0;
            bottom: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 20px 60px 20px;
            background: #f8f9fa;
            transition: left 0.3s ease;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 991px) {
            .main {
                left: 0 !important;
            }
        }

        .main.sidebar-collapsed {
            left: 70px;
        }
        
        /* Sidebar state classes for main content alignment */
        body.sidebar-expanded .main {
            left: 250px !important;
        }
        
        body.sidebar-collapsed .main {
            left: 70px !important;
        }
    }

    .brand-title { color: var(--petron-blue) !important; font-weight: bold; font-size: 1.3em; line-height: 1.1; }
    .brand-mark {
        width: 40px; height: 40px;
        margin-right: 10px;
        object-fit: contain;
    }

    /* Page Header Styles - UPPERCASE for All Roles (Super Admin, Admin, Manager, Staff) */
    .page-head, .page-header {
        margin-bottom: 20px;
    }
    
    .page-head h1, .page-header h1,
    .page-head .h1, .page-header .h1,
    .page-title, .page-head .page-title, .page-header .page-title {
        text-transform: uppercase !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        color: var(--petron-blue) !important;
        margin: 0 0 8px 0 !important;
        letter-spacing: 0.5px !important;
    }
    
    .page-subtitle, .page-head .sub, .page-header .page-subtitle {
        text-transform: uppercase !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #666666 !important;
        margin: 0 !important;
        letter-spacing: 0.3px !important;
    }
    
    /* Additional header elements to ensure consistency */
    h1, h2, h3 {
        text-transform: uppercase !important;
        color: var(--petron-blue) !important;
        letter-spacing: 0.3px !important;
    }
    
    h1 {
        font-size: 24px !important;
        font-weight: 700 !important;
    }
    
    h2 {
        font-size: 20px !important;
        font-weight: 600 !important;
    }
    
    h3 {
        font-size: 18px !important;
        font-weight: 600 !important;
    }

    .nav-item { color: #eeeeee !important; transition: all 0.2s; display: flex; align-items: center; justify-content: flex-start; padding: 10px 15px; text-decoration: none; min-height: 44px; }
    .nav-item:hover { background-color: rgba(255,255,255,0.1) !important; color: #ffffff !important; }
    .nav-item.active { background-color: var(--petron-red) !important; color: #ffffff !important; }
    
    .nav-item .ico {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        margin-right: 12px;
        flex-shrink: 0;
    }
    
    .nav-item .ico i {
        font-size: 16px;
        text-align: center;
        line-height: 1;
    }
    
    .nav-item span:not(.ico) {
        font-size: 13px;
        font-weight: 500;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .nav-item-wrapper {
        margin: 0;
        padding: 0;
        border: none;
    }
    
    .sidebar-collapse-btn {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        font-size: 14px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    
    /* Notifications Dropdown */
    .notification-bell { 
        position: relative; 
        cursor: pointer; 
        color: var(--petron-blue); 
        font-size: 1.2rem; 
        padding: 8px; 
        display: inline-block;
        border-radius: 50%;
        transition: all 0.2s ease;
    }
    .notification-bell:hover {
        background: rgba(0, 47, 112, 0.1);
        transform: scale(1.05);
    }
    .notification-bell .badge { 
        position: absolute; 
        top: -8px; 
        right: -8px; 
        background: #dc3545 !important; 
        color: white !important; 
        border-radius: 50%; 
        width: 20px; 
        height: 20px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 10px; 
        font-weight: bold; 
        min-width: 18px; 
        text-align: center; 
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        z-index: 1001;
    }
    
        .notif-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        width: 380px;
        z-index: 9999;
        border: 1px solid rgba(0,0,0,0.08);
        margin-top: 12px;
        display: none;
        transform-origin: top right;
    }
    .notif-dropdown.show { 
        display: block !important;
        animation: dropdownSlide 0.2s ease-out;
    }
    
    @keyframes dropdownSlide {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .notif-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        border-bottom: 1px solid #f0f0f0;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 12px 12px 0 0;
        min-height: 60px;
        position: relative;
        z-index: 10;
    }
    
    .notif-dropdown-header span {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        letter-spacing: 0.5px;
    }
    
    .notif-header-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    .notif-header-actions button {
        font-size: 12px;
        color: #002f70;
        background: none;
        border: none;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .notif-header-actions button:hover {
        background: rgba(0, 47, 112, 0.1);
        color: #00449e;
        transform: translateY(-1px);
    }
    
    .notif-header-actions button i {
        font-size: 14px;
    }
    
    .notif-dropdown-footer {
        border-top: 1px solid #f0f0f0;
        background: #f8f9fa;
        border-radius: 0 0 12px 12px;
        padding: 12px;
        text-align: center;
    }
    
    .notif-dropdown-footer a {
        color: #002f70;
        text-decoration: none;
        font-weight: 600;
        font-size: 13px;
        transition: color 0.2s ease;
    }
    
    .notif-dropdown-footer a:hover {
        color: #00449e;
    }
    
    .notif-empty {
        padding: 50px 20px;
        text-align: center;
        color: #999;
    }
    
    .notif-empty i {
        font-size: 32px;
        margin-bottom: 12px;
        color: #ddd;
    }
    
    .notif-item {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 16px 24px;
        border-bottom: 1px solid #f5f5f5;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }
    
    .notif-item:hover {
        background: #f8f9fa;
        transform: translateX(2px);
    }
    
    .notif-item.unread {
        background: linear-gradient(90deg, #f0f8ff 0%, #ffffff 100%);
        border-left: 4px solid #002f70;
        font-weight: 600;
    }
    
    .notif-item.unread::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: #002f70;
    }
    
    .notif-item.read {
        background: #ffffff;
        opacity: 0.8;
    }
    
    .notif-item.read .notif-title {
        font-weight: 500;
        color: #666;
    }
    
    .notif-item.read .notif-message {
        color: #999;
    }
    
    .notif-icon {
        margin-right: 14px;
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f0f0f0;
    }
    
    .notif-content {
        flex: 1;
        min-width: 0;
    }
    
    .notif-title {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 4px;
        line-height: 1.3;
        font-size: 14px;
    }
    
    .notif-message {
        color: #666;
        font-size: 13px;
        line-height: 1.4;
        margin-bottom: 6px;
    }
    
    .notif-time {
        color: #999;
        font-size: 11px;
        font-weight: 500;
    }
    
    .notif-status {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #002f70;
        margin-left: 12px;
        flex-shrink: 0;
        margin-top: 6px;
    }
    
    .notif-status.read {
        background: transparent;
    }
    
    .notif-item.unread::before {
        content: '';
        position: absolute;
        left: 4px;
        top: 50%;
        transform: translateY(-50%);
        width: 6px;
        height: 6px;
        background: #2563eb;
        border-radius: 50%;
    }
    
    .notif-icon {
        flex-shrink: 0;
    }
    
    .notif-type-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
        color: white;
    }
    
    .notif-type-icon i {
        font-size: 12px;
        margin: 0;
        padding: 0;
        line-height: 1;
    }
    
    .notif-type-icon.icon-success {
        background: #16a34a;
    }
    
    .notif-type-icon.icon-warning {
        background: #d97706;
    }
    
    .notif-type-icon.icon-error {
        background: #dc2626;
    }
    
    .notif-type-icon.icon-info {
        background: #2563eb;
    }
    
    .notif-content {
        flex: 1;
        min-width: 0;
    }
    
    .notif-title {
        font-size: 13px;
        font-weight: 500;
        color: #333;
        line-height: 1.3;
        margin-bottom: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .notif-time {
        font-size: 11px;
        color: #888;
    }
    
    .notif-status {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    
    .notif-status.unread {
        background: #2563eb;
    }
    
    .notif-status.read {
        background: transparent;
    }

    /* Top Header */
    .top-header {
        display: flex;
        align-items: center;
        background-color: #ffffff;
        padding: 0 20px;
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }
    .header-center {
        flex-grow: 1;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .header-right {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-shrink: 0;
    }
    .profile-access {
        position: relative;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: var(--petron-blue);
    }
    .profile-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: #fff;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        border-radius: 4px;
        min-width: 180px;
        z-index: 1100;
        border: 1px solid #eee;
        margin-top: 5px;
    }
    .profile-dropdown a {
        display: block;
        padding: 10px 15px;
        text-decoration: none;
        color: #333;
        font-size: 14px;
    }
    .profile-dropdown a:hover {
        background: #f9f9f9;
        color: var(--petron-blue);
    }
    .profile-dropdown.show { display: block !important; }
    .profile-dropdown .dropdown-divider { height: 1px; margin: .5rem 0; overflow: hidden; background-color: #e9ecef; }

    /* Settings Icon */
    .settings-icon {
        color: var(--petron-blue);
        font-size: 1.2rem;
        text-decoration: none;
        cursor: pointer;
        transition: color 0.2s;
    }
    .settings-icon:hover {
        color: var(--petron-red);
    }

    /* Sidebar Toggle Button */
    .sidebar-toggle {
        background: rgba(255, 255, 255, 0.1);
        border: none;
        color: #ffffff;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 8px 12px;
        border-radius: 8px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
    }

    .sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-50%) scale(1.05);
    }

    .sidebar-toggle:active {
        transform: translateY(-50%) scale(0.95);
    }

    /* Collapsed state adjustments */
    .sidebar.collapsed .sidebar-toggle {
        right: 50%;
        transform: translateX(50%) translateY(-50%);
    }

    .sidebar.collapsed .sidebar-toggle:hover {
        transform: translateX(50%) translateY(-50%) scale(1.05);
    }

    .sidebar.collapsed .sidebar-toggle:active {
        transform: translateX(50%) translateY(-50%) scale(0.95);
    }

    /* Tooltip for collapsed sidebar */
    .sidebar.collapsed .nav-item {
        position: relative;
    }

    .sidebar.collapsed .nav-item::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 10px;
        background: rgba(0, 0, 0, 0.9);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
        z-index: 1000;
    }

    .sidebar.collapsed .nav-item:hover::after {
        opacity: 1;
    }

    /* Mobile responsive adjustments */
    @media (max-width: 991px) {
        .sidebar-toggle {
            display: none; /* Hide toggle on mobile (use mobile menu) */
        }
        
        #scrollToggleBar {
            display: none; /* Hide toggle bar on mobile */
        }
        
        .sidebar-toggle-bar {
            display: none; /* Hide toggle bar on mobile */
        }
    }

    /* Sidebar Collapse Button Styling */
    .sidebar-collapse-btn {
        background: var(--petron-blue) !important;
        border: none !important;
        color: #ffffff !important;
        font-size: 16px !important;
        cursor: pointer !important;
        padding: 0 !important;
        border-radius: 50% !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        width: 40px !important;
        height: 40px !important;
        position: relative !important;
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.3) !important;
    }

    .sidebar-collapse-btn:hover {
        background: #0040a0 !important;
        transform: scale(1.1) !important;
        box-shadow: 0 4px 12px rgba(0, 47, 112, 0.4) !important;
    }

    .sidebar-collapse-btn:active {
        transform: scale(0.95) !important;
        box-shadow: 0 2px 4px rgba(0, 47, 112, 0.2) !important;
        background: var(--petron-blue) !important;
        background-color: var(--petron-blue) !important;
    }
    
    .sidebar-collapse-btn:focus,
    .sidebar-collapse-btn:focus-visible {
        outline: none !important;
        background: var(--petron-blue) !important;
        background-color: var(--petron-blue) !important;
        box-shadow: 0 0 0 3px rgba(255,255,255,0.3) !important;
    }
    
    .sidebar-collapse-btn i {
        font-size: 16px !important;
        margin: 0 !important;
        transition: transform 0.3s ease !important;
    }
    
    .sidebar-collapse-btn:hover i {
        transform: none !important;
    }
    
    /* Sidebar Collapsed State */
    .sidebar.collapsed {
        width: 60px !important;
    }
    
    .sidebar.collapsed .nav-item span:not(.ico) {
        display: none !important;
    }
    
    .sidebar.collapsed .nav-item .ico {
        margin-right: 0 !important;
        justify-content: center !important;
    }
    
    .sidebar.collapsed .nav-item {
        justify-content: center !important;
        padding: 12px 0 !important;
    }
    
    .sidebar.collapsed .sidebar-collapse-btn {
        margin: 0 auto !important;
    }
    
    /* Smooth transition for sidebar */
    .sidebar {
        transition: width 0.3s ease !important;
    }
    
    .nav-item {
        transition: all 0.3s ease !important;
    }

    /* Horizontal Toggle Bar */
    .toggle-bar {
        background: linear-gradient(135deg, var(--petron-blue), #0040a0);
        height: 40px;
        display: flex;
        align-items: center;
        padding: 0 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 47, 112, 0.2);
        z-index: 999;
    }

    .toggle-bar.fixed-top {
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        z-index: 999;
    }

    .toggle-bar.fixed-bottom {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 999;
    }

    .toggle-bar-items {
        display: flex;
        align-items: center;
        gap: 20px;
        flex: 1;
    }

    .toggle-bar-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: white;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .toggle-bar-item:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: translateY(-1px);
    }

    .toggle-bar-item.active {
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .toggle-bar-item i {
        font-size: 16px;
        width: 16px;
        text-align: center;
    }

    .toggle-bar-divider {
        width: 1px;
        height: 24px;
        background: rgba(255, 255, 255, 0.3);
        margin: 0 10px;
    }

    .toggle-bar-right {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-left: auto;
    }

    .toggle-bar-button {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .toggle-bar-button:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-1px);
    }

    .toggle-bar-button:active {
        transform: translateY(0);
    }

    .toggle-bar-button i {
        font-size: 12px;
    }

    /* Toggle bar with sidebar adjustment */
    .main.with-toggle-bar {
        top: 110px; /* Header (70px) + Toggle Bar (40px) */
    }

    .main.with-toggle-bar.sidebar-collapsed {
        left: 70px;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .toggle-bar {
            padding: 0 15px;
            height: 45px;
        }

        .toggle-bar-items {
            gap: 15px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .toggle-bar-items::-webkit-scrollbar {
            display: none;
        }

        .toggle-bar-item {
            font-size: 13px;
            padding: 8px 10px;
        }

        .toggle-bar-item span {
            display: none; /* Hide text on mobile, show only icons */
        }

        .toggle-bar-divider {
            display: none;
        }

        .toggle-bar-right {
            gap: 10px;
        }

        .toggle-bar-button {
            padding: 8px 10px;
            font-size: 11px;
        }
    }
    
    /* Global FontAwesome Icon Visibility Fix */
    .fas, .far, .fab, .fa {
        opacity: 1 !important;
        visibility: visible !important;
        display: inline-block !important;
    }

    /* Ensure all icons in headers and cards are visible */
    .card-header i,
    .metric-icon i,
    .admin-metric-icon i,
    .stat-icon i,
    .btn i,
    button i,
    h3 i,
    h4 i {
        opacity: 1 !important;
        visibility: visible !important;
    }
    
    /* Sidebar Header */
    .sidebar-header {
        display: none;
    }

    /* Toggle row — floats over sidebar, takes no flow space */
    .sidebar-toggle-row {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 52px;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10;
        pointer-events: none;
        border-bottom: 1px solid rgba(255,255,255,0.15);
        background: transparent !important;
    }
    .sidebar-toggle-row .sidebar-collapse-btn {
        pointer-events: auto;
        background: var(--petron-blue) !important;
        background-color: var(--petron-blue) !important;
    }
    
    /* Header Search Bar Styling */
    .header-center form input[type="text"]:focus {
        outline: none;
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
        background: rgba(255, 255, 255, 1);
        transform: translateY(-1px);
    }
    
    .header-center form button:hover {
        background: #001a4d;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 47, 112, 0.3);
    }
    
    /* Notification Bell Improvements */
    .notification-bell {
        position: relative;
        cursor: pointer;
        padding: 8px;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0, 47, 112, 0.05);
        z-index: 1000;
        pointer-events: auto;
    }
    
    .notification-bell:hover {
        background: rgba(0, 47, 112, 0.1);
        transform: scale(1.1);
    }
    
    .notification-bell .badge {
        position: absolute;
        top: -2px;
        right: -2px;
        background: #E30613;
        color: white;
        font-size: 10px;
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 10px;
        min-width: 16px;
        text-align: center;
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        pointer-events: none;
        z-index: 1001;
    }

    /* Profile Dropdown Fix */
    .profile-access {
        position: relative;
        cursor: pointer;
        z-index: 999;
        pointer-events: auto;
    }

    .profile-dropdown {
        z-index: 1001;
        pointer-events: auto;
    }
    
        
        
        
        
      </style>
</head>
<body class="app" data-page="<?php echo htmlspecialchars($page_id); ?>" data-role="<?php echo htmlspecialchars($role); ?>">
  <!-- Debug Info (remove after fixing) -->
  <aside class="sidebar" id="mainSidebar">
    <div class="sidebar-toggle-row">
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Toggle Sidebar">
            <i class="fas fa-bars" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <div class="sidebar-menu">
            <nav class="nav">
<?php
// Include the new RBAC menu generation
require_once __DIR__ . '/rbac_menu.php';

  $base_path = '/group31petron_system_official4/public/';
  
  // Helper function to map hrefs to absolute paths
  function map_hrefs(&$items, $base_path) {
    foreach ($items as &$item) {
      if (isset($item['href']) && !empty($item['href']) && strpos($item['href'], 'http') === false && strpos($item['href'], '#') !== 0) {
        // Make all hrefs absolute paths to /public/, preserving query strings
        $href = $item['href'];
        // Split on '?' to preserve query string
        $qpos = strpos($href, '?');
        if ($qpos !== false) {
            $file_part  = substr($href, 0, $qpos);
            $query_part = substr($href, $qpos); // includes the '?'
            $item['href'] = $base_path . basename($file_part) . $query_part;
        } else {
            $item['href'] = $base_path . basename($href);
        }
      }
      
      if (isset($item['sub_items']) && !empty($item['sub_items'])) {
        map_hrefs($item['sub_items'], $base_path);
      }
    }
  }
  
  map_hrefs($items, $base_path);

  // Determine active sub-item from URL hash or page_id
  $current_url  = basename($_SERVER['PHP_SELF']);
  $current_hash = '';
  if (isset($_SERVER['REQUEST_URI'])) {
      $uri_parts = explode('#', $_SERVER['REQUEST_URI']);
      $current_hash = $uri_parts[1] ?? '';
  }

  // Fuel management sub-item badge counts — proper prepared statements
  $fuel_sub_badges = [];
  if ($role === 'manager' && $myStationId) {
      try {
          // Pending fuel transactions (Fuel Transactions tab)
          $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND LOWER(status)='pending'");
          $s->execute([$myStationId]);
          $cnt = (int)$s->fetchColumn();
          $fuel_sub_badges['fuel_transactions'] = $cnt;

          // Pending deliveries (Fuel Deliveries tab)
          $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id=? AND LOWER(status) IN ('pending','pending review')");
          $s->execute([$myStationId]);
          $fuel_sub_badges['fuel_deliveries'] = (int)$s->fetchColumn();

          // Open variance reports — shown in Reports sidebar
          $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports WHERE station_id=? AND status IN ('Open','Under Investigation')");
          $s->execute([$myStationId]);
          $vcount = (int)$s->fetchColumn();
          $fuel_sub_badges['fuel_variance_report'] = $vcount;
          $fuel_sub_badges['fuel_reconciliation']  = $vcount; // legacy compat

          // Pending merchandise stock requests (Inventory > Stock Requests)
          try {
              $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND status='Pending'");
              $s->execute([$myStationId]);
              $fuel_sub_badges['mgr_inv_requests'] = (int)$s->fetchColumn();
          } catch (Exception $ignored) {}

          // Pending fuel stock requests
          try {
              $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id=? AND status='Pending'");
              $s->execute([$myStationId]);
              $fuel_sub_badges['mgr_inv_requests'] = ($fuel_sub_badges['mgr_inv_requests'] ?? 0) + (int)$s->fetchColumn();
          } catch (Exception $ignored) {}
      } catch (Exception $e) { /* silent */ }
  }

  // Sub-item descriptions for Fuel Management
  $fuel_sub_desc = [
      'fuel_transactions'   => 'View all staff submissions — validate, approve, reject or adjust',
      'fuel_deliveries'     => 'Validate delivery receipts encoded by staff',
      'fuel_adjustments'    => 'Encode corrections for stock discrepancies (logs old vs new + remarks)',
      'fuel_daily_ops'      => 'Monitor daily pump readings and reconcile liters vs sales',
      'fuel_pump_master'    => 'Encode/update calibration values per pump (weekly variance)',
      // Reports sidebar
      'fuel_vol_sales'      => 'Consolidated liters sold per fuel type',
      'fuel_vol_amount'     => 'Liters + peso totals per fuel type',
      'fuel_variance_report'=> 'Short/over analysis — discrepancies between sales vs stock',
      'fuel_meter_reading'  => 'Validated meter reading table after Manager approval',
  ];

  foreach($items as $it){
    // Dashboard — render with role-correct href (hidden for manager, admin, and superadmin)
    if (($it['id'] ?? '') === 'dashboard') {
        if (in_array($role, ['manager','supervisor','admin','superadmin'])) {
            continue; // Dashboard removed from manager, admin, and superadmin sidebar
        }
        $dash_href = '/group31petron_system_official4/public/';
        if (in_array($role, ['staff','cashier','pump_attendant'])) $dash_href .= 'staff_dashboard.php';
        elseif ($role === 'admin') $dash_href .= 'dashboard.php';
        else $dash_href .= 'dashboard.php';
        $dash_active = in_array($page_id, ['dashboard','staff_dashboard','manager_dashboard']) ? 'active' : '';
        echo '<div class="nav-item-wrapper">';
        echo '<a class="nav-item '.$dash_active.'" href="'.htmlspecialchars($dash_href).'" data-tooltip="Dashboard">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;"><i class="fas fa-gauge"></i></span>';
        echo '<span style="flex-grow:1;">Dashboard</span>';
        echo '</a>';
        echo '</div>';
        continue;
    }

    $has_sub = !empty($it['sub_items']);
    $active = '';
    $parent_active = false;

    // Check if main item is active (more specific detection)
    if (!$has_sub && (
        $page_id === ($it['id'] ?? '') ||
        (($it['id'] ?? '') === 'dashboard' && in_array($page_id, ['staff_dashboard','manager_dashboard','dashboard'], true) && !(isset($_GET['view']) && $_GET['view'] === 'job_orders')) ||
        (($it['id'] ?? '') === 'manager_dashboard' && $page_id === 'manager_dashboard') ||
        (($it['id'] ?? '') === 'job_orders' && $page_id === 'manager_dashboard' && isset($_GET['view']) && $_GET['view'] === 'job_orders')
    )) {
        $active = 'active';
    }
    
    // Prevent manager inventory sub-items from activating other menu items
    if (in_array($page_id, ['mgr_inv_fuel', 'mgr_inv_merch', 'mgr_inv_requests', 'pm_fuel', 'pm_merchandise']) && !$has_sub) {
        $active = ''; // Don't highlight other menu items when on manager inventory/product sub-pages
    }

    // Check if any sub-item is active (for auto-expand only, NOT for parent highlight)
    if ($has_sub) {
        foreach ($it['sub_items'] as $sub) {
            $sub_fragment = ltrim(parse_url($sub['href'], PHP_URL_FRAGMENT) ?? '', '#');
            $sub_query    = parse_url($sub['href'], PHP_URL_QUERY) ?? '';
            $sub_file     = basename(parse_url($sub['href'], PHP_URL_PATH) ?? '');

            // page_id exact match
            if ($page_id === ($sub['id'] ?? '')) {
                $parent_active = true;
                break;
            }
            // Hash-based match
            if ($sub_fragment !== '' && $current_hash === $sub_fragment) {
                $parent_active = true;
                break;
            }
            // Query-param match — most specific: file + all query params must match
            if ($sub_file !== '' && $current_url === $sub_file && $sub_query !== '') {
                parse_str($sub_query, $sub_params);
                $match = true;
                foreach ($sub_params as $k => $v) {
                    if (($k === 'section' ? ($_GET['section'] ?? '') : ($_GET[$k] ?? '')) !== $v) {
                        $match = false; break;
                    }
                }
                if ($match) { $parent_active = true; break; }
            }
            // Direct file match ONLY when sub-item has no query string and no fragment
            // (avoids false matches when multiple parents share the same file)
            if ($sub_fragment === '' && $sub_query === '' && $sub_file !== '' && $current_url === $sub_file) {
                $parent_active = true;
                break;
            }
        }
        // Special case for fuel management page
        if ($page_id === 'manager_fuel_management' && ($it['id'] ?? '') === 'fuel') $parent_active = true;
    }

    echo '<div class="nav-item-wrapper">';

    if ($has_sub) {
        // Parent item — toggles sub-menu (don't highlight parent red for Manager Inventory)
        $expanded = $parent_active ? 'expanded' : '';
        // Don't highlight parent red for these items — only their sub-items should be red
        $should_highlight_parent = $parent_active && !in_array(($it['id'] ?? ''), ['inventory_manager', 'job_orders', 'product_management_main', 'transactions', 'fuel', 'inventory', 'customers', 'mgr_customers', 'reports']);
        $parent_cls = $should_highlight_parent ? 'nav-item active' : 'nav-item';
        echo '<a class="'.$parent_cls.' has-submenu" href="'.htmlspecialchars($it['href']).'" data-tooltip="'.htmlspecialchars($it['label']).'" onclick="toggleSidebarSub(event,\'sub-'.htmlspecialchars($it['id']).'\')">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;"><i class="'.htmlspecialchars($it['ico']).'"></i></span>';
        echo '<span style="flex-grow:1;">'.htmlspecialchars($it['label']).'</span>';
        // Parent badge = sum of sub badges
        $parent_badge = 0;
        foreach ($it['sub_items'] as $sub) {
            $parent_badge += $fuel_sub_badges[$sub['id'] ?? ''] ?? 0;
        }
        if ($parent_badge > 0) {
            echo '<span style="background:#E30613;color:white;padding:0 6px;border-radius:10px;font-size:11px;font-weight:bold;min-width:20px;height:20px;display:flex;align-items:center;justify-content:center;margin-right:6px;">'.$parent_badge.'</span>';
        }
        echo '<i class="fas fa-chevron-down" style="font-size:10px;transition:transform .3s;'.($parent_active?'transform:rotate(180deg)':'').'"></i>';
        echo '</a>';

        // Sub-menu
        $display = $parent_active ? 'block' : 'none';
        echo '<div id="sub-'.htmlspecialchars($it['id']).'" style="display:'.$display.';background:rgba(0,0,0,.15);border-left:3px solid rgba(255,255,255,.2);margin-left:0;padding-left:0;">';
        foreach ($it['sub_items'] as $sub) {
            // Active if hash matches this sub-item's fragment OR if current page matches the sub-item href
            $sub_fragment = ltrim(parse_url($sub['href'], PHP_URL_FRAGMENT) ?? '', '#');
            $sub_query    = parse_url($sub['href'], PHP_URL_QUERY) ?? '';
            $sub_file     = basename(parse_url($sub['href'], PHP_URL_PATH) ?? '');
            $sub_active   = '';

            // Hash-based navigation (Staff Inventory) — only match if hash is non-empty
            if ($sub_fragment !== '' && $current_hash === $sub_fragment) {
                $sub_active = 'active';
            }
            // page_id match (Product Management sub-items with ?tab= params)
            elseif ($page_id === ($sub['id'] ?? '')) {
                $sub_active = 'active';
            }
            // Query-param match — e.g. ?section=fuel on staff_transactions_hub.php
            elseif ($sub_file !== '' && $current_url === $sub_file && $sub_query !== '') {
                parse_str($sub_query, $sub_params);
                $match = true;
                foreach ($sub_params as $k => $v) {
                    if (($k === 'section' ? ($_GET['section'] ?? '') : ($_GET[$k] ?? '')) !== $v) {
                        $match = false;
                        break;
                    }
                }
                if ($match) $sub_active = 'active';
            }
            // Direct file navigation (Manager Inventory) — exact filename match only
            // Skip if any sibling sub-item has a query string that matches the current URL's query params
            // (prevents both "Pending Transactions" and "Validated Transactions" lighting up simultaneously)
            elseif ($sub_fragment === '' && $sub_query === '' && $current_url !== '' && $current_url === $sub_file) {
                // Check if any sibling sub-item with a query string matches the current request
                $sibling_matches = false;
                foreach (($it['sub_items'] ?? []) as $sibling) {
                    if (($sibling['id'] ?? '') === ($sub['id'] ?? '')) continue; // skip self
                    $sib_query = parse_url($sibling['href'], PHP_URL_QUERY) ?? '';
                    $sib_file  = basename(parse_url($sibling['href'], PHP_URL_PATH) ?? '');
                    if ($sib_file === $sub_file && $sib_query !== '') {
                        parse_str($sib_query, $sib_params);
                        $sib_match = true;
                        foreach ($sib_params as $k => $v) {
                            if (($_GET[$k] ?? '') !== $v) { $sib_match = false; break; }
                        }
                        if ($sib_match) { $sibling_matches = true; break; }
                    }
                }
                if (!$sibling_matches) {
                    $sub_active = 'active';
                }
            }
            $sub_badge    = $fuel_sub_badges[$sub['id'] ?? ''] ?? 0;
            $sub_desc     = $fuel_sub_desc[$sub['id'] ?? ''] ?? '';

            echo '<a class="nav-item sidebar-sub-item '.$sub_active.'" href="'.htmlspecialchars($sub['href']).'" style="padding:8px 15px 8px 47px;min-height:auto;" data-tooltip="'.htmlspecialchars($sub['label'] ?? '').'" data-tab="'.htmlspecialchars($sub_fragment).'">';
            echo '<span class="ico" style="margin-right:8px;width:14px;text-align:center;flex-shrink:0;"><i class="fas fa-circle" style="font-size:4px;opacity:.5;"></i></span>';
            echo '<span style="flex-grow:1;line-height:1.3;">';
            echo '<span style="display:block;font-size:12px;font-weight:500;">'.htmlspecialchars($sub['label'] ?? '').'</span>';
            echo '</span>';
            if ($sub_badge > 0) {
                echo '<span style="background:#E30613;color:white;padding:0 5px;border-radius:10px;font-size:10px;font-weight:bold;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'.$sub_badge.'</span>';
            }
            echo '</a>';
        }
        echo '</div>';

    } else {
        // Regular item — direct link
        echo '<a class="nav-item '.$active.'" href="'.htmlspecialchars($it['href']).'" data-tooltip="'.htmlspecialchars($it['label']).'">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;"><i class="'.htmlspecialchars($it['ico']).'"></i></span>';
        echo '<span style="flex-grow:1;">'.htmlspecialchars($it['label']).'</span>';
        if (isset($badges[$it['id']]) && $badges[$it['id']] > 0) {
            echo '<span style="background:#E30613;color:white;padding:0 6px;border-radius:10px;font-size:11px;font-weight:bold;min-width:20px;height:20px;display:flex;align-items:center;justify-content:center;margin-left:10px;">'.$badges[$it['id']].'</span>';
        }
        echo '</a>';
    }

    echo '</div>'; // end wrapper
  }
  ?>
      </nav>
    </div>

    <!-- ── Sidebar Identity Footer ── -->
    <?php
    $sid_first = trim($user['first_name'] ?? '');
    $sid_last  = trim($user['last_name']  ?? '');
    if ($sid_first !== '' || $sid_last !== '') {
        $sid_name = strtoupper(trim("$sid_first $sid_last"));
    } else {
        $sid_name = strtoupper($user['username'] ?? 'USER');
    }
    $sid_role = strtoupper(normalize_role($user['role'] ?? 'Staff'));
    ?>
    <div class="sidebar-identity-footer" title="<?php echo htmlspecialchars("$sid_name – $sid_role"); ?>">
        <div class="sif-avatar">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($app_base_path . '/' . ltrim($user['profile_picture'], '/')); ?>"
                     alt="<?php echo htmlspecialchars($sid_name); ?>">
            <?php else: ?>
                <i class="fas fa-user-circle"></i>
            <?php endif; ?>
        </div>
        <span class="sif-name"><?php echo htmlspecialchars($sid_name); ?></span>
        <div class="sif-role-line">
            <span class="sif-dash">–</span>
            <span class="sif-role"><?php echo htmlspecialchars($sid_role); ?></span>
        </div>
    </div>

  </aside>

  <main class="main">

    <!-- GLOBAL TOP HEADER -->
    <header class="top-header">
        <div class="header-left">
            <img src="../assets/img/Petron Logo.png" alt="Petron Logo" class="brand-mark" id="petronLogo">
            <div class="brand-text">
                <div class="brand-title">Petron Station Management System</div>
                <?php if ($station_name && $role !== 'superadmin'): ?>
                <div style="font-size: 14px; color: #666; margin-top: 2px; font-weight: 500;">
                    <i class="fas fa-building" style="font-size: 12px;"></i> <?php echo htmlspecialchars($station_name); ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="display: none; flex-direction: column; margin-left: 20px;"> <!-- Hidden in left block, moved logic if needed or kept simple -->
                <div style="font-size: 1.1em; font-weight: bold; color: var(--petron-blue);">
                    <?php
                    if ($role === 'superadmin') {
                        echo 'Super Admin Console';
                    } elseif ($role === 'admin') {
                        echo 'Admin Console';
                    } else {
                        echo 'Staff Console';
                    }
                    ?>
                </div>
                <div id="live-clock" style="font-size: 0.85em; color: #666; font-weight: 500;">
                    <i class="far fa-clock"></i> Loading time...
                </div>
            </div>
        </div>
        <div class="header-center">
            <!-- Global Search Bar -->
            <?php if(in_array($role, ['superadmin', 'admin', 'manager', 'staff'])): ?>
            <div style="position:relative;" id="searchWrapper">
                <form method="get" action="search.php" style="margin:0;display:flex;" autocomplete="off">
                    <input type="text" id="searchInput" name="q"
                           placeholder="<?php
                               if ($role === 'superadmin') echo 'Search Stations / Admins / Reports…';
                               elseif ($role === 'admin')   echo 'Search Transactions / Customers / Inventory…';
                               elseif ($role === 'manager') echo 'Search Transactions / Job Orders / Fuel / Products…';
                               else echo 'Search Transactions / Customers / Products…';
                           ?>"
                           style="padding:8px 15px;border-radius:25px 0 0 25px;border:2px solid rgba(0,47,112,0.2);border-right:none;font-size:14px;width:300px;background:rgba(255,255,255,0.9);transition:all 0.3s ease;outline:none;">
                    <button type="submit"
                            style="padding:8px 15px;border-radius:0 25px 25px 0;border:2px solid rgba(0,47,112,0.2);border-left:none;background:var(--petron-blue);color:white;font-size:14px;cursor:pointer;transition:all 0.3s ease;">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
                <!-- Autocomplete suggestions dropdown -->
                <div id="searchSuggestions"
                     style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.14);border:1px solid #e2e8f0;z-index:9999;overflow:hidden;max-height:420px;overflow-y:auto;">
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="header-right">
            <!-- Notification Bell -->
            <?php if(in_array($role, ['staff','admin','manager','superadmin'])): ?>
            <div class="notification-bell" id="notificationBell">
                <i class="fas fa-bell"></i>
                <span class="badge" id="notificationBadge" style="display: none;">0</span>

                <div class="notif-dropdown" id="notificationDropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                        <div class="notif-header-actions">
                            <button id="markAllReadBtn">Mark All Read</button>
                            <button id="refreshNotificationsBtn"><i class="fas fa-sync"></i></button>
                        </div>
                    </div>
                    <div class="notif-list" id="notificationList" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                        <div class="notif-loading" style="text-align: center; padding: 20px; color: #888;">
                            <i class="fas fa-spinner fa-spin"></i> Loading notifications...
                        </div>
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="<?php echo htmlspecialchars($public_base_url . '/notifications.php'); ?>" style="display:block; text-align:center; padding:8px; font-size:12px; color:var(--petron-blue); text-decoration:none; border-top:1px solid #eee;">
                            View All Notifications
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Profile Dropdown -->
            <?php
            // Build display name from first_name and last_name
            $hdr_first = trim($user['first_name'] ?? '');
            $hdr_last  = trim($user['last_name']  ?? '');
            if ($hdr_first !== '' || $hdr_last !== '') {
                $hdr_display = strtoupper(trim("$hdr_first $hdr_last"));
            } else {
                $hdr_display = strtoupper($user['username'] ?? 'USER');
            }
            $hdr_role = strtoupper(normalize_role($user['role'] ?? 'Staff'));
            // Profile picture: stored in users.profile_picture as relative path
            $hdr_pic = !empty($user['profile_picture']) ? $app_base_path . '/' . ltrim($user['profile_picture'], '/') : '';
            ?>
            <div class="profile-access" id="profileMenu">
                <?php if ($hdr_pic): ?>
                <img src="<?php echo htmlspecialchars($hdr_pic); ?>" alt="Profile"
                     style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--petron-blue);flex-shrink:0;">
                <?php else: ?>
                <div style="width:34px;height:34px;border-radius:50%;background:var(--petron-blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-user" style="color:#fff;font-size:15px;"></i>
                </div>
                <?php endif; ?>
                <div style="text-align: right; margin-left: 8px;">
                    <div style="font-weight: 700; font-size: 13px; color: var(--petron-blue); letter-spacing: 0.3px;">
                        <?php echo htmlspecialchars($hdr_display); ?>
                    </div>
                    <div style="font-size: 11px; color: #888; margin-top: 1px; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($hdr_role); ?>
                    </div>
                </div>
                <i class="fas fa-caret-down" style="font-size:0.8em; color:#888; margin-left: 5px;"></i>

                <div class="profile-dropdown" id="profileDropdown">
                    <a href="<?php echo htmlspecialchars($public_base_url . '/profile.php'); ?>">
                        <i class="fas fa-user-circle" style="margin-right:8px;color:var(--petron-blue);"></i>View Profile
                    </a>
                    <a href="<?php echo htmlspecialchars($public_base_url . '/update_password.php'); ?>">
                        <i class="fas fa-key" style="margin-right:8px;color:var(--petron-blue);"></i>Change Password
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo htmlspecialchars($public_base_url . '/logout.php'); ?>" class="logout">
                        <i class="fas fa-sign-out-alt" style="margin-right:8px;color:#cc0000;"></i>Log Out
                    </a>
                </div>
            </div>
        </div>
</header>

    <!-- ══ GLOBAL FLASH MESSAGE STYLES ══════════════════════════════════════ -->
    <style>
    /* ── Petron system-wide flash alerts ── */
    .petron-flash {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 13px 18px;
        border-radius: 8px;
        margin: 14px 0 6px 0;
        font-size: 14px;
        font-weight: 500;
        line-height: 1.5;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        animation: flashSlideIn 0.3s ease;
        position: relative;
    }
    @keyframes flashSlideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .petron-flash.flash-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #b8dfc4;
        border-left: 5px solid #28a745;
    }
    .petron-flash.flash-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f1b0b7;
        border-left: 5px solid #dc3545;
    }
    .petron-flash.flash-warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffe08a;
        border-left: 5px solid #ffc107;
    }
    .petron-flash.flash-info {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #a8d9e3;
        border-left: 5px solid #17a2b8;
    }
    .petron-flash i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .petron-flash .flash-close {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: inherit; opacity: 0.5; font-size: 16px; padding: 0 4px;
        line-height: 1;
    }
    .petron-flash .flash-close:hover { opacity: 1; }

    /* ── JS-powered toast (bottom-right, for AJAX actions) ── */
    #petron-toast-container {
        position: fixed;
        bottom: 60px;
        right: 24px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
    }
    .petron-toast {
        min-width: 280px;
        max-width: 420px;
        padding: 13px 18px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        line-height: 1.45;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.18);
        pointer-events: auto;
        animation: toastIn 0.3s ease;
        transition: opacity 0.35s ease, transform 0.35s ease;
    }
    .petron-toast.toast-success { background:#28a745; color:#fff; }
    .petron-toast.toast-error   { background:#dc3545; color:#fff; }
    .petron-toast.toast-warning { background:#ffc107; color:#333; }
    .petron-toast.toast-info    { background:#17a2b8; color:#fff; }
    .petron-toast.toast-hide    { opacity:0; transform:translateX(30px); }
    @keyframes toastIn {
        from { opacity:0; transform:translateX(30px); }
        to   { opacity:1; transform:translateX(0); }
    }
    .petron-toast i { font-size: 16px; flex-shrink: 0; margin-top: 2px; }
    </style>

    <!-- ══ GLOBAL FLASH MESSAGE RENDERER (PHP SESSION → HTML) ════════════════ -->
    <?php
    /* Icons + classes map */
    $__flash_map = [
        'success' => ['cls'=>'flash-success', 'ico'=>'fa-check-circle'],
        'error'   => ['cls'=>'flash-error',   'ico'=>'fa-exclamation-circle'],
        'warning' => ['cls'=>'flash-warning', 'ico'=>'fa-exclamation-triangle'],
        'info'    => ['cls'=>'flash-info',    'ico'=>'fa-info-circle'],
    ];
    foreach ($__flash_map as $__ftype => $__fmeta):
        $__fkey = ($__ftype === 'error') ? 'error' : $__ftype;
        /* support both $_SESSION['success'] and $_SESSION['flash_success'] patterns */
        $__fmsg = $_SESSION[$__fkey] ?? $_SESSION['flash_'.$__fkey] ?? '';
        if ($__fmsg === '') continue;
        unset($_SESSION[$__fkey], $_SESSION['flash_'.$__fkey]);
    ?>
    <div class="petron-flash <?php echo $__fmeta['cls']; ?>" role="alert">
        <i class="fas <?php echo $__fmeta['ico']; ?>"></i>
        <span><?php echo htmlspecialchars((string)$__fmsg, ENT_QUOTES); ?></span>
        <button class="flash-close" onclick="this.parentElement.remove();" title="Dismiss">&times;</button>
    </div>
    <?php endforeach; ?>

    <!-- ══ GLOBAL JS TOAST HELPER ═══════════════════════════════════════════ -->
    <div id="petron-toast-container"></div>
    <script>
    /**
     * showPetronFlash(message, type, duration)
     * Shows a bottom-right toast notification for AJAX-driven actions.
     * type: 'success' | 'error' | 'warning' | 'info'   (default: 'success')
     * duration: ms (default 3500, use 0 for persistent)
     */
    function showPetronFlash(message, type, duration) {
        type     = type     || 'success';
        duration = (duration === undefined) ? 3500 : duration;
        var icons = {
            success: 'fa-check-circle',
            error:   'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info:    'fa-info-circle'
        };
        var container = document.getElementById('petron-toast-container');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'petron-toast toast-' + type;
        toast.innerHTML =
            '<i class="fas ' + (icons[type] || icons.success) + '"></i>' +
            '<span>' + message + '</span>';
        container.appendChild(toast);
        if (duration > 0) {
            setTimeout(function() {
                toast.classList.add('toast-hide');
                setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 400);
            }, duration);
        }
    }

    /* Auto-dismiss page-level flash banners after 6 seconds */
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.petron-flash').forEach(function(el) {
            setTimeout(function() {
                el.style.transition = 'opacity 0.5s ease';
                el.style.opacity = '0';
                setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
            }, 6000);
        });
    });
    </script>

    <!-- Page content starts here -->      
    <script>
        
    function updateClock() {
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        document.getElementById('live-clock').innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Initialize page data for notification system
    window.pageData = {
        role: '<?php echo htmlspecialchars($role); ?>',
        userId: '<?php echo htmlspecialchars($user['id'] ?? ''); ?>',
        stationId: '<?php echo htmlspecialchars($station_id ?? ''); ?>'
    };

    // Simple Dropdown Toggle Logic
    document.addEventListener('DOMContentLoaded', function() {
        // Get elements
        const notifBell = document.getElementById('notificationBell');
        const notifDropdown = document.getElementById('notificationDropdown');
        const profileAccess = document.getElementById('profileMenu');
        const profileDropdown = document.getElementById('profileDropdown');

        // Notification bell click
        if (notifBell && notifDropdown) {
            notifBell.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close profile dropdown if open
                if (profileDropdown) {
                    profileDropdown.classList.remove('show');
                }

                // Toggle notification dropdown
                notifDropdown.classList.toggle('show');
                
                // If we just opened it, load notifications
                if (notifDropdown.classList.contains('show')) {
                    if (typeof window.loadStaffNotifications === 'function') {
                        window.loadStaffNotifications();
                    } else if (typeof window.saLoadNotifications === 'function') {
                        window.saLoadNotifications();
                    }
                }
            });
        }

        // Profile dropdown click
        if (profileAccess && profileDropdown) {
            profileAccess.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close notification dropdown if open
                if (notifDropdown) {
                    notifDropdown.classList.remove('show');
                }
                
                // Toggle profile dropdown
                profileDropdown.classList.toggle('show');
            });
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notificationBell') && !e.target.closest('#notificationDropdown')) {
                if (notifDropdown) notifDropdown.classList.remove('show');
            }
            if (!e.target.closest('#profileMenu') && !e.target.closest('#profileDropdown')) {
                if (profileDropdown) profileDropdown.classList.remove('show');
            }
        });

        // Prevent closing when clicking inside dropdowns
        if (notifDropdown) {
            notifDropdown.addEventListener('click', function(e) { 
                e.stopPropagation(); 
            });
        }
        if (profileDropdown) {
            profileDropdown.addEventListener('click', function(e) { 
                e.stopPropagation(); 
            });
        }

        // ── Sidebar Sub-menu Toggle ──
        // NOTE: defined inside DOMContentLoaded — moved to global below

        // ── Highlight active sub-item based on URL hash ──
        (function() {
            const hash = window.location.hash;
            if (!hash) return;
            // Only add active to exact hash matches, not partial matches
            document.querySelectorAll('.nav-item[href$="' + hash + '"]').forEach(function(el) {
                el.classList.add('active');
                // Ensure parent sub-menu is open
                const parent = el.closest('[id^="sub-"]');
                if (parent) parent.style.display = 'block';
            });
        })();

        // Sidebar Collapse Functionality
        const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const mainSidebar = document.getElementById('mainSidebar');
        
        if (sidebarCollapseBtn && sidebarToggleIcon && mainSidebar) {
            // Load saved state from localStorage
            const savedState = localStorage.getItem('sidebarState');
            const mainContent = document.querySelector('.main');
            
            if (savedState === 'collapsed') {
                mainSidebar.classList.add('collapsed');
                sidebarToggleIcon.className = 'fas fa-chevron-right';
                
                if (mainContent) {
                    mainContent.style.left = '70px';
                    mainContent.style.marginLeft = '';
                    mainContent.classList.add('sidebar-collapsed');
                    document.body.classList.add('sidebar-collapsed');
                    document.body.classList.remove('sidebar-expanded');
                }
            } else {
                sidebarToggleIcon.className = 'fas fa-bars';
                
                if (mainContent) {
                    mainContent.style.left = '250px';
                    mainContent.style.marginLeft = '';
                    mainContent.classList.remove('sidebar-collapsed');
                    document.body.classList.add('sidebar-expanded');
                    document.body.classList.remove('sidebar-collapsed');
                }
            }
            
            // Function to toggle sidebar collapse
            function toggleSidebar() {
                const isCollapsed = mainSidebar.classList.contains('collapsed');
                const mainContent = document.querySelector('.main');
                
                if (isCollapsed) {
                    // Expand sidebar
                    mainSidebar.classList.remove('collapsed');
                    sidebarToggleIcon.className = 'fas fa-bars';
                    localStorage.setItem('sidebarState', 'expanded');
                    
                    if (mainContent) {
                        mainContent.style.left = '250px';
                        mainContent.style.marginLeft = '';
                        mainContent.classList.remove('sidebar-collapsed');
                        document.body.classList.add('sidebar-expanded');
                        document.body.classList.remove('sidebar-collapsed');
                    }
                } else {
                    // Collapse sidebar
                    mainSidebar.classList.add('collapsed');
                    sidebarToggleIcon.className = 'fas fa-chevron-right';
                    localStorage.setItem('sidebarState', 'collapsed');
                    
                    if (mainContent) {
                        mainContent.style.left = '70px';
                        mainContent.style.marginLeft = '';
                        mainContent.classList.add('sidebar-collapsed');
                        document.body.classList.add('sidebar-collapsed');
                        document.body.classList.remove('sidebar-expanded');
                    }
                }
            }
            
            // Add click event listener
            sidebarCollapseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar();
            });
            
            // Update tooltip based on state
            function updateTooltip() {
                const isCollapsed = mainSidebar.classList.contains('collapsed');
                sidebarCollapseBtn.title = isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar';
            }
            
            // Initialize tooltip
            updateTooltip();
            
            // Update tooltip when sidebar state changes
            const observer = new MutationObserver(updateTooltip);
            observer.observe(mainSidebar, { attributes: true, attributeFilter: ['class'] });
        }

            });

                
                
        
        // ── Global Search Autocomplete ────────────────────────────────────────
        (function () {
            const searchInput       = document.getElementById('searchInput');
            const searchSuggestions = document.getElementById('searchSuggestions');
            if (!searchInput || !searchSuggestions) return;

            // Icon + colour per result type (mirrors search.php $ICONS / $COLORS)
            const TYPE_META = {
                'Transaction' : { icon: 'fas fa-shopping-cart', color: '#3b82f6' },
                'Customer'    : { icon: 'fas fa-user',           color: '#10b981' },
                'Product'     : { icon: 'fas fa-box',            color: '#f59e0b' },
                'Job Order'   : { icon: 'fas fa-wrench',         color: '#8b5cf6' },
                'Delivery'    : { icon: 'fas fa-truck',          color: '#ef4444' },
                'Calendar'    : { icon: 'fas fa-calendar-alt',   color: '#06b6d4' },
                'Report'      : { icon: 'fas fa-chart-bar',      color: '#64748b' },
            };

            let debounceTimer;

            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const query = this.value.trim();
                if (query.length < 2) {
                    searchSuggestions.style.display = 'none';
                    return;
                }
                // Show loading state
                searchSuggestions.innerHTML =
                    '<div style="padding:14px 16px;color:#94a3b8;font-size:13px;text-align:center;">' +
                    '<i class="fas fa-spinner fa-spin"></i> Searching…</div>';
                searchSuggestions.style.display = 'block';

                debounceTimer = setTimeout(() => {
                    fetch('search.php?q=' + encodeURIComponent(query) + '&ajax=1')
                        .then(r => r.json())
                        .then(data => {
                            searchSuggestions.innerHTML = '';
                            if (!data || data.length === 0) {
                                searchSuggestions.innerHTML =
                                    '<div style="padding:20px 16px;color:#94a3b8;font-size:13px;text-align:center;">' +
                                    '<i class="fas fa-inbox" style="display:block;font-size:22px;margin-bottom:6px;"></i>' +
                                    'No results found.</div>';
                                return;
                            }

                            // Group by type
                            const grouped = {};
                            data.forEach(item => {
                                if (!grouped[item.type]) grouped[item.type] = [];
                                grouped[item.type].push(item);
                            });

                            Object.keys(grouped).forEach(type => {
                                const meta  = TYPE_META[type] || { icon: 'fas fa-circle', color: '#64748b' };
                                const color = meta.color;

                                // Group header
                                const hdr = document.createElement('div');
                                hdr.style.cssText =
                                    'padding:6px 14px 4px;font-size:11px;font-weight:700;text-transform:uppercase;' +
                                    'letter-spacing:.6px;color:#94a3b8;background:#f8fafc;' +
                                    'border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:6px;';
                                hdr.innerHTML =
                                    `<i class="${meta.icon}" style="color:${color};font-size:10px;"></i>${type}s`;
                                searchSuggestions.appendChild(hdr);

                                grouped[type].forEach(item => {
                                    const row = document.createElement('a');
                                    row.href = item.link || '#';
                                    row.style.cssText =
                                        'display:flex;align-items:center;gap:10px;padding:9px 14px;' +
                                        'text-decoration:none;color:inherit;border-bottom:1px solid #f8fafc;' +
                                        'transition:background .12s;';
                                    row.onmouseenter = () => row.style.background = '#f8fafc';
                                    row.onmouseleave = () => row.style.background = '';
                                    row.innerHTML =
                                        `<div style="width:30px;height:30px;border-radius:8px;background:${color}18;` +
                                        `display:flex;align-items:center;justify-content:center;flex-shrink:0;">` +
                                        `<i class="${meta.icon}" style="color:${color};font-size:12px;"></i></div>` +
                                        `<div style="flex:1;min-width:0;">` +
                                        `<div style="font-size:13px;font-weight:600;color:#1e293b;` +
                                        `white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${item.title}</div>` +
                                        `<div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;` +
                                        `text-overflow:ellipsis;">${item.subtitle}</div></div>` +
                                        `<i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:10px;flex-shrink:0;"></i>`;
                                    searchSuggestions.appendChild(row);
                                });
                            });

                            // "View all results" footer
                            const footer = document.createElement('a');
                            footer.href = 'search.php?q=' + encodeURIComponent(query);
                            footer.style.cssText =
                                'display:block;padding:10px 14px;text-align:center;font-size:12px;' +
                                'font-weight:600;color:#002F6C;text-decoration:none;' +
                                'border-top:1px solid #e2e8f0;background:#f8fafc;';
                            footer.innerHTML = '<i class="fas fa-search" style="margin-right:5px;"></i>View all results';
                            searchSuggestions.appendChild(footer);

                            searchSuggestions.style.display = 'block';
                        })
                        .catch(() => {
                            searchSuggestions.style.display = 'none';
                        });
                }, 280);
            });

            // Keyboard: Escape closes dropdown
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    searchSuggestions.style.display = 'none';
                    this.blur();
                }
            });

            // Hide on outside click
            document.addEventListener('click', function (e) {
                const wrapper = document.getElementById('searchWrapper');
                if (wrapper && !wrapper.contains(e.target)) {
                    searchSuggestions.style.display = 'none';
                }
            });
        })();

        // ── Notification System ───────────────────────────────────────────────────
        // SuperAdmin/Developer: real system-level alerts from DB via AJAX.
        // Manager: deeper approval/oversight notifications.
        // Staff/Admin: operational notifications.
        <?php
        $is_superadmin_role = in_array($role, ['superadmin', 'developer']);
        $is_admin_role      = ($role === 'admin');
        $is_manager_role    = ($role === 'manager');
        $notif_generator    = $is_superadmin_role
            ? '../backend/api/superadmin_notification_generator.php'
            : ($is_admin_role
                ? '../backend/api/admin_notifications_seeder.php?action=seed'
                : ($is_manager_role
                    ? '../backend/api/manager_notification_generator.php'
                    : '../backend/api/staff_notification_generator.php'));
        ?>

        <?php if ($is_superadmin_role): ?>
        // ── SuperAdmin: Real DB-driven notifications ──────────────────────────
        (function () {
            'use strict';

            const API_LIST = '../backend/api/notifications_api.php';
            const API_GEN  = '../backend/api/superadmin_notification_generator.php';

            // Severity → colour mapping
            const SEV_COLOR = {
                critical : '#dc3545',
                high     : '#fd7e14',
                medium   : '#ffc107',
                low      : '#28a745',
                info     : '#0d6efd'
            };
            const TYPE_ICON = {
                failed_login          : 'fas fa-shield-alt',
                unauthorized_access   : 'fas fa-user-slash',
                account_lockout       : 'fas fa-user-lock',
                system_error          : 'fas fa-exclamation-triangle',
                database_error        : 'fas fa-database',
                mass_delete           : 'fas fa-trash-alt',
                export_logs           : 'fas fa-file-export',
                pos_import_failure    : 'fas fa-file-import',
                integration_change    : 'fas fa-plug',
                security_report       : 'fas fa-shield-alt',
                config_change         : 'fas fa-sliders-h',
                settings_change       : 'fas fa-cog',
                unauthorized_settings : 'fas fa-ban',
                general               : 'fas fa-info-circle'
            };

            function timeAgo(ts) {
                const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
                if (diff < 60)   return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
                return Math.floor(diff / 86400) + ' days ago';
            }

            function renderNotifications(list) {
                const el = document.getElementById('notificationList');
                if (!el) return;
                if (!list || list.length === 0) {
                    el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#28a745;"></i>No new system alerts.</div>';
                    return;
                }
                el.innerHTML = list.map(n => {
                    const icon  = TYPE_ICON[n.event_type] || TYPE_ICON.general;
                    const color = SEV_COLOR[n.severity]   || SEV_COLOR.info;
                    const url   = n.redirect_url ? n.redirect_url : '#';
                    const unread = n.status === 'unread';
                    return `<div class="sa-notif-item${unread ? ' unread' : ''}"
                                 style="padding:12px 14px;border-bottom:1px solid #f0f0f0;cursor:pointer;background:${unread ? '#fff9f0' : '#fff'};transition:background .15s;"
                                 onclick="saMarkRead(${n.id}, '${url}')"
                                 onmouseenter="this.style.background='#f8fafc'"
                                 onmouseleave="this.style.background='${unread ? '#fff9f0' : '#fff'}'">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <div style="width:34px;height:34px;border-radius:50%;background:${color}22;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">
                                <i class="${icon}" style="color:${color};font-size:14px;"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:${unread ? '700' : '500'};font-size:13px;color:#1a1a1a;line-height:1.3;margin-bottom:2px;">${n.title}</div>
                                <div style="font-size:11px;color:#666;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px;" title="${n.message}">${n.message}</div>
                                <div style="font-size:10px;color:#aaa;margin-top:3px;display:flex;align-items:center;gap:6px;">
                                    <span style="padding:1px 6px;border-radius:10px;background:${color}22;color:${color};font-weight:700;text-transform:uppercase;font-size:9px;">${n.severity}</span>
                                    ${timeAgo(n.created_at)}
                                </div>
                            </div>
                            ${unread ? '<div style="width:8px;height:8px;border-radius:50%;background:#fd7e14;flex-shrink:0;margin-top:6px;"></div>' : ''}
                        </div>
                    </div>`;
                }).join('');
            }

            function updateBadge(count) {
                const badge = document.getElementById('notificationBadge');
                if (!badge) return;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'block';
                    badge.style.background = count > 0 ? '#dc3545' : '#6c757d';
                } else {
                    badge.style.display = 'none';
                }
            }

            async function loadNotifications() {
                const el = document.getElementById('notificationList');
                if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
                try {
                    const res  = await fetch(API_LIST + '?action=list&limit=15&status=all');
                    const data = await res.json();
                    if (data.success) {
                        renderNotifications(data.notifications || []);
                        updateBadge(data.unread_count || 0);
                    }
                } catch (e) {
                    if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#cc0000;font-size:12px;"><i class="fas fa-exclamation-circle"></i> Failed to load notifications.</div>';
                }
            }

            async function fetchUnreadCount() {
                try {
                    const res  = await fetch(API_LIST + '?action=unread_count');
                    const data = await res.json();
                    if (data.success) updateBadge(data.unread_count || 0);
                } catch (e) {}
            }

            async function generateAndRefresh() {
                try { await fetch(API_GEN); } catch (e) {}
                await fetchUnreadCount();
            }

            // Expose mark-read globally so onclick works
            window.saMarkRead = async function(id, url) {
                try {
                    const fd = new FormData();
                    fd.append('notification_id', id);
                    await fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd });
                } catch (e) {}
                if (url && url !== '#') window.location.href = url;
                else loadNotifications();
            };

            // Mark all read button
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    try {
                        const fd = new FormData();
                        await fetch(API_LIST + '?action=mark_all_read', { method: 'POST', body: fd });
                    } catch (e) {}
                    loadNotifications();
                });
            }

            // Refresh button
            const refreshBtn = document.getElementById('refreshNotificationsBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    await generateAndRefresh();
                    loadNotifications();
                });
            }

            // Load on bell open (Expose globally for the toggle listener)
            window.saLoadNotifications = loadNotifications;

            // On page load: generate new alerts then get count
            generateAndRefresh();

            // Poll every 60 seconds for new alerts
            setInterval(generateAndRefresh, 60000);
        })();

        <?php else: ?>
        // ── Staff / Manager / Admin: Dynamic DB-driven notifications ─────────
        (function () {
            'use strict';

            const API_LIST = '../backend/api/notifications_api.php';
            const API_GEN  = '<?php echo $notif_generator; ?>';

            // Inject styles for hover effects
            if (!document.getElementById('notifStyles')) {
                const style = document.createElement('style');
                style.id = 'notifStyles';
                style.innerHTML = `
                    .notif-item { background: #fff; transition: background 0.2s; border-radius: 8px; margin: 0 8px; }
                    .notif-item:hover { background: #f0f2f5; }
                    .notif-item.unread { background: #eef3f8; }
                    .notif-item.unread:hover { background: #e4ebf3; }
                    #notificationList { padding-bottom: 8px; padding-top: 8px; }
                `;
                document.head.appendChild(style);
            }

            // Event type → icon mapping
            const EVT_ICON = {
                transaction     : 'fas fa-shopping-cart',
                job_order       : 'fas fa-wrench',
                fuel_management : 'fas fa-gas-pump',
                inventory       : 'fas fa-warehouse',
                customer        : 'fas fa-user',
                delivery        : 'fas fa-truck',
                calendar        : 'fas fa-calendar-alt',
                report          : 'fas fa-chart-bar',
                general         : 'fas fa-bell'
            };

            const TYPE_COLOR = {
                success : '#28a745',
                warning : '#f59e0b',
                error   : '#dc3545',
                info    : '#17a2b8'
            };

            function timeAgo(dateStr) {
                if (!dateStr) return '';
                const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
                if (diff < 60)    return diff + 's ago';
                if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            function updateBadge(count) {
                const badge = document.getElementById('notificationBadge');
                if (!badge) return;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'block';
                } else {
                    badge.style.display = 'none';
                }
            }

            // ── Load & render notifications (fast — no generator wait) ────────
            async function loadNotifications() {
                const el = document.getElementById('notificationList');
                if (!el) return;
                el.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
                try {
                    const ctrl = new AbortController();
                    const tid  = setTimeout(() => ctrl.abort(), 8000); // 8s timeout
                    const res  = await fetch(API_LIST + '?action=list&limit=15&status=all', { signal: ctrl.signal });
                    clearTimeout(tid);
                    const data = await res.json();

                    if (data.success && data.notifications && data.notifications.length > 0) {
                        let html = '';
                        data.notifications.forEach(function (n) {
                            const icon   = EVT_ICON[n.event_type] || EVT_ICON.general;
                            const color  = TYPE_COLOR[n.type]     || '#17a2b8';
                            const unread = n.status === 'unread';
                            const bg     = unread ? 'rgba(0,47,108,0.04)' : 'transparent';
                            const url    = (n.redirect_url || '').replace(/'/g, "\\'");
                            const ago    = n.time_ago || timeAgo(n.created_at);
                            // Facebook-like notification styling
                            const hoverClass = unread ? 'notif-item unread' : 'notif-item';
                            html += `<div class="${hoverClass}" style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none;"
                                          onclick="staffMarkRead(${n.id},'${url}')">
                                        <div style="width:48px;height:48px;border-radius:50%;background:${color}15;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid ${color}30;">
                                            <i class="${icon}" style="color:${color};font-size:20px;"></i>
                                        </div>
                                        <div style="flex:1;min-width:0;line-height:1.3;">
                                            <div style="font-size:14px;color:#050505;margin-bottom:2px;">
                                                <strong style="font-weight:600;">${n.title}</strong>
                                            </div>
                                            <div style="color:#65676B;font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                                ${n.message}
                                            </div>
                                            <div style="color:${unread ? '#002F6C' : '#65676B'};font-size:12px;font-weight:${unread ? '600' : 'normal'};margin-top:4px;">
                                                ${ago}
                                            </div>
                                        </div>
                                        ${unread ? '<div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:20px;"></div>' : ''}
                                    </div>`;
                        });
                        el.innerHTML = html;
                        updateBadge(data.unread_count || 0);
                    } else if (data.success) {
                        el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                        updateBadge(0);
                    } else {
                        el.innerHTML = '<div style="padding:16px;text-align:center;color:#dc3545;font-size:12px;"><i class="fas fa-exclamation-circle"></i> Could not load notifications.</div>';
                    }
                } catch (e) {
                    const el2 = document.getElementById('notificationList');
                    if (el2) el2.innerHTML = '<div style="padding:16px;text-align:center;color:#dc3545;font-size:12px;"><i class="fas fa-exclamation-circle"></i> Could not load notifications.</div>';
                }
            }

            // ── Fetch unread count only (lightweight) ─────────────────────────
            async function fetchUnreadCount() {
                try {
                    const ctrl = new AbortController();
                    const tid  = setTimeout(() => ctrl.abort(), 5000);
                    const res  = await fetch(API_LIST + '?action=unread_count', { signal: ctrl.signal });
                    clearTimeout(tid);
                    const data = await res.json();
                    if (data.success) updateBadge(data.unread_count || 0);
                } catch (e) {}
            }

            // ── Run generator silently in background (fire-and-forget) ────────
            function runGeneratorBackground() {
                fetch(API_GEN, { keepalive: true })
                    .then(r => r.json())
                    .then(d => { if (d.ok && d.generated > 0) fetchUnreadCount(); })
                    .catch(() => {});
            }

            // ── Mark one notification as read ─────────────────────────────────
            window.staffMarkRead = async function (id, url) {
                try {
                    const fd = new FormData();
                    fd.append('notification_id', id);
                    await fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd });
                } catch (e) {}
                if (url && url !== '#' && url !== '') {
                    window.location.href = url;
                } else {
                    loadNotifications();
                }
            };

            // ── Mark all read ─────────────────────────────────────────────────
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    try {
                        await fetch(API_LIST + '?action=mark_all_read', { method: 'POST' });
                    } catch (e) {}
                    loadNotifications();
                });
            }

            // ── Refresh button ────────────────────────────────────────────────
            const refreshBtn = document.getElementById('refreshNotificationsBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    runGeneratorBackground();
                    loadNotifications();
                });
            }

            // Expose globally for the toggle listener
            window.loadStaffNotifications = loadNotifications;

            // ── On page load: fetch count immediately, run generator after 2s ─
            fetchUnreadCount();
            setTimeout(runGeneratorBackground, 2000);

            // ── Poll: count every 60s, generator every 5 min ─────────────────
            setInterval(fetchUnreadCount, 60000);
            setInterval(runGeneratorBackground, 300000);

        })();
        <?php endif; ?>
    </script>

    <script>
    /* ── GLOBAL: Sidebar Sub-menu Toggle ── */
    function toggleSidebarSub(e, subId) {
        e.preventDefault();
        e.stopPropagation();
        
        // If sidebar is collapsed, expand it first
        const mainSidebar = document.getElementById('mainSidebar');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const mainContent = document.querySelector('.main');
        
        if (mainSidebar && mainSidebar.classList.contains('collapsed')) {
            mainSidebar.classList.remove('collapsed');
            if (sidebarToggleIcon) sidebarToggleIcon.className = 'fas fa-bars';
            localStorage.setItem('sidebarState', 'expanded');
            
            if (mainContent) {
                mainContent.style.left = '250px';
                mainContent.style.marginLeft = '';
                mainContent.classList.remove('sidebar-collapsed');
                document.body.classList.add('sidebar-expanded');
                document.body.classList.remove('sidebar-collapsed');
            }
        }

        const sub = document.getElementById(subId);
        if (!sub) return;

        const isOpen = sub.style.display !== 'none';

        // Close all other open sub-menus (accordion — no overlap)
        document.querySelectorAll('[id^="sub-"]').forEach(function(other) {
            if (other.id !== subId) {
                other.style.display = 'none';
                // Reset chevron for closed menus
                const otherLink = document.querySelector('a[onclick*="' + other.id + '"]');
                if (otherLink) {
                    const otherChevron = otherLink.querySelector('.fa-chevron-down');
                    if (otherChevron) otherChevron.style.transform = 'rotate(0deg)';
                    otherLink.classList.remove('sub-open');
                }
            }
        });

        // Toggle the clicked sub-menu
        sub.style.display = isOpen ? 'none' : 'block';

        // Rotate chevron
        const chevron = e.currentTarget.querySelector('.fa-chevron-down');
        if (chevron) {
            chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
            chevron.style.transition = 'transform 0.3s ease';
        }

        // Active state on parent
        e.currentTarget.classList.toggle('sub-open', !isOpen);
    }

    // On page load: clear old localStorage submenu states so nothing auto-opens
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^="sub-"]').forEach(function(sub) {
            localStorage.removeItem('submenu_' + sub.id);
        });
    });
    </script>
    
</body>
</html>
