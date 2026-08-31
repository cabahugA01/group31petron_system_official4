<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'admin_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

if (!function_exists('get_canonical_fuel_name')) {
    function get_canonical_fuel_name($name) {
        $name_lower = strtolower(trim($name));
        if (strpos($name_lower, 'turbo') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($name_lower, 'diesel') !== false) {
            return 'Diesel';
        } elseif (strpos($name_lower, 'kerosene') !== false) {
            return 'Kerosene';
        } elseif (strpos($name_lower, 'xcs') !== false) {
            return 'XCS Plus';
        } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
            return 'XTR ADVANCE';
        }
        return $name;
    }
}

if (!function_exists('get_matching_fuel_ids')) {
    function get_matching_fuel_ids($pdo, $station_id, $fuel_id, $raw_fuel_type = '') {
        if (empty($raw_fuel_type) && $fuel_id > 0) {
            $stmt = $pdo->prepare("SELECT fuel_type FROM fuel_inventory WHERE id = ? LIMIT 1");
            $stmt->execute([$fuel_id]);
            $raw_fuel_type = $stmt->fetchColumn() ?: '';
        }
        $canonical = get_canonical_fuel_name($raw_fuel_type);
        $ids = [];
        $stmt = $pdo->prepare("SELECT id, fuel_type FROM fuel_inventory WHERE station_id = ?");
        $stmt->execute([$station_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['id'] == $fuel_id || 
                get_canonical_fuel_name($row['fuel_type']) === $canonical || 
                strcasecmp($row['fuel_type'], $raw_fuel_type) === 0) {
                $ids[] = (int)$row['id'];
            }
        }
        if (empty($ids) && $fuel_id > 0) {
            $ids = [(int)$fuel_id];
        }
        return array_values(array_unique($ids));
    }
}

// ── Superadmin: station selection (defaults to first station if none assigned) ─
if ($role === 'superadmin' && (int)$station_id <= 0) {
    // Try to get selected station from query param
    $selected_sid = (int)($_GET['station_id'] ?? 0);
    if ($selected_sid > 0) {
        $station_id = $selected_sid;
    } else {
        // Default to first available station
        try {
            $first_s = $pdo->query("SELECT id FROM stations ORDER BY id LIMIT 1")->fetchColumn();
            $station_id = $first_s ?: 0;
        } catch (Exception $e) { $station_id = 0; }
    }
}

// ── Ensure job_order_service_types table exists ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        service_key VARCHAR(100) NOT NULL,
        service_name VARCHAR(200) NOT NULL,
        service_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_price DECIMAL(12,2) DEFAULT 0,
        max_price DECIMAL(12,2) DEFAULT 0,
        price_description TEXT DEFAULT NULL,
        pricing_notes TEXT DEFAULT NULL,
        icon_class VARCHAR(100) DEFAULT 'fa-wrench',
        color_class VARCHAR(100) DEFAULT 'text-primary',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table already exists */ }

// ── Handle Approvals / Rejections ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Preserve the active tab across POST redirects
    $redirect_tab = trim($_POST['active_tab'] ?? 'fuel');
    if (!in_array($redirect_tab, ['fuel', 'merch', 'services'])) $redirect_tab = 'fuel';

    if ($action === 'approve_price') {
        $approval_id = (int)$_POST['approval_id'];
        $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
        $stmt->execute([$approval_id]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pending) {
            $ptype = $pending['product_type'] ?? '';
            // Support both new_price (new schema) and new_value (legacy schema)
            $new_price_val = $pending['new_price'] ?? $pending['new_value'] ?? 0;
            $new_cost_val  = $pending['new_cost']  ?? $pending['new_value'] ?? 0;
            $pid           = (int)($pending['product_id'] ?? 0);

            if ($ptype === 'merchandise') {
                $target_station_id = (int)($pending['station_id'] ?? $station_id);
                if ($target_station_id <= 0) $target_station_id = (int)$station_id;

                try {
                    $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $pid]);
                } catch (Exception $legacy_error) {
                    // Current inventory source uses products + station_inventory.
                }

                $pdo->prepare("UPDATE products SET cost=?, price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);

                $si_stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $si_stmt->execute([$target_station_id, $pid]);
                $si_id = (int)($si_stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET cost=?, price=?, last_updated=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, 0, ?, ?, 'active', NOW())")
                        ->execute([$target_station_id, $pid, $new_cost_val, $new_price_val]);
                }
            } elseif ($ptype === 'service_type' || $ptype === 'service') {
                $svc_id      = (int)($pending['service_type_id'] ?? $pid);
                $new_labor   = (float)($pending['new_cost'] ?? 0);
                $pdo->prepare("UPDATE job_order_service_types SET service_price=?, labor_fee=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_price_val, $new_labor, $svc_id]);
            } else {
                // covers 'fuel' and 'fuel_inventory'
                $fuel_id = (int)($pending['fuel_type_id'] ?? $pid);
                $matching_ids = get_matching_fuel_ids($pdo, $target_station_id, $fuel_id, $pending['product_name'] ?? '');

                $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));
                $upd_params = array_merge([$new_price_val], $matching_ids);
                $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, last_updated=NOW() WHERE id IN ($in_clause)")
                    ->execute($upd_params);

                // Insert new history record for all matching tanks (Preserves complete linear audit trail!)
                try {
                    $diff = (float)$new_price_val - (float)$old_price_val;
                    $is_restoration = stripos($pending['reason'] ?? '', 'restor') !== false;
                    $action_reason = $is_restoration ? 'Price Restored' : 'Price Updated';
                    
                    $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history 
                        (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())");
                    foreach ($matching_ids as $m_id) {
                        $hist_stmt->execute([
                            $target_station_id,
                            $m_id,
                            $pending['product_name'] ?? 'Fuel',
                            $old_price_val,
                            $new_price_val,
                            $diff,
                            $action_reason,
                            $pending['requested_by'] ?? $pending['manager_id'],
                            $me['id']
                        ]);
                    }
                } catch (Exception $e) {}

                // Mark any other pending approval records for these matching tanks as approved
                try {
                    $del_params = array_merge([$me['id'], $me['id']], [$target_station_id], $matching_ids);
                    $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                        ->execute($del_params);
                } catch (Exception $e) {}
            }
            // Update status — write to both admin_id and reviewed_by for compatibility
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$me['id'], $me['id'], $approval_id]);
            log_activity($pdo, $me['id'], 'Approve Price',
                "Admin approved price change for {$ptype} ID {$pid}. New value: {$new_price_val}");
            $_SESSION['success'] = "Price change approved successfully!";
        }
    } elseif ($action === 'reject_price') {
        $approval_id = (int)$_POST['approval_id'];
        $remarks = trim($_POST['remarks'] ?? '');

        // Fetch pending approval details first
        $stmt_p = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id=? LIMIT 1");
        $stmt_p->execute([$approval_id]);
        $pending = $stmt_p->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("UPDATE pending_price_approvals SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
        $stmt->execute([$remarks, $remarks, $me['id'], $me['id'], $approval_id]);
        if ($stmt->rowCount() > 0 && $pending) {
            $ptype = $pending['product_type'] ?? 'fuel';
            $pid   = (int)($pending['product_id'] ?? 0);
            $target_station_id = (int)($pending['station_id'] ?? $station_id);

            if (in_array($ptype, ['fuel', 'fuel_inventory'], true)) {
                $fuel_id = (int)($pending['fuel_type_id'] ?? $pid);
                $matching_ids = get_matching_fuel_ids($pdo, $target_station_id, $fuel_id, $pending['product_name'] ?? '');

                try {
                    $old_price_val = (float)($pending['old_price'] ?? $pending['old_value'] ?? 0);
                    $new_price_val = (float)($pending['new_price'] ?? $pending['new_value'] ?? 0);
                    $diff = $new_price_val - $old_price_val;

                    $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history 
                        (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Rejected', NOW())");
                    foreach ($matching_ids as $m_id) {
                        $hist_stmt->execute([
                            $target_station_id,
                            $m_id,
                            $pending['product_name'] ?? 'Fuel',
                            $old_price_val,
                            $new_price_val,
                            $diff,
                            !empty($remarks) ? "Rejected: " . $remarks : "Price Change Request Rejected by Admin",
                            $pending['requested_by'] ?? $pending['manager_id'],
                            $me['id']
                        ]);
                    }
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Reject Price',
                "Admin rejected price change for {$pending['product_name']} (Approval ID $approval_id). Remarks: $remarks");
            $_SESSION['success'] = "Price change request has been rejected.";
        }
    } elseif ($action === 'admin_edit_fuel_direct') {
        $id             = (int)($_POST['id'] ?? 0);
        $fuel_name      = trim($_POST['fuel_name'] ?? '');
        $price          = (float)($_POST['price'] ?? 0);
        $capacity       = (float)($_POST['capacity'] ?? 0);
        $critical_level = (float)($_POST['critical_level'] ?? 0);
        $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
        $status_val     = trim($_POST['status'] ?? 'active');

        if ($price <= 0) throw new Exception('Price per liter must be a positive number greater than 0.');
        if ($capacity <= 0) throw new Exception('Tank capacity must be a positive number greater than 0.');
        if ($critical_level <= 0) throw new Exception('Critical level must be a positive number greater than 0.');
        if ($reorder_level <= 0) throw new Exception('Reorder level must be a positive number greater than 0.');
        if ($critical_level >= $capacity) throw new Exception('Critical level cannot exceed tank capacity.');
        if ($reorder_level >= $capacity) throw new Exception('Reorder level cannot exceed tank capacity.');

        $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
        $stmt_f->execute([$id, $station_id]);
        $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);

        if ($old_fuel) {
            $old_price   = (float)($old_fuel['price_per_liter'] ?? 0);
            $old_name    = $old_fuel['fuel_type'] ?? '';
            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $id, $old_name);
            $in_clause   = implode(',', array_fill(0, count($matching_ids), '?'));

            $user_name   = $me['username'] ?? ($me['first_name'] ?? 'Admin');
            $old_cap     = (float)($old_fuel['capacity'] ?? 0);
            $old_crit    = (float)($old_fuel['critical_level'] ?? 0);
            $old_reorder = (float)($old_fuel['reorder_level'] ?? 0);
            $old_status  = strtolower($old_fuel['status'] ?? 'active');

            // ── Admin is owner: cancel any pending price requests for this fuel ──
            // Admin direct edit supersedes any pending manager request
            try {
                $cancel_params = array_merge([$station_id], $matching_ids);
                $pdo->prepare("UPDATE pending_price_approvals SET status='cancelled', updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                    ->execute($cancel_params);
            } catch (Exception $e) { /* column may not exist — silently ignore */ }

            // ── Log configuration changes (non-price fields) ──
            if (!empty($fuel_name) && strcasecmp($old_name, $fuel_name) !== 0) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Fuel Name', ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel_name, $old_name, $fuel_name, $me['id'], $user_name]);
            }
            if (abs($old_cap - $capacity) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Capacity', ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $old_name, number_format($old_cap, 2) . ' L', number_format($capacity, 2) . ' L', $me['id'], $user_name]);
            }
            if (abs($old_crit - $critical_level) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Critical Level', ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $old_name, number_format($old_crit, 2) . ' L', number_format($critical_level, 2) . ' L', $me['id'], $user_name]);
            }
            if (abs($old_reorder - $reorder_level) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Reorder Level', ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $old_name, number_format($old_reorder, 2) . ' L', number_format($reorder_level, 2) . ' L', $me['id'], $user_name]);
            }
            if (strcasecmp($old_status, $status_val) !== 0) {
                $status_label = (strtolower($status_val) === 'active') ? 'Activated' : 'Deactivated';
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Direct Admin Edit', ?, ?, NOW())")
                    ->execute([$station_id, $id, $old_name, ucfirst($old_status), ucfirst($status_val), $status_label, $me['id'], $user_name]);
            }

            // ── Admin always updates ALL fields immediately — no approval needed ──
            $upd_params = array_merge([$price, $capacity, $critical_level, $reorder_level, $status_val, $me['id']], $matching_ids);
            $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, capacity=?, critical_level=?, reorder_level=?, status=?, updated_by=?, last_updated=NOW() WHERE id IN ($in_clause)")
                ->execute($upd_params);

            // ── Sync to fuel_types & fuel_pricing across system ──
            $fuel_type_id = $old_fuel['fuel_type_id'] ?? null;
            if ($fuel_type_id) {
                try {
                    $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")
                        ->execute([$price, $fuel_type_id]);
                } catch (Exception $e) {}

                try {
                    $fp_stmt = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                    $fp_stmt->execute([$station_id, $fuel_type_id]);
                    $fp_id = $fp_stmt->fetchColumn();
                    if ($fp_id) {
                        $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$price, $fp_id]);
                    } else {
                        $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                            ->execute([$station_id, $fuel_type_id, $price, $me['id']]);
                    }
                } catch (Exception $e) {}
            }

            // ── Log price change to history if price changed ──
            if (abs($price - $old_price) > 0.001) {
                $diff = $price - $old_price;
                $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history
                    (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Direct Admin Edit', ?, ?, 'Approved', NOW())");
                foreach ($matching_ids as $m_id) {
                    $hist_stmt->execute([
                        $station_id, $m_id, $old_name, $old_price, $price, $diff, $me['id'], $me['id']
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Direct Admin Edit Fuel', "Admin directly updated {$old_name}: Price ₱{$old_price} -> ₱{$price}, Capacity {$capacity}L, Critical {$critical_level}L, Reorder {$reorder_level}L");
            $_SESSION['success'] = "Fuel product updated successfully!";
        }
        header("Location: admin_set_prices.php?tab=fuel");
        exit;
    } elseif ($action === 'toggle_fuel_status_admin') {
        $id          = (int)($_POST['id'] ?? 0);
        $new_status  = trim($_POST['status'] ?? 'active');
        $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
        $stmt_f->execute([$id, $station_id]);
        $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);

        if ($old_fuel) {
            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
            $old_st_text = ucfirst(strtolower($old_fuel['status'] ?? 'active'));
            $new_st_text = ucfirst(strtolower($new_status));
            $status_label = (strtolower($new_status) === 'active') ? 'Activated' : 'Deactivated';
            $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Admin Action', ?, ?, NOW())")
                ->execute([$station_id, $id, $old_fuel['fuel_type'], $old_st_text, $new_st_text, $status_label, $me['id'], $user_name]);

            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $id, $old_fuel['fuel_type']);
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));
            $upd_params = array_merge([$new_status, $me['id']], $matching_ids);
            $pdo->prepare("UPDATE fuel_inventory SET status=?, updated_by=?, last_updated=NOW() WHERE id IN ($in_clause)")
                ->execute($upd_params);
            log_activity($pdo, $me['id'], 'Toggle Fuel Status', "Admin set status of {$old_fuel['fuel_type']} to {$new_status}");
            $_SESSION['success'] = "Fuel status updated to " . ucfirst($new_status) . ".";
        }
        header("Location: admin_set_prices.php?tab=fuel");
        exit;
    }
    header("Location: admin_set_prices.php?tab=" . urlencode($redirect_tab));
    exit;
}



// ── Fetch station name ──────────────────────────────────────────────────────────────
$station_name = 'Unknown Station';
try {
    $stmt_sn = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $stmt_sn->execute([$station_id]);
    $station_name = $stmt_sn->fetchColumn() ?: 'Unknown Station';
} catch (Exception $e) { /* silent */ }

// Helper function to get the canonical 5 fuel types
if (!function_exists('get_canonical_fuel_name')) {
    function get_canonical_fuel_name($name) {
        $name_lower = strtolower(trim($name));
        if (strpos($name_lower, 'turbo') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($name_lower, 'diesel') !== false) {
            return 'Diesel';
        } elseif (strpos($name_lower, 'kerosene') !== false) {
            return 'Kerosene';
        } elseif (strpos($name_lower, 'xcs') !== false) {
            return 'XCS Plus';
        } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
            return 'Xtra UNL';
        }
        return $name;
    }
}

// ── Fetch fuel inventory ────────────────────────────────────────────────────
$fuel_products = [];
try {
    $TANK_CONFIG_17 = get_tank_config((int)$station_id);
    $target_sid = (int)$station_id;
    

    $fi_lookup = [];
    $fi_lookup_by_id = [];
    $fi_status_by_id = [];
    $s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_val  = strtolower(trim($row['ugt_no'] ?? ''));

        // Index by full fuel_type name
        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        // Index by UGT number string (e.g. "ugt #1")
        if ($ugt_val) {
            $fi_lookup[$ugt_val] = $row;
        }

        // ── Extra canonical/variant keys so TANK_CONFIG ft_key matches ─────
        // e.g. fuel_type='Diesel 1 (UGT #1)' -> also store under 'diesel 1', 'diesel', 'diesel 1 (ugt #1)'
        // Extract number suffix from fuel_type if present
        if (preg_match('/diesel\s*(\d)/i', $fuel_key, $m)) {
            $k = 'diesel ' . $m[1];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
        }
        if (strpos($fuel_key, 'diesel') !== false && strpos($fuel_key, 'turbo') === false) {
            if (!isset($fi_lookup['diesel'])) $fi_lookup['diesel'] = $row;
        }
        if (preg_match('/(xtra unl|xtra unl)\s*(\d)/i', $fuel_key, $m)) {
            $k = 'xtra unl ' . $m[2];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xtra') !== false || strpos($fuel_key, 'unl') !== false) {
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xcs') !== false) {
            if (!isset($fi_lookup['xcs plus'])) $fi_lookup['xcs plus'] = $row;
        }
        if (strpos($fuel_key, 'kerosene') !== false) {
            if (!isset($fi_lookup['kerosene'])) $fi_lookup['kerosene'] = $row;
        }
        if (strpos($fuel_key, 'turbo') !== false) {
            if (!isset($fi_lookup['turbo diesel'])) $fi_lookup['turbo diesel'] = $row;
        }

        $fi_lookup_by_id[(int)$row['id']] = $row;
        $st_lower = strtolower(trim($row['status'] ?? ''));
        $fi_status_by_id[(int)$row['id']] = in_array($st_lower, ['inactive', 'disabled', 'deactivated'], true) ? 'inactive' : 'active';
    }


    $del_lookup = [];
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }

    $sales_lookup = [];
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }

    $adj_lookup = [];
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }

    $price_lookup = [];
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? AND fp.is_active = 1 ORDER BY fp.effective_date DESC");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }

    $pending_approvals = [];
    try {
        $s_pa = $pdo->prepare("SELECT id AS approval_id, product_id, fuel_type_id, product_name, COALESCE(new_price, new_value) AS new_value, old_price, new_price, status, reason, requested_by, created_at, station_id FROM pending_price_approvals WHERE status = 'pending' AND product_type IN ('fuel', 'fuel_inventory')");
        $s_pa->execute();
        foreach ($s_pa->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
            $pid = (int)($p_row['product_id'] ?? 0);
            $ftid = (int)($p_row['fuel_type_id'] ?? 0);
            $pname = strtolower(trim($p_row['product_name'] ?? ''));

            if ($pid > 0) {
                $pending_approvals['id_' . $pid] = $p_row;
            }
            if ($ftid > 0) {
                $pending_approvals['id_' . $ftid] = $p_row;
            }
            if ($pname) {
                $pending_approvals['name_' . $pname] = $p_row;
                $canonical_pname = strtolower(get_canonical_fuel_name($pname));
                $pending_approvals['canon_' . $canonical_pname] = $p_row;
            }
        }
    } catch (Exception $e) {}

    foreach ($TANK_CONFIG_17 as $tc) {
        $ft_key = strtolower(trim($tc['fuel_type']));
        $tank_num = $tc['tanker_num'];

        $inv = null;
        if (isset($fi_lookup[$ft_key . '_tank_' . $tank_num])) {
            $inv = $fi_lookup[$ft_key . '_tank_' . $tank_num];
        } elseif (isset($fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))])) {
            $inv = $fi_lookup[$ft_key . '_' . strtolower(trim($tc['tank']))];
        } elseif (isset($fi_lookup[$ft_key . '_' . strtolower(trim($tc['label']))])) {
            $inv = $fi_lookup[$ft_key . '_' . strtolower(trim($tc['label']))];
        } elseif ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $inv = $fi_lookup[$cand]; }
            else { $inv = $fi_lookup['xtra unl'] ?? null; }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $inv = $fi_lookup[$cand]; }
            else { $inv = $fi_lookup['diesel'] ?? null; }
        } else {
            $inv = $fi_lookup[$ft_key] ?? null;
        }

        $tank_key = strtolower(trim($tc['tank']));
        $capacity  = (float)$tc['capacity'];
        $cur_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;

        $same_type_count = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key, $fi_lookup) {
            $k = strtolower(trim($t['fuel_type']));
            if ($k === 'xtra unl' || $k === 'xtr advance') {
                $cand = '';
                if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'xtra unl 1'; }
                elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'xtra unl 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
                else { $k = 'xtra unl'; }
            } elseif ($k === 'diesel') {
                $cand = '';
                if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'diesel 1'; }
                elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'diesel 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
                else { $k = 'diesel'; }
            }
            return $k === $ft_key;
        }));
        $purchases = $del_lookup[$tank_key] ?? 0;

        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;
        $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

        $beginning = $same_type_count > 0 ? round($cur_level / $same_type_count, 2) : 0;
        $total_available = $beginning + $purchases;
        $ending_system   = min(max(0, $total_available - $sales - $calibration), $capacity);

        if ($capacity == 14000) {
            $critical_lvl = 2500; $low_lvl = 5000;
        } elseif ($capacity == 7000) {
            $critical_lvl = 1000; $low_lvl = 2000;
        } else {
            $critical_lvl = $capacity * 0.10; $low_lvl = $capacity * 0.20;
        }

        if ($ending_system <= 0) {
            $status = 'Out of Stock';
        } elseif ($ending_system <= $critical_lvl) {
            $status = 'Critical';
        } elseif ($ending_system <= $low_lvl) {
            $status = 'Low';
        } else {
            $status = 'Normal';
        }

        $price = ($inv && (float)($inv['price_per_liter'] ?? 0) > 0) ? (float)$inv['price_per_liter'] : ($price_lookup[$ft_key] ?? 0);
        $timestamp = $inv['last_updated'] ?? null;

        $critical_level = $inv ? (float)($inv['critical_level'] ?? 0) : 0;
        if ($critical_level <= 0) {
            $critical_level = ($capacity == 14000) ? 2500 : (($capacity == 7000) ? 1000 : $capacity * 0.10);
        }
        $reorder_level = $inv ? (float)($inv['reorder_level'] ?? 0) : 0;
        if ($reorder_level <= 0) {
            $reorder_level = ($capacity == 14000) ? 5000 : (($capacity == 7000) ? 2000 : $capacity * 0.20);
        }

        $inv_id = $inv['id'] ?? null;
        $inv_name = strtolower(trim($inv['fuel_type'] ?? $tc['fuel_type'] ?? ''));
        $inv_canonical = strtolower(get_canonical_fuel_name($inv_name));
        $ugt_name = strtolower(trim($tc['tank'] ?? ''));

        $app = null;
        if ($inv_id && isset($pending_approvals['id_' . (int)$inv_id])) {
            $app = $pending_approvals['id_' . (int)$inv_id];
        } elseif ($inv_name && isset($pending_approvals['name_' . $inv_name])) {
            $app = $pending_approvals['name_' . $inv_name];
        } elseif ($inv_canonical && isset($pending_approvals['canon_' . $inv_canonical])) {
            $app = $pending_approvals['canon_' . $inv_canonical];
        } elseif ($ugt_name && isset($pending_approvals['name_' . $ugt_name])) {
            $app = $pending_approvals['name_' . $ugt_name];
        }

        $fuel_products[] = [
            'id'             => $inv_id,
            'pump_id'        => $tc['tanker_num'],
            'ugt_no'         => $tc['tank'],
            'tank_label'     => $tc['label'],
            'raw_fuel_type'  => $tc['fuel_type'],
            'capacity'       => $capacity,
            'current_stock'  => $ending_system,
            'critical_level' => $critical_level,
            'reorder_level'  => $reorder_level,
            'status'         => $status,
            'inv_status'     => $inv_id ? ($fi_status_by_id[(int)$inv_id] ?? 'active') : 'active',
            'last_updated'   => $timestamp,
            'price_per_liter'=> $price,
            'pending_price'  => $app ? (float)$app['new_value'] : null,
            'approval_status'=> $app ? $app['status'] : null,
            'approval_id'    => $app ? $app['approval_id'] : null
        ];
    }
} catch (Exception $e) {
    $fuel_products = [];
    error_log('[admin_set_prices] fuel error: ' . $e->getMessage());
}

// ── Fetch merchandise grouped by category ───────────────────────────────────
$merch_by_cat   = [];
$merch_all      = [];
$merch_stats    = ['total' => 0, 'valid_price' => 0, 'below_cost' => 0, 'unpriced' => 0];
$all_categories = [];
$all_brands     = [];
$all_units      = [];
$all_suppliers  = [];

try {
    $rows = load_merchandise_pricing_catalog($pdo, (int)$station_id);

    foreach ($rows as $row) {
        $cat    = $row['category_name'] ?? $row['category'] ?? 'Uncategorized';
        $cost   = (float)($row['unit_cost']  ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (float)($row['stock_quantity'] ?? $row['stock'] ?? 0);

        $merch_stats['total']++;
        if ($price <= 0) {
            $merch_stats['unpriced']++;
        } elseif ($price < $cost) {
            $merch_stats['below_cost']++;
        } else {
            $merch_stats['valid_price']++;
        }

        $row['_cost']  = $cost;
        $row['_price'] = $price;
        $row['_stock'] = $stock;

        $merch_by_cat[$cat][] = $row;
        $merch_all[]          = $row;
        $all_categories[$cat] = true;
        $all_brands[$row['brand'] ?? 'Generic'] = true;
        $all_units[$row['unit'] ?? 'Piece (pc)'] = true;
        $all_suppliers[$row['supplier'] ?? 'Petron Corporation'] = true;
    }
} catch (Exception $e) {
    $merch_by_cat = [];
}

// ── Admin Summary Metrics ───────────────────────────────────────────────────
$approved_today_count = 0;
$pending_requests_count = 0;
try {
    $approved_today_count = (int)$pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE status = 'approved' AND (DATE(reviewed_at) = CURDATE() OR DATE(updated_at) = CURDATE())
    ")->fetchColumn();

    $pending_requests_count = (int)$pdo->query("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE status = 'pending' AND product_type = 'merchandise'
    ")->fetchColumn();
} catch (Exception $e) {}


// ── Pre-load merchandise batches per product ──────────────────────────────
$merch_batches_by_product = [];
try {
    $bStmt = $pdo->prepare("
        SELECT mb.*
        FROM merchandise_batches mb
        WHERE mb.station_id = ? AND LOWER(COALESCE(mb.status, 'active')) NOT IN ('cancelled', 'disabled')
        ORDER BY mb.date_received ASC, mb.id ASC
    ");
    $bStmt->execute([(int)$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $merch_batches_by_product[(int)$b['product_id']][] = $b;
    }
} catch (Exception $e) {}

$all_categories = array_keys($all_categories);
sort($all_categories);
$all_brands = array_keys($all_brands);
sort($all_brands);
$all_units = array_keys($all_units);
sort($all_units);
$all_suppliers = array_keys($all_suppliers);
sort($all_suppliers);

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Admin viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

// ── Fetch service types with pending approvals ─────────────────────────────
$service_types = [];
$service_error = null;
try {
    $stmt = $pdo->prepare("
        SELECT s.id,
               COALESCE(s.service_code, CONCAT('SRV-', LPAD(s.id,4,'0'))) AS service_code,
               s.service_name, s.service_key, s.category, s.service_price,
               s.labor_fee, s.estimated_duration, s.required_mechanics,
               s.description, s.active, s.updated_at,
               p.new_price     AS pending_price,
               p.new_cost      AS pending_labor_fee,
               p.old_price     AS old_service_fee,
               p.old_cost      AS old_labor_fee,
               p.manager_id    AS pending_manager_id,
               p.status        AS approval_status,
               p.id            AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.product_type IN ('service', 'service_type')
              AND p.status = 'pending'
        ORDER BY s.service_name
    ");
    $stmt->execute();
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add manager names in a second pass
    foreach ($service_types as &$svc) {
        $svc['manager_name'] = null;
        if (!empty($svc['pending_manager_id'])) {
            try {
                $uStmt = $pdo->prepare("SELECT COALESCE(CONCAT(first_name,' ',last_name), username) FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$svc['pending_manager_id']]);
                $svc['manager_name'] = $uStmt->fetchColumn() ?: 'Unknown';
            } catch (Exception $ue) {
                $svc['manager_name'] = 'Unknown';
            }
        }
    }
    unset($svc);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null; // suppress debug output in production
    error_log("[admin_set_prices] service types error: " . $e->getMessage());
}

// ── Active tab (persists across refresh via ?tab= query param) ───────────────
$active_tab = $_GET['tab'] ?? 'fuel';
if (!in_array($active_tab, ['fuel', 'merch', 'services'])) $active_tab = 'fuel';

// ── AJAX JSON POLLING ENDPOINT FOR ADMIN PRODUCT & PRICING OVERVIEW ─────────
if (isset($_GET['ajax_asp']) && $_GET['ajax_asp'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'counts' => [
            'fuel_count'      => count($fuel_products),
            'merch_count'     => count($merch_all),
            'service_count'   => count($service_types),
            'total_count'     => count($fuel_products) + count($merch_all) + count($service_types),
            'approved_today'  => (int)$approved_today_count,
            'pending_requests'=> (int)$pending_requests_count
        ]
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page-level styles ─────────────────────────────────────────────────────── */
.main-content {
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
}
.page-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 25px !important;
    margin-top: 0 !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
}
.page-head h1, .page-head .h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}


/* Summary cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 16px 18px; text-align: center;
}
.summary-card .s-num  { font-size: 28px; font-weight: 700; line-height: 1; text-decoration: none !important; }
.summary-card .s-lbl  { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 500; }
.summary-card.s-total  .s-num { color: #002F6C; }
.summary-card.s-valid  .s-num { color: #16a34a; }
.summary-card.s-below  .s-num { color: #dc2626; }
.summary-card.s-unpriced .s-num { color: #d97706; }

/* Toolbar */
.toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 16px;
}
.toolbar input[type="text"],
.toolbar select {
    padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; color: #334155; background: #fff;
}
.toolbar input[type="text"] {  }
.toolbar input[type="text"]:focus,
.toolbar select:focus { outline: none; border-color: #002F6C; box-shadow: 0 0 0 2px rgba(0,47,108,.12); }

/* Readonly notice */
.readonly-notice {
    display: inline-flex; align-items: center; gap: 6px;
    background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
    border-radius: 20px; padding: 4px 14px; font-size: 12px; font-weight: 600;
}

/* Table tweaks - Fix horizontal overflow */
.table-wrap {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.pricing-table {
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
}
#adminMerchTable {
    table-layout: fixed !important;
}

/* ── Section Tabs - Reports-style boxed design ── */
.ato-tab-bar {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.ato-tab {
    flex: 1 !important; min-width: 140px !important;
    padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 7px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important;
}
.ato-tab:last-child { border-right: none !important; }
.ato-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.ato-tab.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
    border-radius: 0 !important; border-bottom-color: transparent !important;
}

/* Tab Panel Visibility - Only active tab panel is displayed */
.tab-panel {
    display: none !important;
}
.tab-panel.active {
    display: block !important;
}

/* == Action buttons — ultra crisp & visible outline style == */
.act-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    padding: 5px 10px !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    width: 100% !important;
    max-width: 110px !important;
    margin-bottom: 4px !important;
    transition: all .18s ease-in-out !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    opacity: 1 !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }

/* View buttons in Admin are sleek GREY (#475569) so GREEN (#16a34a) is reserved exclusively for Approve Price / Activate */
.act-btn-view { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-viewreq { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-viewreq:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-batches { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-batches:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-history { color: #64748b !important; -webkit-text-fill-color: #64748b !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-history:hover { background: #64748b !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #64748b !important; }

.act-btn-edit { color: #002F6C !important; -webkit-text-fill-color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #002F6C !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #002F6C !important; }

.act-btn-deactivate { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #dc2626 !important; }

.act-btn-activate { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-activate:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #16a34a !important; }

/* Approve Price is GREEN */
.act-btn-approve { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-approve:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #16a34a !important; }

/* Reject Price is RED */
.act-btn-reject { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.act-btn-reject:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #dc2626 !important; }

/* Restore Fees is ORANGE */
.act-btn-restore { color: #d97706 !important; -webkit-text-fill-color: #d97706 !important; border-color: #d97706 !important; background: #ffffff !important; }
.act-btn-restore:hover { background: #d97706 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #d97706 !important; }

.act-btn i { color: inherit !important; -webkit-text-fill-color: inherit !important; }
.act-btn-wrap { display: flex; flex-direction: column; gap: 3px; width: 100%; align-items: center; }
.pricing-table th {
    background: #002F6C !important; 
    color: #ffffff !important; 
    padding: 10px 8px !important; 
    text-align: left;
    font-size: 11px !important; 
    font-weight: 700; 
    text-transform: uppercase;
    letter-spacing: .4px; 
    border-bottom: 2px solid #001f48 !important; 
    white-space: nowrap;
}
.pricing-table td {
    padding: 6px 5px !important;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    white-space: normal !important;
    word-break: break-word !important;
    font-size: 11px !important;
}
.pricing-table tbody tr:hover { background: #e3f2fd; }

/* Category header row */
.cat-row td {
    background: #f1f5f9 !important; font-weight: 700; font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px; color: #475569;
    padding: 7px 12px; border-bottom: 1px solid #e2e8f0;
}

/* Row highlight for price-below-cost */
.row-below-cost { background: #fff5f5 !important; }
.row-below-cost:hover { background: #fee2e2 !important; }

/* Badges */
.badge-normal    { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-available { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-ok        { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-active    { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-low       { background: #fef3c7 !important; color: #b45309 !important; border: 1px solid #fde68a !important; }
.badge-critical  { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }
.badge-out       { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }
.badge-inactive  { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }
.badge-noprice   { background: #fef3c7 !important; color: #92400e !important; border: 1px solid #fde68a !important; }
.badge-warn      { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }

.badge {
    display: inline-block; padding: 3px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}

/* Export buttons */
.btn-export-csv { background: #16a34a; color: #fff; border: none; }
.btn-export-csv:hover { background: #15803d; }
.btn-export-pdf { background: #7c3aed; color: #fff; border: none; }
.btn-export-pdf:hover { background: #6d28d9; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}
</style>

<div class="main-content">
<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Overview</h1>
    </div>
</div>


<!-- ── Section Tabs ──────────────────────────────────────────────────── -->
<input type="hidden" id="activeSection" value="<?php echo htmlspecialchars($active_tab); ?>">
<div class="ato-tab-bar">
    <a onclick="switchTab('fuel')" id="tab-btn-fuel" class="ato-tab <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>"><i class="fas fa-gas-pump"></i> Fuel Products</a>
    <a onclick="switchTab('merch')" id="tab-btn-merch" class="ato-tab <?php echo $active_tab === 'merch' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Merchandise</a>
    <a onclick="switchTab('services')" id="tab-btn-services" class="ato-tab <?php echo $active_tab === 'services' ? 'active' : ''; ?>"><i class="fas fa-wrench"></i> Service Types</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — FUEL PRODUCTS (ADMIN OVERVIEW & APPROVALS)
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-fuel" class="tab-panel <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">

<?php if (!empty($_SESSION['success'])): ?>
    <div style="background:#dcfce7;border:1.5px solid #86efac;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#166534;font-weight:600;">
        <i class="fas fa-check-circle" style="font-size:16px;"></i>
        <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
    </div>
<?php elseif (!empty($_SESSION['warning'])): ?>
    <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#92400e;font-weight:600;">
        <i class="fas fa-lock" style="font-size:16px;"></i>
        <span><?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?></span>
    </div>
<?php endif; ?>

    <?php
    $total_fuel_count   = count($fuel_products);
    $pending_req_count  = 0;
    $active_fuel_count  = 0;
    $inactive_fuel_count = 0;

    foreach ($fuel_products as $fp) {
        if (!empty($fp['approval_status']) && $fp['approval_status'] === 'pending') {
            $pending_req_count++;
        }
        if (($fp['inv_status'] ?? 'active') === 'active') {
            $active_fuel_count++;
        } else {
            $inactive_fuel_count++;
        }
    }
    ?>

    <!-- ── 1. Admin Fuel Summary Metric Cards ────────────────────────────── -->
    <div class="summary-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:14px;margin-bottom:16px;">
        <div class="summary-card s-total" onclick="filterAdminFuelByCard('all')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num"><?php echo $total_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-gas-pump"></i> Total Fuel Products</div>
        </div>
        <div class="summary-card" onclick="filterAdminFuelByCard('pending')" style="cursor:pointer;background:#fffbeb;border:1.5px solid #fde68a;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#d97706;"><?php echo $pending_req_count; ?></div>
            <div class="s-lbl" style="color:#b45309;font-weight:700;"><i class="fas fa-clock"></i> Pending Price Requests</div>
        </div>
        <div class="summary-card s-valid" onclick="filterAdminFuelByCard('active')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#16a34a;"><?php echo $active_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-check-circle"></i> Active Products</div>
        </div>
        <div class="summary-card s-below" onclick="filterAdminFuelByCard('inactive')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#dc2626;"><?php echo $inactive_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-ban"></i> Inactive Products</div>
        </div>
    </div>

    <!-- ── 2. Admin Filters Bar ───────────────────────────────────────────── -->
    <div class="toolbar" style="margin-bottom:16px;background:#f8fafc;padding:12px 16px;border-radius:10px;border:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="text" id="adminFuelSearch" placeholder="Search UGT or Fuel Name..." oninput="filterAdminFuelTable()" style="min-width:200px;flex:1;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
        
        <select id="adminFuelTypeFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            <option value="">All Fuel Types</option>
            <option value="Diesel">Diesel</option>
            <option value="Turbo Diesel">Turbo Diesel</option>
            <option value="XCS Plus">XCS Plus</option>
            <option value="XTR ADVANCE">XTR ADVANCE</option>
            <option value="Kerosene">Kerosene</option>
        </select>

        <select id="adminFuelUgtFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            <option value="">All UGTs</option>
            <option value="UGT #1">UGT #1</option>
            <option value="UGT #2">UGT #2</option>
            <option value="UGT #3">UGT #3</option>
            <option value="UGT #4">UGT #4</option>
            <option value="UGT #5">UGT #5</option>
            <option value="UGT #6">UGT #6</option>
            <option value="UGT #7">UGT #7</option>
        </select>

        <select id="adminFuelPriceReqFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            <option value="">All Request Statuses</option>
            <option value="pending">Pending Approval Only</option>
            <option value="none">None / Approved</option>
            <option value="rejected">Rejected</option>
        </select>

        <select id="adminFuelStatusFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            <option value="">All Active/Inactive Statuses</option>
            <option value="active">Active Only</option>
            <option value="inactive">Inactive Only</option>
        </select>
    </div>

    <!-- ── 3. Fuel Inventory & Pricing Table ─────────────────────────────── -->
    <div class="card" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:10px;">

        <div class="table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
            <table class="pricing-table" id="adminFuelTable">
                <thead>
                    <tr>
                        <th>UGT No.</th>
                        <th>Fuel Type</th>
                        <th>Price / Liter (₱)</th>
                        <th>Current Volume (L)</th>
                        <th>Capacity (L)</th>
                        <th>Critical Level (L)</th>
                        <th>Reorder Level (L)</th>
                        <th>Status</th>
                        <th>Price Request Status</th>
                        <th>Last Updated</th>
                        <th style="text-align: center; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminFuelTableBody">
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:28px;color:#94a3b8;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fuel_products as $f):
                        $level    = (float)($f['current_stock'] ?? 0);
                        $critical = (float)($f['critical_level'] ?? 0);
                        $capacity = (float)($f['capacity'] ?? 0);
                        $reorder  = (float)($f['reorder_level'] ?? 0);
                        
                        $raw_status = strtolower(trim($f['status'] ?? 'normal'));
                        if ($level <= 0 || in_array($raw_status, ['out of stock', 'out', 'empty'])) {
                            $status_label = 'Out of Stock';
                            $status_class = 'badge-out';
                            $badge_style  = 'background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;';
                        } elseif (($critical > 0 && $level <= $critical) || in_array($raw_status, ['critical', 'crit'])) {
                            $status_label = 'Critical';
                            $status_class = 'badge-critical';
                            $badge_style  = 'background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;';
                        } elseif (($reorder > 0 && $level <= $reorder) || in_array($raw_status, ['low', 'low stock', 'reorder'])) {
                            $status_label = 'Low Stock';
                            $status_class = 'badge-low';
                            $badge_style  = 'background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;';
                        } else {
                            $status_label = 'Normal';
                            $status_class = 'badge-normal';
                            $badge_style  = 'background:#dcfce7;color:#15803d;border:1px solid #86efac;';
                        }
                        
                        $ugt_str = $f['ugt_no'] ?? ('UGT #' . $f['pump_id']);
                        $canonical_type = get_canonical_fuel_name($f['raw_fuel_type']);
                        $full_fuel_name = $canonical_type;
                        $fuel_active_status = strtolower($f['inv_status'] ?? ($f['status'] ?? 'active'));
                        $req_status = $f['approval_status'] ?? '';
                    ?>
                    <tr class="admin-fuel-row" 
                        data-ugt="<?php echo htmlspecialchars($ugt_str); ?>" 
                        data-fueltype="<?php echo htmlspecialchars($canonical_type); ?>"
                        data-fullname="<?php echo htmlspecialchars($full_fuel_name); ?>"
                        data-reqstatus="<?php echo htmlspecialchars($req_status ?: 'none'); ?>"
                        data-activestatus="<?php echo htmlspecialchars($fuel_active_status); ?>">
                        
                        <!-- UGT No. -->
                        <td>
                            <strong style="font-family:monospace;color:#002F6C;font-size:14px;"><?php echo htmlspecialchars($ugt_str); ?></strong>
                        </td>
                        
                        <!-- Fuel Type -->
                        <td>
                            <strong><?php echo htmlspecialchars($full_fuel_name); ?></strong>
                        </td>
                        
                        <!-- Current Price -->
                        <td>
                            <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format((float)($f['price_per_liter'] ?? 0), 2); ?></strong>
                        </td>
                        
                        <!-- Current Volume (Clean numerical format) -->
                        <td>
                            <?php echo number_format($level, 2); ?>
                        </td>
                        
                        <!-- Capacity -->
                        <td><?php echo number_format($capacity, 2); ?></td>
                        
                        <!-- Critical Level -->
                        <td><?php echo number_format($critical, 2); ?></td>
                        
                        <!-- Reorder Level -->
                        <td><strong style="color:#475569;"><?php echo number_format($reorder, 2); ?></strong></td>
                        
                        <!-- Status -->
                        <td>
                            <span class="badge <?php echo $status_class; ?>" style="<?php echo $badge_style; ?>padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;">
                                <?php echo htmlspecialchars($status_label); ?>
                            </span>
                        </td>
                        
                        <!-- Price Request Status -->
                        <td>
                            <?php if ($req_status === 'pending'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;padding:4px 9px;">
                                    <i class="fas fa-clock"></i> Pending (&#8369;<?php echo number_format((float)$f['pending_price'], 2); ?>)
                                </span>
                            <?php elseif ($req_status === 'rejected'): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:700;padding:4px 9px;">
                                    <i class="fas fa-times-circle"></i> Rejected
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;font-weight:600;padding:4px 9px;">
                                    <i class="fas fa-check-circle"></i> None / Approved
                                </span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Last Updated -->
                        <td class="muted" style="font-size:12px;">
                            <?php echo $f['last_updated'] ? htmlspecialchars(date('M d, Y H:i', strtotime($f['last_updated']))) : '&mdash;'; ?>
                        </td>
                        
                        <!-- Actions Column -->
                        <td style="text-align: center; vertical-align: middle;">
                            <div class="act-btn-wrap">
                                <?php if (!empty($f['id'])): ?>
                                    <!-- <i class="fas fa-eye"></i> View Button -->
                                    <button type="button" onclick="openViewFuelModalAdmin(<?php echo $f['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <!-- Edit Button (Direct Admin Edit) -->
                                    <button type="button" onclick="openEditPriceModalAdmin(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['capacity'] ?? 0); ?>, <?php echo (float)($f['critical_level'] ?? 0); ?>, <?php echo (float)($f['reorder_level'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($ugt_str)); ?>', <?php echo $req_status === 'pending' ? 'true' : 'false'; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <!-- Approve & Reject Buttons (Shown ONLY if there is a pending request!) -->
                                    <?php if ($req_status === 'pending' && !empty($f['approval_id'])): ?>
                                        <button type="button" onclick="openApprovePriceModalAdmin(<?php echo $f['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['pending_price'] ?? 0); ?>)" class="act-btn act-btn-approve">
                                            <i class="fas fa-check"></i> Approve Price
                                        </button>

                                        <button type="button" onclick="openRejectPriceModalAdmin(<?php echo $f['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['pending_price'] ?? 0); ?>)" class="act-btn act-btn-reject">
                                            <i class="fas fa-times"></i> Reject Price
                                        </button>
                                    <?php endif; ?>

                                    <!-- Deactivate / Activate Button -->
                                    <?php if ($fuel_active_status !== 'inactive'): ?>
                                        <button type="button" onclick="openToggleFuelStatusModal(<?php echo $f['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($canonical_type)); ?>')" class="act-btn act-btn-deactivate">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openToggleFuelStatusModal(<?php echo $f['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($canonical_type)); ?>')" class="act-btn act-btn-activate">
                                            <i class="fas fa-check-circle"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;font-style:italic;">No Actions</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — MERCHANDISE PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-merch" class="tab-panel <?php echo $active_tab === 'merch' ? 'active' : ''; ?>">

    <!-- ── 1. Summary Cards ────────────────────────────────────────────────── -->
    <div class="summary-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 18px;">
        <div class="summary-card s-total">
            <i class="fas fa-box" style="font-size:22px;color:#002F6C;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo count($merch_all); ?></div>
            <div class="s-lbl">Total Products</div>
        </div>
        <div class="summary-card s-valid">
            <i class="fas fa-tags" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $merch_stats['valid_price']; ?></div>
            <div class="s-lbl">Current Active Prices</div>
        </div>
        <div class="summary-card s-unpriced">
            <i class="fas fa-hourglass-half" style="font-size:22px;color:#d97706;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $pending_requests_count; ?></div>
            <div class="s-lbl">Pending Price Requests</div>
        </div>
        <div class="summary-card s-total">
            <i class="fas fa-check-double" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $approved_today_count; ?></div>
            <div class="s-lbl">Approved Today</div>
        </div>
    </div>

    <!-- ── 2. Filters Toolbar ─────────────────────────────────────────────── -->
    <div class="toolbar" style="margin-bottom: 16px;">
        <input type="text" id="adminSearchInput" placeholder="&#128269; Search Product / SKU&hellip;" oninput="filterAdminMerchTable()" style="min-width: 220px;">
        
        <select id="adminCatFilter" onchange="filterAdminMerchTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminBrandFilter" onchange="filterAdminMerchTable()">
            <option value="">All Brands</option>
            <?php foreach ($all_brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand); ?>"><?php echo htmlspecialchars($brand); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminUnitFilter" onchange="filterAdminMerchTable()">
            <option value="">All UOMs</option>
            <?php foreach ($all_units as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminProdStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Product Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>

        <select id="adminReqStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Request Statuses</option>
            <option value="current">Current</option>
            <option value="pending">Pending Approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <!-- ── 3. Merchandise Table ────────────────────────────────────────────── -->
    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:hidden; width:100%;">
            <table class="pricing-table" id="adminMerchTable" style="width:100%; table-layout:fixed;">
                <colgroup>
                    <col style="width:90px;">   <!-- SKU -->
                    <col style="width:20%;">    <!-- Product -->
                    <col style="width:150px;"> <!-- Category / Brand -->
                    <col style="width:90px;">   <!-- UOM -->
                    <col style="width:140px;">  <!-- Default Selling Price -->
                    <col style="width:85px;">   <!-- Total Stock -->
                    <col style="width:115px;">  <!-- Request Status -->
                    <col style="width:95px;">   <!-- Product Status -->
                    <col style="width:90px;">   <!-- Updated -->
                    <col style="width:130px;">  <!-- Actions -->
                </colgroup>
                <thead style="background:#002F6C !important;">
                    <tr style="background:#002F6C !important;">
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:left;">SKU</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:left;">Product</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:left;">Category / Brand</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:left;">UOM</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:right;">Default Selling Price</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Total Stock</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Request Status</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Product Status</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Updated</th>
                        <th style="background:#002F6C !important; color:#ffffff !important; font-weight:700; padding:10px 8px; font-size:11px; text-transform:uppercase; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminMerchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="10">
                            <i class="fas fa-folder"></i>
                            <?php echo htmlspecialchars($cat_label); ?>
                            <span class="muted cat-count" style="font-weight:400;margin-left:6px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $price         = $item['_price'];
                        $stock         = $item['_stock'];
                        $updated       = !empty($item['last_updated']) ? date('M d, Y', strtotime($item['last_updated'])) : '&mdash;';
                        $app_status    = strtolower(trim($item['approval_status'] ?? 'current'));
                        if (empty($app_status)) $app_status = 'current';

                        $prod_status   = strtolower(trim($item['status'] ?? 'active'));
                        $is_inactive   = in_array($prod_status, ['inactive', 'disabled', 'deactivated']);
                    ?>
                    <tr class="admin-merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($item['brand'] ?? 'Generic')); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($item['unit'] ?? 'pcs')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-prodstatus="<?php echo $is_inactive ? 'inactive' : 'active'; ?>"
                        data-reqstatus="<?php echo $app_status; ?>"
                        <?php if ($is_inactive): ?>style="opacity:0.6;background:#f8f9fa;"<?php endif; ?>>
                        
                        <!-- SKU -->
                        <td>
                            <code style="font-size:11px;color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:4px;font-weight:700;">
                                <?php echo htmlspecialchars($item['sku'] ?? '—'); ?>
                            </code>
                        </td>

                        <!-- Product -->
                        <td>
                            <strong style="color:#1e293b;font-size:13px;"><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                        </td>

                        <!-- Category / Brand -->
                        <td>
                            <div style="font-weight:600;color:#334155;font-size:12px;"><?php echo htmlspecialchars($cat_label); ?></div>
                            <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($item['brand'] ?? 'Generic'); ?></div>
                        </td>

                        <!-- UOM -->
                        <td style="font-size:12px;color:#334155;font-weight:500;"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>

                        <!-- Default Selling Price -->
                        <td style="text-align:right;">
                            <?php if ($price <= 0): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format($price, 2); ?></strong>
                            <?php endif; ?>
                        </td>

                        <!-- Total Stock -->
                        <td style="text-align:center;">
                            <strong style="font-size:13px;color:#1e293b;"><?php echo number_format($stock, 0); ?></strong>
                        </td>

                        <!-- Request Status -->
                        <td style="text-align:center;">
                            <?php if ($app_status === 'pending'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;"><i class="fas fa-clock" style="font-size:10px;margin-right:3px;"></i> Pending</span>
                            <?php elseif ($app_status === 'approved'): ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;font-weight:700;"><i class="fas fa-check-circle" style="font-size:10px;margin-right:3px;"></i> Approved</span>
                            <?php elseif ($app_status === 'rejected'): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:700;"><i class="fas fa-times-circle" style="font-size:10px;margin-right:3px;"></i> Rejected</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-weight:600;"><i class="fas fa-check" style="font-size:9px;color:#16a34a;margin-right:3px;"></i> Current</span>
                            <?php endif; ?>
                        </td>

                        <!-- Product Status -->
                        <td style="text-align:center;">
                            <?php if ($is_inactive): ?>
                                <span class="badge badge-out">Inactive</span>
                            <?php else: ?>
                                <span class="badge badge-available">Active</span>
                            <?php endif; ?>
                        </td>

                        <!-- Updated -->
                        <td style="text-align:center;font-size:11px;color:#64748b;"><?php echo $updated; ?></td>

                        <!-- Actions -->
                        <td style="text-align:center;">
                            <div class="act-btn-wrap">
                                <?php if ($app_status === 'pending'): ?>
                                    <button onclick="viewAdminMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button onclick="openViewRequestModal(<?php echo $item['approval_id']; ?>)" class="act-btn act-btn-viewreq">
                                        <i class="fas fa-eye"></i> View Request
                                    </button>
                                    <button onclick="openApproveConfirmModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', '<?php echo number_format($price, 2); ?>', '<?php echo number_format($item['pending_price'], 2); ?>')" class="act-btn act-btn-approve">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button onclick="openRejectReasonModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>')" class="act-btn act-btn-reject">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php else: ?>
                                    <button onclick="viewAdminMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button onclick="openAdminEditProductModal(<?php echo $item['id']; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 3 — SERVICE TYPES
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-services" class="tab-panel <?php echo $active_tab === 'services' ? 'active' : ''; ?>">
    
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-wrench"></i> Service Types</strong>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8;">
                <i class="fas fa-wrench" style="font-size:32px;margin-bottom:10px;display:block;"></i>
                No service types found.
            </div>
        <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                <table class="pricing-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:75px;">Code</th>
                            <th style="min-width:120px;">Service Name</th>
                            <th style="width:110px;">Category</th>
                            <th style="text-align:right;width:95px;">Service Fee</th>
                            <th style="text-align:right;width:90px;">Labor Fee</th>
                            <th style="text-align:center;width:70px;">Duration</th>
                            <th style="text-align:center;width:65px;">Mechanics</th>
                            <th style="text-align:center;width:85px;">Status</th>
                            <th style="text-align:center;width:90px;">Last Updated</th>
                            <th style="width:100px;">Manager</th>
                            <th style="text-align:center;width:115px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($service_types as $svc):
                            $svcId        = (int)$svc['id'];
                            $svcCode      = htmlspecialchars($svc['service_code'] ?? ('SRV-' . str_pad($svcId, 4, '0', STR_PAD_LEFT)));
                            $svcName      = htmlspecialchars($svc['service_name']);
                            $svcKey       = htmlspecialchars($svc['service_key'] ?? '');
                            $svcCat       = htmlspecialchars($svc['category'] ?? 'Others');
                            $svcDesc      = htmlspecialchars($svc['description'] ?? '');
                            $currentSvcFee  = (float)($svc['service_price'] ?? 0);
                            $currentLabFee  = (float)($svc['labor_fee'] ?? 0);
                            $duration     = (int)($svc['estimated_duration'] ?? 60);
                            $mechanics    = (int)($svc['required_mechanics'] ?? 1);
                            $isActive     = (int)($svc['active'] ?? 1) === 1;
                            $hasPending   = ($svc['approval_status'] ?? '') === 'pending';
                            $pendSvcFee   = $hasPending ? (float)($svc['pending_price'] ?? 0) : 0;
                            $pendLabFee   = $hasPending ? (float)($svc['pending_labor_fee'] ?? 0) : 0;
                            $oldSvcFee    = (float)($svc['old_service_fee'] ?? $currentSvcFee);
                            $oldLabFee    = (float)($svc['old_labor_fee'] ?? $currentLabFee);
                            $updatedAt    = !empty($svc['updated_at']) ? date('M j, Y', strtotime($svc['updated_at'])) : '—';
                            $hrs          = floor($duration / 60);
                            $mins         = $duration % 60;
                            $durationStr  = ($hrs > 0 ? $hrs . 'h' : '') . ($mins > 0 ? ($hrs > 0 ? ' ' : '') . $mins . 'm' : ($hrs === 0 ? '0m' : ''));
                            $managerName  = htmlspecialchars($svc['manager_name'] ?? '');
                            $approvalId   = (int)($svc['approval_id'] ?? 0);
                            $jsObj = json_encode([
                                'id'                 => $svcId,
                                'service_code'       => $svc['service_code'] ?? '',
                                'service_name'       => $svc['service_name'],
                                'service_key'        => $svc['service_key'] ?? '',
                                'category'           => $svc['category'] ?? '',
                                'service_price'      => $currentSvcFee,
                                'labor_fee'          => $currentLabFee,
                                'estimated_duration' => $duration,
                                'required_mechanics' => $mechanics,
                                'description'        => $svc['description'] ?? '',
                                'active'             => $isActive ? 1 : 0,
                            ], JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                        <tr>
                            <!-- Code -->
                            <td>
                                <span style="font-family:monospace;font-size:11px;color:#0369a1;font-weight:700;background:#e0f2fe;padding:3px 6px;border-radius:5px;letter-spacing:0.2px;"><?php echo $svcCode; ?></span>
                            </td>

                            <!-- Service Name -->
                            <td>
                                <div style="font-weight:600;color:#1e293b;font-size:12.5px;"><?php echo $svcName; ?></div>
                                <?php if ($svcDesc): ?>
                                <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svcDesc; ?>"><?php echo $svcDesc; ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Category -->
                            <td>
                                <span style="background:#f0f7ff;color:#003d7a;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:600;white-space:nowrap;"><?php echo $svcCat; ?></span>
                            </td>

                            <!-- Service Fee -->
                            <td style="text-align:right;">
                                <div style="font-weight:700;color:#002F6C;font-size:13px;">&#8369;<?php echo number_format($currentSvcFee, 2); ?></div>
                                <?php if ($hasPending && $pendSvcFee > 0): ?>
                                <div style="font-size:9.5px;color:#d97706;background:#fef3c7;padding:1px 4px;border-radius:4px;margin-top:2px;font-weight:600;display:inline-block;white-space:nowrap;">
                                    <i class="fas fa-hourglass-half" style="font-size:8.5px;"></i> &#8369;<?php echo number_format($pendSvcFee, 2); ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Labor Fee -->
                            <td style="text-align:right;">
                                <div style="font-weight:600;color:#0369a1;font-size:12.5px;">&#8369;<?php echo number_format($currentLabFee, 2); ?></div>
                                <?php if ($hasPending && $pendLabFee > 0): ?>
                                <div style="font-size:9.5px;color:#d97706;background:#fef3c7;padding:1px 4px;border-radius:4px;margin-top:2px;font-weight:600;display:inline-block;white-space:nowrap;">
                                    <i class="fas fa-hourglass-half" style="font-size:8.5px;"></i> &#8369;<?php echo number_format($pendLabFee, 2); ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Duration -->
                            <td style="text-align:center;">
                                <span style="color:#64748b;font-size:11.5px;white-space:nowrap;"><i class="fas fa-clock" style="color:#94a3b8;font-size:10px;"></i> <?php echo $durationStr; ?></span>
                            </td>

                            <!-- Mechanics -->
                            <td style="text-align:center;">
                                <span style="color:#64748b;font-size:11.5px;"><i class="fas fa-user-cog" style="color:#94a3b8;font-size:10px;"></i> <?php echo $mechanics; ?></span>
                            </td>

                            <!-- Status -->
                            <td style="text-align:center;">
                                <?php if ($isActive): ?>
                                <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;display:inline-block;">Active</span>
                                <?php else: ?>
                                <span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;display:inline-block;">Inactive</span>
                                <?php endif; ?>
                                <?php if ($hasPending): ?>
                                <div style="margin-top:3px;">
                                    <span style="background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:999px;font-size:9.5px;font-weight:700;white-space:nowrap;"><i class="fas fa-hourglass-half" style="font-size:8.5px;"></i> Pending</span>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Last Updated -->
                            <td style="text-align:center;font-size:11px;color:#94a3b8;white-space:nowrap;"><?php echo $updatedAt; ?></td>

                            <!-- Manager -->
                            <td>
                                <?php if ($hasPending && $managerName): ?>
                                <div style="font-size:11.5px;color:#1e293b;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo $managerName; ?>"><?php echo $managerName; ?></div>
                                <div style="font-size:9.5px;color:#94a3b8;">Pending fee</div>
                                <?php else: ?>
                                <span style="color:#cbd5e1;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Action (Admin Functions) -->
                            <td style="text-align:center;vertical-align:middle;padding:4px 3px !important;">
                                <div class="act-btn-wrap">
                                    <?php if ($hasPending): ?>
                                    <button type="button"
                                        onclick="openApprovePriceModalAdmin(<?php echo $approvalId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', <?php echo $currentSvcFee; ?>, <?php echo $pendSvcFee; ?>, 'services')"
                                        class="act-btn act-btn-approve">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button"
                                        onclick="openRejectModal(<?php echo $approvalId; ?>, 'services')"
                                        class="act-btn act-btn-reject">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!$hasPending && ($oldSvcFee !== $currentSvcFee || $oldLabFee !== $currentLabFee)): ?>
                                    <button type="button"
                                        onclick="restoreServiceFees(<?php echo $svcId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', <?php echo $oldSvcFee; ?>, <?php echo $oldLabFee; ?>)"
                                        class="act-btn act-btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="openAdminEditServiceModal(<?php echo $svcId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', '<?php echo htmlspecialchars(addslashes($svc['category']??'')); ?>', '<?php echo htmlspecialchars(addslashes($svc['service_key']??'')); ?>', <?php echo $currentSvcFee; ?>, <?php echo (int)($svc['active']??1); ?>)"
                                        class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($isActive): ?>
                                    <button type="button"
                                        onclick="adminToggleService(<?php echo $svcId; ?>, 0, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>')"
                                        class="act-btn act-btn-deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                    <?php else: ?>
                                    <button type="button"
                                        onclick="adminToggleService(<?php echo $svcId; ?>, 1, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>')"
                                        class="act-btn act-btn-activate">
                                        <i class="fas fa-check-circle"></i> Activate
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Rejection Modal -->
<style>
/* Modal styles matching transaction module design */
.modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.modal.open { display:flex; }
.modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.modal-header h3 { margin:0; font-size:1.05rem; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px; }
.modal-body { padding:24px; flex:1; overflow-y:auto; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #e9ecef; background:#fff; flex-shrink:0; }
.modal-footer .btn { padding:10px 20px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; border:none; }
.modal-footer .btn-cancel { background:#fff; color:#475569; border:1px solid #cbd5e1; }
.modal-footer .btn-cancel:hover { background:#f8fafc; border-color:#94a3b8; }
.modal-footer .btn-reject { background:#dc2626; color:#fff; }
.modal-footer .btn-reject:hover { background:#b91c1c; }
</style>
<div class="modal" id="rejectModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Reject Price Proposal</h3>
    </div>
    <form method="post" id="rejectForm">
      <div class="modal-body">
          <input type="hidden" name="action" value="reject_price">
          <input type="hidden" name="approval_id" id="rejectApprovalId" value="">
          <input type="hidden" name="active_tab" id="rejectActiveTab" value="fuel">
          <label style="display:block; margin-bottom:8px; font-weight:600; font-size:13px; color:#1e293b;">
            Reason for Rejection <span style="color:#dc2626;">*</span>
          </label>
          <textarea name="remarks" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; min-height:100px; transition:border-color .15s;" placeholder="Provide detailed remarks for the manager regarding the price rejection..." required onfocus="this.style.borderColor='#002F70';this.style.boxShadow='0 0 0 3px rgba(0,47,112,.1)'" onblur="this.style.borderColor='#cbd5e1';this.style.boxShadow='none'"></textarea>
          <p style="margin-top:8px; font-size:12px; color:#64748b;">
            <i class="fas fa-info-circle"></i> This feedback will be sent to the manager who submitted the price change request.
          </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-reject">Reject Proposal</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ADMIN MODALS & JAVASCRIPT LOGIC
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. ADMIN EDIT PRODUCT MODAL (Price read-only) -->
<div id="adminEditProductModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:550px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-edit"></i> Edit Product Details</h4>
            <button onclick="closeAdminEditProductModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditProductForm" style="padding:20px;">
            <input type="hidden" id="adminEditId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\.\,\&]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Brand</label>
                    <input type="text" id="adminEditBrand" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">SKU / Product Code</label>
                    <input type="text" id="adminEditSku" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-\_\.]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Unit of Measure (UOM)</label>
                    <input type="text" id="adminEditUnit" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\(\)]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Reorder Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" id="adminEditReorder" min="1" value="24" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" id="adminEditCritical" min="1" value="10" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Product Status <span style="color:#dc2626;">*</span></label>
                    <select id="adminEditStatus" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Default Selling Price (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" min="0" id="adminEditPrice" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
                </div>
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, any price edit you save will take effect immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditProductModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.1 ADMIN EDIT FUEL MODAL -->
<div id="adminEditFuelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-gas-pump"></i> Edit Fuel Product (Admin)</h4>
            <button onclick="closeAdminEditFuelModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditFuelForm" style="padding:20px;">
            <input type="hidden" id="adminEditFuelId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px;">Fuel Type</label>
                <div id="adminEditFuelTypeDisplay" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; background:#f8fafc; color:#1e293b; font-weight:700; border-radius:6px; font-size:13px;"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Price / Liter (₱)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelPrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Capacity (L)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelCapacity" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" placeholder="0.00">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level (L)</label>
                <input type="number" step="0.01" min="0" id="adminEditFuelCritical" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" placeholder="0.00">
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, saving this edit will update live fuel pricing immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditFuelModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.2 ADMIN EDIT SERVICE MODAL -->
<div id="adminEditServiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-wrench"></i> Edit Service Type (Admin)</h4>
            <button onclick="closeAdminEditServiceModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <form id="adminEditServiceForm" style="padding:20px;">
            <input type="hidden" id="adminEditServiceId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="adminEditServiceName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\.\,\&]/g, '');">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditServiceCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Key <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditServiceKey" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\_\-]/g, '');">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Service Price (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" min="0" id="adminEditServicePrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C;" placeholder="0.00" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Status <span style="color:#dc2626;">*</span></label>
                    <select id="adminEditServiceActive" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:10px; font-size:11px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:8px 10px; border-radius:6px;">
                <i class="fas fa-shield-alt"></i> <em>As Admin, saving this edit will update service pricing immediately.</em>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditServiceModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. VIEW REQUEST MODAL -->
<div id="viewRequestModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-file-invoice-dollar"></i> PRICE CHANGE REQUEST</h4>
            <button onclick="closeViewRequestModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="viewRequestContent" style="padding:20px;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- 3. APPROVE CONFIRMATION MODAL -->
<div id="approveConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#16a34a; color:#fff; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-check-circle"></i> Confirm Approval</h4>
        </div>
        <div style="padding:20px;">
            <p style="font-size:14px; color:#1e293b; margin-top:0;">Approve this price change?</p>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px; margin-bottom:20px;">
                <div id="appModalProdName" style="font-weight:700; color:#166534; font-size:14px; margin-bottom:6px;"></div>
                <div style="font-size:13px; color:#334155; display:flex; justify-content:space-between;">
                    <span>Old Price: <strong id="appModalOldPrice" style="color:#64748b;"></strong></span>
                    <span>→</span>
                    <span>New Price: <strong id="appModalNewPrice" style="color:#16a34a;"></strong></span>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <input type="hidden" id="confirmApproveId">
                <button type="button" onclick="closeApproveConfirmModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="button" onclick="confirmApprovePriceRequest()" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-check"></i> Approve Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 4. REJECT REASON MODAL -->
<div id="rejectReasonModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#dc2626; color:#fff; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-times-circle"></i> Reject Price Change</h4>
        </div>
        <div style="padding:20px;">
            <input type="hidden" id="rejectReasonApprovalId">
            <p id="rejectModalProdName" style="font-size:14px; font-weight:600; color:#1e293b; margin-top:0;"></p>
            <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px;">Rejection Reason</label>
            <textarea id="rejectReasonText" rows="3" required placeholder="Enter reason for rejecting this price request&hellip;" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box;"></textarea>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeRejectReasonModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="button" onclick="confirmRejectPriceRequest()" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 5. VIEW PRICE HISTORY MODAL -->
<div id="priceHistoryModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:650px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 id="priceHistoryTitle" style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-history"></i> Price History</h4>
            <button onclick="closePriceHistoryModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="priceHistoryContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- VIEW ADMIN MERCHANDISE DETAILS MODAL -->
<div id="viewAdminMerchModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:flex-start;justify-content:center;padding:85px 20px 70px 20px;box-sizing:border-box;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:920px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100vh - 155px);display:flex;flex-direction:column;">
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-box" style="color:#fff;font-size:18px;"></i>
                <span id="adm_vm_title" style="color:#fff;">MERCHANDISE SPECIFICATION &amp; HISTORY</span>
            </h3>
            <button onclick="closeAdminViewMerchModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:16px;">&times;</button>
        </div>
        <div style="padding:20px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#fff;min-height:0;box-sizing:border-box;">
            <!-- Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;"><i class="fas fa-info-circle"></i> Product Specification &amp; Overview</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:13px;">
                    <div><span style="color:#64748b;font-weight:600;">SKU / Code:</span><br><code id="adm_vm_sku" style="font-weight:800;color:#4f46e5;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;">Barcode:</span><br><strong id="adm_vm_barcode">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Product Name:</span><br><strong id="adm_vm_name" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Category:</span><br><strong id="adm_vm_category">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Brand:</span><br><strong id="adm_vm_brand">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Unit (UOM):</span><br><strong id="adm_vm_unit">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Selling Price:</span><br><strong id="adm_vm_price" style="color:#002F6C;font-size:15px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Cost Price:</span><br><strong id="adm_vm_cost" style="color:#16a34a;">-</strong> <small style="color:#94a3b8;font-size:10px;">(latest Stock-In)</small></div>
                    <div><span style="color:#64748b;font-weight:600;">Total Stock:</span><br><strong id="adm_vm_stock">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Batch Count:</span><br><strong id="adm_vm_batch_count">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Reorder Level:</span><br><strong id="adm_vm_reorder">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Status:</span><br><span id="adm_vm_status">-</span></div>
                </div>
            </div>
            <!-- Batch Summary -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-layer-group" style="color:#0284c7;"></i> Batch Summary <small style="color:#64748b;font-weight:400;">(Read Only)</small></h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:8px 12px;">Batch No.</th><th style="padding:8px 12px;">Remaining Qty</th><th style="padding:8px 12px;">Expiration</th><th style="padding:8px 12px;">Status</th></tr></thead>
                        <tbody id="adm_vm_batches_body"><tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batches</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Price History -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-history" style="color:#4f46e5;"></i> Price History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:8px 12px;">Date</th><th style="padding:8px 12px;">Old Price</th><th style="padding:8px 12px;">New Price</th><th style="padding:8px 12px;">Requested By</th><th style="padding:8px 12px;">Approved By</th><th style="padding:8px 12px;">Status</th></tr></thead>
                        <tbody id="adm_vm_price_history_body"><tr><td colspan="6" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Config History -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-sliders-h" style="color:#d97706;"></i> Configuration History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:8px 12px;">Date</th><th style="padding:8px 12px;">Field</th><th style="padding:8px 12px;">Old Value</th><th style="padding:8px 12px;">New Value</th><th style="padding:8px 12px;">Changed By</th></tr></thead>
                        <tbody id="adm_vm_config_history_body"><tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No changes recorded</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Status History -->
            <div>
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-power-off" style="color:#dc2626;"></i> Status History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:8px 12px;">Date</th><th style="padding:8px 12px;">Old Status</th><th style="padding:8px 12px;">New Status</th><th style="padding:8px 12px;">Changed By</th></tr></thead>
                        <tbody id="adm_vm_status_history_body"><tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button onclick="closeAdminViewMerchModal()" style="background:#00264D !important;color:#fff !important;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<!-- 6. VIEW BATCHES MODAL (ADMIN) -->
<div id="viewAdminBatchesModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:700px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h4 id="adminBatchesTitle" style="margin:0; font-size:16px; font-weight:600;"><i class="fas fa-layer-group"></i> Batch History</h4>
            <button onclick="closeAdminBatchesModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;">&times;</button>
        </div>
        <div id="adminBatchesContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>


<script>
// ── Admin Merchandise Filter Function ──────────────────────────────────────
function filterAdminMerchTable() {
    var q          = (document.getElementById('adminSearchInput').value || '').toLowerCase().trim();
    var catFilter  = document.getElementById('adminCatFilter').value;
    var brandFilter= (document.getElementById('adminBrandFilter').value || '').toLowerCase();
    var unitFilter = (document.getElementById('adminUnitFilter').value || '').toLowerCase();
    var pStFilter  = document.getElementById('adminProdStatusFilter').value;
    var rStFilter  = document.getElementById('adminReqStatusFilter').value;

    var rows       = document.querySelectorAll('#adminMerchBody .admin-merch-row');
    var catHeaders = document.querySelectorAll('#adminMerchBody .cat-row');
    var catVisibleCount = {};

    rows.forEach(function(row) {
        var name     = row.getAttribute('data-name') || '';
        var sku      = row.getAttribute('data-sku')  || '';
        var brand    = row.getAttribute('data-brand') || '';
        var unit     = row.getAttribute('data-unit')  || '';
        var cat      = row.getAttribute('data-cat')   || '';
        var pStatus  = row.getAttribute('data-prodstatus') || '';
        var rStatus  = row.getAttribute('data-reqstatus')  || '';

        var matchQ      = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || brand.indexOf(q) !== -1;
        var matchCat    = !catFilter || cat === catFilter;
        var matchBrand  = !brandFilter || brand === brandFilter;
        var matchUnit   = !unitFilter || unit === unitFilter;
        var matchPStatus= !pStFilter || pStatus === pStFilter;
        var matchRStatus= !rStFilter || rStatus === rStFilter;

        var show = matchQ && matchCat && matchBrand && matchUnit && matchPStatus && matchRStatus;
        row.style.display = show ? '' : 'none';
        if (show) {
            catVisibleCount[cat] = (catVisibleCount[cat] || 0) + 1;
        }
    });

    catHeaders.forEach(function(hdr) {
        var cat = hdr.getAttribute('data-cat-header') || '';
        var count = catVisibleCount[cat] || 0;
        hdr.style.display = count > 0 ? '' : 'none';
    });
}

// ── Professional Toast Banner ─────────────────────────────────────────────
function showCustomAlert(message, type, callback) {
    type = type || 'success';
    var isError = (type === 'error' || type === 'danger' || type === 'warning');
    var isWarning = (type === 'warning');

    var container = document.getElementById('adminToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'adminToastContainer';
        container.style.cssText = 'position:fixed;top:24px;right:24px;z-index:9999999;display:flex;flex-direction:column;gap:10px;max-width:400px;width:calc(100% - 48px);pointer-events:none;';
        document.body.appendChild(container);
    }

    var toast = document.createElement('div');
    var accentColor = isWarning ? '#d97706' : (isError ? '#dc2626' : '#16a34a');
    var iconBg      = isWarning ? '#fef3c7' : (isError ? '#fee2e2' : '#dcfce7');
    var iconClass   = isWarning ? 'fa-exclamation-circle' : (isError ? 'fa-times-circle' : 'fa-check-circle');
    var titleText   = isWarning ? 'Validation Error' : (isError ? 'Action Failed' : 'Action Successful');

    toast.style.cssText = [
        'pointer-events:auto',
        'background:#ffffff',
        'border-radius:12px',
        'padding:16px 18px',
        'box-shadow:0 20px 40px rgba(0,0,0,0.12),0 4px 12px rgba(0,0,0,0.06)',
        'display:flex',
        'align-items:flex-start',
        'gap:14px',
        'border:1px solid #e2e8f0',
        (isError ? 'border-left:5px solid ' + accentColor : ''),
        'transform:translateX(120%)',
        'opacity:0',
        'transition:all 0.35s cubic-bezier(0.16,1,0.3,1)',
        "font-family:'Segoe UI',Roboto,system-ui,sans-serif"
    ].filter(Boolean).join(';');

    toast.innerHTML =
        '<div style="width:38px;height:38px;border-radius:50%;background:' + iconBg + ';color:' + accentColor + ';display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;margin-top:1px;">'
            + '<i class="fas ' + iconClass + '"></i>'
        + '</div>'
        + '<div style="flex:1;min-width:0;">'
            + '<div style="font-size:12px;font-weight:800;color:#0f172a;margin-bottom:3px;letter-spacing:0.01em;">' + titleText + '</div>'
            + '<div style="font-size:13px;font-weight:500;color:#475569;line-height:1.45;">' + message + '</div>'
        + '</div>'
        + (isError ? '<button type="button" onclick="this.closest(\'[style]\').style.opacity=0;setTimeout(function(){this.remove();}.bind(this.closest(\'[style]\')),300);" style="background:none;border:none;color:#94a3b8;font-size:20px;line-height:1;cursor:pointer;padding:0 2px;flex-shrink:0;align-self:flex-start;transition:color 0.15s;" onmouseover="this.style.color=\'#475569\'" onmouseout="this.style.color=\'#94a3b8\'">&times;</button>' : '');

    container.appendChild(toast);
    setTimeout(function() { toast.style.transform = 'translateX(0)'; toast.style.opacity = '1'; }, 20);

    var delay = (typeof callback === 'function') ? 1600 : (isError || isWarning ? 6000 : 4500);
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        toast.style.opacity = '0';
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            if (typeof callback === 'function') callback();
        }, 350);
    }, delay);
}

// ── 1. Admin Edit Product Modal ────────────────────────────────────────────
function openAdminEditProductModal(id) {
    document.getElementById('adminEditId').value = id;
    document.getElementById('adminEditProductModal').style.display = 'flex';
    fetch('admin_set_prices_handler.php?action=get_merch_details_admin&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('adminEditName').value     = i.product_name || '';
                document.getElementById('adminEditCategory').value = i.category || '';
                document.getElementById('adminEditBrand').value    = i.brand || '';
                document.getElementById('adminEditSku').value      = i.sku || '';
                document.getElementById('adminEditUnit').value     = i.unit || 'pcs';
                document.getElementById('adminEditReorder').value  = parseInt(i.reorder_level || 24);
                document.getElementById('adminEditCritical').value = parseInt(i.critical_level || 10);
                document.getElementById('adminEditStatus').value   = i.status || 'active';
                document.getElementById('adminEditPrice').value    = parseFloat(i.unit_price || 0).toFixed(2);
            }
        });
}

function closeAdminEditProductModal() {
    document.getElementById('adminEditProductModal').style.display = 'none';
    document.getElementById('adminEditProductForm').reset();
}

document.getElementById('adminEditProductForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var nameVal  = document.getElementById('adminEditName').value.trim();
    var catVal   = document.getElementById('adminEditCategory').value.trim();
    var priceVal = parseFloat(document.getElementById('adminEditPrice').value || 0);
    var reorderVal = parseFloat(document.getElementById('adminEditReorder').value || 0);
    var criticalVal = parseFloat(document.getElementById('adminEditCritical').value || 0);

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!nameVal || placeholders.includes(nameVal.toLowerCase())) {
        showCustomAlert('Product Name is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditName').focus();
        return;
    }

    if (!catVal || placeholders.includes(catVal.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditCategory').focus();
        return;
    }

    if (isNaN(priceVal) || priceVal <= 0) {
        showCustomAlert('Default Selling Price must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditPrice').focus();
        return;
    }

    if (isNaN(reorderVal) || reorderVal <= 0) {
        showCustomAlert('Reorder Level must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditReorder').focus();
        return;
    }

    if (isNaN(criticalVal) || criticalVal <= 0) {
        showCustomAlert('Critical Level must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditCritical').focus();
        return;
    }

    var fd = new FormData();
    fd.append('action',         'edit_product_admin');
    fd.append('id',             document.getElementById('adminEditId').value);
    fd.append('product_name',   nameVal);
    fd.append('category',       catVal);
    fd.append('brand',          document.getElementById('adminEditBrand').value.trim());
    fd.append('sku',            document.getElementById('adminEditSku').value.trim());
    fd.append('unit',           document.getElementById('adminEditUnit').value.trim());
    fd.append('unit_price',     priceVal);
    fd.append('reorder_level',  reorderVal);
    fd.append('critical_level', criticalVal);
    fd.append('status',         document.getElementById('adminEditStatus').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditProductModal();
                showCustomAlert('Product updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update product.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating product.', 'error'); });
});

// ── 1.1 Admin Edit Fuel Modal ─────────────────────────────────────────────
function openAdminEditFuelModal(id, fuelType, price, capacity, critical) {
    document.getElementById('adminEditFuelId').value = id;
    document.getElementById('adminEditFuelTypeDisplay').textContent = fuelType;
    document.getElementById('adminEditFuelPrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditFuelCapacity').value = parseFloat(capacity || 0).toFixed(2);
    document.getElementById('adminEditFuelCritical').value = parseFloat(critical || 0).toFixed(2);
    document.getElementById('adminEditFuelModal').style.display = 'flex';
}

function closeAdminEditFuelModal() {
    document.getElementById('adminEditFuelModal').style.display = 'none';
    document.getElementById('adminEditFuelForm').reset();
}

document.getElementById('adminEditFuelForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',         'admin_edit_fuel');
    fd.append('id',             document.getElementById('adminEditFuelId').value);
    fd.append('price',          document.getElementById('adminEditFuelPrice').value);
    fd.append('capacity',       document.getElementById('adminEditFuelCapacity').value);
    fd.append('critical_level', document.getElementById('adminEditFuelCritical').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditFuelModal();
                showCustomAlert('Fuel product updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update fuel product.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating fuel product.', 'error'); });
});

// ── 1.2 Admin Edit Service Modal ──────────────────────────────────────────
function openAdminEditServiceModal(id, name, category, key, price, active) {
    document.getElementById('adminEditServiceId').value = id;
    document.getElementById('adminEditServiceName').value = name;
    document.getElementById('adminEditServiceCategory').value = category;
    document.getElementById('adminEditServiceKey').value = key;
    document.getElementById('adminEditServicePrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditServiceActive').value = active ? '1' : '0';
    document.getElementById('adminEditServiceModal').style.display = 'flex';
}

function closeAdminEditServiceModal() {
    document.getElementById('adminEditServiceModal').style.display = 'none';
    document.getElementById('adminEditServiceForm').reset();
}

document.getElementById('adminEditServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var nameVal  = document.getElementById('adminEditServiceName').value.trim();
    var catVal   = document.getElementById('adminEditServiceCategory').value.trim();
    var keyVal   = document.getElementById('adminEditServiceKey').value.trim();
    var priceVal = parseFloat(document.getElementById('adminEditServicePrice').value || 0);

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!nameVal || placeholders.includes(nameVal.toLowerCase())) {
        showCustomAlert('Service Name is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceName').focus();
        return;
    }

    if (!catVal || placeholders.includes(catVal.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceCategory').focus();
        return;
    }

    if (!keyVal || placeholders.includes(keyVal.toLowerCase())) {
        showCustomAlert('Service Key is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceKey').focus();
        return;
    }

    if (isNaN(priceVal) || priceVal <= 0) {
        showCustomAlert('Service Price must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditServicePrice').focus();
        return;
    }

    var fd = new FormData();
    fd.append('action',        'admin_edit_service');
    fd.append('id',            document.getElementById('adminEditServiceId').value);
    fd.append('service_name',  nameVal);
    fd.append('category',      catVal);
    fd.append('service_key',   keyVal);
    fd.append('service_price', priceVal);
    fd.append('active',        document.getElementById('adminEditServiceActive').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditServiceModal();
                showCustomAlert('Service type updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update service type.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating service type.', 'error'); });
});

function adminToggleService(id, active, name) {
    document.getElementById('toggleServiceStatusId').value = id;
    document.getElementById('toggleServiceStatusValue').value = active;
    document.getElementById('toggleServiceStatusName').innerText = name;

    var header     = document.getElementById('toggleServiceStatusHeader');
    var title      = document.getElementById('toggleServiceStatusTitle');
    var hIcon      = document.getElementById('toggleServiceHeaderIcon');
    var btnIcon    = document.getElementById('toggleServiceBtnIcon');
    var desc       = document.getElementById('toggleServiceStatusDesc');
    var confirmBtn = document.getElementById('toggleServiceStatusConfirmBtn');

    var safeName = document.createElement('div');
    safeName.textContent = name;
    var escapedName = safeName.innerHTML;

    if (parseInt(active, 10) === 1) {
        header.style.background = 'linear-gradient(135deg, #16a34a, #15803d)';
        title.innerText = 'Activate Service';
        hIcon.className = 'fas fa-check-circle';
        btnIcon.className = 'fas fa-check-circle';
        desc.innerHTML = 'Are you sure you want to activate <strong style="color:#0f172a;">' + escapedName + '</strong>? This service will become active for job orders.';
        confirmBtn.style.background = '#16a34a';
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Activation';
    } else {
        header.style.background = 'linear-gradient(135deg, #dc2626, #b91c1c)';
        title.innerText = 'Deactivate Service';
        hIcon.className = 'fas fa-ban';
        btnIcon.className = 'fas fa-ban';
        desc.innerHTML = 'Are you sure you want to deactivate <strong style="color:#0f172a;">' + escapedName + '</strong>? Deactivated services will be hidden from new job orders.';
        confirmBtn.style.background = '#dc2626';
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Deactivation';
    }

    document.getElementById('toggleServiceStatusModal').style.display = 'flex';
}

function closeToggleServiceStatusModal() {
    document.getElementById('toggleServiceStatusModal').style.display = 'none';
}

function confirmAdminToggleService() {
    var id     = document.getElementById('toggleServiceStatusId').value;
    var active = document.getElementById('toggleServiceStatusValue').value;

    var fd = new FormData();
    fd.append('action', 'admin_toggle_service');
    fd.append('id', id);
    fd.append('active', active);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeToggleServiceStatusModal();
            if (data.success) {
                showCustomAlert(data.message, 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update service status.', 'error');
            }
        }).catch(function() {
            closeToggleServiceStatusModal();
            showCustomAlert('Network error while updating service status.', 'error');
        });
}

function restoreServiceFees(id, name, oldSvcFee, oldLabFee) {
    document.getElementById('restoreSvcId').value = id;
    document.getElementById('restoreOldSvcFee').value = oldSvcFee;
    document.getElementById('restoreOldLabFee').value = oldLabFee;

    var safeName = document.createElement('div');
    safeName.textContent = name;
    var escapedName = safeName.innerHTML;

    var desc = document.getElementById('restoreSvcDesc');
    desc.innerHTML = 'Are you sure you want to restore previous fees for <strong style="color:#0f172a;">' + escapedName + '</strong>?<br><br>' +
        '• <strong>Service Fee:</strong> ₱' + parseFloat(oldSvcFee).toFixed(2) + '<br>' +
        '• <strong>Labor Fee:</strong> ₱' + parseFloat(oldLabFee).toFixed(2);

    document.getElementById('restoreServiceFeesModal').style.display = 'flex';
}

function closeRestoreServiceFeesModal() {
    document.getElementById('restoreServiceFeesModal').style.display = 'none';
}

function confirmRestoreServiceFees() {
    var id        = document.getElementById('restoreSvcId').value;
    var oldSvcFee = document.getElementById('restoreOldSvcFee').value;
    var oldLabFee = document.getElementById('restoreOldLabFee').value;

    var fd = new FormData();
    fd.append('action', 'restore_service_fees');
    fd.append('id', id);
    fd.append('old_service_fee', oldSvcFee);
    fd.append('old_labor_fee', oldLabFee);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeRestoreServiceFeesModal();
            if (data.success) {
                showCustomAlert(data.message, 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to restore service fees.', 'error');
            }
        }).catch(function() {
            closeRestoreServiceFeesModal();
            showCustomAlert('Network error while restoring service fees.', 'error');
        });
}

// ── 2. View Request Modal ──────────────────────────────────────────────────
function openViewRequestModal(approvalId) {
    document.getElementById('viewRequestContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading details&hellip;</div>';
    document.getElementById('viewRequestModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_request_details&approval_id=' + approvalId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.request) {
                document.getElementById('viewRequestContent').innerHTML = '<div style="color:#dc2626;text-align:center;">Failed to load request details.</div>';
                return;
            }
            var req = data.request;
            var oldP = parseFloat(req.old_price || req.old_value || 0).toFixed(2);
            var newP = parseFloat(req.new_price || req.new_value || 0).toFixed(2);
            var reqBy = req.requested_by_name || 'Manager';
            var reason = req.reason || req.remarks || 'Supplier acquisition cost change';
            var dateReq = (req.created_at || '').substring(0, 16);

            document.getElementById('viewRequestContent').innerHTML = `
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Product:</td><td style="padding:8px 0; font-weight:700; color:#002F6C; text-align:right;">${req.product_name}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Current Price:</td><td style="padding:8px 0; font-weight:600; text-align:right; color:#64748b;">₱${oldP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested Price:</td><td style="padding:8px 0; font-weight:700; text-align:right; color:#16a34a; font-size:15px;">₱${newP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested By:</td><td style="padding:8px 0; text-align:right; font-weight:600;">${reqBy}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Reason:</td><td style="padding:8px 0; text-align:right; color:#334155;">${reason}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Date Requested:</td><td style="padding:8px 0; text-align:right; color:#64748b;">${dateReq}</td></tr>
                </table>
                <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                    <button onclick="closeViewRequestModal(); openApproveConfirmModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}', '${oldP}', '${newP}')" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-check"></i> Approve</button>
                    <button onclick="closeViewRequestModal(); openRejectReasonModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}')" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-times"></i> Reject</button>
                </div>
            `;
        });
}

function closeViewRequestModal() {
    document.getElementById('viewRequestModal').style.display = 'none';
}

// ── 3. Approve Confirmation Modal ──────────────────────────────────────────
function openApproveConfirmModal(approvalId, productName, currentPrice, requestedPrice) {
    document.getElementById('confirmApproveId').value = approvalId;
    document.getElementById('appModalProdName').textContent = productName;
    document.getElementById('appModalOldPrice').textContent = '₱' + currentPrice;
    document.getElementById('appModalNewPrice').textContent = '₱' + requestedPrice;
    document.getElementById('approveConfirmModal').style.display = 'flex';
}

function closeApproveConfirmModal() {
    document.getElementById('approveConfirmModal').style.display = 'none';
}

function confirmApprovePriceRequest() {
    var id = document.getElementById('confirmApproveId').value;
    var fd = new FormData();
    fd.append('action', 'approve_price_request');
    fd.append('approval_id', id);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeApproveConfirmModal();
                showCustomAlert('Price change approved successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to approve request.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while approving request.', 'error'); });
}

// ── 4. Reject Reason Modal ────────────────────────────────────────────────
function openRejectReasonModal(approvalId, productName) {
    document.getElementById('rejectReasonApprovalId').value = approvalId;
    document.getElementById('rejectModalProdName').textContent = productName;
    document.getElementById('rejectReasonText').value = '';
    document.getElementById('rejectReasonModal').style.display = 'flex';
}

function closeRejectReasonModal() {
    document.getElementById('rejectReasonModal').style.display = 'none';
}

function confirmRejectPriceRequest() {
    var id = document.getElementById('rejectReasonApprovalId').value;
    var reason = document.getElementById('rejectReasonText').value.trim();

    if (!reason) {
        showCustomAlert('Please enter a rejection reason before submitting.', 'warning');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'reject_price_request');
    fd.append('approval_id', id);
    fd.append('rejection_reason', reason);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeRejectReasonModal();
                showCustomAlert('Price change request rejected.', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to reject request.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while rejecting request.', 'error'); });
}

// ── 5. View Price History Modal ───────────────────────────────────────────
function openPriceHistoryModal(productId, productName) {
    document.getElementById('priceHistoryTitle').textContent = productName + ' — Price History';
    document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading history&hellip;</div>';
    document.getElementById('priceHistoryModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_price_history&product_id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history || data.history.length === 0) {
                document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-history" style="font-size:32px;margin-bottom:10px;display:block;"></i>No price change history found for this product.</div>';
                return;
            }
            var rows = data.history.map(function(h) {
                var oldP = parseFloat(h.old_price || 0).toFixed(2);
                var newP = parseFloat(h.new_price || 0).toFixed(2);
                var dateStr = (h.date_approved || h.date_requested || '').substring(0, 10);
                var reqBy = h.requested_by || 'Manager';
                var appBy = h.approved_by || 'Admin';
                var statusBadge = h.status === 'approved'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Approved</span>'
                    : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Rejected</span>';

                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px; font-size:12px; color:#64748b;">' + dateStr + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; color:#64748b;">₱' + oldP + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; font-weight:700; color:#002F6C;">₱' + newP + '</td>'
                    + '<td style="padding:8px 10px; font-size:12px;">' + reqBy + '</td>'
                    + '<td style="padding:8px 10px; font-size:12px;">' + appBy + ' ' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('priceHistoryContent').innerHTML =
                '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
                + '<thead><tr style="background:#002F6C; color:#fff;">'
                + '<th style="padding:8px 10px; text-align:left;">Date</th>'
                + '<th style="padding:8px 10px; text-align:right;">Old Price</th>'
                + '<th style="padding:8px 10px; text-align:right;">New Price</th>'
                + '<th style="padding:8px 10px; text-align:left;">Requested By</th>'
                + '<th style="padding:8px 10px; text-align:left;">Approved By</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closePriceHistoryModal() {
    document.getElementById('priceHistoryModal').style.display = 'none';
}

// ── 6. View Batches Modal (Admin) ──────────────────────────────────────────
function viewAdminBatches(productId, productName) {
    document.getElementById('adminBatchesTitle').textContent = productName + ' — Batch History';
    document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches&hellip;</div>';
    document.getElementById('viewAdminBatchesModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_product_batches&id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches || data.batches.length === 0) {
                document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>No batch records found for this product.<br><small>Record a delivery to create the first batch.</small></div>';
                return;
            }
            var batches = data.batches;
            var firstActive = true;
            var rows = batches.map(function(b) {
                var isFirst = firstActive && b.status === 'active';
                if (isFirst) firstActive = false;
                var bNum = b.batch_number || ('B' + String(b.id).padStart(4,'0'));
                var fifo = isFirst ? '<span style="background:#16a34a;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:4px;">NEXT FIFO</span>' : '';
                var statusBadge = b.status === 'active'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;">Active</span>'
                    : '<span style="background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:4px;font-size:11px;">Depleted</span>';

                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px;"><code style="color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:3px;font-size:12px;">' + bNum + '</code>' + fifo + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + parseInt(b.remaining_qty||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#64748b;">&#8369;' + parseFloat(b.unit_cost||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#002F6C;font-weight:600;">&#8369;' + parseFloat(b.selling_price||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;font-size:11px;color:#64748b;">' + (b.date_received||'—').substring(0,10) + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('adminBatchesContent').innerHTML =
                '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
                + '<thead><tr style="background:#002F6C;color:#fff;">'
                + '<th style="padding:10px;text-align:left;">Batch</th>'
                + '<th style="padding:10px;text-align:right;">Remaining</th>'
                + '<th style="padding:10px;text-align:right;">Cost</th>'
                + '<th style="padding:10px;text-align:right;">Selling</th>'
                + '<th style="padding:10px;">Date</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closeAdminBatchesModal() {
    document.getElementById('viewAdminBatchesModal').style.display = 'none';
}

// ── Admin View Merchandise Details Modal ─────────────────────────────────────
function viewAdminMerchandiseDetails(id) {
    document.getElementById('viewAdminMerchModal').style.display = 'flex';
    ['adm_vm_sku','adm_vm_barcode','adm_vm_name','adm_vm_category','adm_vm_brand','adm_vm_unit','adm_vm_price','adm_vm_cost','adm_vm_stock','adm_vm_batch_count','adm_vm_reorder'].forEach(function(el){
        var e = document.getElementById(el); if(e) e.textContent = '...';
    });
    ['adm_vm_batches_body','adm_vm_price_history_body','adm_vm_config_history_body','adm_vm_status_history_body'].forEach(function(el){
        var e = document.getElementById(el);
        if(e) e.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    });

    fetch('admin_set_prices_handler.php?action=get_merchandise_details_admin&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (!data.success) { closeAdminViewMerchModal(); showCustomAlert(data.message || 'Failed to load product details.', 'error'); return; }
        var p = data.product;
        document.getElementById('adm_vm_title').textContent = (p.name || 'Product') + ' — SPECIFICATION & HISTORY';
        document.getElementById('adm_vm_sku').textContent = p.sku || '—';
        document.getElementById('adm_vm_barcode').textContent = p.barcode || '—';
        document.getElementById('adm_vm_name').textContent = p.name || '—';
        document.getElementById('adm_vm_category').textContent = p.category_name || '—';
        document.getElementById('adm_vm_brand').textContent = p.brand || '—';
        document.getElementById('adm_vm_unit').textContent = p.unit || '—';
        document.getElementById('adm_vm_price').textContent = '₱' + parseFloat(p.price || 0).toFixed(2);
        document.getElementById('adm_vm_cost').textContent = '₱' + parseFloat(p.cost || 0).toFixed(2);
        document.getElementById('adm_vm_stock').textContent = parseFloat(p.current_stock || 0).toLocaleString();
        document.getElementById('adm_vm_batch_count').textContent = (p.batch_count || 0) + ' batch(es)';
        document.getElementById('adm_vm_reorder').textContent = p.min_stock_level || '—';
        var stLower = (p.status || 'active').toLowerCase();
        var stColor = stLower === 'active' ? '#16a34a' : '#dc2626';
        var stBg = stLower === 'active' ? '#dcfce7' : '#fee2e2';
        document.getElementById('adm_vm_status').innerHTML = '<span style="background:' + stBg + ';color:' + stColor + ';padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">' + (p.status || 'Active') + '</span>';

        // Batches
        var bb = document.getElementById('adm_vm_batches_body');
        if (data.batches && data.batches.length > 0) {
            bb.innerHTML = data.batches.map(function(b) {
                var stBadge = b.status === 'active' ? '<span style="background:#dcfce7;color:#16a34a;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>' : '<span style="background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:700;">' + b.status + '</span>';
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-family:monospace;font-weight:700;color:#0284c7;">' + (b.batch_number || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + parseFloat(b.remaining_qty || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px 12px;">' + (b.expiration_date || '—') + '</td>' +
                    '<td style="padding:8px 12px;">' + stBadge + '</td></tr>';
            }).join('');
        } else { bb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batch records</td></tr>'; }

        // Price History
        var pb = document.getElementById('adm_vm_price_history_body');
        if (data.price_history && data.price_history.length > 0) {
            pb.innerHTML = data.price_history.map(function(h) {
                var statusColor = h.status === 'approved' ? '#16a34a' : h.status === 'rejected' ? '#dc2626' : '#d97706';
                var statusBg = h.status === 'approved' ? '#dcfce7' : h.status === 'rejected' ? '#fee2e2' : '#fef3c7';
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;">₱' + parseFloat(h.old_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;color:#002F6C;">₱' + parseFloat(h.new_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.requested_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.approved_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;"><span style="background:' + statusBg + ';color:' + statusColor + ';padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">' + (h.status || '—') + '</span></td></tr>';
            }).join('');
        } else { pb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr>'; }

        // Config History
        var cb = document.getElementById('adm_vm_config_history_body');
        if (data.config_history && data.config_history.length > 0) {
            cb.innerHTML = data.config_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.field_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#dc2626;">' + (h.old_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#16a34a;font-weight:700;">' + (h.new_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else { cb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No configuration changes recorded</td></tr>'; }

        // Status History
        var sb = document.getElementById('adm_vm_status_history_body');
        if (data.status_history && data.status_history.length > 0) {
            sb.innerHTML = data.status_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:11px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#64748b;">' + (h.old_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.new_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:11px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else { sb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes recorded</td></tr>'; }
    })
    .catch(function() { closeAdminViewMerchModal(); showCustomAlert('Network error while loading product details.', 'error'); });
}

function closeAdminViewMerchModal() {
    document.getElementById('viewAdminMerchModal').style.display = 'none';
}

// ── Admin Fuel Table Filters ──────────────────────────────────────────────
function filterAdminFuelTable() {
    var searchVal = (document.getElementById('adminFuelSearch') ? document.getElementById('adminFuelSearch').value : '').toLowerCase().trim();
    var fuelTypeVal = document.getElementById('adminFuelTypeFilter') ? document.getElementById('adminFuelTypeFilter').value : '';
    var ugtVal = document.getElementById('adminFuelUgtFilter') ? document.getElementById('adminFuelUgtFilter').value : '';
    var reqStatusVal = document.getElementById('adminFuelPriceReqFilter') ? document.getElementById('adminFuelPriceReqFilter').value : '';
    var statusVal = document.getElementById('adminFuelStatusFilter') ? document.getElementById('adminFuelStatusFilter').value : '';

    var rows = document.querySelectorAll('#adminFuelTableBody tr.admin-fuel-row');
    rows.forEach(function(row) {
        var ugt = row.getAttribute('data-ugt') || '';
        var fueltype = row.getAttribute('data-fueltype') || '';
        var fullname = row.getAttribute('data-fullname') || '';
        var reqstatus = row.getAttribute('data-reqstatus') || 'none';
        var activestatus = row.getAttribute('data-activestatus') || 'active';

        var matchesSearch = !searchVal || ugt.toLowerCase().indexOf(searchVal) !== -1 || fullname.toLowerCase().indexOf(searchVal) !== -1;
        var matchesFuelType = !fuelTypeVal || fueltype === fuelTypeVal || fullname.indexOf(fuelTypeVal) !== -1;
        var matchesUgt = !ugtVal || ugt === ugtVal;
        var matchesReqStatus = !reqStatusVal || (reqStatusVal === 'pending' && reqstatus === 'pending') || (reqStatusVal === 'rejected' && reqstatus === 'rejected') || (reqStatusVal === 'none' && (reqstatus === 'none' || reqstatus === 'approved' || !reqstatus));
        var matchesStatus = !statusVal || activestatus === statusVal;

        if (matchesSearch && matchesFuelType && matchesUgt && matchesReqStatus && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterAdminFuelByCard(type) {
    var searchEl = document.getElementById('adminFuelSearch');
    var fuelTypeEl = document.getElementById('adminFuelTypeFilter');
    var ugtEl = document.getElementById('adminFuelUgtFilter');
    var reqStatusEl = document.getElementById('adminFuelPriceReqFilter');
    var statusEl = document.getElementById('adminFuelStatusFilter');

    if (searchEl) searchEl.value = '';
    if (fuelTypeEl) fuelTypeEl.value = '';
    if (ugtEl) ugtEl.value = '';
    
    if (type === 'all') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = '';
    } else if (type === 'pending') {
        if (reqStatusEl) reqStatusEl.value = 'pending';
        if (statusEl) statusEl.value = '';
    } else if (type === 'active') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = 'active';
    } else if (type === 'inactive') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = 'inactive';
    }
    filterAdminFuelTable();
}

// ── Admin View Fuel Modal ──────────────────────────────────────────────────
function openViewFuelModalAdmin(id) {
    var contentEl = document.getElementById('viewFuelModalAdminContent');
    contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:28px;"></i><br><br>Loading fuel product specifications & history...</div>';
    document.getElementById('viewFuelModalAdmin').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_fuel_details_admin&id=' + id)
        .then(function(r) {
            if (!r.ok) throw new Error('Server error: HTTP ' + r.status);
            return r.text();
        })
        .then(function(text) {
            var data;
            try { data = JSON.parse(text); }
            catch(e) {
                contentEl.innerHTML = '<div style="color:#dc2626;text-align:center;padding:30px;"><i class="fas fa-exclamation-triangle" style="font-size:24px;display:block;margin-bottom:10px;"></i>Server returned an invalid response. Check PHP logs.<br><small style="color:#94a3b8;font-size:11px;margin-top:6px;display:block;">' + text.substring(0, 200) + '</small></div>';
                return;
            }
            if (!data.success || !data.fuel) {
                var errMsg = data.message || 'Failed to load fuel details.';
                contentEl.innerHTML = '<div style="color:#dc2626;text-align:center;padding:30px;"><i class="fas fa-exclamation-circle" style="font-size:24px;display:block;margin-bottom:10px;"></i>' + errMsg + '</div>';
                return;
            }

            var f = data.fuel;
            var req = data.pending_request;
            var history = data.price_history || [];
            var configHist = data.config_history || [];
            var statusHist = data.status_history || [];

            var ugt = f.ugt_no || ('UGT #' + f.pump_id);
            var fName = (f.raw_fuel_type || f.fuel_type || 'Fuel').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
            var curPrice = parseFloat(f.price_per_liter || 0).toFixed(2);
            var capacity = parseFloat(f.capacity || 0).toFixed(2);
            var curStock = parseFloat(f.current_stock || f.current_level || 0).toFixed(2);
            var critical = parseFloat(f.critical_level || 0).toFixed(2);
            var reorder = parseFloat(f.reorder_level || 0).toFixed(2);
            var lastUpd = f.last_updated ? f.last_updated : '—';

            var pendingHtml = '';
            if (req && req.status === 'pending') {
                var oldP = parseFloat(req.old_price || req.old_value || curPrice).toFixed(2);
                var newP = parseFloat(req.new_price || req.new_value || 0).toFixed(2);
                var diffVal = (newP - oldP).toFixed(2);
                var diffBadge = diffVal > 0 
                    ? '<span style="color:#16a34a;font-weight:700;">+₱' + diffVal + '/L</span>'
                    : '<span style="color:#dc2626;font-weight:700;">-₱' + Math.abs(diffVal).toFixed(2) + '/L</span>';
                var reqBy = req.requested_by_name || 'Manager';
                var reasonText = req.reason || 'Price change requested by Manager';

                pendingHtml = `
                    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:16px;margin-bottom:20px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                            <h4 style="margin:0;font-size:14px;color:#92400e;font-weight:800;display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-clock" style="color:#d97706;"></i> PENDING PRICE CHANGE REQUEST
                            </h4>
                            <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;">Action Required</span>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:12px;font-size:13px;margin-bottom:14px;">
                            <div><strong style="display:block;font-size:11px;color:#92400e;">CURRENT PRICE</strong>₱${oldP}</div>
                            <div><strong style="display:block;font-size:11px;color:#92400e;">REQUESTED PRICE</strong><span style="font-weight:800;color:#16a34a;font-size:15px;">₱${newP}</span></div>
                            <div><strong style="display:block;font-size:11px;color:#92400e;">DIFFERENCE</strong>${diffBadge}</div>
                            <div><strong style="display:block;font-size:11px;color:#92400e;">REQUESTED BY</strong>${reqBy}</div>
                            <div><strong style="display:block;font-size:11px;color:#92400e;">DATE REQUESTED</strong>${(req.created_at||'').substring(0,16)}</div>
                        </div>
                        <div style="font-size:12px;color:#78350f;margin-bottom:14px;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                            <strong>Reason:</strong> ${reasonText}
                        </div>
                        <div style="display:flex;justify-content:flex-end;gap:10px;">
                            <button type="button" onclick="closeViewFuelModalAdmin(); openApprovePriceModalAdmin(${req.id}, '${fName.replace(/'/g, "\\'")}', ${oldP}, ${newP})" style="background:#16a34a;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check"></i> Approve Request</button>
                            <button type="button" onclick="closeViewFuelModalAdmin(); openRejectPriceModalAdmin(${req.id}, '${fName.replace(/'/g, "\\'")}', ${oldP}, ${newP})" style="background:#dc2626;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times"></i> Reject Request</button>
                        </div>
                    </div>
                `;
            }

            var priceHistRows = '';
            if (history.length === 0) {
                priceHistRows = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No price change records found.</td></tr>';
            } else {
                priceHistRows = history.map(function(h) {
                    var stBadge = (h.status === 'Approved' || h.status === 'approved')
                        ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Approved</span>'
                        : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">Rejected</span>';
                    var diffVal = parseFloat(h.difference || 0).toFixed(2);
                    var diffStr = diffVal > 0 ? ('+₱' + diffVal) : ('-₱' + Math.abs(diffVal).toFixed(2));
                    return `
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;font-size:12px;color:#64748b;">${(h.created_at||'').substring(0,16)}</td>
                            <td style="padding:8px 10px;font-weight:600;">₱${parseFloat(h.old_price||0).toFixed(2)}</td>
                            <td style="padding:8px 10px;font-weight:700;color:#002F6C;">₱${parseFloat(h.new_price||0).toFixed(2)}</td>
                            <td style="padding:8px 10px;font-size:12px;color:#475569;">${diffStr}</td>
                            <td style="padding:8px 10px;font-size:12px;">${h.requested_by_name || 'Manager'} / ${h.approved_by_name || 'Admin'}</td>
                            <td style="padding:8px 10px;">${stBadge}</td>
                        </tr>
                    `;
                }).join('');
            }

            var configHistRows = '';
            if (configHist.length === 0) {
                configHistRows = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration change records found.</td></tr>';
            } else {
                configHistRows = configHist.map(function(c) {
                    return `
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;font-size:12px;color:#64748b;">${(c.created_at||'').substring(0,16)}</td>
                            <td style="padding:8px 10px;font-weight:700;color:#002F6C;">${c.field_name}</td>
                            <td style="padding:8px 10px;color:#dc2626;font-weight:600;">${c.old_value || '-'}</td>
                            <td style="padding:8px 10px;font-weight:700;color:#16a34a;">${c.new_value || '-'}</td>
                            <td style="padding:8px 10px;font-size:12px;">${c.updated_by_name || 'Manager'}</td>
                        </tr>
                    `;
                }).join('');
            }

            var statusHistRows = '';
            if (statusHist.length === 0) {
                statusHistRows = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status change records found.</td></tr>';
            } else {
                statusHistRows = statusHist.map(function(s) {
                    var oldSt = (s.old_status || (s.status === 'Activated' ? 'Inactive' : (s.status === 'Deactivated' ? 'Active' : 'Active'))).toLowerCase();
                    var newSt = (s.new_status || (s.status === 'Deactivated' ? 'Inactive' : (s.status === 'Activated' ? 'Active' : 'Inactive'))).toLowerCase();
                    
                    var oldBadge = oldSt === 'active'
                        ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>'
                        : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Inactive</span>';
                    
                    var newBadge = newSt === 'active'
                        ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Active</span>'
                        : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Inactive</span>';
                    
                    var reasonTxt = s.reason ? s.reason : '-';

                    return `
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px 10px;font-size:12px;color:#64748b;">${(s.created_at||'').substring(0,16)}</td>
                            <td style="padding:8px 10px;">${oldBadge}</td>
                            <td style="padding:8px 10px;">${newBadge}</td>
                            <td style="padding:8px 10px;font-size:12px;color:#64748b;">${reasonTxt}</td>
                            <td style="padding:8px 10px;font-size:12px;">${s.changed_by_name || 'Manager'}</td>
                        </tr>
                    `;
                }).join('');
            }

            contentEl.innerHTML = `
                <!-- Fuel Specification Overview -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
                    <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                        <i class="fas fa-info-circle" style="color:#002F6C;"></i> Fuel Specification & Overview
                    </h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:13px;">
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">UGT / Tank</strong><span style="font-weight:700;color:#002F6C;font-size:14px;">${ugt}</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Fuel Name</strong><span style="font-weight:700;color:#002F6C;font-size:14px;">${fName}</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Current Price</strong><span style="font-weight:800;color:#002F6C;font-size:16px;">₱${curPrice}</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Current Volume</strong><span style="font-weight:700;color:#334155;">${curStock} L</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Tank Capacity</strong><span style="font-weight:700;color:#334155;">${capacity} L</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Critical Level</strong><span style="font-weight:700;color:#dc2626;">${critical} L</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Reorder Level</strong><span style="font-weight:700;color:#d97706;">${reorder} L</span></div>
                        <div><strong style="display:block;font-size:11px;color:#64748b;text-transform:uppercase;">Last Updated</strong><span style="font-size:12px;color:#475569;">${lastUpd}</span></div>
                    </div>
                </div>

                ${pendingHtml}

                <!-- Price Change History -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;"><i class="fas fa-history"></i> Fuel Price History</h4>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                        <thead>
                            <tr style="background:#002F6C;color:#fff;">
                                <th style="padding:8px 10px;text-align:left;">Date</th>
                                <th style="padding:8px 10px;text-align:left;">Old Price</th>
                                <th style="padding:8px 10px;text-align:left;">New Price</th>
                                <th style="padding:8px 10px;text-align:left;">Difference</th>
                                <th style="padding:8px 10px;text-align:left;">Users</th>
                                <th style="padding:8px 10px;text-align:left;">Status</th>
                            </tr>
                        </thead>
                        <tbody>${priceHistRows}</tbody>
                    </table>
                </div>

                <!-- Configuration Change History -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;"><i class="fas fa-sliders-h"></i> Configuration Change History</h4>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                        <thead>
                            <tr style="background:#002F6C;color:#fff;">
                                <th style="padding:8px 10px;text-align:left;">Date</th>
                                <th style="padding:8px 10px;text-align:left;">Field Changed</th>
                                <th style="padding:8px 10px;text-align:left;">Old Value</th>
                                <th style="padding:8px 10px;text-align:left;">New Value</th>
                                <th style="padding:8px 10px;text-align:left;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody>${configHistRows}</tbody>
                    </table>
                </div>

                <!-- Status Change History -->
                <div>
                    <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;"><i class="fas fa-toggle-on"></i> Status Change History</h4>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                        <thead>
                            <tr style="background:#002F6C;color:#fff;">
                                <th style="padding:8px 10px;text-align:left;">Date</th>
                                <th style="padding:8px 10px;text-align:left;">Old Status</th>
                                <th style="padding:8px 10px;text-align:left;">New Status</th>
                                <th style="padding:8px 10px;text-align:left;">Reason</th>
                                <th style="padding:8px 10px;text-align:left;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody>${statusHistRows}</tbody>
                    </table>
                </div>
            `;
        })
        .catch(function(err) {
            contentEl.innerHTML = '<div style="color:#dc2626;text-align:center;padding:30px;"><i class="fas fa-exclamation-triangle" style="font-size:24px;display:block;margin-bottom:10px;"></i>Could not load fuel details.<br><small style="color:#94a3b8;font-size:11px;margin-top:6px;display:block;">' + (err.message || err) + '</small></div>';
        });
}

function closeViewFuelModalAdmin() {
    document.getElementById('viewFuelModalAdmin').style.display = 'none';
}

// Global helper: smart default based on tank capacity (mirrors PHP fallback)
function adminSmartDefault(val, cap, isReorder) {
    var v = parseFloat(val);
    if (!isNaN(v) && v > 0) return v;
    var c = parseFloat(cap) || 0;
    if (isReorder) {
        return (c === 14000) ? 5000 : ((c === 7000) ? 2000 : parseFloat((c * 0.20).toFixed(2)));
    } else {
        return (c === 14000) ? 2500 : ((c === 7000) ? 1000 : parseFloat((c * 0.10).toFixed(2)));
    }
}

function getCleanCanonicalFuelName(name) {
    if (!name) return 'Fuel';
    var lower = String(name).toLowerCase().trim();
    if (lower.indexOf('turbo') !== -1) return 'Turbo Diesel';
    if (lower.indexOf('diesel') !== -1) return 'Diesel';
    if (lower.indexOf('kerosene') !== -1) return 'Kerosene';
    if (lower.indexOf('xcs') !== -1) return 'XCS Plus';
    if (lower.indexOf('xtra') !== -1 || lower.indexOf('unl') !== -1 || lower.indexOf('advance') !== -1) return 'XTR ADVANCE';
    return String(name).replace(/[\s\-_#]*\d+$/gi, '').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
}

function openEditPriceModalAdmin(id, fuelName, currentPrice, capacity, critical, reorder, ugtNo, hasPending) {
    // Pre-fill form fields immediately from inline PHP values
    if (document.getElementById('aef_fuel_id')) document.getElementById('aef_fuel_id').value = id;
    var cleanFuelName = getCleanCanonicalFuelName(fuelName);
    if (document.getElementById('aef_ugt_no')) document.getElementById('aef_ugt_no').value = ugtNo || '';
    if (document.getElementById('aef_fuel_name')) document.getElementById('aef_fuel_name').value = cleanFuelName;
    if (document.getElementById('aef_capacity')) document.getElementById('aef_capacity').value = parseFloat(capacity) || '';
    if (document.getElementById('aef_price')) document.getElementById('aef_price').value = parseFloat(currentPrice || 0).toFixed(2);
    if (document.getElementById('aef_critical')) document.getElementById('aef_critical').value = adminSmartDefault(critical, capacity, false);
    if (document.getElementById('aef_reorder')) document.getElementById('aef_reorder').value = adminSmartDefault(reorder, capacity, true);

    // Admin can always edit price directly
    var priceInput  = document.getElementById('aef_price');
    var priceNotice = document.getElementById('aef_price_notice');
    if (priceInput) {
        priceInput.removeAttribute('readonly');
        priceInput.style.background  = '';
        priceInput.style.borderColor = '';
        priceInput.style.color       = '';
        priceInput.style.cursor      = '';
    }
    if (priceNotice) priceNotice.style.display = 'none';

    var modal = document.getElementById('editPriceModalAdmin');
    if (modal) modal.style.display = 'flex';

    // Fetch fresh live values from DB to overwrite with accurate data
    if (parseInt(id) > 0) {
        fetch('admin_set_prices_handler.php?action=get_fuel_details_admin&id=' + parseInt(id))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !data.fuel) return;
                var f   = data.fuel;
                var cap = parseFloat(f.capacity || capacity || 0);

                if (document.getElementById('aef_ugt_no')) document.getElementById('aef_ugt_no').value = f.ugt_no || ugtNo || '';
                var rawName = getCleanCanonicalFuelName(f.clean_fuel_type || f.raw_fuel_type || f.fuel_type || fuelName);
                if (document.getElementById('aef_fuel_name')) document.getElementById('aef_fuel_name').value = rawName;

                // Overwrite capacity
                document.getElementById('aef_capacity').value = cap || '';

                // Overwrite price
                var livePrice = parseFloat(f.price_per_liter);
                document.getElementById('aef_price').value = (isNaN(livePrice) ? parseFloat(currentPrice || 0) : livePrice).toFixed(2);

                // Overwrite critical level — DB first, smart default as fallback
                document.getElementById('aef_critical').value = adminSmartDefault(f.critical_level, cap, false);

                // Overwrite reorder level — DB first, smart default as fallback
                document.getElementById('aef_reorder').value  = adminSmartDefault(f.reorder_level, cap, true);

                // Sync fuel name
                if (document.getElementById('aef_fuel_name')) {
                    document.getElementById('aef_fuel_name').value = rawName;
                }

                // Sync status radio buttons
                var liveStatus  = (f.status || 'active').toLowerCase();
                var radActive   = document.getElementById('aef_status_active');
                var radInactive = document.getElementById('aef_status_inactive');
                if (radActive && radInactive) {
                    radActive.checked   = (liveStatus === 'active');
                    radInactive.checked = (liveStatus !== 'active');
                }
            })
            .catch(function() { /* keep inline values on network error */ });
    }
}

function closeEditPriceModalAdmin() {
    document.getElementById('editPriceModalAdmin').style.display = 'none';
}

function validateEditFuelForm() {
    const pEl  = document.getElementById('aef_price');
    const cEl  = document.getElementById('aef_capacity');
    const crEl = document.getElementById('aef_critical');
    const rEl  = document.getElementById('aef_reorder');

    const p  = parseFloat(pEl?.value || 0);
    const c  = parseFloat(cEl?.value || 0);
    const cr = parseFloat(crEl?.value || 0);
    const r  = parseFloat(rEl?.value || 0);

    if (isNaN(p) || p <= 0) {
        alert('Price / Liter must be a valid positive number greater than 0.');
        if (pEl) pEl.focus();
        return false;
    }
    if (isNaN(c) || c <= 0) {
        alert('Tank Capacity must be a valid positive number greater than 0.');
        if (cEl) cEl.focus();
        return false;
    }
    if (isNaN(cr) || cr <= 0) {
        alert('Critical Level must be a valid positive number greater than 0.');
        if (crEl) crEl.focus();
        return false;
    }
    if (cr >= c) {
        alert('Critical Level cannot be greater than or equal to Tank Capacity (' + c + ' L).');
        if (crEl) crEl.focus();
        return false;
    }
    if (isNaN(r) || r <= 0) {
        alert('Reorder Level must be a valid positive number greater than 0.');
        if (rEl) rEl.focus();
        return false;
    }
    if (r >= c) {
        alert('Reorder Level cannot be greater than or equal to Tank Capacity (' + c + ' L).');
        if (rEl) rEl.focus();
        return false;
    }
    return true;
}

function openRejectPriceModalAdmin(approvalId, productName, oldPrice, newPrice) {
    document.getElementById('adminRejectApprovalId').value = approvalId;
    document.getElementById('adminRejectProdName').textContent = productName;
    document.getElementById('adminRejectOldPrice').textContent = '₱' + parseFloat(oldPrice).toFixed(2);
    document.getElementById('adminRejectNewPrice').textContent = '₱' + parseFloat(newPrice).toFixed(2);
    document.getElementById('adminRejectRemarks').value = '';
    document.getElementById('rejectPriceModalAdmin').style.display = 'flex';
}

function closeRejectPriceModalAdmin() {
    document.getElementById('rejectPriceModalAdmin').style.display = 'none';
}

function openToggleFuelStatusModal(id, newStatus, fuelName) {
    var modal = document.getElementById('toggleFuelStatusModal');
    document.getElementById('toggleFuelStatusId').value = id;
    document.getElementById('toggleFuelStatusValue').value = newStatus;
    document.getElementById('toggleFuelStatusName').textContent = fuelName;
    var isDeactivate = (newStatus === 'inactive');
    var header = document.getElementById('toggleFuelStatusHeader');
    var icon = document.getElementById('toggleFuelStatusIcon');
    var titleEl = document.getElementById('toggleFuelStatusTitle');
    var descEl = document.getElementById('toggleFuelStatusDesc');
    var confirmBtn = document.getElementById('toggleFuelStatusConfirmBtn');
    if (isDeactivate) {
        header.style.background = 'linear-gradient(135deg,#dc2626,#b91c1c)';
        icon.innerHTML = '<i class="fas fa-ban" style="font-size:28px;color:#fff;"></i>';
        titleEl.textContent = 'Deactivate Fuel Product';
        descEl.innerHTML = 'You are about to set <strong style="color:#0f172a;">' + fuelName + '</strong> to <strong style="color:#dc2626;">Inactive</strong>. This will prevent it from being used in transactions until reactivated.';
        confirmBtn.style.background = '#dc2626';
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Deactivation';
    } else {
        header.style.background = 'linear-gradient(135deg,#16a34a,#15803d)';
        icon.innerHTML = '<i class="fas fa-check-circle" style="font-size:28px;color:#fff;"></i>';
        titleEl.textContent = 'Activate Fuel Product';
        descEl.innerHTML = 'You are about to set <strong style="color:#0f172a;">' + fuelName + '</strong> to <strong style="color:#16a34a;">Active</strong>. It will be available for transactions.';
        confirmBtn.style.background = '#16a34a';
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Activation';
    }
    modal.style.display = 'flex';
}

function closeToggleFuelStatusModal() {
    document.getElementById('toggleFuelStatusModal').style.display = 'none';
}

function openApprovePriceModalAdmin(approvalId, productName, oldPrice, newPrice, tab) {
    tab = tab || 'fuel';
    document.getElementById('adminApproveApprovalId').value = approvalId;
    document.getElementById('adminApproveActiveTab').value = tab;
    document.getElementById('adminApproveProdName').textContent = productName;
    document.getElementById('adminApproveOldPrice').textContent = '\u20b1' + parseFloat(oldPrice).toFixed(2);
    document.getElementById('adminApproveNewPrice').textContent = '\u20b1' + parseFloat(newPrice).toFixed(2);
    var diff = parseFloat(newPrice) - parseFloat(oldPrice);
    var diffEl = document.getElementById('adminApproveDiff');
    diffEl.textContent = (diff >= 0 ? '+' : '') + '\u20b1' + diff.toFixed(2);
    diffEl.style.color = diff >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('approvePriceModalAdmin').style.display = 'flex';
}

function closeApprovePriceModalAdmin() {
    document.getElementById('approvePriceModalAdmin').style.display = 'none';
}

// ── Tab Switching ─────────────────────────────────────────────────────────
function switchTab(tabName) {
    if (['fuel', 'merch', 'services'].indexOf(tabName) === -1) tabName = 'fuel';

    document.querySelectorAll('.tab-panel').forEach(function(panel) {
        panel.classList.remove('active');
    });
    document.querySelectorAll('.ato-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    var panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('active');
    var btn = document.getElementById('tab-btn-' + tabName);
    if (btn) btn.classList.add('active');
    var hidden = document.getElementById('activeSection');
    if (hidden) hidden.value = tabName;

    // Update URL without reloading so refresh lands on the same tab
    try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState(null, '', url.toString());
    } catch (e) {}

    // Persist in sessionStorage
    try {
        sessionStorage.setItem('petron_admin_active_tab', tabName);
    } catch (e) {}
}

(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabFromUrl = urlParams.get('tab');
    var savedTab = null;
    try { savedTab = sessionStorage.getItem('petron_admin_active_tab'); } catch (e) {}

    var targetTab = tabFromUrl || savedTab;
    if (targetTab && ['fuel', 'merch', 'services'].indexOf(targetTab) !== -1) {
        switchTab(targetTab);
    } else {
        var activeHidden = document.getElementById('activeSection');
        var activeTab = activeHidden ? activeHidden.value : 'fuel';
        if (!activeTab || ['fuel', 'merch', 'services'].indexOf(activeTab) === -1) activeTab = 'fuel';
        switchTab(activeTab);
    }
})();
</script>

<!-- Admin View Fuel Product & History Modal -->
<div id="viewFuelModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:flex-start;justify-content:center;padding:85px 20px 70px 20px;box-sizing:border-box;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:880px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100vh - 155px);display:flex;flex-direction:column;">
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-gas-pump" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">FUEL PRODUCT SPECIFICATION &amp; HISTORY</span>
            </h3>
        </div>
        <div id="viewFuelModalAdminContent" style="padding:20px 24px 24px 24px;overflow-y:auto;flex:1 1 auto;background:#ffffff;box-sizing:border-box;">
        </div>
        <!-- Footer with Close Button -->
        <div style="display:flex;justify-content:flex-end;padding:14px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;">
            <button type="button" onclick="closeViewFuelModalAdmin()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Admin Edit Fuel Modal (Direct Update - Option A) -->
<div id="editPriceModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:720px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-edit" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
        <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">EDIT FUEL PRODUCT</span>
      </h3>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 24px;" onsubmit="return validateEditFuelForm();">
      <input type="hidden" name="action" value="admin_edit_fuel_direct">
      <input type="hidden" name="active_tab" value="fuel">
      <input type="hidden" id="aef_fuel_id" name="id">

      <!-- Row 1: UGT Number + Fuel Name -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">UGT Number</label>
          <input type="text" id="aef_ugt_no" name="ugt_no" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;color:#002F70;font-weight:800;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Fuel Name</label>
          <input type="text" id="aef_fuel_name" name="fuel_name" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;color:#0f172a;font-weight:700;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
      </div>

      <!-- Row 2: Price Per Liter + Tank Capacity -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Price / Liter (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_price" name="price" step="0.01" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
          <div id="aef_price_notice" style="display:none;margin-top:6px;background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:6px 10px;align-items:center;gap:8px;">
              <i class="fas fa-lock" style="color:#92400e;font-size:12px;"></i>
              <span style="font-size:11px;color:#92400e;font-weight:700;">PRICE LOCKED &mdash; A pending price request exists. Approve or reject it first to change the price.</span>
          </div>
          <small style="font-size:10px;color:#16a34a;display:block;margin-top:2px;"><i class="fas fa-check-circle"></i> Direct Admin Edit: Updates price immediately.</small>
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Tank Capacity (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_capacity" name="capacity" step="1" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
        </div>
      </div>

      <!-- Row 3: Critical Level + Reorder Level -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Critical Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_critical" name="critical_level" step="1" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">Reorder Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_reorder" name="reorder_level" step="1" min="0" required style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if ((this.value.match(/\./g) || []).length > 1) this.value = this.value.replace(/\.+$/, '');">
        </div>
      </div>

      <!-- Row 4: Status -->
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:6px;">Status <span style="color:#dc2626;">*</span></label>
        <div style="display:flex;gap:18px;align-items:center;padding-top:4px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#166534;">
            <input type="radio" id="aef_status_active" name="status" value="active" checked style="accent-color:#16a34a;"> Active
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;font-weight:600;color:#991b1b;">
            <input type="radio" id="aef_status_inactive" name="status" value="inactive" style="accent-color:#dc2626;"> Inactive
          </label>
        </div>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:14px;">
        <button type="button" onclick="closeEditPriceModalAdmin()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;"><i class="fas fa-times-circle"></i> Cancel</button>
        <button type="submit" style="background:#002F6C !important;color:#ffffff !important;border:none;padding:8px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save &amp; Apply Immediately</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin Reject Price Request Modal -->
<div id="rejectPriceModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:480px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-times-circle" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Reject Price Change Request</h3>
        <p style="margin:2px 0 0 0;font-size:11px;color:rgba(255,255,255,.8);">This action will notify the manager of the rejection.</p>
      </div>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 22px;">
      <input type="hidden" name="action" value="reject_price">
      <input type="hidden" name="active_tab" value="fuel">
      <input type="hidden" id="adminRejectApprovalId" name="approval_id">
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <strong style="color:#991b1b;display:block;font-size:13px;margin-bottom:6px;" id="adminRejectProdName">Fuel Product</strong>
        <div style="font-size:13px;color:#475569;display:flex;gap:16px;flex-wrap:wrap;">
          <span>Current Price: <strong style="color:#334155;" id="adminRejectOldPrice">&#8369;0.00</strong></span>
          <span style="color:#94a3b8;">&#8594;</span>
          <span>Requested: <strong style="color:#dc2626;" id="adminRejectNewPrice">&#8369;0.00</strong></span>
        </div>
      </div>
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Rejection Reason <span style="color:#dc2626;">*</span></label>
        <textarea name="remarks" id="adminRejectRemarks" rows="3" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;resize:vertical;transition:border-color .2s;" placeholder="Please provide reason for rejecting this price request..." onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#d1d5db'"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeRejectPriceModalAdmin()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="submit" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin Approve Price Request Modal -->
<div id="approvePriceModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:460px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-check-circle" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Approve Price Change Request</h3>
        <p style="margin:2px 0 0 0;font-size:11px;color:rgba(255,255,255,.8);">This will immediately apply the new price.</p>
      </div>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 22px;">
      <input type="hidden" name="action" value="approve_price">
      <input type="hidden" id="adminApproveActiveTab" name="active_tab" value="fuel">
      <input type="hidden" id="adminApproveApprovalId" name="approval_id">
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <strong style="color:#166534;display:block;font-size:13px;margin-bottom:8px;" id="adminApproveProdName">Fuel Product</strong>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:12px;">
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">Current Price</span>
            <span style="font-weight:700;color:#334155;font-size:13px;" id="adminApproveOldPrice">&#8369;0.00</span>
          </div>
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">New Price</span>
            <span style="font-weight:800;color:#002F6C;font-size:14px;" id="adminApproveNewPrice">&#8369;0.00</span>
          </div>
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">Difference</span>
            <span style="font-weight:700;font-size:13px;" id="adminApproveDiff">&#8369;0.00</span>
          </div>
        </div>
      </div>
      <p style="font-size:12px;color:#64748b;margin:0 0 18px 0;"><i class="fas fa-info-circle" style="color:#16a34a;"></i> Once approved, the new price will take effect immediately for all future transactions.</p>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeApprovePriceModalAdmin()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="submit" style="background:#16a34a !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Approval</button>
      </div>
    </form>
  </div>
</div>

<!-- Deactivate / Activate Fuel Status Confirmation Modal -->
<div id="toggleFuelStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div id="toggleFuelStatusHeader" style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div id="toggleFuelStatusIcon" style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-ban" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 id="toggleFuelStatusTitle" style="margin:0;font-size:15px;font-weight:800;color:#fff;">Deactivate Fuel Product</h3>
        <p style="margin:2px 0 0 0;font-size:11px;color:rgba(255,255,255,.8);">Please confirm this action.</p>
      </div>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 22px;">
      <input type="hidden" name="action" value="toggle_fuel_status_admin">
      <input type="hidden" name="active_tab" value="fuel">
      <input type="hidden" id="toggleFuelStatusId" name="id">
      <input type="hidden" id="toggleFuelStatusValue" name="status">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:13px;color:#475569;line-height:1.6;" id="toggleFuelStatusDesc">
          Are you sure you want to change the status of <strong id="toggleFuelStatusName" style="color:#0f172a;">this fuel</strong>?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeToggleFuelStatusModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="submit" id="toggleFuelStatusConfirmBtn" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-ban" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Deactivation</button>
      </div>
    </form>
  </div>
</div>

<!-- Deactivate / Activate Service Status Confirmation Modal -->
<div id="toggleServiceStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div id="toggleServiceStatusHeader" style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i id="toggleServiceHeaderIcon" class="fas fa-ban" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 id="toggleServiceStatusTitle" style="margin:0;font-size:15px;font-weight:800;color:#fff;">Deactivate Service</h3>
        <p style="margin:2px 0 0 0;font-size:11px;color:rgba(255,255,255,.8);">Please confirm this action.</p>
      </div>
    </div>
    <div style="padding:20px 22px;">
      <input type="hidden" id="toggleServiceStatusId">
      <input type="hidden" id="toggleServiceStatusValue">
      <input type="hidden" id="toggleServiceStatusNameHolder">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:13px;color:#475569;line-height:1.6;" id="toggleServiceStatusDesc">
          Are you sure you want to change the status of <strong id="toggleServiceStatusName" style="color:#0f172a;">this service</strong>?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeToggleServiceStatusModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="button" id="toggleServiceStatusConfirmBtn" onclick="confirmAdminToggleService()" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i id="toggleServiceBtnIcon" class="fas fa-ban" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Deactivation</button>
      </div>
    </div>
  </div>
</div>

<!-- Restore Service Fees Confirmation Modal -->
<div id="restoreServiceFeesModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#d97706,#b45309);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-undo" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Restore Previous Fees</h3>
        <p style="margin:2px 0 0 0;font-size:11px;color:rgba(255,255,255,.8);">Revert service fees to previous values.</p>
      </div>
    </div>
    <div style="padding:20px 22px;">
      <input type="hidden" id="restoreSvcId">
      <input type="hidden" id="restoreOldSvcFee">
      <input type="hidden" id="restoreOldLabFee">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:13px;color:#475569;line-height:1.6;" id="restoreSvcDesc">
          Are you sure you want to restore previous fees?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeRestoreServiceFeesModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="button" onclick="confirmRestoreServiceFees()" style="background:#d97706 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-undo" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Restoration</button>
      </div>
    </div>
  </div>
</div>

<style>
@keyframes adminModalPopIn {
    from { opacity:0; transform:scale(0.93) translateY(-10px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
</style>
</div> <!-- /.main-content -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
