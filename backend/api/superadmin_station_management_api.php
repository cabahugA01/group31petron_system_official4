<?php

// ============================================================
// SuperAdmin – Station Management API
// backend/api/superadmin_station_management_api.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

// ── Route GET actions BEFORE the POST CSRF check ─────────────
// GET actions are read-only and use session auth only (no CSRF needed for reads).
$get_action = trim($_GET['action'] ?? '');

// ── POST CSRF check — only applies to POST requests ───────────
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_POST['action'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']); exit;
    }
    $action = trim($_POST['action'] ?? '');
}

// ── Helper: ensure address column exists ─────────────────────
function ensure_address_column(PDO $pdo): bool {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('address', $cols)) {
            $pdo->exec("ALTER TABLE stations ADD COLUMN address VARCHAR(500) NULL AFTER location");
        }
        return true;
    } catch (Exception $e) {
        return false; // column may already exist or table issue
    }
}

// ── Helper: ensure location column exists ─────────────────────
function ensure_location_column(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('location', $cols)) {
            $pdo->exec("ALTER TABLE stations ADD COLUMN location VARCHAR(1000) NULL AFTER name");
        }
    } catch (Exception $e) {}
}
// Format: "Region||Province||City||Barangay||Street"
// Also stores a human-readable version in the address column
function build_location(string $region, string $province, string $city, string $barangay, string $street): string {
    return implode('||', array_map('trim', [$region, $province, $city, $barangay, $street]));
}

function build_address_readable(string $region, string $province, string $city, string $barangay, string $street): string {
    return implode(', ', array_filter(array_map('trim', [$street, $barangay, $city, $province, $region])));
}

// ════════════════════════════════════════════════════════════
// register_station
// ════════════════════════════════════════════════════════════
if ($action === 'register_station') {
    $name     = trim($_POST['name']     ?? '');
    $region   = trim($_POST['region']   ?? '');
    $province = trim($_POST['province'] ?? '');
    $city     = trim($_POST['city']     ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $street   = trim($_POST['street']   ?? '');
    $fuel_ids = $_POST['fuel_types']    ?? [];

    if (empty($name)) { echo json_encode(['ok'=>false,'error'=>'Station name is required.']); exit; }
    if (empty($region)) { echo json_encode(['ok'=>false,'error'=>'Region is required.']); exit; }
    if (empty($city))   { echo json_encode(['ok'=>false,'error'=>'City / Municipality is required.']); exit; }

    try {
        // Duplicate name check
        $chk = $pdo->prepare("SELECT id FROM stations WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $chk->execute([$name]);
        if ($chk->rowCount() > 0) { echo json_encode(['ok'=>false,'error'=>"A station named \"{$name}\" already exists."]); exit; }

        $location = build_location($region, $province, $city, $barangay, $street);
        $address  = build_address_readable($region, $province, $city, $barangay, $street);

        // Ensure columns exist (safe for MySQL 5.7+)
        ensure_location_column($pdo);
        $has_address_col = ensure_address_column($pdo);

        if ($has_address_col) {
            $ins = $pdo->prepare("INSERT INTO stations (name, location, address, status, created_at, updated_at) VALUES (?, ?, ?, 'active', NOW(), NOW())");
            $ins->execute([$name, $location, $address]);
        } else {
            $ins = $pdo->prepare("INSERT INTO stations (name, location, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())");
            $ins->execute([$name, $location]);
        }
        $new_id = (int)$pdo->lastInsertId();

        // Seed fuel inventory rows for selected fuel types
        if (!empty($fuel_ids)) {
            try {
                $ft_stmt  = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                $chk_inv  = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_name=? AND type='fuel' LIMIT 1");
                $inv_ins  = $pdo->prepare("INSERT INTO station_inventory (station_id, product_name, stock_level, type) VALUES (?, ?, 0, 'fuel')");
                foreach ($fuel_ids as $fid) {
                    $ft_stmt->execute([(int)$fid]);
                    $ft_name = $ft_stmt->fetchColumn();
                    if (!$ft_name) continue;
                    $chk_inv->execute([$new_id, $ft_name]);
                    if (!$chk_inv->fetchColumn()) {
                        $inv_ins->execute([$new_id, $ft_name]);
                    }
                }
            } catch (Exception $e) { /* non-fatal — inventory seeding */ }
        }

        log_activity($pdo, $me['id'], 'Register Station', "SuperAdmin registered station '{$name}' (ID {$new_id}), location: '{$location}'");

        echo json_encode(['ok'=>true,'message'=>"Station \"{$name}\" registered successfully (ID: {$new_id}).",'station_id'=>$new_id]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// edit_station
// ════════════════════════════════════════════════════════════
if ($action === 'edit_station') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $name       = trim($_POST['name']     ?? '');
    $region     = trim($_POST['region']   ?? '');
    $province   = trim($_POST['province'] ?? '');
    $city       = trim($_POST['city']     ?? '');
    $barangay   = trim($_POST['barangay'] ?? '');
    $street     = trim($_POST['street']   ?? '');
    $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    $fuel_ids   = $_POST['fuel_types'] ?? [];

    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }
    if (empty($name))     { echo json_encode(['ok'=>false,'error'=>'Station name is required.']); exit; }

    try {
        // Duplicate name check (exclude self)
        $chk = $pdo->prepare("SELECT id FROM stations WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1");
        $chk->execute([$name, $station_id]);
        if ($chk->rowCount() > 0) { echo json_encode(['ok'=>false,'error'=>"Another station named \"{$name}\" already exists."]); exit; }

        $location = build_location($region, $province, $city, $barangay, $street);
        $address  = build_address_readable($region, $province, $city, $barangay, $street);

        // Ensure columns exist (safe for MySQL 5.7+)
        ensure_location_column($pdo);
        $has_address_col = ensure_address_column($pdo);

        if ($has_address_col) {
            $upd = $pdo->prepare("UPDATE stations SET name=?, location=?, address=?, status=?, updated_at=NOW() WHERE id=?");
            $upd->execute([$name, $location, $address, $status, $station_id]);
        } else {
            $upd = $pdo->prepare("UPDATE stations SET name=?, location=?, status=?, updated_at=NOW() WHERE id=?");
            $upd->execute([$name, $location, $status, $station_id]);
        }

        // Update fuel inventory seeds for newly selected types
        if (!empty($fuel_ids)) {
            try {
                $ft_stmt  = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                $chk_inv  = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_name=? AND type='fuel' LIMIT 1");
                $inv_ins  = $pdo->prepare("INSERT INTO station_inventory (station_id, product_name, stock_level, type) VALUES (?, ?, 0, 'fuel')");
                foreach ($fuel_ids as $fid) {
                    $ft_stmt->execute([(int)$fid]);
                    $ft_name = $ft_stmt->fetchColumn();
                    if (!$ft_name) continue;
                    $chk_inv->execute([$station_id, $ft_name]);
                    if (!$chk_inv->fetchColumn()) {
                        $inv_ins->execute([$station_id, $ft_name]);
                    }
                }
            } catch (Exception $e) { /* non-fatal */ }
        }

        log_activity($pdo, $me['id'], 'Edit Station', "SuperAdmin updated station ID {$station_id} ('{$name}'), status: {$status}");

        echo json_encode(['ok'=>true,'message'=>"Station \"{$name}\" updated successfully."]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// assign_admin
// ════════════════════════════════════════════════════════════
if ($action === 'assign_admin') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $admin_id   = (int)($_POST['admin_id']   ?? 0);

    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }
    if ($admin_id   <= 0) { echo json_encode(['ok'=>false,'error'=>'Please select an admin.']); exit; }

    try {
        $pdo->beginTransaction();

        // Verify station
        $st = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Station not found.']); exit; }

        // Verify admin
        $adm = $pdo->prepare("SELECT name, station_id FROM users WHERE id=? AND LOWER(role) IN ('admin','station admin','station_admin') AND status='active' LIMIT 1");
        $adm->execute([$admin_id]);
        $admin = $adm->fetch(PDO::FETCH_ASSOC);
        if (!$admin) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Admin not found or inactive.']); exit; }

        $prev_station = $admin['station_id'];

        // Unassign any existing admin from target station
        $pdo->prepare("UPDATE users SET station_id=NULL WHERE station_id=? AND LOWER(role) IN ('admin','station admin','station_admin')")->execute([$station_id]);

        // Assign new admin
        $pdo->prepare("UPDATE users SET station_id=? WHERE id=?")->execute([$station_id, $admin_id]);

        $pdo->commit();

        $prev_info = $prev_station ? " (transferred from station ID {$prev_station})" : '';
        log_activity($pdo, $me['id'], 'Assign Admin', "SuperAdmin assigned admin '{$admin['name']}' (ID {$admin_id}) to station '{$station_name}' (ID {$station_id}){$prev_info}");

        echo json_encode(['ok'=>true,'message'=>"Admin \"{$admin['name']}\" assigned to \"{$station_name}\" successfully."]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// deactivate_station / activate_station
// ════════════════════════════════════════════════════════════
if (in_array($action, ['deactivate_station','activate_station'])) {
    $station_id = (int)($_POST['station_id'] ?? 0);
    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }

    $new_status = $action === 'deactivate_station' ? 'inactive' : 'active';

    try {
        $st = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) { echo json_encode(['ok'=>false,'error'=>'Station not found.']); exit; }

        $pdo->prepare("UPDATE stations SET status=?, updated_at=NOW() WHERE id=?")->execute([$new_status, $station_id]);

        log_activity($pdo, $me['id'], ucfirst($new_status).' Station', "SuperAdmin set station '{$station_name}' (ID {$station_id}) to {$new_status}");

        echo json_encode(['ok'=>true,'message'=>"Station \"{$station_name}\" is now {$new_status}."]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// GET actions (via query string — no CSRF needed for reads)
// ════════════════════════════════════════════════════════════
$get_action = trim($_GET['action'] ?? '');

// get_station_fuel_types
if ($get_action === 'get_station_fuel_types') {
    $station_id = (int)($_GET['station_id'] ?? 0);
    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }
    try {
        // Fuel types active for this station = those that have a station_inventory row of type 'fuel'
        $rows = $pdo->prepare(
            "SELECT DISTINCT ft.id
             FROM fuel_types ft
             INNER JOIN station_inventory si ON si.product_name = ft.name AND si.station_id = ?
             WHERE si.type = 'fuel' OR si.type IS NULL"
        );
        $rows->execute([$station_id]);
        $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
        // Fallback: also check inventory_products join
        if (empty($ids)) {
            $rows2 = $pdo->prepare(
                "SELECT DISTINCT ft.id
                 FROM fuel_types ft
                 INNER JOIN inventory_products ip ON LOWER(ip.product_name) = LOWER(ft.name) AND ip.category = 'Fuel'
                 INNER JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?"
            );
            $rows2->execute([$station_id]);
            $ids = $rows2->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode(['ok'=>true,'fuel_type_ids'=>$ids]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>true,'fuel_type_ids'=>[]]); // non-fatal
    }
    exit;
}

// get_station_profile
if ($get_action === 'get_station_profile') {
    $station_id = (int)($_GET['station_id'] ?? 0);
    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }
    try {
        // Station base info
        $st = $pdo->prepare(
            "SELECT s.id, s.name, s.location, s.status, s.created_at,
                    (SELECT u.name  FROM users u WHERE u.station_id = s.id AND LOWER(u.role) IN ('admin','station admin','station_admin') AND u.status='active' LIMIT 1) AS admin_name,
                    (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status='active') AS active_users
             FROM stations s WHERE s.id = ? LIMIT 1"
        );
        $st->execute([$station_id]);
        $station = $st->fetch(PDO::FETCH_ASSOC);
        if (!$station) { echo json_encode(['ok'=>false,'error'=>'Station not found.']); exit; }

        // Parse location
        $loc = ['region'=>'','province'=>'','city'=>'','barangay'=>'','street'=>''];
        if (!empty($station['location'])) {
            if (strpos($station['location'], '||') !== false) {
                [$loc['region'], $loc['province'], $loc['city'], $loc['barangay'], $loc['street']] =
                    array_pad(explode('||', $station['location']), 5, '');
            } else {
                $pipe = strpos($station['location'], ' | ');
                if ($pipe !== false) {
                    $loc['region'] = trim(substr($station['location'], 0, $pipe));
                    $loc['street'] = trim(substr($station['location'], $pipe + 3));
                } else {
                    $loc['street'] = $station['location'];
                }
            }
        }

        // Pumps
        $pumps = [];
        try {
            $p = $pdo->prepare(
                "SELECT fp.pump_number, fp.capacity, fp.status, ft.name AS fuel_type_name
                 FROM fuel_pumps fp
                 LEFT JOIN fuel_types ft ON ft.id = fp.fuel_type_id
                 WHERE fp.station_id = ?
                 ORDER BY fp.pump_number"
            );
            $p->execute([$station_id]);
            $pumps = $p->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // Fuel inventory
        $fuel_inventory = [];
        try {
            $fi = $pdo->prepare(
                "SELECT si.product_name, COALESCE(si.stock_level, 0) AS stock_level
                 FROM station_inventory si
                 WHERE si.station_id = ? AND (si.type = 'fuel' OR si.product_name IN (SELECT name FROM fuel_types))
                 ORDER BY si.product_name"
            );
            $fi->execute([$station_id]);
            $fuel_inventory = $fi->fetchAll(PDO::FETCH_ASSOC);
            // Fallback: try inventory_products join
            if (empty($fuel_inventory)) {
                $fi2 = $pdo->prepare(
                    "SELECT ip.product_name, COALESCE(si.stock_level, 0) AS stock_level
                     FROM station_inventory si
                     INNER JOIN inventory_products ip ON ip.id = si.product_id
                     WHERE si.station_id = ? AND ip.category = 'Fuel'
                     ORDER BY ip.product_name"
                );
                $fi2->execute([$station_id]);
                $fuel_inventory = $fi2->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}

        // Merchandise stock
        $merchandise = [];
        try {
            $m = $pdo->prepare(
                "SELECT ip.product_name, ip.category,
                        COALESCE(si.stock_level, 0) AS stock_level,
                        COALESCE(si.price, si.cost, ip.unit_price, ip.unit_cost, 0) AS price
                 FROM station_inventory si
                 INNER JOIN inventory_products ip ON ip.id = si.product_id
                 WHERE si.station_id = ? AND ip.category != 'Fuel' AND ip.category IS NOT NULL
                 ORDER BY ip.category, ip.product_name"
            );
            $m->execute([$station_id]);
            $merchandise = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        echo json_encode([
            'ok'      => true,
            'profile' => array_merge($station, $loc, [
                'pumps'          => $pumps,
                'fuel_inventory' => $fuel_inventory,
                'merchandise'    => $merchandise,
            ])
        ]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// get_regions
if ($get_action === 'get_regions') {
    try {
        $regions = $pdo->query("SELECT id, name, sort_order FROM ph_regions ORDER BY sort_order, name")
                       ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'regions' => $regions]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// get_merch_categories
if ($get_action === 'get_merch_categories') {
    try {
        $cats = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category IS NOT NULL AND category != '' AND category != 'Fuel' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['ok'=>true,'categories'=>$cats]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>true,'categories'=>[]]);
    }
    exit;
}

// search_catalog
if ($get_action === 'search_catalog') {
    $q          = trim($_GET['q']          ?? '');
    $cat        = trim($_GET['cat']        ?? '');
    $station_id = (int)($_GET['station_id'] ?? 0);
    try {
        $where = ["ip.category != 'Fuel'", "ip.category IS NOT NULL"];
        $params = [];
        if ($q)   { $where[] = "ip.product_name LIKE ?"; $params[] = "%{$q}%"; }
        if ($cat) { $where[] = "ip.category = ?";        $params[] = $cat; }
        $sql = "SELECT ip.id, ip.product_name, ip.category,
                       COALESCE(ip.unit_price, ip.unit_cost, 0) AS unit_price,
                       COALESCE(ip.unit_cost, 0) AS unit_cost,
                       IF(si.id IS NOT NULL, 1, 0) AS in_station
                FROM inventory_products ip
                LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ip.category, ip.product_name LIMIT 60";
        array_unshift($params, $station_id);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok'=>true,'products'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// get_station_merch
if ($get_action === 'get_station_merch') {
    $station_id = (int)($_GET['station_id'] ?? 0);
    if ($station_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid station.']); exit; }
    try {
        $stmt = $pdo->prepare(
            "SELECT si.product_id, ip.product_name, ip.category,
                    COALESCE(si.price, si.cost, ip.unit_price, ip.unit_cost, 0) AS price
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.station_id = ? AND ip.category != 'Fuel'
             ORDER BY ip.category, ip.product_name"
        );
        $stmt->execute([$station_id]);
        echo json_encode(['ok'=>true,'products'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// Merchandise POST actions
// ════════════════════════════════════════════════════════════

// add_merch
if ($action === 'add_merch') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($station_id <= 0 || $product_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid parameters.']); exit; }
    try {
        $ip = $pdo->prepare("SELECT product_name, COALESCE(unit_price, unit_cost, 0) AS price FROM inventory_products WHERE id=? LIMIT 1");
        $ip->execute([$product_id]);
        $prod = $ip->fetch(PDO::FETCH_ASSOC);
        if (!$prod) { echo json_encode(['ok'=>false,'error'=>'Product not found.']); exit; }

        $ins = $pdo->prepare("INSERT IGNORE INTO station_inventory (station_id, product_id, product_name, stock_level, price, cost, type, status, last_updated) VALUES (?,?,?,0,?,?,'merch','active',NOW())");
        $ins->execute([$station_id, $product_id, $prod['product_name'], $prod['price'], $prod['price']]);

        log_activity($pdo, $me['id'], 'Add Station Merch', "Added '{$prod['product_name']}' to station ID {$station_id} catalog");
        echo json_encode(['ok'=>true,'message'=>"Product added to station catalog."]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// remove_merch
if ($action === 'remove_merch') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($station_id <= 0 || $product_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid parameters.']); exit; }
    try {
        $ip = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=? LIMIT 1");
        $ip->execute([$product_id]);
        $prod_name = $ip->fetchColumn() ?: "ID {$product_id}";

        $del = $pdo->prepare("DELETE FROM station_inventory WHERE station_id=? AND product_id=?");
        $del->execute([$station_id, $product_id]);

        log_activity($pdo, $me['id'], 'Remove Station Merch', "Removed '{$prod_name}' from station ID {$station_id} catalog");
        echo json_encode(['ok'=>true,'message'=>"Product removed from station catalog."]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// update_merch_price
if ($action === 'update_merch_price') {
    $station_id = (int)($_POST['station_id'] ?? 0);
    $product_id = (int)($_POST['product_id'] ?? 0);
    $price      = max(0, (float)($_POST['price'] ?? 0));
    if ($station_id <= 0 || $product_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid parameters.']); exit; }
    try {
        $pdo->prepare("UPDATE station_inventory SET price=?, last_updated=NOW() WHERE station_id=? AND product_id=?")->execute([$price, $station_id, $product_id]);
        log_activity($pdo, $me['id'], 'Update Station Merch Price', "Updated price for product ID {$product_id} at station ID {$station_id} to ₱{$price}");
        echo json_encode(['ok'=>true]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
