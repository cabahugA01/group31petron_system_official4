<?php
// Force fresh reload - prevent CSS/HTML caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

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

$header_notifications = [];
$header_unread_count = 0;
$header_time_ago = function($datetime) {
    $ts = strtotime((string)$datetime);
    if (!$ts) return '';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
};
$header_notif_url = function($url) use ($app_base_path, $public_base_url) {
    $url = trim((string)$url);
    if ($url === '' || $url === '#') return '#';
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '/public/') === 0) return $app_base_path . $url;
    if (strpos($url, 'public/') === 0) return $app_base_path . '/' . $url;
    if (preg_match('/^[a-zA-Z0-9_-]+\.php/', $url)) return $public_base_url . '/' . $url;
    return $url;
};
if (in_array($role, ['staff','admin','manager','superadmin','developer'])) {
    try {
        if (function_exists('ensure_notifications_table')) {
            ensure_notifications_table($pdo);
        }
        $hn_stmt = $pdo->prepare(
            "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 15"
        );
        $hn_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_notifications = $hn_stmt->fetchAll(PDO::FETCH_ASSOC);

        $hc_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $hc_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_unread_count = (int)$hc_stmt->fetchColumn();
    } catch (Exception $e) {
        $header_notifications = [];
        $header_unread_count = 0;
    }
}

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
        $expiring_passwords = $pdo->query("SELECT username FROM users WHERE password_expires_at < NOW() AND status = 'Active' LIMIT 5")->fetchAll();
        foreach($expiring_passwords as $ep) $header_alerts[] = ['msg'=>"Password Expired: {$ep['username']}", 'time'=>'Now', 'link'=>'users.php'];
    } catch(Exception $e){}
    // 3. Reconciliation Delays (Super Admin only)
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
        $badges['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Disabled'")->fetchColumn();
        
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
            $badges['reports_admin'] = $pending_tx + $pending_jo_count + ($badges['inventory'] ?? 0);
        } else {
            // Reports Aggregate for manager
            $badges['reports'] = ($badges['pos'] ?? 0) + $pending_jo_count + ($badges['inventory'] ?? 0);
        }
    } elseif ($role === 'staff') {
        // Staff badges are dynamically computed via $__badge_add in the layout section below
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

$header_notif_url = function($url) use ($app_base_path, $public_base_url) {
    $url = trim((string)$url);
    if ($url === '' || $url === '#') return '#';
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '/public/') === 0) return $app_base_path . $url;
    if (strpos($url, 'public/') === 0) return $app_base_path . '/' . $url;
    if (preg_match('/^[a-zA-Z0-9_-]+\.php/', $url)) return $public_base_url . '/' . $url;
    return $url;
};
if (in_array($role, ['staff','admin','manager','superadmin','developer'])) {
    try {
        if (function_exists('ensure_notifications_table')) {
            ensure_notifications_table($pdo);
        }
        $hn_stmt = $pdo->prepare(
            "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 15"
        );
        $hn_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_notifications = $hn_stmt->fetchAll(PDO::FETCH_ASSOC);

        $hc_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $hc_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_unread_count = (int)$hc_stmt->fetchColumn();
    } catch (Exception $e) {
        $header_notifications = [];
        $header_unread_count = 0;
    }
}

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
        $expiring_passwords = $pdo->query("SELECT username FROM users WHERE password_expires_at < NOW() AND status = 'Active' LIMIT 5")->fetchAll();
        foreach($expiring_passwords as $ep) $header_alerts[] = ['msg'=>"Password Expired: {$ep['username']}", 'time'=>'Now', 'link'=>'users.php'];
    } catch(Exception $e){}
    // 3. Reconciliation Delays (Super Admin only)
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
        $badges['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Disabled'")->fetchColumn();
        
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
        // Legacy individual badge assignments removed — now handled by the newer
        // $__badge_add() system below (lines ~2900+) to avoid double-counting.
        // No legacy badge keys added here for staff.
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
<?php 
// --- FETCH SYSTEM SETTINGS (GLOBAL & STATION-SPECIFIC) ---
$station_settings = [];
try {
    $stmt0 = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = 0");
    $stmt0->execute();
    while ($row = $stmt0->fetch(PDO::FETCH_ASSOC)) {
        // Strip 'global_' prefix so we just have 'color_primary', 'logo', etc.
        $key = preg_replace('/^global_/', '', $row['setting_key']);
        $station_settings[$key] = $row['setting_value'];
    }
    if ($myStationId > 0) {
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = ?");
        $stmtS->execute([$myStationId]);
        while ($row = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                // Strip 'station_X_' prefix
                $key = preg_replace('/^station_' . $myStationId . '_/', '', $row['setting_key']);
                $station_settings[$key] = $row['setting_value'];
            }
        }
    }
} catch (Exception $e) {}

$theme_primary_color = $station_settings['color_primary'] ?? '#002F6C';
$theme_button_color  = $station_settings['color_button'] ?? '#002F6C';
$theme_sidebar_color = $station_settings['color_sidebar'] ?? '#00264D';
$theme_font_scale    = $station_settings['font_scale'] ?? '100';
$theme_high_contrast = (isset($station_settings['high_contrast']) && ($station_settings['high_contrast'] === '1' || $station_settings['high_contrast'] === 'true'));

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
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/vendor/fontawesome/css/all.min.css">
    <!-- GLOBAL TEXT VISIBILITY FIX - Ensures all text is readable while keeping original colors -->
    <style>
        /* ===== TEXT VISIBILITY FIX - Keep all colors, just fix text contrast ===== */
    
    /* Only fix form labels and inputs - keep everything else as is */
    label:not([style*="color:"]), 
    .form-label:not([style*="color:"]), 
    .field-label:not([style*="color:"]) {
        color: #1e293b !important;
    }
    
    /* Input fields - dark text */
    input[type="text"]:not([readonly]):not([disabled]),
    input[type="email"]:not([readonly]):not([disabled]),
    input[type="password"]:not([readonly]):not([disabled]),
    input[type="number"]:not([readonly]):not([disabled]),
    input[type="tel"]:not([readonly]):not([disabled]),
    input[type="search"]:not([readonly]):not([disabled]),
    select:not([disabled]),
    textarea:not([readonly]):not([disabled]) {
        color: #1e293b !important;
    }
    
    /* Placeholder text */
    input::placeholder,
    textarea::placeholder {
        color: #94a3b8 !important;
        opacity: 1 !important;
    }
  </style>
  <style>
    :root {
        --petron-blue: <?php echo htmlspecialchars($theme_primary_color); ?> !important;
        --primary: <?php echo htmlspecialchars($theme_primary_color); ?> !important;
        --sidebar-bg: <?php echo htmlspecialchars($theme_sidebar_color); ?> !important;
        font-size: <?php echo htmlspecialchars($theme_font_scale); ?>% !important;
    }
    button, .btn, .ss-btn-primary {
        background-color: <?php echo htmlspecialchars($theme_button_color); ?> !important;
    }
    /* Prevent theme button color from bleeding into custom dropdown triggers */
    button.fd-select-trigger,
    button.cdd-trigger {
        background-color: #fff !important;
        color: #374151 !important;
    }
    <?php if ($theme_high_contrast): ?>
    body, html, div, p, span, table, td, th, a, button, input, select {
        filter: contrast(1.15) !important;
    }
    <?php endif; ?>
    
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
        
        /* Light Theme (Default) */
        --bg-main: #f8f9fa;
        --bg-card: #ffffff;
        --text-main: #333333;
        --text-secondary: #666666;
        --border-color: #e0e0e0;
        --sidebar-bg: #00264D;
        --sidebar-text: #ffffff;
        --header-bg: #ffffff;
        --header-text: #00264D;
    }
    
    /* â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• 
       DARK THEME â€” Full proper dark mode
       â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â•  */

    /* Dark theme CSS variable overrides */
    body.dark-theme {
        --bg-main:        #0f1117;
        --bg-card:        #1e2130;
        --bg-card-hover:  #252840;
        --text-main:      #e2e8f0;
        --text-secondary: #94a3b8;
        --border-color:   #2d3748;
        --sidebar-bg:     #111827;
        --sidebar-text:   #cbd5e1;
        --header-bg:      #1a1f2e;
        --header-text:    #e2e8f0;
        --input-bg:       #252840;
        --input-border:   #3d4a5c;
        --input-text:     #e2e8f0;
        --table-header:   #1a2035;
        --table-row-odd:  #1e2130;
        --table-row-even: #22273a;
        --table-hover:    #2a3150;
        --shadow-dark:    0 4px 24px rgba(0,0,0,0.5);
    }

    /* â”€â”€ Body & Main â”€â”€ */
    body.dark-theme {
        background-color: var(--bg-main) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .main {
        background-color: var(--bg-main) !important;
    }

    /* â”€â”€ Top Header â”€â”€ */
    body.dark-theme .top-header {
        background-color: var(--header-bg) !important;
        border-bottom: 1px solid var(--border-color) !important;
        box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
    }
    body.dark-theme .brand-title {
        color: #e2e8f0 !important;
    }
    body.dark-theme .top-header .brand-text div {
        color: #94a3b8 !important;
    }

    /* â”€â”€ Sidebar â”€â”€ */
    body.dark-theme .sidebar {
        background-color: var(--sidebar-bg) !important;
        border-right: 1px solid var(--border-color) !important;
    }
    body.dark-theme .nav-item {
        color: var(--sidebar-text) !important;
    }
    body.dark-theme .nav-item:hover {
        background: rgba(255,255,255,0.06) !important;
        color: #ffffff !important;
    }
    body.dark-theme .nav-item.active {
        background: rgba(204,0,0,0.25) !important;
        color: #ffffff !important;
    }
    body.dark-theme .sidebar-identity-footer {
        background: rgba(0,0,0,0.4) !important;
        border-top: 1px solid rgba(255,255,255,0.08) !important;
    }

    /* â”€â”€ Cards & Panels â”€â”€ */
    body.dark-theme .widget-card,
    body.dark-theme .card,
    body.dark-theme .petron-card,
    body.dark-theme .panel,
    body.dark-theme .pcard,
    body.dark-theme .mini {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
        box-shadow: var(--shadow-dark) !important;
    }
    body.dark-theme .widget-card h1,
    body.dark-theme .widget-card h2,
    body.dark-theme .widget-card h3,
    body.dark-theme .widget-card h4,
    body.dark-theme .widget-card h5,
    body.dark-theme .card h1,
    body.dark-theme .card h2,
    body.dark-theme .card h3,
    body.dark-theme .card h4,
    body.dark-theme .card h5,
    body.dark-theme .petron-card h1,
    body.dark-theme .petron-card h2,
    body.dark-theme .petron-card h3,
    body.dark-theme .petron-card h4 {
        color: #93c5fd !important;
    }
    body.dark-theme .status-card,
    body.dark-theme .metric-card,
    body.dark-theme .quick-action-btn,
    body.dark-theme .report-btn {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }

    /* Card-header sections */
    body.dark-theme .card-header,
    body.dark-theme .petron-card-header {
        background-color: var(--table-header) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .card-body {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
    }

    /* â”€â”€ Tables â”€â”€ */
    body.dark-theme table,
    body.dark-theme .table {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table thead th,
    body.dark-theme .table thead th {
        background-color: var(--table-header) !important;
        color: #93c5fd !important;
        border-color: var(--border-color) !important;
    }
    /* Report-specific table headers visible in dark mode */
    body.dark-theme .sr-table thead th,
    body.dark-theme .sr-tbl thead th,
    body.dark-theme .rpt-table thead th,
    body.dark-theme .pr-tbl thead th {
        background-color: var(--table-header) !important;
        color: #93c5fd !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:nth-child(odd) td,
    body.dark-theme .table tbody tr:nth-child(odd) td {
        background-color: var(--table-row-odd) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:nth-child(even) td,
    body.dark-theme .table tbody tr:nth-child(even) td {
        background-color: var(--table-row-even) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:hover td,
    body.dark-theme .table tbody tr:hover td {
        background-color: var(--table-hover) !important;
    }
    body.dark-theme table td,
    body.dark-theme table th {
        border-color: var(--border-color) !important;
    }

    /* â”€â”€ Inputs, Selects, Textareas â”€â”€ */
    body.dark-theme input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]):not([type="reset"]),
    body.dark-theme select,
    body.dark-theme textarea {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    body.dark-theme input::placeholder,
    body.dark-theme textarea::placeholder {
        color: #64748b !important;
    }
    body.dark-theme select option {
        background-color: #252840;
        color: #e2e8f0;
    }
    /* Rows-per-page select — transparent background, native arrow always visible */
    body.dark-theme select.rows-select,
    select.rows-select {
        background-color: transparent !important;
        border-color: #cbd5e1 !important;
        appearance: auto !important;
        -webkit-appearance: auto !important;
    }
    body.dark-theme .form-control,
    body.dark-theme .form-select {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    body.dark-theme .form-label,
    body.dark-theme label {
        color: var(--text-secondary) !important;
    }

    /* â”€â”€ Buttons â”€â”€ */
    body.dark-theme .btn-light,
    body.dark-theme .btn-outline-secondary,
    body.dark-theme .btn-secondary {
        background-color: #2d3748 !important;
        color: #e2e8f0 !important;
        border-color: #3d4a5c !important;
    }
    body.dark-theme .btn-light:hover,
    body.dark-theme .btn-outline-secondary:hover {
        background-color: #3d4a5c !important;
    }
    /* Keep primary/danger/success buttons their original colors */
    body.dark-theme .btn-primary  { background-color: #2563eb !important; border-color: #1d4ed8 !important; color: #fff !important; }
    body.dark-theme .btn-danger   { background-color: #dc2626 !important; border-color: #b91c1c !important; color: #fff !important; }
    body.dark-theme .btn-success  { background-color: #16a34a !important; border-color: #15803d !important; color: #fff !important; }
    body.dark-theme .btn-warning  { background-color: #d97706 !important; border-color: #b45309 !important; color: #fff !important; }
    body.dark-theme .btn-info     { background-color: #0891b2 !important; border-color: #0e7490 !important; color: #fff !important; }

    /* custom petron buttons â€” keep them as-is or slightly darken */
    body.dark-theme .cust-btn {
        background: #252840 !important;
        color: #e2e8f0 !important;
        border-color: #3d4a5c !important;
    }

    /* â”€â”€ Header icons & controls â”€â”€ */
    body.dark-theme .notification-bell {
        background: rgba(255,255,255,0.07) !important;
    }
    body.dark-theme .notification-bell:hover { background: rgba(255,255,255,0.14) !important; }
    body.dark-theme .notification-bell i,
    body.dark-theme .theme-toggle-btn i { color: #e2e8f0 !important; }
    body.dark-theme .theme-toggle-btn {
        background: rgba(255,255,255,0.07) !important;
    }
    body.dark-theme .theme-toggle-btn:hover { background: rgba(255,255,255,0.14) !important; }

    /* â”€â”€ Profile area in header â”€â”€ */
    body.dark-theme .profile-access { color: var(--text-main) !important; }
    body.dark-theme .profile-access > div > div:first-child { color: #93c5fd !important; }
    body.dark-theme .profile-access > div > div:last-child  { color: #64748b !important; }

    /* â”€â”€ Dropdowns â”€â”€ */
    body.dark-theme .notif-dropdown,
    body.dark-theme .profile-dropdown {
        background-color: #1e2130 !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .notif-dropdown-header {
        background: linear-gradient(135deg, #1a2035 0%, #1e2130 100%) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: #93c5fd !important;
    }
    body.dark-theme .notif-dropdown-header span,
    body.dark-theme .notif-dropdown-header button { color: #93c5fd !important; }
    body.dark-theme .notif-header-actions button:hover { background: rgba(255,255,255,0.08) !important; color: #ffffff !important; }
    body.dark-theme .notif-item {
        border-bottom: 1px solid var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .notif-item:hover { background-color: #252840 !important; }
    body.dark-theme .profile-dropdown a {
        color: var(--text-main) !important;
    }
    body.dark-theme .profile-dropdown a:hover {
        background-color: #252840 !important;
        color: #93c5fd !important;
    }
    body.dark-theme .dropdown-divider {
        border-color: var(--border-color) !important;
    }

    /* â”€â”€ Footer â”€â”€ */
    body.dark-theme .fixed-footer {
        background-color: #1a1f2e !important;
        border-top: 1px solid var(--border-color) !important;
        color: var(--text-secondary) !important;
    }
    body.dark-theme .footer-sidebar-area {
        background-color: #111827 !important;
        color: var(--text-secondary) !important;
     }
    body.dark-theme .footer-text,
    body.dark-theme .footer-clock { color: var(--text-secondary) !important; }
    body.dark-theme .footer-identity { color: #93c5fd !important; }

    /* â”€â”€ Welcome Banner / Page headers â”€â”€ */
    body.dark-theme .welcome-banner {
        background: linear-gradient(135deg, #1e2130 0%, #1a2035 100%) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .welcome-banner h2,
    body.dark-theme .welcome-banner h3,
    body.dark-theme .welcome-banner p { color: var(--text-main) !important; }
    body.dark-theme .page-head h1,
    body.dark-theme .page-header h1,
    body.dark-theme .page-title { color: #93c5fd !important; }
    body.dark-theme .page-subtitle { color: var(--text-secondary) !important; }

    /* â”€â”€ Generic text overrides â”€â”€ */
    body.dark-theme h1, body.dark-theme h2, body.dark-theme h3,
    body.dark-theme h4, body.dark-theme h5, body.dark-theme h6 {
        color: var(--text-main) !important;
    }
    body.dark-theme p, body.dark-theme span, body.dark-theme li,
    body.dark-theme td, body.dark-theme th { color: var(--text-main); }
    body.dark-theme a:not(.btn):not(.nav-item):not(.sidebar-sub-item) { color: #60a5fa !important; }
    body.dark-theme a:not(.btn):not(.nav-item):not(.sidebar-sub-item):hover { color: #93c5fd !important; }

    /* â”€â”€ Flash messages â€” keep original alert colors but on dark bg â”€â”€ */
    body.dark-theme .petron-toast {
        background: #111827 !important;
        border-color: #263449 !important;
        color: #e5e7eb !important;
    }
    body.dark-theme .petron-toast-message { color: #d1d5db !important; }
    body.dark-theme .petron-flash {
        background: #111827 !important;
        border-color: #263449 !important;
        color: #e5e7eb !important;
    }
    body.dark-theme .petron-flash span { color: #d1d5db !important; }

    /* â”€â”€ Modals â”€â”€ */
    body.dark-theme .modal-card,
    body.dark-theme .modal-card-wide,
    body.dark-theme .modal-card-xl {
        background-color: var(--bg-card) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .modal-head {
        background-color: var(--table-header) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: #93c5fd !important;
    }
    body.dark-theme .modal-actions {
        background-color: var(--bg-card) !important;
        border-top: 1px solid var(--border-color) !important;
    }
    body.dark-theme .modal-backdrop,
    body.dark-theme .modal-overlay { background: rgba(0,0,0,0.75) !important; }

    /* â”€â”€ Badges â”€â”€ */
    body.dark-theme .badge:not([style*="background"]) {
        background-color: #252840 !important;
        color: #e2e8f0 !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme .sbadge { opacity: 0.9 !important; }

    /* â”€â”€ Scrollbars in dark mode â”€â”€ */
    body.dark-theme ::-webkit-scrollbar { width: 6px; height: 6px; }
    body.dark-theme ::-webkit-scrollbar-track { background: #0f1117; }
    body.dark-theme ::-webkit-scrollbar-thumb { background: #3d4a5c; border-radius: 3px; }
    body.dark-theme ::-webkit-scrollbar-thumb:hover { background: #4d5a6c; }

    /* â”€â”€ Misc overrides â”€â”€ */
    body.dark-theme hr                      { border-color: var(--border-color) !important; }
    body.dark-theme .text-muted             { color: #64748b !important; }
    body.dark-theme .border                 { border-color: var(--border-color) !important; }
    body.dark-theme .bg-white               { background-color: var(--bg-card) !important; }
    body.dark-theme .bg-light               { background-color: #252840 !important; }
    body.dark-theme .text-dark              { color: var(--text-main) !important; }
    body.dark-theme .shadow, body.dark-theme .shadow-sm { box-shadow: var(--shadow-dark) !important; }
    body.dark-theme .list-group-item {
        background-color: var(--bg-card) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .list-group-item:hover  { background-color: var(--bg-card-hover) !important; }

    /* Page content wrappers */
    body.dark-theme .page-content,
    body.dark-theme .content-wrapper,
    body.dark-theme .section-wrapper,
    body.dark-theme .petron-section {
        background-color: var(--bg-main) !important;
        color: var(--text-main) !important;
    }

    /* Search bar on header in dark mode */
    body.dark-theme #searchInput {
        background: rgba(255,255,255,0.08) !important;
        border-color: rgba(255,255,255,0.15) !important;
        color: #e2e8f0 !important;
    }
    body.dark-theme #searchInput::placeholder { color: #64748b !important; }
    body.dark-theme #searchInput:focus {
        background: rgba(255,255,255,0.12) !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2) !important;
    }
    body.dark-theme #searchSuggestions {
        background: #1e2130 !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6) !important;
        color: var(--text-main) !important;
    }

    /* Toggle scroll button in dark mode */
    body.dark-theme .toggle-scroll-btn {
        background: #2563eb !important;
        border-color: #1e2130 !important;
    }
    
    /* Apply theme variables to elements â€” with smooth transitions */
    body {
        background-color: var(--bg-main);
        color: var(--text-main);
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    .top-header,
    .sidebar,
    .main,
    .fixed-footer,
    .footer-sidebar-area,
    .widget-card, .card, .petron-card {
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    html, body { max-width: 100vw; overflow-x: hidden; } /* Sidebar Navigation */
    /* Base variable-driven colours for all major surfaces */
    .top-header {
        background-color: var(--header-bg);
        color: var(--header-text);
    }
    .sidebar {
        background-color: var(--sidebar-bg) !important;
        color: var(--sidebar-text) !important;
    }
    .main {
        background-color: var(--bg-main);
    }
    .widget-card, .card, .petron-card {
        background-color: var(--bg-card);
        color: var(--text-main);
        border-color: var(--border-color);
    }
    
    /* Desktop Sidebar Layout (Header + Sidebar Integration, Fixed Footer) */
    @media (min-width: 992px) {
        body { 
            overflow: hidden !important;
            pointer-events: auto !important;
        }

        .top-header {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            height: 70px;
            z-index: 12002;
            background-color: var(--header-bg);
            padding: 0; /* Reset padding to handle split bg */
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: auto !important;
        }

        .sidebar {
            display: flex !important;
            flex-direction: column !important;
            position: fixed !important;
            top: 70px !important;
            left: 0 !important;
            bottom: 40px !important; /* height of fixed footer */
            height: calc(100vh - 110px) !important; /* 70px top + 40px bottom = 110px */
            width: 250px !important;
            z-index: 1001 !important;
            overflow: hidden !important;
            background: var(--sidebar-bg) !important;
            border-right: 1px solid var(--line) !important;
            transition: width 0.3s ease !important;
            pointer-events: auto !important;
        }
        .sidebar-menu { 
            flex: 1 1 auto; 
            overflow-y: auto;
            overflow-x: hidden;
            padding-top: 8px; /* removed the 52px padding for hamburger button */
            padding-bottom: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.3) transparent;
            pointer-events: auto !important;
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

        /* â”€â”€ Sidebar Identity Footer â”€â”€ */
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

        /* Avatar circle â€” centered above name */
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

        /* Sub-menus use normal document flow â€” they expand inline, not floating */
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
            padding: 0 24px 60px 24px;
            background: #f8f9fa;
            transition: left 0.3s ease;
            pointer-events: auto !important;
            z-index: 1 !important;
        }
        
        /* Ensure all main content children are clickable */
        .main * {
            pointer-events: auto !important;
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

    /* â”€â”€ Mobile Layout (screens < 992px) â”€â”€ */
    @media (max-width: 991px) {
        body { 
            overflow-x: hidden !important;
            overflow-y: auto !important;
            pointer-events: auto !important;
        }

        /* Top header sticks to top on mobile */
        .top-header {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            height: 60px;
            z-index: 1002;
            background-color: var(--header-bg, #ffffff);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }

        /* Main content pushed down by header height */
        .main {
            position: fixed !important;
            top: 60px !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 0 16px 60px 16px !important;
            background: var(--bg-main, #f8f9fa);
            pointer-events: auto !important;
            z-index: 1 !important;
        }
        
        /* Ensure all main content children are clickable on mobile */
        .main * {
            pointer-events: auto !important;
        }

        /* Sidebar hidden off-screen by default */
        .sidebar {
            position: fixed !important;
            top: 60px !important;
            left: -260px !important;
            bottom: 40px !important; /* height of fixed footer */
            height: calc(100vh - 100px) !important; /* 60px top + 40px bottom = 100px */
            width: 250px !important;
            z-index: 1100 !important;
            overflow-y: auto !important;
            transition: left 0.3s ease !important;
            background: var(--sidebar-bg, #002F70) !important;
            box-shadow: 4px 0 16px rgba(0,0,0,0.25) !important;
        }

        /* Sidebar open state (toggled via JS) */
        .sidebar.mobile-open {
            left: 0 !important;
        }

        /* Backdrop overlay when sidebar is open */
        .mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1099;
        }
        .mobile-sidebar-backdrop.active {
            display: block;
        }

        /* Show hamburger button visibly on mobile */
        .sidebar-collapse-btn {
            display: flex !important;
            width: 36px !important;
            height: 36px !important;
            font-size: 0 !important;
        }
        .sidebar-collapse-btn i {
            font-size: 18px !important;
            color: var(--petron-blue, #002F70) !important;
        }

        /* Hide search bar on very small screens */
        .header-center { display: none !important; }

        /* Shrink brand text on mobile */
        .brand-title { font-size: 0.95em !important; }
        .brand-mark { width: 30px !important; height: 30px !important; }
    }

    .brand-title { color: var(--petron-blue) !important; font-weight: bold; font-size: 1.3em; line-height: 1.1; }
    .brand-mark {
        width: 40px; height: 40px;
        margin-right: 10px;
        object-fit: contain;
    }

    /* Page Header Styles - UPPERCASE for All Roles (Super Admin, Admin, Manager, Staff) */
    .page-head, .page-header {
        margin-top: 0 !important;
        margin-bottom: 24px !important;
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

    .nav-item { color: #eeeeee !important; transition: all 0.2s; display: flex; align-items: center; justify-content: flex-start; padding: 10px 15px; text-decoration: none; min-height: 44px; font-size: 13px !important; font-weight: 500 !important; pointer-events: auto !important; cursor: pointer !important; position: relative !important; z-index: 10 !important; }
    .nav-item:hover { background-color: rgba(255,255,255,0.1) !important; color: #ffffff !important; font-size: 13px !important; font-weight: 500 !important; }
    .nav-item.active { background-color: var(--petron-red) !important; color: #ffffff !important; font-size: 13px !important; font-weight: 500 !important; }
    .nav-item span { font-size: 13px !important; font-weight: 500 !important; }
    .nav-item.active span { font-size: 13px !important; font-weight: 500 !important; }
    .sidebar-sub-item { font-size: 12px !important; font-weight: 500 !important; color: #eeeeee !important; text-decoration: none !important; }
    .sidebar-sub-item span:not(.ico) { white-space: normal !important; word-break: break-word !important; color: #eeeeee !important; text-decoration: none !important; }
    .sidebar-sub-item:hover { background-color: rgba(255,255,255,0.1) !important; color: #ffffff !important; text-decoration: none !important; }
    .sidebar-sub-item.active { background-color: transparent !important; color: #ffffff !important; border-left: 3px solid var(--petron-red); text-decoration: none !important; }


    
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
        width: 380px !important;
        min-width: 380px !important;
        max-width: 380px !important;
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
        color: #002f70 !important;
        background: none !important;
        border: none !important;
        box-shadow: none !important;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .notif-header-actions button:hover {
        background: rgba(0, 47, 112, 0.1) !important;
        color: #00449e !important;
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
        padding: 0 20px;  /* Equal left and right padding */
        overflow: visible;  /* Ensure nothing gets clipped */
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
        position: relative;
        z-index: 10;
        pointer-events: auto;
    }
    .header-center {
        flex-grow: 1;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 0 5px;  /* Minimal padding */
        position: relative;
        z-index: 5;
        pointer-events: none;  /* Prevent center area from blocking header icon clicks */
        min-width: 0;
    }
    /* Collapse header-center when empty (no search bar rendered for this role) */
    .header-center:not(:has(#searchWrapper)) {
        flex-grow: 0;
        padding: 0;
        width: 0;
    }
    .header-right {
        display: flex;
        align-items: center;
        gap: 5px;  /* Ultra compact gap */
        flex-shrink: 0;
        padding-right: 0;  /* No extra padding - balanced with left */
        overflow: visible;  /* Ensure content not clipped */
        position: relative;
        z-index: 10;
        pointer-events: auto;
    }
    .profile-access {
        position: relative;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: var(--petron-blue);
        padding-right: 0;  /* Removed extra padding */
        flex-shrink: 0;  /* Don't let it shrink */
        min-width: fit-content;  /* Ensure it doesn't compress */
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

    /* Sidebar Collapse Button Styling - ICON ONLY, NO TEXT */
    .sidebar-collapse-btn {
        background: var(--petron-blue) !important;
        border: none !important;
        color: transparent !important; /* Make text transparent */
        cursor: pointer !important;
        padding: 0 !important;
        border-radius: 50% !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        width: 32px !important;  /* Match notification & theme toggle */
        height: 32px !important;
        position: relative !important;
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.3) !important;
        flex-shrink: 0 !important;
        font-size: 0 !important; /* Hide any text */
        overflow: hidden !important; /* Hide overflow text */
        text-indent: -9999px !important; /* Push text way off screen */
        line-height: 0 !important; /* Collapse text height */
        z-index: 1100 !important;
        pointer-events: auto !important;
    }
    
    /* Ensure no text content visible */
    .sidebar-collapse-btn::before,
    .sidebar-collapse-btn::after {
        content: none !important;
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
    
    /* ICON IS VISIBLE */
    .sidebar-collapse-btn i {
        font-size: 15px !important;  /* Match notification & theme toggle icons */
        margin: 0 !important;
        transition: transform 0.3s ease !important;
        color: #ffffff !important;
        position: relative !important;
        z-index: 10 !important;
        display: block !important;
        text-indent: 0 !important; /* Reset text-indent for icon */
        line-height: normal !important; /* Reset line-height for icon */
    }
    
    .sidebar-collapse-btn:hover i {
        transform: none !important;
    }
    
    /* Sidebar Collapsed State */
    .sidebar.collapsed {
        width: 70px !important;
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

    /* Toggle row removed - button moved to header */
    
    /* Header Search Bar - restore pointer events on actual search elements */
    #searchWrapper,
    .header-center form,
    .header-center input,
    .header-center button,
    #searchInput,
    #searchSuggestions {
        pointer-events: auto;
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
        padding: 6px;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0, 47, 112, 0.05);
        z-index: 1100 !important;
        pointer-events: auto !important;
        flex-shrink: 0;
        width: 32px;  /* Ultra compact */
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
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

    /* â”€â”€ Theme Toggle Button (icon only) â”€â”€ */
    .theme-toggle-btn {
        position: relative;
        cursor: pointer;
        padding: 0;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0, 47, 112, 0.07);
        border: 1.5px solid rgba(0, 47, 112, 0.12);
        z-index: 1100 !important;
        pointer-events: auto !important;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        user-select: none;
    }
    .theme-toggle-btn:hover {
        background: rgba(0, 47, 112, 0.13);
        border-color: rgba(0, 47, 112, 0.3);
        transform: scale(1.1);
    }
    .theme-toggle-btn i {
        font-size: 15px;
        color: var(--petron-blue);
        transition: all 0.3s ease;
    }
    .theme-toggle-btn:hover i {
        color: var(--petron-red);
        transform: rotate(20deg);
    }

    /* Dark mode â€” glowing indigo circle */
    body.dark-theme .theme-toggle-btn {
        background: rgba(99, 102, 241, 0.18) !important;
        border: 1.5px solid rgba(99, 102, 241, 0.55) !important;
        box-shadow: 0 0 10px rgba(99, 102, 241, 0.35), inset 0 0 6px rgba(99, 102, 241, 0.1) !important;
    }
    body.dark-theme .theme-toggle-btn:hover {
        background: rgba(99, 102, 241, 0.28) !important;
        box-shadow: 0 0 16px rgba(99, 102, 241, 0.55) !important;
        transform: scale(1.12) !important;
    }
    body.dark-theme .theme-toggle-btn i {
        color: #a5b4fc !important;
    }
    body.dark-theme .theme-toggle-btn:hover i {
        color: #c7d2fe !important;
    }

    /* â”€â”€ Dark Mode Accent Bar (thin glow line at very top of page) â”€â”€ */
    /* === CRITICAL FIX: Ensure body pseudo-elements don't block clicks === */
    body::before {
        content: '';
        position: fixed;
        pointer-events: none !important;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: transparent;
        z-index: 9999;
        transition: background 0.3s ease;
    }
    body.dark-theme::before {
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #3b82f6, #6366f1);
        background-size: 200% 100%;
        animation: darkModeBarShimmer 3s linear infinite;
        box-shadow: 0 0 12px rgba(99,102,241,0.7);
    }
    @keyframes darkModeBarShimmer {
        0%   { background-position: 0% 50%; }
        100% { background-position: 200% 50%; }
    }


    
    .notification-bell i {
        font-size: 15px;  /* Smaller icon */
        color: var(--petron-blue);
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
        z-index: 1100 !important;
        pointer-events: auto !important;
    }

    .profile-dropdown {
        z-index: 1150 !important;
        pointer-events: auto !important;
    }

    /* ---- Force all header interactive elements to receive clicks ---- */
    .top-header .notification-bell,
    .top-header .theme-toggle-btn,
    .top-header .profile-access,
    .top-header .sidebar-collapse-btn,
    .top-header .notif-dropdown,
    .top-header .profile-dropdown,
    .top-header button,
    .top-header a,
    #notificationBell,
    #themeToggle,
    #profileMenu,
    #profileDropdown,
    #sidebarCollapseBtn {
        pointer-events: auto !important;
        position: relative;
        z-index: 1100 !important;
    }

    /* Ensure header-left and header-right are fully interactive */
    .header-left,
    .header-right {
        pointer-events: auto !important;
        z-index: 1100 !important;
        position: relative;
    }
    
    /* Make sure search wrapper doesn't block icons */
    #searchWrapper {
        pointer-events: auto !important;
        z-index: 5 !important;
        max-width: 250px;
        position: relative;
    }
    
    /* Ensure header-center stays behind interactive elements */
    .header-center {
        z-index: 5 !important;
        pointer-events: none !important;
    }
    
    /* But allow interaction with search elements */
    .header-center * {
        pointer-events: auto !important;
    }
    
    /* === CRITICAL HEADER ICON FIX === */
    /* Ensure all header buttons are always clickable with highest priority */
    .top-header,
    .top-header * {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    
    /* NUCLEAR FIX: Force all interactive header elements to be on top and clickable */
    #notificationBell,
    #themeToggle,
    #profileMenu,
    #sidebarCollapseBtn,
    .notification-bell,
    .theme-toggle-btn,
    .profile-access,
    .sidebar-collapse-btn {
        position: relative !important;
        z-index: 99999 !important;
        pointer-events: auto !important;
        cursor: pointer !important;
        isolation: isolate !important;
    }
    
    /* Make sure button backgrounds don't block clicks */
    .sidebar-collapse-btn,
    #sidebarCollapseBtn {
        background: var(--petron-blue) !important;
        border: none !important;
        outline: none !important;
        -webkit-tap-highlight-color: transparent !important;
    }
    
    /* Ensure dropdowns appear above everything */
    #notificationDropdown,
    #profileDropdown,
    .notif-dropdown,
    .profile-dropdown {
        z-index: 999999 !important;
        pointer-events: auto !important;
    }
    
    /* Prevent any overlay from blocking header icons */
    .header-left > *,
    .header-right > * {
        position: relative !important;
        z-index: 99999 !important;
        pointer-events: auto !important;
    }
    
    /* Ensure brand elements don't block the button */
    .brand-mark,
    .brand-text {
        pointer-events: none !important;
        z-index: 1 !important;
    }
    
    /* Make sure header areas have proper stacking */
    .header-left {
        z-index: 99999 !important;
        isolation: isolate !important;
    }
    
    .header-right {
        z-index: 99999 !important;
        isolation: isolate !important;
    }
        
        
        
        
      </style>
</head>
<body class="app" data-page="<?php echo htmlspecialchars($page_id); ?>" data-role="<?php echo htmlspecialchars($role); ?>">
<!-- NUCLEAR-HEADER-FIX: Force header above any overlays and ensure clicks reach controls -->
<style id="nuclearHeaderFix">
    .top-header{ position:fixed !important; top:0; left:0; right:0; z-index:12002 !important; pointer-events:auto !important; }
    .top-header *{ pointer-events:auto !important; }
    /* Sidebar must be clickable and not blocked by header */
    .sidebar { pointer-events:auto !important; position: fixed !important; z-index:1001 !important; }
    .sidebar *, .sidebar .nav-item, .sidebar .nav-item *, .sidebar-menu, .sidebar-menu * { pointer-events:auto !important; cursor: pointer !important; }
    /* Make common backdrop/overlay elements pass pointer-events through so header remains clickable */
    .mobile-sidebar-backdrop, .modal-backdrop, .sr-modal-overlay, .overlay-block, .ui-block { pointer-events:none !important; }
    /* Keep dropdowns above everything */
    #notificationDropdown, #profileDropdown, .notif-dropdown, .profile-dropdown { z-index:12003 !important; pointer-events:auto !important; }
</style>

<!-- SIDEBAR CLICKABILITY FIX: Ensure all sidebar navigation items are fully clickable -->
<style id="sidebarClickabilityFix">
    /* Force sidebar and all children to accept pointer events */
    #mainSidebar { pointer-events: auto !important; z-index: 1001 !important; }
    #mainSidebar * { pointer-events: auto !important; }
    
    /* Ensure nav items are clickable */
    .nav-item-wrapper { pointer-events: auto !important; position: relative; z-index: 10; }
    .nav-item { pointer-events: auto !important; cursor: pointer !important; position: relative; z-index: 10; }
    .nav-item a { pointer-events: auto !important; cursor: pointer !important; }
    .sidebar-sub-item { pointer-events: auto !important; cursor: pointer !important; }
    
    /* Ensure the sidebar-menu is fully interactive */
    .sidebar-menu { pointer-events: auto !important; }
    .sidebar-menu * { pointer-events: auto !important; }
    
    /* Make sure no overlay is blocking the sidebar */
    body::before, body::after { pointer-events: none !important; }
</style>

<!-- MAIN CONTENT CLICKABILITY FIX: Ensure all buttons and interactive elements in main content are clickable -->
<style id="mainContentClickabilityFix">
    /* Force main content area to accept pointer events */
    .main { pointer-events: auto !important; z-index: 1 !important; }
    .main * { pointer-events: auto !important; }
    
    /* Ensure all buttons are clickable */
    button, .btn, .button, input[type="button"], input[type="submit"], a.btn {
        pointer-events: auto !important;
        cursor: pointer !important;
        position: relative;
        z-index: 10;
    }
    
    /* Ensure all interactive form elements are clickable */
    input, select, textarea, label {
        pointer-events: auto !important;
        cursor: auto !important;
    }
    
    input[type="button"], input[type="submit"], button {
        cursor: pointer !important;
    }
    
    /* Ensure all links are clickable */
    a { pointer-events: auto !important; cursor: pointer !important; }
    
    /* Cards and panels should allow clicks */
    .card, .panel, .widget-card, .petron-card {
        pointer-events: auto !important;
    }
    
    .card *, .panel *, .widget-card *, .petron-card * {
        pointer-events: auto !important;
    }
    
    /* Tables and their contents should be clickable */
    table, table * {
        pointer-events: auto !important;
    }
    
    /* Modals and their content should be clickable */
    .modal, .modal * {
        pointer-events: auto !important;
    }
    
    /* Ensure dropdowns are clickable */
    .dropdown, .dropdown-menu, .dropdown-item {
        pointer-events: auto !important;
        cursor: pointer !important;
    }
    
    /* Override any conflicting styles */
    .main-content, .content, .container, .container-fluid {
        pointer-events: auto !important;
    }
    
    /* Ensure no pseudo-elements block clicks */
    .main::before, .main::after,
    .card::before, .card::after,
    .panel::before, .panel::after {
        pointer-events: none !important;
    }
</style>

<!-- PREVENT FLICKER ON PAGE LOAD: Apply theme immediately -->
<style id="antiFlickerFix">
    /* Smooth transitions for theme changes */
    body {
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    
    .sidebar, .main, .top-header, .card, .panel, .widget-card {
        transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }
    
    /* Prevent sidebar flickering during toggle */
    .sidebar {
        transition: width 0.3s ease, transform 0.3s ease !important;
    }
    
    .main {
        transition: left 0.3s ease, margin-left 0.3s ease !important;
    }
    
    /* Smooth icon transitions */
    #sidebarToggleIcon, #themeIcon {
        transition: transform 0.2s ease;
    }
</style>

<!-- ULTIMATE CONTENT CLICKABILITY FIX: Nuclear option to ensure EVERYTHING works -->
<style id="ultimateClickFix">
    /* === FORCE ALL CONTENT TO BE INTERACTIVE === */
    
    /* Main content area MUST be on top of any overlays */
    .main {
        position: fixed !important;
        pointer-events: auto !important;
        z-index: 1 !important;
        isolation: isolate !important;
    }
    
    /* Everything inside main MUST be clickable */
    .main *,
    .main button,
    .main .btn,
    .main input,
    .main select,
    .main textarea,
    .main a,
    .main label,
    .main .card,
    .main .panel,
    .main table,
    .main tr,
    .main td,
    .main th {
        pointer-events: auto !important;
    }
    
    /* Buttons specifically MUST work */
    button,
    .btn,
    input[type="button"],
    input[type="submit"],
    a.btn,
    .button {
        pointer-events: auto !important;
        cursor: pointer !important;
        position: relative;
        z-index: 10 !important;
    }
    
    /* Ensure main content can scroll */
    .main {
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch !important;
    }
    
    /* Remove any accidental overlays — but exclude modals moved to body via JS */
    body > *:not(.app):not(.top-header):not(.sidebar):not(.main):not(.fixed-footer):not(script):not(style):not(.mi-overlay):not(.modal-overlay):not(.sr-success-overlay):not(.sr-success-popup):not([id$="Modal"]):not([id$="modal"]):not([id*="Overlay"]):not([id*="overlay"]):not([id*="Popup"]):not([id*="popup"]):not(#toast) {
        pointer-events: none !important;
    }

    /* Modals and overlays appended to body MUST be fully interactive */
    .mi-overlay,
    .mi-overlay *,
    .modal-overlay,
    .modal-overlay *,
    .sr-success-overlay,
    .sr-success-popup,
    .sr-success-popup * {
        pointer-events: auto !important;
    }
    
    /* Ensure no pseudo-elements block interaction */
    *::before,
    *::after {
        pointer-events: none !important;
    }
    
    /* But allow pseudo-elements inside interactive elements */
    button::before, button::after,
    .btn::before, .btn::after,
    a::before, a::after {
        pointer-events: auto !important;
    }
    
    /* App container should not interfere */
    .app {
        pointer-events: auto !important;
        overflow: hidden !important;
    }
    
    /* Scrollable containers */
    .main,
    .content,
    .container,
    .container-fluid,
    .table-responsive,
    .overflow-auto,
    .scroll-container {
        pointer-events: auto !important;
        overflow-y: auto !important;
    }
</style>

<!-- Move header dropdowns to body to avoid clipping by ancestor overflow -->
<script>
(function(){
    function moveToBody(id){
        try{
            var el = document.getElementById(id);
            if(!el) return null;
            if(el.dataset.moved === '1') return el;
            var ph = document.createElement('div'); ph.style.display='none'; el.parentNode.insertBefore(ph, el);
            el.dataset._ph = '';
            document.body.appendChild(el);
            el.style.position = 'fixed';
            el.style.left = '0px';
            el.style.top = '0px';
            el.style.margin = '0';
            el.dataset.moved = '1';
            return el;
        }catch(e){return null;}
    }

    function positionDropdown(dropdownId, triggerEl){
        try{
            var d = document.getElementById(dropdownId);
            if(!d || !triggerEl) return;
            // ensure block is visible to measure
            var wasHidden = window.getComputedStyle(d).display === 'none';
            if(wasHidden){ d.style.display = 'block'; d.style.visibility = 'hidden'; }
            var rect = triggerEl.getBoundingClientRect();

            // Prefer a compact width for profile, larger for notifications
            var desiredWidth;
            if(dropdownId === 'profileDropdown') {
                desiredWidth = 240; // profile menu should be compact
            } else if (dropdownId === 'notificationDropdown') {
                desiredWidth = Math.min(380, Math.max(260, d.offsetWidth || 320));
            } else {
                desiredWidth = Math.min(380, Math.max(240, d.offsetWidth || 320));
            }
            // Never exceed viewport minus small padding
            var width = Math.min(desiredWidth, Math.max(160, window.innerWidth - 24));
            d.style.width = width + 'px';

            // Position horizontally: prefer aligning right edge to trigger's right edge
            var left = Math.round(rect.right - width - 6);
            // If there's not enough room on the right, try aligning left edge to trigger's left
            if(left < 8) left = Math.round(rect.left + 6);
            if(left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);

            // Position vertically: below trigger; if dropdown would overflow viewport bottom, place above
            var topBelow = Math.round(rect.bottom + 8);
            var bottomOverflow = topBelow + d.offsetHeight > window.innerHeight - 8;
            var top = topBelow;
            if(dropdownId === 'profileDropdown' && bottomOverflow){
                // place above trigger if it would overflow
                top = Math.round(rect.top - d.offsetHeight - 8);
                if(top < 8) top = 8;
            }

            d.style.left = left + 'px';
            d.style.top = top + 'px';
            d.style.visibility = '';
            if(wasHidden && !d.classList.contains('show')) d.style.display = 'none';
        }catch(e){console && console.warn && console.warn('positionDropdown err', e);}  
    }

    function toggleDropdown(dropdownId, triggerSel){
        var trigger = (typeof triggerSel === 'string') ? document.querySelector(triggerSel) : triggerSel;
        if(!trigger) return;
        var d = moveToBody(dropdownId) || document.getElementById(dropdownId);
        if(!d) return;
        var showing = d.classList.contains('show');
        if(!showing){
            // Close any other header dropdowns first (mutual exclusivity)
            closeAllHeaderDropdowns(dropdownId);
            d.classList.add('show');
            d.style.display = 'block';
            positionDropdown(dropdownId, trigger);
        } else {
            d.classList.remove('show');
            d.style.display = 'none';
        }
    }

    function closeAllHeaderDropdowns(exceptId){
        try{
            var ids = ['notificationDropdown','profileDropdown','varianceAlertDropdown'];
            ids.forEach(function(id){
                if(!id) return;
                if(id === exceptId) return;
                var el = document.getElementById(id);
                if(!el) return;
                el.classList.remove('show');
                el.style.display = 'none';
            });
            // Also hide any generic dropdown classes used in header
            document.querySelectorAll('.notif-dropdown, .profile-dropdown').forEach(function(el){
                if(el.id && el.id === exceptId) return;
                el.classList.remove('show'); el.style.display = 'none';
            });
        }catch(e){ console && console.warn && console.warn('closeAllHeaderDropdowns err', e); }
    }

    // Replace existing toggle handlers with safe wrappers
    window.petronToggleNotif = function(e){
        try{ toggleDropdown('notificationDropdown', '#notificationBell'); if(e && e.preventDefault) e.preventDefault(); }catch(err){}
    };
    window.petronToggleProfile = function(e){
        try{ toggleDropdown('profileDropdown', '#profileMenu'); if(e && e.preventDefault) e.preventDefault(); }catch(err){}
    };

    // Reposition visible dropdowns on resize/scroll
    function repositionAll(){
        var nb = document.getElementById('notificationBell');
        var pd = document.getElementById('profileMenu');
        var nd = document.getElementById('notificationDropdown');
        var prd = document.getElementById('profileDropdown');
        if(nd && nd.classList.contains('show') && nb) positionDropdown('notificationDropdown', nb);
        if(prd && prd.classList.contains('show') && pd) positionDropdown('profileDropdown', pd);
    }
    window.addEventListener('resize', function(){ setTimeout(repositionAll, 50); }, {passive:true});
    window.addEventListener('scroll', function(){ setTimeout(repositionAll, 50); }, {passive:true});

    // On DOM ready, move dropdowns so they cannot be clipped
    document.addEventListener('DOMContentLoaded', function(){
        moveToBody('notificationDropdown');
        moveToBody('profileDropdown');
        moveToBody('varianceAlertDropdown');
    });

    // Expose helpers so other header scripts can reuse positioning/toggle behavior
    window.petronHeaderToggle = toggleDropdown;
    window.petronHeaderRepositionAll = repositionAll;
})();
</script>

    <!-- Debug Info (remove after fixing) -->
  <aside class="sidebar" id="mainSidebar">
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

  // Sidebar badge system.
  // Show live action counts so users can identify pending work from the menu.
  // These badges stay visible until the underlying item is resolved.
  $fuel_sub_badges = [];  // keyed by sub-item ID
  $badges          = is_array($badges ?? null) ? $badges : [];  // keyed by top-level item ID

  // Helper: load all badge_seen timestamps for this user in one query
  $badge_seen = [];
  $__uid = (int)($user['id'] ?? 0);
  if ($__uid > 0) {
      try {
          $__bs = $pdo->prepare(
              "SELECT preference_key, preference_value
               FROM user_preferences
               WHERE user_id = ? AND preference_key LIKE 'badge_seen_%'"
          );
          $__bs->execute([$__uid]);
          foreach ($__bs->fetchAll(PDO::FETCH_ASSOC) as $__row) {
              $__key = str_replace('badge_seen_', '', $__row['preference_key']);
              $badge_seen[$__key] = $__row['preference_value']; // UTC datetime string
          }
      } catch (Exception $e) {}
  }

  // Helper: get the "since" datetime for a badge key (fallback = 30 days ago for new users)
  $__default_since = date('Y-m-d H:i:s', strtotime('-30 days'));
  $__badge_since = function($key) use ($badge_seen, $__default_since) {
      return $badge_seen[$key] ?? $__default_since;
  };

  // Helper: safe count query with since-filter on created_at
  $__badge_count = function($sql, $params) use ($pdo) {
      try {
          $s = $pdo->prepare($sql);
          $s->execute($params);
          return max(0, (int)$s->fetchColumn());
      } catch (Exception $e) { return 0; }
  };

  $__badge_add = function($key, $count, $target = 'sub') use (&$fuel_sub_badges, &$badges) {
      $count = max(0, (int)$count);
      if ($count <= 0 || $key === '') return;
      if ($target === 'top') {
          $badges[$key] = ($badges[$key] ?? 0) + $count;
      } else {
          $fuel_sub_badges[$key] = ($fuel_sub_badges[$key] ?? 0) + $count;
      }
  };

  if ($myStationId || in_array($role, ['admin', 'superadmin', 'developer'], true)) {

      // Staff badges
      if (in_array($role, ['staff', 'cashier', 'pump_attendant']) && $myStationId) {

          $__own_merch_pending = $__badge_count(
              "SELECT COUNT(*) FROM merchandise_transactions
               WHERE station_id=? AND staff_id=? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
              [$myStationId, $__uid]
          );
          $__own_fuel_pending = $__badge_count(
              "SELECT COUNT(*) FROM fuel_transactions
               WHERE station_id=? AND staff_id=? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
              [$myStationId, $__uid]
          );
          $__own_jobs_active = $__badge_count(
              "SELECT COUNT(*) FROM job_orders
               WHERE station_id=? AND (created_by=? OR user_id=?)
               AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
              [$myStationId, $__uid, $__uid]
          );
          $__badge_add('staff_new_transaction', $__own_merch_pending + $__own_fuel_pending + $__own_jobs_active);

          $__low_merch = $__badge_count(
              "SELECT COUNT(*) FROM station_inventory si
               LEFT JOIN inventory_products ip ON ip.id=si.product_id
               WHERE si.station_id=? AND COALESCE(si.stock_level,0) <= COALESCE(si.reorder_level, ip.min_stock, 10)
               AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL)",
              [$myStationId]
          );
          $__badge_add('inv_merch', $__low_merch);

          $__low_fuel = $__badge_count(
              "SELECT COUNT(*) FROM fuel_inventory
               WHERE station_id=? AND current_level >= 0 AND capacity > 0
               AND current_level <= COALESCE(reorder_level, capacity * 0.20)",
              [$myStationId]
          );
          $__badge_add('inv_fuel', $__low_fuel);

          $__badge_add('fuel', $__low_fuel + $__own_fuel_pending, 'top');

          $__pending_merch_pos = $__badge_count(
               "SELECT COUNT(DISTINCT po_number) FROM purchase_orders
                WHERE station_id=? AND status IN ('Admin Finalized', 'Approved') AND type='merch'",
               [$myStationId]
           );
           $__pending_fuel_pos = $__badge_count(
               "SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), po_number)) FROM fuel_purchase_orders
                WHERE station_id=? AND status IN ('Approved PO', 'Approved')",
               [$myStationId]
           );
           $__badge_add('inv_record_delivery', $__pending_merch_pos + $__pending_fuel_pos);

          $__staff_stock_requests = $__badge_count(
              "SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
              [$myStationId, $__uid]
          ) + $__badge_count(
              "SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id=? AND staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
              [$myStationId, $__uid]
          );
          $__badge_add('staff_stock_requests', $__staff_stock_requests);

          $__calendar_due = $__badge_count(
              "SELECT COUNT(*) FROM calendar_events
               WHERE station_id=? AND staff_assigned=?
               AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND status IN ('pending','approved')",
              [$myStationId, $__uid]
          );
          $__badge_add('calendar', $__calendar_due, 'top');
      }
  }

  // ── Sync Sidebar Drawer Badges directly with Notifications table unread counts ──
  if ($__uid > 0) {
      try {
          $un_stmt = $pdo->prepare(
              "SELECT event_type, COUNT(*) as cnt FROM notifications WHERE user_id = ? AND status = 'unread' GROUP BY event_type"
          );
          $un_stmt->execute([$__uid]);
          $un_rows = $un_stmt->fetchAll(PDO::FETCH_ASSOC);

          $notif_cat = [
              'transactions' => 0,
              'fuel'         => 0,
              'inventory'    => 0,
              'customers'    => 0
          ];
          foreach ($un_rows as $ur) {
              $e = strtolower($ur['event_type'] ?? '');
              $c = (int)$ur['cnt'];
              if (in_array($e, ['transaction', 'job_order', 'joborder'])) {
                  $notif_cat['transactions'] += $c;
              } elseif (in_array($e, ['fuel_management', 'fuel'])) {
                  $notif_cat['fuel'] += $c;
              } elseif (in_array($e, ['inventory', 'stock_in', 'delivery', 'stock_request'])) {
                  $notif_cat['inventory'] += $c;
              } elseif ($e === 'customer') {
                  $notif_cat['customers'] += $c;
              }
          }

          // Override top-level badges for Transactions, Fuel Management, Inventory, Customers
          $badges['transactions'] = $notif_cat['transactions'];
          $badges['fuel']         = $notif_cat['fuel'];
          $badges['inventory']    = $notif_cat['inventory'];
          $badges['customers']    = $notif_cat['customers'];

      } catch (Exception $e) {}
  }

  // Calculate Header Bell Unread Count
  $header_unread_count = 0;
  if ($__uid > 0) {
      try {
          $h_cnt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
          $h_cnt->execute([$__uid]);
          $header_unread_count = (int)$h_cnt->fetchColumn();
      } catch (Exception $e) {}
  }

  foreach($items as $it){
    if (($it['id'] ?? '') === 'dashboard') {
        if (in_array($role, ['manager','supervisor','admin','superadmin'])) {
            continue;
        }
        $dash_href = '/group31petron_system_official4/public/';
        if (in_array($role, ['staff','cashier','pump_attendant'])) $dash_href .= 'staff_dashboard.php';
        else $dash_href .= 'dashboard.php';
        $dash_active = in_array($page_id, ['dashboard','staff_dashboard','manager_dashboard']) ? 'active' : '';
        echo '<div class="nav-item-wrapper">';
        echo '<a class="nav-item '.$dash_active.'" href="'.htmlspecialchars($dash_href).'" data-tooltip="Dashboard">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="fas fa-gauge"></i></span>';
        echo '<span style="flex-grow:1;font-size:13px;font-weight:500;">Dashboard</span>';
        echo '</a>';
        echo '</div>';
        continue;
    }

    $has_sub = !empty($it['sub_items']);
    $active = '';
    $parent_active = false;

    if (!$has_sub && $page_id === ($it['id'] ?? '')) {
        $active = 'active';
    }

    if ($has_sub) {
        foreach ($it['sub_items'] as $sub) {
            if ($page_id === ($sub['id'] ?? '')) {
                $parent_active = true; break;
            }
        }
    }

    echo '<div class="nav-item-wrapper">';

    if ($has_sub) {
        $parent_cls = $parent_active ? 'nav-item active' : 'nav-item';
        echo '<a class="'.$parent_cls.' has-submenu" href="'.htmlspecialchars($it['href']).'" data-tooltip="'.htmlspecialchars($it['label']).'" onclick="toggleSidebarSub(event,\'sub-'.htmlspecialchars($it['id']).'\')">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="'.htmlspecialchars($it['ico']).'"></i></span>';
        echo '<span style="flex-grow:1;font-size:13px;font-weight:500;">'.htmlspecialchars($it['label']).'</span>';
        
        $p_badge = $badges[$it['id']] ?? 0;
        if ($p_badge <= 0 && !empty($it['sub_items'])) {
            foreach ($it['sub_items'] as $sub) {
                $p_badge += $fuel_sub_badges[$sub['id'] ?? ''] ?? 0;
            }
        }
        $p_disp = $p_badge > 0 ? 'display:flex;' : 'display:none;';
        echo '<span data-sidebar-badge="'.htmlspecialchars($it['id']).'" data-badge style="background:#E30613;color:white;padding:0 6px;border-radius:10px;font-size:11px;font-weight:bold;min-width:20px;height:20px;'.$p_disp.'align-items:center;justify-content:center;margin-right:6px;">'.($p_badge > 0 ? $p_badge : '').'</span>';
        echo '<i class="fas fa-chevron-down" style="font-size:10px;transition:transform .3s;'.($parent_active?'transform:rotate(180deg)':'').'"></i>';
        echo '</a>';

        $display = $parent_active ? 'block' : 'none';
        echo '<div id="sub-'.htmlspecialchars($it['id']).'" style="display:'.$display.';background:transparent;border-left:3px solid rgba(255,255,255,.2);margin-left:0;padding-left:0;">';
        foreach ($it['sub_items'] as $sub) {
            $sub_active = ($page_id === ($sub['id'] ?? '')) ? 'active' : '';
            $sub_badge = $fuel_sub_badges[$sub['id'] ?? ''] ?? 0;
            echo '<a class="nav-item sidebar-sub-item '.$sub_active.'" href="'.htmlspecialchars($sub['href']).'" style="padding:6px 15px 6px 47px;min-height:auto;" data-tooltip="'.htmlspecialchars($sub['label'] ?? '').'">';
            echo '<span class="ico" style="margin-right:8px;width:14px;text-align:center;flex-shrink:0;"><i class="fas fa-circle" style="font-size:4px;opacity:.5;"></i></span>';
            echo '<span style="flex-grow:1;line-height:1.2;">';
            echo '<span style="display:block;font-size:12px;font-weight:500;">'.htmlspecialchars($sub['label'] ?? '').'</span>';
            echo '</span>';
            if ($sub_badge > 0) {
                echo '<span data-badge style="background:#E30613;color:white;padding:0 5px;border-radius:10px;font-size:10px;font-weight:bold;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'.$sub_badge.'</span>';
            }
            echo '</a>';
        }
        echo '</div>';

    } else {
        echo '<a class="nav-item '.$active.'" href="'.htmlspecialchars($it['href']).'" data-tooltip="'.htmlspecialchars($it['label']).'">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="'.htmlspecialchars($it['ico']).'"></i></span>';
        echo '<span style="flex-grow:1;font-size:13px;font-weight:500;">'.htmlspecialchars($it['label']).'</span>';
        $r_b = $badges[$it['id']] ?? 0;
        $r_disp = $r_b > 0 ? 'display:flex;' : 'display:none;';
        echo '<span data-sidebar-badge="'.htmlspecialchars($it['id']).'" data-badge style="background:#E30613;color:white;padding:0 6px;border-radius:10px;font-size:11px;font-weight:bold;min-width:20px;height:20px;'.$r_disp.'align-items:center;justify-content:center;margin-left:10px;">'.($r_b > 0 ? $r_b : '').'</span>';
        echo '</a>';
    }

    echo '</div>';
  }
  ?>
      </nav>
    </div>

    <!-- â”€â”€ Sidebar Identity Footer â”€â”€ -->
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
    <div class="sidebar-identity-footer" title="<?php echo htmlspecialchars("$sid_name - $sid_role"); ?>">
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
            <span class="sif-dash">-</span>
            <span class="sif-role"><?php echo htmlspecialchars($sid_role); ?></span>
        </div>
    </div>

  </aside>

  <?php
  // Legacy seen tracking is kept for compatibility, but sidebar badges now use
  // live pending/action counts and stay visible until items are resolved.
  $badge_page_map = [
      // STAFF pages
      'staff_stock_requests'                      => ['staff_stock_requests'],
      'staff_fuel_deliveries_history'             => ['staff_fuel_del_history'],
      'staff_delivery_history'                    => ['staff_delivery_history'],
      'staff_transactions_hub'                    => ['staff_new_transaction'],
      // MANAGER pages
      'manager_validated_transactions'            => ['validated_transactions_manager'],
      'manager_stock_request_review'              => ['mgr_stock_review'],
      'manager_fuel_transaction_validation'       => ['fuel_transactions_validation', 'fuel_transactions'],
      'manager_fuel_daily_ops'                    => ['fuel_reconciliation', 'fuel_variance_report'],
      'manager_fuel_reconciliation'               => ['fuel_reconciliation', 'fuel_variance_report'],
      'manager_request_data_management'           => ['manager_request_data_management'],
      // ADMIN pages
      'admin_purchase_orders'                     => ['admin_purchase_orders'],
      'admin_request_data_management'             => ['admin_request_data_management'],
      'admin_voided_transactions'                 => ['admin_voided_transactions'],
      'admin_fuel_transactions_oversight'         => ['admin_fuel_transactions_oversight'],
  ];
  $badge_modules_to_mark = $badge_page_map[$page_id] ?? [];
  ?>

  <?php if (!empty($badge_modules_to_mark)): ?>
  <script>
  // Auto-mark badge modules as seen when this page loads
  (function() {
      var API = '/group31petron_system_official4/backend/api/badge_seen.php';
      var modules = <?php echo json_encode($badge_modules_to_mark); ?>;
      function markSeen(mod) {
          fetch(API, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ module: mod }),
              credentials: 'same-origin'
          }).catch(function(){});
      }
      // Small delay so page loads first, then mark seen
      setTimeout(function() {
          modules.forEach(markSeen);
      }, 800);
  })();
  </script>
  <?php endif; ?>

  <script>
  // Keep live action badges visible. They represent unresolved work, so clicking
  // a module should not hide a badge until the underlying item is completed.
  document.addEventListener('DOMContentLoaded', function() {
      var API = '/group31petron_system_official4/backend/api/badge_seen.php';

      // Map: filename base (without .php) → badge module keys to mark seen
      var navBadgeMap = {
          // Staff
          'staff_stock_requests':               ['staff_stock_requests'],
          'staff_fuel_deliveries_history':      ['staff_fuel_del_history'],
          'staff_delivery_history':             ['staff_delivery_history'],
          'staff_transactions_hub':             ['staff_new_transaction'],
          // Manager
          'manager_validated_transactions':     ['validated_transactions_manager'],
          'manager_stock_request_review':       ['mgr_stock_review'],
          'manager_fuel_transaction_validation': ['fuel_transactions_validation', 'fuel_transactions'],
          'manager_fuel_deliveries_validation':  ['fuel_deliveries_validation', 'fuel_deliveries'],
          'manager_fuel_daily_ops':             ['fuel_variance_report', 'fuel_reconciliation'],
          'manager_fuel_reconciliation':        ['fuel_variance_report', 'fuel_reconciliation'],
          'manager_request_data_management':    ['manager_request_data_management'],
          // Admin
          'admin_purchase_orders':              ['admin_purchase_orders'],
          'admin_request_data_management':      ['admin_request_data_management'],
          'admin_voided_transactions':          ['admin_voided_transactions'],
          'admin_fuel_transactions_oversight':  ['admin_fuel_transactions_oversight'],
      };

      function markSeen(modules) {
          modules.forEach(function(mod) {
              fetch(API, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ module: mod }),
                  credentials: 'same-origin'
              }).catch(function(){});
          });
      }

      function recalculateParentBadge(container) {
          if (!container) return;
          var parentLink = container.previousElementSibling;
          if (!parentLink) return;
          
          var parentBadgeSpan = parentLink.querySelector('span[data-badge]');
          if (!parentBadgeSpan) return;
          
          // Sum of all sub badges inside container
          var sum = 0;
          container.querySelectorAll('a.sidebar-sub-item span[data-badge]').forEach(function(span) {
              var val = parseInt(span.textContent, 10) || 0;
              sum += val;
          });
          
          if (sum > 0) {
              parentBadgeSpan.textContent = sum;
          } else {
              parentBadgeSpan.remove();
          }
      }

      // Attach click handler to all nav-items in the sidebar
      var sidebar = document.getElementById('mainSidebar');
      if (!sidebar) return;

      // 1. Keep badges visible on click; only preserve legacy seen tracking.
      sidebar.querySelectorAll('a.nav-item').forEach(function(link) {
          link.addEventListener('click', function() {
              // Determine module key from href to call badge_seen
              var href = link.getAttribute('href') || '';
              var base = href.split('/').pop().split('?')[0].replace('.php','');
              if (navBadgeMap[base]) {
                  markSeen(navBadgeMap[base]);
              }
          });
      });

      // 2. No visual badge clearing on load. Server-rendered counts are authoritative.
  });
  </script>

  <!-- Mobile Sidebar Backdrop (shown only on mobile when sidebar is open) -->
  <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop"></div>

  <!-- GLOBAL TOP HEADER -->
    <script>
    // Fallback global stubs — ensures onclick attributes don't throw
    // These will be replaced by full implementations later in the file if available.
    window.petronToggleSidebar = window.petronToggleSidebar || function(e){
        try { console && console.warn && console.warn('petronToggleSidebar fallback'); } catch(e){}
        try {
            var s = document.getElementById('mainSidebar');
            if (s) s.classList.toggle('collapsed');
        } catch(err){}
    };
    window.petronToggleNotif = window.petronToggleNotif || function(e){
        try { console && console.warn && console.warn('petronToggleNotif fallback'); } catch(e){}
        try { var nd = document.getElementById('notificationDropdown'); if (nd) nd.classList.toggle('show'); } catch(err){}
    };
    window.petronToggleProfile = window.petronToggleProfile || function(e){
        try { console && console.warn && console.warn('petronToggleProfile fallback'); } catch(e){}
        try { var pd = document.getElementById('profileDropdown'); if (pd) pd.classList.toggle('show'); } catch(err){}
    };
    window.petronToggleTheme = window.petronToggleTheme || function(e){
        try { console && console.warn && console.warn('petronToggleTheme fallback'); } catch(e){}
        try { document.body.classList.toggle('dark-theme'); } catch(err){}
    };
    </script>
    <header class="top-header">
        <div class="header-left">
            <!-- Sidebar Toggle Button -->
            <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" aria-label="Toggle Sidebar" onclick="petronToggleSidebar(event)" style="margin-right: 15px; z-index: 99999 !important; pointer-events: auto !important; position: relative !important; cursor: pointer !important;">
                <i class="fas fa-bars" id="sidebarToggleIcon" style="pointer-events: none !important;"></i>
            </button>
            <?php 
                $logo_path = $station_settings['logo'] ?? '../assets/img/Petron Logo.png';
            ?>
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Petron Logo" class="brand-mark" id="petronLogo">
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
        <div class="header-center" <?php if(!in_array($role, ['superadmin','developer','admin','manager'])): ?>style="display:none"<?php endif; ?>>
            <!-- Global Search Bar removed per user request -->
        </div>
        <div class="header-right">
            <!-- Variance Alert Badge (Job Order Tracker) â€” shown only to staff on merchandise page -->
            <?php if (isset($variance_alert_count) && $variance_alert_count > 0): ?>
            <div class="notification-bell" id="varianceAlertBell" title="Variance Alerts"
                 style="position:relative;cursor:pointer;"
                 onclick="document.getElementById('varianceAlertDropdown').classList.toggle('show')">
                <i class="fas fa-exclamation-triangle" style="color:#dc2626;font-size:15px;"></i>
                <span class="badge" style="display:inline-flex;align-items:center;justify-content:center;
                      background:#dc2626;color:#fff;border-radius:50%;width:17px;height:17px;
                      font-size:10px;font-weight:700;position:absolute;top:-2px;right:-2px;">
                    <?= (int)$variance_alert_count ?>
                </span>
                <div id="varianceAlertDropdown" class="notif-dropdown"
                     style="min-width:320px;max-width:400px;">
                    <div class="notif-dropdown-header" style="background:#fff3f3;border-bottom:1px solid #fecaca;">
                        <span style="color:#dc2626;font-weight:600;">
                            <i class="fas fa-exclamation-triangle"></i>
                            Variance Alerts (<?= (int)$variance_alert_count ?>)
                        </span>
                    </div>
                    <div style="max-height:320px;overflow-y:auto;padding:8px 0;">
                        <?php foreach ($variance_alerts as $_va): ?>
                        <div style="padding:8px 14px;border-bottom:1px solid #f1f5f9;font-size:12px;">
                            <strong style="color:#002F6C;">
                                <?= htmlspecialchars($_va['jo_ref']) ?>
                            </strong>
                            <span style="margin-left:6px;padding:2px 6px;border-radius:3px;
                                         background:<?= $_va['type']==='qty' ? '#fef3c7' : '#fee2e2' ?>;
                                         color:<?= $_va['type']==='qty' ? '#92400e' : '#991b1b' ?>;
                                         font-size:11px;font-weight:600;">
                                <?= $_va['type']==='qty' ? 'Qty Mismatch' : 'Amount Mismatch' ?>
                            </span>
                            <div style="color:#64748b;margin-top:3px;"><?= htmlspecialchars($_va['message']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker"
                           style="display:block;text-align:center;padding:8px;font-size:12px;
                                  color:#dc2626;text-decoration:none;border-top:1px solid #eee;">
                            View Job Order Tracker
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notification Bell -->
            <?php if(in_array($role, ['staff','admin','manager','superadmin','developer'])): ?>
            <div class="notification-bell" id="notificationBell" onclick="petronToggleNotif(event)" style="z-index: 99999 !important; pointer-events: auto !important; position: relative !important; cursor: pointer !important;">
                <i class="fas fa-bell" style="pointer-events: none !important;"></i>
                <span class="badge" id="notificationBadge" data-server-count="<?php echo (int)$header_unread_count; ?>" style="display: <?php echo $header_unread_count > 0 ? 'flex' : 'none'; ?>; pointer-events: none !important;"><?php echo $header_unread_count > 99 ? '99+' : (int)$header_unread_count; ?></span>

                <div class="notif-dropdown" id="notificationDropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                        <div class="notif-header-actions">
                            <button id="markAllReadBtn">Mark All Read</button>
                            <button id="refreshNotificationsBtn"><i class="fas fa-sync"></i></button>
                        </div>
                    </div>
                    <div class="notif-list" id="notificationList" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                        <?php if (!empty($header_notifications)): ?>
                            <?php
                            $header_evt_icons = [
                                'transaction'     => 'fas fa-shopping-cart',
                                'job_order'       => 'fas fa-wrench',
                                'fuel_management' => 'fas fa-gas-pump',
                                'inventory'       => 'fas fa-warehouse',
                                'customer'        => 'fas fa-user',
                                'delivery'        => 'fas fa-truck',
                                'calendar'        => 'fas fa-calendar-alt',
                                'report'          => 'fas fa-chart-bar',
                                'general'         => 'fas fa-bell',
                            ];
                            $header_type_colors = [
                                'success' => '#28a745',
                                'warning' => '#f59e0b',
                                'error'   => '#dc3545',
                                'info'    => '#17a2b8',
                            ];
                            ?>
                            <?php foreach ($header_notifications as $hn): ?>
                                <?php
                                $hn_unread = (($hn['status'] ?? '') === 'unread');
                                $hn_icon = $header_evt_icons[$hn['event_type'] ?? 'general'] ?? $header_evt_icons['general'];
                                $hn_color = $header_type_colors[$hn['type'] ?? 'info'] ?? $header_type_colors['info'];
                                $hn_raw_url = (string)($hn['redirect_url'] ?? '');
                                $hn_href = $header_notif_url($hn_raw_url);
                                $hn_js_url = json_encode($hn_raw_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                <a class="notif-item<?php echo $hn_unread ? ' unread' : ''; ?>"
                                   href="<?php echo htmlspecialchars($hn_href); ?>"
                                   onclick="if(window.staffMarkRead || window.saMarkRead){event.preventDefault(); (window.staffMarkRead || window.saMarkRead)(<?php echo (int)$hn['id']; ?>, <?php echo htmlspecialchars($hn_js_url, ENT_QUOTES); ?>);}"
                                   style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none;color:inherit;">
                                    <div style="width:48px;height:48px;border-radius:50%;background:<?php echo htmlspecialchars($hn_color); ?>15;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid <?php echo htmlspecialchars($hn_color); ?>30;">
                                        <i class="<?php echo htmlspecialchars($hn_icon); ?>" style="color:<?php echo htmlspecialchars($hn_color); ?>;font-size:20px;"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;line-height:1.3;">
                                        <div style="font-size:14px;color:#050505;margin-bottom:2px;">
                                            <strong style="font-weight:600;"><?php echo htmlspecialchars($hn['title'] ?? 'Notification'); ?></strong>
                                        </div>
                                        <div style="color:#65676B;font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                            <?php echo htmlspecialchars($hn['message'] ?? ''); ?>
                                        </div>
                                        <div style="color:<?php echo $hn_unread ? '#002F6C' : '#65676B'; ?>;font-size:12px;font-weight:<?php echo $hn_unread ? '600' : 'normal'; ?>;margin-top:4px;">
                                            <?php echo htmlspecialchars($header_time_ago($hn['created_at'] ?? '')); ?>
                                        </div>
                                    </div>
                                    <?php if ($hn_unread): ?>
                                        <div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:20px;"></div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
                                <i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="<?php echo htmlspecialchars($public_base_url . '/notifications.php'); ?>" style="display:block; text-align:center; padding:8px; font-size:12px; color:var(--petron-blue); text-decoration:none; border-top:1px solid #eee;">
                            View All Notifications
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Theme Toggle Button -->
            <div class="theme-toggle-btn" id="themeToggle" title="Switch to Dark Mode" aria-label="Toggle theme" onclick="petronToggleTheme(event)">
                <i class="fas fa-moon" id="themeIcon"></i>
            </div>

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
            <div class="profile-access" id="profileMenu" onclick="petronToggleProfile(event)">
                <?php if ($hdr_pic): ?>
                <img src="<?php echo htmlspecialchars($hdr_pic); ?>" alt="Profile"
                     style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:2px solid var(--petron-blue);flex-shrink:0;">
                <?php else: ?>
                <div style="width:30px;height:30px;border-radius:50%;background:var(--petron-blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-user" style="color:#fff;font-size:13px;"></i>
                </div>
                <?php endif; ?>
                <div style="text-align: right; margin-left: 6px;">
                    <div style="font-weight: 700; font-size: 12px; color: var(--petron-blue); letter-spacing: 0.3px;">
                        <?php echo htmlspecialchars($hdr_display); ?>
                    </div>
                    <div style="font-size: 10px; color: #888; margin-top: 1px; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($hdr_role); ?>
                    </div>
                </div>
                <i class="fas fa-caret-down" style="font-size:0.7em; color:#888; margin-left: 4px;"></i>

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

  <main class="main">

    <!-- ══ GLOBAL FLASH MESSAGE STYLES â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <style>
    /* â”€â”€ Petron system-wide flash alerts â”€â”€ */
    /* Petron system-wide top-right toast notifications */

    /* â”€â”€ JS-powered toast (bottom-right, for AJAX actions) â”€â”€ */
    #petron-toast-container {
        position: fixed;
        top: 84px;
        right: 22px;
        z-index: 2147483000;
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: min(390px, calc(100vw - 32px));
        pointer-events: none;
        min-height: 0;
        background: transparent;
    }
    /* Hide container and all children when no toast divs present */
    #petron-toast-container:not(:has(.petron-toast)):not(:has(.petron-flash)) {
        display: none !important;
    }
    /* Hide container when no visible toast children */
    #petron-toast-container > *:not(.petron-toast):not(.petron-flash) {
        display: none;
    }
    .petron-toast {
        position: relative;
        width: 100%;
        min-height: 58px;
        padding: 13px 42px 13px 15px;
        border-radius: 8px;
        border: 1px solid #dbe4f0;
        border-left: 5px solid #2563eb;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.4;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
        pointer-events: auto;
        animation: toastIn 0.26s ease;
        transition: opacity 0.35s ease, transform 0.35s ease;
        overflow: hidden;
    }
    .petron-toast.toast-success { border-left-color:#16a34a; }
    .petron-toast.toast-error   { border-left-color:#dc2626; }
    .petron-toast.toast-warning { border-left-color:#f59e0b; }
    .petron-toast.toast-info    { border-left-color:#2563eb; }
    .petron-toast.toast-success .petron-toast-icon { color:#16a34a; }
    .petron-toast.toast-error .petron-toast-icon   { color:#dc2626; }
    .petron-toast.toast-warning .petron-toast-icon { color:#d97706; }
    .petron-toast.toast-info .petron-toast-icon    { color:#2563eb; }
    .petron-toast.toast-hide { opacity:0; transform:translateX(34px); }
    @keyframes toastIn {
        from { opacity:0; transform:translateX(34px); }
        to   { opacity:1; transform:translateX(0); }
    }
    .petron-toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .petron-toast-body { min-width: 0; flex: 1; }
    .petron-toast-title {
        display: block;
        margin-bottom: 2px;
        color: inherit;
        font-size: 14px;
        font-weight: 800;
    }
    .petron-toast-message {
        display: block;
        color: #475569;
        font-size: 13px;
        font-weight: 600;
        overflow-wrap: anywhere;
    }
    .petron-toast-close {
        position: absolute;
        top: 9px;
        right: 10px;
        width: 24px;
        height: 24px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #64748b;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .petron-toast-close:hover { background: #f1f5f9; color: #0f172a; }
    .petron-flash {
        position: fixed;
        top: 84px;
        right: 22px;
        z-index: 2147483000;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        width: min(390px, calc(100vw - 32px));
        min-height: 58px;
        padding: 13px 42px 13px 15px;
        border-radius: 8px;
        border: 1px solid #dbe4f0;
        border-left: 5px solid #2563eb;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.4;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
        animation: toastIn 0.26s ease;
        transition: opacity 0.35s ease, transform 0.35s ease;
    }
    .petron-flash.flash-success { border-left-color:#16a34a; }
    .petron-flash.flash-error   { border-left-color:#dc2626; }
    .petron-flash.flash-warning { border-left-color:#f59e0b; }
    .petron-flash.flash-info    { border-left-color:#2563eb; }
    .petron-flash.flash-success i { color:#16a34a; }
    .petron-flash.flash-error i   { color:#dc2626; }
    .petron-flash.flash-warning i { color:#d97706; }
    .petron-flash.flash-info i    { color:#2563eb; }
    .petron-flash span { color:#475569; overflow-wrap:anywhere; }
    .petron-flash.toast-hide { opacity:0; transform:translateX(34px); }
    .petron-flash .flash-close {
        position: absolute;
        top: 9px;
        right: 10px;
        width: 24px;
        height: 24px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #64748b;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .petron-flash .flash-close:hover { background: #f1f5f9; color: #0f172a; }
    @media (max-width: 640px) {
        #petron-toast-container {
            top: 76px;
            right: 12px;
            left: 12px;
            width: auto;
        }
        .petron-flash {
            top: 76px;
            right: 12px;
            left: 12px;
            width: auto;
        }
    }
    @media print {
        #petron-toast-container,
        .petron-flash { display: none !important; }
    }
    </style>

    <!-- â•â• GLOBAL FLASH MESSAGE RENDERER (PHP SESSION â†’ HTML) â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php
    $__toast_map = [
        'success' => ['class'=>'success', 'title'=>'Success',     'icon'=>'fa-check-circle',          'keys'=>['success', 'flash_success', 'ok']],
        'error'   => ['class'=>'error',   'title'=>'Error',       'icon'=>'fa-exclamation-circle',    'keys'=>['error', 'flash_error', 'err']],
        'warning' => ['class'=>'warning', 'title'=>'Warning',     'icon'=>'fa-exclamation-triangle',  'keys'=>['warning', 'flash_warning']],
        'info'    => ['class'=>'info',    'title'=>'Information', 'icon'=>'fa-info-circle',           'keys'=>['info', 'flash_info']],
    ];
    $__toasts = [];
    foreach ($__toast_map as $__toast_type => $__toast_meta) {
        foreach ($__toast_meta['keys'] as $__toast_key) {
            if (!empty($_SESSION[$__toast_key])) {
                $__toasts[] = [
                    'type'    => $__toast_meta['class'],
                    'title'   => $__toast_meta['title'],
                    'icon'    => $__toast_meta['icon'],
                    'message' => (string) $_SESSION[$__toast_key],
                ];
                unset($_SESSION[$__toast_key]);
            }
        }
    }
    ?>

    <!-- GLOBAL JS TOAST HELPER - Top-right toast notifications -->
    <div id="petron-toast-container" aria-live="polite" aria-atomic="true"<?php if(empty($__toasts)): ?> style="display:none"<?php endif; ?>>
        <?php foreach ($__toasts as $__toast): ?>
        <div class="petron-toast toast-<?php echo htmlspecialchars($__toast['type'], ENT_QUOTES); ?>" role="status">
            <i class="fas <?php echo htmlspecialchars($__toast['icon'], ENT_QUOTES); ?> petron-toast-icon"></i>
            <span class="petron-toast-body">
                <strong class="petron-toast-title"><?php echo htmlspecialchars($__toast['title'], ENT_QUOTES); ?></strong>
                <span class="petron-toast-message"><?php echo htmlspecialchars($__toast['message'], ENT_QUOTES); ?></span>
            </span>
            <button type="button" class="petron-toast-close" aria-label="Close notification">&times;</button>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    /**
     * showToast(message, type, duration, title)
     * Shows a top-right toast notification for system feedback.
     * type: 'success' | 'error' | 'warning' | 'info'   (default: 'success')
     * duration: ms (default 4000, use 0 for persistent)
     */
    (function() {
        var icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        var titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information'
        };

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        function dismissToast(toast) {
            if (!toast || toast.classList.contains('toast-hide')) return;
            toast.classList.add('toast-hide');
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
                // Hide container when no toasts remain
                var container = document.getElementById('petron-toast-container');
                if (container && container.querySelectorAll('.petron-toast, .petron-flash').length === 0) {
                    container.style.display = 'none';
                }
            }, 380);
        }

        function armToast(toast, duration) {
            var timeout = (duration === undefined) ? 4000 : Number(duration);
            var close = toast.querySelector('.petron-toast-close, .flash-close');
            if (close) close.addEventListener('click', function() { dismissToast(toast); });
            if (timeout > 0) {
                setTimeout(function() { dismissToast(toast); }, timeout);
            }
        }

        function normalizeToastType(type) {
            type = String(type || 'success').toLowerCase();
            if (type === 'ok' || type === 'done') return 'success';
            if (type === 'err' || type === 'danger' || type === 'failed' || type === 'fail') return 'error';
            if (type === 'warn') return 'warning';
            if (type === 'information') return 'info';
            return ['success', 'error', 'warning', 'info'].indexOf(type) >= 0 ? type : 'success';
        }

        window.showToast = window.showToast || function(message, type, duration, title) {
            if (String(message == null ? '' : message).trim() === '') return;
            type = normalizeToastType(type);
            var container = document.getElementById('petron-toast-container');
            if (!container) return;
            container.style.display = 'flex'; // ensure visible when adding toast
            var toast = document.createElement('div');
            toast.className = 'petron-toast toast-' + type;
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<i class="fas ' + (icons[type] || icons.info) + ' petron-toast-icon"></i>' +
                '<span class="petron-toast-body">' +
                    '<strong class="petron-toast-title">' + escapeHtml(title || titles[type] || 'Information') + '</strong>' +
                    '<span class="petron-toast-message">' + escapeHtml(message) + '</span>' +
                '</span>' +
                '<button type="button" class="petron-toast-close" aria-label="Close notification">&times;</button>';
            container.appendChild(toast);
            armToast(toast, duration);
        };

        var sharedShowToast = window.showToast;
        window.showPetronFlash = window.showPetronFlash || function(message, type, duration) {
            sharedShowToast(message, type, duration);
        };

        if (typeof window.toast !== 'function') {
            window.toast = function(message, type) {
                window.showToast(message, type || 'info');
            };
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#petron-toast-container .petron-toast, .petron-flash').forEach(function(toast) {
                armToast(toast, 4500);
            });
        });
    })();
    </script>

    <!-- Page content starts here -->      
    <script>
        
    function updateClock() {
        const el = document.getElementById('live-clock');
        if (!el) return;
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        el.innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();

    // (diagnostic click tracker removed)

    // Initialize page data for notification system
    window.pageData = {
        role: '<?php echo htmlspecialchars($role); ?>',
        userId: '<?php echo htmlspecialchars($user['id'] ?? ''); ?>',
        stationId: '<?php echo htmlspecialchars($station_id ?? $myStationId ?? ''); ?>',
        appBasePath: '<?php echo htmlspecialchars($app_base_path ?? ""); ?>'
    };

    window.resolveRedirectUrl = function(url) {
        if (!url || url === '#' || url === '') return '#';
        const base = window.pageData && window.pageData.appBasePath ? window.pageData.appBasePath : '';
        // Already an absolute /public/ path
        if (url.startsWith('/public/')) {
            return base + url;
        }
        // Relative public/ path
        if (url.startsWith('public/')) {
            return base + '/' + url;
        }
        // Already a full URL (http/https)
        if (url.startsWith('http://') || url.startsWith('https://')) {
            return url;
        }
        // Bare filename (e.g. "manager_job_orders.php", "manager_reports.php?tab=foo")
        // â†’ resolve to /public/<filename>
        if (url.match(/^[a-zA-Z0-9_\-]+\.php/)) {
            return base + '/public/' + url;
        }
        return url;
    };


    // ── Global header action functions (called via onclick attributes) ──────────
    
    // FIXED: Ensure functions are immediately available and properly handle all edge cases
    
    window.petronToggleSidebar = function(e) {
        console.log('Sidebar toggle clicked'); // Debug
        if (e) {
            e.stopPropagation();
            e.preventDefault(); // Prevent any default behavior
        }
        
        // Debounce - prevent multiple rapid clicks
        var now = Date.now();
        if (window.__petronSidebarLastToggleAt && (now - window.__petronSidebarLastToggleAt) < 300) {
            console.log('Sidebar toggle debounced');
            return;
        }
        window.__petronSidebarLastToggleAt = now;
        
        // Close any open header dropdowns when toggling sidebar
        try{ if (typeof closeAllHeaderDropdowns === 'function') closeAllHeaderDropdowns(); }catch(e){}
        
        var s = document.getElementById('mainSidebar');
        var backdrop = document.getElementById('mobileSidebarBackdrop');
        var icon = document.getElementById('sidebarToggleIcon');
        var main = document.querySelector('.main');
        
        if (!s) {
            console.error('Sidebar element not found');
            return;
        }
        
        // Mobile toggle
        if (window.innerWidth < 992) {
            var isOpen = s.classList.contains('mobile-open');
            s.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('active');
            document.body.style.overflow = isOpen ? '' : 'hidden';
            console.log('Mobile sidebar toggled:', !isOpen ? 'open' : 'closed');
        } 
        // Desktop toggle
        else {
            var isCollapsed = s.classList.contains('collapsed');
            
            // Use requestAnimationFrame to prevent flickering
            requestAnimationFrame(function() {
                if (isCollapsed) {
                    // Expand sidebar
                    s.classList.remove('collapsed');
                    if (icon) icon.className = 'fas fa-bars';
                    if (main) { 
                        main.style.left = '250px'; 
                        main.classList.remove('sidebar-collapsed'); 
                    }
                    document.body.classList.add('sidebar-expanded');
                    document.body.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebarState', 'expanded');
                    console.log('Sidebar expanded');
                } else {
                    // Collapse sidebar
                    s.classList.add('collapsed');
                    if (icon) icon.className = 'fas fa-chevron-right';
                    if (main) { 
                        main.style.left = '70px'; 
                        main.classList.add('sidebar-collapsed'); 
                    }
                    document.body.classList.add('sidebar-collapsed');
                    document.body.classList.remove('sidebar-expanded');
                    localStorage.setItem('sidebarState', 'collapsed');
                    console.log('Sidebar collapsed');
                }
            });
        }
    };

    window.petronToggleNotif = function(e) {
        if (e && e.target && e.target.closest && e.target.closest('.notif-dropdown')) {
            return;
        }
        if (e) {
            if (e.preventDefault) e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
        }

        var now = Date.now();
        if (window.__petronNotifLastToggleAt && (now - window.__petronNotifLastToggleAt) < 250) {
            return;
        }
        window.__petronNotifLastToggleAt = now;

        var nd = document.getElementById('notificationDropdown');
        if (!nd) {
            console.error('Notification dropdown not found');
            return;
        }

        var wasShowing = nd.classList.contains('show') || nd.style.display === 'block';
        if (window.petronHeaderToggle) {
            window.petronHeaderToggle('notificationDropdown', '#notificationBell');
        } else {
            var pd = document.getElementById('profileDropdown');
            if (pd) { pd.classList.remove('show'); pd.style.display = 'none'; }
            nd.classList.toggle('show');
            nd.style.display = nd.classList.contains('show') ? 'block' : 'none';
        }

        var isShowing = !wasShowing && (nd.classList.contains('show') || nd.style.display === 'block');
        if (isShowing) {
            if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
            else if (typeof window.saLoadNotifications === 'function') window.saLoadNotifications();
        }
    };

    window.petronToggleProfile = function(e) {
        // Prefer shared toggle implementation when available
        if (window.petronHeaderToggle) {
            if (e && e.preventDefault) e.preventDefault();
            window.petronHeaderToggle('profileDropdown', '#profileMenu');
            return;
        }
        console.log('Profile menu clicked'); // Debug
        // If the click originates from an actionable link inside the dropdown, allow it
        if (e && e.target && e.target.closest && e.target.closest('.profile-dropdown')) {
            return; // allow link navigation
        }
        if (e) {
            e.stopPropagation();
        }
        var nd = document.getElementById('notificationDropdown');
        var pd = document.getElementById('profileDropdown');
        if (nd) { nd.classList.remove('show'); nd.style.display = 'none'; }
        if (!pd) {
            console.error('Profile dropdown not found');
            return;
        }
        pd.classList.toggle('show');
        pd.style.display = pd.classList.contains('show') ? 'block' : 'none';
        console.log('Profile dropdown is now:', pd.classList.contains('show') ? 'visible' : 'hidden');
    };

    window.petronToggleTheme = function(e) {
        console.log('Theme toggle clicked'); // Debug
        if (e) {
            e.stopPropagation();
            e.preventDefault(); // Prevent any default behavior
        }
        
        // Debounce - prevent multiple rapid clicks
        var now = Date.now();
        if (window.__petronThemeLastToggleAt && (now - window.__petronThemeLastToggleAt) < 300) {
            console.log('Theme toggle debounced');
            return;
        }
        window.__petronThemeLastToggleAt = now;
        
        // Close header dropdowns when switching theme
        try{ if (typeof closeAllHeaderDropdowns === 'function') closeAllHeaderDropdowns(); }catch(e){}
        
        var isDark = document.body.classList.contains('dark-theme');
        var icon = document.getElementById('themeIcon');
        var btn  = document.getElementById('themeToggle');
        
        // Use requestAnimationFrame to prevent flickering
        requestAnimationFrame(function() {
            if (isDark) {
                // Switch to Light Mode
                document.body.classList.remove('dark-theme');
                if (icon) icon.className = 'fas fa-moon';
                if (btn)  btn.title = 'Switch to Dark Mode';
                localStorage.setItem('petronTheme', 'light');
                console.log('Switched to Light Mode - saved to localStorage');
            } else {
                // Switch to Dark Mode
                document.body.classList.add('dark-theme');
                if (icon) icon.className = 'fas fa-sun';
                if (btn)  btn.title = 'Switch to Light Mode';
                localStorage.setItem('petronTheme', 'dark');
                console.log('Switched to Dark Mode - saved to localStorage');
            }
        });
    };

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        var nd = document.getElementById('notificationDropdown');
        var pd = document.getElementById('profileDropdown');
        var nb = document.getElementById('notificationBell');
        var pm = document.getElementById('profileMenu');
        var vd = document.getElementById('varianceAlertDropdown');
        var vb = document.getElementById('varianceAlertBell');
        if (nd && nb && !nb.contains(e.target) && !nd.contains(e.target)) nd.classList.remove('show');
        if (pd && pm && !pm.contains(e.target) && !pd.contains(e.target)) pd.classList.remove('show');
        if (vd && vb && !vb.contains(e.target)) vd.classList.remove('show');
    });

    // Apply saved theme immediately (BEFORE DOMContentLoaded to prevent flicker)
    (function() {
        var savedTheme = localStorage.getItem('petronTheme');
        console.log('Initializing theme from localStorage:', savedTheme);
        
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-theme');
            // Wait for elements to be available
            setTimeout(function() {
                var icon = document.getElementById('themeIcon');
                if (icon) icon.className = 'fas fa-sun';
                var btn = document.getElementById('themeToggle');
                if (btn) btn.title = 'Switch to Light Mode';
            }, 0);
        } else if (savedTheme === 'light') {
            document.body.classList.remove('dark-theme');
            setTimeout(function() {
                var icon = document.getElementById('themeIcon');
                if (icon) icon.className = 'fas fa-moon';
                var btn = document.getElementById('themeToggle');
                if (btn) btn.title = 'Switch to Dark Mode';
            }, 0);
        }
    })();

    // Init sidebar state + mobile backdrop on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Header initialized - adding event listeners');
        
        // ADD BACKUP EVENT LISTENERS FOR ALL HEADER BUTTONS
        // These will fire even if onclick attributes fail
        
        var sidebarBtn = document.getElementById('sidebarCollapseBtn');
        if (sidebarBtn) {
            sidebarBtn.addEventListener('click', function(e) {
                console.log('Sidebar button clicked (event listener)');
                petronToggleSidebar(e);
            });
        }
        
        var notifBell = document.getElementById('notificationBell');
        if (notifBell) {
            notifBell.addEventListener('click', function(e) {
                console.log('Notification bell clicked (event listener)');
                petronToggleNotif(e);
            });
        }

        var varianceBell = document.getElementById('varianceAlertBell');
        if (varianceBell) {
            varianceBell.addEventListener('click', function(e){
                try{ if (typeof toggleDropdown === 'function') { toggleDropdown('varianceAlertDropdown', '#varianceAlertBell'); if (e && e.preventDefault) e.preventDefault(); } }
                catch(err){ console.error('varianceBell handler err', err); }
            });
        }
        
        var themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function(e) {
                console.log('Theme toggle clicked (event listener)');
                petronToggleTheme(e);
            });
        }
        
        var profileMenu = document.getElementById('profileMenu');
        if (profileMenu) {
            profileMenu.addEventListener('click', function(e) {
                console.log('Profile menu clicked (event listener)');
                petronToggleProfile(e);
            });
        }
        
        // Restore sidebar state (desktop only)
        if (window.innerWidth >= 992) {
            var saved = localStorage.getItem('sidebarState');
            if (saved === 'collapsed') {
                var s    = document.getElementById('mainSidebar');
                var icon = document.getElementById('sidebarToggleIcon');
                var main = document.querySelector('.main');
                if (s) s.classList.add('collapsed');
                if (icon) icon.className = 'fas fa-chevron-right';
                if (main) { main.style.left = '70px'; main.classList.add('sidebar-collapsed'); }
                document.body.classList.add('sidebar-collapsed');
            }
        }
        // Mobile backdrop click
        var backdrop = document.getElementById('mobileSidebarBackdrop');
        if (backdrop) {
            backdrop.addEventListener('click', function() {
                var s = document.getElementById('mainSidebar');
                if (s) s.classList.remove('mobile-open');
                backdrop.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
        // Close mobile sidebar on resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992) {
                var s = document.getElementById('mainSidebar');
                var bd = document.getElementById('mobileSidebarBackdrop');
                if (s) s.classList.remove('mobile-open');
                if (bd) bd.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // --- FALLBACK: Capture-phase listener to ensure header icons respond even
        // if other event listeners or overlays interfere. This will call the
        // existing toggle functions but will NOT prevent default link navigation.
        document.addEventListener('click', function(e) {
            try {
                var c = e.target;
                var sb = c.closest && c.closest('#sidebarCollapseBtn, .sidebar-collapse-btn');
                if (sb) { petronToggleSidebar(e); return; }
                var nb = c.closest && c.closest('#notificationBell, .notification-bell');
                if (nb) { petronToggleNotif(e); return; }
                var tt = c.closest && c.closest('#themeToggle, .theme-toggle-btn');
                if (tt) { petronToggleTheme(e); return; }
                var pm = c.closest && c.closest('#profileMenu, .profile-access');
                if (pm) { petronToggleProfile(e); return; }
            } catch (err) {
                console.error('Header fallback listener error', err);
            }
        }, true); // use capture phase

        // Robust initializer: ensure header controls are interactive, remove duplicate
        // event listeners by cloning nodes, and attach single click handlers.
        function initHeaderControls() {
            try {
                const mapping = {
                    'sidebarCollapseBtn': window.petronToggleSidebar,
                    'notificationBell':   window.petronToggleNotif,
                    'themeToggle':        window.petronToggleTheme,
                    'profileMenu':        window.petronToggleProfile
                };

                Object.keys(mapping).forEach(function(id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    // Force styles so overlays don't block
                    el.style.pointerEvents = 'auto';
                    el.style.zIndex = '99999';
                    el.style.position = el.style.position || 'relative';

                    // Replace element with a shallow clone to remove previously attached listeners
                    const clone = el.cloneNode(true);
                    clone.removeAttribute('onclick');
                    el.parentNode.replaceChild(clone, el);

                    // Attach single click listener
                    clone.addEventListener('click', function(ev) {
                        try {
                            ev.stopPropagation();
                            // Do not call preventDefault to allow link navigation inside dropdowns
                            const fn = mapping[id];
                            if (typeof fn === 'function') fn(ev);
                        } catch (err) { console.error('Header control handler error', err); }
                    });
                });

                // Diagnostic: log topmost element when header area is clicked (helps find overlays)
                ['header-left','header-center','header-right','top-header'].forEach(function(cls) {
                    const container = document.querySelector('.' + cls);
                    if (!container) return;
                    container.addEventListener('click', function(ev) {
                        try {
                            const x = ev.clientX, y = ev.clientY;
                            const topEl = document.elementFromPoint(x, y);
                            if (topEl) console.log('Header click at', x, y, 'top element:', topEl.tagName, topEl.id || topEl.className);
                        } catch (err) {}
                    }, true);
                });
            } catch (e) { console.error('initHeaderControls failed', e); }
        }

        // Run initializer once DOM is ready (after other listeners are added)
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(initHeaderControls, 50);
        } else {
            document.addEventListener('DOMContentLoaded', function() { setTimeout(initHeaderControls, 50); });
        }

        // Capture-phase pointerdown to ensure toggles fire even when other code stops propagation
        document.addEventListener('pointerdown', function(ev) {
            try {
                const t = ev.target;
                if (!t) return;
                const sb = t.closest && t.closest('#sidebarCollapseBtn, .sidebar-collapse-btn');
                const nb = t.closest && t.closest('#notificationBell, .notification-bell');
                const tt = t.closest && t.closest('#themeToggle, .theme-toggle-btn');
                const pm = t.closest && t.closest('#profileMenu, .profile-access');
                if (sb) { console.log('Sidebar toggle clicked (pointerdown)'); ev.stopPropagation(); petronToggleSidebar(ev); }
                else if (nb) { console.log('Notification bell clicked (pointerdown)'); ev.stopPropagation(); petronToggleNotif(ev); }
                else if (tt) { console.log('Theme toggle clicked (pointerdown)'); ev.stopPropagation(); petronToggleTheme(ev); }
                else if (pm) { console.log('Profile menu clicked (pointerdown)'); ev.stopPropagation(); petronToggleProfile(ev); }
            } catch (err) { console.error('pointerdown listener error', err); }
        }, true);

        // Keyboard accessibility: Enter/Space activate controls
        document.addEventListener('keydown', function(ev) {
            try {
                if (ev.key !== 'Enter' && ev.key !== ' ') return;
                const t = ev.target;
                if (!t) return;
                if (t.id === 'sidebarCollapseBtn' || t.classList.contains('sidebar-collapse-btn')) { ev.preventDefault(); petronToggleSidebar(ev); }
                if (t.id === 'notificationBell' || t.classList.contains('notification-bell')) { ev.preventDefault(); petronToggleNotif(ev); }
                if (t.id === 'themeToggle' || t.classList.contains('theme-toggle-btn')) { ev.preventDefault(); petronToggleTheme(ev); }
                if (t.id === 'profileMenu' || t.classList.contains('profile-access')) { ev.preventDefault(); petronToggleProfile(ev); }
            } catch (err) {}
        }, false);

        // --- UNBLOCKER: Detect and disable any overlaying elements that cover the header
        function unblockHeaderOverlays() {
            try {
                const header = document.querySelector('.top-header');
                if (!header) return;
                const hr = header.getBoundingClientRect();
                // find potentially blocking elements
                const els = Array.from(document.body.children);
                const changed = [];
                function intersects(r1, r2) {
                    return !(r2.left > r1.right || r2.right < r1.left || r2.top > r1.bottom || r2.bottom < r1.top);
                }
                // Walk many elements to detect overlays (limit to first 500 to avoid perf issues)
                const all = Array.from(document.querySelectorAll('body *')).slice(0, 1000);
                all.forEach(function(el) {
                    if (!el || el === header || header.contains(el)) return;
                    const s = getComputedStyle(el);
                    if (s.display === 'none' || s.visibility === 'hidden' || s.pointerEvents === 'none') return;
                    const r = el.getBoundingClientRect();
                    if (r.width === 0 || r.height === 0) return;
                    // Only consider elements that are positioned and likely overlays
                    if (s.position === 'fixed' || s.position === 'absolute' || parseInt(s.zIndex) > 0) {
                        if (intersects(hr, r)) {
                            // mark and disable pointer events
                            if (!el.dataset._hdrUnblocked) {
                                el.dataset._hdrUnblocked = el.style.pointerEvents || '';
                                el.style.pointerEvents = 'none';
                                el.style.outline = '2px dashed rgba(255,0,0,0.12)';
                                changed.push(el);
                            }
                        }
                    }
                });
                if (changed.length) console.log('Unblocked header by disabling pointer-events on', changed.length, 'elements', changed);
            } catch (err) { console.error('unblockHeaderOverlays error', err); }
        }

        // Restore function (for debugging)
        window.restoreHeaderOverlays = function() {
            try {
                document.querySelectorAll('[data-_hdrUnblocked]').forEach(function(el){
                    el.style.pointerEvents = el.dataset._hdrUnblocked || '';
                    el.style.outline = '';
                    delete el.dataset._hdrUnblocked;
                });
                console.log('Header overlays restored');
            } catch (e) { console.error(e); }
        };

        // Run immediately and on resize; also observe mutations to catch dynamic overlays
        setTimeout(unblockHeaderOverlays, 100);
        window.addEventListener('resize', function(){ setTimeout(unblockHeaderOverlays, 50); });
        const mo = new MutationObserver(function(){ setTimeout(unblockHeaderOverlays, 30); });
        mo.observe(document.body, { childList: true, subtree: true });
        // Hash-based active sidebar link
        var hash = window.location.hash;
        if (hash) {
            document.querySelectorAll('.nav-item[href$="' + hash + '"]').forEach(function(el) {
                el.classList.add('active');
                var parent = el.closest('[id^="sub-"]');
                if (parent) parent.style.display = 'block';
            });
        }
        
        // Log success message
        console.log('Header navigation fully initialized and ready');
    });
        // ---- CAPTURE-PHASE HEADER CLICK HANDLER (removed - conflicts with normal handlers) ----
        (function() {
            function headerCaptureHandler(e) { return; /* disabled */
                var x = e.clientX, y = e.clientY;

                // Get ALL elements at click position (includes ones under overlays)
                var all = document.elementsFromPoint ? document.elementsFromPoint(x, y) : [];

                // Helper: check if any element in the stack matches a selector/id
                function inStack(id) {
                    var el = document.getElementById(id);
                    if (!el) return false;
                    var r = el.getBoundingClientRect();
                    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
                }

                // Notification bell
                if (inStack('notificationBell')) {
                    console.log('Notification bell clicked'); // Debug log
                    var nd = document.getElementById('notificationDropdown');
                    var pd = document.getElementById('profileDropdown');
                    if (pd) pd.classList.remove('show');
                    if (nd) {
                        nd.classList.toggle('show');
                        if (nd.classList.contains('show')) {
                            if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
                            else if (typeof window.saLoadNotifications === 'function') window.saLoadNotifications();
                        }
                    }
                    e.stopPropagation();
                    e.preventDefault();
                    return;
                }

                // Profile menu
                if (inStack('profileMenu') && !inStack('profileDropdown')) {
                    console.log('Profile menu clicked'); // Debug log
                    var nd2 = document.getElementById('notificationDropdown');
                    var pd2 = document.getElementById('profileDropdown');
                    if (nd2) nd2.classList.remove('show');
                    if (pd2) pd2.classList.toggle('show');
                    e.stopPropagation();
                    e.preventDefault();
                    return;
                }

                // Theme toggle
                if (inStack('themeToggle')) {
                    console.log('Theme toggle clicked'); // Debug log
                    var goingDark = !document.body.classList.contains('dark-theme');
                    if (goingDark) {
                        document.body.classList.add('dark-theme');
                        var ti = document.getElementById('themeIcon');
                        if (ti) ti.className = 'fas fa-sun';
                        localStorage.setItem('petronTheme', 'dark');
                    } else {
                        document.body.classList.remove('dark-theme');
                        var ti2 = document.getElementById('themeIcon');
                        if (ti2) ti2.className = 'fas fa-moon';
                        localStorage.setItem('petronTheme', 'light');
                    }
                    e.stopPropagation();
                    e.preventDefault();
                    return;
                }

                // Sidebar collapse button
                if (inStack('sidebarCollapseBtn')) {
                    console.log('Sidebar collapse clicked'); // Debug log
                    if (typeof toggleSidebar === 'function') toggleSidebar();
                    e.stopPropagation();
                    e.preventDefault();
                    return;
                }

                // Close dropdowns on outside click
                var nb = document.getElementById('notificationBell');
                var pf = document.getElementById('profileMenu');
                if (nb && nb.getBoundingClientRect && !inStack('notificationBell')) {
                    var nd3 = document.getElementById('notificationDropdown');
                    if (nd3 && !inStack('notificationDropdown')) nd3.classList.remove('show');
                }
                if (pf && pf.getBoundingClientRect && !inStack('profileMenu') && !inStack('profileDropdown')) {
                    var pd3 = document.getElementById('profileDropdown');
                    if (pd3) pd3.classList.remove('show');
                }
            }

            document.addEventListener('click', headerCaptureHandler, true);
        })();
        // ---- END CAPTURE-PHASE HEADER CLICK HANDLER ----



                
                
        
        // â”€â”€ Global Search Autocomplete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        (function () {
            const searchInput       = document.getElementById('searchInput');
            const searchSuggestions = document.getElementById('searchSuggestions');
            if (!searchInput || !searchSuggestions) return;

            // Icon + colour per result type (mirrors search.php $ICONS / $COLORS)
            const TYPE_META = {
                'Transaction'   : { icon: 'fas fa-shopping-cart',   color: '#3b82f6' },
                'Customer'      : { icon: 'fas fa-user',             color: '#10b981' },
                'Product'       : { icon: 'fas fa-box',              color: '#f59e0b' },
                'Job Order'     : { icon: 'fas fa-wrench',           color: '#8b5cf6' },
                'Delivery'      : { icon: 'fas fa-truck',            color: '#ef4444' },
                'Calendar'      : { icon: 'fas fa-calendar-alt',     color: '#06b6d4' },
                'Report'        : { icon: 'fas fa-chart-bar',        color: '#64748b' },
                'Station'       : { icon: 'fas fa-gas-pump',         color: '#002F6C' },
                'Admin'         : { icon: 'fas fa-user-shield',      color: '#7c3aed' },
                'System Log'    : { icon: 'fas fa-server',           color: '#dc2626' },
                'Security'      : { icon: 'fas fa-shield-alt',       color: '#b91c1c' },
                'Audit Trail'   : { icon: 'fas fa-history',          color: '#0891b2' },
                'Product Mgmt'  : { icon: 'fas fa-tags',             color: '#e11d48' },
                'Fuel Management': { icon: 'fas fa-gas-pump',        color: '#f97316' },
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
                    '<i class="fas fa-spinner fa-spin"></i> Searchingâ€¦</div>';
                searchSuggestions.style.display = 'block';

                debounceTimer = setTimeout(() => {
                    const _searchBase = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
                    fetch(_searchBase + '/public/search.php?q=' + encodeURIComponent(query) + '&ajax=1')
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
                                        'transition:background .12s;cursor:pointer;';
                                    row.onmouseenter = () => row.style.background = '#f8fafc';
                                    row.onmouseleave = () => row.style.background = '';
                                    
                                    // Add click handler to ensure navigation works
                                    row.onclick = function(e) {
                                        e.preventDefault();
                                        if (item.link && item.link !== '#') {
                                            window.location.href = window.resolveRedirectUrl(item.link);
                                        }
                                    };
                                    
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
                            const _sb = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
                            footer.href = _sb + '/public/search.php?q=' + encodeURIComponent(query);
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

        // â”€â”€ Notification System â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // SuperAdmin/Developer: real system-level alerts from DB via AJAX.
        // Manager: deeper approval/oversight notifications.
        // Staff/Admin: operational notifications.
        <?php
        $is_superadmin_role = in_array($role, ['superadmin', 'developer']);
        $is_admin_role      = ($role === 'admin');
        $is_manager_role    = ($role === 'manager');
        $notif_generator    = $is_superadmin_role
            ? '/backend/api/superadmin_notification_generator.php'
            : ($is_admin_role
                ? '/backend/api/admin_notification_generator.php'
                : ($is_manager_role
                    ? '/backend/api/manager_notification_generator.php'
                    : '/backend/api/staff_notification_generator.php'));
        ?>

        <?php if ($is_superadmin_role): ?>
        // â”€â”€ SuperAdmin: Real DB-driven notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        (function () {
            'use strict';

            const BASE_PATH = (window.pageData && window.pageData.appBasePath)
                ? window.pageData.appBasePath.replace(/\/$/, '')
                : '';
            const resolveApiPath = (path) => {
                if (!path) return path;
                if (path.startsWith('http://') || path.startsWith('https://')) return path;
                return BASE_PATH ? BASE_PATH + path : path;
            };
            const API_LIST = resolveApiPath('/backend/api/notifications_api.php');
            const API_GEN  = resolveApiPath('/backend/api/superadmin_notification_generator.php');

            // Severity â†’ colour mapping
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
                    // Escape quotes in URL to prevent breaking the onclick attribute
                    const safeUrl = url.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    return `<div class="sa-notif-item${unread ? ' unread' : ''}"
                                 style="padding:12px 14px;border-bottom:1px solid #f0f0f0;cursor:pointer;background:${unread ? '#fff9f0' : '#fff'};transition:background .15s;"
                                 onclick="saMarkRead(${n.id}, '${safeUrl}')"
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
                if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loadingâ€¦</div>';
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
                    if (data.success) {
                        // Use bell_unread_count for the bell badge (actual unread notifications)
                        // unread_count is the sidebar action badge count (separate)
                        const bellCount = (typeof data.bell_unread_count !== 'undefined')
                            ? data.bell_unread_count
                            : (data.unread_count || 0);
                        updateBadge(bellCount);
                    }
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
                    await fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd, credentials: 'same-origin' });
                } catch (e) {}
                
                // Navigate to the URL after marking as read
                if (url && url !== '#' && url.trim() !== '') {
                    // Add a small delay to ensure the mark-read completes
                    setTimeout(function() {
                        window.location.href = window.resolveRedirectUrl(url);
                    }, 100);
                } else {
                    loadNotifications();
                }
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
        // â”€â”€ Staff / Manager / Admin: Dynamic DB-driven notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€
        (function () {
            'use strict';

            const BASE_PATH = (window.pageData && window.pageData.appBasePath)
                ? window.pageData.appBasePath.replace(/\/$/, '')
                : '';
            const resolveApiPath = (path) => {
                if (!path) return path;
                if (path.startsWith('http://') || path.startsWith('https://')) return path;
                return BASE_PATH ? BASE_PATH + path : path;
            };
            const API_LIST = resolveApiPath('/backend/api/notifications_api.php');
            const API_GEN  = resolveApiPath('<?php echo $notif_generator; ?>');

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

            // Event type â†’ icon mapping
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

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (ch) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
                });
            }

            function escapeJsString(value) {
                return String(value || '')
                    .replace(/\\/g, '\\\\')
                    .replace(/'/g, "\\'")
                    .replace(/\r?\n/g, ' ');
            }

            function isNotificationDropdownOpen() {
                const nd = document.getElementById('notificationDropdown');
                return !!(nd && (nd.classList.contains('show') || nd.style.display === 'block'));
            }

            function notificationListNeedsRefresh() {
                const el = document.getElementById('notificationList');
                if (!el) return false;
                const text = (el.textContent || '').trim();
                return !text || text.indexOf('Loading notifications') !== -1 || text.indexOf('Loading') !== -1 || text.indexOf('Could not load') !== -1;
            }

            function timeAgo(dateStr) {
                if (!dateStr) return '';
                const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
                if (diff < 60)    return diff + 's ago';
                if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            function updateBadge(count, categoryCounts) {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
                if (categoryCounts) {
                    updateSidebarDrawerBadges(categoryCounts);
                }
            }

            function updateSidebarDrawerBadges(categoryCounts) {
                if (!categoryCounts) return;
                const map = {
                    'transactions': categoryCounts.transactions || 0,
                    'fuel':         categoryCounts.fuel || 0,
                    'inventory':    categoryCounts.inventory || 0,
                    'customers':    categoryCounts.customers || 0
                };
                for (const [key, cnt] of Object.entries(map)) {
                    const els = document.querySelectorAll(`[data-sidebar-badge="${key}"]`);
                    els.forEach(el => {
                        if (cnt > 0) {
                            el.textContent = cnt > 99 ? '99+' : cnt;
                            el.style.display = 'flex';
                        } else {
                            el.style.display = 'none';
                        }
                    });
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
                    const res  = await fetch(API_LIST + '?action=list&limit=15&status=all', {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    clearTimeout(tid);
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();

                    if (data.success && data.notifications && data.notifications.length > 0) {
                        let html = '';
                        data.notifications.forEach(function (n) {
                            const icon   = EVT_ICON[n.event_type] || EVT_ICON.general;
                            const color  = TYPE_COLOR[n.type]     || '#17a2b8';
                            const unread = n.status === 'unread';
                            const bg     = unread ? 'rgba(0,47,108,0.04)' : 'transparent';
                            const url    = escapeJsString(n.redirect_url || '');
                            const title  = escapeHtml(n.title || 'Notification');
                            const msg    = escapeHtml(n.message || '');
                            const ago    = escapeHtml(n.time_ago || timeAgo(n.created_at));
                            // Facebook-like notification styling
                            const hoverClass = unread ? 'notif-item unread' : 'notif-item';
                            html += `<div class="${hoverClass}" style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none;"
                                          onclick="staffMarkRead(${n.id},'${url}')">
                                        <div style="width:48px;height:48px;border-radius:50%;background:${color}15;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid ${color}30;">
                                            <i class="${icon}" style="color:${color};font-size:20px;"></i>
                                        </div>
                                        <div style="flex:1;min-width:0;line-height:1.3;">
                                            <div style="font-size:14px;color:#050505;margin-bottom:2px;">
                                                <strong style="font-weight:600;">${title}</strong>
                                            </div>
                                            <div style="color:#65676B;font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                                ${msg}
                                            </div>
                                            <div style="color:${unread ? '#002F6C' : '#65676B'};font-size:12px;font-weight:${unread ? '600' : 'normal'};margin-top:4px;">
                                                ${ago}
                                            </div>
                                        </div>
                                        ${unread ? '<div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:20px;"></div>' : ''}
                                    </div>`;
                        });
                        el.innerHTML = html;
                        updateBadge(data.unread_count || 0, data.category_counts);
                    } else if (data.success) {
                        el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                        updateBadge(0, data.category_counts);
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
                    const res  = await fetch(API_LIST + '?action=unread_count', {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    clearTimeout(tid);
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.success) {
                        const bellCount = (typeof data.bell_unread_count !== 'undefined')
                            ? data.bell_unread_count
                            : (data.unread_count || 0);
                        updateBadge(bellCount, data.category_counts);
                    }
                } catch (e) {}
            }

            // ── Run generator silently in background (fire-and-forget) ────────
            function runGeneratorBackground() {
                const ctrl = new AbortController();
                const tid = setTimeout(() => ctrl.abort(), 8000);
                return fetch(API_GEN, {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    })
                    .then(r => {
                        clearTimeout(tid);
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(d => {
                        fetchUnreadCount();
                        if ((d.ok && d.generated > 0) || isNotificationDropdownOpen() || notificationListNeedsRefresh()) {
                            loadNotifications();
                        }
                    })
                    .catch(() => {
                        clearTimeout(tid);
                        if (notificationListNeedsRefresh()) loadNotifications();
                    });
            }

            // ── Mark one notification as read ─────────────────────────────────
            window.staffMarkRead = async function (id, url) {
                try {
                    const fd = new FormData();
                    fd.append('notification_id', id);
                    const res = await fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json();
                    if (data && data.success) {
                        updateBadge(data.bell_unread_count || 0, data.category_counts);
                    }
                } catch (e) {}
                if (url && url !== '#' && url !== '') {
                    window.location.href = window.resolveRedirectUrl(url);
                } else {
                    loadNotifications();
                }
            };

            // ── Mark all read ──────────────────────────────────────────────────
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    try {
                        const res = await fetch(API_LIST + '?action=mark_all_read', { method: 'POST', credentials: 'same-origin' });
                        const data = await res.json();
                        if (data && data.success) {
                            updateBadge(0, data.category_counts);
                        }
                    } catch (e) {}
                    loadNotifications();
                });
            }

            // â”€â”€ Refresh button â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            const refreshBtn = document.getElementById('refreshNotificationsBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    await runGeneratorBackground();
                    loadNotifications();
                });
            }

            // Expose globally for the toggle listener
            window.loadStaffNotifications = loadNotifications;
            window.petronLoadNotifications = loadNotifications;

            // â”€â”€ On page load: fetch count immediately, run generator after 2s â”€
            loadNotifications();
            fetchUnreadCount();
            setTimeout(runGeneratorBackground, 800);

            // â”€â”€ Poll: count every 60s, generator every 5 min â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            setInterval(fetchUnreadCount, 60000);
            setInterval(runGeneratorBackground, 300000);

        })();
        <?php endif; ?>
    </script>

    <script>
    /* â”€â”€ GLOBAL: Sidebar Sub-menu Toggle â”€â”€ */
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

        // Close all other open sub-menus (accordion â€” no overlap)
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
