<?php
$page_id = 'mgr_stock_review';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST Actions (Approve & Generate PO / Reject / Revision / Remarks)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. Approve & Generate PO
    if ($action === 'approve_generate_po') {
        $req_id            = (int)($_POST['request_id'] ?? 0);
        $approved_qty      = (int)($_POST['approved_quantity'] ?? 0);
        $po_number         = trim($_POST['po_number'] ?? '');
        $supplier_name     = trim($_POST['supplier_name'] ?? 'Petron Regional Depot');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $unit_cost         = (float)($_POST['unit_cost'] ?? 0);
        $notes             = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && $approved_qty > 0) {
            try {
                $pdo->beginTransaction();
                
                // Fetch request
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Purchase request not found.");
                
                if (empty($po_number)) {
                    $po_number = "PO-2026-" . str_pad($req_id, 5, '0', STR_PAD_LEFT);
                }
                if (empty($expected_delivery)) {
                    $expected_delivery = date('Y-m-d', strtotime('+2 days'));
                }
                if ($unit_cost <= 0) {
                    $stmt = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ?");
                    $stmt->execute([$req['item_id']]);
                    $unit_cost = (float)($stmt->fetchColumn() ?: 145.00);
                }
                $subtotal = $approved_qty * $unit_cost;
                
                // Resolve supplier_id from name
                $stmt_sup = $pdo->prepare("SELECT id FROM suppliers WHERE name LIKE ? OR id = 1 LIMIT 1");
                $stmt_sup->execute(['%' . $supplier_name . '%']);
                $supplier_id = (int)($stmt_sup->fetchColumn() ?: 1);

                // Check existing PO or insert new
                $stmtPO = $pdo->prepare("SELECT id FROM purchase_orders WHERE request_id = ? AND type = 'merch'");
                $stmtPO->execute([$req_id]);
                $existPO = $stmtPO->fetchColumn();
                
                if ($existPO) {
                    $pdo->prepare("
                        UPDATE purchase_orders 
                        SET quantity = ?, unit_price = ?, total_amount = ?, po_number = ?, supplier_id = ?, expected_delivery_date = ?, status = 'Pending Admin Validation', remarks = ?, updated_at = NOW()
                        WHERE id = ?
                    ")->execute([
                        $approved_qty, $unit_cost, $subtotal, $po_number, $supplier_id, $expected_delivery, $notes, $existPO
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO purchase_orders 
                            (request_id, product_name, quantity, unit_price, total_amount, type, po_number, station_id, supplier_id, expected_delivery_date, created_by, status, remarks, created_at, updated_at, admin_finalized)
                        VALUES (?, ?, ?, ?, ?, 'merch', ?, ?, ?, ?, ?, 'Pending Admin Validation', ?, NOW(), NOW(), 0)
                    ")->execute([
                        $req_id, $req['item_name'], $approved_qty, $unit_cost, $subtotal, $po_number, $station_id, $supplier_id, $expected_delivery, $me['id'], $notes
                    ]);
                }
                
                // Update Stock Request status to 'Approved by Manager'
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Approved by Manager', approved_quantity = ?, manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $approved_qty, $me['id'], $notes, $req_id
                ]);
                
                // Audit log & Activity log
                $audit_note = "Manager approved qty={$approved_qty}. PO: {$po_number}. Forwarded to Admin.";
                if ($notes) $audit_note .= " Notes: {$notes}";
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Approved & PO Generated', ?, ?, ?, 'Approved by Manager', ?)
                ")->execute([$req_id, $me['id'], $role, $req['status'], $audit_note]);
                
                log_activity($pdo, $me['id'], 'Approve & Generate PO', "Request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " | {$req['item_name']} | PO: {$po_number} generated");
                
                $pdo->commit();
                $_SESSION['success'] = "Purchase Order Created &mdash; {$po_number} | Status: Pending Admin Approval";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid quantity or request ID.';
        }
        header('Location: manager_stock_request_review.php'); exit;
    }

    // 2. Reject Request
    if ($action === 'reject_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && !empty($notes)) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Purchase request not found.");
                
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Rejected', manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $me['id'], $notes, $req_id
                ]);
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Rejected', ?, ?, ?, 'Rejected', ?)
                ")->execute([$req_id, $me['id'], $role, $req['status'], "Rejected by {$me['name']}. Reason: {$notes}"]);
                
                log_activity($pdo, $me['id'], 'Reject Purchase Request', "Request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " rejected by {$me['name']}");
                
                $pdo->commit();
                $_SESSION['success'] = "Purchase request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " rejected.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
        header('Location: manager_stock_request_review.php'); exit;
    }

    // 3. Adjust Request
    if ($action === 'adjust_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $adjusted_qty = (int)($_POST['adjusted_quantity'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        if ($req_id > 0 && $adjusted_qty > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$req) throw new Exception("Purchase request not found.");

                $old_qty = $req['requested_quantity'];

                $pdo->prepare("
                    UPDATE stock_requests 
                    SET requested_quantity = ?, manager_id = ?, manager_notes = ?, updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([$adjusted_qty, $me['id'], $notes, $req_id, $station_id]);
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Adjusted Quantity', ?, ?, ?, ?, ?)
                ")->execute([$req_id, $me['id'], $role, $req['status'], $req['status'], "Quantity adjusted from {$old_qty} to {$adjusted_qty} by {$me['name']}. Notes: {$notes}"]);

                log_activity($pdo, $me['id'], 'Adjust Purchase Request', "Adjusted REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " quantity from {$old_qty} to {$adjusted_qty}");
                $_SESSION['success'] = "Purchase request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " quantity adjusted to {$adjusted_qty}.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error adjusting request: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Valid adjusted quantity is required.';
        }
        header('Location: manager_stock_request_review.php'); exit;
    }

    // 4. Add Remarks / Update Notes
    if ($action === 'add_remarks') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        if ($req_id > 0) {
            try {
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET manager_notes = ?, updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([$remarks, $req_id, $station_id]);
                
                log_activity($pdo, $me['id'], 'Update Purchase Request Remarks', "Remarks updated for Request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT));
                $_SESSION['success'] = "Remarks updated successfully for Request REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . ".";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating remarks: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid request ID.';
        }
        header('Location: manager_stock_request_review.php'); exit;
    }

    // 5. Generate Fuel Purchase Order / Approve Fuel Request
    if ($action === 'fuel_generate_po') {
        $req_id            = (int)($_POST['request_id'] ?? 0);
        $approved_liters   = (float)($_POST['approved_liters'] ?? 0);
        $unit_cost         = (float)($_POST['unit_cost'] ?? 0);
        $po_number         = trim($_POST['po_number'] ?? '');
        $expected_delivery = trim($_POST['expected_delivery'] ?? '');
        $notes             = trim($_POST['manager_notes'] ?? '');

        if ($req_id > 0 && $approved_liters > 0) {
            try {
                $pdo->beginTransaction();

                // Fetch request
                $stmt = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$req) {
                    throw new Exception("Fuel request not found.");
                }

                if (empty($po_number)) {
                    $po_number = "POF-2026-" . str_pad($req_id, 5, '0', STR_PAD_LEFT);
                }
                if (empty($expected_delivery)) {
                    $expected_delivery = date('Y-m-d', strtotime('+2 days'));
                }

                $subtotal = $approved_liters * $unit_cost;

                // Get or create Petron supplier ID
                $supplier_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 1;

                // Resolve fuel_type_id
                $ft_stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                $ft_stmt->execute([$req['fuel_type']]);
                $fuel_type_id = (int)($ft_stmt->fetchColumn() ?: 0);
                
                // If fuel type not found, create it
                if ($fuel_type_id === 0) {
                    $pdo->prepare("INSERT INTO fuel_types (name) VALUES (?)")->execute([$req['fuel_type']]);
                    $fuel_type_id = (int)$pdo->lastInsertId();
                }
                
                // Ensure we have a valid fuel_type_id
                if ($fuel_type_id === 0) {
                    throw new Exception("Unable to resolve or create fuel type: " . $req['fuel_type']);
                }

                // Check if existing PO exists
                $stmtPO = $pdo->prepare("SELECT id FROM fuel_purchase_orders WHERE po_number = ? AND station_id = ?");
                $stmtPO->execute([$po_number, $station_id]);
                $existPO = $stmtPO->fetchColumn();

                if ($existPO) {
                    $pdo->prepare("
                        UPDATE fuel_purchase_orders 
                        SET volume = ?, unit_price = ?, total_amount = ?, supplier_id = ?, expected_delivery_date = ?, status = 'Pending Admin Validation', notes = ?, updated_at = NOW()
                        WHERE id = ?
                    ")->execute([
                        $approved_liters, $unit_cost, $subtotal, $supplier_id, $expected_delivery, $notes, $existPO
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO fuel_purchase_orders 
                            (po_number, station_id, fuel_type_id, volume, unit_price, total_amount, supplier_id, expected_delivery_date, status, created_by, notes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Admin Validation', ?, ?, NOW(), NOW())
                    ")->execute([
                        $po_number, $station_id, $fuel_type_id, $approved_liters, $unit_cost, $subtotal, $supplier_id, $expected_delivery, $me['id'], $notes
                    ]);
                }

                // Update Fuel Request status to 'Approved'
                $pdo->prepare("
                    UPDATE fuel_stock_requests 
                    SET status = 'Approved', approved_liters = ?, manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $approved_liters, $me['id'], $notes, $req_id
                ]);

                // Audit log & Activity log
                $audit_note = "Approved: {$req['requested_liters']} L → {$approved_liters} L. Unit Cost: ₱{$unit_cost}/L. PO: {$po_number}.";
                if ($notes) $audit_note .= " Notes: {$notes}";

                $pdo->prepare("
                    INSERT INTO fuel_stock_request_audit
                        (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Approved', ?, ?, ?, 'Approved', ?)
                ")->execute([$req_id, $me['id'], $role, $req['status'], $audit_note]);

                // Log to main audit_trail
                try {
                    $pdo->prepare("
                        INSERT INTO audit_trail (transaction_id, manager_id, station_id, action_type, new_value, notes, created_at)
                        VALUES (?, ?, ?, 'Approve Fuel Request', ?, ?, NOW())
                    ")->execute(['FSR-'.$req_id, $me['id'], $station_id, "Approved {$approved_liters} L of {$req['fuel_type']}", $audit_note]);
                } catch (Exception $ignored) {}

                $pdo->commit();
                $_SESSION['success'] = "Fuel request approved & PO generated. {$approved_liters} L of {$req['fuel_type']} &mdash; Pending Admin validation.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Approved liters must be greater than 0.';
        }
        header('Location: manager_stock_request_review.php?subtab=fuel'); exit;
    }

    // 5b. Add Fuel Request Remarks / Review
    if ($action === 'fuel_add_remarks') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        if ($req_id > 0) {
            try {
                $pdo->prepare("
                    UPDATE fuel_stock_requests 
                    SET manager_notes = ?, updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([$remarks, $req_id, $station_id]);

                log_activity($pdo, $me['id'], 'Update Fuel Request Remarks', "Remarks updated for Request FSR-" . str_pad($req_id, 4, '0', STR_PAD_LEFT));
                $_SESSION['success'] = "Remarks updated successfully for Request FSR-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . ".";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating remarks: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid request ID.';
        }
        header('Location: manager_stock_request_review.php?subtab=fuel'); exit;
    }


    // 6. Reject Fuel Stock Request
    if ($action === 'fuel_reject') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $manager_notes = trim($_POST['manager_notes'] ?? '');

        if ($req_id > 0 && !empty($manager_notes)) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($req && strtolower($req['status']) === 'pending') {
                    $pdo->beginTransaction();
                    $pdo->prepare("
                        UPDATE fuel_stock_requests
                        SET status='Rejected', manager_id=?, manager_notes=?,
                            processed_at=NOW(), updated_at=NOW()
                        WHERE id=?
                    ")->execute([$me['id'], $manager_notes, $req_id]);

                    $note = "Rejected by {$me['name']}. Reason: {$manager_notes}";
                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
                    ")->execute([$req_id, $me['id'], $role, $note]);

                    try {
                        $pdo->prepare("
                            INSERT INTO audit_trail (transaction_id, manager_id, station_id, action_type, new_value, notes, created_at)
                            VALUES (?, ?, ?, 'Reject Fuel Request', ?, ?, NOW())
                        ")->execute(['FSR-'.$req_id, $me['id'], $station_id, "Rejected {$req['fuel_type']} ({$req['requested_liters']} L)", $note]);
                    } catch (Exception $ignored) {}

                    $pdo->commit();
                    $_SESSION['success'] = 'Fuel request rejected successfully.';
                } else {
                    $_SESSION['error'] = 'Request not found or already processed.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
        header('Location: manager_stock_request_review.php?subtab=fuel'); exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fetch and Calculate Data
// ─────────────────────────────────────────────────────────────────────────────

// Calculate summary cards metrics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status IN ('Approved', 'Approved by Manager', 'Validated') THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        COALESCE(SUM(requested_quantity), 0) AS total_items
    FROM stock_requests 
    WHERE station_id = ? AND LOWER(COALESCE(item_category, '')) != 'fuel'
");
$stmt->execute([$station_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$summary_total_requests = $stats['total_count'];
$summary_pending_requests = $stats['pending_count'];
$summary_approved_requests = $stats['approved_count'];
$summary_rejected_requests = $stats['rejected_count'];
$summary_total_items = $stats['total_items'];

// Fetch all requests
$requests_list = [];
$categories_list = [];
$requesters_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name, 
               m.name AS manager_name,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.unit, 'pcs') AS unit,
               COALESCE(ip.unit_price, 145.00) AS unit_price,
               ip.sku AS prod_sku,
               po.po_number,
               po.status AS po_status,
               po.total_amount AS po_total,
               po.created_at AS po_created,
               po.remarks AS po_remarks,
               s.name AS po_supplier
        FROM stock_requests sr 
        LEFT JOIN users u ON sr.staff_id = u.id 
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE sr.station_id = ? AND LOWER(COALESCE(sr.item_category, '')) != 'fuel'
        ORDER BY CASE sr.status WHEN 'Pending' THEN 1 ELSE 2 END, sr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $requests_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($requests_list as $r) {
        $cat = $r['item_category'] ?? '';
        if ($cat !== '') {
            $categories_list[$cat] = true;
        }
        $reqBy = $r['staff_name'] ?? '';
        if ($reqBy !== '') {
            $requesters_list[$reqBy] = true;
        }
    }
    ksort($categories_list);
    ksort($requesters_list);
} catch (Exception $e) {
    // Fail silently
}

// ── Fetch Fuel Stock Requests (from staff) ───────────────────────────────────
$fuel_requests_list = [];
try {
    $fstmt = $pdo->prepare("
        SELECT fsr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        WHERE fsr.station_id = ?
        ORDER BY
            CASE fsr.status WHEN 'Pending' THEN 1 ELSE 2 END,
            fsr.created_at DESC
    ");
    $fstmt->execute([$station_id]);
    $fuel_requests_list = $fstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* fail silently */ }

$fuel_pending_count  = count(array_filter($fuel_requests_list, fn($r) => strtolower($r['status']) === 'pending'));
$fuel_approved_count = count(array_filter($fuel_requests_list, fn($r) => strtolower($r['status']) === 'approved'));
$fuel_rejected_count = count(array_filter($fuel_requests_list, fn($r) => strtolower($r['status']) === 'rejected'));

$active_sub_tab = $_GET['subtab'] ?? 'merchandise';

include __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - matches standard Petron dashboard layout == */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    margin-top: 0px !important;
}
.int-head h1 {
    font-size: 20px;
    font-weight: 800;
    color: #002F6C;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.5px;
}
.int-head .sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* Custom Table & Badge styling */
.table-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}
.table th {
    background: #002F6C;
    color: #fff;
    font-weight: 700;
    text-align: left;
    padding: 11px 14px;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.table td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.table tr:hover {
    background: #f8fafc;
}

.badge-pending {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    white-space: nowrap;
}
.badge-approved {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    white-space: nowrap;
}
.badge-rejected {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    white-space: nowrap;
}
.badge-revision {
    background: #ffe8cc;
    color: #d97706;
    border: 1px solid #ffd8a8;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    white-space: nowrap;
}

.flt-btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #cbd5e1;
    background: #fff;
    transition: all .15s;
}
.flt-btn:hover { background: #f1f5f9; }
.flt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; }
.flt-btn-excel:hover  { background: #16a34a !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* == Transaction Action Buttons (txn-btn style) == */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1.2;
    width: 100%;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.txn-btn-info { color: #6b7280 !important; border-color: #6b7280 !important; }
.txn-btn-info:hover { background: #6b7280 !important; color: #fff !important; }
.txn-btn-secondary { color: #002F6C !important; border-color: #002F6C !important; }
.txn-btn-secondary:hover { background: #002F6C !important; color: #fff !important; }
.txn-btn-approve { color: #16a34a !important; border-color: #16a34a !important; }
.txn-btn-approve:hover { background: #16a34a !important; color: #fff !important; }
.txn-btn-reject { color: #dc2626 !important; border-color: #dc2626 !important; }
.txn-btn-reject:hover { background: #dc2626 !important; color: #fff !important; }

/* Modals */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
    padding: 20px;
    overflow-y: auto;
}
.modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
}
.modal-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
    width: 90%;
    max-width: 720px;
    max-height: 90vh;
    overflow: hidden;
    margin: auto;
    position: relative;
    display: flex;
    flex-direction: column;
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #002F6C;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
    max-height: calc(90vh - 140px);
    flex: 1;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-shrink: 0;
    background: #fff;
}
.btn-cancel {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
}
.btn-cancel:hover {
    background: #f8fafc;
}
/* Modal Action Buttons - Dark Blue Style */
.btn-primary-modal {
    background: #002F6C;
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary-modal:hover {
    background: #001f4d;
}
.btn-approve-confirm {
    background: #002F6C;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 9px 22px;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.btn-approve-confirm:hover {
    background: #001f4d;
}
.btn-reject-confirm {
    background: #002F6C;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-reject-confirm:hover {
    background: #001f4d;
}

/* Modal Button Custom Overrides - Plain White Background with Outlines */
.modal-box .btn-cancel,
.modal-box .btn-primary-modal,
.modal-box .btn-approve-confirm,
.modal-box .btn-reject-confirm {
    background-color: #ffffff !important;
    background: #ffffff !important;
    border-style: solid !important;
    border-width: 1px !important;
    border-radius: 6px !important;
    padding: 8px 16px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    height: auto !important;
    box-shadow: none !important;
    transition: all 0.15s ease !important;
}

/* Specific button style variants for readability */
.modal-box .btn-cancel {
    color: #475569 !important;
    border-color: #cbd5e1 !important;
}
.modal-box .btn-cancel:hover {
    background-color: #f8fafc !important;
    border-color: #94a3b8 !important;
    color: #1e293b !important;
}

.modal-box .btn-primary-modal,
.modal-box .btn-approve-confirm {
    color: #002F6C !important;
    border-color: #002F6C !important;
}
.modal-box .btn-primary-modal:hover,
.modal-box .btn-approve-confirm:hover {
    background-color: #002F6C !important;
    border-color: #002F6C !important;
    color: #fff !important;
}

.modal-box .btn-reject-confirm {
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.modal-box .btn-reject-confirm:hover {
    background-color: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #fff !important;
}
</style>

<!-- ══ Page Title / Header ══ -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-clipboard-check"></i> Purchase Request Review</h1>
        <div class="sub">Manage and validate store merchandise replenishment requests.</div>
    </div>
</div>

<!-- ══ Flash Messages ══ -->
<?php if (!empty($_SESSION['success'])): ?>
    <div style="background:#d1e7dd; color:#0f5132; border:1px solid #badbcc; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div style="background:#f8d7da; color:#842029; border:1px solid #f5c6cb; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:700; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- ══ Summary Cards ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Requests -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Requests</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_total_requests) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-list-ol"></i></div>
    </div>
    <!-- Pending Requests -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Pending Requests</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_pending_requests) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-hourglass-half"></i></div>
    </div>
    <!-- Approved Requests -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Approved Requests</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_approved_requests) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-check-circle"></i></div>
    </div>
    <!-- Rejected Requests -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Rejected Requests</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_rejected_requests) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Total Requested Items -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Requested Items</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($summary_total_items) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-boxes"></i></div>
    </div>
</div>

<!-- ══ SUB-TAB NAV: Merchandise / Fuel ══ -->
<style>
.req-tabs-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    background: transparent;
    border-radius: 0;
    overflow: visible;
    box-shadow: none;
    border-bottom: none;
}
.req-tab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border-radius: 6px;
    transition: all .2s;
    text-transform: none;
    letter-spacing: normal;
    box-shadow: none;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #475569;
}
.req-tab-btn:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
}
.req-tab-btn.active-merch {
    background: #002F6C !important;
    color: #fff !important;
    border-color: #002F6C !important;
}
.req-tab-btn.active-fuel {
    background: #002F6C !important;
    color: #fff !important;
    border-color: #002F6C !important;
}
.req-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 800;
    background: #e2e8f0;
    color: #475569;
}
.req-tab-btn.active-merch .req-tab-badge,
.req-tab-btn.active-fuel .req-tab-badge {
    background: rgba(255, 255, 255, 0.2) !important;
    color: #fff !important;
}
.req-tab-pane {
    display: none;
}
.req-tab-pane.active {
    display: block;
}
</style>

<div class="req-tabs-nav">
  <button class="req-tab-btn <?= $active_sub_tab === 'merchandise' ? 'active-merch' : '' ?>" id="reqTabBtnMerch" onclick="switchReqTab('merchandise')">
    <i class="fas fa-boxes"></i> Merchandise
    <span class="req-tab-badge"><?= count($requests_list) ?></span>
  </button>
  <button class="req-tab-btn <?= $active_sub_tab === 'fuel' ? 'active-fuel' : '' ?>" id="reqTabBtnFuel" onclick="switchReqTab('fuel')">
    <i class="fas fa-gas-pump"></i> Fuel
    <span class="req-tab-badge"><?= count($fuel_requests_list) ?></span>
  </button>
</div>

<!-- ══ MERCHANDISE TAB ══ -->
<div class="req-tab-pane <?= $active_sub_tab === 'merchandise' ? 'active' : '' ?>" id="reqTabPaneMerch">
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    
    <!-- Filter bar layout -->
    <div style="padding:20px; border-bottom:1px solid #e9ecef; display:flex; flex-direction:column; gap:16px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clipboard-list"></i> Store Purchase Requests
        </div>
        
        <!-- Grid layout for filters -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Date Range</label>
                <div style="display:flex; align-items:center; gap:6px;">
                    <input type="date" id="reqDateFrom" onchange="filterReqTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <span style="color:#64748b; font-size:12px;">to</span>
                    <input type="date" id="reqDateTo" onchange="filterReqTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                </div>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Requested By</label>
                <select id="reqByFilter" onchange="filterReqTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Staff</option>
                    <?php foreach (array_keys($requesters_list) as $rBy): ?>
                        <option value="<?= htmlspecialchars(strtolower($rBy)) ?>"><?= htmlspecialchars($rBy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Product Category</label>
                <select id="reqCategoryFilter" onchange="filterReqTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Categories</option>
                    <?php foreach (array_keys($categories_list) as $c): ?>
                        <option value="<?= htmlspecialchars(strtolower($c)) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Status</label>
                <select id="reqStatusFilter" onchange="filterReqTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved by Manager</option>
                    <option value="rejected">Rejected</option>
                    <option value="requested revision">Requested Revision</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Search</label>
                <input type="text" id="reqSearch" placeholder="Search ID / Product..." oninput="filterReqTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box;">
            </div>
        </div>
    </div>

    <!-- Exact 10 Columns Table requested by user -->
    <div class="table-wrap">
        <table class="table" id="mgrReqTable">
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Product</th>
                    <th style="text-align:center;">Qty</th>
                    <th>Requested By</th>
                    <th>Supplier</th>
                    <th>PO No.</th>
                    <th>PO Status</th>
                    <th style="text-align:center;">Status</th>
                    <th>Decision Date</th>
                    <th style="text-align:center;width:170px;">Action</th>
                </tr>
            </thead>
            <tbody id="reqTableBody">
            <?php if (empty($requests_list)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:24px;color:#64748b;">
                        <i class="fas fa-check-circle" style="color:#28a745;font-size:24px;margin-bottom:8px;display:block;"></i>
                        No purchase requests found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests_list as $r):
                    $req_code = 'REQ-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT);
                    $decision_date = $r['processed_at'] ? date('M d', strtotime($r['processed_at'])) : '—';
                    $status_lower = strtolower($r['status'] ?? 'pending');
                    
                    $badge_class = 'badge-pending';
                    $status_display = $r['status'];
                    if (strpos($status_lower, 'approved') !== false || $status_lower === 'validated') {
                        $badge_class = 'badge-approved';
                        if ($status_lower === 'approved') $status_display = 'Approved by Manager';
                    } elseif ($status_lower === 'rejected') {
                        $badge_class = 'badge-rejected';
                    } elseif (strpos($status_lower, 'revision') !== false) {
                        $badge_class = 'badge-revision';
                    }

                    $po_num_display = $r['po_number'] ?: '—';
                    $po_status_display = $r['po_status'] ?: '—';
                    if ($r['po_status'] === 'Pending Admin Validation') {
                        $po_status_display = 'Pending Admin Approval';
                    }
                    $supplier_display = $r['po_supplier'] ?: (strpos($status_lower, 'approved') !== false ? 'Petron Regional Depot' : '—');
                ?>
                    <tr class="req-row"
                        data-category="<?= strtolower(htmlspecialchars($r['item_category'] ?? '')) ?>"
                        data-status="<?= $status_lower ?>"
                        data-requested-by="<?= strtolower(htmlspecialchars($r['staff_name'] ?? '')) ?>"
                        data-date="<?= date('Y-m-d', strtotime($r['created_at'])) ?>"
                        data-search="<?= strtolower(htmlspecialchars($req_code . ' ' . $r['item_name'] . ' ' . ($r['prod_sku'] ?? '') . ' ' . ($r['staff_name'] ?? '') . ' ' . ($r['po_number'] ?? ''))) ?>">
                        
                        <!-- 1. Request ID -->
                        <td><code style="font-weight:800; color:#002F6C; font-size:12px;"><?= $req_code ?></code></td>
                        
                        <!-- 2. Product -->
                        <td>
                             <strong><?= htmlspecialchars($r['item_name']) ?></strong><br>
                             <small style="color:#64748b; font-size:11px;"><?= htmlspecialchars($r['item_category'] ?? 'General') ?></small>
                        </td>
                        
                        <!-- 3. Qty -->
                        <td style="text-align:center;font-weight:800;color:#002F70;"><?= number_format($r['requested_quantity']) ?></td>
                        
                        <!-- 4. Requested By -->
                        <td><?= htmlspecialchars($r['staff_name'] ?? 'Staff') ?></td>
                        
                        <!-- 5. Supplier -->
                        <td><span style="font-weight:600; color:#475569;"><?= htmlspecialchars($supplier_display) ?></span></td>
                        
                        <!-- 6. PO No. -->
                        <td>
                            <?php if ($r['po_number']): ?>
                                <code style="font-weight:700; color:#1e40af; background:#eff6ff; padding:2px 6px; border-radius:4px; border:1px solid #bfdbfe;"><?= htmlspecialchars($r['po_number']) ?></code>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 7. PO Status -->
                        <td>
                            <?php if ($r['po_status']): ?>
                                <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:3px 7px; border-radius:4px; font-size:10px; font-weight:700; white-space:nowrap; display:inline-block;"><?= htmlspecialchars($po_status_display) ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 8. Status -->
                        <td style="text-align:center;">
                            <span class="<?= $badge_class ?>"><?= htmlspecialchars($status_display) ?></span>
                        </td>
                        
                        <!-- 9. Decision Date -->
                        <td style="font-size:11px;color:#64748b; white-space:nowrap;"><?= $decision_date ?></td>
                        
                        <!-- 10. Action -->
                        <td style="text-align:center;">
                            <div style="display:flex; flex-direction:column; gap:4px; width:155px; margin:0 auto;">
                                <!-- View -->
                                <button class="txn-btn txn-btn-info" onclick="viewReqDetails(<?= htmlspecialchars(json_encode($r)) ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <!-- Review -->
                                <button class="txn-btn txn-btn-secondary" onclick="openRemarksModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                                    <i class="fas fa-comment-dots"></i> Review
                                </button>

                                <?php if ($status_lower === 'pending' || strpos($status_lower, 'revision') !== false): ?>
                                    <!-- Generate PO -->
                                    <button class="txn-btn txn-btn-approve" onclick="openApprovePOModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                                        <i class="fas fa-file-invoice"></i> Generate PO
                                    </button>
                                    
                                    <!-- Reject -->
                                    <button class="txn-btn txn-btn-reject" onclick="openRejectModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrReqPagination" style="padding:10px 20px;"></div>
</div>
</div><!-- /merchandise tab pane -->

<!-- ══ FUEL TAB ══ -->
<div class="req-tab-pane <?= $active_sub_tab === 'fuel' ? 'active' : '' ?>" id="reqTabPaneFuel">
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
  <div style="padding:20px;border-bottom:1px solid #e9ecef;display:flex;flex-direction:column;gap:16px;">
    <div style="font-size:1rem;font-weight:700;color:#795548;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-gas-pump"></i> Fuel Stock Requests
      <span style="background:#795548;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;margin-left:4px;"><?= count($fuel_requests_list) ?></span>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
      <input type="text" id="fuelReqSearch" placeholder="Search Staff / Fuel Type..." oninput="filterFuelTable()"
             style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:220px;">
      <select id="fuelStatusFilter" onchange="filterFuelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
      <div style="margin-left:auto;display:flex;gap:6px;">
        <span style="background:#fff3cd;color:#856404;border:1px solid #fde68a;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;"><i class="fas fa-hourglass-half"></i> Pending: <?= $fuel_pending_count ?></span>
        <span style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;"><i class="fas fa-check"></i> Approved: <?= $fuel_approved_count ?></span>
        <span style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;"><i class="fas fa-times"></i> Rejected: <?= $fuel_rejected_count ?></span>
      </div>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table" id="fuelReqTable">
      <thead>
        <tr>
          <th>Request ID</th>
          <th>Fuel Type</th>
          <th>Current Level</th>
          <th style="text-align:center;">Requested (L)</th>
          <th style="text-align:center;">Approved (L)</th>
          <th>Requested By</th>
          <th style="text-align:center;">Status</th>
          <th>Date</th>
          <th>Manager Notes</th>
          <th style="text-align:center;width:150px;">Action</th>
        </tr>
      </thead>
      <tbody id="fuelReqTableBody">
      <?php if (empty($fuel_requests_list)): ?>
        <tr>
          <td colspan="10" style="text-align:center;padding:36px;color:#94a3b8;">
            <i class="fas fa-gas-pump" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.2;"></i>
            No fuel stock requests found.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($fuel_requests_list as $fr):
            $fr_code  = 'FSR-' . str_pad($fr['id'], 4, '0', STR_PAD_LEFT);
            $fr_st    = $fr['status'] ?? 'Pending';
            $fr_st_lc = strtolower($fr_st);
            if ($fr_st_lc === 'approved')      { $fr_badge = 'badge-approved'; $fr_badge_lbl = 'Approved'; }
            elseif ($fr_st_lc === 'rejected')  { $fr_badge = 'badge-rejected'; $fr_badge_lbl = 'Rejected'; }
            else                               { $fr_badge = 'badge-pending';  $fr_badge_lbl = 'Pending'; }
            $stock_cls = in_array($fr['stock_status'] ?? 'LOW', ['OUT OF STOCK','CRITICAL']) ? '#dc3545' : '#fd7e14';
        ?>
        <tr class="fuel-req-row"
            data-status="<?= $fr_st_lc ?>"
            data-search="<?= strtolower(htmlspecialchars($fr_code . ' ' . $fr['fuel_type'] . ' ' . ($fr['staff_name'] ?? ''))) ?>">
          <td><code style="font-weight:800;color:#795548;font-size:12px;"><?= $fr_code ?></code></td>
          <td><strong><?= htmlspecialchars($fr['fuel_type']) ?></strong></td>
          <td><?= number_format($fr['current_level'] ?? 0, 2) ?> L
            <?php if (!empty($fr['stock_status'])): ?>
              <span style="color:<?= $stock_cls ?>;font-size:10px;font-weight:700;display:block;"><?= htmlspecialchars($fr['stock_status']) ?></span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-weight:800;color:#002F70;">
            <?= number_format($fr['approved_liters'] ?? $fr['requested_liters'] ?? 0, 2) ?>
          </td>
          <td style="text-align:center;">
            <?php if ($fr['approved_liters'] !== null): ?>
              <strong style="color:#28a745;"><?= number_format($fr['approved_liters'], 2) ?></strong>
            <?php else: ?>
              <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($fr['staff_name'] ?? '—') ?></td>
          <td style="text-align:center;"><span class="<?= $fr_badge ?>"><?= $fr_badge_lbl ?></span></td>
          <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= $fr['created_at'] ? date('M d, Y', strtotime($fr['created_at'])) : '—' ?></td>
          <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?= htmlspecialchars($fr['manager_notes'] ?? '') ?>">
            <?= $fr['manager_notes'] ? htmlspecialchars($fr['manager_notes']) : '<span style="color:#94a3b8;">—</span>' ?>
          </td>
          <td style="text-align:center;">
            <div style="display:flex;flex-direction:column;gap:4px;width:155px;margin:0 auto;">
              <!-- View -->
              <button class="txn-btn txn-btn-info" onclick="openFuelViewModal(<?= htmlspecialchars(json_encode($fr)) ?>)">
                <i class="fas fa-eye"></i> View
              </button>
              
              <!-- Review -->
              <button class="txn-btn txn-btn-secondary" onclick="openFuelRemarksModal(<?= htmlspecialchars(json_encode($fr)) ?>)">
                <i class="fas fa-comment-dots"></i> Review
              </button>

              <?php if ($fr_st_lc === 'pending'): ?>
                <!-- Generate PO -->
                <button class="txn-btn txn-btn-approve" onclick="openFuelPOModal(<?= htmlspecialchars(json_encode($fr)) ?>)">
                  <i class="fas fa-file-invoice"></i> Generate PO
                </button>
                
                <!-- Reject -->
                <button class="txn-btn txn-btn-reject" onclick="openFuelRejectModal(<?= htmlspecialchars(json_encode($fr)) ?>)">
                  <i class="fas fa-times"></i> Reject
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div id="fuelReqPagination" style="padding:10px 20px;"></div>
</div>
</div><!-- /fuel tab pane -->

<!-- ── Fuel Details Modal (View) ── -->
<div class="modal-overlay" id="fuelDetailsModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Fuel Request Details</h3>
        </div>
        <div class="modal-body">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b; width:150px;">Request ID:</td><td id="detFuelReqId" style="font-weight:700;color:#795548;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Fuel Type:</td><td id="detFuelType" style="font-weight:700;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Current Level:</td><td id="detFuelCurrentLevel"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Stock Status:</td><td id="detFuelStockStatus" style="font-weight:700;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Requested Liters:</td><td id="detFuelRequestedQty" style="font-weight:700;color:#002F6C;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Approved Liters:</td><td id="detFuelApprovedQty" style="font-weight:700;color:#28a745;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Requested By:</td><td id="detFuelRequestedBy"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Date Requested:</td><td id="detFuelRequestDate"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Status:</td><td id="detFuelStatus" style="font-weight:700;"></td></tr>
                <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Manager Remarks:</td><td id="detFuelManagerNotes" style="font-style:italic;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closeFuelModal('fuelDetailsModal')" class="btn-cancel">Close</button>
        </div>
    </div>
</div>

<!-- ── Fuel Remarks Modal (Review) ── -->
<div class="modal-overlay" id="fuelRemarksModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-comment-dots"></i> Review Remarks</h3>
        </div>
        <form action="manager_stock_request_review.php?subtab=fuel" method="POST">
            <input type="hidden" name="action" value="fuel_add_remarks">
            <input type="hidden" name="request_id" id="fuelRemarksReqId">
            <div class="modal-body">
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Fuel Type</label>
                    <div id="fuelRemarksType" style="font-weight:700; font-size:14px; color:#795548;">—</div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Remarks / Notes</label>
                    <textarea name="remarks" id="fuelRemarksInput" rows="4" placeholder="Enter review remarks..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn-primary-modal">Save Remarks</button>
                <button type="button" onclick="closeFuelModal('fuelRemarksModal')" class="btn-cancel">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Fuel Purchase Order Form Modal (Generate PO) ── -->
<div class="modal-overlay" id="fuelPOModal">
    <div class="modal-box" style="width:620px; max-height:90vh; padding:0; overflow:hidden; border-radius:10px; display:flex; flex-direction:column;">
        <div style="background:#fff; color:#002F6C; padding:16px 24px; border-bottom:2px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div>
                <h3 style="margin:0; font-size:16px; font-weight:800; letter-spacing:0.5px; color:#002F6C;"><i class='fas fa-gas-pump' style='margin-right:8px;'></i>Fuel Purchase Order Form</h3>
                <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600; letter-spacing:0.5px;">Generate fuel PO &amp; forward to Admin for final approval</div>
            </div>
        </div>
        <form action="manager_stock_request_review.php?subtab=fuel" method="POST" style="margin:0; display:flex; flex-direction:column; flex:1; min-height:0;">
            <input type="hidden" name="action" value="fuel_generate_po">
            <input type="hidden" name="request_id" id="fuelPOReqId">
            <div style="padding:20px; overflow-y:auto; flex:1;">
                
                <!-- Form Fields Grid -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; background:#fdfbf7; padding:16px; border-radius:8px; border:1px solid #ebd8c8;">
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">PO Number</label>
                        <input type="text" name="po_number" id="fuelPONumberInput" readonly style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#795548; background:#f0e4dc; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Reference Request</label>
                        <input type="text" id="fuelPORefReqInput" readonly style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#475569; background:#f0e4dc; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Supplier</label>
                        <select name="supplier_name" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; box-sizing:border-box;">
                            <option value="Petron Regional Depot">Petron Regional Depot</option>
                            <option value="Petron Corporation">Petron Corporation</option>
                            <option value="Petron Main Depot">Petron Main Depot</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">PO Date</label>
                        <input type="text" readonly value="<?= date('F d, Y') ?>" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#f5ebe6; box-sizing:border-box;">
                    </div>
                    <div style="grid-column: span 2;">
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Expected Delivery</label>
                        <input type="date" name="expected_delivery" id="fuelPOExpectedDeliveryInput" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; box-sizing:border-box;">
                    </div>
                </div>

                <!-- ITEMS Section -->
                <div style="margin-bottom:16px;">
                    <div style="font-size:12px; font-weight:800; color:#795548; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #795548; padding-bottom:4px; margin-bottom:10px;">
                        ITEMS
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f5ebe6; color:#475569; font-size:11px; text-transform:uppercase;">
                                    <th style="padding:10px 12px; text-align:left;">Fuel Type</th>
                                    <th style="padding:10px 12px; text-align:center;">Requested (L)</th>
                                    <th style="padding:10px 12px; text-align:center;">Approved (L)</th>
                                    <th style="padding:10px 12px; text-align:right;">Unit Cost / L</th>
                                    <th style="padding:10px 12px; text-align:right;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding:12px; font-weight:700;" id="fuelPOItemName">—</td>
                                    <td style="padding:12px; text-align:center; font-weight:600;" id="fuelPORequestedQty">0</td>
                                    <td style="padding:12px; text-align:center; width:110px;">
                                        <input type="number" name="approved_liters" id="fuelPOApprovedQtyInput" min="0.01" step="0.01" oninput="calcFuelPOSubtotal()" style="width:100%; padding:6px; border:2px solid #795548; border-radius:5px; text-align:center; font-weight:800; font-size:14px; box-sizing:border-box;">
                                    </td>
                                    <td style="padding:12px; text-align:right; width:100px;">
                                        <input type="number" name="unit_cost" id="fuelPOUnitCostInput" value="60.00" min="0.01" step="0.01" oninput="calcFuelPOSubtotal()" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:5px; text-align:right; font-weight:700; font-size:13px; box-sizing:border-box;">
                                    </td>
                                    <td style="padding:12px; text-align:right; font-weight:800; color:#795548; font-size:14px;" id="fuelPOSubtotalDisplay">₱0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Remarks -->
                <div style="margin-bottom:16px;">
                    <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Remarks</label>
                    <textarea name="manager_notes" id="fuelPORemarksInput" rows="2" placeholder="Enter remarks..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"></textarea>
                </div>

                <!-- Generated By -->
                <div style="font-size:12px; color:#64748b; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
                    <span>Generated By:</span>
                    <strong style="color:#795548; font-size:13px;"><?= htmlspecialchars($me['name'] ?? 'Edgar Eslit') ?></strong>
                </div>

            </div>
            <div style="padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeFuelModal('fuelPOModal')" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-approve-confirm">
                    <i class="fas fa-check-circle"></i> Generate PO
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Fuel Reject Modal ── -->
<div class="modal-overlay" id="fuelRejectModal">
  <div class="modal-box" style="width:440px;">
    <div class="modal-header">
      <h3><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Fuel Request</h3>
    </div>
    <form method="post" action="manager_stock_request_review.php?subtab=fuel">
      <div class="modal-body">
        <input type="hidden" name="action" value="fuel_reject">
        <input type="hidden" name="request_id" id="fuelRejectId">
        <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
          <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:4px;">Fuel Type</div>
          <div style="font-weight:800;font-size:16px;color:#dc3545;" id="fuelRejectType">—</div>
        </div>
        <div style="margin-bottom:12px;">
          <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Rejection Reason <span style="color:red;">*</span></label>
          <textarea name="manager_notes" rows="3" required placeholder="Explain why this request is rejected..."
                    style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px;resize:vertical;box-sizing:border-box;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn-reject-confirm">Confirm Reject</button>
        <button type="button" onclick="closeFuelModal('fuelRejectModal')" class="btn-cancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Modal 1: Details Modal (View Request) ══ -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Purchase Request Details</h3>
        </div>
        <div class="modal-body">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b; width:150px;">Request ID:</td><td id="detReqId" style="font-weight:700;color:#002F70;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Product Name:</td><td id="detProdName" style="font-weight:700;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">SKU:</td><td id="detSku"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Category:</td><td id="detCategory"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Current Stock:</td><td id="detCurrentStock"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Reorder Level:</td><td id="detReorderLevel"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Requested Quantity:</td><td id="detRequestedQty" style="font-weight:700;color:#002F70;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Requested By:</td><td id="detRequestedBy"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Date Requested:</td><td id="detRequestDate"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Staff Reason:</td><td id="detReason" style="font-style:italic;color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Status:</td><td id="detStatus" style="font-weight:700;"></td></tr>
                <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Manager Remarks:</td><td id="detManagerNotes" style="font-style:italic;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closeDetailsModal()" class="btn-cancel">Close</button>
        </div>
    </div>
</div>

<!-- ══ Modal 2: Purchase Order Form (Approve & Generate PO Modal) ══ -->
<div class="modal-overlay" id="approvePOModal">
    <div class="modal-box" style="width:620px; max-height:90vh; padding:0; overflow:hidden; border-radius:10px; display:flex; flex-direction:column;">
        <div style="background:#fff; color:#002F6C; padding:16px 24px; border-bottom:2px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div>
                <h3 style="margin:0; font-size:16px; font-weight:800; letter-spacing:0.5px; color:#002F6C;"><i class='fas fa-file-invoice' style='margin-right:8px;'></i>Purchase Order Form</h3>
                <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600; letter-spacing:0.5px;">Generate &amp; forward to Admin for final approval</div>
            </div>
        </div>
        <form action="" method="POST" style="margin:0; display:flex; flex-direction:column; flex:1; min-height:0;">
            <input type="hidden" name="action" value="approve_generate_po">
            <input type="hidden" name="request_id" id="poReqId">
            <div style="padding:20px; overflow-y:auto; flex:1;">
                
                <!-- Form Fields Grid -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; background:#f8fafc; padding:16px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">PO Number</label>
                        <input type="text" name="po_number" id="poNumberInput" readonly style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F6C; background:#e2e8f0; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Reference Request</label>
                        <input type="text" id="poRefReqInput" readonly style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#475569; background:#e2e8f0; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Supplier</label>
                        <select name="supplier_name" id="poSupplierSelect" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; box-sizing:border-box;">
                            <option value="Petron Regional Depot">Petron Regional Depot</option>
                            <option value="Petron Main Depot">Petron Main Depot</option>
                            <option value="Petron Lube Plant Depot">Petron Lube Plant Depot</option>
                            <option value="Petron Gasul Terminal">Petron Gasul Terminal</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">PO Date</label>
                        <input type="text" id="poDateDisplay" readonly value="<?= date('F d, Y') ?>" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#f1f5f9; box-sizing:border-box;">
                    </div>
                    <div style="grid-column: span 2;">
                        <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Expected Delivery</label>
                        <input type="date" name="expected_delivery" id="poExpectedDeliveryInput" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; box-sizing:border-box;">
                    </div>
                </div>

                <!-- ITEMS Section -->
                <div style="margin-bottom:16px;">
                    <div style="font-size:12px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #002F6C; padding-bottom:4px; margin-bottom:10px;">
                        ITEMS
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="background:#f1f5f9; color:#475569; font-size:11px; text-transform:uppercase;">
                                    <th style="padding:10px 12px; text-align:left;">Item Name</th>
                                    <th style="padding:10px 12px; text-align:center;">Requested Qty</th>
                                    <th style="padding:10px 12px; text-align:center;">Approved Qty</th>
                                    <th style="padding:10px 12px; text-align:right;">Unit Cost</th>
                                    <th style="padding:10px 12px; text-align:right;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding:12px; font-weight:700;" id="poItemName">Oil Saver (425ml)</td>
                                    <td style="padding:12px; text-align:center; font-weight:600;" id="poRequestedQty">24</td>
                                    <td style="padding:12px; text-align:center; width:90px;">
                                        <input type="number" name="approved_quantity" id="poApprovedQtyInput" min="1" step="1" oninput="calcPOSubtotal()" style="width:100%; padding:6px; border:2px solid #002F6C; border-radius:5px; text-align:center; font-weight:800; font-size:14px; box-sizing:border-box;">
                                    </td>
                                    <td style="padding:12px; text-align:right; font-weight:600;" id="poUnitCostDisplay">₱145.00</td>
                                    <td style="padding:12px; text-align:right; font-weight:800; color:#002F6C; font-size:14px;" id="poSubtotalDisplay">₱3,480.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <input type="hidden" name="unit_cost" id="poUnitCostInput">

                <!-- Remarks -->
                <div style="margin-bottom:16px;">
                    <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Remarks</label>
                    <textarea name="manager_notes" id="poRemarksInput" rows="2" placeholder="Enter remarks..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; resize:vertical;"></textarea>
                </div>

                <!-- Generated By -->
                <div style="font-size:12px; color:#64748b; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
                    <span>Generated By:</span>
                    <strong style="color:#002F6C; font-size:13px;"><?= htmlspecialchars($me['name'] ?? 'Edgar Eslit') ?></strong>
                </div>

            </div>
            <div style="padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeApprovePOModal()" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-approve-confirm">
                    <i class="fas fa-check-circle"></i> Generate PO
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal 3: Reject Request Modal ══ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3 style="color:#dc3545;"><i class="fas fa-times-circle"></i> Reject Purchase Request</h3>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="reject_request">
            <input type="hidden" name="request_id" id="rejectReqId">
            <div class="modal-body">
                <p style="margin-bottom:14px;font-size:13px;color:#475569;">
                    Rejecting purchase request for <strong id="rejectProdName"></strong>.
                </p>
                <div>
                    <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">Reason for Rejection <span style="color:#dc3545;">*</span></label>
                    <textarea name="manager_notes" rows="3" placeholder="Specify the reason for rejection..." required
                              style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeRejectModal()" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-reject-confirm">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal 5: Add Remarks Modal ══ -->
<div class="modal-overlay" id="remarksModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-comment-dots"></i> Add / Edit Remarks</h3>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_remarks">
            <input type="hidden" name="request_id" id="remarksReqId">
            <div class="modal-body">
                <p style="margin-bottom:14px;font-size:13px;color:#475569;">
                    Add or update manager remarks for request on <strong id="remarksProdName"></strong>.
                </p>
                <div>
                    <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">Remarks / Notes</label>
                    <textarea name="remarks" id="remarksInput" rows="4" placeholder="Enter remarks..."
                              style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeRemarksModal()" class="btn-cancel">Cancel</button>
                <button type="submit" class="btn-primary-modal">Save Remarks</button>
            </div>
        </form>
    </div>
</div>

<script>
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

var currentPOItemCost = 145.00;

// ── Search & Filter ──
function filterReqTable() {
    var search = (document.getElementById('reqSearch').value || '').toLowerCase();
    var category = (document.getElementById('reqCategoryFilter').value || '').toLowerCase();
    var status = (document.getElementById('reqStatusFilter').value || '').toLowerCase();
    var requestedBy = (document.getElementById('reqByFilter').value || '').toLowerCase();
    
    var dateFromVal = document.getElementById('reqDateFrom').value;
    var dateToVal = document.getElementById('reqDateTo').value;
    
    var dateFrom = dateFromVal ? new Date(dateFromVal + 'T00:00:00') : null;
    var dateTo = dateToVal ? new Date(dateToVal + 'T23:59:59') : null;

    document.querySelectorAll('#reqTableBody tr.req-row').forEach(function(row) {
        var rowSearch = (row.dataset.search || '');
        var rowCat = (row.dataset.category || '');
        var rowStatus = (row.dataset.status || '');
        var rowReqBy = (row.dataset.requestedBy || '');
        
        var rowDateVal = row.dataset.date;
        var rowDate = rowDateVal ? new Date(rowDateVal + 'T00:00:00') : null;

        var match = true;
        if (search && rowSearch.indexOf(search) === -1) match = false;
        if (category && rowCat !== category) match = false;
        if (status && rowStatus.indexOf(status) === -1) match = false;
        if (requestedBy && rowReqBy !== requestedBy) match = false;
        
        if (dateFrom && rowDate && rowDate < dateFrom) match = false;
        if (dateTo && rowDate && rowDate > dateTo) match = false;

        row.style.display = match ? '' : 'none';
    });
}

// ── View Request Details Modal ──
function viewReqDetails(r) {
    var reqFormatted = 'REQ-' + String(r.id).padStart(4, '0');
    document.getElementById('detReqId').textContent = reqFormatted;
    document.getElementById('detProdName').textContent = r.item_name;
    document.getElementById('detSku').textContent = r.prod_sku || '—';
    document.getElementById('detCategory').textContent = r.item_category || '—';
    document.getElementById('detCurrentStock').textContent = Number(r.current_stock).toLocaleString() + ' ' + (r.unit || 'pcs');
    document.getElementById('detReorderLevel').textContent = Number(r.reorder_level).toLocaleString();
    document.getElementById('detRequestedQty').textContent = Number(r.requested_quantity).toLocaleString() + ' ' + (r.unit || 'pcs');
    document.getElementById('detRequestedBy').textContent = r.staff_name;
    document.getElementById('detRequestDate').textContent = r.created_at ? new Date(r.created_at).toLocaleString() : '—';
    document.getElementById('detReason').textContent = r.remarks || 'No reason specified';
    document.getElementById('detStatus').textContent = r.status;
    document.getElementById('detManagerNotes').textContent = r.manager_notes || '—';
    document.getElementById('detailsModal').classList.add('open');
}
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

// ── Approve & Generate PO Modal ──
function openApprovePOModal(r) {
    document.getElementById('poReqId').value = r.id;
    var reqFormatted = 'REQ-' + String(r.id).padStart(4, '0');
    document.getElementById('poRefReqInput').value = reqFormatted;
    
    var poNum = r.po_number || ('PO-2026-' + String(r.id).padStart(5, '0'));
    document.getElementById('poNumberInput').value = poNum;
    
    if (r.po_supplier) {
        document.getElementById('poSupplierSelect').value = r.po_supplier;
    }
    
    document.getElementById('poItemName').textContent = r.item_name;
    document.getElementById('poRequestedQty').textContent = Number(r.requested_quantity).toLocaleString();
    document.getElementById('poApprovedQtyInput').value = r.approved_quantity || r.requested_quantity;
    
    var unitPrice = parseFloat(r.unit_price || 145.00);
    if (isNaN(unitPrice) || unitPrice <= 0) unitPrice = 145.00;
    currentPOItemCost = unitPrice;
    
    document.getElementById('poUnitCostInput').value = unitPrice;
    document.getElementById('poUnitCostDisplay').textContent = '₱' + unitPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    document.getElementById('poRemarksInput').value = r.manager_notes || '';
    
    calcPOSubtotal();
    document.getElementById('approvePOModal').classList.add('open');
}

function calcPOSubtotal() {
    var qty = parseInt(document.getElementById('poApprovedQtyInput').value) || 0;
    var subtotal = qty * currentPOItemCost;
    document.getElementById('poSubtotalDisplay').textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function closeApprovePOModal() {
    document.getElementById('approvePOModal').classList.remove('open');
}

// ── Reject Modal ──
function openRejectModal(r) {
    document.getElementById('rejectReqId').value = r.id;
    document.getElementById('rejectProdName').textContent = r.item_name;
    document.getElementById('rejectModal').classList.add('open');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}

// ── Remarks Modal ──
function openRemarksModal(r) {
    document.getElementById('remarksReqId').value = r.id;
    document.getElementById('remarksProdName').textContent = r.item_name;
    document.getElementById('remarksInput').value = r.manager_notes || '';
    document.getElementById('remarksModal').classList.add('open');
}
function closeRemarksModal() {
    document.getElementById('remarksModal').classList.remove('open');
}

// ── Print Request Slip ──
function printRequestRecord(r) {
    var reqFormatted = 'REQ-' + String(r.id).padStart(4, '0');
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Purchase Request Slip — ' + reqFormatted + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}');
    pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
    pw.document.write('.header p{margin:4px 0 0;font-size:11px;opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
    pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:12px;}');
    pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:180px;padding:5px 0;}');
    pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    
    pw.document.write('<div class="header"><h2>Purchase Replenishment Request</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    
    pw.document.write('<div class="section"><h4>Request Details</h4><table class="info">');
    pw.document.write('<tr><td>Request ID:</td><td><strong>' + reqFormatted + '</strong></td></tr>');
    pw.document.write('<tr><td>Requested By:</td><td>' + esc(r.staff_name) + '</td></tr>');
    pw.document.write('<tr><td>Date Requested:</td><td>' + (r.created_at ? new Date(r.created_at).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('<tr><td>Status:</td><td><strong>' + esc(r.status) + '</strong></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Item Specifications</h4><table class="info">');
    pw.document.write('<tr><td>Product Name:</td><td><strong>' + esc(r.item_name) + '</strong></td></tr>');
    pw.document.write('<tr><td>SKU:</td><td>' + esc(r.prod_sku || '—') + '</td></tr>');
    pw.document.write('<tr><td>Category:</td><td>' + esc(r.item_category || '—') + '</td></tr>');
    pw.document.write('<tr><td>Current Stock:</td><td>' + Number(r.current_stock).toLocaleString() + ' ' + esc(r.unit || 'pcs') + '</td></tr>');
    pw.document.write('<tr><td>Reorder Level:</td><td>' + Number(r.reorder_level).toLocaleString() + '</td></tr>');
    pw.document.write('<tr><td>Requested Quantity:</td><td><strong style="font-size:14px;color:#002F70;">' + Number(r.requested_quantity).toLocaleString() + ' ' + esc(r.unit || 'pcs') + '</strong></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Notes & Remarks</h4><table class="info">');
    pw.document.write('<tr><td>Reason for Request:</td><td><em>' + esc(r.remarks || '—') + '</em></td></tr>');
    pw.document.write('<tr><td>Manager Remarks:</td><td>' + esc(r.manager_notes || '—') + '</td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

// ── Export Functions ──
function exportReqTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrReqTable', 'Purchase Requests Review Report');
    } else {
        window.print();
    }
}

function exportReqTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrReqTable', 'purchase_requests_review.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}

function exportReqTableCSV() {
    if (typeof exportTableToCSV === 'function') {
        exportTableToCSV('mgrReqTable', 'purchase_requests_review.csv');
    } else {
        alert('CSV export not supported on this page.');
    }
}
// ── Tab Switching ──
function switchReqTab(tab) {
    document.getElementById('reqTabPaneMerch').classList.toggle('active', tab === 'merchandise');
    document.getElementById('reqTabPaneFuel').classList.toggle('active', tab === 'fuel');
    var btnM = document.getElementById('reqTabBtnMerch');
    var btnF = document.getElementById('reqTabBtnFuel');
    btnM.className = 'req-tab-btn' + (tab === 'merchandise' ? ' active-merch' : '');
    btnF.className = 'req-tab-btn' + (tab === 'fuel' ? ' active-fuel' : '');
    var url = new URL(window.location.href);
    url.searchParams.set('subtab', tab);
    history.replaceState(null, '', url.toString());
}

// ── Fuel Table Filter ──
function filterFuelTable() {
    var search = (document.getElementById('fuelReqSearch').value || '').toLowerCase();
    var status = (document.getElementById('fuelStatusFilter').value || '').toLowerCase();
    document.querySelectorAll('.fuel-req-row').forEach(function(row) {
        var ds = (row.dataset.search || '').toLowerCase();
        var st = (row.dataset.status || '').toLowerCase();
        var matchS = !search || ds.includes(search);
        var matchSt = !status || st.includes(status);
        row.style.display = (matchS && matchSt) ? '' : 'none';
    });
}

// ── Fuel View Details Modal ──
function openFuelViewModal(r) {
    var reqFormatted = 'FSR-' + String(r.id).padStart(4, '0');
    document.getElementById('detFuelReqId').textContent = reqFormatted;
    document.getElementById('detFuelType').textContent = r.fuel_type || '—';
    document.getElementById('detFuelCurrentLevel').textContent = (r.current_level ? Number(r.current_level).toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00') + ' L';
    document.getElementById('detFuelStockStatus').textContent = r.stock_status || '—';
    document.getElementById('detFuelRequestedQty').textContent = (r.requested_liters ? Number(r.requested_liters).toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00') + ' L';
    document.getElementById('detFuelApprovedQty').textContent = r.approved_liters !== null ? (Number(r.approved_liters).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L') : '—';
    document.getElementById('detFuelRequestedBy').textContent = r.staff_name || '—';
    document.getElementById('detFuelRequestDate').textContent = r.created_at ? new Date(r.created_at).toLocaleString() : '—';
    document.getElementById('detFuelStatus').textContent = r.status || 'Pending';
    document.getElementById('detFuelManagerNotes').textContent = r.manager_notes || '—';
    document.getElementById('fuelDetailsModal').classList.add('open');
}

// ── Fuel Remarks Modal (Review) ──
function openFuelRemarksModal(r) {
    document.getElementById('fuelRemarksReqId').value = r.id;
    document.getElementById('fuelRemarksType').textContent = r.fuel_type || '—';
    document.getElementById('fuelRemarksInput').value = r.manager_notes || '';
    document.getElementById('fuelRemarksModal').classList.add('open');
}

// ── Fuel PO Modal (Generate PO) ──
function openFuelPOModal(r) {
    document.getElementById('fuelPOReqId').value = r.id;
    var reqFormatted = 'FSR-' + String(r.id).padStart(4, '0');
    document.getElementById('fuelPORefReqInput').value = reqFormatted;
    
    var poNum = 'POF-2026-' + String(r.id).padStart(5, '0');
    document.getElementById('fuelPONumberInput').value = poNum;
    
    document.getElementById('fuelPOItemName').textContent = r.fuel_type || '—';
    document.getElementById('fuelPORequestedQty').textContent = (r.requested_liters ? Number(r.requested_liters).toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00');
    document.getElementById('fuelPOApprovedQtyInput').value = r.approved_liters || r.requested_liters || '';
    
    document.getElementById('fuelPOUnitCostInput').value = '60.00';
    document.getElementById('fuelPORemarksInput').value = r.manager_notes || '';
    
    calcFuelPOSubtotal();
    document.getElementById('fuelPOModal').classList.add('open');
}

function calcFuelPOSubtotal() {
    var qty = parseFloat(document.getElementById('fuelPOApprovedQtyInput').value) || 0;
    var price = parseFloat(document.getElementById('fuelPOUnitCostInput').value) || 0;
    var subtotal = qty * price;
    document.getElementById('fuelPOSubtotalDisplay').textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// ── Fuel Reject Modal ──
function openFuelRejectModal(r) {
    document.getElementById('fuelRejectId').value = r.id;
    document.getElementById('fuelRejectType').textContent = r.fuel_type || '—';
    document.getElementById('fuelRejectModal').classList.add('open');
}

function closeFuelModal(id) {
    document.getElementById(id).classList.remove('open');
}

// ── On Page Load Tab Check ──
window.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var subtab = params.get('subtab');
    if (subtab === 'fuel') {
        switchReqTab('fuel');
    } else {
        switchReqTab('merchandise');
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
