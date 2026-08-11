<?php
/**
 * GET TRANSACTION DETAILS
 * 
 * Fetches complete transaction details for View modal
 * Supports both merchandise_transactions and job_orders
 * 
 * Parameters:
 * - $_GET['type'] - 'merchandise_transactions' or 'job_orders'
 * - $_GET['id'] - Transaction ID
 * 
 * Returns: JSON
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';

// Verify login
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get parameters
$type = trim($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    if ($type === 'merchandise_transactions') {
        // Fetch merchandise transaction details
        $stmt = $pdo->prepare("
            SELECT 
                mt.id,
                mt.transaction_id,
                mt.customer_name,
                mt.item_sku,
                mt.quantity,
                mt.unit_price,
                mt.total_amount,
                mt.payment_method,
                mt.transaction_date,
                mt.created_at,
                mt.validation_status,
                mt.validated_at,
                mt.rejection_reason,
                mt.adjustment_reason,
                mt.remarks,
                mt.shift_period,
                mt.shift_name,
                mt.amount_tendered,
                mt.change_amount,
                mt.card_reference,
                mt.card_type,
                mt.ewallet_reference,
                mt.ewallet_provider,
                mt.subtotal_amount,
                mt.vat_amount,
                mt.transaction_type,
                mt.job_order_service,
                mt.job_order_vehicle_plate,
                mt.job_order_vehicle_type,
                mt.job_order_mechanic_name,
                mt.job_order_description,
                COALESCE(NULLIF(CONCAT(u_staff.first_name,' ',u_staff.last_name),' '), u_staff.username, 'Unknown') AS staff_name,
                COALESCE(NULLIF(CONCAT(u_validated.first_name,' ',u_validated.last_name),' '), u_validated.username, 'N/A') AS validated_by_name
            FROM merchandise_transactions mt
            LEFT JOIN users u_staff ON u_staff.id = mt.staff_id
            LEFT JOIN users u_validated ON u_validated.id = mt.validated_by
            WHERE mt.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            exit;
        }

        // Fetch detailed items and real SKUs from products table
        $items_stmt = $pdo->prepare("
            SELECT mti.product_id, mti.product_name, mti.category, mti.size_variant,
                   mti.quantity, mti.unit_price, mti.subtotal,
                   COALESCE(p.sku, '') AS real_sku
            FROM merchandise_transaction_items mti
            LEFT JOIN products p ON p.id = mti.product_id
            WHERE mti.transaction_id = ?
        ");
        $items_stmt->execute([$row['id']]);
        $items_rows = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $sku_list = [];
        $items_breakdown = [];
        if (!empty($items_rows)) {
            foreach ($items_rows as $it) {
                $item_sku_code = !empty($it['real_sku']) ? $it['real_sku'] : ('SKU-' . $it['product_id']);
                $sku_list[] = $item_sku_code;
                $items_breakdown[] = [
                    'sku'          => $item_sku_code,
                    'product_name' => $it['product_name'],
                    'category'     => $it['category'] ?: 'Merchandise',
                    'quantity'     => (float)$it['quantity'],
                    'unit_price'   => number_format((float)$it['unit_price'], 2),
                    'subtotal'     => number_format((float)$it['subtotal'], 2),
                ];
            }
        }
        $formatted_item_sku = !empty($sku_list) ? implode(', ', array_unique($sku_list)) : ($row['item_sku'] ?: 'N/A');

        // Intelligent fallbacks for Change, Validated By, and Validated At
        $tendered = (float)($row['amount_tendered'] ?? 0);
        $total    = (float)($row['total_amount'] ?? 0);
        
        $change_calc = '0.00';
        if (isset($row['change_amount']) && $row['change_amount'] !== null && $row['change_amount'] !== '') {
            $change_calc = number_format((float)$row['change_amount'], 2);
        } elseif ($tendered > 0 && $tendered >= $total) {
            $change_calc = number_format(max(0, $tendered - $total), 2);
        }

        $validated_by = (!empty($row['validated_by_name']) && $row['validated_by_name'] !== 'N/A') 
            ? $row['validated_by_name'] 
            : (!empty($row['staff_name']) ? $row['staff_name'] : 'System Staff');

        $validated_at = (!empty($row['validated_at']) && $row['validated_at'] > '2000-01-01')
            ? date('M d, Y h:i A', strtotime($row['validated_at'])) 
            : date('M d, Y h:i A', strtotime($row['transaction_date'] > '2000-01-01' ? $row['transaction_date'] : $row['created_at']));

        // Format response for merchandise transaction
        $is_jo_type = (in_array(strtolower($row['transaction_type'] ?? ''), ['job_order', 'combined']) || !empty(trim($row['job_order_service'] ?? '')));
        echo json_encode([
            'success' => true,
            'type' => $is_jo_type ? 'job_order' : 'merchandise',
            'transaction_id' => $row['transaction_id'],
            'customer_name' => $row['customer_name'] ?: 'Walk-in',
            'item_sku' => $formatted_item_sku,
            'items_breakdown' => $items_breakdown,
            'quantity' => $row['quantity'],
            'unit_price' => number_format((float)$row['unit_price'], 2),
            'total_amount' => number_format((float)$row['total_amount'], 2),
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['validation_status'] ?: 'Paid',
            'transaction_date' => date('M d, Y h:i A', strtotime($row['transaction_date'] > '2000-01-01' ? $row['transaction_date'] : $row['created_at'])),
            'validation_status' => $row['validation_status'],
            'job_status' => $row['validation_status'] ?: 'Completed',
            'validated_at' => $validated_at,
            'rejection_reason' => $row['rejection_reason'] ?: 'N/A',
            'adjustment_reason' => $row['adjustment_reason'] ?: 'N/A',
            'remarks' => $row['remarks'] ?: 'N/A',
            'shift' => $row['shift_name'] ?: $row['shift_period'] ?: 'N/A',
            'amount_tendered' => $tendered > 0 ? number_format($tendered, 2) : 'N/A',
            'amount_paid' => $tendered > 0 ? number_format($tendered, 2) : number_format((float)$row['total_amount'], 2),
            'change_amount' => $change_calc,
            'card_reference' => $row['card_reference'] ?: 'N/A',
            'card_type' => $row['card_type'] ?: 'N/A',
            'ewallet_reference' => $row['ewallet_reference'] ?: 'N/A',
            'ewallet_provider' => $row['ewallet_provider'] ?: 'N/A',
            'subtotal_amount' => $row['subtotal_amount'] ? number_format((float)$row['subtotal_amount'], 2) : 'N/A',
            'vat_amount' => $row['vat_amount'] ? number_format((float)$row['vat_amount'], 2) : 'N/A',
            'transaction_type' => $row['transaction_type'] ?? 'merchandise',
            'service_type' => $row['job_order_service'] ?: 'N/A',
            'vehicle_plate' => $row['job_order_vehicle_plate'] ?: 'N/A',
            'vehicle_type' => $row['job_order_vehicle_type'] ?: 'N/A',
            'mechanic_name' => $row['job_order_mechanic_name'] ?: 'Station Mechanic',
            'service_description' => $row['job_order_description'] ?: 'N/A',
            'required_parts' => 'N/A',
            'estimated_cost' => number_format((float)$row['total_amount'], 2),
            'job_order_service' => $row['job_order_service'] ?? '',
            'job_order_vehicle_plate' => $row['job_order_vehicle_plate'] ?? '',
            'job_order_vehicle_type' => $row['job_order_vehicle_type'] ?? '',
            'job_order_mechanic_name' => $row['job_order_mechanic_name'] ?? '',
            'job_order_description' => $row['job_order_description'] ?? '',
            'staff_name' => $row['staff_name'] ?: 'Unknown',
            'validated_by' => $validated_by,
            'adjustment_history' => (function() use ($pdo, $row, $id) {
                try {
                    $s = $pdo->prepare("SELECT ah.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'Manager') AS approved_by_name FROM adjustment_history ah LEFT JOIN users u ON u.id=ah.approved_by WHERE ah.transaction_db_id=? OR ah.transaction_id=? ORDER BY ah.created_at DESC LIMIT 10");
                    $s->execute([$id, $row['transaction_id'] ?? '']);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'audit_trail' => (function() use ($pdo, $row) {
                try {
                    $s = $pdo->prepare("SELECT at.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'System') AS user_name FROM audit_trail at LEFT JOIN users u ON u.id=at.user_id WHERE at.transaction_id=? ORDER BY at.created_at DESC LIMIT 20");
                    $s->execute([$row['transaction_id'] ?? '']);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'ar_record' => (function() use ($pdo, $id, $row) {
                try {
                    $s = $pdo->prepare("SELECT * FROM customer_accounts_receivable WHERE transaction_db_id=? OR transaction_id=? LIMIT 1");
                    $s->execute([$id, $row['transaction_id'] ?? '']);
                    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Exception $e) { return null; }
            })()
        ]);
        
    } elseif ($type === 'job_orders') {
        // Fetch job order details
        $stmt = $pdo->prepare("
            SELECT 
                jo.id,
                jo.job_order_number,
                jo.customer_name,
                jo.vehicle_plate,
                jo.vehicle_type,
                jo.service_type,
                jo.service_description,
                jo.required_parts,
                jo.additional_notes,
                jo.estimated_cost,
                jo.total_cost,
                jo.amount_paid,
                jo.sukli,
                jo.payment_method,
                jo.payment_status,
                jo.validation_status,
                jo.created_at,
                jo.validated_at,
                jo.adjustment_reason,
                jo.status,
                jo.notes,
                jo.service_price_details,
                COALESCE(NULLIF(CONCAT(u_staff.first_name,' ',u_staff.last_name),' '), u_staff.username, 'Unknown') AS staff_name,
                COALESCE(NULLIF(CONCAT(u_validated.first_name,' ',u_validated.last_name),' '), u_validated.username, 'N/A') AS validated_by_name,
                COALESCE(NULLIF(CONCAT(mech.first_name,' ',mech.last_name),' '), mech.username, 'Not assigned') AS mechanic_name
            FROM job_orders jo
            LEFT JOIN users u_staff ON u_staff.id = COALESCE(jo.created_by, jo.user_id)
            LEFT JOIN users u_validated ON u_validated.id = jo.validated_by
            LEFT JOIN users mech ON mech.id = jo.assigned_mechanic_id
            WHERE jo.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Job order not found']);
            exit;
        }
        
        // Parse required parts JSON
        $required_parts = 'N/A';
        if ($row['required_parts']) {
            $parts = json_decode($row['required_parts'], true);
            if (is_array($parts) && count($parts) > 0) {
                $parts_list = [];
                foreach ($parts as $part) {
                    $part_name = $part['name'] ?? 'Unknown';
                    $part_qty = $part['qty'] ?? 1;
                    $parts_list[] = "{$part_name} (Qty: {$part_qty})";
                }
                $required_parts = implode(', ', $parts_list);
            }
        }
        
        $jo_paid  = (float)($row['amount_paid'] ?? 0);
        $jo_total = (float)($row['total_cost'] ?: $row['estimated_cost'] ?: 0);
        $jo_sukli = (float)($row['sukli'] ?? 0);
        if ($jo_sukli <= 0 && $jo_paid > $jo_total) {
            $jo_sukli = $jo_paid - $jo_total;
        }

        $jo_validated_by = (!empty($row['validated_by_name']) && $row['validated_by_name'] !== 'N/A')
            ? $row['validated_by_name']
            : (!empty($row['staff_name']) ? $row['staff_name'] : 'System Staff');

        $jo_validated_at = (!empty($row['validated_at']) && $row['validated_at'] > '2000-01-01')
            ? date('M d, Y h:i A', strtotime($row['validated_at']))
            : date('M d, Y h:i A', strtotime($row['created_at']));

        // Format response for job order
        echo json_encode([
            'success' => true,
            'type' => 'job_order',
            'transaction_id' => $row['job_order_number'] ?: "JO-{$id}",
            'customer_name' => $row['customer_name'] ?: 'Walk-in',
            'vehicle_plate' => $row['vehicle_plate'] ?: 'N/A',
            'vehicle_type' => $row['vehicle_type'] ?: 'N/A',
            'service_type' => $row['service_type'],
            'service_description' => $row['service_description'] ?: 'N/A',
            'required_parts' => $required_parts,
            'additional_notes' => $row['additional_notes'] ?: $row['notes'] ?: 'N/A',
            'estimated_cost' => number_format((float)($row['estimated_cost'] ?: 0), 2),
            'total_amount' => number_format($jo_total, 2),
            'amount_paid' => number_format($jo_paid, 2),
            'change_amount' => number_format($jo_sukli, 2),
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'validation_status' => $row['validation_status'],
            'job_status' => $row['status'] ?: 'Pending',
            'transaction_date' => date('M d, Y h:i A', strtotime($row['created_at'])),
            'validated_at' => $jo_validated_at,
            'adjustment_reason' => $row['adjustment_reason'] ?: 'N/A',
            'staff_name' => $row['staff_name'] ?: 'Unknown',
            'validated_by' => $jo_validated_by,
            'mechanic_name' => $row['mechanic_name'] ?: 'Not assigned',
            'audit_trail' => (function() use ($pdo, $row, $id) {
                try {
                    $jo_no = $row['job_order_number'] ?? 'JO-'.$id;
                    $s = $pdo->prepare("SELECT at.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username,'System') AS user_name FROM audit_trail at LEFT JOIN users u ON u.id=at.user_id WHERE at.transaction_id=? ORDER BY at.created_at DESC LIMIT 20");
                    $s->execute([$jo_no]);
                    return $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { return []; }
            })(),
            'adjustment_history' => [],
            'ar_record' => null
        ]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid transaction type']);
    }
    
} catch (Exception $e) {
    error_log("Transaction details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
