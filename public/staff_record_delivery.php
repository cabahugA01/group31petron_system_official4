<?php
$page_id = 'staff_record_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$msg_type = 'success';

// Get active tab
$active_tab = $_GET['tab'] ?? 'merchandise';
if (!in_array($active_tab, ['merchandise', 'fuel'])) {
    $active_tab = 'merchandise';
}

try {
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS delivery_time TIME NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS sales_invoice_no VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS received_shift VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS received_by_name VARCHAR(200) NULL");
} catch (Exception $ignored) {}

$staff_profile = [
    'name' => $me['name'] ?? $me['username'] ?? 'Staff',
    'assigned_shift' => 'Not Assigned',
];
try {
    $staff_stmt = $pdo->prepare("SELECT name, username, assigned_shift FROM users WHERE id = ? LIMIT 1");
    $staff_stmt->execute([(int)$me['id']]);
    $staff_row = $staff_stmt->fetch(PDO::FETCH_ASSOC);
    if ($staff_row) {
        $staff_profile['name'] = $staff_row['name'] ?: ($staff_row['username'] ?: $staff_profile['name']);
        $staff_profile['assigned_shift'] = $staff_row['assigned_shift'] ?: 'Not Assigned';
    }
} catch (Exception $ignored) {}

function notify_manager_delivery_recorded(PDO $pdo, int $station_id, string $title, string $message): void
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? AND role = 'manager' AND status = 'Active'");
        $stmt->execute([$station_id]);
        $manager_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($manager_ids)) {
            return;
        }

        $notify = $pdo->prepare("
            INSERT INTO notifications
                (user_id, type, title, message, event_type, severity, redirect_url, status, created_at)
            VALUES (?, 'info', ?, ?, 'delivery', 'high', 'manager_stock_in.php', 'unread', NOW())
        ");
        foreach ($manager_ids as $manager_id) {
            $notify->execute([(int)$manager_id, $title, $message]);
        }
    } catch (Exception $e) {
        error_log('Manager delivery notification failed: ' . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════
   POST — Record Merchandise Delivery
   ══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_merchandise') {
    $po_ids_raw = $_POST['po_ids'] ?? []; $po_ids = array_map('intval', (array)$po_ids_raw);
    $dr_number = trim($_POST['dr_number'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $delivery_batch_no = trim($_POST['delivery_batch_no'] ?? '');
    $driver_name = trim($_POST['driver_name'] ?? '');
    $vehicle_plate_no = trim($_POST['vehicle_plate_no'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $delivery_time = trim($_POST['delivery_time'] ?? date('H:i'));
    $received_shift = trim($_POST['received_shift'] ?? $staff_profile['assigned_shift']);
    $received_by_staff = trim($_POST['received_by_staff'] ?? $staff_profile['name']);
    $received_qtys = $_POST['received_qty'] ?? []; // Map of item_id => qty
    $conditions = $_POST['condition'] ?? []; // Map of item_id => condition
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (!empty($po_ids) && $dr_number && $invoice_number && $delivery_date && $delivery_time && !empty($received_qtys)) {
        try {
            $pdo->beginTransaction();
            $recorded_count = 0;

            foreach ($po_ids as $pr_id) {
                if ($pr_id <= 0) continue;

                // Get PO details
                $stmt = $pdo->prepare("
                    SELECT po.*, s.name as supplier_name
                    FROM purchase_orders po
                    LEFT JOIN suppliers s ON po.supplier_id = s.id
                    WHERE po.id = ? AND po.station_id = ? AND po.status IN ('Admin Finalized', 'Approved') AND po.type = 'merch'
                ");
                $stmt->execute([$pr_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$po) continue;

                // Try purchase_order_items first (newer format)
                $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
                $stmt->execute([$pr_id]);
                $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $delivery_ref = 'MDR-' . date('Ymd') . '-' . str_pad($pr_id, 4, '0', STR_PAD_LEFT);
                $full_remarks = "Invoice: " . $invoice_number . " | Delivery Time: " . $delivery_time . " | Received By: " . $received_by_staff . " (" . $received_shift . ")";
                if ($driver_name || $vehicle_plate_no) {
                    $full_remarks .= " | Driver: " . $driver_name . " | Plate: " . $vehicle_plate_no;
                }
                if ($remarks) { $full_remarks .= " | Remarks: " . $remarks; }

                if (!empty($po_items)) {
                // ── NEW FORMAT: items in purchase_order_items ──
                foreach ($po_items as $item) {
                    $item_id = $item['id'];
                    $received_qty = (float)($received_qtys[$item_id] ?? 0);
                    $condition    = trim($conditions[$item_id] ?? 'Good');
                    if ($received_qty <= 0) continue;

                    $is_good = (empty($condition) || strtolower($condition) === 'good');
                    $damaged_qty = !$is_good ? $received_qty : 0.0;
                    $actual_qty  = $is_good ? $received_qty : 0.0;

                    $p_stmt = $pdo->prepare("
                        SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                        FROM inventory_products ip
                        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                        WHERE ip.product_name = ? LIMIT 1
                    ");
                    $p_stmt->execute([$station_id, $item['item_name']]);
                    $prod_unit = $p_stmt->fetchColumn() ?: 'pcs';

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight
                            (delivery_type, delivery_ref, supplier, product, quantity, unit,
                             expected_quantity, actual_quantity, damaged_quantity,
                             delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                             status, remarks, received_shift, received_by_name,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, ?, ?, NOW(), NOW())
                    ")->execute([
                        $delivery_ref,
                        $po['supplier_name'] ?? 'Unknown',
                        $item['item_name'],
                        $received_qty,
                        $prod_unit,
                        $item['quantity'],
                        $actual_qty,
                        $damaged_qty,
                        $delivery_date,
                        $delivery_time,
                        $dr_number,
                        $invoice_number,
                        $me['id'],
                        $station_id,
                        $full_remarks,
                        $received_shift,
                        $received_by_staff,
                        $po['po_number'],
                        $delivery_batch_no
                    ]);

                    $pdo->prepare("
                        UPDATE purchase_order_items
                        SET quantity_received = quantity_received + ?,
                            received_quantity = received_quantity + ?,
                            received_at = NOW(), received_by = ?
                        WHERE id = ?
                    ")->execute([$actual_qty, $actual_qty, $me['id'], $item_id]);

                    $recorded_count++;
                }

                // Update this single PO status
                $pdo->prepare("UPDATE purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")
                    ->execute([$pr_id]);

            } else {
                // ── LEGACY FORMAT: products stored on purchase_orders rows ──
                // submitted as received_qty['po_{po_id}'] keyed by the PO row id
                $key = 'po_' . $pr_id;
                $received_qty = (float)($received_qtys[$key] ?? 0);
                if ($received_qty <= 0) continue;

                $legacy_po_id = $pr_id;

                    $condition   = trim($conditions[$key] ?? 'Good');
                    $is_good = (empty($condition) || strtolower($condition) === 'good');
                    $damaged_qty = !$is_good ? $received_qty : 0.0;
                    $actual_qty  = $is_good ? $received_qty : 0.0;

                    // Fetch the legacy PO row for product name and quantity
                    $leg_stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND station_id = ?");
                    $leg_stmt->execute([$legacy_po_id, $station_id]);
                    $leg_po = $leg_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$leg_po) continue;

                    $p_stmt = $pdo->prepare("
                        SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                        FROM inventory_products ip
                        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                        WHERE ip.product_name = ? LIMIT 1
                    ");
                    $p_stmt->execute([$station_id, $leg_po['product_name']]);
                    $prod_unit = $p_stmt->fetchColumn() ?: 'pcs';

                    $pdo->prepare("
                        INSERT INTO deliveries_oversight
                            (delivery_type, delivery_ref, supplier, product, quantity, unit,
                             expected_quantity, actual_quantity, damaged_quantity,
                             delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                             status, remarks, received_shift, received_by_name,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, ?, ?, NOW(), NOW())
                    ")->execute([
                        $delivery_ref,
                        $po['supplier_name'] ?? 'Unknown',
                        $leg_po['product_name'],
                        $received_qty,
                        $prod_unit,
                        $leg_po['quantity'],
                        $actual_qty,
                        $damaged_qty,
                        $delivery_date,
                        $delivery_time,
                        $dr_number,
                        $invoice_number,
                        $me['id'],
                        $station_id,
                        $full_remarks,
                        $received_shift,
                        $received_by_staff,
                        $leg_po['po_number'],
                        $delivery_batch_no
                    ]);

                    // Update legacy PO status after delivery details are recorded.
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")
                        ->execute([$legacy_po_id]);

                    $recorded_count++;
            }
        } // end foreach $po_ids

            if ($recorded_count <= 0) {
                throw new Exception('Please enter at least one received quantity.');
            }
             
            log_activity($pdo, $me['id'], 'Record Merchandise Delivery', "POs: " . implode(',', $po_ids) . " | DR: {$dr_number} | Rows: {$recorded_count}");
            notify_manager_delivery_recorded(
                $pdo,
                (int)$station_id,
                'Merchandise Delivery Pending Stock-In',
                "Staff recorded merchandise delivery. DR {$dr_number} is pending stock-in."
            );
             
            $pdo->commit();
            
            $_SESSION['flash_msg'] = "Merchandise delivery recorded successfully! Status: Pending Stock-In";
            $_SESSION['flash_type'] = 'success';
            header("Location: staff_record_delivery.php?tab=merchandise");
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please fill in all required fields';
        $msg_type = 'error';
    }
}

/* ══════════════════════════════════════════════════════════
   POST — Record Fuel Delivery
   ══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_fuel') {
    $po_number = trim($_POST['po_number'] ?? '');
    $dr_number = trim($_POST['dr_number'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $tanker_number = trim($_POST['tanker_number'] ?? '');
    $driver_name = trim($_POST['driver_name'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $delivery_time = trim($_POST['delivery_time'] ?? date('H:i'));
    $received_shift = trim($_POST['received_shift'] ?? $staff_profile['assigned_shift']);
    $received_by_staff = trim($_POST['received_by_staff'] ?? $staff_profile['name']);
    $received_liters_arr = $_POST['received_liters'] ?? []; // Map of fpo_id => volume
    $remarks = trim($_POST['remarks'] ?? '');

    if ($po_number && $dr_number && $invoice_number && $delivery_date && $delivery_time && !empty($received_liters_arr)) {
        try {
            $pdo->beginTransaction();

            // Fetch fuel PO rows for this po_number
            $stmt = $pdo->prepare("
                SELECT fpo.*, ft.name as fuel_type, s.name as supplier_name 
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN suppliers s ON fpo.supplier_id = s.id
                WHERE fpo.po_number = ? AND fpo.station_id = ? AND fpo.status IN ('Approved PO', 'Approved')
            ");
            $stmt->execute([$po_number, $station_id]);
            $fpos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($fpos)) {
                throw new Exception("Fuel Purchase Order not found or not in approved status.");
            }

            $fpo_map = [];
            foreach ($fpos as $fpo) {
                $fpo_map[$fpo['id']] = $fpo;
            }

            $delivery_ref = 'FDR-' . date('Ymd') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);

            $recorded_count = 0;
            foreach ($received_liters_arr as $fpo_id => $received_vol) {
                $fpo_id = (int)$fpo_id;
                $received_vol = (float)$received_vol;
                if ($received_vol <= 0 || !isset($fpo_map[$fpo_id])) {
                    continue;
                }

                $fpo_item = $fpo_map[$fpo_id];

                $full_remarks = "Invoice: " . $invoice_number . " | Delivery Time: " . $delivery_time . " | Received By: " . $received_by_staff . " (" . $received_shift . ")";
                if ($driver_name || $tanker_number) {
                    $full_remarks .= " | Driver: " . $driver_name . " | Tanker: " . $tanker_number;
                }
                if ($remarks) {
                    $full_remarks .= " | Remarks: " . $remarks;
                }

                $stmt_ins = $pdo->prepare("
                    INSERT INTO deliveries_oversight
                        (delivery_type, delivery_ref, supplier, product, quantity, unit,
                         expected_quantity, actual_quantity, damaged_quantity,
                         delivery_date, delivery_time, dr_number, sales_invoice_no, encoded_by, station_id,
                         status, remarks, received_shift, received_by_name,
                         source_ref, batch_id, created_at, updated_at)
                    VALUES ('fuel', ?, ?, ?, ?, 'L', ?, ?, 0, ?, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt_ins->execute([
                    $delivery_ref,
                    $fpo_item['supplier_name'] ?? 'Unknown',
                    $fpo_item['fuel_type'],
                    $received_vol,
                    $fpo_item['volume'], // expected_quantity
                    $received_vol,       // actual_quantity
                    $delivery_date,
                    $delivery_time,
                    $dr_number,
                    $invoice_number,
                    $me['id'],
                    $station_id,
                    $full_remarks,
                    $received_shift,
                    $received_by_staff,
                    $po_number,
                    $tanker_number
                ]);

                // Update specific fuel PO row status
                $stmt_upd = $pdo->prepare("
                    UPDATE fuel_purchase_orders 
                    SET status = 'Delivered', actual_volume = ?, delivery_date = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt_upd->execute([$received_vol, $delivery_date, $fpo_id]);
                
                $recorded_count++;
            }

            log_activity($pdo, $me['id'], 'Record Fuel Delivery', "POF #{$po_number} | DR: {$dr_number} | Rows: {$recorded_count}");
            if ($recorded_count <= 0) {
                throw new Exception('Please enter at least one received liter value.');
            }
            notify_manager_delivery_recorded(
                $pdo,
                (int)$station_id,
                'Fuel Delivery Pending Stock-In',
                "Staff recorded fuel delivery for PO {$po_number}. DR {$dr_number} is pending stock-in."
            );

            $pdo->commit();

            $_SESSION['flash_msg'] = "Fuel delivery recorded successfully! Status: Pending Stock-In";
            $_SESSION['flash_type'] = 'success';
            header("Location: staff_record_delivery.php?tab=fuel");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please fill in all required fields';
        $msg_type = 'error';
    }
}

// Check for flash messages
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

/* ══════════════════════════════════════════════════════════
   Fetch Pending Purchase Orders
   ══════════════════════════════════════════════════════════ */
$grouped_merch_pos = [];
$grouped_fuel_pos = [];

try {
    // 1. Merchandise POs — fetch base PO records (handles BOTH legacy & purchase_order_items formats)
    $stmt = $pdo->prepare("
        SELECT po.id, po.po_number, po.status,
               po.product_name AS po_product_name,
               po.quantity AS po_quantity,
               po.unit_price, po.total_amount,
               po.expected_delivery_date, po.created_at, po.remarks,
               s.name as supplier_name,
               CONCAT(u_prep.first_name, ' ', u_prep.last_name) AS prepared_by_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name,
               COALESCE(
                   (SELECT sr.request_no FROM stock_requests sr WHERE sr.id = po.request_id AND sr.request_no IS NOT NULL AND sr.request_no != '' LIMIT 1),
                   '—'
               ) AS pr_number
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u_prep ON po.created_by = u_prep.id
        LEFT JOIN users u_app ON po.approved_by = u_app.id
        WHERE po.station_id = ? 
          AND po.status IN ('Admin Finalized', 'Approved')
          AND po.type = 'merch'
        ORDER BY po.expected_delivery_date ASC, po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $merch_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by po_number; collect all PO ids sharing the same number
    foreach ($merch_raw as $row) {
        $po_num = $row['po_number'];
        if (!isset($grouped_merch_pos[$po_num])) {
            $grouped_merch_pos[$po_num] = [
                'id'                     => $row['id'],
                'po_number'              => $po_num,
                'pr_number'              => $row['pr_number'] ?? '—',
                'supplier_name'          => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at'             => $row['created_at'],
                'remarks'                => $row['remarks'],
                'status'                 => $row['status'],
                'unit_price'             => (float)($row['unit_price'] ?? 0),
                'total_amount'           => (float)($row['total_amount'] ?? 0),
                'prepared_by_name'       => $row['prepared_by_name'] ?: 'Manager',
                'approved_by_name'       => $row['approved_by_name'] ?: 'Admin',
                'po_ids'                 => [],
                'items'                  => []
            ];
        }
        $grouped_merch_pos[$po_num]['po_ids'][] = $row['id'];

        // Legacy format: product lives on the PO row itself
        if (!empty($row['po_product_name'])) {
            $grouped_merch_pos[$po_num]['items'][] = [
                'item_id'      => 'po_' . $row['id'],
                'product_name' => $row['po_product_name'],
                'ordered_qty'  => (float)$row['po_quantity'],
                'unit'         => 'pcs',
                'product_id'   => '',
                'sku'          => '—',
                'from_po_row'  => true
            ];
        }
    }

    // For each group, check if purchase_order_items exist (newer format) — if so, override
    foreach ($grouped_merch_pos as $po_num => &$po_group) {
        $all_po_ids = $po_group['po_ids'];
        if (empty($all_po_ids)) continue;
        $in_ph = implode(',', array_fill(0, count($all_po_ids), '?'));
        $poi_stmt = $pdo->prepare("
            SELECT poi.id as item_id, poi.item_name as product_name, poi.quantity as ordered_qty,
                   poi.unit_price, poi.total_price,
                   ip.id AS product_id, ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
            FROM purchase_order_items poi
            LEFT JOIN inventory_products ip ON poi.product_id = ip.id
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE poi.po_id IN ($in_ph)
        ");
        $poi_stmt->execute(array_merge([$station_id], $all_po_ids));
        $poi_rows = $poi_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($poi_rows)) {
            // Newer format: replace legacy items with proper purchase_order_items rows
            $po_group['items'] = [];
            foreach ($poi_rows as $pi) {
                $po_group['items'][] = [
                    'item_id'      => $pi['item_id'],
                    'product_name' => $pi['product_name'],
                    'ordered_qty'  => (float)$pi['ordered_qty'],
                    'unit'         => format_merch_unit($pi['unit'] ?? 'pcs'),
                    'product_id'   => !empty($pi['product_id']) ? 'P' . str_pad((int)$pi['product_id'], 4, '0', STR_PAD_LEFT) : '',
                    'sku'          => $pi['sku'] ?? '—',
                    'unit_price'   => (float)($pi['unit_price'] ?? 0),
                    'total_price'  => (float)($pi['total_price'] ?? 0),
                    'from_po_row'  => false
                ];
            }
        } else {
            // Legacy: attempt to look up unit from inventory for each item
            foreach ($po_group['items'] as &$item) {
                $u_stmt = $pdo->prepare("
                    SELECT ip.id AS product_id, ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.product_name = ? LIMIT 1
                ");
                $u_stmt->execute([$station_id, $item['product_name']]);
                $product_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
                $item['unit'] = format_merch_unit($product_row['unit'] ?? 'pcs');
                $item['sku'] = $product_row['sku'] ?? $item['sku'];
                $item['product_id'] = !empty($product_row['product_id']) ? 'P' . str_pad((int)$product_row['product_id'], 4, '0', STR_PAD_LEFT) : '';
            }
            unset($item);
        }
    }
    unset($po_group);

    // 2. Fuel POs
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name as fuel_type_name, s.name as supplier_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name,
               COALESCE(fi.ugt_no, '') AS ugt_no,
               COALESCE((SELECT request_no FROM fuel_stock_requests WHERE station_id = fpo.station_id AND LOWER(fuel_type) = LOWER(ft.name) ORDER BY id DESC LIMIT 1), '') AS pr_number
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        LEFT JOIN users u_app ON fpo.approved_by = u_app.id
        LEFT JOIN fuel_inventory fi ON fi.fuel_type_id = fpo.fuel_type_id AND fi.station_id = fpo.station_id
        WHERE fpo.station_id = ?
          AND fpo.status IN ('Approved PO', 'Approved')
        ORDER BY fpo.expected_delivery_date ASC, fpo.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $fuel_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fuel_raw as $row) {
        $po_num = $row['po_number'];
        if (!isset($grouped_fuel_pos[$po_num])) {
            $grouped_fuel_pos[$po_num] = [
                'id'                     => $row['id'],
                'po_number'              => $po_num,
                'pr_number'              => $row['pr_number'] ?? '',
                'supplier_name'          => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at'             => $row['created_at'],
                'approved_by'            => $row['approved_by_name'] ?: 'Manager',
                'status'                 => $row['status'],
                'unit_price'             => (float)($row['unit_price'] ?? 0),
                'total_amount'           => (float)($row['total_amount'] ?? 0),
                'notes'                  => $row['notes'] ?? '',
                'items'                  => []
            ];
        }
        $grouped_fuel_pos[$po_num]['items'][] = [
            'id'          => $row['id'],
            'fuel_type'   => $row['fuel_type_name'] ?: 'Fuel',
            'ordered_qty' => (float)$row['volume'],
            'ugt_no'      => $row['ugt_no'] ?? '—',
            'unit_price'  => (float)($row['unit_price'] ?? 0),
            'total_amount'=> (float)($row['total_amount'] ?? 0)
        ];
    }

} catch (Exception $e) {
    error_log("Error fetching POs: " . $e->getMessage());
}

/* ══════════════════════════════════════════════════════════
   Compute Merchandise Summary Cards
   ══════════════════════════════════════════════════════════ */
$count_pending_merch_pos = count($grouped_merch_pos);

// Deliveries Today
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND DATE(delivery_date) = CURDATE()
");
$stmt->execute([$station_id]);
$count_deliveries_today = (int)$stmt->fetchColumn();

// Pending Stock-In
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND status = 'Pending Stock-In'
");
$stmt->execute([$station_id]);
$count_pending_stock_in = (int)$stmt->fetchColumn();

// Completed Deliveries
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE station_id = ? AND delivery_type = 'merchandise' AND status IN ('Stock-In Complete', 'Confirmed', 'Closed')
");
$stmt->execute([$station_id]);
$count_completed_deliveries = (int)$stmt->fetchColumn();

// Fuel summary cards
$count_pending_fuel_pos = count($grouped_fuel_pos);

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND DATE(delivery_date) = CURDATE()
");
$stmt->execute([$station_id]);
$count_fuel_deliveries_today = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND status = 'Pending Stock-In'
");
$stmt->execute([$station_id]);
$count_fuel_pending_stock_in = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref)
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_type = 'fuel' AND status IN ('Stock-In Complete', 'Confirmed', 'Closed')
");
$stmt->execute([$station_id]);
$count_fuel_completed_deliveries = (int)$stmt->fetchColumn();

include __DIR__ . '/../partials/header.php';
?>
<div class="stock-page">
<style>
/* Page Header */
.page-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: flex-start; 
    margin-bottom: 24px; 
    flex-wrap: wrap; 
    gap: 16px;
}
.page-header h1 { 
    font-size: 26px; 
    font-weight: 800; 
    color: #002F70; 
    margin: 0; 
    display: flex; 
    align-items: center; 
    gap: 10px;
}
.page-header .subtitle { 
    font-size: 14px; 
    color: #64748b; 
    margin-top: 6px;
}

/* Alert Box */
.alert-box {
    display: none; /* Hide old alert box, use toast instead */
}

/* Professional Toast Notification */
.toast-container {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    z-index: 99999 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
    max-width: 400px !important;
    pointer-events: none;
}
.toast {
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);
    padding: 16px 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    animation: slideInRight 0.3s ease-out;
    min-width: 320px;
    pointer-events: auto;
}
.toast.success {
    border-left: 4px solid #16a34a;
}
.toast.error {
    border-left: 4px solid #dc2626;
}
.toast-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 14px;
}
.toast.success .toast-icon {
    background: #dcfce7;
    color: #16a34a;
}
.toast.error .toast-icon {
    background: #fee2e2;
    color: #dc2626;
}
.toast-content {
    flex: 1;
    padding-top: 2px;
}
.toast-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 4px;
}
.toast-message {
    font-size: 13px;
    color: #64748b;
    line-height: 1.4;
}
@keyframes slideInRight {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

/* Tabs */
.tabs-container {
    margin-bottom: 20px;
}
.tabs-header {
    display: flex;
    gap: 12px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 8px;
}
.tab-btn {
    padding: 10px 24px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    white-space: nowrap;
}
.tab-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #334155;
}
.tab-btn.active {
    background: #002F70;
    color: #ffffff;
    border-color: #002F70;
}

/* Tab Content */
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* Premium Table Styles */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.sr-table th {
    background: #002F70;
    color: #ffffff;
    font-weight: 700;
    padding: 12px 16px;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border: none;
}
.sr-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
    vertical-align: middle;
}
.sr-table tbody tr:hover {
    background: #f8fafc;
}
#merchandise-tab .sr-table tbody tr[id^="row_merch_"] td:nth-child(2),
#merchandise-tab .sr-table tbody tr[id^="row_merch_"] td:nth-child(7),
#fuel-tab .sr-table tbody tr[id^="row_fuel_"] td:nth-child(2),
#fuel-tab .sr-table tbody tr[id^="row_fuel_"] td:nth-child(6),
#fuel-tab .sr-table tbody tr[id^="row_fuel_"] td:nth-child(7),
#fuel-tab .sr-table tbody tr[id^="row_fuel_"] td:nth-child(8),
#fuel-tab .sr-table tbody tr[id^="row_fuel_"] td:nth-child(10) {
    display: none;
}

/* Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid transparent;
}
.status-waiting {
    background: #fffbeb;
    color: #b45309;
    border-color: #fde68a;
}
.status-info {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}

/* Form controls */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-label span {
    color: #dc2626;
}
.form-control, .form-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #ffffff;
    color: #1e293b;
    box-sizing: border-box;
    transition: all 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: #002F70;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
}
textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

body[data-page="staff_record_delivery"] .main {
    padding-bottom: 130px !important;
    scroll-padding-bottom: 130px;
}
.delivery-detail-shell {
    position: relative;
}
.delivery-detail-scroll {
    max-height: min(620px, calc(100vh - 360px)) !important;
    min-height: 260px;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    background: #f8fafc;
}
.delivery-detail-actions {
    position: sticky !important;
    bottom: 44px !important;
    z-index: 30;
    background: #ffffff !important;
    padding: 16px 24px !important;
    border-top: 2px solid #e2e8f0 !important;
    display: flex !important;
    gap: 10px !important;
    justify-content: flex-end !important;
    box-shadow: 0 -8px 18px rgba(15, 23, 42, 0.08);
}
@media (max-width: 700px) {
    .delivery-detail-scroll {
        max-height: calc(100vh - 330px) !important;
    }
    .delivery-detail-actions {
        flex-direction: column;
    }
    .delivery-detail-actions .txn-btn {
        width: 100%;
    }
}

/* Modals — always rendered at body level so position:fixed is viewport-relative */
.modal-overlay {
    display: none;
    position: fixed !important;
    z-index: 99999 !important;
    inset: 0 !important;
    background: rgba(10, 20, 40, 0.65);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    overflow-y: auto;
    padding: 40px 20px;
    align-items: center;
    justify-content: center;
    pointer-events: auto !important;
}
.modal-overlay.show,
.modal-overlay[style*="display: block"],
.modal-overlay[style*="display:block"],
.modal-overlay[style*="display: flex"],
.modal-overlay[style*="display:flex"] {
    display: flex !important;
}
.modal-container {
    background: #ffffff;
    max-width: 860px;
    width: calc(100vw - 40px);
    margin: auto;
    border-radius: 14px;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35), 0 12px 24px rgba(0,0,0,0.15);
    overflow: hidden;
    animation: modalSlideUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: calc(100vh - 60px);
    display: flex;
    flex-direction: column;
}
@keyframes modalSlideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    background: #002F70;
    color: #ffffff;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.modal-title {
    font-size: 15px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff !important;
}
.modal-close {
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.8);
    font-size: 24px;
    cursor: pointer;
    line-height: 1;
    flex-shrink: 0;
}
.modal-close:hover { color: #ffffff; }
.modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    background: #ffffff;
}
.modal-footer {
    padding: 14px 24px;
    border-top: 2px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    background: #f8fafc;
    flex-shrink: 0;
    gap: 10px;
    flex-wrap: wrap;
}
.modal-footer .footer-warning {
    color: #b45309;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 0;
}
.modal-footer .footer-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}
/* The form inside the modal must also be flex column so footer pins */
.modal-container > form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

/* ══ MODAL TEXT VISIBILITY — force all text clearly readable ══ */

/* Modal container always has white background, dark text */
.modal-container {
    color: #1e293b !important;
    background: #ffffff !important;
}

/* Every text node inside the modal body */
.modal-body,
.modal-body * {
    color: #1e293b;
    background-color: transparent;
}

/* Section headings */
.modal-body h4,
.modal-body h3,
.modal-body h2 {
    color: #002F70 !important;
    font-weight: 700 !important;
}

/* Info panel (PO details read-only box) */
.modal-body > div[style*="background:#f8fafc"],
.modal-body > div > div[style*="background:#f8fafc"] {
    background: #f8fafc !important;
    color: #1e293b !important;
}

/* Label spans (gray labels like "Purchase Order No:") */
.modal-body span {
    color: #475569 !important;
    font-weight: 600 !important;
    font-size: 12px !important;
}

/* Values (bold text after label) */
.modal-body strong {
    color: #0f172a !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}

/* Status text (green) stays green */
#lbl_m_status,
#lbl_f_status {
    color: #10b981 !important;
}

/* Form labels */
.form-label {
    color: #334155 !important;
    font-weight: 700 !important;
    font-size: 11.5px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.form-label span { color: #dc2626 !important; }

/* Inputs, selects, textareas */
.modal-overlay .form-control,
.modal-overlay .form-select,
.modal-overlay input,
.modal-overlay select,
.modal-overlay textarea {
    color: #1e293b !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    font-size: 13px !important;
}
.modal-overlay input::placeholder,
.modal-overlay textarea::placeholder {
    color: #94a3b8 !important;
    opacity: 1 !important;
}
.modal-overlay input:focus,
.modal-overlay select:focus,
.modal-overlay textarea:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1) !important;
    outline: none !important;
}

/* Table inside modal */
.modal-body table thead th {
    background: #002F70 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 11.5px !important;
}
.modal-body table tbody td {
    color: #1e293b !important;
    font-size: 13px !important;
}
.modal-body table tbody tr:hover td {
    background: #f0f4ff !important;
}

/* Ordered qty (blue) */
.modal-body table tbody td[style*="color:#3b82f6"],
.modal-body table tbody td > span[style*="color:#3b82f6"] {
    color: #2563eb !important;
    font-weight: 700 !important;
}

/* Summary totals */
.modal-body [id*="summary"],
.modal-body span[style*="font-weight:700"],
.modal-body span[style*="font-weight: 700"] {
    color: #0f172a !important;
}

/* Ensure modal buttons are always clickable */
.modal-overlay button,
.modal-overlay input,
.modal-overlay select,
.modal-overlay textarea,
.modal-overlay a {
    pointer-events: auto !important;
    position: relative;
    z-index: 1;
}

/* Buttons */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    border: 1px solid transparent;
    text-decoration: none;
}
.txn-btn.primary {
    background: #002F70;
    color: #ffffff;
}
.txn-btn.primary:hover {
    background: #001f4d;
}
.txn-btn.secondary {
    background: #ffffff;
    color: #475569;
    border-color: #cbd5e1;
}
.txn-btn.secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Record Delivery</h1>
        <div class="subtitle">Encode actual delivery details: DR number, products received, quantity, and date</div>
    </div>
</div>

<!-- Toast Notification Container -->
<div class="toast-container" id="toastContainer"></div>

<?php if ($msg): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showToast('<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>', '<?php echo addslashes($msg); ?>');
});
</script>
<?php endif; ?>

<div class="tabs-container">
    <div class="tabs-header">
        <button class="tab-btn <?php echo $active_tab === 'merchandise' ? 'active' : ''; ?>" 
                onclick="switchTab('merchandise')">
            <i class="fas fa-boxes"></i>
            <span>Merchandise</span>
        </button>
        <button class="tab-btn <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>" 
                onclick="switchTab('fuel')">
            <i class="fas fa-gas-pump"></i>
            <span>Fuel</span>
        </button>
    </div>
</div>

<!-- =========================================================================
     MERCHANDISE TAB
     ========================================================================= -->
<div id="merchandise-tab" class="tab-content <?php echo $active_tab === 'merchandise' ? 'active' : ''; ?>">
    
    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#3b82f6; font-size:20px;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_merch_pos ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#22c55e; font-size:20px;">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Deliveries Received Today</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_deliveries_today ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Stock-In</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_stock_in ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f5f3ff; display:flex; align-items:center; justify-content:center; color:#8b5cf6; font-size:20px;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Completed Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_completed_deliveries ?></div>
            </div>
        </div>
    </div>

    <!-- Table block -->
    <?php if (empty($grouped_merch_pos)): ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; text-align:center; padding:60px 20px; color:#64748b; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <i class="fas fa-inbox" style="font-size:54px; color:#cbd5e1; margin-bottom:16px;"></i>
        <h3 style="font-size:18px; font-weight:800; color:#1e293b; margin:0 0 8px 0;">📦 No Pending Merchandise Deliveries</h3>
        <p style="font-size:13px; margin:0; color:#64748b;">All approved purchase orders have already been recorded.</p>
    </div>
    <?php else: ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-clipboard-list"></i> Pending Delivery Table
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Delivery Date</th>
                        <th style="text-align:center; width:120px;">Products</th>
                        <th style="text-align:center; width:140px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_merch_pos as $po):
                        $safe_key = 'merch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $po['po_number']);
                    ?>
                    <!-- Summary row -->
                    <tr id="row_<?= $safe_key ?>" style="cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background 0.12s;">
                         <td style="font-weight:700; font-family:monospace; color:#002F70;">
                            <?php
                            $merch_view_data = [
                                'type'       => 'Merchandise',
                                'po_number'  => $po['po_number'],
                                'pr_number'  => $po['pr_number'] ?? '—',
                                'supplier'   => $po['supplier_name'],
                                'exp_del'    => $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—',
                                'prep_by'    => $po['prepared_by_name'],
                                'appr_by'    => $po['approved_by_name'],
                                'status'     => $po['status'],
                                'total'      => $po['total_amount'],
                                'remarks'    => $po['remarks'],
                                'items'      => array_map(fn($it) => [
                                    'sku'        => $it['sku'] ?? '—',
                                    'name'       => $it['product_name'],
                                    'qty'        => $it['ordered_qty'],
                                    'unit'       => $it['unit'],
                                    'unit_price' => $it['unit_price'] ?? 0,
                                    'total'      => $it['total_price'] ?? 0,
                                ], $po['items'])
                            ];
                            ?>
                            <button type="button" onclick="toggleInlineDelivery('<?= $safe_key ?>')"
                                style="background-color:transparent !important;border:none;color:#002F70;font-weight:800;font-family:monospace;font-size:13px;cursor:pointer;padding:0;text-decoration:underline;">
                                <i class="fas fa-file-alt" style="font-size:11px;margin-right:4px;"></i>
                                <?= htmlspecialchars($po['po_number']) ?>
                            </button>
                        </td>
                        <td style="font-family:monospace; color:#64748b;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="font-weight:600; color:#334155;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                        <td style="text-align:center; font-weight:700; color:#002F70;"><?= count($po['items']) ?></td>
                        <td style="text-align:center;">
                            <span class="status-badge status-waiting"><i class="fas fa-clock"></i> Waiting Delivery</span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn primary" onclick="toggleInlineDelivery('<?= $safe_key ?>')"
                                style="padding:6px 12px; font-size:11.5px; font-weight:700;">
                                <i class="fas fa-file-signature"></i> Record Delivery
                            </button>
                        </td>
                    </tr>
                    <!-- Inline delivery form -->
                    <tr id="detail_<?= $safe_key ?>" style="display:none;">
                        <td colspan="7" style="padding:0; background:#f8fafc; border:none !important;">
                            <div style="position:relative;">
                                <!-- Scrollable Content Area -->
                                <div class="delivery-detail-scroll" style="max-height:calc(100vh - 180px); overflow-y:auto;">
                                    <!-- Header bar -->
                                    <div style="background:#002F70; padding:14px 24px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:10;">
                                        <i class="fas fa-boxes" style="color:#fff; font-size:16px;"></i>
                                        <span style="font-size:14px; font-weight:800; color:#fff; letter-spacing:0.3px;">
                                            Merchandise Delivery — <?= htmlspecialchars($po['po_number']) ?>
                                        </span>
                                    </div>
                                    <div style="padding:20px 24px; flex:1; overflow-y:auto;">
                                    <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-file-invoice" style="margin-right:5px;"></i> Purchase Order Information (Read Only)</div>
                                    <!-- PO Info -->
                                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; font-size:12px;">
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;"><?= htmlspecialchars($po['po_number']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Request No.</div><div style="font-weight:700;color:#1e293b;font-family:monospace;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Supplier</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($po['supplier_name']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order Date</div><div style="font-weight:600;color:#1e293b;"><?= $po['created_at'] ? date('M d, Y', strtotime($po['created_at'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Expected Delivery</div><div style="font-weight:600;color:#1e293b;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Received By</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div></div>
                                    </div>
                                    <!-- Form -->
                                    <form method="POST" action="staff_record_delivery.php?tab=merchandise" id="merch-form-<?= $safe_key ?>">
                                        <input type="hidden" name="action" value="record_merchandise">
                                        <?php foreach ($po['po_ids'] as $pid): ?>
                                        <input type="hidden" name="po_ids[]" value="<?= (int)$pid ?>">
                                        <?php endforeach; ?>
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;"><i class="fas fa-truck-loading" style="margin-right:5px;"></i> Delivery Information</div>
                                        <!-- Delivery inputs -->
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
                                            <div class="form-group">
                                                <label class="form-label">Delivery Receipt No. <span>*</span></label>
                                                <input type="text" name="dr_number" class="form-control" required placeholder="e.g. DR-12345">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Sales Invoice No. <span>*</span></label>
                                                <input type="text" name="invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Date <span>*</span></label>
                                                <input type="date" name="delivery_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Time <span>*</span></label>
                                                <input type="time" name="delivery_time" class="form-control" required value="<?= date('H:i') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By</label>
                                                <input type="text" name="received_shift" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['assigned_shift']) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By Staff</label>
                                                <input type="text" name="received_by_staff" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['name']) ?>">
                                            </div>
                                        </div>
                                        <div class="form-group" style="margin-bottom:18px;">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" placeholder="Optional remarks"></textarea>
                                        </div>
                                        <!-- Items table -->
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-boxes" style="margin-right:5px;"></i> Received Products</div>
                                        <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:18px; background:#fff;">
                                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                <thead>
                                                    <tr style="background:#002F70;">
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product ID</th>
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product Code</th>
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Product Name</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Qty Ordered</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Qty Received <span style="color:#ff8a8a;">*</span></th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">UOM</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($po['items'] as $item): ?>
                                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#0f172a;font-weight:700;"><?= htmlspecialchars($item['product_id'] ?: ('P' . str_pad((int)preg_replace('/\D/', '', (string)$item['item_id']), 4, '0', STR_PAD_LEFT))) ?></td>
                                                        <td style="padding:10px 12px;font-family:monospace;font-size:12px;color:#64748b;"><?= htmlspecialchars($item['sku'] ?? '—') ?></td>
                                                        <td style="padding:10px 12px;font-weight:600;color:#1e293b;"><?= htmlspecialchars($item['product_name']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;font-weight:700;color:#2563eb;"><?= number_format($item['ordered_qty']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <input type="number" name="received_qty[<?= $item['item_id'] ?>]"
                                                                step="0.01" min="0" value="" placeholder="____" required
                                                                style="width:90px;padding:6px;border:1.5px solid #cbd5e1;border-radius:6px;text-align:center;font-weight:700;font-size:13px;">
                                                        </td>
                                                        <td style="padding:10px 12px;text-align:center;color:#64748b;font-weight:600;"><?= htmlspecialchars($item['unit']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </form>
                                </div>
                                </div>
                                <!-- Fixed Action Buttons -->
                                <div style="background:#fff; padding:16px 24px; border-top:2px solid #e2e8f0; border-bottom:none; display:flex; gap:10px; justify-content:flex-end;">
                                    <button type="button" class="txn-btn secondary" onclick="toggleInlineDelivery('<?= $safe_key ?>')">Cancel</button>
                                    <button type="submit" form="merch-form-<?= $safe_key ?>" class="txn-btn primary"><i class="fas fa-paper-plane"></i> Submit Delivery</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- =========================================================================
     FUEL TAB
     ========================================================================= -->
<div id="fuel-tab" class="tab-content <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">

    <!-- Summary Cards -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#3b82f6; font-size:20px;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_fuel_pos ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#22c55e; font-size:20px;">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Deliveries Received Today</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_deliveries_today ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending Stock-In</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_pending_stock_in ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f5f3ff; display:flex; align-items:center; justify-content:center; color:#8b5cf6; font-size:20px;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Completed Deliveries</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_fuel_completed_deliveries ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($grouped_fuel_pos)): ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; text-align:center; padding:60px 20px; color:#64748b; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <i class="fas fa-gas-pump" style="font-size:54px; color:#cbd5e1; margin-bottom:16px;"></i>
        <h3 style="font-size:18px; font-weight:800; color:#1e293b; margin:0 0 8px 0;">No Pending Fuel Deliveries</h3>
        <p style="font-size:13px; margin:0; color:#64748b;">All approved fuel purchase orders have already been recorded.</p>
    </div>
    <?php else: ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-gas-pump"></i> Pending Delivery Table
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Delivery Date</th>
                        <th style="text-align:center; width:120px;">Fuel Types</th>
                        <th style="text-align:center; width:140px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_fuel_pos as $po):
                        $safe_fkey  = 'fuel_' . preg_replace('/[^a-zA-Z0-9]/', '_', $po['po_number']);
                        $fuel_types = implode(', ', array_column($po['items'], 'fuel_type'));
                        $total_liters = array_sum(array_column($po['items'], 'ordered_qty'));
                    ?>
                    <!-- Summary row -->
                    <tr id="row_<?= $safe_fkey ?>" style="cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background 0.12s;">
                         <td style="font-weight:700; font-family:monospace; color:#002F70;">
                            <?php
                            $fuel_view_data = [
                                'type'       => 'Fuel',
                                'po_number'  => $po['po_number'],
                                'pr_number'  => $po['pr_number'] ?? '—',
                                'supplier'   => $po['supplier_name'],
                                'exp_del'    => $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—',
                                'appr_by'    => $po['approved_by'] ?? 'Admin',
                                'status'     => $po['status'],
                                'total'      => $po['total_amount'],
                                'remarks'    => $po['notes'] ?? '',
                                'items'      => array_map(fn($it) => [
                                    'name'       => $it['fuel_type'],
                                    'ugt'        => $it['ugt_no'],
                                    'qty'        => $it['ordered_qty'],
                                    'unit_price' => $it['unit_price'] ?? 0,
                                    'total'      => $it['total_amount'] ?? 0,
                                ], $po['items'])
                            ];
                            ?>
                            <button type="button" onclick="toggleInlineDelivery('<?= $safe_fkey ?>')"
                                style="background-color:transparent !important;border:none;color:#002F70;font-weight:800;font-family:monospace;font-size:13px;cursor:pointer;padding:0;text-decoration:underline;">
                                <i class="fas fa-file-alt" style="font-size:11px;margin-right:4px;"></i>
                                <?= htmlspecialchars($po['po_number']) ?>
                            </button>
                        </td>
                        <td style="font-family:monospace; color:#64748b;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="font-weight:600; color:#334155;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                        <td style="text-align:center; font-weight:700; color:#002F70;"><?= count($po['items']) ?> Fuel Types</td>
                        <td style="font-weight:700; color:#002F70;"><?= htmlspecialchars($fuel_types) ?></td>
                        <td style="text-align:right; font-weight:800; font-family:monospace; color:#0f172a;"><?= number_format($total_liters) ?> L</td>
                        <td style="font-weight:600; color:#334155;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                        <td style="text-align:center;">
                            <span class="status-badge status-waiting"><i class="fas fa-clock"></i> Waiting Delivery</span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn primary" onclick="toggleInlineDelivery('<?= $safe_fkey ?>')"
                                style="padding:6px 12px; font-size:11.5px; font-weight:700;">
                                <i class="fas fa-file-signature"></i> Record Delivery
                            </button>
                        </td>
                    </tr>
                    <!-- Inline fuel delivery form -->
                    <tr id="detail_<?= $safe_fkey ?>" style="display:none;">
                        <td colspan="10" style="padding:0; background:#f8fafc; border:none !important;">
                            <div style="position:relative;">
                                <!-- Scrollable Content Area -->
                                <div class="delivery-detail-scroll" style="max-height:calc(100vh - 180px); overflow-y:auto;">
                                    <!-- Header bar -->
                                    <div style="background:#002F70; padding:14px 24px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:10;">
                                        <i class="fas fa-gas-pump" style="color:#fff; font-size:16px;"></i>
                                        <span style="font-size:14px; font-weight:800; color:#fff; letter-spacing:0.3px;">
                                            Fuel Delivery — <?= htmlspecialchars($po['po_number']) ?>
                                        </span>
                                </div>
                                <div style="padding:20px 24px;">
                                    <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-file-invoice" style="margin-right:5px;"></i> Purchase Order Information (Read Only)</div>
                                    <!-- PO Info -->
                                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-bottom:18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; font-size:12px;">
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;"><?= htmlspecialchars($po['po_number']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Purchase Request No.</div><div style="font-weight:700;color:#1e293b;font-family:monospace;"><?= htmlspecialchars($po['pr_number'] ?? '—') ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Supplier</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($po['supplier_name']) ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Expected Delivery</div><div style="font-weight:600;color:#1e293b;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></div></div>
                                        <div><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Received By</div><div style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div></div>
                                    </div>
                                    <!-- Form -->
                                    <form method="POST" action="staff_record_delivery.php?tab=fuel" id="fuel-form-<?= $safe_fkey ?>">
                                        <input type="hidden" name="action" value="record_fuel">
                                        <input type="hidden" name="po_number" value="<?= htmlspecialchars($po['po_number']) ?>">
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;"><i class="fas fa-truck-loading" style="margin-right:5px;"></i> Delivery Information</div>
                                        <!-- Delivery inputs -->
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
                                            <div class="form-group">
                                                <label class="form-label">Delivery Receipt No. <span>*</span></label>
                                                <input type="text" name="dr_number" class="form-control" required placeholder="e.g. DR-12345">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Sales Invoice No. <span>*</span></label>
                                                <input type="text" name="invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Date <span>*</span></label>
                                                <input type="date" name="delivery_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Delivery Time <span>*</span></label>
                                                <input type="time" name="delivery_time" class="form-control" required value="<?= date('H:i') ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By</label>
                                                <input type="text" name="received_shift" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['assigned_shift']) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Received By Staff</label>
                                                <input type="text" name="received_by_staff" class="form-control" readonly value="<?= htmlspecialchars($staff_profile['name']) ?>">
                                            </div>
                                        </div>
                                        <div class="form-group" style="margin-bottom:18px;">
                                            <label class="form-label">Remarks</label>
                                            <textarea name="remarks" class="form-control" placeholder="Optional remarks"></textarea>
                                        </div>
                                        <!-- Fuel items table -->
                                        <div style="font-size:10.5px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;"><i class="fas fa-gas-pump" style="margin-right:5px;"></i> Received Fuel</div>
                                        <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:18px; background:#fff;">
                                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                <thead>
                                                    <tr style="background:#002F70;">
                                                        <th style="padding:10px 12px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Fuel Type</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">UGT No.</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Liters Ordered</th>
                                                        <th style="padding:10px 12px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Liters Received <span style="color:#ff8a8a;">*</span></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($po['items'] as $fitem): ?>
                                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                                        <td style="padding:10px 12px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($fitem['fuel_type']) ?></td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <span style="background:#dbeafe;color:#1d4ed8;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:20px;font-family:monospace;"><?= htmlspecialchars($fitem['ugt_no'] ?? '—') ?></span>
                                                        </td>
                                                        <td style="padding:10px 12px;text-align:center;font-weight:700;color:#2563eb;"><?= number_format($fitem['ordered_qty']) ?> L</td>
                                                        <td style="padding:10px 12px;text-align:center;">
                                                            <div style="display:inline-flex;align-items:center;gap:4px;">
                                                                <input type="number" name="received_liters[<?= (int)$fitem['id'] ?>]"
                                                                    step="0.01" min="0" value="" placeholder="____" required
                                                                    style="width:110px;padding:6px;border:1.5px solid #cbd5e1;border-radius:6px;text-align:center;font-weight:700;font-size:13px;">
                                                                <span style="font-size:11px;color:#64748b;font-weight:700;">L</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </form>
                                </div>
                                </div>
                                <!-- Fixed Action Buttons -->
                                <div style="background:#fff; padding:16px 24px; border-top:2px solid #e2e8f0; border-bottom:none; display:flex; gap:10px; justify-content:flex-end;">
                                    <button type="button" class="txn-btn secondary" onclick="toggleInlineDelivery('<?= $safe_fkey ?>')">Cancel</button>
                                    <button type="submit" form="fuel-form-<?= $safe_fkey ?>" class="txn-btn primary"><i class="fas fa-paper-plane"></i> Submit Delivery</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Toast Notification
function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    const title = type === 'success' ? 'Success' : 'Error';
    toast.innerHTML = `<div class="toast-icon"><i class="fas ${icon}"></i></div><div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideInRight 0.3s ease-out reverse';
        setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 300);
    }, 5000);
}

// Tab Switching
function switchTab(tab) {
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.querySelector(`.tab-btn[onclick="switchTab('${tab}')"]`).classList.add('active');
    document.getElementById(`${tab}-tab`).classList.add('active');
}

// Inline accordion toggle
function toggleInlineDelivery(key) {
    const detailRow = document.getElementById('detail_' + key);
    const icon = document.getElementById('icon_' + key);
    if (!detailRow) return;
    const isOpen = detailRow.style.display !== 'none';
    // Close all others first
    document.querySelectorAll('tr[id^="detail_"]').forEach(r => { r.style.display = 'none'; });
    document.querySelectorAll('i[id^="icon_"]').forEach(i => { i.style.transform = 'rotate(0deg)'; });
    if (!isOpen) {
        detailRow.style.display = 'table-row';
        if (icon) icon.style.transform = 'rotate(90deg)';
        // Scroll into view smoothly
        setTimeout(() => detailRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
    }
}

// Flash messages from PHP session
<?php if ($msg): ?>
document.addEventListener('DOMContentLoaded', function() {
    showToast('<?= $msg_type === 'success' ? 'success' : 'error' ?>', '<?= addslashes($msg) ?>');
});
<?php endif; ?>
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

<!-- ═══════════════════════════════════════════
     PO RECEIPT VIEW MODAL
     ═══════════════════════════════════════════ -->
<div id="poViewModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:780px; max-height:92vh; display:flex; flex-direction:column; box-shadow:0 25px 60px rgba(0,0,0,0.35); overflow:hidden;">
        <!-- Modal Header -->
        <div style="background:#002F70; padding:16px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-file-invoice" style="color:#fff; font-size:18px;"></i>
                <div>
                    <div style="color:#fff; font-weight:800; font-size:15px; letter-spacing:0.3px;" id="pov_title">Purchase Order Receipt</div>
                    <div style="color:rgba(255,255,255,0.7); font-size:11px; margin-top:1px;" id="pov_subtitle">View PO Details</div>
                </div>
            </div>
            <button type="button" onclick="closePOView()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:0.8;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">&times;</button>
        </div>
        <!-- Modal Body -->
        <div style="overflow-y:auto; flex:1; padding:24px;">
            <!-- Info Grid -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:20px;">
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Purchase Order No.</div><div style="font-weight:800;color:#002F70;font-family:monospace;font-size:13px;" id="pov_po_number">—</div></div>
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">PR No.</div><div style="font-weight:700;color:#1e293b;font-family:monospace;font-size:13px;" id="pov_pr_number">—</div></div>
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Supplier</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="pov_supplier">—</div></div>
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Expected Delivery</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="pov_exp_del">—</div></div>
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Prepared By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="pov_prep_by">—</div></div>
                <div><div style="font-size:9.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Approved By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="pov_appr_by">—</div></div>
            </div>
            <!-- Items Table -->
            <div style="font-size:11px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;" id="pov_items_label"><i class="fas fa-boxes" style="margin-right:5px;"></i>Ordered Items</div>
            <div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:16px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead id="pov_thead" style="background:#002F70;"></thead>
                    <tbody id="pov_tbody"></tbody>
                    <tfoot id="pov_tfoot"></tfoot>
                </table>
            </div>
            <!-- Notes -->
            <div id="pov_notes_wrap" style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; display:none;">
                <div style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;margin-bottom:4px;"><i class="fas fa-sticky-note" style="margin-right:4px;"></i>Notes / Remarks</div>
                <div style="font-size:13px;color:#78350f;" id="pov_notes"></div>
            </div>
        </div>
        <!-- Modal Footer -->
        <div style="padding:14px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px; flex-shrink:0;">
            <button type="button" onclick="closePOView()" style="background:#6b7280;color:#fff;border:none;padding:9px 22px;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
function openPOView(data) {
    var modal = document.getElementById('poViewModal');
    var fmt = function(v) { return v || '—'; };
    var fmtMoney = function(v) {
        v = parseFloat(v) || 0;
        return v > 0 ? '₱' + v.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) : '—';
    };
    // Header
    document.getElementById('pov_title').textContent = data.type + ' Purchase Order';
    document.getElementById('pov_subtitle').textContent = data.po_number;
    document.getElementById('pov_po_number').textContent = fmt(data.po_number);
    document.getElementById('pov_pr_number').textContent = fmt(data.pr_number);
    document.getElementById('pov_supplier').textContent  = fmt(data.supplier);
    document.getElementById('pov_exp_del').textContent   = fmt(data.exp_del);
    document.getElementById('pov_prep_by').textContent   = fmt(data.prep_by || data.appr_by);
    document.getElementById('pov_appr_by').textContent   = fmt(data.appr_by);
    // Notes
    var notesWrap = document.getElementById('pov_notes_wrap');
    if (data.remarks && data.remarks.trim()) {
        document.getElementById('pov_notes').textContent = data.remarks;
        notesWrap.style.display = 'block';
    } else {
        notesWrap.style.display = 'none';
    }
    // Items
    var isFuel = data.type === 'Fuel';
    document.getElementById('pov_items_label').innerHTML =
        (isFuel ? '<i class="fas fa-gas-pump" style="margin-right:5px;"></i>Fuel Items Ordered'
                : '<i class="fas fa-boxes" style="margin-right:5px;"></i>Items Ordered');
    // Build thead
    var thead = document.getElementById('pov_thead');
    var tbody = document.getElementById('pov_tbody');
    var tfoot = document.getElementById('pov_tfoot');
    var thStyle = 'padding:10px 12px;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;';
    if (isFuel) {
        thead.innerHTML = '<tr>' +
            '<th style="'+thStyle+'text-align:left;">Fuel Type</th>' +
            '<th style="'+thStyle+'text-align:center;">UGT No.</th>' +
            '<th style="'+thStyle+'text-align:right;">Liters Ordered</th>' +
            '<th style="'+thStyle+'text-align:right;">Unit Price</th>' +
            '<th style="'+thStyle+'text-align:right;">Total Amount</th>' +
            '</tr>';
    } else {
        thead.innerHTML = '<tr>' +
            '<th style="'+thStyle+'text-align:left;">Product Code</th>' +
            '<th style="'+thStyle+'text-align:left;">Product Name</th>' +
            '<th style="'+thStyle+'text-align:center;">Qty Ordered</th>' +
            '<th style="'+thStyle+'text-align:center;">UOM</th>' +
            '<th style="'+thStyle+'text-align:right;">Unit Price</th>' +
            '<th style="'+thStyle+'text-align:right;">Total</th>' +
            '</tr>';
    }
    // Build tbody
    var tdStyle = 'padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#1e293b;';
    var rows = '';
    var grandTotal = 0;
    (data.items || []).forEach(function(item) {
        if (isFuel) {
            var rowTotal = parseFloat(item.total) || (parseFloat(item.qty||0) * parseFloat(item.unit_price||0));
            grandTotal += rowTotal;
            rows += '<tr>' +
                '<td style="'+tdStyle+'font-weight:700;">' + escH(item.name) + '</td>' +
                '<td style="'+tdStyle+'text-align:center;"><span style="background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;font-family:monospace;">' + escH(item.ugt||'—') + '</span></td>' +
                '<td style="'+tdStyle+'text-align:right;font-weight:700;font-family:monospace;">' + Number(item.qty||0).toLocaleString('en-PH') + ' L</td>' +
                '<td style="'+tdStyle+'text-align:right;color:#334155;">' + fmtMoney(item.unit_price) + '</td>' +
                '<td style="'+tdStyle+'text-align:right;font-weight:800;color:#002F70;">' + fmtMoney(rowTotal) + '</td>' +
                '</tr>';
        } else {
            var rowTotal = parseFloat(item.total) || (parseFloat(item.qty||0) * parseFloat(item.unit_price||0));
            grandTotal += rowTotal;
            rows += '<tr>' +
                '<td style="'+tdStyle+'font-family:monospace;color:#475569;font-size:11.5px;">' + escH(item.sku||'—') + '</td>' +
                '<td style="'+tdStyle+'font-weight:600;">' + escH(item.name) + '</td>' +
                '<td style="'+tdStyle+'text-align:center;font-weight:700;color:#002F70;">' + Number(item.qty||0).toLocaleString('en-PH') + '</td>' +
                '<td style="'+tdStyle+'text-align:center;color:#64748b;">' + escH(item.unit||'pcs') + '</td>' +
                '<td style="'+tdStyle+'text-align:right;color:#334155;">' + fmtMoney(item.unit_price) + '</td>' +
                '<td style="'+tdStyle+'text-align:right;font-weight:800;color:#002F70;">' + fmtMoney(rowTotal) + '</td>' +
                '</tr>';
        }
    });
    tbody.innerHTML = rows || '<tr><td colspan="6" style="padding:20px;text-align:center;color:#94a3b8;">No items found.</td></tr>';
    // Grand total footer
    var usedTotal = parseFloat(data.total) > 0 ? parseFloat(data.total) : grandTotal;
    var colSpan = isFuel ? 4 : 5;
    tfoot.innerHTML = '<tr style="background:#f8fafc;">' +
        '<td colspan="'+colSpan+'" style="padding:12px;text-align:right;font-weight:800;font-size:12px;color:#334155;text-transform:uppercase;letter-spacing:.3px;">Grand Total</td>' +
        '<td style="padding:12px;text-align:right;font-weight:900;font-size:15px;color:#002F70;font-family:monospace;">' + fmtMoney(usedTotal) + '</td>' +
        '</tr>';
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closePOView() {
    document.getElementById('poViewModal').style.display = 'none';
    document.body.style.overflow = '';
}
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
// Close on backdrop click
document.getElementById('poViewModal').addEventListener('click', function(e) {
    if (e.target === this) closePOView();
});
// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('poViewModal').style.display === 'flex') closePOView();
});
</script>
