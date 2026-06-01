<?php
/**
 * STAFF FUEL DELIVERIES INTERFACE
 * 
 * Fuel delivery recording for staff with:
 * - Simple delivery recording form
 * - Pending delivery status tracking
 * - Delivery history view
 * - Manager approval workflow
 */

$page_id = 'staff_fuel_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only staff and above can access fuel deliveries
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Staff privileges required.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$msg_type = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    $msg_type = 'success';
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    $msg_type = 'error';
    unset($_SESSION['error']); 
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // STAFF: Record Fuel Delivery
    if ($action === 'record_delivery') {
        $delivery_date   = $_POST['delivery_date'] ?? '';
        $fuel_type       = trim($_POST['fuel_type'] ?? '');
        $supplier        = trim($_POST['supplier'] ?? 'Petron Corporation');
        $invoice_no      = trim($_POST['invoice_no'] ?? '');
        $delivery_liters = (float)($_POST['delivery_liters'] ?? 0);
        $tanker_number   = trim($_POST['tanker_number'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        if ($delivery_date && $fuel_type && $supplier && $delivery_liters > 0 && $invoice_no) {
            try {
                // ── Capacity check before inserting ──────────────────────────
                $capStmt = $pdo->prepare("
                    SELECT COALESCE(current_level, current_stock, 0) AS current_level,
                           COALESCE(capacity, 0) AS capacity
                    FROM fuel_inventory
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $capStmt->execute([$station_id, $fuel_type]);
                $capRow = $capStmt->fetch(PDO::FETCH_ASSOC);

                $excess_flagged = false;
                $original_liters = $delivery_liters;
                $redirect_info = "";

                if ($capRow && $capRow['capacity'] > 0) {
                    $current  = (float)$capRow['current_level'];
                    $capacity = (float)$capRow['capacity'];
                    $available = max(0, $capacity - $current);
                    
                    if ($delivery_liters > $available) {
                        $excess_flagged = true;
                        $excess_liters = $delivery_liters - $available;
                        $delivery_liters = $available; // Encode only what fits

                        // Check other tanks of the same fuel type for available space
                        try {
                            $otherStmt = $pdo->prepare("
                                SELECT id, fuel_type, 
                                       COALESCE(current_level, current_stock, 0) AS cur,
                                       COALESCE(capacity, 0) AS cap
                                FROM fuel_inventory
                                WHERE station_id = ? 
                                  AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                                  AND COALESCE(capacity, 0) > COALESCE(current_level, current_stock, 0)
                            ");
                            $otherStmt->execute([$station_id, $fuel_type]);
                            $otherTanks = $otherStmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (!empty($otherTanks)) {
                                $tank_infos = [];
                                foreach ($otherTanks as $ot) {
                                    $tank_avail = max(0, $ot['cap'] - $ot['cur']);
                                    if ($tank_avail > 0) {
                                        $tank_infos[] = "Tank ID {$ot['id']} (Avail: " . number_format($tank_avail, 2) . " L)";
                                    }
                                }
                                if (!empty($tank_infos)) {
                                    $redirect_info = "Suggested redirect tanks with space: " . implode(', ', $tank_infos);
                                } else {
                                    $redirect_info = "No alternative tanks of the same fuel type have available space.";
                                }
                            } else {
                                $redirect_info = "No alternative tanks of the same fuel type have available space.";
                            }
                        } catch (Exception $oe) {
                            $redirect_info = "Error checking alternative tanks.";
                        }

                        // Build robust excess notes
                        $excess_note = "[Excess Fuel Capped: " . number_format($excess_liters, 2) . " L of excess not received due to full tank. Original: " . number_format($original_liters, 2) . " L. Available: " . number_format($available, 2) . " L. " . $redirect_info . "]";
                        $notes = $excess_note . ($notes ? "\n" . $notes : "");
                    }
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO fuel_deliveries (
                        station_id, delivery_date, fuel_type, supplier,
                        invoice_no, delivery_liters, tanker_number,
                        received_by, notes, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ");
                $stmt->execute([
                    $station_id, $delivery_date, $fuel_type, $supplier,
                    $invoice_no, $delivery_liters, $tanker_number,
                    $me['id'], $notes
                ]);

                $delivery_id = $pdo->lastInsertId();

                log_activity($pdo, $me['id'], 'Record Fuel Delivery',
                    "Recorded delivery: {$delivery_liters}L of {$fuel_type} (Invoice: {$invoice_no})" . ($excess_flagged ? " (Excess flagged)" : ""),
                    'fuel_management'
                );

                $pdo->commit();

                if ($excess_flagged) {
                    $_SESSION['success'] = "Fuel delivery recorded! Capped at available space (" . number_format($delivery_liters, 2) . " L). Excess " . number_format($original_liters - $delivery_liters, 2) . " L has been flagged in notes/remarks. Awaiting manager verification.";
                } else {
                    $_SESSION['success'] = "Fuel delivery recorded successfully! Delivery ID: {$delivery_id}. Awaiting manager verification.";
                }
                header('Location: staff_fuel_deliveries.php');
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg      = "" . $e->getMessage();
                $msg_type = 'error';
            }
        } else {
            $msg      = "Please fill all required fields.";
            $msg_type = 'error';
        }
    }

    // STAFF: Receive Expected Fuel Delivery (from Admin-finalized PO)
    if ($action === 'receive_fuel_expected') {
        $del_id       = (int)($_POST['delivery_id'] ?? 0);
        $actual_liters = (float)($_POST['actual_liters'] ?? 0);
        $invoice_no   = trim($_POST['invoice_no'] ?? '');
        $tanker_no    = trim($_POST['tanker_number'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');

        if ($del_id > 0 && $actual_liters > 0 && $invoice_no) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'");
                $stmt->execute([$del_id, $station_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$del) throw new Exception('Expected delivery not found or already processed.');

                // ── Capacity check before receiving ──────────────────────────
                $capStmt = $pdo->prepare("
                    SELECT COALESCE(current_level, current_stock, 0) AS current_level,
                           COALESCE(capacity, 0) AS capacity
                    FROM fuel_inventory
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $capStmt->execute([$station_id, $del['product']]);
                $capRow = $capStmt->fetch(PDO::FETCH_ASSOC);

                $excess_flagged = false;
                $original_actual_liters = $actual_liters;
                $redirect_info = "";

                if ($capRow && $capRow['capacity'] > 0) {
                    $current  = (float)$capRow['current_level'];
                    $capacity = (float)$capRow['capacity'];
                    $available = max(0, $capacity - $current);
                    
                    if ($actual_liters > $available) {
                        $excess_flagged = true;
                        $excess_liters = $actual_liters - $available;
                        $actual_liters = $available; // Capped at available space

                        // Check other tanks of the same fuel type for available space
                        try {
                            $otherStmt = $pdo->prepare("
                                SELECT id, fuel_type, 
                                       COALESCE(current_level, current_stock, 0) AS cur,
                                       COALESCE(capacity, 0) AS cap
                                FROM fuel_inventory
                                WHERE station_id = ? 
                                  AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                                  AND COALESCE(capacity, 0) > COALESCE(current_level, current_stock, 0)
                            ");
                            $otherStmt->execute([$station_id, $del['product']]);
                            $otherTanks = $otherStmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (!empty($otherTanks)) {
                                $tank_infos = [];
                                foreach ($otherTanks as $ot) {
                                    $tank_avail = max(0, $ot['cap'] - $ot['cur']);
                                    if ($tank_avail > 0) {
                                        $tank_infos[] = "Tank ID {$ot['id']} (Avail: " . number_format($tank_avail, 2) . " L)";
                                    }
                                }
                                if (!empty($tank_infos)) {
                                    $redirect_info = "Suggested redirect tanks with space: " . implode(', ', $tank_infos);
                                } else {
                                    $redirect_info = "No alternative tanks of the same fuel type have available space.";
                                }
                            } else {
                                $redirect_info = "No alternative tanks of the same fuel type have available space.";
                            }
                        } catch (Exception $oe) {
                            $redirect_info = "Error checking alternative tanks.";
                        }

                        // Build robust excess notes
                        $excess_note = "[Excess Fuel Capped: " . number_format($excess_liters, 2) . " L of excess not received in this tank due to full tank. Original input: " . number_format($original_actual_liters, 2) . " L. Available Space: " . number_format($available, 2) . " L. " . $redirect_info . "]";
                        $notes = $excess_note . ($notes ? "\n" . $notes : "");
                    }
                }

                $expected_liters = (float)$del['quantity'];
                // We use the original_actual_liters or capped actual_liters?
                // Capped actual_liters is what we commit, but we flag variance against the PO expected amount!
                $diff = abs($actual_liters - $expected_liters);
                $new_status  = ($diff > 0.001 || $excess_flagged) ? 'Discrepancy' : 'Pending Manager Approval';
                $admin_notes = ($diff > 0.001 || $excess_flagged)
                    ? "System Flag: PO expected " . number_format($expected_liters, 2) . " L, but received " . number_format($actual_liters, 2) . " L." . ($excess_flagged ? " (Capped due to full tank. Excess: " . number_format($original_actual_liters - $actual_liters, 2) . " L)" : " Variance: " . number_format($actual_liters - $expected_liters, 2) . " L.")
                    : null;

                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET quantity = ?, dr_number = ?, remarks = ?, encoded_by = ?,
                        status = ?, admin_notes = ?, delivery_date = CURDATE(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$actual_liters, $invoice_no, ($tanker_no ? 'Tanker: '.$tanker_no.'. '.$notes : $notes), $me['id'], $new_status, $admin_notes, $del_id]);

                // Also insert into fuel_deliveries table for the existing delivery records view
                $pdo->prepare("
                    INSERT INTO fuel_deliveries
                        (station_id, delivery_date, fuel_type, supplier, invoice_no,
                         delivery_liters, tanker_number, received_by, notes, status, created_at)
                    VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ")->execute([
                    $station_id, $del['product'], $del['supplier'],
                    $invoice_no, $actual_liters, $tanker_no, $me['id'], $notes
                ]);

                log_activity($pdo, $me['id'], 'Staff Received Fuel PO Delivery',
                    "PO: {$del['source_ref']} | Fuel: {$del['product']} | Expected: {$expected_liters}L | Actual: {$actual_liters}L",
                    'fuel_management'
                );

                $pdo->commit();

                if ($new_status === 'Discrepancy') {
                    $_SESSION['error'] = "&#9888; Variance detected! Expected " . number_format($expected_liters, 2) . "L but recorded " . number_format($actual_liters, 2) . "L. Flagged for Manager review.";
                } else {
                    $_SESSION['success'] = "&#10003; Fuel delivery received and matches PO. Pending Manager Approval.";
                }
                header('Location: staff_fuel_deliveries.php');
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = $e->getMessage(); $msg_type = 'error';
            }
        } else {
            $msg = 'Please fill all required fields (Actual Liters and Invoice No.).'; $msg_type = 'error';
        }
    }
}

// Fetch fuel types — try inventory_products first, fall back to fuel_types table
$fuel_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT product_name AS name
        FROM inventory_products
        WHERE LOWER(category) = 'fuel'
        ORDER BY product_name
    ");
    $stmt->execute();
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching fuel types from inventory_products: " . $e->getMessage());
}

// Fallback: try fuel_types table if inventory_products returned nothing
if (empty($fuel_types)) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM fuel_types ORDER BY name");
        $stmt->execute();
        $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching fuel types from fuel_types: " . $e->getMessage());
    }
}

// Fetch current tank levels (with capacity for available-space display)
$tank_levels = [];
try {
    $stmt = $pdo->prepare("
        SELECT fi.fuel_type,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
               COALESCE(fi.capacity, 0)                        AS capacity
        FROM fuel_inventory fi
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tank_levels[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching tank levels: " . $e->getMessage());
}

// ── Filter state ─────────────────────────────────────────────
// Period presets: ytd | monthly | weekly | custom (default: monthly)
$filter_period    = $_GET['period']     ?? 'monthly';
$filter_date_from = $_GET['date_from']  ?? '';
$filter_date_to   = $_GET['date_to']    ?? '';
$filter_fuel_type = trim($_GET['fuel_type_filter'] ?? '');
$filter_supplier  = trim($_GET['supplier_filter']  ?? '');
$filter_keyword   = trim($_GET['keyword']           ?? '');

// Resolve date range from period preset
$today      = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));

switch ($filter_period) {
    case 'ytd':
        $resolved_from = date('Y-01-01');
        $resolved_to   = $today;
        break;
    case 'weekly':
        $resolved_from = $week_start;
        $resolved_to   = $week_end;
        break;
    case 'custom':
        $resolved_from = $filter_date_from ?: date('Y-m-01');
        $resolved_to   = $filter_date_to   ?: $today;
        break;
    case 'monthly':
    default:
        $filter_period = 'monthly';
        $resolved_from = date('Y-m-01');
        $resolved_to   = $today;
        break;
}

// Sanitize
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolved_from)) $resolved_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolved_to))   $resolved_to   = $today;

// Ensure fuel_deliveries has verified_by + verified_at columns (added by manager verification flow)
try {
    $pdo->exec("ALTER TABLE fuel_deliveries ADD COLUMN verified_by INT DEFAULT NULL");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE fuel_deliveries ADD COLUMN verified_at DATETIME DEFAULT NULL");
} catch (Exception $e) {}

// ── Distinct suppliers for filter dropdown ────────────────────
$supplier_list = [];
try {
    $sup = $pdo->prepare("
        SELECT DISTINCT supplier
        FROM fuel_deliveries
        WHERE station_id = ?
          AND supplier IS NOT NULL AND supplier <> ''
        ORDER BY supplier ASC
    ");
    $sup->execute([$station_id]);
    $supplier_list = array_column($sup->fetchAll(PDO::FETCH_ASSOC), 'supplier');
} catch (Exception $e) { $supplier_list = []; }

// Fetch deliveries — filtered
$recent_deliveries = [];
try {
    $sql = "
        SELECT
            fd.id,
            fd.delivery_date,
            fd.fuel_type,
            fd.supplier,
            fd.invoice_no,
            fd.delivery_liters,
            fd.tanker_number,
            fd.notes,
            fd.status,
            fd.created_at,
            fd.verified_by,
            fd.verified_at,
            u_rec.name  AS recorded_by_name,
            u_ver.name  AS verified_by_name
        FROM fuel_deliveries fd
        LEFT JOIN users u_rec ON fd.received_by  = u_rec.id
        LEFT JOIN users u_ver ON fd.verified_by  = u_ver.id
        WHERE fd.station_id = ?
          AND DATE(fd.delivery_date) BETWEEN ? AND ?
    ";
    $params = [$station_id, $resolved_from, $resolved_to];

    if ($filter_fuel_type !== '') {
        $sql    .= " AND LOWER(TRIM(fd.fuel_type)) = LOWER(TRIM(?))";
        $params[] = $filter_fuel_type;
    }
    if ($filter_supplier !== '') {
        $sql    .= " AND LOWER(TRIM(fd.supplier)) = LOWER(TRIM(?))";
        $params[] = $filter_supplier;
    }
    if ($filter_keyword !== '') {
        $sql    .= " AND (fd.invoice_no LIKE ? OR fd.tanker_number LIKE ? OR fd.notes LIKE ?)";
        $kw      = '%' . $filter_keyword . '%';
        $params  = array_merge($params, [$kw, $kw, $kw]);
    }

    $sql .= " ORDER BY fd.delivery_date DESC, fd.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching deliveries: " . $e->getMessage());
}

// ── Summary totals for the active filter period ───────────────
$summary_liters  = array_sum(array_column($recent_deliveries, 'delivery_liters'));
$summary_count   = count($recent_deliveries);
$pending_count   = count(array_filter($recent_deliveries, fn($d) => in_array(strtolower($d['status'] ?? ''), ['pending', 'pending review', 'encoded'])));
$verified_count  = count(array_filter($recent_deliveries, fn($d) => in_array(strtolower($d['status'] ?? ''), ['verified', 'confirmed', 'approved'])));

// Fetch expected fuel deliveries from Admin-finalized POs
$expected_fuel_deliveries = [];
$expected_fuel_fetch_error = '';
try {
    // Ensure deliveries_oversight table exists with required columns
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   VARCHAR(20) NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            batch_id        VARCHAR(100) DEFAULT NULL,
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30) NOT NULL DEFAULT 'pcs',
            delivery_date   DATE NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT DEFAULT NULL,
            station_id      INT NOT NULL,
            status          VARCHAR(60) NOT NULL DEFAULT 'Pending Manager Approval',
            source_ref      VARCHAR(100) DEFAULT NULL,
            admin_id        INT DEFAULT NULL,
            admin_action_at DATETIME DEFAULT NULL,
            admin_notes     TEXT DEFAULT NULL,
            remarks         TEXT DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status  (status),
            INDEX idx_date    (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        SELECT * FROM deliveries_oversight
        WHERE station_id = ?
          AND delivery_type = 'fuel'
          AND status = 'Expected Delivery'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$station_id]);
    $expected_fuel_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expected_fuel_fetch_error = $e->getMessage();
    error_log("Expected fuel deliveries fetch error: " . $e->getMessage());
}

// Fetch fuel suppliers for datalist (always includes Petron Corporation)
$fuel_supplier_list = ['Petron Corporation'];
try {
    $sp = $pdo->prepare("
        SELECT DISTINCT supplier_name FROM fuel_suppliers
        WHERE (station_id = ? OR station_id IS NULL) AND is_active = 1
        ORDER BY supplier_name
    ");
    $sp->execute([$station_id]);
    $db_suppliers = $sp->fetchAll(PDO::FETCH_COLUMN);
    $fuel_supplier_list = array_values(array_unique(array_merge($fuel_supplier_list, $db_suppliers)));
} catch (Exception $e) {
    // table may not exist yet — default list is fine
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page layout ─────────────────────────────────────────────── */
.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}
/* .back-link replaced by global .btn-back in style.css */

/* ── Record New Delivery card ── */
.del-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: hidden;
    margin-bottom: 20px;
}
.del-card-header {
    padding: 14px 20px;
    background: #f0f4ff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.del-card-header h3 {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #003d82 !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
}

/* ── Shared fields row (date + supplier) ── */
.del-shared-row {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    padding: 16px 16px 12px;
    align-items: flex-end;
    border-bottom: 1px solid #f1f5f9;
}
.del-shared-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 140px;
    flex: 1;
}
.del-shared-field label {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.del-shared-field input {
    padding: 8px 11px;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
    box-sizing: border-box;
}
.del-shared-field input:focus {
    outline: none;
    border-color: #003d82;
    box-shadow: 0 0 0 3px rgba(0,61,130,.1);
}

/* ── Per-fuel-type row cards ── */
.det-rows { padding: 12px 16px; display: flex; flex-direction: column; gap: 10px; }

.det-row-card {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    transition: box-shadow .15s;
}
.det-row-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.07); }

/* Top strip: fuel identity + tank stats */
.det-row-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    flex-wrap: wrap;
}
.det-fuel-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.det-fuel-name { font-weight: 700; font-size: 13px; }
.det-tank-stat {
    font-size: 11px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 4px;
}
.det-tank-bar {
    display: inline-block;
    width: 48px;
    height: 5px;
    background: #e2e8f0;
    border-radius: 4px;
    vertical-align: middle;
    overflow: hidden;
}
.det-tank-bar-fill { height: 100%; border-radius: 4px; }

/* Input grid inside each row card */
.det-row-body {
    padding: 12px 14px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 14px;
    align-items: end;
}
@media (max-width: 600px) {
    .det-row-body { grid-template-columns: 1fr; }
}

.det-field { display: flex; flex-direction: column; gap: 4px; }
.det-field label {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.det-input {
    padding: 8px 10px;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    background: #fff;
    width: 100%;
    box-sizing: border-box;
    transition: border-color .15s, box-shadow .15s;
}
.det-input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,61,130,.1);
}
.det-input.notes-input { font-weight: 400; font-size: 12px; }

/* Actions row at bottom of each card */
.det-row-actions {
    padding: 10px 14px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: flex-end;
}
.det-submit-btn {
    padding: 8px 18px;
    background: #002f6c;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .15s;
}
.det-submit-btn:hover    { background: #001f4d; }
.det-submit-btn:disabled { opacity: .5; cursor: not-allowed; }
.det-reset-btn {
    padding: 8px 12px;
    background: #f1f5f9;
    color: #475569;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.det-reset-btn:hover { background: #e2e8f0; }
.det-row-msg { font-size: 11px; display: none; }

/* ── Delivery records table ──────────────────────────────────── */
.deliveries-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}
.table-wrapper { overflow-x: auto; }
.deliveries-table table { width: 100%; border-collapse: collapse; }
.deliveries-table th,
.deliveries-table td { padding: 10px 13px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
.deliveries-table th {
    background: #f8fafc;
    font-weight: 700;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.deliveries-table tbody tr:hover td { background: #f8fafc; }

/* ── Status badges ───────────────────────────────────────────── */
.status-badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; display: inline-block; white-space: nowrap; }
.status-badge.pending         { background: #fef9c3; color: #854d0e; }
.status-badge.verified        { background: #dcfce7; color: #166534; }
.status-badge.rejected        { background: #fee2e2; color: #991b1b; }
.status-badge.pending_review  { background: #fef9c3; color: #854d0e; }

/* ── Tabs Styling ────────────────────────────────────────────── */
.tabs-container { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; padding-bottom: 1px; }
.tab-btn { padding: 10px 20px; border: none; background: none; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.15s; position: relative; }
.tab-btn::after { content: ''; position: absolute; bottom: -2px; left: 0; right: 0; height: 3px; background: transparent; transition: all 0.15s; }
.tab-btn.active { color: #002F6C; font-weight: 700; }
.tab-btn.active::after { background: #002F6C; }

.empty-state { text-align: center; padding: 36px; color: #6c757d; }
.empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: .45; display: block; }

/* ── Expected Deliveries Card (matches merchandise style) ── */
.exp-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); border: 1px solid #e9ecef; margin-bottom: 20px; }
.exp-card-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e9ecef; }
.exp-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.exp-card-body { padding: 20px; }
.expected-item { background: #f8f9fa; border: 1px solid #e9ecef; border-left: 4px solid #002F6C; border-radius: 8px; padding: 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; transition: transform .1s, box-shadow .1s; }
.expected-item:last-child { margin-bottom: 0; }
.expected-item:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,.05); }
.expected-info h4 { margin: 0 0 4px 0; font-size: 14px; color: #002F6C; }
.expected-meta { font-size: 12px; color: #6c757d; display: flex; gap: 12px; flex-wrap: wrap; }
.expected-meta span { display: inline-flex; align-items: center; gap: 4px; }
.po-badge { background: #e8f4fd; color: #002F6C; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 11px; font-weight: bold; border: 1px solid #b8d4f0; }
.btn-receive-fuel { background: #28a745; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
.btn-receive-fuel:hover { background: #218838; }

/* ── Side-by-side layout (mirrors merchandise deliveries) ── */
.layout-grid { display: grid; grid-template-columns: 1fr; gap: 24px; margin-bottom: 24px; }
@media (min-width: 1100px) { .layout-grid { grid-template-columns: 1fr 1fr; } }
.layout-grid .exp-card,
.layout-grid .del-card { height: 100%; margin-bottom: 0; }
</style>

<div class="page-head" data-rendering="php">
    <div>
        <h1 class="h1"><i class="fas fa-truck" style="color:#003d82;margin-right:8px;"></i>Fuel Deliveries</h1>
        <div class="sub">Record fuel deliveries received from suppliers — pending manager validation</div>
    </div>
    <a href="staff_transactions_hub.php?section=fuel" class="btn-back">
        <i class="fas fa-arrow-left"></i> Back to Fuel Transactions
    </a>
</div>

<!-- Message Alert -->
<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" style="margin-bottom:20px;">
    <?= $msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$active_tab = 'record-tab';
if (isset($_GET['period']) || isset($_GET['fuel_type_filter']) || isset($_GET['supplier_filter']) || isset($_GET['keyword'])) {
    $active_tab = 'history-tab';
}
?>

<!-- Tab Navigation -->
<div class="tabs-container">
    <button class="tab-btn <?= $active_tab === 'record-tab' ? 'active' : '' ?>" onclick="switchTab('record-tab')" id="tab_record-tab">
        <i class="fas fa-edit"></i> Record Deliveries
    </button>
    <button class="tab-btn <?= $active_tab === 'history-tab' ? 'active' : '' ?>" onclick="switchTab('history-tab')" id="tab_history-tab">
        <i class="fas fa-history"></i> My Delivery Records
        <?php if ($pending_count > 0): ?>
            <span style="background:#b45309;color:#fff;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:700;margin-left:4px;"><?= $pending_count ?></span>
        <?php endif; ?>
    </button>
</div>

<div id="record-tab" class="tab-content" style="display: <?= $active_tab === 'record-tab' ? 'block' : 'none' ?>;">
    <!-- ═══════════════════════════════════════════════════════════
         SIDE-BY-SIDE: Expected Deliveries  |  Record New Delivery
    ═══════════════════════════════════════════════════════════ -->
    <div class="layout-grid">

    <!-- LEFT: Expected Fuel Deliveries (from Admin-finalized POs) -->
    <div class="exp-card">
    <div class="exp-card-head">
        <div class="exp-card-title">
            <i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Expected Fuel Deliveries
            <?php if (count($expected_fuel_deliveries) > 0): ?>
                <span style="background:#dc3545;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;"><?php echo count($expected_fuel_deliveries); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="exp-card-body">
        <?php if (!empty($expected_fuel_fetch_error)): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:14px 16px;color:#991b1b;font-size:13px;">
                <i class="fas fa-exclamation-circle"></i> <strong>Fetch Error:</strong> <?php echo htmlspecialchars($expected_fuel_fetch_error); ?>
            </div>
        <?php elseif (empty($expected_fuel_deliveries)): ?>
            <div style="text-align:center;padding:40px;color:#adb5bd;">
                <i class="fas fa-box-open" style="font-size:3em;margin-bottom:15px;display:block;opacity:.65;"></i>
                No expected fuel deliveries at the moment.
            </div>
        <?php else: ?>
            <?php foreach ($expected_fuel_deliveries as $efd): ?>
            <div class="expected-item">
                <div class="expected-info">
                    <h4><i class="fas fa-gas-pump" style="margin-right:6px;opacity:.7;"></i><?php echo htmlspecialchars($efd['product']); ?></h4>
                    <div class="expected-meta">
                        <span><i class="fas fa-hashtag"></i> PO: <span class="po-badge"><?php echo htmlspecialchars($efd['source_ref'] ?? 'N/A'); ?></span></span>
                        <span><i class="fas fa-tint"></i> Exp: <strong><?php echo number_format((float)$efd['quantity'], 2); ?> L</strong></span>
                        <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($efd['supplier']); ?></span>
                    </div>
                </div>
                <button class="btn-receive-fuel" onclick="openFuelReceiveModal(
                    <?php echo (int)$efd['id']; ?>,
                    '<?php echo addslashes($efd['source_ref'] ?? ''); ?>',
                    '<?php echo addslashes($efd['product']); ?>',
                    '<?php echo addslashes($efd['supplier']); ?>',
                    <?php echo (float)$efd['quantity']; ?>
                )">
                    <i class="fas fa-hand-holding-box"></i> Receive
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- RIGHT: Record New Delivery — Table format (matches fuel transaction style) -->
<!-- ═══════════════════════════════════════════════════════════
     RECORD NEW DELIVERY — Table format (matches fuel transaction style)
═══════════════════════════════════════════════════════════ -->

<?php if (empty($fuel_types)): ?>
<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:16px 20px;margin-bottom:20px;color:#7c5c00;font-size:13px;">
    <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
    No fuel types configured for this station. Contact your manager.
</div>
<?php else: ?>

<!-- Hidden forms for each fuel row (placed outside table — <form> inside <tr> is invalid HTML) -->
<?php foreach ($fuel_types as $idx => $fuel):
    $ft_key  = 'del_' . preg_replace('/[^a-z0-9]/', '_', strtolower($fuel['name'])) . '_' . $idx;
    $ft_name = htmlspecialchars($fuel['name']);
    $tl_key  = strtolower(trim($fuel['name']));
    $tl      = $tank_levels[$tl_key] ?? null;
    $cur     = $tl ? (float)$tl['current_stock'] : 0;
    $cap     = $tl ? (float)$tl['capacity']      : 0;
?>
<form id="delForm_<?= $ft_key ?>"
      method="POST"
      action="staff_fuel_deliveries.php"
      style="display:none;">
    <input type="hidden" name="action"            value="record_delivery">
    <input type="hidden" name="fuel_type"         value="<?= $ft_name ?>">
    <input type="hidden" name="delivery_date"     id="delDate_<?= $ft_key ?>">
    <input type="hidden" name="supplier"          id="delSupplier_<?= $ft_key ?>">
    <input type="hidden" name="invoice_no"        id="delInvoice_<?= $ft_key ?>">
    <input type="hidden" name="delivery_liters"   id="delLiters_<?= $ft_key ?>">
    <input type="hidden" name="tanker_number"     id="delTanker_<?= $ft_key ?>">
    <input type="hidden" name="notes"             id="delNotes_<?= $ft_key ?>">
</form>
<?php endforeach; ?>

<div class="del-card">
    <div class="del-card-header">
        <i class="fas fa-truck" style="color:#003d82;"></i>
        <h3>Record New Delivery</h3>
    </div>

    <!-- Shared fields: Date & Supplier (apply to all rows) -->
    <div class="del-shared-row">
        <div class="del-shared-field">
            <label><i class="fas fa-calendar-day" style="margin-right:4px;"></i>Delivery Date <span style="color:#dc2626;">*</span></label>
            <input type="date" id="sharedDate" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="del-shared-field" style="max-width:280px;flex:0 1 280px;">
            <label><i class="fas fa-building" style="margin-right:4px;"></i>Supplier <span style="color:#dc2626;">*</span></label>
            <input type="text" id="sharedSupplier" value="Petron Corporation"
                   placeholder="e.g., Petron Corporation"
                   list="fuelSupplierList"
                   autocomplete="off">
            <datalist id="fuelSupplierList">
                <?php foreach ($fuel_supplier_list as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div style="display:flex;align-items:flex-end;padding-bottom:1px;">
            <div style="font-size:11px;color:#64748b;background:#f1f5f9;border-radius:7px;padding:8px 12px;line-height:1.5;">
                <i class="fas fa-info-circle" style="margin-right:4px;color:#003d82;"></i>
                Fill in <strong>Invoice</strong> &amp; <strong>Liters</strong> per fuel type below, then click <strong>Submit</strong>.
            </div>
        </div>
    </div>

    <!-- Per-fuel-type row cards -->
    <div class="det-rows">
    <?php foreach ($fuel_types as $idx => $fuel):
        $ft_key   = 'del_' . preg_replace('/[^a-z0-9]/', '_', strtolower($fuel['name'])) . '_' . $idx;
        $ft_name  = htmlspecialchars($fuel['name']);
        $ft_lower = strtolower($fuel['name']);
        $tl_key   = strtolower(trim($fuel['name']));
        $tl       = $tank_levels[$tl_key] ?? null;
        $cur      = $tl ? (float)$tl['current_stock'] : 0;
        $cap      = $tl ? (float)$tl['capacity']      : 0;
        $avail    = ($cap > 0) ? max(0, $cap - $cur) : null;

        if      (str_contains($ft_lower, 'diesel'))   { $ft_color = '#003d7a'; $ft_icon = 'fa-gas-pump';  }
        elseif  (str_contains($ft_lower, 'kerosene')) { $ft_color = '#b45309'; $ft_icon = 'fa-fire';      }
        elseif  (str_contains($ft_lower, 'xcs'))      { $ft_color = '#0369a1'; $ft_icon = 'fa-gas-pump';  }
        elseif  (str_contains($ft_lower, 'xtra'))     { $ft_color = '#15803d'; $ft_icon = 'fa-gas-pump';  }
        elseif  (str_contains($ft_lower, 'blaze'))    { $ft_color = '#b91c1c'; $ft_icon = 'fa-fire-alt';  }
        elseif  (str_contains($ft_lower, 'e10'))      { $ft_color = '#065f46'; $ft_icon = 'fa-leaf';      }
        else                                           { $ft_color = '#334155'; $ft_icon = 'fa-gas-pump';  }

        $fill_pct   = ($cap > 0) ? min(100, round(($cur / $cap) * 100)) : 0;
        $fill_color = $fill_pct >= 80 ? '#15803d' : ($fill_pct >= 40 ? '#b45309' : '#dc2626');
    ?>
    <div class="det-row-card" id="delRow_<?= $ft_key ?>" style="border-left: 4px solid <?= $ft_color ?>;">

        <!-- Head: fuel identity + tank stats -->
        <div class="det-row-head">
            <div class="det-fuel-cell">
                <div style="width:30px;height:30px;border-radius:50%;background:<?= $ft_color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $ft_icon ?>" style="color:#fff;font-size:12px;"></i>
                </div>
                <div class="det-fuel-name" style="color:<?= $ft_color ?>;"><?= $ft_name ?></div>
            </div>
            <?php if ($cap > 0): ?>
            <div class="det-tank-stat">
                <span><?= number_format($cur, 0) ?> / <?= number_format($cap, 0) ?> L</span>
                <span class="det-tank-bar"><span class="det-tank-bar-fill" style="width:<?= $fill_pct ?>%;background:<?= $fill_color ?>;"></span></span>
                <span style="color:<?= $fill_color ?>;font-weight:700;"><?= $fill_pct ?>%</span>
                <?php if ($avail !== null): ?>
                <span style="color:#94a3b8;">— <?= number_format($avail, 0) ?> L avail.</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Body: input fields in 2-column grid -->
        <div class="det-row-body">
            <div class="det-field">
                <label>Invoice No. <span style="color:#dc2626;">*</span></label>
                <input type="text"
                       id="invoice_<?= $ft_key ?>"
                       class="det-input"
                       placeholder="INV-2024-001"
                       maxlength="100"
                       autocomplete="off"
                       style="border-color:<?= $ft_color ?>;"
                       oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="det-field">
                <label>Liters Delivered <span style="color:#dc2626;">*</span></label>
                <input type="number"
                       id="liters_<?= $ft_key ?>"
                       class="det-input"
                       step="0.01" min="0.01"
                       placeholder="e.g. 10000"
                       autocomplete="off"
                       style="border-color:<?= $ft_color ?>;"
                       oninput="checkDelCapacity('<?= $ft_key ?>', <?= $cur ?>, <?= $cap ?>)">
                <div id="capHint_<?= $ft_key ?>" style="font-size:10px;margin-top:2px;display:none;"></div>
            </div>
            <div class="det-field">
                <label>Tanker No.</label>
                <input type="text"
                       id="tanker_<?= $ft_key ?>"
                       class="det-input"
                       placeholder="TK-001"
                       maxlength="50"
                       autocomplete="off"
                       oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="det-field">
                <label>Notes / Remarks</label>
                <input type="text"
                       id="notes_<?= $ft_key ?>"
                       class="det-input notes-input"
                       placeholder="Driver, seal no., remarks…"
                       maxlength="255"
                       autocomplete="off">
            </div>
        </div>

        <!-- Actions -->
        <div class="det-row-actions">
            <button type="button"
                    class="det-submit-btn"
                    id="delSubmitBtn_<?= $ft_key ?>"
                    onclick="triggerDelSubmit('<?= $ft_key ?>', <?= $cur ?>, <?= $cap ?>)">
                <i class="fas fa-paper-plane"></i> Submit
            </button>
            <button type="button"
                    class="det-reset-btn"
                    onclick="resetDelRow('<?= $ft_key ?>')"
                    title="Clear this row">
                <i class="fas fa-undo"></i> Clear
            </button>
            <div id="delRowMsg_<?= $ft_key ?>" class="det-row-msg" style="margin-left:4px;"></div>
        </div>

    </div>
    <?php endforeach; ?>
    </div><!-- /det-rows -->
</div><!-- /del-card -->

</div><!-- /layout-grid -->

<?php endif; ?>
</div><!-- /record-tab -->

<div id="history-tab" class="tab-content" style="display: <?= $active_tab === 'history-tab' ? 'block' : 'none' ?>;">
<!-- ═══════════════════════════════════════════════════════════
     DELIVERY RECORDS — Filter Bar + Summary + Table
═══════════════════════════════════════════════════════════ -->
<div class="deliveries-table">

    <!-- ── Filter Header ──────────────────────────────────────── -->
    <div style="padding:18px 20px;background:#f8f9fa;border-bottom:1px solid #dee2e6;">

        <!-- Title row -->
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <h3 style="margin:0;padding:0;background:none;border:none;font-size:1rem;color:#003d82;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-history"></i> My Delivery Records
                <?php if ($pending_count > 0): ?>
                <span class="status-badge pending"><?= $pending_count ?> Pending</span>
                <?php endif; ?>
            </h3>
        </div>

        <!-- Filter form -->
        <form method="GET" action="staff_fuel_deliveries.php" id="filterForm">

            <!-- Row 1: Period preset buttons -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center;">
                <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-right:4px;">Period:</span>
                <?php
                $periods = [
                    'ytd'     => ['label' => 'Year-to-Date', 'icon' => 'fa-calendar'],
                    'monthly' => ['label' => 'This Month',   'icon' => 'fa-calendar-alt'],
                    'weekly'  => ['label' => 'This Week',    'icon' => 'fa-calendar-week'],
                    'custom'  => ['label' => 'Custom Range', 'icon' => 'fa-sliders-h'],
                ];
                foreach ($periods as $key => $p):
                    $active = ($filter_period === $key);
                ?>
                <button type="button"
                        onclick="setPeriod('<?= $key ?>')"
                        style="padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;border:2px solid <?= $active ? '#002f6c' : '#dee2e6' ?>;background:<?= $active ? '#002f6c' : '#fff' ?>;color:<?= $active ? '#fff' : '#475569' ?>;transition:all .15s;"
                        id="periodBtn_<?= $key ?>">
                    <i class="fas <?= $p['icon'] ?>"></i> <?= $p['label'] ?>
                </button>
                <?php endforeach; ?>
                <input type="hidden" name="period" id="periodInput" value="<?= htmlspecialchars($filter_period) ?>">
            </div>

            <!-- Row 2: Custom date range (shown only when custom is active) -->
            <div id="customDateRow" style="display:<?= $filter_period === 'custom' ? 'flex' : 'none' ?>;gap:10px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end;">
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">From</label>
                    <input type="date" name="date_from" id="dateFrom"
                           value="<?= htmlspecialchars($filter_date_from ?: $resolved_from) ?>"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">To</label>
                    <input type="date" name="date_to" id="dateTo"
                           value="<?= htmlspecialchars($filter_date_to ?: $resolved_to) ?>"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>
            </div>

            <!-- Row 3: Search dropdowns + keyword + action buttons -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

                <!-- Fuel Type filter -->
                <div style="display:flex;flex-direction:column;gap:4px;min-width:160px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Fuel Type</label>
                    <select name="fuel_type_filter"
                            style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                        <option value="">All Types</option>
                        <?php foreach ($fuel_types as $ft): ?>
                        <option value="<?= htmlspecialchars($ft['name']) ?>"
                            <?= $filter_fuel_type === $ft['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ft['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Supplier filter -->
                <div style="display:flex;flex-direction:column;gap:4px;min-width:180px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Supplier</label>
                    <select name="supplier_filter"
                            style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                        <option value="">All Suppliers</option>
                        <?php foreach ($supplier_list as $sup): ?>
                        <option value="<?= htmlspecialchars($sup) ?>"
                            <?= $filter_supplier === $sup ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sup) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Keyword search -->
                <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:180px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Keyword</label>
                    <input type="text" name="keyword"
                           value="<?= htmlspecialchars($filter_keyword) ?>"
                           placeholder="Invoice no., tanker, notes…"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>

                <!-- Action buttons -->
                <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px;">
                    <button type="submit"
                            style="padding:8px 18px;background:#002f6c;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .15s;"
                            onmouseover="this.style.background='#001f4d'" onmouseout="this.style.background='#002f6c'">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="staff_fuel_deliveries.php"
                       style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px;transition:background .15s;"
                       onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>

            </div><!-- /row 3 -->

            <!-- Active filter summary label -->
            <?php
            $period_labels = ['ytd'=>'Year-to-Date','monthly'=>'This Month','weekly'=>'This Week','custom'=>'Custom Range'];
            $period_label  = $period_labels[$filter_period] ?? 'This Month';
            ?>
            <div style="margin-top:10px;font-size:11px;color:#64748b;">
                <i class="fas fa-filter" style="margin-right:4px;"></i>
                Showing: <strong><?= $period_label ?></strong>
                (<?= date('M j, Y', strtotime($resolved_from)) ?> – <?= date('M j, Y', strtotime($resolved_to)) ?>)
                <?php if ($filter_fuel_type): ?> &bull; Fuel: <strong><?= htmlspecialchars($filter_fuel_type) ?></strong><?php endif; ?>
                <?php if ($filter_supplier):  ?> &bull; Supplier: <strong><?= htmlspecialchars($filter_supplier) ?></strong><?php endif; ?>
                <?php if ($filter_keyword):   ?> &bull; Keyword: <strong>"<?= htmlspecialchars($filter_keyword) ?>"</strong><?php endif; ?>
            </div>

        </form>
    </div><!-- /filter header -->

    <!-- ── Table ──────────────────────────────────────────────── -->
    <div class="table-wrapper" style="max-height: 450px; overflow-y: auto;">
        <table id="deliveriesTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Fuel Type</th>
                    <th>Liters</th>
                    <th>Supplier</th>
                    <th>Invoice No.</th>
                    <th>Tanker</th>
                    <th>Status</th>
                    <th>Validated By</th>
                    <th>Encoded</th>
                </tr>
            </thead>
            <tbody id="deliveriesTableBody">
                <?php foreach ($recent_deliveries as $delivery):
                    $st_raw     = $delivery['status'] ?? 'Pending';
                    $st_lower   = strtolower($st_raw);
                    $is_pending = in_array($st_lower, ['pending', 'pending review', 'encoded']);
                    $is_verified = in_array($st_lower, ['verified', 'confirmed', 'approved']);
                    $is_discrepancy = in_array($st_lower, ['discrepancy', 'flagged', 'rejected']);

                    if ($is_verified)     $badge = 'verified';
                    elseif ($is_pending)  $badge = 'pending';
                    elseif ($is_discrepancy) $badge = 'rejected';
                    else                  $badge = 'pending';
                ?>
                <tr style="<?= $is_pending ? 'background:#fffbea;' : ($is_discrepancy ? 'background:#fff5f5;' : '') ?>">
                    <td><strong>#<?= (int)$delivery['id'] ?></strong></td>
                    <td><?= date('M d, Y', strtotime($delivery['delivery_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($delivery['fuel_type']) ?></strong></td>
                    <td><strong><?= number_format((float)$delivery['delivery_liters'], 0) ?> L</strong></td>
                    <td><?= htmlspecialchars($delivery['supplier'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($delivery['invoice_no'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($delivery['tanker_number'] ?? '—') ?></td>
                    <td>
                        <span class="status-badge <?= $badge ?>">
                            <?php if ($is_verified): ?>
                                <i class="fas fa-check-circle"></i>
                            <?php elseif ($is_discrepancy): ?>
                                <i class="fas fa-exclamation-triangle"></i>
                            <?php else: ?>
                                <i class="fas fa-clock"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($st_raw) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($delivery['verified_by_name'])): ?>
                            <span style="font-size:.82rem;color:#155724;font-weight:600;">
                                <i class="fas fa-user-check"></i> <?= htmlspecialchars($delivery['verified_by_name']) ?>
                            </span>
                            <?php if (!empty($delivery['verified_at'])): ?>
                            <div style="font-size:10px;color:#6c757d;margin-top:2px;">
                                <?= date('M j, g:i A', strtotime($delivery['verified_at'])) ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#bbb;font-size:.8rem;">Awaiting manager</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:#888;"><?= date('M j, g:i A', strtotime($delivery['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <?php if (!empty($recent_deliveries)): ?>
    <div id="deliveriesPagination" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; font-size:13px; color:#475569;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="margin:0; font-weight:400; color:#475569;">Rows per page:</label>
            <select id="delRowsPerPage" onchange="changeDelPageSize()" style="padding:4px 24px 4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; background:#fff; color:#1e293b; outline:none; cursor:pointer;">
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <button onclick="prevDelPage()" id="delPrevBtn" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center;" disabled>
                <i class="fas fa-chevron-left"></i>
            </button>
            <span id="delPageInfo" style="color:#475569; font-size:13px; padding:0 4px;">Page 1 of 1</span>
            <button onclick="nextDelPage()" id="delNextBtn" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($recent_deliveries)): ?>
    <div class="empty-state">
        <i class="fas fa-truck"></i>
        <p>No deliveries found for the selected filters.</p>
    </div>
    <?php endif; ?>

</div><!-- /deliveries-table -->
</div><!-- /history-tab -->

<div style="height: 80px;"></div> <!-- Spacer to prevent overlap with fixed footer -->

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(function(el) {
        el.style.display = 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    document.getElementById(tabId).style.display = 'block';
    document.getElementById('tab_' + tabId).classList.add('active');
}

// ── Pagination Logic ─────────────────────────────────────────
var currentDelPage = 1;
var rowsPerDelPage = 10;
var totalDelRows = 0;
var allDelRows = [];

document.addEventListener('DOMContentLoaded', function() {
    var tbody = document.getElementById('deliveriesTableBody');
    if (tbody) {
        allDelRows = Array.from(tbody.querySelectorAll('tr'));
        totalDelRows = allDelRows.length;
        if (totalDelRows > 0) {
            renderDelPage();
        }
    }
});

function changeDelPageSize() {
    var select = document.getElementById('delRowsPerPage');
    rowsPerDelPage = parseInt(select.value, 10);
    currentDelPage = 1;
    renderDelPage();
}

function prevDelPage() {
    if (currentDelPage > 1) {
        currentDelPage--;
        renderDelPage();
    }
}

function nextDelPage() {
    var totalPages = Math.ceil(totalDelRows / rowsPerDelPage);
    if (currentDelPage < totalPages) {
        currentDelPage++;
        renderDelPage();
    }
}

function renderDelPage() {
    if (totalDelRows === 0) return;
    
    var totalPages = Math.ceil(totalDelRows / rowsPerDelPage) || 1;
    var startIndex = (currentDelPage - 1) * rowsPerDelPage;
    var endIndex = Math.min(startIndex + rowsPerDelPage, totalDelRows);
    
    allDelRows.forEach(function(row, index) {
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    var info = document.getElementById('delPageInfo');
    if (info) {
        info.textContent = 'Page ' + currentDelPage + ' of ' + totalPages;
    }
    
    var prevBtn = document.getElementById('delPrevBtn');
    if (prevBtn) {
        prevBtn.disabled = (currentDelPage === 1);
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    var nextBtn = document.getElementById('delNextBtn');
    if (nextBtn) {
        nextBtn.disabled = (currentDelPage >= totalPages) || (totalDelRows === 0);
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
}

// ── Period preset toggle ──────────────────────────────────────
function setPeriod(key) {
    document.getElementById('periodInput').value = key;
    ['ytd','monthly','weekly','custom'].forEach(function(k) {
        var btn = document.getElementById('periodBtn_' + k);
        if (!btn) return;
        if (k === key) {
            btn.style.background  = '#002f6c';
            btn.style.color       = '#fff';
            btn.style.borderColor = '#002f6c';
        } else {
            btn.style.background  = '#fff';
            btn.style.color       = '#475569';
            btn.style.borderColor = '#dee2e6';
        }
    });
    var customRow = document.getElementById('customDateRow');
    if (customRow) customRow.style.display = (key === 'custom') ? 'flex' : 'none';
    if (key !== 'custom') document.getElementById('filterForm').submit();
}

// ── Per-row capacity hint ─────────────────────────────────────
function checkDelCapacity(ftKey, current, capacity) {
    var litersEl = document.getElementById('liters_' + ftKey);
    var hint     = document.getElementById('capHint_' + ftKey);
    if (!litersEl || !hint) return;

    var liters = parseFloat(litersEl.value) || 0;

    // Clear any previous row error message when user is typing
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (msgEl && liters > 0) { msgEl.style.display = 'none'; }

    if (capacity <= 0) { hint.style.display = 'none'; return; }

    var avail = Math.max(0, capacity - current);
    var after = current + liters;
    hint.style.display = 'block';

    if (liters > 0 && after > capacity) {
        hint.style.color      = '#dc2626';
        hint.style.fontWeight = '700';
        hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Exceeds capacity! Avail: '
            + Math.round(avail).toLocaleString() + ' L';
    } else if (liters > 0) {
        hint.style.color      = '#15803d';
        hint.style.fontWeight = '600';
        hint.innerHTML = '<i class="fas fa-check-circle"></i> After delivery: '
            + Math.round(after).toLocaleString() + ' L / '
            + Math.round(capacity).toLocaleString() + ' L ('
            + Math.round((after / capacity) * 100) + '%)';
    } else {
        hint.style.color      = '#64748b';
        hint.style.fontWeight = '400';
        hint.innerHTML = 'Available space: ' + Math.round(avail).toLocaleString() + ' L';
    }
}

// ── Reset a delivery row ──────────────────────────────────────
function resetDelRow(ftKey) {
    ['invoice_', 'liters_', 'tanker_', 'notes_'].forEach(function(prefix) {
        var el = document.getElementById(prefix + ftKey);
        if (el) el.value = '';
    });
    var hint = document.getElementById('capHint_' + ftKey);
    if (hint) { hint.style.display = 'none'; hint.innerHTML = ''; }
    var msg = document.getElementById('delRowMsg_' + ftKey);
    if (msg) { msg.style.display = 'none'; msg.innerHTML = ''; }
    var btn = document.getElementById('delSubmitBtn_' + ftKey);
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit'; }
}

// ── Show inline row message ───────────────────────────────────
function showDelMsg(ftKey, text, color) {
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (!msgEl) return;
    msgEl.style.display  = 'block';
    msgEl.style.color    = color || '#dc2626';
    msgEl.innerHTML      = text;
}

// ── Trigger submit for a row ──────────────────────────────────
function triggerDelSubmit(ftKey, current, capacity) {
    var dateEl     = document.getElementById('sharedDate');
    var supplierEl = document.getElementById('sharedSupplier');
    var invoiceEl  = document.getElementById('invoice_' + ftKey);
    var litersEl   = document.getElementById('liters_'  + ftKey);
    var tankerEl   = document.getElementById('tanker_'  + ftKey);
    var notesEl    = document.getElementById('notes_'   + ftKey);
    var btn        = document.getElementById('delSubmitBtn_' + ftKey);

    // Clear previous message
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (msgEl) { msgEl.style.display = 'none'; msgEl.innerHTML = ''; }

    // Read values
    var delivDate = dateEl     ? dateEl.value.trim()     : '';
    var supplier  = supplierEl ? supplierEl.value.trim() : '';
    var invoice   = invoiceEl  ? invoiceEl.value.trim()  : '';
    var liters    = litersEl   ? parseFloat(litersEl.value) : NaN;

    // ── Validation ────────────────────────────────────────────
    if (!delivDate) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Delivery date is required.');
        dateEl && dateEl.focus();
        return;
    }
    if (!supplier) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Supplier is required.');
        supplierEl && supplierEl.focus();
        return;
    }
    if (!invoice) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Invoice number is required.');
        invoiceEl && invoiceEl.focus();
        return;
    }
    if (isNaN(liters) || liters <= 0) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Enter a valid quantity (liters > 0).');
        litersEl && litersEl.focus();
        return;
    }

    // ── Capacity guard ────────────────────────────────────────
    if (capacity > 0) {
        var avail = Math.max(0, capacity - current);
        if (liters > avail) {
            showDelMsg(ftKey,
                '<i class="fas fa-exclamation-triangle"></i> Exceeds available tank space! Max: '
                + Math.round(avail).toLocaleString() + ' L'
            );
            litersEl && litersEl.focus();
            return;
        }
    }

    // ── Populate hidden form fields ───────────────────────────
    document.getElementById('delDate_'     + ftKey).value = delivDate;
    document.getElementById('delSupplier_' + ftKey).value = supplier;
    document.getElementById('delInvoice_'  + ftKey).value = invoice;
    document.getElementById('delLiters_'   + ftKey).value = liters;
    document.getElementById('delTanker_'   + ftKey).value = tankerEl ? tankerEl.value.trim() : '';
    document.getElementById('delNotes_'    + ftKey).value = notesEl  ? notesEl.value.trim()  : '';

    // ── Disable button to prevent double-submit ───────────────
    if (btn) {
        btn.disabled    = true;
        btn.innerHTML   = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026';
    }

    // ── Submit the hidden form ────────────────────────────────
    var form = document.getElementById('delForm_' + ftKey);
    if (form) {
        form.submit();
    } else {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Form not found. Please refresh the page.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit'; }
    }
}

// ── Prevent Enter key from accidentally submitting shared fields ──
document.addEventListener('DOMContentLoaded', function() {
    ['sharedDate', 'sharedSupplier'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }
    });

    // Move fuel receive modal to body
    var fm = document.getElementById('fuelReceiveModal');
    if (fm && fm.parentNode !== document.body) document.body.appendChild(fm);
});

// ── Fuel Expected Delivery Receive Modal ──
var _fuelExpected = 0;
var tankCapacityInfo = <?php echo json_encode($tank_levels); ?>;

function openFuelReceiveModal(id, po, product, supplier, expected) {
    _fuelExpected = expected;
    document.getElementById('frec_id').value       = id;
    document.getElementById('frec_po').value       = po || 'N/A';
    document.getElementById('frec_product').value  = product;
    document.getElementById('frec_supplier').value = supplier;
    document.getElementById('frec_expected').value = expected.toFixed(2) + ' L';
    document.getElementById('frec_actual').value   = expected.toFixed(2);
    document.getElementById('frec_invoice').value  = '';
    document.getElementById('frec_tanker').value   = '';
    document.getElementById('frec_notes').value    = '';
    checkFuelVariance();
    document.getElementById('fuelReceiveModal').style.display = 'flex';
}

function closeFuelReceiveModal() {
    document.getElementById('fuelReceiveModal').style.display = 'none';
}

function checkFuelVariance() {
    var actual = parseFloat(document.getElementById('frec_actual').value) || 0;
    var warn   = document.getElementById('fuelVarianceWarn');
    var product = document.getElementById('frec_product').value.toLowerCase().trim();
    var overfillWarn = document.getElementById('fuelOverfillWarn');
    
    // Variance check
    if (Math.abs(actual - _fuelExpected) > 0.001) {
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }

    // Live Tank Capacity / Overfill validation
    overfillWarn.style.display = 'none';
    if (tankCapacityInfo && tankCapacityInfo[product]) {
        var info = tankCapacityInfo[product];
        var capacity = parseFloat(info.capacity) || 0;
        var current = parseFloat(info.current_stock) || 0;
        var available = Math.max(0, capacity - current);
        
        if (actual > available) {
            document.getElementById('fuelOverfillMsg').innerHTML = 
                '<strong>&times; Overfill Warning!</strong> Delivery of <strong>' + actual.toLocaleString() + ' L</strong> exceeds remaining tank space of <strong>' + available.toLocaleString() + ' L</strong> (Capacity: ' + capacity.toLocaleString() + ' L, Current level: ' + current.toLocaleString() + ' L).';
            overfillWarn.style.display = 'block';
        }
    }
}

document.addEventListener('click', function(e) {
    var modal = document.getElementById('fuelReceiveModal');
    if (modal && e.target === modal) closeFuelReceiveModal();
});

</script>

<!-- ═══════════════ FUEL RECEIVE MODAL ═══════════════ -->
<div id="fuelReceiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;width:520px;max-width:92%;padding:28px;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:mIn .2s ease;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #e9ecef;">
            <h3 style="margin:0;color:#002F6C;font-size:16px;"><i class="fas fa-gas-pump"></i> Receive Fuel Delivery (PO-Based)</h3>
            <button onclick="closeFuelReceiveModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#adb5bd;">&times;</button>
        </div>

        <form method="POST" action="staff_fuel_deliveries.php">
            <input type="hidden" name="action" value="receive_fuel_expected">
            <input type="hidden" name="delivery_id" id="frec_id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">PO Number</label>
                    <input type="text" id="frec_po" readonly style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#f8f9fa;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Supplier</label>
                    <input type="text" id="frec_supplier" readonly style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#f8f9fa;box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Fuel Type</label>
                <input type="text" id="frec_product" readonly style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#f8f9fa;font-weight:700;color:#002F6C;box-sizing:border-box;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;background:#f0f4ff;padding:14px;border-radius:8px;border:1px solid #c5d3f0;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#002F6C;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Expected (PO Liters)</label>
                    <input type="text" id="frec_expected" readonly style="width:100%;padding:8px 11px;border:1.5px solid #b8d4f0;border-radius:7px;font-size:14px;background:#e8f4fd;color:#002F6C;font-weight:700;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#28a745;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Actual Delivered (L) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" name="actual_liters" id="frec_actual" required oninput="checkFuelVariance()"
                           style="width:100%;padding:8px 11px;border:1.5px solid #28a745;border-radius:7px;font-size:14px;font-weight:700;background:#f8fff9;box-sizing:border-box;">
                </div>
            </div>

            <div id="fuelVarianceWarn" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 12px;font-size:12px;color:#856404;margin-bottom:14px;">
                <i class="fas fa-exclamation-triangle"></i> <strong>Variance Detected!</strong> Actual liters does not match PO quantity. This will be flagged for Manager review.
            </div>

            <div id="fuelOverfillWarn" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:10px 12px;font-size:12px;color:#991b1b;margin-bottom:14px;">
                <span id="fuelOverfillMsg"></span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Invoice No. <span style="color:red;">*</span></label>
                    <input type="text" name="invoice_no" id="frec_invoice" required placeholder="e.g. INV-2024-001"
                           style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value=this.value.toUpperCase()">
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Tanker No.</label>
                    <input type="text" name="tanker_number" id="frec_tanker" placeholder="e.g. TK-001"
                           style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;box-sizing:border-box;" oninput="this.value=this.value.toUpperCase()">
                </div>
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">Notes (Optional)</label>
                <input type="text" name="notes" id="frec_notes" placeholder="Driver, seal no., remarks..."
                       style="width:100%;padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;box-sizing:border-box;">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e9ecef;padding-top:14px;">
                <button type="button" onclick="closeFuelReceiveModal()"
                        style="background:#e9ecef;color:#495057;border:none;padding:10px 18px;border-radius:7px;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit"
                        style="background:#28a745;color:#fff;border:none;padding:10px 22px;border-radius:7px;font-weight:700;cursor:pointer;">
                    <i class="fas fa-check"></i> Submit Delivery
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
