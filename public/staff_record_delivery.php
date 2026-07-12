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

/* ══════════════════════════════════════════════════════════
   POST — Record Merchandise Delivery
   ══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_merchandise') {
    $pr_id = (int)($_POST['pr_id'] ?? 0); // This is purchase_orders.id
    $dr_number = trim($_POST['dr_number'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $delivery_batch_no = trim($_POST['delivery_batch_no'] ?? '');
    $driver_name = trim($_POST['driver_name'] ?? '');
    $vehicle_plate_no = trim($_POST['vehicle_plate_no'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $received_qtys = $_POST['received_qty'] ?? []; // Map of item_id => qty
    $conditions = $_POST['condition'] ?? []; // Map of item_id => condition
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($pr_id > 0 && $dr_number && !empty($received_qtys)) {
        try {
            $pdo->beginTransaction();
            
            // Get PO details
            $stmt = $pdo->prepare("
                SELECT po.*, s.name as supplier_name 
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE po.id = ? AND po.station_id = ? AND po.status IN ('Admin Finalized', 'Approved') AND po.type = 'merch'
            ");
            $stmt->execute([$pr_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                throw new Exception("Purchase Order not found or not in Approved/Finalized status");
            }
            
            // Try purchase_order_items first (newer format)
            $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
            $stmt->execute([$pr_id]);
            $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $delivery_ref = 'MDR-' . date('Ymd') . '-' . str_pad($pr_id, 4, '0', STR_PAD_LEFT);
            
            $full_remarks = "Driver: " . $driver_name . " | Plate: " . $vehicle_plate_no . " | Invoice: " . $invoice_number;
            if ($remarks) {
                $full_remarks .= " | Remarks: " . $remarks;
            }

            $recorded_count = 0;

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
                             delivery_date, dr_number, encoded_by, station_id, status, remarks,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, NOW(), NOW())
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
                        $dr_number,
                        $me['id'],
                        $station_id,
                        $full_remarks,
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
                $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?")
                    ->execute([$pr_id]);

            } else {
                // ── LEGACY FORMAT: products stored on purchase_orders rows ──
                // submitted as received_qty['po_{po_id}'] keyed by the PO row id
                foreach ($received_qtys as $key => $qty) {
                    $received_qty = (float)$qty;
                    if ($received_qty <= 0) continue;

                    // key is either numeric (poi id) or 'po_NN'
                    if (strpos((string)$key, 'po_') === 0) {
                        $legacy_po_id = (int)substr($key, 3);
                    } else {
                        continue; // unexpected key in legacy context
                    }

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
                             delivery_date, dr_number, encoded_by, station_id, status, remarks,
                             source_ref, batch_id, created_at, updated_at)
                        VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, NOW(), NOW())
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
                        $dr_number,
                        $me['id'],
                        $station_id,
                        $full_remarks,
                        $leg_po['po_number'],
                        $delivery_batch_no
                    ]);

                    // Update legacy PO status to Delivered
                    $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?")
                        ->execute([$legacy_po_id]);

                    $recorded_count++;
                }
            }
            
            log_activity($pdo, $me['id'], 'Record Merchandise Delivery', "PO #{$pr_id} | DR: {$dr_number} | Rows: {$recorded_count}");
            
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
    $received_liters_arr = $_POST['received_liters'] ?? []; // Map of fpo_id => volume
    $remarks = trim($_POST['remarks'] ?? '');

    if ($po_number && $dr_number && !empty($received_liters_arr)) {
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

                $full_remarks = "Driver: " . $driver_name . " | Invoice: " . $invoice_number . " | Tanker: " . $tanker_number;
                if ($remarks) {
                    $full_remarks .= " | Remarks: " . $remarks;
                }

                $stmt_ins = $pdo->prepare("
                    INSERT INTO deliveries_oversight
                        (delivery_type, delivery_ref, supplier, product, quantity, unit,
                         expected_quantity, actual_quantity, damaged_quantity,
                         delivery_date, dr_number, encoded_by, station_id, status, remarks, 
                         source_ref, batch_id, created_at, updated_at)
                    VALUES ('fuel', ?, ?, ?, ?, 'L', ?, ?, 0, ?, ?, ?, ?, 'Pending Stock-In', ?, ?, ?, NOW(), NOW())
                ");
                $stmt_ins->execute([
                    $delivery_ref,
                    $fpo_item['supplier_name'] ?? 'Unknown',
                    $fpo_item['fuel_type'],
                    $received_vol,
                    $fpo_item['volume'], // expected_quantity
                    $received_vol,       // actual_quantity
                    $delivery_date,
                    $dr_number,
                    $me['id'],
                    $station_id,
                    $full_remarks,
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
               po.expected_delivery_date, po.created_at, po.remarks,
               s.name as supplier_name,
               CONCAT(u_prep.first_name, ' ', u_prep.last_name) AS prepared_by_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name
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
                'supplier_name'          => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at'             => $row['created_at'],
                'remarks'                => $row['remarks'],
                'status'                 => $row['status'],
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
                   ip.sku, COALESCE(si.unit, ip.size, 'pcs') AS unit
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
                    'sku'          => $pi['sku'] ?? '—',
                    'from_po_row'  => false
                ];
            }
        } else {
            // Legacy: attempt to look up unit from inventory for each item
            foreach ($po_group['items'] as &$item) {
                $u_stmt = $pdo->prepare("
                    SELECT COALESCE(si.unit, ip.size, 'pcs') AS unit
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.product_name = ? LIMIT 1
                ");
                $u_stmt->execute([$station_id, $item['product_name']]);
                $found_unit = $u_stmt->fetchColumn();
                $item['unit'] = format_merch_unit($found_unit ?: 'pcs');
            }
            unset($item);
        }
    }
    unset($po_group);

    // 2. Fuel POs
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name as fuel_type_name, s.name as supplier_name,
               CONCAT(u_app.first_name, ' ', u_app.last_name) AS approved_by_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        LEFT JOIN users u_app ON fpo.approved_by = u_app.id
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
                'id' => $row['id'],
                'po_number' => $po_num,
                'supplier_name' => $row['supplier_name'] ?? 'Petron Corporation',
                'expected_delivery_date' => $row['expected_delivery_date'],
                'created_at' => $row['created_at'],
                'approved_by' => $row['approved_by_name'] ?: 'Manager',
                'status' => $row['status'],
                'items' => []
            ];
        }
        $grouped_fuel_pos[$po_num]['items'][] = [
            'id' => $row['id'],
            'fuel_type' => $row['fuel_type_name'] ?: 'Fuel',
            'ordered_qty' => (float)$row['volume']
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

include __DIR__ . '/../partials/header.php';
?>

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
    top: 80px !important;
    right: 20px !important;
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
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
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
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Pending POs</div>
                <div style="font-size:24px; font-weight:800; color:#1e293b; margin-top:2px;"><?= $count_pending_merch_pos ?></div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; display:flex; align-items:center; gap:16px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div style="width:48px; height:48px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#22c55e; font-size:20px;">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">Deliveries Today</div>
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
            <i class="fas fa-clipboard-list"></i> Pending Purchase Orders
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Purchase Request No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Expected Delivery</th>
                        <th style="text-align:center; width:120px;">Total Products</th>
                        <th style="text-align:center; width:140px;">Status</th>
                        <th style="text-align:center; width:150px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_merch_pos as $po): ?>
                    <tr>
                        <td style="font-weight:700; font-family:monospace; color:#0f172a;"><?= htmlspecialchars($po['po_number']) ?></td>
                        <td style="font-family:monospace; color:#64748b;"><?= htmlspecialchars($po['po_number']) ?></td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="font-weight:600; color:#334155;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                        <td style="text-align:center; font-weight:700; color:#002F70;"><?= count($po['items']) ?></td>
                        <td style="text-align:center;">
                            <span class="status-badge status-waiting"><i class="fas fa-clock"></i> Waiting Delivery</span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn primary" 
                                    onclick='openMerchDeliveryModal(<?= json_encode($po) ?>)'
                                    style="padding:6px 12px; font-size:11.5px; font-weight:700;">
                                <i class="fas fa-file-signature"></i> Record Delivery
                            </button>
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
    
    <?php if (empty($grouped_fuel_pos)): ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; text-align:center; padding:60px 20px; color:#64748b; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <i class="fas fa-gas-pump" style="font-size:54px; color:#cbd5e1; margin-bottom:16px;"></i>
        <h3 style="font-size:18px; font-weight:800; color:#1e293b; margin:0 0 8px 0;">⛽ No Pending Fuel Deliveries</h3>
        <p style="font-size:13px; margin:0; color:#64748b;">All approved fuel purchase orders have already been recorded.</p>
    </div>
    <?php else: ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="padding:14px 20px; background:#002F70; color:#fff; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-clipboard-list"></i> Pending Fuel Purchase Orders
        </div>
        <div style="overflow-x:auto;">
            <table class="sr-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">PO No.</th>
                        <th style="text-align:left;">Supplier</th>
                        <th style="text-align:left;">Fuel Type</th>
                        <th style="text-align:right; width:150px;">Ordered Liters</th>
                        <th style="text-align:left;">Expected Delivery</th>
                        <th style="text-align:center; width:140px;">Status</th>
                        <th style="text-align:center; width:150px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_fuel_pos as $po): 
                        $fuel_types = implode(', ', array_column($po['items'], 'fuel_type'));
                        $total_liters = array_sum(array_column($po['items'], 'ordered_qty'));
                    ?>
                    <tr>
                        <td style="font-weight:700; font-family:monospace; color:#0f172a;"><?= htmlspecialchars($po['po_number']) ?></td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($po['supplier_name']) ?></td>
                        <td style="font-weight:700; color:#0284c7;"><?= htmlspecialchars($fuel_types) ?></td>
                        <td style="text-align:right; font-weight:800; font-family:monospace; color:#0f172a;"><?= number_format($total_liters) ?> L</td>
                        <td style="font-weight:600; color:#334155;"><?= $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—' ?></td>
                        <td style="text-align:center;">
                            <span class="status-badge status-waiting"><i class="fas fa-clock"></i> Waiting Delivery</span>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn primary" 
                                    onclick='openFuelDeliveryModal(<?= json_encode($po) ?>)'
                                    style="padding:6px 12px; font-size:11.5px; font-weight:700;">
                                <i class="fas fa-file-signature"></i> Record Delivery
                            </button>
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
     MERCHANDISE RECORD DELIVERY MODAL
     ========================================================================= -->
<div id="merchDeliveryModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-boxes"></i> Record Merchandise Delivery</h3>
        </div>
        <form method="POST" action="staff_record_delivery.php?tab=merchandise">
            <input type="hidden" name="action" value="record_merchandise">
            <input type="hidden" name="pr_id" id="m_po_id">
            
            <div class="modal-body">
                <!-- 1. Purchase Order Information (Read Only) -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-file-alt"></i> Purchase Order Information (Read Only)
                    </h4>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; font-size:12px;">
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Purchase Order No:</span>
                            <strong style="color:#0f172a;" id="lbl_m_po_no"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Purchase Request No:</span>
                            <strong style="color:#0f172a;" id="lbl_m_pr_no"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Supplier:</span>
                            <strong style="color:#0f172a;" id="lbl_m_supplier"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Purchase Order Date:</span>
                            <strong style="color:#0f172a;" id="lbl_m_po_date"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Expected Delivery Date:</span>
                            <strong style="color:#0f172a;" id="lbl_m_expected_date"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Prepared By (Manager):</span>
                            <strong style="color:#0f172a;" id="lbl_m_prepared_by"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Approved By:</span>
                            <strong style="color:#0f172a;" id="lbl_m_approved_by"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Status:</span>
                            <strong style="color:#10b981;" id="lbl_m_status"></strong>
                        </div>
                    </div>
                </div>

                <!-- 2. Delivery Information -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-truck"></i> Delivery Information
                    </h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Delivery Date <span>*</span></label>
                            <input type="date" name="delivery_date" id="m_delivery_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Receipt No. <span>*</span></label>
                            <input type="text" name="dr_number" id="m_dr_number" class="form-control" required placeholder="e.g. DR-12345">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Invoice No. <span>*</span></label>
                            <input type="text" name="invoice_number" id="m_invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Batch No.</label>
                            <input type="text" name="delivery_batch_no" id="m_delivery_batch_no" class="form-control" placeholder="e.g. BATCH-A1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" id="m_driver_name" class="form-control" placeholder="e.g. Driver Name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Vehicle Plate No.</label>
                            <input type="text" name="vehicle_plate_no" id="m_vehicle_plate_no" class="form-control" placeholder="e.g. ABC 1234">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Received By (Auto)</label>
                            <input type="text" class="form-control" readonly value="<?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="m_remarks" class="form-control" placeholder="Optional notes..."></textarea>
                    </div>
                </div>

                <!-- 3. Received Products -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-boxes"></i> Received Products
                    </h4>
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1;">
                                <th style="padding:10px; text-align:left; color:#475569; font-weight:700; width:45%;">Product</th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:15%;">Ordered Qty</th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:15%;">Received Qty <span style="color:#ef4444;">*</span></th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:10%;">Unit</th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:15%;">Condition</th>
                            </tr>
                        </thead>
                        <tbody id="merch_delivery_items_body">
                            <!-- Dynamic rows -->
                        </tbody>
                    </table>
                </div>

                <!-- 4. Delivery Summary -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-poll-h"></i> Delivery Summary
                    </h4>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; font-size:13px; font-weight:700;">
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Total Ordered Qty:</span>
                            <span style="color:#0f172a; font-size:15px;" id="summary_total_ordered">0.00</span>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Total Received Qty:</span>
                            <span style="color:#0f172a; font-size:15px;" id="summary_total_received">0.00</span>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Variance:</span>
                            <span style="color:#e11d48; font-size:15px;" id="summary_variance">0.00</span>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Delivery Status:</span>
                            <span style="font-size:15px;" id="summary_delivery_status">Complete</span>
                        </div>
                    </div>
                </div>
            </div><!-- end .modal-body -->

            <!-- ── Sticky Footer (always visible) ── -->
            <div class="modal-footer">
                <div class="footer-actions">
                    <button type="button" class="txn-btn secondary" onclick="closeModal('merchDeliveryModal')">Cancel</button>
                    <button type="submit" class="txn-btn primary"><i class="fas fa-save"></i> Save Delivery</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     FUEL RECORD DELIVERY MODAL
     ========================================================================= -->
<div id="fuelDeliveryModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-gas-pump"></i> Record Fuel Delivery</h3>
        </div>
        <form method="POST" action="staff_record_delivery.php?tab=fuel">
            <input type="hidden" name="action" value="record_fuel">
            <input type="hidden" name="po_number" id="f_po_number">
            
            <div class="modal-body">
                <!-- 1. Purchase Order Information -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-file-alt"></i> Purchase Order Information
                    </h4>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; font-size:12px;">
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Purchase Order No:</span>
                            <strong style="color:#0f172a;" id="lbl_f_po_no"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Supplier:</span>
                            <strong style="color:#0f172a;" id="lbl_f_supplier"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Purchase Order Date:</span>
                            <strong style="color:#0f172a;" id="lbl_f_po_date"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Expected Delivery Date:</span>
                            <strong style="color:#0f172a;" id="lbl_f_expected_date"></strong>
                        </div>
                        <div>
                            <span style="color:#64748b; font-weight:600; display:block;">Approved By:</span>
                            <strong style="color:#0f172a;" id="lbl_f_approved_by"></strong>
                        </div>
                    </div>
                </div>

                <!-- 2. Delivery Information -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-truck"></i> Delivery Information
                    </h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Delivery Date <span>*</span></label>
                            <input type="date" name="delivery_date" id="f_delivery_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Receipt No. <span>*</span></label>
                            <input type="text" name="dr_number" id="f_dr_number" class="form-control" required placeholder="e.g. DR-12345">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Invoice No. <span>*</span></label>
                            <input type="text" name="invoice_number" id="f_invoice_number" class="form-control" required placeholder="e.g. INV-98765">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tanker No. <span>*</span></label>
                            <input type="text" name="tanker_number" id="f_tanker_number" class="form-control" required placeholder="e.g. TNK-7890">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Driver Name</label>
                            <input type="text" name="driver_name" id="f_driver_name" class="form-control" placeholder="e.g. Driver Name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Received By (Auto)</label>
                            <input type="text" class="form-control" readonly value="<?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="f_remarks" class="form-control" placeholder="Optional notes..."></textarea>
                    </div>
                </div>

                <!-- 3. Fuel Received -->
                <div style="margin-bottom:20px;">
                    <h4 style="margin:0 0 12px; font-size:12.5px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                        <i class="fas fa-gas-pump"></i> Fuel Received
                    </h4>
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1;">
                                <th style="padding:10px; text-align:left; color:#475569; font-weight:700; width:50%;">Fuel Type</th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:25%;">Ordered Liters</th>
                                <th style="padding:10px; text-align:center; color:#475569; font-weight:700; width:25%;">Received Liters <span style="color:#ef4444;">*</span></th>
                            </tr>
                        </thead>
                        <tbody id="fuel_delivery_items_body">
                            <!-- Dynamic rows -->
                        </tbody>
                    </table>
                </div>
            </div><!-- end .modal-body -->

            <!-- ── Sticky Footer (always visible) ── -->
            <div class="modal-footer">
                <div class="footer-actions">
                    <button type="button" class="txn-btn secondary" onclick="closeModal('fuelDeliveryModal')">Cancel</button>
                    <button type="submit" class="txn-btn primary"><i class="fas fa-save"></i> Save Delivery</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Professional Toast Notification
function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    const title = type === 'success' ? 'Success' : 'Error';
    
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
    `;
    
    container.appendChild(toast);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        toast.style.animation = 'slideInRight 0.3s ease-out reverse';
        setTimeout(() => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 300);
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

// Close Modal
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
    document.body.classList.remove('modal-open');
}

// Esc key closes modals
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
    }
});

// Dynamic Populators
function openMerchDeliveryModal(po) {
    document.getElementById('m_po_id').value = po.id;
    document.getElementById('lbl_m_po_no').innerText = po.po_number;
    document.getElementById('lbl_m_pr_no').innerText = po.po_number;
    document.getElementById('lbl_m_supplier').innerText = po.supplier_name;
    document.getElementById('lbl_m_po_date').innerText = new Date(po.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
    document.getElementById('lbl_m_expected_date').innerText = po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : '—';
    document.getElementById('lbl_m_prepared_by').innerText = po.prepared_by_name || 'Manager';
    document.getElementById('lbl_m_approved_by').innerText = po.approved_by_name || 'Admin';
    document.getElementById('lbl_m_status').innerText = po.status;

    // Reset inputs
    document.getElementById('m_dr_number').value = '';
    document.getElementById('m_invoice_number').value = '';
    document.getElementById('m_delivery_batch_no').value = '';
    document.getElementById('m_driver_name').value = '';
    document.getElementById('m_vehicle_plate_no').value = '';
    document.getElementById('m_remarks').value = '';
    document.getElementById('m_delivery_date').value = new Date().toISOString().split('T')[0];

    const tbody = document.getElementById('merch_delivery_items_body');
    tbody.innerHTML = '';
    
    po.items.forEach(item => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #e2e8f0';
        tr.innerHTML = `
            <td style="padding:10px; font-weight:600; color:#1e293b;">
                ${item.product_name}
                <div style="font-size:10px; color:#94a3b8; font-family:monospace;">SKU: ${item.sku}</div>
            </td>
            <td style="padding:10px; text-align:center; font-weight:700; color:#3b82f6;">${item.ordered_qty}</td>
            <td style="padding:10px; text-align:center;">
                <input type="number" name="received_qty[${item.item_id}]" class="form-control m-received-qty-inp"
                       style="width:90px; text-align:center; margin:0 auto; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-weight:700;"
                       step="0.01" min="0" value="" placeholder="0" required oninput="calculateMerchSummary()">
                <input type="hidden" class="m-ordered-qty" value="${item.ordered_qty}">
            </td>
            <td style="padding:10px; text-align:center; color:#64748b; font-weight:600;">${item.unit}</td>
            <td style="padding:10px; text-align:center;">
                <input type="text" name="condition[${item.item_id}]" class="form-control m-condition-inp"
                       style="width:110px; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-weight:700;"
                       value="" placeholder="Good" oninput="calculateMerchSummary()">
            </td>
        `;
        tbody.appendChild(tr);
    });

    calculateMerchSummary();
    var modal = document.getElementById('merchDeliveryModal');
    document.body.appendChild(modal);
    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function calculateMerchSummary() {
    let totalOrdered = 0;
    let totalReceived = 0;
    let hasDamaged = false;

    const orderedInps = document.querySelectorAll('.m-ordered-qty');
    const receivedInps = document.querySelectorAll('.m-received-qty-inp');
    const conditionInps = document.querySelectorAll('.m-condition-inp');

    for (let i = 0; i < orderedInps.length; i++) {
        const ordVal = parseFloat(orderedInps[i].value) || 0;
        const recVal = parseFloat(receivedInps[i].value) || 0;
        const condVal = conditionInps[i].value.trim();

        totalOrdered += ordVal;
        totalReceived += recVal;
        const condValLower = condVal.toLowerCase();
        if (condValLower !== 'good' && condValLower !== '') {
            hasDamaged = true;
        }
    }

    const variance = totalOrdered - totalReceived;

    document.getElementById('summary_total_ordered').innerText = totalOrdered.toFixed(2);
    document.getElementById('summary_total_received').innerText = totalReceived.toFixed(2);
    document.getElementById('summary_variance').innerText = variance.toFixed(2);

    let statusText = 'Complete';
    let statusColor = '#16a34a';
    
    if (hasDamaged) {
        statusText = 'Damaged Items';
        statusColor = '#dc2626';
    } else if (variance > 0) {
        statusText = 'Shortage';
        statusColor = '#d97706';
    } else if (variance < 0) {
        statusText = 'Excess';
        statusColor = '#2563eb';
    }

    const statusBadge = document.getElementById('summary_delivery_status');
    statusBadge.innerText = statusText;
    statusBadge.style.color = statusColor;
}

function openFuelDeliveryModal(po) {
    document.getElementById('f_po_number').value = po.po_number;
    document.getElementById('lbl_f_po_no').innerText = po.po_number;
    document.getElementById('lbl_f_supplier').innerText = po.supplier_name;
    document.getElementById('lbl_f_po_date').innerText = new Date(po.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
    document.getElementById('lbl_f_expected_date').innerText = po.expected_delivery_date ? new Date(po.expected_delivery_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : '—';
    document.getElementById('lbl_f_approved_by').innerText = po.approved_by || 'Manager';

    // Reset inputs
    document.getElementById('f_dr_number').value = '';
    document.getElementById('f_invoice_number').value = '';
    document.getElementById('f_tanker_number').value = '';
    document.getElementById('f_driver_name').value = '';
    document.getElementById('f_remarks').value = '';
    document.getElementById('f_delivery_date').value = new Date().toISOString().split('T')[0];

    const tbody = document.getElementById('fuel_delivery_items_body');
    tbody.innerHTML = '';

    po.items.forEach(item => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #e2e8f0';
        tr.innerHTML = `
            <td style="padding:10px; font-weight:700; color:#1e293b;">${item.fuel_type}</td>
            <td style="padding:10px; text-align:center; font-weight:700; color:#3b82f6;">${item.ordered_qty.toLocaleString()} L</td>
            <td style="padding:10px; text-align:center;">
                <div style="position:relative; display:inline-block; width:130px;">
                    <input type="number" name="received_liters[${item.id}]" class="form-control"
                           style="width:100%; text-align:right; padding:6px 24px 6px 6px; border:1px solid #cbd5e1; border-radius:6px; font-weight:700;"
                           step="0.01" min="0" value="" placeholder="0" required>
                    <span style="position:absolute; right:8px; top:50%; transform:translateY(-50%); font-size:11px; color:#94a3b8; font-weight:700;">L</span>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    var fuelModal = document.getElementById('fuelDeliveryModal');
    document.body.appendChild(fuelModal);
    fuelModal.style.display = 'flex';
    document.body.classList.add('modal-open');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
