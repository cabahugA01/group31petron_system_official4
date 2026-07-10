<?php
// ============================================================
// Manager Merchandise Purchase Requests — public/manager_stock_request_review.php
// Flow: Staff Stock Requests -> Manager generates Merchandise Purchase Request -> Admin validates
// ============================================================
$page_id = 'mgr_stock_review';
$page_title = 'Purchase Request';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

// Ensure the required tables and schema modifications exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        product_id INT NULL,
        item_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        quantity_ordered INT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        quantity_received INT NULL,
        received_at DATETIME NULL,
        received_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ignored) {}

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate Merchandise Purchase Request
    if ($action === 'generate_merch_pr') {
        $pr_number = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $quantities = $_POST['quantities'] ?? []; // Array of product_id => qty
        $stock_req_ids = $_POST['stock_req_ids'] ?? []; // Array of product_id => stock_request_id
        
        if (empty($pr_number)) {
            $pr_number = "PR-" . date('Ymd') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        try {
            $pdo->beginTransaction();

            $total_amount = 0;
            $items_to_insert = [];

            foreach ($quantities as $prod_id => $qty) {
                $qty = (int)$qty;
                if ($qty <= 0) continue;

                // Retrieve product details
                $p_stmt = $pdo->prepare("SELECT product_name, unit_cost, unit_price FROM inventory_products WHERE id = ?");
                $p_stmt->execute([$prod_id]);
                $prod = $p_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) continue;

                $unit_cost = (float)($prod['unit_cost'] ?: ($prod['unit_price'] * 0.8) ?: 145.00);
                $subtotal = $qty * $unit_cost;
                $total_amount += $subtotal;

                $items_to_insert[] = [
                    'product_id' => $prod_id,
                    'product_name' => $prod['product_name'],
                    'quantity' => $qty,
                    'unit_price' => $unit_cost,
                    'total_price' => $subtotal,
                    'stock_req_id' => isset($stock_req_ids[$prod_id]) ? (int)$stock_req_ids[$prod_id] : null
                ];
            }

            if (empty($items_to_insert)) {
                throw new Exception("Please specify quantity for at least one item.");
            }

            // Find or default to Petron supplier ID
            $supplier_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' OR id = 1 LIMIT 1")->fetchColumn() ?: 1;

            // Resolve the representative stock request ID
            $first_req_id = null;
            foreach ($items_to_insert as $item) {
                if ($item['stock_req_id']) {
                    $first_req_id = $item['stock_req_id'];
                    break;
                }
            }

            // Insert into purchase_orders
            $po_stmt = $pdo->prepare("
                INSERT INTO purchase_orders 
                    (po_number, station_id, supplier_id, created_by, status, expected_delivery_date, remarks, type, total_amount, request_id, created_at, updated_at, admin_finalized)
                VALUES (?, ?, ?, ?, 'Pending Admin Validation', ?, ?, 'merch', ?, ?, NOW(), NOW(), 0)
            ");
            $po_stmt->execute([
                $pr_number, $station_id, $supplier_id, $me['id'], $expected_delivery ?: null, $remarks, $total_amount, $first_req_id
            ]);
            $po_id = $pdo->lastInsertId();

            // Insert items & update linked stock requests
            foreach ($items_to_insert as $item) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO purchase_order_items 
                        (po_id, product_id, item_name, quantity, quantity_ordered, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $item_stmt->execute([
                    $po_id, $item['product_id'], $item['product_name'], $item['quantity'], $item['quantity'], $item['unit_price'], $item['total_price']
                ]);

                // Update stock request status if linked
                if ($item['stock_req_id']) {
                    $sr_stmt = $pdo->prepare("
                        UPDATE stock_requests 
                        SET status = 'Approved', approved_quantity = ?, manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $sr_stmt->execute([
                        $item['quantity'], $me['id'], $remarks, $item['stock_req_id'], $station_id
                    ]);

                    // Audit trail for stock request
                    $audit_stmt = $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Approved', ?, ?, 'Pending', 'Approved', ?)
                    ");
                    $audit_stmt->execute([
                        $item['stock_req_id'], $me['id'], $role, "Approved by Manager. Associated with Purchase Request: $pr_number"
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Generate Purchase Request', "Generated Merchandise Purchase Request: $pr_number");
            $pdo->commit();
            $_SESSION['success'] = "Merchandise Purchase Request <strong>$pr_number</strong> generated successfully and forwarded to Admin.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }

        header('Location: manager_stock_request_review.php');
        exit;
    }

    // 2. Cancel Purchase Request
    if ($action === 'cancel_pr') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if ($po_id > 0) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND station_id = ?");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($po && in_array($po['status'], ['Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft'])) {
                    // Update status to Cancelled
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")->execute([$po_id]);

                    // Restore linked stock requests back to Pending
                    if ($po['request_id']) {
                        $pdo->prepare("
                            UPDATE stock_requests sr
                            JOIN purchase_order_items poi ON poi.product_id = sr.item_id AND sr.status = 'Approved'
                            SET sr.status = 'Pending', sr.approved_quantity = NULL, sr.processed_at = NULL 
                            WHERE poi.po_id = ? AND sr.station_id = ?
                        ")->execute([$po_id, $station_id]);
                    }

                    log_activity($pdo, $me['id'], 'Cancel Purchase Request', "Cancelled Purchase Request: {$po['po_number']}");
                    $pdo->commit();
                    $_SESSION['success'] = "Purchase Request <strong>{$po['po_number']}</strong> has been cancelled.";
                } else {
                    throw new Exception("Unable to cancel. Purchase Request not found or already processed.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php');
        exit;
    }

    // 3. Generate Fuel Purchase Request
    if ($action === 'generate_fuel_pr') {
        $pr_number       = trim($_POST['pr_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $remarks         = trim($_POST['remarks'] ?? '');
        $fuel_quantities = $_POST['fuel_quantities'] ?? [];  // fuel_type_id => liters
        $fuel_req_id     = (int)($_POST['fuel_req_id'] ?? 0);

        if (empty($pr_number)) {
            $pr_number = 'FPR-' . date('Ymd') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
        }
        try {
            $pdo->beginTransaction();
            $supplier_id = (int)($pdo->query("SELECT id FROM suppliers LIMIT 1")->fetchColumn() ?: 1);
            $inserted = 0;
            foreach ($fuel_quantities as $fuel_type_id => $liters) {
                $liters = (float)$liters;
                if ($liters <= 0) continue;
                $fp = $pdo->prepare("SELECT price_per_liter FROM fuel_inventory WHERE fuel_type_id = ? AND station_id = ? LIMIT 1");
                $fp->execute([$fuel_type_id, $station_id]);
                $price = (float)($fp->fetchColumn() ?: 60.00);
                $note_text = ($fuel_req_id > 0 ? "[FSR:{$fuel_req_id}] " : '') . $remarks;
                $pdo->prepare("
                    INSERT INTO fuel_purchase_orders
                        (po_number, station_id, fuel_type_id, volume, unit_price, total_amount,
                         supplier_id, expected_delivery_date, status, created_by, notes, batch_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Admin Validation', ?, ?, ?, NOW(), NOW())
                ")->execute([
                    $pr_number, $station_id, $fuel_type_id, $liters, $price, $liters * $price,
                    $supplier_id, $expected_delivery ?: null, $me['id'], $note_text, $pr_number
                ]);
                $inserted++;
            }
            if ($inserted === 0) throw new Exception('Enter liters for at least one fuel type.');
            if ($fuel_req_id > 0) {
                $pdo->prepare("UPDATE fuel_stock_requests SET status='Approved', manager_id=?, processed_at=NOW() WHERE id=? AND station_id=?")
                    ->execute([$me['id'], $fuel_req_id, $station_id]);
            }
            if (function_exists('log_activity')) log_activity($pdo, $me['id'], 'Generate Fuel PR', "Generated Fuel PR: $pr_number");
            $pdo->commit();
            $_SESSION['success'] = "Fuel Purchase Request <strong>$pr_number</strong> generated and forwarded to Admin.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_stock_request_review.php?tab=fuel'); exit;
    }

    // 4. Cancel Fuel Purchase Request
    if ($action === 'cancel_fuel_pr') {
        $batch_id = trim($_POST['batch_id'] ?? '');
        if ($batch_id) {
            try {
                $chk = $pdo->prepare("SELECT status FROM fuel_purchase_orders WHERE batch_id=? AND station_id=? LIMIT 1");
                $chk->execute([$batch_id, $station_id]);
                $fst = $chk->fetchColumn();
                if (in_array($fst, ['Pending', 'Pending Admin Validation', 'Draft'])) {
                    $pdo->prepare("UPDATE fuel_purchase_orders SET status='Cancelled' WHERE batch_id=? AND station_id=?")
                        ->execute([$batch_id, $station_id]);
                    $_SESSION['success'] = "Fuel PR <strong>$batch_id</strong> cancelled.";
                } else {
                    throw new Exception('Cannot cancel a request with status: ' . $fst);
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_stock_request_review.php?tab=fuel'); exit;
    }
}

// ── Fetch Summary Metrics ───────────────────────────────────────────────────
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_prs,
            SUM(CASE WHEN status IN ('Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft') THEN 1 ELSE 0 END) AS pending_prs,
            SUM(CASE WHEN status IN ('Approved', 'Approved PO', 'Confirmed', 'Official', 'Admin Finalized') THEN 1 ELSE 0 END) AS generated_prs,
            SUM(CASE WHEN status IN ('Approved', 'Approved PO', 'Confirmed', 'Official', 'Admin Finalized') AND COALESCE(stock_in_done, 0) = 0 THEN 1 ELSE 0 END) AS waiting_delivery_prs
        FROM purchase_orders
        WHERE type = 'merch' AND station_id = ?
    ");
    $stats_stmt->execute([$station_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = [];
}

$total_prs = $stats['total_prs'] ?? 0;
$pending_prs = $stats['pending_prs'] ?? 0;
$generated_prs = $stats['generated_prs'] ?? 0;
$waiting_delivery_prs = $stats['waiting_delivery_prs'] ?? 0;

// ── Fetch Purchase Requests ──────────────────────────────────────────────────
$purchase_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, 
               u.name AS prepared_by_name,
               sr.id AS linked_stock_request_id,
               sr_u.name AS requested_by_name,
               (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS total_products
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        LEFT JOIN users sr_u ON sr.staff_id = sr_u.id
        WHERE po.station_id = ? AND po.type = 'merch'
        ORDER BY po.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $purchase_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch Pending Stock Requests (grouped by staff + timestamp) ──────────────
$pending_items = [];
try {
    $pending_stmt = $pdo->prepare("
        SELECT sr.*, u.name AS staff_name, 
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.stock_level, ip.stock_quantity, ip.stock, sr.current_stock, 0) AS current_stock_actual,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               ip.sku AS prod_sku
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status = 'Pending' AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY sr.created_at DESC
    ");
    $pending_stmt->execute([$station_id]);
    $pending_items = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$grouped_requests = [];
foreach ($pending_items as $item) {
    $time_key = date('YmdHi', strtotime($item['created_at']));
    // Group by staff ID and the YYYYMMDDHHMM timestamp
    $group_key = $item['staff_id'] . '_' . $time_key;
    if (!isset($grouped_requests[$group_key])) {
        $grouped_requests[$group_key] = [
            'staff_name' => $item['staff_name'],
            'created_at' => $item['created_at'],
            'date_formatted' => date('M d, Y h:i A', strtotime($item['created_at'])),
            'items' => []
        ];
    }
    $grouped_requests[$group_key]['items'][] = $item;
}

// ── Fetch Active Products (for manual addition) ─────────────────────────────
$all_station_products = [];
try {
    $products_stmt = $pdo->prepare("
        SELECT ip.*, 
               COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0) AS current_stock_actual, 
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.unit, ip.size, 'pcs') AS stock_unit
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON ip.id = si.product_id AND si.station_id = ?
        WHERE ip.station_id = ? AND LOWER(ip.category) != 'fuel' AND ip.status = 'active'
        ORDER BY ip.product_name ASC
    ");
    $products_stmt->execute([$station_id, $station_id]);
    $all_station_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch PO Items Grouped by PO ID ─────────────────────────────────────────
$po_items_grouped = [];
try {
    $items_stmt = $pdo->prepare("
        SELECT poi.* 
        FROM purchase_order_items poi
        JOIN purchase_orders po ON poi.po_id = po.id
        WHERE po.station_id = ? AND po.type = 'merch'
    ");
    $items_stmt->execute([$station_id]);
    $all_po_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_po_items as $item) {
        $po_items_grouped[$item['po_id']][] = $item;
    }
} catch (Exception $e) {}

// ── Active tab ────────────────────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'merchandise';

// ── Fuel PRs grouped by batch_id ─────────────────────────────────────────────
$fuel_prs = [];
try {
    $fp_stmt = $pdo->prepare("
        SELECT fpo.batch_id, fpo.po_number, fpo.status, fpo.expected_delivery_date,
               MIN(fpo.created_at) AS created_at,
               u.name AS prepared_by_name,
               GROUP_CONCAT(DISTINCT COALESCE(fi.fuel_type, CONCAT('Type#',fpo.fuel_type_id))
                   ORDER BY COALESCE(fi.fuel_type,'') SEPARATOR ', ') AS fuel_types,
               COUNT(DISTINCT fpo.id) AS total_fuel_items,
               SUM(fpo.total_amount) AS batch_total,
               MAX(fpo.notes) AS notes
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.created_by = u.id
        LEFT JOIN (SELECT DISTINCT fuel_type_id, fuel_type FROM fuel_inventory) fi ON fpo.fuel_type_id = fi.fuel_type_id
        WHERE fpo.station_id = ?
        GROUP BY fpo.batch_id, fpo.po_number, fpo.status, fpo.expected_delivery_date, u.name
        ORDER BY MIN(fpo.created_at) DESC
    ");
    $fp_stmt->execute([$station_id]);
    $fuel_prs = $fp_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fuel PR items grouped by batch_id ────────────────────────────────────────
$fuel_pr_items_grouped = [];
try {
    $fpi_stmt = $pdo->prepare("
        SELECT fpo.*, COALESCE(fi.fuel_type, CONCAT('Type#',fpo.fuel_type_id)) AS fuel_type_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN (SELECT DISTINCT fuel_type_id, fuel_type FROM fuel_inventory) fi ON fpo.fuel_type_id = fi.fuel_type_id
        WHERE fpo.station_id = ?
    ");
    $fpi_stmt->execute([$station_id]);
    foreach ($fpi_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_pr_items_grouped[$row['batch_id']][] = $row;
    }
} catch (Exception $e) {}

// ── Fuel Summary Stats ───────────────────────────────────────────────────────
$fuel_total_prs = count($fuel_prs);
$fuel_pending_prs = 0; $fuel_generated_prs = 0; $fuel_waiting_prs = 0;
foreach ($fuel_prs as $fp) {
    if (in_array($fp['status'], ['Pending','Pending Admin Validation','Draft'])) {
        $fuel_pending_prs++;
    } elseif (in_array($fp['status'], ['Approved PO','Approved','Confirmed','Official','Admin Finalized'])) {
        $fuel_generated_prs++;
        // Waiting for delivery = no actual delivery yet
        $items = $fuel_pr_items_grouped[$fp['batch_id']] ?? [];
        $has_delivery = false;
        foreach ($items as $it) { if ($it['actual_volume'] > 0) { $has_delivery = true; break; } }
        if (!$has_delivery) $fuel_waiting_prs++;
    }
}

// ── Pending Fuel Stock Requests ───────────────────────────────────────────────
$fuel_inventory_list = [];
try {
    $fi_stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $fi_stmt->execute([$station_id]);
    $fuel_inventory_list = $fi_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$pending_fuel_requests = [];
try {
    $pfr_stmt = $pdo->prepare("
        SELECT fsr.*, u.name AS staff_name
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        WHERE fsr.station_id = ? AND fsr.status = 'Pending'
        ORDER BY fsr.created_at DESC
    ");
    $pfr_stmt->execute([$station_id]);
    $pending_fuel_requests = $pfr_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fuel PR number auto-gen
$f_today = date('Ymd');
try {
    $fpr_c = $pdo->prepare("SELECT COUNT(DISTINCT batch_id) FROM fuel_purchase_orders WHERE batch_id LIKE ?");
    $fpr_c->execute(["FPR-{$f_today}-%"]);
    $fpr_seq = (int)$fpr_c->fetchColumn() + 1;
} catch (Exception $e) { $fpr_seq = 1; }
$fpr_number_auto = 'FPR-' . $f_today . '-' . str_pad($fpr_seq, 4, '0', STR_PAD_LEFT);

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Modern, Clean Styles aligned with SuperAdmin dashboard aesthetics */
.pr-container {
    padding: 24px;
    font-family: 'Inter', sans-serif;
    color: #1e293b;
}
.pr-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}
.pr-title {
    font-size: 24px;
    font-weight: 800;
    color: #002F6C;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pr-subtitle {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
.summary-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.summary-card-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.summary-card-value {
    font-size: 28px;
    font-weight: 800;
    color: #002F6C;
    margin-top: 6px;
}
.summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.bg-total { background: #eff6ff; color: #1d4ed8; }
.bg-pending { background: #fffbeb; color: #d97706; }
.bg-generated { background: #f0fdf4; color: #16a34a; }
.bg-waiting { background: #faf5ff; color: #9333ea; }

.action-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.action-buttons {
    display: flex;
    gap: 10px;
    align-items: center;
}
.btn-pr {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid transparent;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary-pr {
    background: #002F6C;
    color: #fff;
}
.btn-primary-pr:hover {
    background: #001f4d;
}
.btn-outline-pr {
    background: #fff;
    border-color: #cbd5e1;
    color: #475569;
}
.btn-outline-pr:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}

.tab-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 1px;
}
.tab-btn {
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    border: none;
    background: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.tab-btn:hover {
    color: #002F6C;
}
.tab-btn.active {
    color: #002F6C;
    border-bottom-color: #002F6C;
}


.table-wrap-pr {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.table-pr {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table-pr th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: .5px;
    padding: 14px 18px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}
.table-pr td {
    padding: 14px 18px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
    vertical-align: middle;
}
.table-pr tr:hover td {
    background: #f8fafc;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.status-pending { background: #fef3c7; color: #d97706; }
.status-approved { background: #dcfce7; color: #15803d; }
.status-received { background: #dbeafe; color: #1d4ed8; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }

.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    padding: 20px;
}
.modal-overlay.open {
    display: flex;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 800px;
    max-height: calc(100vh - 60px);
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    animation: modalFadeIn 0.18s ease-out;
}
@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.97); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #002F6C;
    border-radius: 12px 12px 0 0;
    color: #fff;
}
.modal-title {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close {
    background: none;
    border: none;
    color: #fff;
    opacity: 0.8;
    font-size: 22px;
    cursor: pointer;
}
.modal-close:hover {
    opacity: 1;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: #f8fafc;
    border-radius: 0 0 12px 12px;
}
.field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field-group label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.field-group input, .field-group select, .field-group textarea {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    background: #fff;
    color: #1e293b;
    transition: border-color 0.2s;
}
.field-group input:focus, .field-group select:focus, .field-group textarea:focus {
    border-color: #002F6C;
}
.field-group input[readonly] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}
.table-inner {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 10px;
}
.table-inner th {
    background: #f1f5f9;
    padding: 10px 12px;
    border-bottom: 2px solid #cbd5e1;
    text-align: left;
    color: #475569;
    font-weight: 700;
}
.table-inner td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
}
.text-right {
    text-align: right;
}
.badge-secondary {
    background: #e2e8f0;
    color: #475569;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
}
</style>

<div class="pr-container">

    <!-- ── Header ── -->
    <div class="pr-head">
        <div>
            <h1 class="pr-title">
                <i class="fas fa-clipboard-list" style="color: #002F6C;"></i> Purchase Request
            </h1>
            <div class="pr-subtitle">
                <?php if ($active_tab === 'fuel'): ?>
                    Review and generate fuel purchase requests.
                <?php else: ?>
                    Review and generate merchandise purchase requests.
                <?php endif; ?>
            </div>
        </div>
        <div style="font-size: 12px; color: #64748b;">
            Logged in: <strong style="color: #002F6C;"><?= htmlspecialchars($me['name']) ?></strong> (Manager)
        </div>
    </div>

    <!-- ── Flash Alerts ── -->
    <?php if (!empty($_SESSION['success'])): ?>
        <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div style="background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- ── Sub Tabs ── -->
    <div class="tab-nav">
        <a href="manager_stock_request_review.php?tab=merchandise" class="tab-btn <?= $active_tab === 'merchandise' ? 'active' : '' ?>">
            <i class="fas fa-boxes"></i> Merchandise Purchase Request
        </a>
        <a href="manager_stock_request_review.php?tab=fuel" class="tab-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>">
            <i class="fas fa-gas-pump"></i> Fuel Purchase Request
        </a>
    </div>

    <?php if ($active_tab === 'fuel'): ?>
        <!-- ==================== FUEL SUB-TAB ==================== -->
        <!-- ── Summary Cards ── -->
        <div class="summary-grid">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Total Fuel Purchase Requests</div>
                    <div class="summary-card-value"><?= number_format($fuel_total_prs) ?></div>
                </div>
                <div class="summary-icon bg-total"><i class="fas fa-gas-pump"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Pending</div>
                    <div class="summary-card-value" style="color: #d97706;"><?= number_format($fuel_pending_prs) ?></div>
                </div>
                <div class="summary-icon bg-pending"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Generated</div>
                    <div class="summary-card-value" style="color: #16a34a;"><?= number_format($fuel_generated_prs) ?></div>
                </div>
                <div class="summary-icon bg-generated"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Waiting for Delivery</div>
                    <div class="summary-card-value" style="color: #9333ea;"><?= number_format($fuel_waiting_prs) ?></div>
                </div>
                <div class="summary-icon bg-waiting"><i class="fas fa-truck"></i></div>
            </div>
        </div>

        <!-- ── Action Bar ── -->
        <div class="action-bar">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <input type="text" id="fuelSearchInput" placeholder="Search Fuel PR No. or fuel type..." oninput="filterFuelPrTable()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 240px; outline: none;">
                <select id="fuelStatusFilter" onchange="filterFuelPrTable()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #fff;">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending / Pending Validation</option>
                    <option value="admin finalized">Admin Finalized</option>
                    <option value="approved">Approved PO / Confirmed</option>
                    <option value="received">Received</option>
                    <option value="cancelled">Cancelled / Rejected</option>
                </select>
            </div>
            <div class="action-buttons">
                <button onclick="location.reload()" class="btn-pr btn-outline-pr"><i class="fas fa-sync-alt"></i> Refresh</button>
                <button onclick="openGenerateFuelModal()" class="btn-pr btn-primary-pr"><i class="fas fa-plus"></i> Generate PO</button>
            </div>
        </div>

        <!-- ── Fuel Table ── -->
        <div class="table-wrap-pr">
            <table class="table-pr" id="fuelPrTable">
                <thead>
                    <tr>
                        <th>PR No.</th>
                        <th>Stock Request No.</th>
                        <th>Requested By</th>
                        <th>Fuel Types</th>
                        <th>Request Date</th>
                        <th>Status</th>
                        <th style="text-align: right; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="fuelPrTableBody">
                    <?php if (empty($fuel_prs)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                                <i class="fas fa-gas-pump" style="font-size: 32px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                No fuel purchase requests found. Click <strong>Generate Fuel Purchase Request</strong> to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fuel_prs as $fpr): 
                            $status = $fpr['status'];
                            $status_class = 'status-pending';
                            if (in_array($status, ['Approved', 'Approved PO', 'Confirmed', 'Official', 'Admin Finalized'])) {
                                $status_class = 'status-approved';
                            } elseif ($status === 'Received') {
                                $status_class = 'status-received';
                            } elseif (in_array($status, ['Cancelled', 'Rejected', 'Rejected by Admin'])) {
                                $status_class = 'status-cancelled';
                            }
                            
                            // Try to parse [FSR:X] from notes
                            $fsr_no = 'Manual';
                            if (preg_match('/\[FSR:(\d+)\]/', $fpr['notes'] ?? '', $matches)) {
                                $fsr_no = 'FSR-' . str_pad($matches[1], 5, '0', STR_PAD_LEFT);
                            }
                            
                            $prepared_by = $fpr['prepared_by_name'] ?: 'Manual (Manager)';
                        ?>
                            <tr class="fuel-pr-row" data-search="<?= htmlspecialchars(strtolower($fpr['po_number'] . ' ' . $prepared_by . ' ' . $fpr['fuel_types'])) ?>" data-status="<?= htmlspecialchars(strtolower($status)) ?>">
                                <td><strong><?= htmlspecialchars($fpr['po_number']) ?></strong></td>
                                <td style="font-family: monospace; font-size: 12px;"><?= $fsr_no ?></td>
                                <td><?= htmlspecialchars($prepared_by) ?></td>
                                <td><?= htmlspecialchars($fpr['fuel_types']) ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($fpr['created_at'])) ?></td>
                                <td><span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-pr btn-outline-pr" onclick="viewFuelPrDetails('<?= htmlspecialchars($fpr['batch_id']) ?>')" style="padding: 4px 8px; font-size: 11px; margin-right: 4px;"><i class="fas fa-eye"></i> View</button>
                                    <?php if (in_array($status, ['Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft'])): ?>
                                        <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this fuel purchase request?');">
                                            <input type="hidden" name="action" value="cancel_fuel_pr">
                                            <input type="hidden" name="batch_id" value="<?= htmlspecialchars($fpr['batch_id']) ?>">
                                            <button type="submit" class="btn-pr" style="padding: 4px 8px; font-size: 11px; color: #b91c1c; border: 1px solid #fca5a5; background: #fff;"><i class="fas fa-ban"></i> Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <!-- ==================== MERCHANDISE SUB-TAB ==================== -->
        <!-- ── Summary Cards ── -->
        <div class="summary-grid">
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Total Merchandise Purchase Requests</div>
                    <div class="summary-card-value"><?= number_format($total_prs) ?></div>
                </div>
                <div class="summary-icon bg-total"><i class="fas fa-file-invoice"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Pending</div>
                    <div class="summary-card-value" style="color: #d97706;"><?= number_format($pending_prs) ?></div>
                </div>
                <div class="summary-icon bg-pending"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Generated</div>
                    <div class="summary-card-value" style="color: #16a34a;"><?= number_format($generated_prs) ?></div>
                </div>
                <div class="summary-icon bg-generated"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-card-label">Waiting for Delivery</div>
                    <div class="summary-card-value" style="color: #9333ea;"><?= number_format($waiting_delivery_prs) ?></div>
                </div>
                <div class="summary-icon bg-waiting"><i class="fas fa-truck"></i></div>
            </div>
        </div>

        <!-- ── Action Bar ── -->
        <div class="action-bar">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <input type="text" id="prSearchInput" placeholder="Search PR No. or staff..." oninput="filterPrTable()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; min-width: 240px; outline: none;">
                <select id="prStatusFilter" onchange="filterPrTable()" style="padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #fff;">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending / Pending Validation</option>
                    <option value="admin finalized">Admin Finalized</option>
                    <option value="approved">Approved PO / Confirmed</option>
                    <option value="received">Received</option>
                    <option value="cancelled">Cancelled / Rejected</option>
                </select>
            </div>
            <div class="action-buttons">
                <button onclick="openGenerateModal()" class="btn-pr btn-primary-pr"><i class="fas fa-plus"></i> Generate Merchandise Purchase Request</button>
                <button onclick="exportToPDF()" class="btn-pr btn-outline-pr"><i class="fas fa-file-pdf"></i> Export PDF</button>
                <button onclick="exportToExcel()" class="btn-pr btn-outline-pr"><i class="fas fa-file-excel"></i> Export Excel</button>
                <button onclick="exportToCSV()" class="btn-pr btn-outline-pr"><i class="fas fa-file-csv"></i> Export CSV</button>
                <button onclick="location.reload()" class="btn-pr btn-outline-pr"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>

        <!-- ── Table ── -->
        <div class="table-wrap-pr">
            <table class="table-pr" id="prTable">
                <thead>
                    <tr>
                        <th>PR No.</th>
                        <th>Stock Request No.</th>
                        <th>Requested By</th>
                        <th style="text-align: center;">Total Products</th>
                        <th>Request Date</th>
                        <th>Status</th>
                        <th style="text-align: right; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="prTableBody">
                    <?php if (empty($purchase_requests)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                                <i class="fas fa-file-invoice" style="font-size: 32px; color: #cbd5e1; display: block; margin-bottom: 12px;"></i>
                                No merchandise purchase requests found. Click <strong>Generate Merchandise Purchase Request</strong> to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchase_requests as $pr): 
                            $status = $pr['status'];
                            $status_class = 'status-pending';
                            if (in_array($status, ['Approved', 'Approved PO', 'Confirmed', 'Official', 'Admin Finalized'])) {
                                $status_class = 'status-approved';
                            } elseif ($status === 'Received') {
                                $status_class = 'status-received';
                            } elseif (in_array($status, ['Cancelled', 'Rejected', 'Rejected by Admin'])) {
                                $status_class = 'status-cancelled';
                            }
                            
                            $requested_by = $pr['requested_by_name'] ?: 'Manual (Manager)';
                            $stock_req = $pr['linked_stock_request_id'] ? 'REQ-' . str_pad($pr['linked_stock_request_id'], 5, '0', STR_PAD_LEFT) : 'Manual';
                        ?>
                            <tr class="pr-row" data-search="<?= htmlspecialchars(strtolower($pr['po_number'] . ' ' . $requested_by)) ?>" data-status="<?= htmlspecialchars(strtolower($status)) ?>">
                                <td><strong><?= htmlspecialchars($pr['po_number']) ?></strong></td>
                                <td style="font-family: monospace; font-size: 12px;"><?= $stock_req ?></td>
                                <td><?= htmlspecialchars($requested_by) ?></td>
                                <td style="text-align: center; font-weight: 700; color: #002F6C;">
                                    <?php 
                                    $item_count = (int)$pr['total_products'];
                                    if ($item_count === 0 && !empty($pr['product_name'])) {
                                        $item_count = 1;
                                    }
                                    echo $item_count > 0 ? $item_count : '<span style="color:#94a3b8;font-weight:normal;">—</span>';
                                    ?>
                                </td>
                                <td><?= date('M d, Y h:i A', strtotime($pr['created_at'])) ?></td>
                                <td><span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-pr btn-outline-pr" onclick="viewPrDetails(<?= $pr['id'] ?>)" style="padding: 4px 8px; font-size: 11px; margin-right: 4px;"><i class="fas fa-eye"></i> View</button>
                                    <?php if (in_array($status, ['Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft'])): ?>
                                        <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this purchase request?');">
                                            <input type="hidden" name="action" value="cancel_pr">
                                            <input type="hidden" name="po_id" value="<?= $pr['id'] ?>">
                                            <button type="submit" class="btn-pr" style="padding: 4px 8px; font-size: 11px; color: #b91c1c; border: 1px solid #fca5a5; background: #fff;"><i class="fas fa-ban"></i> Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: GENERATE MERCHANDISE PURCHASE REQUEST
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="generateModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-file-invoice"></i> Generate Merchandise Purchase Request</h3>
            <button class="modal-close" onclick="closeModal('generateModal')">×</button>
        </div>
        <form action="" method="POST" onsubmit="return validatePrForm()">
            <input type="hidden" name="action" value="generate_merch_pr">
            <div class="modal-body">
                
                <div class="field-grid">
                    <div class="field-group">
                        <label>Purchase Request No.</label>
                        <?php
                        $today = date('Ymd');
                        // Calculate next sequence
                        try {
                            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE po_number LIKE ?");
                            $count_stmt->execute(["PR-$today-%"]);
                            $seq = $count_stmt->fetchColumn() + 1;
                        } catch (Exception $e) {
                            $seq = 1;
                        }
                        $pr_number_auto = "PR-$today-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
                        ?>
                        <input type="text" name="pr_number" id="prNoInput" value="<?= $pr_number_auto ?>" readonly>
                    </div>
                    <div class="field-group">
                        <label>Linked Merchandise Stock Request</label>
                        <select name="linked_stock_request" id="stockRequestSelector" onchange="onStockRequestChanged()">
                            <option value="">-- Manual Purchase Request (No Link) --</option>
                            <?php foreach ($grouped_requests as $key => $grp): ?>
                                <option value="<?= htmlspecialchars($key) ?>">
                                    Request by <?= htmlspecialchars($grp['staff_name']) ?> (<?= $grp['date_formatted'] ?>) - <?= count($grp['items']) ?> items
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="field-group">
                        <label>Date</label>
                        <input type="text" value="<?= date('M d, Y') ?>" readonly>
                    </div>
                    <div class="field-group">
                        <label>Prepared By</label>
                        <input type="text" value="<?= htmlspecialchars($me['name']) ?>" readonly>
                    </div>
                </div>

                <!-- Manual product search (visible when Manual PR is selected) -->
                <div id="manualProductContainer" style="margin-bottom: 20px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; display: block; margin-bottom: 8px;">Add Merchandise Product</label>
                    <div style="display: flex; gap: 10px;">
                        <select id="manualProductSelect" style="flex: 1; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                            <option value="">-- Select Product to Add --</option>
                            <?php foreach ($all_station_products as $p): ?>
                                <option value="<?= $p['id'] ?>" 
                                        data-name="<?= htmlspecialchars($p['product_name']) ?>" 
                                        data-stock="<?= $p['current_stock_actual'] ?>" 
                                        data-reorder="<?= $p['reorder_level'] ?>"
                                        data-unit="<?= htmlspecialchars($p['stock_unit'] ?: ($p['size'] ?: 'pcs')) ?>">
                                    <?= htmlspecialchars($p['product_name']) ?> (Stock: <?= $p['current_stock_actual'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-pr btn-primary-pr" onclick="addManualProductToTable()"><i class="fas fa-plus"></i> Add Product</button>
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 8px;">Merchandise Items</label>
                    <table class="table-inner">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: center; width: 120px;">Current Stock</th>
                                <th style="text-align: center; width: 120px;">Reorder Level</th>
                                <th style="width: 140px;">Quantity to Order</th>
                                <th style="width: 50px; text-align: center;" id="manualThRemove"></th>
                            </tr>
                        </thead>
                        <tbody id="modalItemsBody">
                            <!-- Items will be populated dynamically -->
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 20px; color: #94a3b8;">
                                    Please select a Linked Stock Request or add products manually.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="field-grid" style="margin-top: 20px;">
                    <div class="field-group">
                        <label>Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group">
                        <label>Remarks</label>
                        <textarea name="remarks" rows="2" placeholder="Optional notes for Admin or supplier..."></textarea>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('generateModal')">❌ Cancel</button>
                <button type="submit" class="btn-pr btn-primary-pr">✅ Generate Merchandise Purchase Request</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: VIEW PURCHASE REQUEST DETAILS
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-info-circle"></i> Purchase Request Details: <span id="detPrNo">—</span></h3>
            <button class="modal-close" onclick="closeModal('detailsModal')">×</button>
        </div>
        <div class="modal-body">
            
            <div class="field-grid">
                <div class="field-group">
                    <label>PR Number</label>
                    <input type="text" id="detPrNoField" readonly>
                </div>
                <div class="field-group">
                    <label>Linked Stock Request</label>
                    <input type="text" id="detLinkedSrField" readonly>
                </div>
            </div>

            <div class="field-grid">
                <div class="field-group">
                    <label>Requested By / Prepared By</label>
                    <input type="text" id="detRequestedByField" readonly>
                </div>
                <div class="field-group">
                    <label>Request Date</label>
                    <input type="text" id="detDateField" readonly>
                </div>
            </div>

            <div class="field-grid">
                <div class="field-group">
                    <label>Expected Delivery Date</label>
                    <input type="text" id="detExpectedDeliveryField" readonly>
                </div>
                <div class="field-group">
                    <label>Status</label>
                    <div style="margin-top: 5px;">
                        <span id="detStatusBadge" class="status-badge">—</span>
                    </div>
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 20px;">
                <label>Remarks</label>
                <textarea id="detRemarksField" rows="2" readonly style="background: #f1f5f9; cursor: not-allowed;"></textarea>
            </div>

            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 8px;">Merchandise Items Ordered</label>
                <table class="table-inner">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right" style="width: 140px;">Quantity Ordered</th>
                            <th class="text-right" style="width: 140px;">Unit Price</th>
                            <th class="text-right" style="width: 140px;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="detItemsBody">
                        <!-- Populated dynamically -->
                    </tbody>
                </table>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn-pr btn-primary-pr" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: GENERATE FUEL PURCHASE REQUEST
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="generateFuelModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-gas-pump"></i> Generate Fuel Purchase Request</h3>
            <button class="modal-close" onclick="closeModal('generateFuelModal')">×</button>
        </div>
        <form action="" method="POST" onsubmit="return validateFuelPrForm()">
            <input type="hidden" name="action" value="generate_fuel_pr">
            <div class="modal-body">
                
                <div class="field-grid">
                    <div class="field-group">
                        <label>Purchase Request No.</label>
                        <input type="text" name="pr_number" id="fuelPrNoInput" value="<?= $fpr_number_auto ?>" readonly>
                    </div>
                    <div class="field-group">
                        <label>Linked Fuel Stock Request</label>
                        <select name="fuel_req_id" id="fuelRequestSelector" onchange="onFuelRequestChanged()">
                            <option value="">-- Manual Purchase Request (No Link) --</option>
                            <?php foreach ($pending_fuel_requests as $fr): ?>
                                <option value="<?= htmlspecialchars($fr['id']) ?>" 
                                        data-fuel="<?= htmlspecialchars($fr['fuel_type']) ?>" 
                                        data-qty="<?= htmlspecialchars($fr['requested_liters']) ?>">
                                    Request by <?= htmlspecialchars($fr['staff_name']) ?> (<?= date('M d, Y h:i A', strtotime($fr['created_at'])) ?>) - <?= htmlspecialchars($fr['fuel_type']) ?> (<?= number_format($fr['requested_liters']) ?> L)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="field-group">
                        <label>Date</label>
                        <input type="text" value="<?= date('M d, Y') ?>" readonly>
                    </div>
                    <div class="field-group">
                        <label>Prepared By</label>
                        <input type="text" value="<?= htmlspecialchars($me['name']) ?>" readonly>
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 8px;">Fuel Items</label>
                    <table class="table-inner">
                        <thead>
                            <tr>
                                <th>Fuel Type</th>
                                <th style="text-align: center; width: 140px;">Current Level</th>
                                <th style="text-align: center; width: 140px;">Reorder Level</th>
                                <th style="width: 180px;">Quantity to Order (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fuel_inventory_list)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 20px; color: #94a3b8;">No fuel inventory data found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fuel_inventory_list as $f_item): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($f_item['fuel_type']) ?></strong></td>
                                        <td style="text-align: center;"><strong><?= number_format($f_item['current_level']) ?> L</strong></td>
                                        <td style="text-align: center;"><strong><?= number_format($f_item['reorder_level']) ?> L</strong></td>
                                        <td>
                                            <input type="number" name="fuel_quantities[<?= $f_item['fuel_type_id'] ?>]" 
                                                   id="fuel_qty_input_<?= htmlspecialchars($f_item['fuel_type']) ?>"
                                                   min="0" placeholder="Liters to order" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="field-grid" style="margin-top: 20px;">
                    <div class="field-group">
                        <label>Expected Delivery Date</label>
                        <input type="date" name="expected_delivery" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group">
                        <label>Remarks</label>
                        <textarea name="remarks" rows="2" placeholder="Optional notes for Tanker delivery..."></textarea>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('generateFuelModal')">❌ Cancel</button>
                <button type="submit" class="btn-pr btn-primary-pr">✅ Generate Fuel Purchase Request</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: VIEW FUEL PURCHASE REQUEST DETAILS
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="fuelDetailsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-info-circle"></i> Fuel Purchase Request Details: <span id="detFuelPrNo">—</span></h3>
            <button class="modal-close" onclick="closeModal('fuelDetailsModal')">×</button>
        </div>
        <div class="modal-body">
            
            <div class="field-grid">
                <div class="field-group">
                    <label>PR Number</label>
                    <input type="text" id="detFuelPrNoField" readonly>
                </div>
                <div class="field-group">
                    <label>Linked Stock Request</label>
                    <input type="text" id="detFuelLinkedSrField" readonly>
                </div>
            </div>

            <div class="field-grid">
                <div class="field-group">
                    <label>Requested By / Prepared By</label>
                    <input type="text" id="detFuelRequestedByField" readonly>
                </div>
                <div class="field-group">
                    <label>Request Date</label>
                    <input type="text" id="detFuelDateField" readonly>
                </div>
            </div>

            <div class="field-grid">
                <div class="field-group">
                    <label>Expected Delivery Date</label>
                    <input type="text" id="detFuelExpectedDeliveryField" readonly>
                </div>
                <div class="field-group">
                    <label>Status</label>
                    <div style="margin-top: 5px;">
                        <span id="detFuelStatusBadge" class="status-badge">—</span>
                    </div>
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 20px;">
                <label>Remarks</label>
                <textarea id="detFuelRemarksField" rows="2" readonly style="background: #f1f5f9; cursor: not-allowed;"></textarea>
            </div>

            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 8px;">Fuel Items Ordered</label>
                <table class="table-inner">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="text-right" style="width: 180px;">Volume Ordered (L)</th>
                            <th class="text-right" style="width: 160px;">Unit Price (₱/L)</th>
                            <th class="text-right" style="width: 180px;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="detFuelItemsBody">
                        <!-- Populated dynamically -->
                    </tbody>
                </table>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn-pr btn-primary-pr" onclick="closeModal('fuelDetailsModal')">Close</button>
        </div>
    </div>
</div>

<script>
// JSON Data injected from PHP
var groupedStockRequests = <?= json_encode($grouped_requests) ?>;
var poItemsGrouped = <?= json_encode($po_items_grouped) ?>;
var purchaseRequestsList = <?= json_encode($purchase_requests) ?>;
var fuelPrsList = <?= json_encode($fuel_prs) ?>;
var fuelPrItemsGrouped = <?= json_encode($fuel_pr_items_grouped) ?>;

// Search & Filter
function filterPrTable() {
    var search = document.getElementById('prSearchInput').value.toLowerCase();
    var status = document.getElementById('prStatusFilter').value.toLowerCase();
    
    var rows = document.querySelectorAll('#prTableBody tr.pr-row');
    rows.forEach(function(row) {
        var ds = row.getAttribute('data-search') || '';
        var st = row.getAttribute('data-status') || '';
        
        var matchesSearch = !search || ds.indexOf(search) !== -1;
        var matchesStatus = !status || st.indexOf(status) !== -1;
        
        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterFuelPrTable() {
    var search = document.getElementById('fuelSearchInput').value.toLowerCase();
    var status = document.getElementById('fuelStatusFilter').value.toLowerCase();
    
    var rows = document.querySelectorAll('#fuelPrTableBody tr.fuel-pr-row');
    rows.forEach(function(row) {
        var ds = row.getAttribute('data-search') || '';
        var st = row.getAttribute('data-status') || '';
        
        var matchesSearch = !search || ds.indexOf(search) !== -1;
        var matchesStatus = !status || st.indexOf(status) !== -1;
        
        if (matchesSearch && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Modal management
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Triggered when generate PR modal opens
function openGenerateModal() {
    // Reset selector and items
    document.getElementById('stockRequestSelector').value = '';
    onStockRequestChanged();
    openModal('generateModal');
}

// Event triggered when dropdown changes
function onStockRequestChanged() {
    var key = document.getElementById('stockRequestSelector').value;
    var tbody = document.getElementById('modalItemsBody');
    var manualContainer = document.getElementById('manualProductContainer');
    
    tbody.innerHTML = '';
    
    if (key === '') {
        // Manual mode
        manualContainer.style.display = 'block';
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px; color: #94a3b8;">No products added yet. Select a product above to add to the request.</td></tr>';
        document.getElementById('manualThRemove').style.display = '';
    } else {
        // Linked Stock Request mode
        manualContainer.style.display = 'none';
        document.getElementById('manualThRemove').style.display = 'none';
        
        var requestGroup = groupedStockRequests[key];
        if (requestGroup && requestGroup.items.length > 0) {
            requestGroup.items.forEach(function(item) {
                var unit = item.unit || item.current_stock_actual ? '' : 'pcs';
                var currentStock = item.current_stock_actual !== undefined ? item.current_stock_actual : (item.current_stock || 0);
                var reorderLevel = item.reorder_level || 10;
                var productId = item.item_id;
                var stockReqId = item.id;
                
                var tr = document.createElement('tr');
                tr.innerHTML = 
                    '<td><strong>' + escHtml(item.item_name) + '</strong></td>' +
                    '<td style="text-align: center;"><strong>' + currentStock + '</strong></td>' +
                    '<td style="text-align: center;"><strong>' + reorderLevel + '</strong></td>' +
                    '<td>' +
                        '<input type="number" name="quantities[' + productId + ']" min="1" required placeholder="Qty to Order" style="width: 110px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;">' +
                        '<input type="hidden" name="stock_req_ids[' + productId + ']" value="' + stockReqId + '">' +
                    '</td>';
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #94a3b8; font-style: italic;">No pending items found for this stock request.</td></tr>';
        }
    }
}

// Manual Product Addition
function addManualProductToTable() {
    var select = document.getElementById('manualProductSelect');
    var prodId = select.value;
    if (!prodId) return;
    
    var option = select.options[select.selectedIndex];
    var name = option.getAttribute('data-name');
    var stock = option.getAttribute('data-stock');
    var reorder = option.getAttribute('data-reorder');
    var unit = option.getAttribute('data-unit') || 'pcs';
    
    var tbody = document.getElementById('modalItemsBody');
    
    // Check if product is already in the table
    var existingInput = tbody.querySelector('input[name="quantities[' + prodId + ']"]');
    if (existingInput) {
        alert('Product is already in the request list.');
        return;
    }
    
    // Clear the placeholder row if it's there
    if (tbody.children.length === 1 && tbody.children[0].cells.length === 1) {
        tbody.innerHTML = '';
    }
    
    var tr = document.createElement('tr');
    tr.id = 'manual_row_' + prodId;
    tr.innerHTML = 
        '<td><strong>' + escHtml(name) + '</strong></td>' +
        '<td style="text-align: center;"><strong>' + stock + '</strong> ' + escHtml(unit) + '</td>' +
        '<td style="text-align: center;"><strong>' + reorder + '</strong> ' + escHtml(unit) + '</td>' +
        '<td>' +
            '<input type="number" name="quantities[' + prodId + ']" min="1" required placeholder="Qty to Order" style="width: 100px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px;">' +
        '</td>' +
        '<td style="text-align: center;">' +
            '<button type="button" onclick="removeManualRow(' + prodId + ')" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 14px;"><i class="fas fa-trash-alt"></i></button>' +
        '</td>';
    tbody.appendChild(tr);
    
    // Reset selection
    select.value = '';
}

function removeManualRow(prodId) {
    var row = document.getElementById('manual_row_' + prodId);
    if (row) {
        row.parentNode.removeChild(row);
    }
    var tbody = document.getElementById('modalItemsBody');
    if (tbody.children.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px; color: #94a3b8;">No products added yet. Select a product above to add to the request.</td></tr>';
    }
}

// Validate Form submission
function validatePrForm() {
    var tbody = document.getElementById('modalItemsBody');
    
    // Check if table contains input fields
    var inputs = tbody.querySelectorAll('input[type="number"]');
    if (inputs.length === 0) {
        alert('Please select a stock request or add at least one product to the purchase request.');
        return false;
    }
    
    var valid = false;
    inputs.forEach(function(input) {
        if (parseInt(input.value) > 0) {
            valid = true;
        }
    });
    
    if (!valid) {
        alert('Please specify a quantity greater than 0 for at least one product.');
        return false;
    }
    
    return true;
}

// View PR Details Modal
function viewPrDetails(poId) {
    var pr = purchaseRequestsList.find(function(x) { return x.id == poId; });
    if (!pr) return;
    
    document.getElementById('detPrNo').textContent = pr.po_number;
    document.getElementById('detPrNoField').value = pr.po_number;
    
    var stockReq = pr.linked_stock_request_id ? 'REQ-' + String(pr.linked_stock_request_id).padStart(5, '0') : 'Manual PR';
    document.getElementById('detLinkedSrField').value = stockReq;
    
    var requestedBy = pr.requested_by_name ? pr.requested_by_name : 'Manual (Manager)';
    document.getElementById('detRequestedByField').value = requestedBy;
    
    document.getElementById('detDateField').value = fmtDate(pr.created_at);
    document.getElementById('detExpectedDeliveryField').value = pr.expected_delivery_date ? fmtDate(pr.expected_delivery_date, true) : '—';
    document.getElementById('detRemarksField').value = pr.remarks || 'No remarks specified.';
    
    // Badge status
    var badge = document.getElementById('detStatusBadge');
    badge.textContent = pr.status;
    badge.className = 'status-badge';
    if (['Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft'].indexOf(pr.status) !== -1) {
        badge.classList.add('status-pending');
    } else if (['Approved', 'Approved PO', 'Confirmed', 'Official'].indexOf(pr.status) !== -1) {
        badge.classList.add('status-approved');
    } else if (pr.status === 'Received') {
        badge.classList.add('status-received');
    } else {
        badge.classList.add('status-cancelled');
    }
    
    // Render items
    var tbody = document.getElementById('detItemsBody');
    tbody.innerHTML = '';
    var items = poItemsGrouped[poId] || [];
    
    if (items.length === 0) {
        if (pr.product_name) {
            var subtotal = parseFloat(pr.total_amount || (pr.quantity * pr.unit_price) || 0);
            var tr = document.createElement('tr');
            tr.innerHTML = 
                '<td><strong>' + escHtml(pr.product_name) + '</strong></td>' +
                '<td class="text-right">' + parseFloat(pr.quantity).toFixed(0) + '</td>' +
                '<td class="text-right">₱' + parseFloat(pr.unit_price || 0).toFixed(2) + '</td>' +
                '<td class="text-right" style="font-weight: 700;">₱' + subtotal.toFixed(2) + '</td>';
            tbody.appendChild(tr);
            
            var trTotal = document.createElement('tr');
            trTotal.style.background = '#f8fafc';
            trTotal.innerHTML = 
                '<td colspan="3" style="text-align: right; font-weight: 800;">Estimated Total Amount:</td>' +
                '<td class="text-right" style="font-weight: 800; color: #002F6C; font-size: 14px;">₱' + subtotal.toFixed(2) + '</td>';
            tbody.appendChild(trTotal);
        } else {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #64748b; padding: 12px;">No items found for this Purchase Request.</td></tr>';
        }
    } else {
        var totalAmount = 0;
        items.forEach(function(item) {
            var subtotal = parseFloat(item.total_price || (item.quantity * item.unit_price) || 0);
            totalAmount += subtotal;
            
            var tr = document.createElement('tr');
            tr.innerHTML = 
                '<td><strong>' + escHtml(item.item_name) + '</strong></td>' +
                '<td class="text-right">' + item.quantity + '</td>' +
                '<td class="text-right">₱' + parseFloat(item.unit_price).toFixed(2) + '</td>' +
                '<td class="text-right" style="font-weight: 700;">₱' + subtotal.toFixed(2) + '</td>';
            tbody.appendChild(tr);
        });
        
        // Append Total Row
        var trTotal = document.createElement('tr');
        trTotal.style.background = '#f8fafc';
        trTotal.innerHTML = 
            '<td colspan="3" style="text-align: right; font-weight: 800;">Estimated Total Amount:</td>' +
            '<td class="text-right" style="font-weight: 800; color: #002F6C; font-size: 14px;">₱' + totalAmount.toFixed(2) + '</td>';
        tbody.appendChild(trTotal);
    }
    
    openModal('detailsModal');
}

// Fuel Modal & Request Changed Handler
function openGenerateFuelModal() {
    document.getElementById('fuelRequestSelector').value = '';
    onFuelRequestChanged();
    openModal('generateFuelModal');
}

function onFuelRequestChanged() {
    var select = document.getElementById('fuelRequestSelector');
    var selectedOption = select.options[select.selectedIndex];
    
    // Clear all fuel inputs first
    var inputs = document.querySelectorAll('[id^="fuel_qty_input_"]');
    inputs.forEach(function(input) {
        input.value = '';
    });
    
    if (selectedOption && selectedOption.value !== '') {
        var fuelType = selectedOption.getAttribute('data-fuel');
        var qty = selectedOption.getAttribute('data-qty');
        
        var inputElement = document.getElementById('fuel_qty_input_' + fuelType);
        if (inputElement) {
            inputElement.value = qty;
        }
    }
}

function validateFuelPrForm() {
    var inputs = document.querySelectorAll('[id^="fuel_qty_input_"]');
    var valid = false;
    inputs.forEach(function(input) {
        if (parseInt(input.value) > 0) {
            valid = true;
        }
    });
    
    if (!valid) {
        alert('Please specify a quantity greater than 0 for at least one fuel type.');
        return false;
    }
    return true;
}

function viewFuelPrDetails(batchId) {
    var pr = fuelPrsList.find(function(x) { return x.batch_id == batchId; });
    if (!pr) return;
    
    document.getElementById('detFuelPrNo').textContent = pr.po_number;
    document.getElementById('detFuelPrNoField').value = pr.po_number;
    
    var fsr_no = 'Manual PR';
    var match = /\[FSR:(\d+)\]/.exec(pr.notes || '');
    if (match) {
        fsr_no = 'FSR-' + String(match[1]).padStart(5, '0');
    }
    document.getElementById('detFuelLinkedSrField').value = fsr_no;
    
    var requestedBy = pr.prepared_by_name ? pr.prepared_by_name : 'Manual (Manager)';
    document.getElementById('detFuelRequestedByField').value = requestedBy;
    
    document.getElementById('detFuelDateField').value = fmtDate(pr.created_at);
    document.getElementById('detFuelExpectedDeliveryField').value = pr.expected_delivery_date ? fmtDate(pr.expected_delivery_date, true) : '—';
    document.getElementById('detFuelRemarksField').value = pr.notes || 'No remarks specified.';
    
    var badge = document.getElementById('detFuelStatusBadge');
    badge.textContent = pr.status;
    badge.className = 'status-badge';
    if (['Pending', 'Pending Admin Validation', 'Pending Approval', 'Draft'].indexOf(pr.status) !== -1) {
        badge.classList.add('status-pending');
    } else if (['Approved', 'Approved PO', 'Confirmed', 'Official', 'Admin Finalized'].indexOf(pr.status) !== -1) {
        badge.classList.add('status-approved');
    } else if (pr.status === 'Received') {
        badge.classList.add('status-received');
    } else {
        badge.classList.add('status-cancelled');
    }
    
    var tbody = document.getElementById('detFuelItemsBody');
    tbody.innerHTML = '';
    var items = fuelPrItemsGrouped[batchId] || [];
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #64748b; padding: 12px;">No items found.</td></tr>';
    } else {
        var totalAmount = 0;
        items.forEach(function(item) {
            var subtotal = parseFloat(item.total_amount || 0);
            totalAmount += subtotal;
            
            var tr = document.createElement('tr');
            tr.innerHTML = 
                '<td><strong>' + escHtml(item.fuel_type_name) + '</strong></td>' +
                '<td class="text-right">' + numberFormat(item.volume) + ' L</td>' +
                '<td class="text-right">₱' + parseFloat(item.price_per_liter || 0).toFixed(2) + '</td>' +
                '<td class="text-right" style="font-weight: 700;">₱' + subtotal.toFixed(2) + '</td>';
            tbody.appendChild(tr);
        });
        
        var trTotal = document.createElement('tr');
        trTotal.style.background = '#f8fafc';
        trTotal.innerHTML = 
            '<td colspan="3" style="text-align: right; font-weight: 800;">Estimated Total Amount:</td>' +
            '<td class="text-right" style="font-weight: 800; color: #002F6C; font-size: 14px;">₱' + totalAmount.toFixed(2) + '</td>';
        tbody.appendChild(trTotal);
    }
    
    openModal('fuelDetailsModal');
}

function numberFormat(val) {
    return parseFloat(val).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

// ── Export Functions ──
function downloadCSV(csv, filename) {
    var csvFile = new Blob([csv], {type: "text/csv;charset=utf-8;"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function tableToCSV(tableId, filename) {
    var csv = [];
    var table = document.getElementById(tableId);
    if (!table) return;
    var rows = table.querySelectorAll("tr");
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].style.display === "none") continue;
        var row = [], cols = rows[i].querySelectorAll("td, th");
        if (cols.length === 0) continue;
        for (var j = 0; j < cols.length - 1; j++) { // exclude actions
            var text = cols[j].innerText.trim().replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        csv.push(row.join(","));
    }
    downloadCSV(csv.join("\n"), filename);
}

function exportToCSV() {
    tableToCSV("prTable", "Merchandise_Purchase_Requests.csv");
}
function exportFuelToCSV() {
    tableToCSV("fuelPrTable", "Fuel_Purchase_Requests.csv");
}

function exportToExcel() {
    tableToCSV("prTable", "Merchandise_Purchase_Requests.csv");
}
function exportFuelToExcel() {
    tableToCSV("fuelPrTable", "Fuel_Purchase_Requests.csv");
}

function printTable(tableId, title) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var win = window.open('', '', 'height=700,width=900');
    win.document.write('<html><head><title>' + title + '</title>');
    win.document.write('<style>body{font-family:sans-serif;padding:20px;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;font-size:12px;}th{background:#f1f5f9;}</style>');
    win.document.write('</head><body>');
    win.document.write('<h2>' + title + '</h2>');
    
    var clone = table.cloneNode(true);
    var rows = clone.querySelectorAll("tr");
    rows.forEach(function(row) {
        if (row.style.display === "none") return;
        var cells = row.querySelectorAll("th, td");
        if (cells.length > 0) {
            cells[cells.length - 1].remove(); // Remove actions
        }
    });
    
    win.document.write(clone.outerHTML);
    win.document.write('</body></html>');
    win.document.close();
    win.print();
}

function exportToPDF() {
    printTable("prTable", "Merchandise Purchase Requests Report");
}
function exportFuelToPDF() {
    printTable("fuelPrTable", "Fuel Purchase Requests Report");
}

// Helpers
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function fmtDate(ds, dateOnly) {
    if (!ds) return '—';
    var d = new Date(ds);
    if (isNaN(d.getTime())) return ds;
    
    var options = { month: 'short', day: 'numeric', year: 'numeric' };
    if (!dateOnly) {
        options.hour = '2-digit';
        options.minute = '2-digit';
    }
    return d.toLocaleString('en-US', options);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
