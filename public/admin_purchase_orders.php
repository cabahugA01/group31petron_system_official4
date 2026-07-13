<?php
$page_id = 'admin_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// Fetch station details for delivery location auto-fill
$station_q = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
$station_q->execute([$station_id]);
$station_data = $station_q->fetch(PDO::FETCH_ASSOC);
$station_name    = $station_data['name'] ?? 'Petron Carmen';
$_raw_addr       = trim($station_data['address'] ?? '');
$_raw_loc        = trim($station_data['location'] ?? '');
if (empty($_raw_addr) && !empty($_raw_loc) && $_raw_loc !== 'CDO') {
    $_raw_addr = $_raw_loc;
} elseif (empty($_raw_addr)) {
    $_raw_addr = 'Vamenta Blvd., Carmen, City of CDO';
}
$station_address = $_raw_addr;

// ── AJAX Handler for Details & Finalization List ──────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_pending_items') {
    header('Content-Type: application/json');
    $po_type = $_GET['type'] ?? 'merch';
    $date    = $_GET['date'] ?? '';
    
    $items = [];
    if ($po_type === 'fuel') {
        $stmt = $pdo->prepare("
            SELECT fpo.id,
                   COALESCE(ft.name,'Fuel') AS product_name,
                   fpo.volume    AS quantity,
                   fpo.unit_price,
                   fpo.total_amount
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            WHERE fpo.station_id = ?
              AND DATE(fpo.created_at) = ?
              AND fpo.status IN ('Pending Admin Validation','Pending')
            ORDER BY fpo.id ASC
        ");
        $stmt->execute([$station_id, $date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount
            FROM purchase_orders po
            WHERE po.station_id = ?
              AND DATE(po.created_at) = ?
              AND po.type = 'merch'
              AND po.status = 'Pending Admin Validation'
              AND po.admin_finalized = 0
            ORDER BY po.id ASC
        ");
        $stmt->execute([$station_id, $date]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// AJAX for Generated PO items
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_generated_items') {
    header('Content-Type: application/json');
    $po_type  = $_GET['type'] ?? 'merch';
    $batch_id = $_GET['batch_id'] ?? '';
    
    $items = [];
    if ($po_type === 'fuel') {
        $stmt = $pdo->prepare("
            SELECT fpo.id, ft.name AS product_name, fpo.volume AS quantity, fpo.unit_price, fpo.total_amount
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            WHERE fpo.batch_id = ? AND fpo.station_id = ?
            ORDER BY fpo.id ASC
        ");
        $stmt->execute([$batch_id, $station_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT po.id, po.product_name, po.quantity, po.unit_price, po.total_amount
            FROM purchase_orders po
            WHERE po.batch_id = ? AND po.station_id = ?
            ORDER BY po.id ASC
        ");
        $stmt->execute([$batch_id, $station_id]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// AJAX for Delivery details
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_delivery_details') {
    header('Content-Type: application/json');
    $batch_id = $_GET['batch_id'] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT id, product AS product_name, quantity, unit_price, (quantity * unit_price) AS total_amount, remarks, delivery_date
        FROM deliveries_oversight
        WHERE batch_id = ? AND station_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$batch_id, $station_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// Handle POST actions (Finalize, Reject)
$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $po_type = $_POST['po_type'] ?? '';
    $po_date = $_POST['po_date'] ?? '';

    if ($action === 'finalize_batch') {
        $pr_number             = trim($_POST['pr_number'] ?? '');
        $submit_action         = trim($_POST['submit_action'] ?? 'finalize_po');
        $exp_date              = trim($_POST['expected_delivery_date'] ?? '');
        $exp_time              = trim($_POST['expected_delivery_time'] ?? '');
        $receiving_personnel   = trim($_POST['receiving_personnel'] ?? 'Any Assigned Staff');
        $delivery_instructions = trim($_POST['delivery_instructions'] ?? '');
        $payment_terms         = trim($_POST['payment_terms'] ?? '30 Days');
        $remarks               = trim($_POST['remarks'] ?? '');
        $items_input           = $_POST['items'] ?? []; // Array of [id => [qty, price]]

        if (empty($pr_number)) {
            $_SESSION['err'] = 'PR Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        $db_exp_date = !empty($exp_date) ? $exp_date : date('Y-m-d', strtotime('+3 days'));
        $time_str    = !empty($exp_time) ? date("g:i A", strtotime($exp_time)) : '9:00 AM';

        $structured_notes = "Expected Time: " . $time_str . "\n"
                          . "Receiving Personnel: " . $receiving_personnel . "\n"
                          . "Payment Terms: " . $payment_terms . "\n"
                          . "Instructions: " . $delivery_instructions . "\n"
                          . "Remarks: " . $remarks;

        try {
            $pdo->beginTransaction();

            $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
            if (!$sup_id) {
                $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                $sup_id = $pdo->lastInsertId();
            }

            // Generate next PO Number sequentially (PO-YYYY-XXXX)
            $year = date('Y');
            $prefix = 'PO';
            $pattern = $prefix . '-' . $year . '-%';

            $stmt1 = $pdo->prepare("SELECT batch_id FROM purchase_orders WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1");
            $stmt1->execute([$pattern]);
            $last1 = $stmt1->fetchColumn();

            $stmt2 = $pdo->prepare("SELECT po_number FROM fuel_purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1");
            $stmt2->execute([$pattern]);
            $last2 = $stmt2->fetchColumn();

            $num1 = 0;
            if ($last1) {
                $parts = explode('-', $last1);
                $num1 = (int)end($parts);
            }
            $num2 = 0;
            if ($last2) {
                $parts = explode('-', $last2);
                $num2 = (int)end($parts);
            }

            $next_num = max($num1, $num2) + 1;
            $po_number = $prefix . '-' . $year . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

            $inserted_items = 0;

            if ($po_type === 'merch') {
                foreach ($items_input as $req_id => $data) {
                    $qty = (float)($data['qty'] ?? 0);
                    $price = (float)($data['price'] ?? 0);
                    $total = round($qty * $price, 2);

                    if ($qty <= 0) {
                        throw new Exception("Quantity must be greater than zero.");
                    }

                    // Fetch the original request
                    $stmt_req = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
                    $stmt_req->execute([(int)$req_id, $station_id]);
                    $req_rec = $stmt_req->fetch(PDO::FETCH_ASSOC);
                    if (!$req_rec) {
                        throw new Exception("Stock Request ID #{$req_id} not found.");
                    }

                    // Insert into purchase_orders
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO purchase_orders (
                            request_id, product_name, quantity, unit_price, total_amount, type, po_number, batch_id,
                            station_id, supplier_id, created_by, status, expected_delivery_date, remarks, admin_finalized,
                            admin_finalized_at, admin_id, approved_by, approved_at, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, 'merch', ?, ?,
                            ?, ?, ?, 'Admin Finalized', ?, ?, 1,
                            NOW(), ?, ?, NOW(), NOW(), NOW()
                        )
                    ");
                    $stmt_ins->execute([
                        $req_rec['id'], $req_rec['item_name'], $qty, $price, $total, $po_number, $po_number,
                        $station_id, $sup_id, $me['id'], $db_exp_date, $structured_notes, $me['id'], $me['id']
                    ]);

                    // Insert into deliveries_oversight
                    $delivery_ref_prefix = 'MDR-' . date('Ymd') . '-';
                    $stmt_max = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max->execute([$delivery_ref_prefix . '%']);
                    $max_num = (int)$stmt_max->fetchColumn();
                    $delivery_ref = $delivery_ref_prefix . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                            created_at, updated_at
                        ) VALUES (
                            'merchandise', ?, ?, 'Petron Corporation', ?, ?, ?,
                            ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        $delivery_ref, $po_number, $req_rec['item_name'], $qty, ($req_rec['item_sku'] ?: 'pcs'),
                        $db_exp_date, $station_id, $po_number, $structured_notes, $price, $qty
                    ]);

                    // Update original request
                    $pdo->prepare("
                        UPDATE stock_requests
                        SET status = 'Purchase Order Generated', approved_quantity = ?, approved_price = ?, updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ")->execute([$qty, $price, $req_rec['id'], $station_id]);

                    // Audit Log
                    $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'PO Generated', ?, ?, 'Waiting for Purchase Order', 'Purchase Order Generated', ?)
                    ")->execute([
                        $req_rec['id'], $me['id'], $role, "PO Generated: $po_number"
                    ]);

                    $inserted_items++;
                }
            } else if ($po_type === 'fuel') {
                foreach ($items_input as $req_id => $data) {
                    $qty = (float)($data['qty'] ?? 0);
                    $price = (float)($data['price'] ?? 0);
                    $total = round($qty * $price, 2);

                    if ($qty <= 0) {
                        throw new Exception("Liters must be greater than zero.");
                    }

                    // Fetch the original request
                    $stmt_req = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                    $stmt_req->execute([(int)$req_id, $station_id]);
                    $req_rec = $stmt_req->fetch(PDO::FETCH_ASSOC);
                    if (!$req_rec) {
                        throw new Exception("Fuel Request ID #{$req_id} not found.");
                    }

                    // Look up fuel_type_id
                    $fuel_type_id = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE LOWER(fuel_type) = LOWER(?) AND station_id = ? LIMIT 1");
                    $fuel_type_id->execute([$req_rec['fuel_type'], $station_id]);
                    $f_type_id = $fuel_type_id->fetchColumn() ?: 1;

                    // Insert into fuel_purchase_orders
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO fuel_purchase_orders (
                            po_number, batch_id, station_id, fuel_type_id, volume, unit_price, total_amount, supplier_id,
                            expected_delivery_date, status, created_by, approved_by, approved_at, notes, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, 'Approved PO', ?, ?, NOW(), ?, NOW(), NOW()
                        )
                    ");
                    $stmt_ins->execute([
                        $po_number, $po_number, $station_id, $f_type_id, $qty, $price, $total, $sup_id,
                        $db_exp_date, $me['id'], $me['id'], $structured_notes
                    ]);

                    // Insert into deliveries_oversight
                    $fuel_delivery_ref_prefix = 'FDR-' . date('Ymd') . '-';
                    $stmt_max_fuel = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max_fuel->execute([$fuel_delivery_ref_prefix . '%']);
                    $max_num_fuel = (int)$stmt_max_fuel->fetchColumn();
                    $fuel_delivery_ref = $fuel_delivery_ref_prefix . str_pad($max_num_fuel + 1, 4, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                            created_at, updated_at
                        ) VALUES (
                            'fuel', ?, ?, 'Petron Corporation', ?, 'L',
                            ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        $fuel_delivery_ref, $po_number, $req_rec['fuel_type'], $qty,
                        $db_exp_date, $station_id, $po_number, $structured_notes, $price, $qty
                    ]);

                    // Update original request
                    $pdo->prepare("
                        UPDATE fuel_stock_requests
                        SET status = 'Purchase Order Generated', approved_liters = ?, updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ")->execute([$qty, $req_rec['id'], $station_id]);

                    // Audit Log
                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'PO Generated', ?, ?, 'Waiting for Purchase Order', 'Purchase Order Generated', ?)
                    ")->execute([
                        $req_rec['id'], $me['id'], $role, "PO Generated: $po_number"
                    ]);

                    $inserted_items++;
                }
            }

            if ($inserted_items === 0) {
                throw new Exception("No items processed.");
            }

            log_activity($pdo, $me['id'], 'Admin Generate PO', "PO {$po_number} generated from PR {$pr_number}.");
            $pdo->commit();

            $_SESSION['ok'] = "Purchase Order {$po_number} has been generated successfully.";
            if ($submit_action === 'print_po') {
                header("Location: print_po_new.php?batch_id=" . urlencode($po_number) . "&type=" . urlencode($po_type) . "&print=1");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }

    if ($action === 'reject_batch') {
        $reason     = trim($_POST['reject_reason'] ?? '');
        $pr_number  = trim($_POST['pr_number'] ?? '');

        if (empty($reason)) {
            $_SESSION['err'] = 'Rejection reason is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }
        if (empty($pr_number)) {
            $_SESSION['err'] = 'PR Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            if ($po_type === 'merch') {
                // Get all matching stock_requests to log audit
                $stmt_get = $pdo->prepare("SELECT id FROM stock_requests WHERE request_no = ? AND station_id = ?");
                $stmt_get->execute([$pr_number, $station_id]);
                $ids = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE stock_requests SET status='Rejected by Admin', manager_notes=? WHERE request_no=? AND station_id=?")
                    ->execute(["Rejected: " . $reason, $pr_number, $station_id]);

                foreach ($ids as $req_id) {
                    $pdo->prepare("
                        INSERT INTO stock_request_audit
                            (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Rejected by Admin', ?, ?, 'Waiting for Purchase Order', 'Rejected by Admin', ?)
                    ")->execute([
                        $req_id, $me['id'], $role, $reason
                    ]);
                }
            } else if ($po_type === 'fuel') {
                $stmt_get = $pdo->prepare("SELECT id FROM fuel_stock_requests WHERE request_no = ? AND station_id = ?");
                $stmt_get->execute([$pr_number, $station_id]);
                $ids = $stmt_get->fetchAll(PDO::FETCH_COLUMN);

                $pdo->prepare("UPDATE fuel_stock_requests SET status='Rejected by Admin', remarks=? WHERE request_no=? AND station_id=?")
                    ->execute(["Rejected: " . $reason, $pr_number, $station_id]);

                foreach ($ids as $req_id) {
                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Rejected by Admin', ?, ?, 'Waiting for Purchase Order', 'Rejected by Admin', ?)
                    ")->execute([
                        $req_id, $me['id'], $role, $reason
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Admin Reject PR', "PR {$pr_number} rejected: {$reason}");
            $pdo->commit();
            $_SESSION['ok'] = "Request {$pr_number} has been rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }
}

// ── DATA EXTRACTION & TAB-WISE BATCHING ──────────────────────────────────────

// Tab 1: Pending Purchase Requests — from stock_requests with 'Waiting for Purchase Order'
$pending_requests = [];

// Merchandise Pendings (from stock_requests)
try {
    $stmt = $pdo->prepare("
        SELECT sr.id, sr.request_no, sr.item_name AS product_name, sr.approved_quantity AS quantity,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
               COALESCE(ip.cost_price, 0) AS cost_price,
               sr.manager_notes AS remarks, sr.processed_at AS created_at, sr.updated_at,
               u.name AS requested_by, m.name AS manager_name
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        WHERE sr.station_id = ? AND sr.status = 'Waiting for Purchase Order'
          AND LOWER(COALESCE(sr.item_category,'')) != 'fuel'
        ORDER BY sr.request_no ASC, sr.updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $merch_wfpo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merch_pr_groups = [];
    foreach ($merch_wfpo as $r) {
        $prKey = $r['request_no'] ?: ('REQ-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
        if (!isset($merch_pr_groups[$prKey])) {
            $merch_pr_groups[$prKey] = [
                'pr_no'        => $prKey,
                'type'         => 'Merchandise',
                'po_type'      => 'merch',
                'requested_by' => $r['requested_by'] ?: 'Staff',
                'manager_name' => $r['manager_name'] ?: 'Manager',
                'supplier'     => 'Petron Corporation',
                'date'         => substr($r['updated_at'], 0, 10),
                'total_items'  => 0,
                'status'       => 'Waiting for Purchase Order',
                'items'        => [],
            ];
        }
        $merch_pr_groups[$prKey]['items'][] = $r;
        $merch_pr_groups[$prKey]['total_items']++;
    }
    foreach ($merch_pr_groups as $pr) {
        $pending_requests[] = $pr;
    }
} catch (Exception $e) {}

// Fuel Pendings (from fuel_stock_requests)
try {
    $stmt = $pdo->prepare("
        SELECT fsr.id, fsr.request_no, fsr.fuel_type AS product_name, fsr.approved_liters AS quantity,
               'L' AS unit, fsr.current_level AS current_stock,
               COALESCE(fi.reorder_level, 5000) AS reorder_level,
               COALESCE((SELECT fp.price_per_liter FROM fuel_pricing fp WHERE fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fsr.station_id AND fp.is_active = 1 ORDER BY fp.effective_date DESC LIMIT 1), 0) AS cost_price,
               fsr.processed_at AS created_at, fsr.updated_at,
               u.name AS requested_by, m.name AS manager_name,
               fi.fuel_type_id
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        LEFT JOIN fuel_inventory fi ON LOWER(fsr.fuel_type) = LOWER(fi.fuel_type) AND fi.station_id = fsr.station_id
        WHERE fsr.station_id = ? AND fsr.status = 'Waiting for Purchase Order'
        ORDER BY fsr.request_no ASC, fsr.updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $fuel_wfpo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fuel_pr_groups = [];
    foreach ($fuel_wfpo as $r) {
        $prKey = $r['request_no'] ?: ('FSR-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT));
        if (!isset($fuel_pr_groups[$prKey])) {
            $fuel_pr_groups[$prKey] = [
                'pr_no'        => $prKey,
                'type'         => 'Fuel',
                'po_type'      => 'fuel',
                'requested_by' => $r['requested_by'] ?: 'Staff',
                'manager_name' => $r['manager_name'] ?: 'Manager',
                'supplier'     => 'Petron Corporation',
                'date'         => substr($r['updated_at'], 0, 10),
                'total_items'  => 0,
                'status'       => 'Waiting for Purchase Order',
                'items'        => [],
            ];
        }
        $fuel_pr_groups[$prKey]['items'][] = $r;
        $fuel_pr_groups[$prKey]['total_items']++;
    }
    foreach ($fuel_pr_groups as $pr) {
        $pending_requests[] = $pr;
    }
} catch (Exception $e) {}

// Sort pending requests by date desc
usort($pending_requests, fn($a, $b) => strcmp($b['date'], $a['date']));


// Tab 2: Generated Purchase Orders (Batches created, waiting for delivery update or verified POs)
$generated_pos = [];

// Merchandise Generated POs
try {
    $stmt = $pdo->prepare("
        SELECT po.batch_id, DATE(po.admin_finalized_at) AS date_only, po.admin_finalized_at, SUM(po.total_amount) AS total,
               COALESCE(u.username, 'Admin') AS generated_by, po.status
        FROM purchase_orders po
        LEFT JOIN users u ON po.admin_id = u.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.admin_finalized = 1 AND po.status NOT IN ('Delivered', 'Received', 'Completed')
        GROUP BY po.batch_id
        ORDER BY po.admin_finalized_at DESC
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (empty($r['batch_id'])) continue;
        $generated_pos[] = [
            'po_no' => $r['batch_id'],
            'po_type' => 'merch',
            'type' => 'Merchandise',
            'supplier' => 'Petron Corporation',
            'date' => $r['date_only'],
            'generated_by' => $r['generated_by'],
            'status' => $r['status']
        ];
    }
} catch (Exception $e) {}

// Fuel Generated POs
try {
    $stmt = $pdo->prepare("
        SELECT fpo.batch_id, DATE(fpo.approved_at) AS date_only, fpo.approved_at, SUM(fpo.total_amount) AS total,
               COALESCE(u.username, 'Admin') AS generated_by, fpo.status
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.approved_by = u.id
        WHERE fpo.station_id = ? AND fpo.status NOT IN ('Pending Admin Validation', 'Pending', 'Delivered', 'Received', 'Completed')
        GROUP BY fpo.batch_id
        ORDER BY fpo.approved_at DESC
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (empty($r['batch_id'])) continue;
        $generated_pos[] = [
            'po_no' => $r['batch_id'],
            'po_type' => 'fuel',
            'type' => 'Fuel',
            'supplier' => 'Petron Corporation',
            'date' => $r['date_only'],
            'generated_by' => $r['generated_by'],
            'status' => $r['status']
        ];
    }
} catch (Exception $e) {}
usort($generated_pos, fn($a, $b) => strcmp($b['date'], $a['date']));

// Tab 3: Waiting Deliveries (from deliveries_oversight where status = 'Expected Delivery')
$waiting_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT batch_id AS po_no, supplier, delivery_date, status, delivery_type
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Expected Delivery', 'Pending Delivery', 'In Transit')
        GROUP BY batch_id
        ORDER BY delivery_date ASC
    ");
    $stmt->execute([$station_id]);
    $waiting_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Tab 4: Completed Purchase Orders (from deliveries_oversight where status = 'Delivered' or 'Received')
$completed_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT batch_id AS po_no, supplier, delivery_date, updated_at AS stock_in_date, status, delivery_type
        FROM deliveries_oversight
        WHERE station_id = ? AND status IN ('Delivered', 'Received', 'Completed')
        GROUP BY batch_id
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$station_id]);
    $completed_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Summary card numbers
$cnt_pending = count($pending_requests);
$cnt_generated = count($generated_pos);
$cnt_waiting = count($waiting_deliveries);
$cnt_completed = count($completed_pos);

$active_tab = $_GET['tab'] ?? 'pending';

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Page header */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* Cards grid */
.po-sum-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px; }
.po-sum-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between; }
.po-sum-label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px; }
.po-sum-val { font-size:24px; font-weight:800; margin-top:4px; }
.po-sum-icon { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; }

/* Tabs navigation */
.po-tabs-nav { display:flex; border-bottom:1px solid #cbd5e1; margin-bottom:20px; gap:8px; }
.po-tab-btn { border:none; background:#fff; padding:10px 16px; font-weight:700; font-size:13px; text-transform:uppercase; cursor:pointer; color:#64748b; border:1px solid #e2e8f0; border-bottom:2px solid transparent; display:flex; align-items:center; gap:6px; transition:all 0.15s; border-radius:6px 6px 0 0; }
.po-tab-btn:hover { color:#002F6C; background:#f8fafc; }
.po-tab-btn.active { color:#ffffff; background:#002F6C; border-color:#002F6C; }
.po-tab-badge { background:#cbd5e1; color:#1e293b; font-size:10px; font-weight:700; padding:2px 6px; border-radius:10px; }
.po-tab-btn.active .po-tab-badge { background:#ffffff; color:#002F6C; }

/* Table styling */
.po-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px; }
.po-table { width:100%; border-collapse:collapse; font-size:12px; }
.po-table thead tr { background:#002F70; }
.po-table thead th { padding:10px 12px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; border:none; }
.po-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.po-table tbody tr:hover { background:#f8fafc; }
.po-table tbody td { padding:10px 12px; color:#1e293b; vertical-align:middle; }

/* Modal overlay & centering */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.6); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:700px; max-width:100%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); border:1px solid #e2e8f0; overflow:hidden; display:flex; flex-direction:column; max-height:90vh; }
.modal-header { padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#00264D; display:flex; align-items:center; justify-content:space-between; }
.modal-header h3 { margin:0; font-size:15px; font-weight:700; color:#fff !important; text-transform:uppercase; }
.modal-body { padding:20px; overflow-y:auto; flex:1; }
.modal-footer { padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; background:#f8fafc; }

.po-form-grp { margin-bottom:12px; text-align:left; }
.po-form-grp label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:4px; }
.po-form-grp input, .po-form-grp select, .po-form-grp textarea { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; color:#1e293b; background:#fff; outline:none; box-sizing:border-box; }

.po-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; }
.po-badge-pending { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
.po-badge-approved { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
.po-badge-delivered { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }

.btn-cancel { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid #6b7280; background:white !important; color:#475569 !important; height:32px; transition:all .15s; }
.btn-cancel:hover { background:#6b7280 !important; color:#fff !important; }
.btn-save { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid #16a34a; background:white !important; color:#16a34a !important; height:32px; transition:all .15s; }
.btn-save:hover { background:#16a34a !important; color:#fff !important; }
.btn-rej { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 16px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid #dc3545; background:white !important; color:#dc3545 !important; height:32px; transition:all .15s; }
.btn-rej:hover { background:#dc3545 !important; color:#fff !important; }
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-file-invoice"></i> Purchase Order Management</h1>
    <div class="sub">Monitor and finalize all procurement operations &middot; Today: <?= date('F d, Y') ?></div>
  </div>
</div>

<!-- Summary Cards -->
<div class="po-sum-grid">
  <div class="po-sum-card">
    <div>
      <div class="po-sum-label">Pending PR Requests</div>
      <div class="po-sum-val" style="color:#fd7e14;"><?= $cnt_pending ?></div>
    </div>
    <div class="po-sum-icon" style="background:#fff3cd; color:#fd7e14;"><i class="fas fa-hourglass-half"></i></div>
  </div>
  <div class="po-sum-card">
    <div>
      <div class="po-sum-label">Generated Purchase Order</div>
      <div class="po-sum-val" style="color:#002F6C;"><?= $cnt_generated ?></div>
    </div>
    <div class="po-sum-icon" style="background:#e8f0fb; color:#002F6C;"><i class="fas fa-file-signature"></i></div>
  </div>
  <div class="po-sum-card">
    <div>
      <div class="po-sum-label">Waiting Deliveries</div>
      <div class="po-sum-val" style="color:#17a2b8;"><?= $cnt_waiting ?></div>
    </div>
    <div class="po-sum-icon" style="background:#e0f2fe; color:#17a2b8;"><i class="fas fa-shipping-fast"></i></div>
  </div>
  <div class="po-sum-card">
    <div>
      <div class="po-sum-label">Completed Orders</div>
      <div class="po-sum-val" style="color:#16a34a;"><?= $cnt_completed ?></div>
    </div>
    <div class="po-sum-icon" style="background:#dcfce7; color:#16a34a;"><i class="fas fa-check-circle"></i></div>
  </div>
</div>

<!-- Tab Navigation -->
<div class="po-tabs-nav">
  <button class="po-tab-btn <?= $active_tab === 'pending' ? 'active' : '' ?>" onclick="switchTab('pending')">
    <i class="fas fa-hourglass-half"></i> Pending Requests <span class="po-tab-badge"><?= $cnt_pending ?></span>
  </button>
  <button class="po-tab-btn <?= $active_tab === 'generated' ? 'active' : '' ?>" onclick="switchTab('generated')">
    <i class="fas fa-file-invoice"></i> Generated Purchase Order <span class="po-tab-badge"><?= $cnt_generated ?></span>
  </button>
  <button class="po-tab-btn <?= $active_tab === 'waiting' ? 'active' : '' ?>" onclick="switchTab('waiting')">
    <i class="fas fa-shipping-fast"></i> Waiting Deliveries <span class="po-tab-badge"><?= $cnt_waiting ?></span>
  </button>
  <button class="po-tab-btn <?= $active_tab === 'completed' ? 'active' : '' ?>" onclick="switchTab('completed')">
    <i class="fas fa-check-circle"></i> Completed POs <span class="po-tab-badge"><?= $cnt_completed ?></span>
  </button>
</div>

<!-- Tab 1: Pending Purchase Requests -->
<div id="pane-pending" style="display: <?= $active_tab === 'pending' ? 'block' : 'none' ?>;">
  <div class="po-table-wrap">
    <table class="po-table" id="pendingTable">
      <thead>
        <tr>
          <th style="width:18%;">PR Number</th>
          <th style="width:10%;">Type</th>
          <th style="width:14%;">Requested By</th>
          <th style="width:14%;">Approved By</th>
          <th style="width:12%;">Date</th>
          <th style="width:8%; text-align:center;">Items</th>
          <th style="width:12%;">Status</th>
          <th style="width:12%; text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pending_requests)): ?>
          <tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-folder-open" style="font-size:20px; display:block; margin-bottom:8px;"></i> No pending purchase requests.</td></tr>
        <?php else: ?>
          <?php foreach ($pending_requests as $pr): ?>
            <tr>
              <td><code style="color:#002F6C; font-weight:700;"><?= htmlspecialchars($pr['pr_no']) ?></code></td>
              <td style="font-weight:700; color:<?= $pr['po_type'] === 'fuel' ? '#0284c7' : '#002F6C' ?>;"><?= htmlspecialchars($pr['type']) ?></td>
              <td><?= htmlspecialchars($pr['requested_by']) ?></td>
              <td><?= htmlspecialchars($pr['manager_name']) ?></td>
              <td><?= date('M d, Y', strtotime($pr['date'])) ?></td>
              <td style="text-align:center; font-weight:700;"><?= $pr['total_items'] ?></td>
              <td><span class="po-badge po-badge-pending">Waiting for PO</span></td>
              <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">
                  <button class="btn-save" style="height:28px; font-size:11px; min-width:120px;" onclick="openAdminFinalizeModal(<?= htmlspecialchars(json_encode($pr)) ?>)"><i class="fas fa-file-signature"></i> Generate PO</button>
                  <button class="btn-rej" style="height:28px; font-size:11px; min-width:120px;" onclick="openRejectPrModal('<?= $pr['po_type'] ?>', '<?= htmlspecialchars($pr['pr_no']) ?>')"><i class="fas fa-times"></i> Reject</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- Tab 2: Generated Purchase Orders -->
<div id="pane-generated" style="display: <?= $active_tab === 'generated' ? 'block' : 'none' ?>;">
  <div class="po-table-wrap">
    <table class="po-table" id="generatedTable">
      <thead>
        <tr>
          <th>PO No.</th>
          <th>Supplier</th>
          <th>Type</th>
          <th>Date</th>
          <th>Generated By</th>
          <th>Status</th>
          <th style="width:250px; text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($generated_pos)): ?>
          <tr><td colspan="7" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-folder-open" style="font-size:20px; display:block; margin-bottom:8px;"></i> No generated purchase orders.</td></tr>
        <?php else: ?>
          <?php foreach ($generated_pos as $po): 
              $print_url = "print_po_new.php?batch_id=" . urlencode($po['po_no']) . "&type=" . urlencode($po['po_type']) . "&print=1";
          ?>
            <tr>
              <td><code><?= htmlspecialchars($po['po_no']) ?></code></td>
              <td><?= htmlspecialchars($po['supplier']) ?></td>
              <td style="font-weight:700;"><?= htmlspecialchars($po['type']) ?></td>
              <td><?= date('M d, Y', strtotime($po['date'])) ?></td>
              <td><?= htmlspecialchars($po['generated_by']) ?></td>
              <td><span class="po-badge po-badge-approved"><?= htmlspecialchars($po['status']) ?></span></td>
              <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">
                  <button class="btn-cancel" style="height:28px; font-size:11px; min-width:100px;" onclick="viewGeneratedDetails('<?= $po['po_type'] ?>', '<?= htmlspecialchars($po['po_no']) ?>')"><i class="fas fa-eye"></i> View</button>
                  <button class="btn-save" style="height:28px; font-size:11px; min-width:100px;" onclick="openEditPOModal('<?= htmlspecialchars($po['po_no']) ?>', '<?= $po['po_type'] ?>')"><i class="fas fa-edit"></i> Edit</button>
                  <a href="<?= $print_url ?>" target="_blank" class="btn-cancel" style="height:28px; font-size:11px; text-decoration:none; line-height:26px; border-color:#0284c7; color:#0284c7 !important; min-width:100px;"><i class="fas fa-print"></i> Print</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 3: Waiting Deliveries -->
<div id="pane-waiting" style="display: <?= $active_tab === 'waiting' ? 'block' : 'none' ?>;">
  <div class="po-table-wrap">
    <table class="po-table" id="waitingTable">
      <thead>
        <tr>
          <th>PO No.</th>
          <th>Supplier</th>
          <th>Expected Delivery</th>
          <th>Status</th>
          <th style="width:100px; text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($waiting_deliveries)): ?>
          <tr><td colspan="5" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-truck-loading" style="font-size:20px; display:block; margin-bottom:8px;"></i> No waiting deliveries.</td></tr>
        <?php else: ?>
          <?php foreach ($waiting_deliveries as $wd): ?>
            <tr>
              <td><code><?= htmlspecialchars($wd['po_no']) ?></code></td>
              <td><?= htmlspecialchars($wd['supplier']) ?></td>
              <td style="font-weight:700; color:#002F70;"><?= date('M d, Y', strtotime($wd['delivery_date'])) ?></td>
              <td><span class="po-badge po-badge-pending"><?= htmlspecialchars($wd['status']) ?></span></td>
              <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">
                  <button class="btn-cancel" style="height:28px; font-size:11px; min-width:100px;" onclick="viewDeliveryDetails('<?= htmlspecialchars($wd['po_no']) ?>')"><i class="fas fa-eye"></i> View</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 4: Completed Purchase Orders -->
<div id="pane-completed" style="display: <?= $active_tab === 'completed' ? 'block' : 'none' ?>;">
  <div class="po-table-wrap">
    <table class="po-table" id="completedTable">
      <thead>
        <tr>
          <th>PO No.</th>
          <th>Supplier</th>
          <th>Delivery Date</th>
          <th>Stock-In Date</th>
          <th>Status</th>
          <th style="width:100px; text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($completed_pos)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-check-double" style="font-size:20px; display:block; margin-bottom:8px;"></i> No completed purchase orders.</td></tr>
        <?php else: ?>
          <?php foreach ($completed_pos as $cp): ?>
            <tr>
              <td><code><?= htmlspecialchars($cp['po_no']) ?></code></td>
              <td><?= htmlspecialchars($cp['supplier']) ?></td>
              <td><?= date('M d, Y', strtotime($cp['delivery_date'])) ?></td>
              <td style="font-weight:700; color:#16a34a;"><?= date('M d, Y h:i A', strtotime($cp['stock_in_date'])) ?></td>
              <td><span class="po-badge po-badge-delivered"><?= htmlspecialchars($cp['status']) ?></span></td>
              <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">
                  <button class="btn-cancel" style="height:28px; font-size:11px; min-width:100px;" onclick="viewDeliveryDetails('<?= htmlspecialchars($cp['po_no']) ?>')"><i class="fas fa-eye"></i> View</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ Modal 1: View Details Modal ══ -->
<div class="modal-overlay" id="viewModal">
  <div class="modal-box" style="width:650px;">
    <div class="modal-header">
      <h3 id="viewModalTitle">Request Details</h3>
    </div>
    <div class="modal-body">
      <div id="viewModalInfo" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px; margin-bottom:15px; font-size:13px; line-height:1.6; text-align:left;"></div>
      <table style="width:100%; border-collapse:collapse; font-size:12px;">
        <thead style="background:#f1f5f9;">
          <tr>
            <th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Product Name</th>
            <th style="padding:8px; text-align:center; border-bottom:1px solid #e2e8f0; width:100px;">Quantity</th>
            <th style="padding:8px; text-align:right; border-bottom:1px solid #e2e8f0; width:120px;">Unit Price</th>
            <th style="padding:8px; text-align:right; border-bottom:1px solid #e2e8f0; width:120px;">Total Price</th>
          </tr>
        </thead>
        <tbody id="viewModalItemsBody">
          <!-- Loaded dynamically -->
        </tbody>
      </table>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ Modal 2: Finalize Purchase Order Modal ══ -->
<div class="modal-overlay" id="finalizeModal">
  <div class="modal-box" style="width:800px;">
    <div class="modal-header">
      <h3 id="finModalTitle">Finalize Purchase Order</h3>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="finalize_batch">
      <input type="hidden" id="modalPoType" name="po_type" value="">
      <input type="hidden" id="modalPoDate" name="po_date" value="">
      <input type="hidden" id="modalPrNumber" name="pr_number" value="">
      
      <div class="modal-body" style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <!-- Left Side: Scheduling & Terms -->
        <div>
          <div class="po-form-grp">
            <label>PO Number / Batch ID (Auto-Generated)</label>
            <input type="text" id="modalBatchId" name="batch_id_override" readonly style="font-family:monospace; font-weight:700; background:#f1f5f9; cursor:not-allowed;">
          </div>
          <div class="po-form-grp">
            <label>Expected Delivery Date <span style="color:#dc2626;">*</span></label>
            <input type="date" name="expected_delivery_date" required min="<?= date('Y-m-d') ?>">
          </div>
          <div class="po-form-grp">
            <label>Expected Delivery Time <span style="color:#dc2626;">*</span></label>
            <select name="expected_delivery_time" required>
              <option value="09:00">09:00 AM</option>
              <option value="14:00">02:00 PM</option>
            </select>
          </div>
          <div class="po-form-grp">
            <label>Payment Terms</label>
            <select name="payment_terms">
              <option value="30 Days">30 Days (Net 30)</option>
              <option value="Cash">Cash</option>
              <option value="Credit">Credit</option>
              <option value="COD">COD</option>
            </select>
          </div>
        </div>

        <!-- Right Side: Logistics & Instructions -->
        <div>
          <div class="po-form-grp">
            <label>Delivery Destination (Read-Only)</label>
            <textarea readonly style="background:#e2e8f0; font-family:monospace; resize:none;" rows="3"><?= htmlspecialchars($station_name . "\n" . $station_address) ?></textarea>
          </div>
          <div class="po-form-grp">
            <label>Receiving Personnel</label>
            <input type="text" name="receiving_personnel" value="Any Assigned Staff" required>
          </div>
          <div class="po-form-grp">
            <label>Delivery Instructions</label>
            <textarea name="delivery_instructions" rows="2" placeholder="e.g. Handle with care..."></textarea>
          </div>
          <div class="po-form-grp">
            <label>Remarks / Notes</label>
            <textarea name="remarks" rows="2" placeholder="Optional notes..."></textarea>
          </div>
        </div>

        <!-- Table of Products spanning full width -->
        <div style="grid-column: span 2; max-height:200px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px;">
          <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead style="background:#f1f5f9; position:sticky; top:0;">
              <tr>
                <th style="padding:8px; text-align:left;">Product Name</th>
                <th style="padding:8px; text-align:center; width:100px;">Quantity</th>
                <th style="padding:8px; text-align:right; width:120px;">Unit Cost (₱)</th>
                <th style="padding:8px; text-align:right; width:120px;">Total (₱)</th>
              </tr>
            </thead>
            <tbody id="modalItemsBody">
              <!-- Loaded dynamically -->
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" id="submitActionInput" name="submit_action" value="finalize_po">
        <button type="button" class="btn-cancel" onclick="closeModal('finalizeModal')">Cancel</button>
        <button type="submit" class="btn-save" onclick="document.getElementById('submitActionInput').value = 'finalize_po'"><i class="fas fa-check-circle"></i> Finalize &amp; Forward</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Modal 3: Reject Modal ══ -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal-box" style="width:450px;">
    <div class="modal-header">
      <h3 style="color:#fff !important;">Reject Purchase Request</h3>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="reject_batch">
      <input type="hidden" id="rejectPoType" name="po_type" value="">
      <input type="hidden" id="rejectPoDate" name="po_date" value="">
      <input type="hidden" id="rejectPrNumber" name="pr_number" value="">
      <div class="modal-body">
        <p style="font-size:13px; color:#475569; margin-bottom:15px;">Are you sure you want to reject this purchase request?</p>
        <div class="po-form-grp">
          <label>Reason for Rejection <span style="color:#dc2626;">*</span></label>
          <textarea name="reject_reason" required rows="3" placeholder="Enter reason..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn-rej">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Modal 4: Edit PO Modal ══ -->
<div class="modal-overlay" id="editPOModal">
  <div class="modal-box" style="width:800px; max-width:95%;">
    <div class="modal-header">
      <h3 style="color:#fff !important;">Edit Purchase Order</h3>
    </div>
    <form id="editPOForm" onsubmit="submitEditPO(event)">
      <input type="hidden" name="action" value="edit_po">
      <input type="hidden" id="editPoNo" name="po_no" value="">
      <input type="hidden" id="editPoType" name="po_type" value="">
      <div class="modal-body" id="editPOModalBody" style="max-height:60vh; overflow-y:auto;">
        <p style="text-align:center; color:#64748b; padding:20px;">Loading...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('editPOModal')">Cancel</button>
        <button type="submit" class="btn-save" id="editPOSubmitBtn"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(tab) {
    // Hide all panes
    document.getElementById('pane-pending').style.display = 'none';
    document.getElementById('pane-generated').style.display = 'none';
    document.getElementById('pane-waiting').style.display = 'none';
    document.getElementById('pane-completed').style.display = 'none';
    
    // Show active pane
    document.getElementById('pane-' + tab).style.display = 'block';
    
    // Update tab button classes
    document.querySelectorAll('.po-tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    event.currentTarget.classList.add('active');
    
    // Update URL parameter without reload
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url.toString());
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function viewPendingDetails(type, date, prNo) {
    var title = document.getElementById('viewModalTitle');
    title.innerHTML = 'Pending Request Details &mdash; ' + prNo;
    
    var info = document.getElementById('viewModalInfo');
    info.innerHTML = '<strong>Request Date:</strong> ' + new Date(date).toLocaleDateString('en-US', {month: 'long', day: 'numeric', year: 'numeric'}) + '<br>' +
                     '<strong>Supplier:</strong> Petron Corporation<br>' +
                     '<strong>Type:</strong> ' + (type === 'fuel' ? 'Fuel Procurement' : 'Merchandise Inventory');
                     
    var tbody = document.getElementById('viewModalItemsBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;"><i class="fas fa-spinner fa-spin"></i> Loading items...</td></tr>';
    
    fetch('admin_purchase_orders.php?ajax=1&action=get_pending_items&type=' + type + '&date=' + date)
    .then(r => r.json())
    .then(res => {
        if (!res.success || res.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px; color:#64748b;">No items found.</td></tr>';
            return;
        }
        var html = '';
        var grandTotal = 0;
        res.items.forEach(item => {
            var qty = parseFloat(item.quantity);
            var price = parseFloat(item.unit_price);
            var total = qty * price;
            grandTotal += total;
            html += '<tr>' +
                '<td style="padding:8px;">' + item.product_name + '</td>' +
                '<td style="padding:8px; text-align:center;">' + qty.toLocaleString() + '</td>' +
                '<td style="padding:8px; text-align:right;">₱' + price.toFixed(2) + '</td>' +
                '<td style="padding:8px; text-align:right; font-weight:700;">₱' + total.toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '<tr style="background:#f8fafc; font-weight:700; border-top:2px solid #cbd5e1;">' +
            '<td colspan="3" style="padding:8px; text-align:right;">Grand Total:</td>' +
            '<td style="padding:8px; text-align:right; color:#16a34a;">₱' + grandTotal.toFixed(2) + '</td>' +
            '</tr>';
        tbody.innerHTML = html;
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#dc3545;">Error loading request.</td></tr>';
    });
    
    document.getElementById('viewModal').classList.add('open');
}

function openAdminFinalizeModal(pr) {
    document.getElementById('modalPoType').value = pr.po_type;
    document.getElementById('modalPoDate').value = pr.date;
    document.getElementById('modalPrNumber').value = pr.pr_no;
    document.getElementById('finModalTitle').innerHTML = 'Finalize Purchase Order &mdash; ' + pr.pr_no;
    
    // Auto-generate PO Number is done on server, so display read-only indicator
    document.getElementById('modalBatchId').value = '[Auto-Generated]';
    
    // Pre-set expected delivery date to 3 days from now
    var expDate = new Date();
    expDate.setDate(expDate.getDate() + 3);
    var yyyy = expDate.getFullYear();
    var mm = String(expDate.getMonth() + 1).padStart(2, '0');
    var dd = String(expDate.getDate()).padStart(2, '0');
    document.querySelector('[name="expected_delivery_date"]').value = yyyy + '-' + mm + '-' + dd;
    
    var tbody = document.getElementById('modalItemsBody');
    var html = '';
    
    pr.items.forEach(item => {
        var qty = parseFloat(item.quantity) || 0;
        var cost = parseFloat(item.cost_price) || 0;
        var total = qty * cost;
        html += '<tr>' +
            '<td style="padding:8px; font-weight:600;">' + item.product_name + '</td>' +
            '<td style="padding:8px; text-align:center;">' +
                '<input type="number" step="any" name="items['+item.id+'][qty]" value="'+qty+'" required style="width:70px; text-align:center; padding:4px;" oninput="recalcRowTotal('+item.id+')">' +
            '</td>' +
            '<td style="padding:8px; text-align:right;">' +
                '<input type="number" step="0.01" name="items['+item.id+'][price]" value="'+cost+'" required style="width:90px; text-align:right; padding:4px;" oninput="recalcRowTotal('+item.id+')">' +
            '</td>' +
            '<td style="padding:8px; text-align:right; font-weight:700;" id="rt-'+item.id+'">₱' + total.toFixed(2) + '</td>' +
            '</tr>';
    });
    tbody.innerHTML = html;
    
    document.getElementById('finalizeModal').classList.add('open');
}

function recalcRowTotal(id) {
    var q = parseFloat(document.querySelector('[name="items['+id+'][qty]"]').value) || 0;
    var p = parseFloat(document.querySelector('[name="items['+id+'][price]"]').value) || 0;
    document.getElementById('rt-'+id).textContent = '₱' + (q*p).toFixed(2);
}

function openRejectPrModal(type, prNo) {
    document.getElementById('rejectPoType').value = type;
    document.getElementById('rejectPrNumber').value = prNo;
    document.getElementById('rejectModal').classList.add('open');
}

function openEditPOModal(poNo, poType) {
    document.getElementById('editPoNo').value = poNo;
    document.getElementById('editPoType').value = poType;
    
    var modalBody = document.getElementById('editPOModalBody');
    modalBody.innerHTML = '<p style="text-align:center; color:#64748b; padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading PO details...</p>';
    
    // Fetch PO details
    fetch('admin_purchase_orders_handler.php?action=get_po_details&po_no=' + encodeURIComponent(poNo) + '&po_type=' + encodeURIComponent(poType))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                modalBody.innerHTML = '<p style="text-align:center; color:#dc2626; padding:20px;">Error: ' + (data.message || 'Failed to load PO') + '</p>';
                return;
            }
            
            var po = data.po;
            var html = '<div style="margin-bottom:20px;">';
            html += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">';
            html += '<div><label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">PO Number</label>';
            html += '<input type="text" value="' + po.po_no + '" disabled style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc;"></div>';
            html += '<div><label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Supplier</label>';
            html += '<input type="text" name="supplier" value="' + (po.supplier || '') + '" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;"></div>';
            html += '</div>';
            
            html += '<label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:8px;">Items</label>';
            html += '<table style="width:100%; border-collapse:collapse; margin-bottom:15px;">';
            html += '<thead><tr style="background:#f8fafc;">';
            html += '<th style="padding:8px; text-align:left; font-size:11px; font-weight:700; color:#475569; border:1px solid #e2e8f0;">Product</th>';
            html += '<th style="padding:8px; text-align:center; font-size:11px; font-weight:700; color:#475569; border:1px solid #e2e8f0; width:100px;">Quantity</th>';
            html += '<th style="padding:8px; text-align:right; font-size:11px; font-weight:700; color:#475569; border:1px solid #e2e8f0; width:120px;">Unit Price</th>';
            html += '<th style="padding:8px; text-align:right; font-size:11px; font-weight:700; color:#475569; border:1px solid #e2e8f0; width:120px;">Total</th>';
            html += '</tr></thead><tbody>';
            
            data.items.forEach((item, idx) => {
                var qty = parseFloat(item.quantity || 0);
                var price = parseFloat(item.unit_price || 0);
                var total = qty * price;
                html += '<tr>';
                html += '<td style="padding:8px; border:1px solid #e2e8f0;">' + item.product_name + '<input type="hidden" name="items[' + idx + '][id]" value="' + item.id + '"></td>';
                html += '<td style="padding:8px; text-align:center; border:1px solid #e2e8f0;"><input type="number" step="any" name="items[' + idx + '][qty]" value="' + qty + '" required style="width:80px; text-align:center; padding:6px; border:1px solid #cbd5e1; border-radius:4px;" oninput="recalcEditRowTotal(' + idx + ')"></td>';
                html += '<td style="padding:8px; text-align:right; border:1px solid #e2e8f0;"><input type="number" step="0.01" name="items[' + idx + '][price]" value="' + price + '" required style="width:100px; text-align:right; padding:6px; border:1px solid #cbd5e1; border-radius:4px;" oninput="recalcEditRowTotal(' + idx + ')"></td>';
                html += '<td style="padding:8px; text-align:right; border:1px solid #e2e8f0; font-weight:700;" id="edit-rt-' + idx + '">₱' + total.toFixed(2) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            modalBody.innerHTML = html;
        })
        .catch(err => {
            modalBody.innerHTML = '<p style="text-align:center; color:#dc2626; padding:20px;">Network error loading PO</p>';
        });
    
    document.getElementById('editPOModal').classList.add('open');
}

function recalcEditRowTotal(idx) {
    var q = parseFloat(document.querySelector('[name="items[' + idx + '][qty]"]').value) || 0;
    var p = parseFloat(document.querySelector('[name="items[' + idx + '][price]"]').value) || 0;
    var cell = document.getElementById('edit-rt-' + idx);
    if (cell) {
        cell.textContent = '₱' + (q * p).toFixed(2);
    }
}

function submitEditPO(event) {
    event.preventDefault();
    
    var submitBtn = document.getElementById('editPOSubmitBtn');
    var originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    var formData = new FormData(document.getElementById('editPOForm'));
    
    fetch('admin_purchase_orders_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (data.success) {
            alert('SUCCESS: Purchase Order updated successfully!');
            closeModal('editPOModal');
            location.reload();
        } else {
            alert('ERROR: ' + (data.message || 'Failed to update PO'));
        }
    })
    .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        alert('ERROR: Network error updating PO');
    });
}

function viewGeneratedDetails(type, batchId) {
    var title = document.getElementById('viewModalTitle');
    title.innerHTML = 'Purchase Order Details &mdash; ' + batchId;
    
    var info = document.getElementById('viewModalInfo');
    info.innerHTML = '<strong>PO Number:</strong> ' + batchId + '<br>' +
                     '<strong>Supplier:</strong> Petron Corporation<br>' +
                     '<strong>Type:</strong> ' + (type === 'fuel' ? 'Fuel Procurement' : 'Merchandise Inventory');
                     
    var tbody = document.getElementById('viewModalItemsBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;"><i class="fas fa-spinner fa-spin"></i> Loading items...</td></tr>';
    
    fetch('admin_purchase_orders.php?ajax=1&action=get_generated_items&type=' + type + '&batch_id=' + encodeURIComponent(batchId))
    .then(r => r.json())
    .then(res => {
        if (!res.success || res.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px; color:#64748b;">No items found.</td></tr>';
            return;
        }
        var html = '';
        var grandTotal = 0;
        res.items.forEach(item => {
            var qty = parseFloat(item.quantity);
            var price = parseFloat(item.unit_price);
            var total = qty * price;
            grandTotal += total;
            html += '<tr>' +
                '<td style="padding:8px;">' + item.product_name + '</td>' +
                '<td style="padding:8px; text-align:center;">' + qty.toLocaleString() + '</td>' +
                '<td style="padding:8px; text-align:right;">₱' + price.toFixed(2) + '</td>' +
                '<td style="padding:8px; text-align:right; font-weight:700;">₱' + total.toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '<tr style="background:#f8fafc; font-weight:700; border-top:2px solid #cbd5e1;">' +
            '<td colspan="3" style="padding:8px; text-align:right;">Grand Total:</td>' +
            '<td style="padding:8px; text-align:right; color:#16a34a;">₱' + grandTotal.toFixed(2) + '</td>' +
            '</tr>';
        tbody.innerHTML = html;
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#dc3545;">Error.</td></tr>';
    });
    
    document.getElementById('viewModal').classList.add('open');
}

function viewDeliveryDetails(batchId) {
    var title = document.getElementById('viewModalTitle');
    title.innerHTML = 'Delivery Details &mdash; ' + batchId;
    
    var info = document.getElementById('viewModalInfo');
    info.innerHTML = '<strong>PO Number:</strong> ' + batchId + '<br>' +
                     '<strong>Supplier:</strong> Petron Corporation';
                     
    var tbody = document.getElementById('viewModalItemsBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    
    fetch('admin_purchase_orders.php?ajax=1&action=get_delivery_details&batch_id=' + encodeURIComponent(batchId))
    .then(r => r.json())
    .then(res => {
        if (!res.success || res.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:15px; color:#64748b;">No delivery records.</td></tr>';
            return;
        }
        var html = '';
        var grandTotal = 0;
        res.items.forEach(item => {
            var qty = parseFloat(item.quantity);
            var price = parseFloat(item.unit_price);
            var total = qty * price;
            grandTotal += total;
            html += '<tr>' +
                '<td style="padding:8px;">' + item.product_name + '</td>' +
                '<td style="padding:8px; text-align:center;">' + qty.toLocaleString() + '</td>' +
                '<td style="padding:8px; text-align:right;">₱' + price.toFixed(2) + '</td>' +
                '<td style="padding:8px; text-align:right; font-weight:700;">₱' + total.toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '<tr style="background:#f8fafc; font-weight:700; border-top:2px solid #cbd5e1;">' +
            '<td colspan="3" style="padding:8px; text-align:right;">Grand Total:</td>' +
            '<td style="padding:8px; text-align:right; color:#16a34a;">₱' + grandTotal.toFixed(2) + '</td>' +
            '</tr>';
        tbody.innerHTML = html;
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#dc3545;">Error.</td></tr>';
    });
    
    document.getElementById('viewModal').classList.add('open');
}

function exportPOtoPDF(batchId) {
    if (typeof exportTableToPDF === 'function') {
        // Fetch generated items first or render details print friendly
        var printUrl = "print_po_new.php?batch_id=" + encodeURIComponent(batchId) + "&print=1";
        window.open(printUrl, '_blank');
    }
}

// Modal dismissal on click outside
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
