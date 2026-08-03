<?php

// ============================================================
// SuperAdmin – Station Management
// public/superadmin_station_management.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'station_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php'); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Philippine Regions (DB-driven) ───────────────────────────
// Auto-creates and seeds the `ph_regions` table on first run.
$ph_regions = [];
try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS ph_regions (
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name     VARCHAR(120) NOT NULL UNIQUE,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default regions if table is empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM ph_regions")->fetchColumn();
    if ($count === 0) {
        $seed = [
            [1,  'NCR – National Capital Region'],
            [2,  'CAR – Cordillera Administrative Region'],
            [3,  'Region I – Ilocos Region'],
            [4,  'Region II – Cagayan Valley'],
            [5,  'Region III – Central Luzon'],
            [6,  'Region IV-A – CALABARZON'],
            [7,  'Region IV-B – MIMAROPA'],
            [8,  'Region V – Bicol Region'],
            [9,  'Region VI – Western Visayas'],
            [10, 'Region VII – Central Visayas'],
            [11, 'Region VIII – Eastern Visayas'],
            [12, 'Region IX – Zamboanga Peninsula'],
            [13, 'Region X – Northern Mindanao'],
            [14, 'Region XI – Davao Region'],
            [15, 'Region XII – SOCCSKSARGEN'],
            [16, 'Region XIII – Caraga'],
            [17, 'BARMM – Bangsamoro Autonomous Region'],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO ph_regions (sort_order, name) VALUES (?, ?)");
        foreach ($seed as [$sort, $name]) {
            $ins->execute([$sort, $name]);
        }
    }

    $ph_regions = $pdo->query("SELECT name FROM ph_regions ORDER BY sort_order, name")
                      ->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Fallback to static list if DB fails
    $ph_regions = [
        'NCR – National Capital Region',
        'CAR – Cordillera Administrative Region',
        'Region I – Ilocos Region',
        'Region II – Cagayan Valley',
        'Region III – Central Luzon',
        'Region IV-A – CALABARZON',
        'Region IV-B – MIMAROPA',
        'Region V – Bicol Region',
        'Region VI – Western Visayas',
        'Region VII – Central Visayas',
        'Region VIII – Eastern Visayas',
        'Region IX – Zamboanga Peninsula',
        'Region X – Northern Mindanao',
        'Region XI – Davao Region',
        'Region XII – SOCCSKSARGEN',
        'Region XIII – Caraga',
        'BARMM – Bangsamoro Autonomous Region',
    ];
}

// ── Fetch stations with admin info ────────────────────────────
$stations = [];
try {
    $stations = $pdo->query(
        "SELECT s.id, s.name, s.location, s.status, s.created_at,
                (SELECT u.name  FROM users u WHERE u.station_id = s.id AND LOWER(u.role) IN ('admin','station admin','station_admin') AND u.status = 'Active' LIMIT 1) AS admin_name,
                (SELECT u.id    FROM users u WHERE u.station_id = s.id AND LOWER(u.role) IN ('admin','station admin','station_admin') AND u.status = 'Active' LIMIT 1) AS admin_id,
                (SELECT u.email FROM users u WHERE u.station_id = s.id AND LOWER(u.role) IN ('admin','station admin','station_admin') AND u.status = 'Active' LIMIT 1) AS admin_email,
                (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'Active') AS active_users
         FROM stations s
         ORDER BY s.name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch fuel types ──────────────────────────────────────────
$fuel_types = [];
try {
    $fuel_types = $pdo->query("SELECT `user_id`, name FROM fuel_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch unassigned admins (for reassign dropdown) ───────────
$all_admins = [];
try {
    $all_admins = $pdo->query(
        "SELECT id, name, email, station_id FROM users
         WHERE LOWER(role) IN ('admin','station admin','station_admin') AND status = 'Active'
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Helper: parse structured location ────────────────────────
function parse_location(string $loc): array {
    if (empty($loc)) return ['region'=>'','province'=>'','city'=>'','barangay'=>'','street'=>''];
    if (strpos($loc, '||') !== false) {
        [$region, $province, $city, $barangay, $street] = array_pad(explode('||', $loc), 5, '');
        return compact('region','province','city','barangay','street');
    }
    // Legacy "Region | Address"
    $pipe = strpos($loc, ' | ');
    if ($pipe !== false) {
        return ['region'=>trim(substr($loc,0,$pipe)),'province'=>'','city'=>'','barangay'=>'','street'=>trim(substr($loc,$pipe+3))];
    }
    return ['region'=>'','province'=>'','city'=>'','barangay'=>'','street'=>$loc];
}

// ── Helper: extract display parts from raw station name ───────
// Many existing stations store full address in the name field:
// "STREET, CITY, PROVINCE REGION STATION_TYPE"
// Returns: [short_name, city_province, region]
function extract_station_display(string $raw_name, string $location): array {
    // If location is already structured (new format), use it
    if (!empty($location)) {
        $loc = parse_location($location);
        $city_prov = trim(implode(', ', array_filter([$loc['city'], $loc['province']])));
        return [
            'name'       => $raw_name,
            'street'     => $loc['street'],
            'city_prov'  => $city_prov,
            'region'     => $loc['region'],
        ];
    }

    // Legacy: parse from the name field itself
    // Pattern: "STREET_ADDRESS, CITY, PROVINCE REGION [STATION_TYPE]"
    $parts = array_map('trim', explode(',', $raw_name));
    $count = count($parts);

    if ($count === 1) {
        return ['name'=>$raw_name,'street'=>'','city_prov'=>'','region'=>''];
    }

    // Last part often contains region + station type
    $last = $parts[$count - 1];
    $region = '';
    $station_type = '';

    // Known PH region keywords
    $region_keywords = ['NCR','CAR','REGION','LUZON','VISAYAS','MINDANAO','BARMM','MIMAROPA','CALABARZON','SOCCSKSARGEN','CARAGA'];
    foreach ($region_keywords as $kw) {
        if (stripos($last, $kw) !== false) {
            // Try to split station type from region
            if (preg_match('/^(.*?)\s+(SERVICE STATION|FUEL STATION|CAR CARE CENTER|TREATS STORE|STATION)\s*$/i', $last, $m)) {
                $region       = trim($m[1]);
                $station_type = trim($m[2]);
            } else {
                $region = trim($last);
            }
            break;
        }
    }

    if ($count === 2) {
        return ['name'=>$raw_name,'street'=>$parts[0],'city_prov'=>$parts[1],'region'=>$region];
    }
    if ($count === 3) {
        return ['name'=>$raw_name,'street'=>$parts[0],'city_prov'=>$parts[1],'region'=>$region ?: $parts[2]];
    }

    // 4+ parts: street = everything before last 2, city_prov = last 2 before region
    $street   = implode(', ', array_slice($parts, 0, $count - 2));
    $city_prov = $parts[$count - 2];
    return ['name'=>$raw_name,'street'=>$street,'city_prov'=>$city_prov,'region'=>$region ?: $parts[$count-1]];
}
$total_stations  = count($stations);
$active_stations = count(array_filter($stations, fn($s) => strtolower($s['status']) === 'active'));
$with_admin      = count(array_filter($stations, fn($s) => !empty($s['admin_id'])));
$no_admin        = $total_stations - $with_admin;

$flash = $_SESSION['sm_flash'] ?? null;
unset($_SESSION['sm_flash']);

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Station Management Styles (sm- prefix) ── */
.sm-page { padding: 12px 24px 28px; }
.sm-page-head { margin-bottom: 24px; padding-top: 10px; display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 0 !important; }
.sm-page-head h1 { font-size: 22px !important; font-weight: 700 !important; color: var(--petron-blue) !important; margin: 0 !important; text-transform: uppercase !important; }
.sm-page-head .sub { font-size: 13px; color: #666; margin-top: 4px; text-transform: none !important; }

/* Stats */
.sm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px, 1fr)); gap: 14px; margin-bottom: 24px; }
.sm-stat-card { background: #fff; border: 1px solid #eaeaea; border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.sm-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.sm-stat-icon.blue  { background: rgba(0,38,77,.1);   color: var(--petron-blue); }
.sm-stat-icon.green { background: rgba(40,167,69,.1);  color: #28a745; }
.sm-stat-icon.amber { background: rgba(255,193,7,.15); color: #b8860b; }
.sm-stat-icon.red   { background: rgba(204,0,0,.1);    color: #cc0000; }
.sm-stat-val { font-size: 26px; font-weight: 800; color: var(--petron-blue); line-height: 1; }
.sm-stat-lbl { font-size: 12px; color: #666; margin-top: 2px; }

/* Toolbar */
.sm-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.sm-toolbar input, .sm-toolbar select { padding: 9px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; background: #fff; outline: none; }
.sm-toolbar input:focus, .sm-toolbar select:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.sm-toolbar input { width: 240px; }
.sm-toolbar-right { margin-left: auto; }

/* Table */
.sm-table-wrap { background: #fff; border: 1px solid #eaeaea; border-radius: 16px; overflow-x: auto; overflow-y: visible; -webkit-overflow-scrolling: touch; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
.sm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sm-table thead th { background: var(--petron-blue); color: #fff; padding: 13px 16px; text-align: left; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
.sm-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .15s; }
.sm-table tbody tr:last-child { border-bottom: none; }
.sm-table tbody tr:hover { background: #f8fafc; }
.sm-table td { padding: 13px 16px; vertical-align: middle; }
.sm-table td .sname { font-weight: 600; color: #1a1a1a; }
.sm-table td .sloc  { font-size: 12px; color: #888; margin-top: 2px; }

/* Sticky Actions column */
.sm-table thead th.col-actions,
.sm-table tbody td.col-actions {
    position: sticky;
    right: 0;
    z-index: 3;
    white-space: nowrap;
    text-align: center;
}
.sm-table thead th.col-actions {
    background: var(--petron-blue);
    box-shadow: -3px 0 8px rgba(0,0,0,.12);
}
.sm-table tbody td.col-actions {
    background: #fff;
    box-shadow: -3px 0 8px rgba(0,0,0,.06);
}
.sm-table tbody tr:hover td.col-actions { background: #f8fafc; }

/* Badges */
.badge-active   { background: rgba(40,167,69,.12); color: #1a7a35; border: 1px solid rgba(40,167,69,.25); padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-inactive { background: rgba(204,0,0,.1);    color: #cc0000; border: 1px solid rgba(204,0,0,.2);    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-no-admin { background: rgba(255,193,7,.15); color: #b8860b; border: 1px solid rgba(255,193,7,.3);  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }

/* Buttons */
.sm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all .2s; text-decoration: none; background: none; }
.sm-btn-primary  { background: var(--petron-blue); color: #fff; border-color: var(--petron-blue); }
.sm-btn-primary:hover  { background: #001a3d; }
.sm-btn-edit     { color: var(--petron-blue); border-color: var(--petron-blue); }
.sm-btn-edit:hover     { background: rgba(0,38,77,.06); }
.sm-btn-assign   { color: #28a745; border-color: #28a745; }
.sm-btn-assign:hover   { background: rgba(40,167,69,.06); }
.sm-btn-deact    { color: #cc0000; border-color: #cc0000; }
.sm-btn-deact:hover    { background: rgba(204,0,0,.06); }
.sm-btn-activate { color: #28a745; border-color: #28a745; }
.sm-btn-activate:hover { background: rgba(40,167,69,.06); }

/* Modal */
.sm-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9000;
    align-items: flex-start;
    justify-content: center;
    padding: 24px 12px;
    overflow-y: auto;
}
.sm-modal-overlay.open { display: flex; }
.sm-modal {
    background: #fff;
    border-radius: 20px;
    width: min(580px, 100%);
    /* No max-height on the modal itself — let the overlay scroll */
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: smSlide .25s ease;
    margin: auto; /* vertical centering when content is short */
}
.sm-modal.narrow { width: min(440px, 100%); }
@keyframes smSlide { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
/* Sticky header */
.sm-modal-header {
    padding: 22px 24px 16px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
    border-radius: 20px 20px 0 0;
}
.sm-modal-header h2 { font-size: 17px !important; font-weight: 700 !important; color: var(--petron-blue) !important; margin: 0 !important; text-transform: uppercase !important; }
.sm-modal-close { background: none; border: none; font-size: 20px; color: #999; cursor: pointer; padding: 4px 8px; border-radius: 6px; }
.sm-modal-close:hover { background: #f0f0f0; color: #333; }
/* Scrollable body */
.sm-modal-body { padding: 22px 24px; flex: 1 1 auto; }
/* Sticky footer */
.sm-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    background: #fff;
    z-index: 2;
    border-radius: 0 0 20px 20px;
}

/* Form */
.sm-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.sm-form-row.full { grid-template-columns: 1fr; }
.sm-form-group { display: flex; flex-direction: column; gap: 5px; }
.sm-form-group label { font-size: 12px; font-weight: 600; color: #444; text-transform: uppercase; letter-spacing: .3px; }
.sm-form-group input, .sm-form-group select, .sm-form-group textarea { padding: 10px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .2s; font-family: inherit; }
.sm-form-group input:focus, .sm-form-group select:focus, .sm-form-group textarea:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.sm-form-hint { font-size: 11px; color: #888; margin-top: 2px; }

/* Fuel type checkboxes */
.sm-fuel-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.sm-fuel-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid #ddd; border-radius: 20px; font-size: 12px; cursor: pointer; transition: all .15s; user-select: none; }
.sm-fuel-chip input { display: none; }
.sm-fuel-chip.checked { background: var(--petron-blue); color: #fff; border-color: var(--petron-blue); }
.sm-fuel-chip:hover { border-color: var(--petron-blue); }

/* Flash */
.sm-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.sm-flash.success { background: rgba(40,167,69,.1); border: 1px solid rgba(40,167,69,.3); color: #1a7a35; }
.sm-flash.error   { background: rgba(204,0,0,.08);  border: 1px solid rgba(204,0,0,.25);  color: #cc0000; }

/* Empty */
.sm-empty { text-align: center; padding: 60px 20px; color: #999; }
.sm-empty i { font-size: 40px; margin-bottom: 12px; opacity: .4; display: block; }

/* Confirm */
.sm-confirm-body { padding: 28px 24px; text-align: center; }
.sm-confirm-body i { font-size: 44px; margin-bottom: 14px; display: block; }
.sm-confirm-body p { font-size: 15px; color: #333; margin: 0 0 6px; }
.sm-confirm-body .sub { font-size: 13px; color: #888; }

/* ── Configure Station Tabs ── */
.cfg-tabs {
    display: flex;
    border-bottom: 2px solid #eee;
    padding: 0 24px;
    gap: 2px;
    background: #fafafa;
    flex-shrink: 0;
    position: sticky;
    top: 73px; /* below the sticky modal header */
    z-index: 1;
    overflow:hidden;
    scrollbar-width: none;
}
.cfg-tabs::-webkit-scrollbar { display: none; }
.cfg-tab { background: none; border: none; border-bottom: 3px solid transparent; padding: 13px 16px; font-size: 13px; font-weight: 600; color: #888; cursor: pointer; display: flex; align-items: center; gap: 7px; margin-bottom: -2px; transition: all .2s; white-space: nowrap; }
.cfg-tab i { font-size: 13px; }
.cfg-tab:hover { color: var(--petron-blue); }
.cfg-tab.active { color: var(--petron-blue); border-bottom-color: var(--petron-blue); background: none; }
.cfg-panel { display: none; }
.cfg-panel.active { display: block; }
.cfg-panel-inner { padding: 22px 24px; }
.cfg-section-head { display: flex; align-items: flex-start; gap: 14px; padding: 14px 16px; background: rgba(0,38,77,.04); border-radius: 12px; border-left: 4px solid var(--petron-blue); }
.cfg-section-head > i { font-size: 20px; color: var(--petron-blue); margin-top: 2px; flex-shrink: 0; }
.cfg-section-title { font-size: 14px; font-weight: 700; color: var(--petron-blue); text-transform: uppercase; letter-spacing: .3px; }
.cfg-section-desc  { font-size: 12px; color: #666; margin-top: 3px; line-height: 1.5; text-transform: none !important; }
.cfg-info-box { background: #f8fafc; border: 1px solid #e8edf2; border-radius: 10px; padding: 11px 14px; font-size: 12px; color: #555; display: flex; align-items: flex-start; gap: 8px; }
.cfg-info-box i { color: var(--petron-blue); flex-shrink: 0; margin-top: 1px; }
/* Merchandise catalog rows */
.merch-row { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-bottom: 1px solid #f5f5f5; font-size: 13px; }
.merch-row:last-child { border-bottom: none; }
.merch-row:hover { background: #f8fafc; }
.merch-row .merch-name { flex: 1; font-weight: 500; }
.merch-row .merch-cat  { font-size: 11px; color: #aaa; margin-left: 4px; }
.merch-row .merch-price { font-size: 12px; color: #555; width: 80px; text-align: right; }
.merch-row .merch-add  { background: var(--petron-blue); color: #fff; border: none; border-radius: 6px; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.merch-row .merch-add:hover { background: #001a3d; }
.merch-row .merch-remove { background: none; color: #cc0000; border: 1px solid #cc0000; border-radius: 6px; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.merch-row .merch-remove:hover { background: rgba(204,0,0,.06); }
.merch-row .merch-price-input { width: 80px; padding: 4px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; text-align: right; outline: none; }
.merch-row .merch-price-input:focus { border-color: var(--petron-blue); }

/* Searchable combo (reuse am-combo styles, add sm-combo alias) */
.sm-combo { position: relative; }
.sm-combo-input { width: 100%; padding: 10px 36px 10px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .2s; background: #fff; box-sizing: border-box; cursor: text; }
.sm-combo-input:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.sm-combo-input.has-value { border-color: var(--petron-blue); }
.sm-combo-arrow { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: #999; font-size: 12px; pointer-events: none; transition: transform .2s; }
.sm-combo.open .sm-combo-arrow { transform: translateY(-50%) rotate(180deg); }
.sm-combo-clear { position: absolute; right: 30px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 13px; cursor: pointer; display: none; background: none; border: none; padding: 2px 4px; line-height: 1; }
.sm-combo-clear:hover { color: #cc0000; }
.sm-combo-dropdown { display: none; position: fixed; background: #fff; border: 1px solid #ddd; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 99999; max-height: 220px; overflow: hidden; flex-direction: column; }
.sm-combo.open .sm-combo-dropdown { display: flex; }
.sm-combo-search { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.sm-combo-search i { color: #bbb; font-size: 13px; }
.sm-combo-search input { border: none; outline: none; font-size: 13px; flex: 1; background: transparent; }
.sm-combo-list { overflow-y: auto; flex: 1; }
.sm-combo-option { padding: 10px 14px; font-size: 13px; cursor: pointer; transition: background .12s; display: flex; align-items: center; gap: 8px; }
.sm-combo-option:hover, .sm-combo-option.focused { background: #f0f5ff; color: var(--petron-blue); }
.sm-combo-option.selected { background: rgba(0,38,77,.08); font-weight: 600; color: var(--petron-blue); }
.sm-combo-option .opt-icon { color: #bbb; font-size: 11px; flex-shrink: 0; }
.sm-combo-option.selected .opt-icon { color: var(--petron-blue); }
.sm-combo-empty { padding: 18px 14px; font-size: 13px; color: #bbb; text-align: center; }

@media (max-width: 640px) {
    .sm-form-row { grid-template-columns: 1fr; }
    .sm-toolbar input { width: 100%; }
    .sm-modal-overlay { padding: 12px 8px; }
    .cfg-tabs { padding: 0 12px; }
    .cfg-tab { padding: 10px 10px; font-size: 12px; }
}

/* Footer and toggle scroll button styles are provided by partials/footer.php */
</style>

<div class="sm-page">

<?php if ($flash): ?>
<div class="sm-flash <?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($flash['msg']); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="sm-page-head">
    <div>
        <h1><i class="fas fa-building" style="margin-right:8px;"></i>Station Management</h1>
        <div class="sub">Register, configure, and manage all stations nationwide. Assign and reassign Admin accounts.</div>
    </div>
    <button class="sm-btn sm-btn-primary" onclick="openRegisterModal()">
        <i class="fas fa-plus"></i> Register New Station
    </button>
</div>

<!-- Stats -->
<div class="sm-stats">
    <div class="sm-stat-card">
        <div class="sm-stat-icon blue"><i class="fas fa-building"></i></div>
        <div><div class="sm-stat-val"><?php echo $total_stations; ?></div><div class="sm-stat-lbl">Total Stations</div></div>
    </div>
    <div class="sm-stat-card">
        <div class="sm-stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div><div class="sm-stat-val"><?php echo $active_stations; ?></div><div class="sm-stat-lbl">Active</div></div>
    </div>
    <div class="sm-stat-card">
        <div class="sm-stat-icon amber"><i class="fas fa-user-shield"></i></div>
        <div><div class="sm-stat-val"><?php echo $with_admin; ?></div><div class="sm-stat-lbl">With Admin</div></div>
    </div>
    <div class="sm-stat-card">
        <div class="sm-stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="sm-stat-val"><?php echo $no_admin; ?></div><div class="sm-stat-lbl">No Admin</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="sm-toolbar">
    <input type="text" id="smSearch" placeholder="Search station name or location…" oninput="smFilter()">
    <select id="smFilterStatus" onchange="smFilter()">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
    <select id="smFilterRegion" onchange="smFilter()">
        <option value="">All Regions</option>
        <?php foreach ($ph_regions as $r): ?>
        <option value="<?php echo strtolower(htmlspecialchars($r)); ?>"><?php echo htmlspecialchars($r); ?></option>
        <?php endforeach; ?>
    </select>
    <select id="smFilterAdmin" onchange="smFilter()">
        <option value="">All Admin Status</option>
        <option value="yes">Has Admin</option>
        <option value="no">No Admin</option>
    </select>
    <div class="sm-toolbar-right">
        <span id="smRowCount" style="font-size:12px;color:#888;"></span>
    </div>
</div>

<!-- Table -->
<div class="sm-table-wrap">
    <table class="sm-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Station ID</th>
                <th>Station</th>
                <th>Location</th>
                <th>Assigned Admin</th>
                <th>Status</th>
                <th>Users</th>
                <th>Registered</th>
                <th style="text-align:center;" class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody id="smTableBody">
        <?php if (empty($stations)): ?>
            <tr><td colspan="9"><div class="sm-empty"><i class="fas fa-building"></i>No stations registered yet.</div></td></tr>
        <?php else: ?>
        <?php foreach ($stations as $i => $st):
            $loc  = parse_location($st['location'] ?? '');
            $disp = extract_station_display($st['name'], $st['location'] ?? '');
        ?>
        <tr data-name="<?php echo strtolower(htmlspecialchars($st['name'])); ?>"
            data-loc="<?php echo strtolower(htmlspecialchars($st['location'] ?? $st['name'])); ?>"
            data-region="<?php echo strtolower(htmlspecialchars($disp['region'] ?: $loc['region'])); ?>"
            data-city="<?php echo strtolower(htmlspecialchars($disp['city_prov'] ?: $loc['city'])); ?>"
            data-status="<?php echo strtolower($st['status']); ?>"
            data-hasadmin="<?php echo $st['admin_id'] ? 'yes' : 'no'; ?>">
            <td style="color:#999;font-size:12px;"><?php echo $i + 1; ?></td>

            <!-- Station ID -->
            <td style="text-align:center;">
                <div style="display:inline-flex;align-items:center;gap:4px;background:#f0f4f8;border:1px solid #dde3ea;border-radius:6px;padding:4px 10px;">
                    <i class="fas fa-hashtag" style="font-size:9px;color:#888;"></i>
                    <span style="font-size:12px;color:#555;font-weight:700;font-family:monospace;"><?php echo str_pad($st['id'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </td>

            <!-- Station Name -->
            <td>
                <div class="sname" style="font-size:13px;font-weight:700;color:#1a1a1a;line-height:1.3;">
                    <?php echo htmlspecialchars($st['name']); ?>
                </div>
            </td>

            <!-- Location (parsed) -->
            <td style="max-width:200px;">
                <?php if ($disp['street']): ?>
                <div style="font-size:12px;color:#333;font-weight:500;line-height:1.3;"><?php echo htmlspecialchars($disp['street']); ?></div>
                <?php endif; ?>
                <?php if ($disp['city_prov']): ?>
                <div style="font-size:12px;color:#555;margin-top:2px;"><?php echo htmlspecialchars($disp['city_prov']); ?></div>
                <?php endif; ?>
                <?php if ($disp['region']): ?>
                <div style="font-size:11px;color:#aaa;margin-top:2px;"><?php echo htmlspecialchars($disp['region']); ?></div>
                <?php endif; ?>
                <?php if (!$disp['street'] && !$disp['city_prov'] && !$disp['region']): ?>
                <span style="color:#bbb;font-size:12px;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($st['admin_id']): ?>
                <div style="font-size:13px;font-weight:600;"><?php echo htmlspecialchars($st['admin_name']); ?></div>
                <div style="font-size:11px;color:#888;"><?php echo htmlspecialchars($st['admin_email'] ?? ''); ?></div>
                <?php else: ?>
                <span class="badge-no-admin"><i class="fas fa-exclamation-triangle" style="font-size:9px;"></i> Unassigned</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (strtolower($st['status']) === 'active'): ?>
                <span class="badge-active"><i class="fas fa-circle" style="font-size:7px;"></i> Active</span>
                <?php else: ?>
                <span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;"></i> Inactive</span>
                <?php endif; ?>
            </td>
            <td style="font-size:13px;color:#555;"><?php echo (int)$st['active_users']; ?></td>
            <td style="font-size:12px;color:#888;"><?php echo $st['created_at'] ? date('M d, Y', strtotime($st['created_at'])) : '—'; ?></td>
            <td style="text-align:center;white-space:nowrap;" class="col-actions">
                <button class="sm-btn sm-btn-view" onclick="openProfileModal(<?php echo (int)$st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>')" title="View Profile" style="background:#fff;color:#6f42c1;border-color:#6f42c1;">
                    <i class="fas fa-eye"></i> Profile
                </button>
                <button class="sm-btn sm-btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($st)); ?>)" title="Edit">
                    <i class="fas fa-pen"></i> Edit
                </button>
                <button class="sm-btn sm-btn-assign" onclick="openAssignModal(<?php echo (int)$st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>', <?php echo $st['admin_id'] ? (int)$st['admin_id'] : 'null'; ?>)" title="Assign Admin">
                    <i class="fas fa-user-shield"></i> Assign
                </button>
                <?php if (strtolower($st['status']) === 'active'): ?>
                <button class="sm-btn sm-btn-deact" onclick="confirmStatus(<?php echo (int)$st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>', 'deactivate')" title="Deactivate">
                    <i class="fas fa-ban"></i>
                </button>
                <?php else: ?>
                <button class="sm-btn sm-btn-activate" onclick="confirmStatus(<?php echo (int)$st['id']; ?>, '<?php echo htmlspecialchars(addslashes($st['name'])); ?>', 'activate')" title="Activate">
                    <i class="fas fa-check-circle"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</div><!-- /.sm-page -->

<!-- ══ STATION PROFILE MODAL ═══════════════════════════════════ -->
<div class="sm-modal-overlay" id="profileModal">
  <div class="sm-modal" style="width:min(720px,97vw);max-height:90vh;overflow-y:auto;">
    <div class="sm-modal-header">
      <div>
        <h2 style="margin-bottom:2px !important;"><i class="fas fa-building" style="margin-right:8px;"></i>Station Profile</h2>
        <div id="p_station_subtitle" style="font-size:12px;color:#888;font-weight:400;text-transform:none !important;"></div>
      </div>
      <button class="sm-modal-close" onclick="closeModal('profileModal')">&times;</button>
    </div>
    <div class="sm-modal-body" id="profileModalBody">
      <div style="text-align:center;padding:40px;color:#bbb;">
        <i class="fas fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>
        Loading station profile…
      </div>
    </div>
    <div class="sm-modal-footer">
      <button type="button" class="sm-btn" style="border-color:#ddd;" onclick="closeModal('profileModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ REGISTER MODAL ══════════════════════════════════════════ -->
<div class="sm-modal-overlay" id="registerModal">
  <div class="sm-modal" style="width:min(660px,97vw);">
    <div class="sm-modal-header">
      <h2><i class="fas fa-plus-circle" style="margin-right:8px;"></i>Register New Station</h2>
      <button class="sm-modal-close" onclick="closeModal('registerModal')">&times;</button>
    </div>
    <form id="registerForm" onsubmit="submitRegister(event)">
      <div class="sm-modal-body">
        <div id="registerAlert" class="sm-flash error" style="display:none;"></div>

        <!-- Station Name -->
        <div class="sm-form-row full" style="margin-bottom:6px;">
          <div class="sm-form-group">
            <label>Station Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="name" id="r_name" placeholder="e.g. Petron Cebu North – Bacalso" required>
            <span class="sm-form-hint">Use a unique, descriptive name that identifies this specific station. Avoid duplicates.</span>
          </div>
        </div>

        <!-- Location divider -->
        <div style="display:flex;align-items:center;gap:10px;margin:14px 0 10px;">
          <div style="flex:1;height:1px;background:#eee;"></div>
          <span style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;"><i class="fas fa-map-marker-alt" style="margin-right:4px;"></i>Location Details</span>
          <div style="flex:1;height:1px;background:#eee;"></div>
        </div>

        <div class="sm-form-row">
          <div class="sm-form-group">
            <label>Region <span style="color:#cc0000;">*</span></label>
            <select name="region" id="r_region" onchange="updateRegisterPreview()" required>
              <option value="">— Select Region —</option>
              <?php foreach ($ph_regions as $r): ?>
              <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm-form-group">
            <label>Province</label>
            <input type="text" name="province" id="r_province" placeholder="e.g. Cebu" oninput="updateRegisterPreview()">
          </div>
        </div>
        <div class="sm-form-row">
          <div class="sm-form-group">
            <label>City / Municipality <span style="color:#cc0000;">*</span></label>
            <input type="text" name="city" id="r_city" placeholder="e.g. Cebu City" required oninput="updateRegisterPreview()">
          </div>
          <div class="sm-form-group">
            <label>Barangay</label>
            <input type="text" name="barangay" id="r_barangay" placeholder="e.g. Brgy. Punta Princesa" oninput="updateRegisterPreview()">
          </div>
        </div>
        <div class="sm-form-row full">
          <div class="sm-form-group">
            <label>Street Address</label>
            <input type="text" name="street" id="r_street" placeholder="e.g. N. Bacalso Ave." oninput="updateRegisterPreview()">
          </div>
        </div>
        <div class="sm-form-row full">
          <div class="sm-form-group">
            <label>Location Preview</label>
            <div id="r_location_preview" style="padding:10px 13px;background:#f8fafc;border:1px solid #eee;border-radius:10px;font-size:12px;color:#555;min-height:36px;line-height:1.5;"></div>
            <span class="sm-form-hint">This is how the full location will be stored and displayed in reports.</span>
          </div>
        </div>

        <!-- Fuel types divider -->
        <div style="display:flex;align-items:center;gap:10px;margin:14px 0 10px;">
          <div style="flex:1;height:1px;background:#eee;"></div>
          <span style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;"><i class="fas fa-gas-pump" style="margin-right:4px;"></i>Fuel Types</span>
          <div style="flex:1;height:1px;background:#eee;"></div>
        </div>

        <div class="sm-fuel-grid" id="r_fuel_grid">
          <?php foreach ($fuel_types as $ft): ?>
          <label class="sm-fuel-chip" id="r_chip_<?php echo $ft['id']; ?>">
            <input type="checkbox" name="fuel_types[]" value="<?php echo $ft['id']; ?>">
            <i class="fas fa-gas-pump" style="font-size:11px;"></i>
            <?php echo htmlspecialchars($ft['name']); ?>
          </label>
          <?php endforeach; ?>
          <?php if (empty($fuel_types)): ?>
          <span style="font-size:12px;color:#aaa;">No fuel types configured yet.</span>
          <?php endif; ?>
        </div>

        <div style="background:#f8fafc;border:1px solid #e8edf2;border-radius:10px;padding:12px 16px;font-size:12px;color:#555;margin-top:14px;">
          <i class="fas fa-info-circle" style="color:var(--petron-blue);margin-right:6px;"></i>
          Station ID will be auto-generated. The station will be ready for Admin assignment after creation.
        </div>
      </div>
      <div class="sm-modal-footer">
        <button type="button" class="sm-btn" style="border-color:#ddd;" onclick="closeModal('registerModal')">Cancel</button>
        <button type="submit" class="sm-btn sm-btn-primary" id="registerSubmitBtn">
          <i class="fas fa-plus-circle"></i> Register Station
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ CONFIGURE STATION MODAL (4 tabs) ═══════════════════════ -->
<div class="sm-modal-overlay" id="editModal">
  <div class="sm-modal" style="width:min(680px,97vw);">
    <div class="sm-modal-header">
      <div>
        <h2 style="margin-bottom:2px !important;"><i class="fas fa-cog" style="margin-right:8px;"></i>Configure Station</h2>
        <div id="e_station_subtitle" style="font-size:12px;color:#888;font-weight:400;text-transform:none !important;"></div>
      </div>
      <button class="sm-modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>

    <!-- Tab nav -->
    <div class="cfg-tabs">
      <button type="button" class="cfg-tab active" data-tab="cfg-name"    onclick="switchCfgTab('cfg-name')">   <i class="fas fa-tag"></i>       Station Info</button>
      <button type="button" class="cfg-tab"        data-tab="cfg-location" onclick="switchCfgTab('cfg-location')"><i class="fas fa-map-marker-alt"></i> Location</button>
      <button type="button" class="cfg-tab"        data-tab="cfg-fuel"     onclick="switchCfgTab('cfg-fuel')">   <i class="fas fa-gas-pump"></i>   Fuel Types</button>
      <button type="button" class="cfg-tab"        data-tab="cfg-merch"    onclick="switchCfgTab('cfg-merch')">  <i class="fas fa-boxes"></i>      Merchandise</button>
    </div>

    <div id="editAlert" class="sm-flash error" style="display:none;margin:12px 24px 0;"></div>

    <!-- ── Tab 1: Station Info ── -->
    <div class="cfg-panel active" id="cfg-name">
      <div class="cfg-panel-inner">
        <div class="cfg-section-head">
          <i class="fas fa-tag"></i>
          <div>
            <div class="cfg-section-title">Station Name</div>
            <div class="cfg-section-desc">Rename the station for franchise rebranding or spelling corrections. Nationwide reports use this name.</div>
          </div>
        </div>
        <div class="sm-form-row full" style="margin-top:16px;">
          <div class="sm-form-group">
            <label>Station Name <span style="color:#cc0000;">*</span></label>
            <input type="text" id="e_name" placeholder="e.g. Petron Cebu North" required>
          </div>
        </div>
        <div class="sm-form-row full">
          <div class="sm-form-group">
            <label>Account Status</label>
            <select id="e_status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="cfg-info-box">
          <i class="fas fa-info-circle"></i>
          Station ID is auto-generated and cannot be changed. Name changes are logged in the Audit Trail.
        </div>
      </div>
    </div>

    <!-- ── Tab 2: Location ── -->
    <div class="cfg-panel" id="cfg-location">
      <div class="cfg-panel-inner">
        <div class="cfg-section-head">
          <i class="fas fa-map-marker-alt"></i>
          <div>
            <div class="cfg-section-title">Location Details</div>
            <div class="cfg-section-desc">Update address and region for accurate geographic data in reports and audit trails. Each field is stored separately for filtering across 1,413+ stations.</div>
          </div>
        </div>
        <div class="sm-form-row" style="margin-top:16px;">
          <div class="sm-form-group">
            <label>Region <span style="color:#cc0000;">*</span></label>
            <select id="e_region" onchange="updateLocationPreview()">
              <option value="">— Select Region —</option>
              <?php foreach ($ph_regions as $r): ?>
              <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm-form-group">
            <label>Province</label>
            <input type="text" id="e_province" placeholder="e.g. Cebu" oninput="updateLocationPreview()">
          </div>
        </div>
        <div class="sm-form-row">
          <div class="sm-form-group">
            <label>City / Municipality <span style="color:#cc0000;">*</span></label>
            <input type="text" id="e_city" placeholder="e.g. Cebu City" oninput="updateLocationPreview()">
          </div>
          <div class="sm-form-group">
            <label>Barangay</label>
            <input type="text" id="e_barangay" placeholder="e.g. Brgy. Punta Princesa" oninput="updateLocationPreview()">
          </div>
        </div>
        <div class="sm-form-row full">
          <div class="sm-form-group">
            <label>Street Address</label>
            <input type="text" id="e_street" placeholder="e.g. N. Bacalso Ave." oninput="updateLocationPreview()">
          </div>
        </div>
        <div class="sm-form-row full">
          <div class="sm-form-group">
            <label>Location Preview</label>
            <div id="e_location_preview" style="padding:10px 13px;background:#f8fafc;border:1px solid #eee;border-radius:10px;font-size:12px;color:#555;min-height:36px;line-height:1.5;"></div>
            <span class="sm-form-hint">This is how the full location will be stored and displayed in reports.</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab 3: Fuel Types ── -->
    <div class="cfg-panel" id="cfg-fuel">
      <div class="cfg-panel-inner">
        <div class="cfg-section-head">
          <i class="fas fa-gas-pump"></i>
          <div>
            <div class="cfg-section-title">Fuel Types</div>
            <div class="cfg-section-desc">Add or remove fuel categories for this station. Not all stations carry the same fuel offerings.</div>
          </div>
        </div>
        <div style="margin-top:16px;">
          <?php if (empty($fuel_types)): ?>
          <div style="text-align:center;padding:30px;color:#bbb;font-size:13px;">
            <i class="fas fa-gas-pump" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
            No fuel types configured in the system yet.
          </div>
          <?php else: ?>
          <div class="sm-fuel-grid" id="e_fuel_grid">
            <?php foreach ($fuel_types as $ft): ?>
            <label class="sm-fuel-chip" id="e_chip_<?php echo $ft['id']; ?>">
              <input type="checkbox" class="e_fuel_cb" value="<?php echo $ft['id']; ?>" data-name="<?php echo htmlspecialchars($ft['name']); ?>">
              <i class="fas fa-gas-pump" style="font-size:11px;"></i>
              <?php echo htmlspecialchars($ft['name']); ?>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="cfg-info-box" style="margin-top:14px;">
            <i class="fas fa-info-circle"></i>
            Selecting a fuel type seeds an inventory row for this station. Removing a type does <strong>not</strong> delete existing stock records — it only stops new seeding.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Tab 4: Merchandise Catalog ── -->
    <div class="cfg-panel" id="cfg-merch">
      <div class="cfg-panel-inner">
        <div class="cfg-section-head">
          <i class="fas fa-boxes"></i>
          <div>
            <div class="cfg-section-title">Merchandise Catalog</div>
            <div class="cfg-section-desc">Manage which products from the global catalog are active for this station. Pricing references can be adjusted per station.</div>
          </div>
        </div>

        <!-- Catalog search + add -->
        <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
          <input type="text" id="e_merch_search" placeholder="Search global catalog…" style="flex:1;padding:9px 13px;border:1px solid #ddd;border-radius:10px;font-size:13px;outline:none;" oninput="searchMerchCatalog()">
          <select id="e_merch_cat_filter" onchange="searchMerchCatalog()" style="padding:9px 13px;border:1px solid #ddd;border-radius:10px;font-size:13px;outline:none;background:#fff;">
            <option value="">All Categories</option>
          </select>
        </div>

        <!-- Catalog results -->
        <div id="e_merch_catalog_list" style="margin-top:10px;max-height:200px;overflow-y:auto;border:1px solid #eee;border-radius:10px;">
          <div style="padding:20px;text-align:center;color:#bbb;font-size:13px;">
            <i class="fas fa-search" style="display:block;font-size:22px;margin-bottom:6px;opacity:.3;"></i>
            Type to search the global product catalog.
          </div>
        </div>

        <!-- Station's active merchandise -->
        <div style="margin-top:16px;">
          <div style="font-size:12px;font-weight:700;color:#444;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">
            Active for This Station <span id="e_merch_count" style="background:var(--petron-blue);color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:6px;">0</span>
          </div>
          <div id="e_merch_active_list" style="max-height:160px;overflow-y:auto;border:1px solid #eee;border-radius:10px;">
            <div style="padding:16px;text-align:center;color:#bbb;font-size:12px;">Loading…</div>
          </div>
        </div>

        <div class="cfg-info-box" style="margin-top:12px;">
          <i class="fas fa-info-circle"></i>
          Changes here update <code>station_inventory</code> for this station only. The global catalog (<code>inventory_products</code>) is not modified.
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="sm-modal-footer">
      <button type="button" class="sm-btn" style="border-color:#ddd;" onclick="closeModal('editModal')">Cancel</button>
      <button type="button" class="sm-btn sm-btn-primary" id="editSubmitBtn" onclick="submitConfigure()">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- ══ ASSIGN ADMIN MODAL ══════════════════════════════════════ -->
<div class="sm-modal-overlay" id="assignModal">
  <div class="sm-modal narrow">
    <div class="sm-modal-header">
      <h2><i class="fas fa-user-shield" style="margin-right:8px;"></i>Assign / Reassign Admin</h2>
      <button class="sm-modal-close" onclick="closeModal('assignModal')">&times;</button>
    </div>
    <form id="assignForm" onsubmit="submitAssign(event)">
      <input type="hidden" name="station_id" id="a_station_id">
      <div class="sm-modal-body">
        <div id="assignAlert" class="sm-flash error" style="display:none;"></div>
        <p id="a_station_label" style="font-size:13px;color:#555;margin:0 0 16px;"></p>

        <div class="sm-form-group">
          <label>Select Admin to Assign <span style="color:#cc0000;">*</span></label>
          <!-- Searchable combo for admin -->
          <div class="sm-combo" id="a_admin_combo">
            <input type="text" class="sm-combo-input" id="a_admin_display" placeholder="Type to search admin…" autocomplete="off" readonly>
            <button type="button" class="sm-combo-clear" id="a_admin_clear" tabindex="-1"><i class="fas fa-times"></i></button>
            <i class="fas fa-chevron-down sm-combo-arrow"></i>
            <input type="hidden" name="admin_id" id="a_admin_id" required>
            <div class="sm-combo-dropdown" id="a_admin_dropdown">
              <div class="sm-combo-search">
                <i class="fas fa-search"></i>
                <input type="text" id="a_admin_search" placeholder="Search admin name or email…" autocomplete="off">
              </div>
              <div class="sm-combo-list" id="a_admin_list">
                <div class="sm-combo-option" data-value="" data-label="— Select Admin —" style="color:#bbb;font-style:italic;">— Select Admin —</div>
                <?php foreach ($all_admins as $adm): ?>
                <div class="sm-combo-option"
                     data-value="<?php echo (int)$adm['id']; ?>"
                     data-label="<?php echo htmlspecialchars($adm['name']); ?>"
                     data-station="<?php echo (int)($adm['station_id'] ?? 0); ?>">
                  <i class="fas fa-user opt-icon"></i>
                  <span>
                    <?php echo htmlspecialchars($adm['name']); ?>
                    <span style="color:#aaa;font-size:11px;margin-left:4px;"><?php echo htmlspecialchars($adm['email'] ?? ''); ?></span>
                    <?php if ($adm['station_id']): ?>
                    <span style="color:#e07b00;font-size:10px;margin-left:4px;">(currently assigned)</span>
                    <?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <span class="sm-form-hint">If the selected admin is currently assigned to another station, they will be transferred. The audit trail is preserved.</span>
        </div>
      </div>
      <div class="sm-modal-footer">
        <button type="button" class="sm-btn" style="border-color:#ddd;" onclick="closeModal('assignModal')">Cancel</button>
        <button type="submit" class="sm-btn sm-btn-primary" id="assignSubmitBtn">
          <i class="fas fa-user-shield"></i> Assign Admin
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ CONFIRM STATUS MODAL ════════════════════════════════════ -->
<div class="sm-modal-overlay" id="confirmModal">
  <div class="sm-modal narrow">
    <div class="sm-modal-header">
      <h2 id="confirmTitle">Confirm Action</h2>
      <button class="sm-modal-close" onclick="closeModal('confirmModal')">&times;</button>
    </div>
    <div class="sm-confirm-body">
      <i id="confirmIcon" class="fas fa-ban" style="color:#cc0000;"></i>
      <p id="confirmMsg">Are you sure?</p>
      <p class="sub" id="confirmSub"></p>
    </div>
    <div class="sm-modal-footer">
      <button type="button" class="sm-btn" style="border-color:#ddd;" onclick="closeModal('confirmModal')">Cancel</button>
      <button type="button" class="sm-btn" id="confirmActionBtn" onclick="executeStatus()">Confirm</button>
    </div>
  </div>
</div>

<script>
// ══ Searchable Combo (sm-combo) ══════════════════════════════
function initSmCombo(comboId, searchId, listId, displayId, hiddenId, clearId, onChange) {
    const combo    = document.getElementById(comboId);
    const search   = document.getElementById(searchId);
    const list     = document.getElementById(listId);
    const display  = document.getElementById(displayId);
    const hidden   = document.getElementById(hiddenId);
    const clear    = document.getElementById(clearId);
    const dropdown = combo ? combo.querySelector('.sm-combo-dropdown') : null;
    if (!combo || !dropdown) return;

    function positionDropdown() {
        const rect = display.getBoundingClientRect();
        dropdown.style.top    = (rect.bottom + 4) + 'px';
        dropdown.style.left   = rect.left + 'px';
        dropdown.style.width  = rect.width + 'px';
    }

    function openCombo()  {
        combo.classList.add('open');
        positionDropdown();
        search.value = '';
        filterOpts('');
        search.focus();
    }
    function closeCombo() { combo.classList.remove('open'); }

    function selectOpt(value, label) {
        hidden.value  = value;
        display.value = value ? label : '';
        display.classList.toggle('has-value', !!value);
        clear.style.display = value ? 'block' : 'none';
        list.querySelectorAll('.sm-combo-option').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        closeCombo();
        if (typeof onChange === 'function') onChange(value, label);
    }

    function filterOpts(q) {
        const lq = q.toLowerCase().trim();
        let any = false;
        list.querySelectorAll('.sm-combo-option').forEach(o => {
            if (!o.dataset.value) { o.style.display = lq ? 'none' : ''; return; }
            const match = !lq || (o.dataset.label || '').toLowerCase().includes(lq) || (o.textContent || '').toLowerCase().includes(lq);
            o.style.display = match ? '' : 'none';
            if (match) any = true;
        });
        let empty = list.querySelector('.sm-combo-empty');
        if (!any && lq) {
            if (!empty) { empty = document.createElement('div'); empty.className = 'sm-combo-empty'; list.appendChild(empty); }
            empty.textContent = `No match for "${q}"`;
            empty.style.display = '';
        } else if (empty) { empty.style.display = 'none'; }
    }

    display.addEventListener('click', () => combo.classList.contains('open') ? closeCombo() : openCombo());
    // Reposition on scroll/resize
    window.addEventListener('scroll', () => { if (combo.classList.contains('open')) positionDropdown(); }, true);
    window.addEventListener('resize', () => { if (combo.classList.contains('open')) positionDropdown(); });
    search.addEventListener('input', () => filterOpts(search.value));
    search.addEventListener('keydown', e => {
        const vis = [...list.querySelectorAll('.sm-combo-option[data-value]:not([style*="display: none"])')];
        const foc = list.querySelector('.sm-combo-option.focused');
        let idx = foc ? vis.indexOf(foc) : -1;
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, vis.length - 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); }
        else if (e.key === 'Enter' && foc) { e.preventDefault(); selectOpt(foc.dataset.value, foc.dataset.label); return; }
        else if (e.key === 'Escape') { closeCombo(); return; }
        list.querySelectorAll('.sm-combo-option').forEach(o => o.classList.remove('focused'));
        if (vis[idx]) { vis[idx].classList.add('focused'); vis[idx].scrollIntoView({ block: 'nearest' }); }
    });
    list.addEventListener('click', e => {
        const opt = e.target.closest('.sm-combo-option');
        if (opt) selectOpt(opt.dataset.value, opt.dataset.label);
    });
    clear.addEventListener('click', e => { e.stopPropagation(); selectOpt('', ''); });
    document.addEventListener('click', e => { if (!combo.contains(e.target)) closeCombo(); });

    combo._setValue = (v, l) => selectOpt(v, l);
    combo._reset    = () => selectOpt('', '');
}

// ══ Fuel chip toggle ══════════════════════════════════════════
document.addEventListener('change', e => {
    if (e.target.type === 'checkbox' && e.target.closest('.sm-fuel-chip')) {
        e.target.closest('.sm-fuel-chip').classList.toggle('checked', e.target.checked);
    }
});

// ══ Table filter ══════════════════════════════════════════════
function smFilter() {
    const q       = document.getElementById('smSearch').value.toLowerCase().trim();
    const status  = document.getElementById('smFilterStatus').value.toLowerCase();
    const region  = document.getElementById('smFilterRegion').value.toLowerCase();
    const admin   = document.getElementById('smFilterAdmin').value.toLowerCase();
    const rows    = document.querySelectorAll('#smTableBody tr[data-name]');
    let visible   = 0;
    rows.forEach(row => {
        const name     = row.dataset.name     || '';
        const loc      = row.dataset.loc      || '';
        const city     = row.dataset.city     || '';
        const rowReg   = row.dataset.region   || '';
        const rowStat  = row.dataset.status   || '';
        const hasAdmin = row.dataset.hasadmin || '';
        const show = (!q      || name.includes(q) || loc.includes(q) || city.includes(q) || rowReg.includes(q))
                  && (!status || rowStat === status)
                  && (!region || rowReg.includes(region))
                  && (!admin  || hasAdmin === admin);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const total = rows.length;
    document.getElementById('smRowCount').textContent = visible === total
        ? `Showing all ${total} station${total !== 1 ? 's' : ''}`
        : `Showing ${visible} of ${total}`;
}

// ══ Modal helpers ═════════════════════════════════════════════
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.sm-modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// ══ Register modal ════════════════════════════════════════════
function openRegisterModal() {
    document.getElementById('registerForm').reset();
    document.getElementById('registerAlert').style.display = 'none';
    document.querySelectorAll('#r_fuel_grid .sm-fuel-chip').forEach(c => c.classList.remove('checked'));
    openModal('registerModal');
}

async function submitRegister(e) {
    e.preventDefault();
    const btn   = document.getElementById('registerSubmitBtn');
    const alert = document.getElementById('registerAlert');
    alert.style.display = 'none';
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering…';

    const fd = new FormData(document.getElementById('registerForm'));
    fd.append('action', 'register_station');
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeModal('registerModal');
            showFlash('success', data.message || 'Station registered successfully.');
            setTimeout(() => location.reload(), 1200);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed.');
            alert.style.display = 'flex';
        }
    } catch { alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error.'; alert.style.display = 'flex'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus-circle"></i> Register Station';
}

// ══ Configure Station (4-tab modal) ══════════════════════════
let _cfgStationId = null;

function switchCfgTab(tabId) {
    document.querySelectorAll('.cfg-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.cfg-panel').forEach(p => p.classList.toggle('active', p.id === tabId));
    if (tabId === 'cfg-merch' && _cfgStationId) loadMerchActive(_cfgStationId);
}

function openEditModal(st) {
    _cfgStationId = st.id;
    document.getElementById('editAlert').style.display = 'none';
    document.getElementById('e_station_subtitle').textContent = `Station ID: ${st.id}`;

    // Tab 1 – Info
    document.getElementById('e_name').value   = st.name   || '';
    document.getElementById('e_status').value = st.status || 'active';

    // Tab 2 – Location (parse structured or legacy format)
    const locParsed = parseLocation(st.location || '');
    document.getElementById('e_region').value   = locParsed.region;
    document.getElementById('e_province').value = locParsed.province;
    document.getElementById('e_city').value     = locParsed.city;
    document.getElementById('e_barangay').value = locParsed.barangay;
    document.getElementById('e_street').value   = locParsed.street;
    updateLocationPreview();

    // Tab 3 – Fuel: load active fuel types for this station
    document.querySelectorAll('.e_fuel_cb').forEach(cb => { cb.checked = false; cb.closest('.sm-fuel-chip').classList.remove('checked'); });
    loadStationFuelTypes(st.id);

    // Tab 4 – Merch: reset
    document.getElementById('e_merch_search').value = '';
    document.getElementById('e_merch_catalog_list').innerHTML = '<div style="padding:20px;text-align:center;color:#bbb;font-size:13px;"><i class="fas fa-search" style="display:block;font-size:22px;margin-bottom:6px;opacity:.3;"></i>Type to search the global product catalog.</div>';
    document.getElementById('e_merch_active_list').innerHTML  = '<div style="padding:16px;text-align:center;color:#bbb;font-size:12px;">Loading…</div>';
    document.getElementById('e_merch_count').textContent = '0';

    // Reset to first tab
    switchCfgTab('cfg-name');
    openModal('editModal');
}

// ── Location helpers ──────────────────────────────────────────
// Structured location format: "Region||Province||City||Barangay||Street"
// Legacy format (pipe-separated): "Region | Address" — we parse both

function parseLocation(loc) {
    if (!loc) return { region:'', province:'', city:'', barangay:'', street:'' };
    // New structured format
    if (loc.includes('||')) {
        const [region='', province='', city='', barangay='', street=''] = loc.split('||').map(s => s.trim());
        return { region, province, city, barangay, street };
    }
    // Legacy "Region | Address" format
    const pipe = loc.indexOf(' | ');
    if (pipe !== -1) {
        return { region: loc.substring(0, pipe).trim(), province:'', city:'', barangay:'', street: loc.substring(pipe + 3).trim() };
    }
    return { region:'', province:'', city:'', barangay:'', street: loc };
}

function buildLocationString(region, province, city, barangay, street) {
    return [region, province, city, barangay, street].map(s => (s||'').trim()).join('||');
}

function buildLocationPreview(region, province, city, barangay, street) {
    return [street, barangay, city, province, region].filter(Boolean).join(', ') || '<span style="color:#bbb;">—</span>';
}

function updateLocationPreview() {
    const r = (document.getElementById('e_region')?.value   || '').trim();
    const p = (document.getElementById('e_province')?.value || '').trim();
    const c = (document.getElementById('e_city')?.value     || '').trim();
    const b = (document.getElementById('e_barangay')?.value || '').trim();
    const s = (document.getElementById('e_street')?.value   || '').trim();
    document.getElementById('e_location_preview').innerHTML = buildLocationPreview(r, p, c, b, s);
}

function updateRegisterPreview() {
    const r = (document.getElementById('r_region')?.value   || '').trim();
    const p = (document.getElementById('r_province')?.value || '').trim();
    const c = (document.getElementById('r_city')?.value     || '').trim();
    const b = (document.getElementById('r_barangay')?.value || '').trim();
    const s = (document.getElementById('r_street')?.value   || '').trim();
    const el = document.getElementById('r_location_preview');
    if (el) el.innerHTML = buildLocationPreview(r, p, c, b, s) || '<span style="color:#bbb;">Fill in the fields above.</span>';
}

// Load active fuel types for station from API
async function loadStationFuelTypes(stationId) {
    try {
        const res  = await fetch(`../backend/api/superadmin_station_management_api.php?action=get_station_fuel_types&station_id=${stationId}&csrf_token=<?php echo $csrf; ?>`);
        const data = await res.json();
        if (data.ok && data.fuel_type_ids) {
            data.fuel_type_ids.forEach(id => {
                const cb = document.querySelector(`.e_fuel_cb[value="${id}"]`);
                if (cb) { cb.checked = true; cb.closest('.sm-fuel-chip').classList.add('checked'); }
            });
        }
    } catch (e) { /* silent */ }
}

// Merchandise catalog search
let _merchSearchTimer = null;
function searchMerchCatalog() {
    clearTimeout(_merchSearchTimer);
    _merchSearchTimer = setTimeout(async () => {
        const q   = document.getElementById('e_merch_search').value.trim();
        const cat = document.getElementById('e_merch_cat_filter').value;
        const list = document.getElementById('e_merch_catalog_list');
        if (!q && !cat) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#bbb;font-size:13px;"><i class="fas fa-search" style="display:block;font-size:22px;margin-bottom:6px;opacity:.3;"></i>Type to search the global product catalog.</div>';
            return;
        }
        list.innerHTML = '<div style="padding:16px;text-align:center;color:#bbb;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Searching…</div>';
        try {
            const params = new URLSearchParams({ action:'search_catalog', q, cat, station_id: _cfgStationId, csrf_token: '<?php echo $csrf; ?>' });
            const res  = await fetch('../backend/api/superadmin_station_management_api.php?' + params);
            const data = await res.json();
            if (!data.ok || !data.products?.length) {
                list.innerHTML = '<div style="padding:16px;text-align:center;color:#bbb;font-size:12px;">No products found.</div>'; return;
            }
            list.innerHTML = data.products.map(p => `
              <div class="merch-row" id="catalog_row_${p.id}">
                <span class="merch-name">${escHtml(p.product_name)}<span class="merch-cat">${escHtml(p.category)}</span></span>
                <span class="merch-price">₱${parseFloat(p.unit_price||p.unit_cost||0).toFixed(2)}</span>
                ${p.in_station
                  ? `<button class="merch-remove" onclick="removeMerchFromStation(${p.id},'${escHtml(p.product_name)}')">Remove</button>`
                  : `<button class="merch-add"    onclick="addMerchToStation(${p.id},'${escHtml(p.product_name)}')">+ Add</button>`}
              </div>`).join('');
        } catch { list.innerHTML = '<div style="padding:16px;text-align:center;color:#cc0000;font-size:12px;">Error loading catalog.</div>'; }
    }, 320);
}

// Load categories into filter dropdown (once)
async function loadMerchCategories() {
    const sel = document.getElementById('e_merch_cat_filter');
    if (sel.options.length > 1) return;
    try {
        const res  = await fetch('../backend/api/superadmin_station_management_api.php?action=get_merch_categories&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        if (data.ok && data.categories) {
            data.categories.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; sel.appendChild(o); });
        }
    } catch { /* silent */ }
}

// Load active merchandise for this station
async function loadMerchActive(stationId) {
    const list = document.getElementById('e_merch_active_list');
    list.innerHTML = '<div style="padding:16px;text-align:center;color:#bbb;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
    try {
        const res  = await fetch(`../backend/api/superadmin_station_management_api.php?action=get_station_merch&station_id=${stationId}&csrf_token=<?php echo $csrf; ?>`);
        const data = await res.json();
        document.getElementById('e_merch_count').textContent = data.products?.length || 0;
        if (!data.ok || !data.products?.length) {
            list.innerHTML = '<div style="padding:16px;text-align:center;color:#bbb;font-size:12px;">No merchandise active for this station yet.</div>'; return;
        }
        list.innerHTML = data.products.map(p => `
          <div class="merch-row" id="active_row_${p.product_id}">
            <span class="merch-name">${escHtml(p.product_name)}<span class="merch-cat">${escHtml(p.category||'')}</span></span>
            <input class="merch-price-input" type="number" step="0.01" min="0" value="${parseFloat(p.price||0).toFixed(2)}" title="Station price override" onchange="updateMerchPrice(${p.product_id}, this.value)">
            <button class="merch-remove" onclick="removeMerchFromStation(${p.product_id},'${escHtml(p.product_name)}')">Remove</button>
          </div>`).join('');
    } catch { list.innerHTML = '<div style="padding:16px;text-align:center;color:#cc0000;font-size:12px;">Error loading merchandise.</div>'; }
}

async function addMerchToStation(productId, name) {
    const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method:'POST', body: buildFd({ action:'add_merch', station_id:_cfgStationId, product_id:productId }) });
    const data = await res.json();
    if (data.ok) { searchMerchCatalog(); loadMerchActive(_cfgStationId); }
    else showFlash('error', data.error || 'Failed to add product.');
}

async function removeMerchFromStation(productId, name) {
    const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method:'POST', body: buildFd({ action:'remove_merch', station_id:_cfgStationId, product_id:productId }) });
    const data = await res.json();
    if (data.ok) { searchMerchCatalog(); loadMerchActive(_cfgStationId); }
    else showFlash('error', data.error || 'Failed to remove product.');
}

async function updateMerchPrice(productId, price) {
    const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method:'POST', body: buildFd({ action:'update_merch_price', station_id:_cfgStationId, product_id:productId, price }) });
    const data = await res.json();
    if (!data.ok) showFlash('error', data.error || 'Failed to update price.');
}

function buildFd(obj) {
    const fd = new FormData();
    fd.append('csrf_token', '<?php echo $csrf; ?>');
    Object.entries(obj).forEach(([k,v]) => fd.append(k, v));
    return fd;
}

function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Save all configure changes
async function submitConfigure() {
    const btn   = document.getElementById('editSubmitBtn');
    const alert = document.getElementById('editAlert');
    alert.style.display = 'none';

    const name = document.getElementById('e_name').value.trim();
    if (!name) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Station name is required.';
        alert.style.display = 'flex';
        switchCfgTab('cfg-name'); return;
    }

    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fuelIds = [...document.querySelectorAll('.e_fuel_cb:checked')].map(cb => cb.value);

    const fd = new FormData();
    fd.append('action',     'edit_station');
    fd.append('csrf_token', '<?php echo $csrf; ?>');
    fd.append('station_id', _cfgStationId);
    fd.append('name',       name);
    fd.append('region',     document.getElementById('e_region').value.trim());
    fd.append('province',   document.getElementById('e_province').value.trim());
    fd.append('city',       document.getElementById('e_city').value.trim());
    fd.append('barangay',   document.getElementById('e_barangay').value.trim());
    fd.append('street',     document.getElementById('e_street').value.trim());
    fd.append('status',     document.getElementById('e_status').value);
    fuelIds.forEach(id => fd.append('fuel_types[]', id));

    try {
        const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.ok) {
            closeModal('editModal');
            showFlash('success', data.message || 'Station updated.');
            setTimeout(() => location.reload(), 1200);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed.');
            alert.style.display = 'flex';
        }
    } catch { alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error.'; alert.style.display = 'flex'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
}

// ══ Assign modal ══════════════════════════════════════════════
function openAssignModal(stationId, stationName, currentAdminId) {
    document.getElementById('assignAlert').style.display = 'none';
    document.getElementById('a_station_id').value = stationId;
    document.getElementById('a_station_label').textContent = `Station: ${stationName}`;
    const combo = document.getElementById('a_admin_combo');
    if (combo && combo._reset) combo._reset();
    if (currentAdminId && combo && combo._setValue) {
        // Find label from list
        const opt = document.querySelector(`#a_admin_list .sm-combo-option[data-value="${currentAdminId}"]`);
        if (opt) combo._setValue(String(currentAdminId), opt.dataset.label);
    }
    openModal('assignModal');
}

async function submitAssign(e) {
    e.preventDefault();
    const btn   = document.getElementById('assignSubmitBtn');
    const alert = document.getElementById('assignAlert');
    alert.style.display = 'none';

    if (!document.getElementById('a_admin_id').value) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select an admin.';
        alert.style.display = 'flex'; return;
    }

    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning…';

    const fd = new FormData(document.getElementById('assignForm'));
    fd.append('action', 'assign_admin');
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeModal('assignModal');
            showFlash('success', data.message || 'Admin assigned successfully.');
            setTimeout(() => location.reload(), 1200);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed.');
            alert.style.display = 'flex';
        }
    } catch { alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error.'; alert.style.display = 'flex'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-user-shield"></i> Assign Admin';
}

// ══ Confirm status ════════════════════════════════════════════
let _cId = null, _cAction = null;
function confirmStatus(id, name, action) {
    _cId = id; _cAction = action;
    const deact = action === 'deactivate';
    document.getElementById('confirmTitle').textContent = deact ? 'Deactivate Station' : 'Activate Station';
    document.getElementById('confirmIcon').className    = 'fas fa-' + (deact ? 'ban' : 'check-circle');
    document.getElementById('confirmIcon').style.color  = deact ? '#cc0000' : '#28a745';
    document.getElementById('confirmMsg').textContent   = `${deact ? 'Deactivate' : 'Activate'} "${name}"?`;
    document.getElementById('confirmSub').textContent   = deact
        ? 'Station will be marked inactive. All records are preserved.'
        : 'Station will be marked active and visible to assigned Admin.';
    const btn = document.getElementById('confirmActionBtn');
    btn.className   = 'sm-btn ' + (deact ? 'sm-btn-deact' : 'sm-btn-activate');
    btn.textContent = deact ? 'Deactivate' : 'Activate';
    openModal('confirmModal');
}

async function executeStatus() {
    if (!_cId) return;
    const btn = document.getElementById('confirmActionBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action',     _cAction === 'deactivate' ? 'deactivate_station' : 'activate_station');
    fd.append('station_id', _cId);
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_station_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        closeModal('confirmModal');
        showFlash(data.ok ? 'success' : 'error', data.message || data.error || 'Done.');
        if (data.ok) setTimeout(() => location.reload(), 1200);
    } catch { closeModal('confirmModal'); showFlash('error', 'Network error.'); }
}

// ══ Profile modal ════════════════════════════════════════════
async function openProfileModal(stationId, stationName) {
    document.getElementById('p_station_subtitle').textContent = stationName;
    document.getElementById('profileModalBody').innerHTML =
        '<div style="text-align:center;padding:40px;color:#bbb;"><i class="fas fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>Loading station profile…</div>';
    openModal('profileModal');

    try {
        const res  = await fetch(`../backend/api/superadmin_station_management_api.php?action=get_station_profile&station_id=${stationId}&csrf_token=<?php echo $csrf; ?>`);
        const data = await res.json();
        if (!data.ok) {
            document.getElementById('profileModalBody').innerHTML =
                `<div style="text-align:center;padding:30px;color:#cc0000;"><i class="fas fa-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px;"></i>${data.error || 'Failed to load profile.'}</div>`;
            return;
        }
        const d = data.profile;
        const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

        // Location
        const locParts = [d.street, d.barangay, d.city, d.province, d.region].filter(Boolean);
        const locStr   = locParts.join(', ') || '—';

        // Pumps rows
        const pumpsHtml = d.pumps.length
            ? d.pumps.map(p => `<tr>
                <td style="padding:8px 12px;font-weight:600;">${esc(p.pump_number)}</td>
                <td style="padding:8px 12px;">${esc(p.fuel_type_name||'—')}</td>
                <td style="padding:8px 12px;">${p.capacity ? parseFloat(p.capacity).toLocaleString() + ' L' : '—'}</td>
                <td style="padding:8px 12px;"><span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:${p.status==='Active'?'rgba(40,167,69,.12)':'rgba(204,0,0,.1)'};color:${p.status==='Active'?'#1a7a35':'#cc0000'};">${esc(p.status||'—')}</span></td>
              </tr>`).join('')
            : '<tr><td colspan="4" style="padding:16px;text-align:center;color:#bbb;">No pumps configured.</td></tr>';

        // Fuel inventory rows
        const fuelHtml = d.fuel_inventory.length
            ? d.fuel_inventory.map(f => `<tr>
                <td style="padding:8px 12px;font-weight:600;">${esc(f.product_name)}</td>
                <td style="padding:8px 12px;text-align:right;">${parseFloat(f.stock_level||0).toLocaleString()} L</td>
              </tr>`).join('')
            : '<tr><td colspan="2" style="padding:16px;text-align:center;color:#bbb;">No fuel inventory records.</td></tr>';

        // Merchandise rows
        const merchHtml = d.merchandise.length
            ? d.merchandise.map(m => `<tr>
                <td style="padding:8px 12px;font-weight:600;">${esc(m.product_name)}</td>
                <td style="padding:8px 12px;">${esc(m.category||'—')}</td>
                <td style="padding:8px 12px;text-align:right;">${parseInt(m.stock_level||0).toLocaleString()}</td>
                <td style="padding:8px 12px;text-align:right;">₱${parseFloat(m.price||0).toFixed(2)}</td>
              </tr>`).join('')
            : '<tr><td colspan="4" style="padding:16px;text-align:center;color:#bbb;">No merchandise in catalog.</td></tr>';

        const statusBadge = d.status === 'active'
            ? '<span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(40,167,69,.12);color:#1a7a35;"><i class="fas fa-circle" style="font-size:7px;"></i> Active</span>'
            : '<span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(204,0,0,.1);color:#cc0000;"><i class="fas fa-circle" style="font-size:7px;"></i> Inactive</span>';

        document.getElementById('profileModalBody').innerHTML = `
          <!-- Overview row -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px;">
            <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:14px;text-align:center;">
              <div style="font-size:22px;font-weight:800;color:var(--petron-blue);">${d.pumps.length}</div>
              <div style="font-size:11px;color:#888;margin-top:2px;">Pumps</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:14px;text-align:center;">
              <div style="font-size:22px;font-weight:800;color:var(--petron-blue);">${d.fuel_inventory.length}</div>
              <div style="font-size:11px;color:#888;margin-top:2px;">Fuel Types</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:14px;text-align:center;">
              <div style="font-size:22px;font-weight:800;color:var(--petron-blue);">${d.merchandise.length}</div>
              <div style="font-size:11px;color:#888;margin-top:2px;">Merchandise</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:14px;text-align:center;">
              <div style="font-size:22px;font-weight:800;color:var(--petron-blue);">${d.active_users}</div>
              <div style="font-size:11px;color:#888;margin-top:2px;">Active Users</div>
            </div>
          </div>

          <!-- Station Info -->
          <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:16px;margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;"><i class="fas fa-info-circle" style="margin-right:5px;"></i>Station Info</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">
              <div><span style="color:#888;font-size:11px;display:block;">Station ID</span><strong>#${String(d.id).padStart(4,'0')}</strong></div>
              <div><span style="color:#888;font-size:11px;display:block;">Status</span>${statusBadge}</div>
              <div><span style="color:#888;font-size:11px;display:block;">Assigned Admin</span><strong>${esc(d.admin_name||'Unassigned')}</strong></div>
              <div><span style="color:#888;font-size:11px;display:block;">Registered</span><strong>${d.created_at ? new Date(d.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</strong></div>
              <div style="grid-column:1/-1;"><span style="color:#888;font-size:11px;display:block;">Full Address</span><strong>${esc(locStr)}</strong></div>
            </div>
          </div>

          <!-- Pumps -->
          <div style="background:#fff;border:1px solid #eee;border-radius:10px;overflow:hidden;margin-bottom:16px;">
            <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;"><i class="fas fa-gas-pump" style="margin-right:5px;color:var(--petron-blue);"></i>Fuel Pumps</div>
            <div style="overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Pump #</th>
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Fuel Type</th>
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Capacity</th>
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Status</th>
                </tr></thead>
                <tbody>${pumpsHtml}</tbody>
              </table>
            </div>
          </div>

          <!-- Fuel Inventory -->
          <div style="background:#fff;border:1px solid #eee;border-radius:10px;overflow:hidden;margin-bottom:16px;">
            <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;"><i class="fas fa-tint" style="margin-right:5px;color:var(--petron-blue);"></i>Fuel Inventory (Current Stock)</div>
            <div style="overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Fuel Type</th>
                  <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Stock Level</th>
                </tr></thead>
                <tbody>${fuelHtml}</tbody>
              </table>
            </div>
          </div>

          <!-- Merchandise -->
          <div style="background:#fff;border:1px solid #eee;border-radius:10px;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.5px;"><i class="fas fa-boxes" style="margin-right:5px;color:var(--petron-blue);"></i>Merchandise Stock</div>
            <div style="overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Product</th>
                  <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Category</th>
                  <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Stock</th>
                  <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;">Price</th>
                </tr></thead>
                <tbody>${merchHtml}</tbody>
              </table>
            </div>
          </div>`;
    } catch (err) {
        document.getElementById('profileModalBody').innerHTML =
            '<div style="text-align:center;padding:30px;color:#cc0000;"><i class="fas fa-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px;"></i>Network error. Please try again.</div>';
    }
}

// ══ Page flash ════════════════════════════════════════════════
function showFlash(type, msg) {
    let el = document.getElementById('smPageFlash');
    if (!el) {
        el = document.createElement('div');
        el.id = 'smPageFlash';
        el.style.cssText = 'position:fixed;top:80px;right:24px;z-index:9999;max-width:420px;';
        document.body.appendChild(el);
    }
    el.className = 'sm-flash ' + type;
    el.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 4500);
}

document.addEventListener('DOMContentLoaded', () => {
    initSmCombo('a_admin_combo', 'a_admin_search', 'a_admin_list', 'a_admin_display', 'a_admin_id', 'a_admin_clear');
    loadMerchCategories();
    smFilter();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
