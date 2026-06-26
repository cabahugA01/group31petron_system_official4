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
// POST Actions (Approve / Reject / Remarks)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. Approve Request
    if ($action === 'approve_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $approved_qty = (int)($_POST['approved_quantity'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && $approved_qty > 0) {
            try {
                $pdo->beginTransaction();
                
                // Fetch request
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
                // Fetch unit price/cost from product
                $stmt = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ?");
                $stmt->execute([$req['item_id']]);
                $unit_price = (float)($stmt->fetchColumn() ?: 0);
                $total_amount = $approved_qty * $unit_price;
                
                // Generate PO
                $po_number = "PO-" . strtoupper(uniqid());
                
                // Insert PO
                $pdo->prepare("
                    INSERT INTO purchase_orders 
                        (request_id, product_name, quantity, unit_price, total_amount, type, po_number, station_id, created_by, status, remarks, created_at, updated_at, admin_finalized)
                    VALUES (?, ?, ?, ?, ?, 'merch', ?, ?, ?, 'Pending Admin Validation', ?, NOW(), NOW(), 0)
                ")->execute([
                    $req_id, $req['item_name'], $approved_qty, $unit_price, $total_amount, $po_number, $station_id, $me['id'], $notes
                ]);
                
                // Update Stock Request
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Approved', approved_quantity = ?, manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
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
                    VALUES (?, 'Forwarded to Admin', ?, ?, 'Pending', 'Forwarded to Admin', ?)
                ")->execute([$req_id, $me['id'], $role, $audit_note]);
                
                log_activity($pdo, $me['id'], 'Approve Stock Request', "Request #{$req_id} | {$req['item_name']} | Qty: {$approved_qty} approved by {$me['name']}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} approved. Purchase Order {$po_number} generated.";
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
                
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
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
                    VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
                ")->execute([$req_id, $me['id'], $role, "Rejected by {$me['name']}. Reason: {$notes}"]);
                
                log_activity($pdo, $me['id'], 'Reject Stock Request', "Request #{$req_id} | {$req['item_name']} rejected by {$me['name']}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} rejected.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
        header('Location: manager_stock_request_review.php'); exit;
    }

    // 3. Add Remarks / Update Notes
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
                
                log_activity($pdo, $me['id'], 'Update Stock Request Remarks', "Remarks updated for Request #{$req_id}");
                $_SESSION['success'] = "Remarks updated successfully for Request #{$req_id}.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating remarks: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid request ID.';
        }
        header('Location: manager_stock_request_review.php'); exit;
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
        SUM(CASE WHEN status IN ('Approved', 'Validated') THEN 1 ELSE 0 END) AS approved_count,
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
        SELECT sr.*, u.name AS staff_name, 
               m.name AS manager_name,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.unit, ip.unit, 'pcs') AS unit,
               ip.sku AS prod_sku,
               po.po_number,
               po.status AS po_status,
               po.total_amount AS po_total,
               po.created_at AS po_created,
               po.remarks AS po_remarks,
               po.supplier_name AS po_supplier
        FROM stock_requests sr 
        JOIN users u ON sr.staff_id = u.id 
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN inventory_products ip ON sr.item_id = ip.id
        LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
        LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'
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
    font-size: 13px;
}
.table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-align: left;
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
}
.table td {
    padding: 12px 16px;
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
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}
.badge-approved {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}
.badge-rejected {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c2c7;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}

.int-btn-outline {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.int-btn-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #1e293b;
}

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* == Transaction Action Buttons (txn-btn style) == */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    width: 100%;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.txn-btn-approve { color: #16a34a !important; border-color: #16a34a !important; }
.txn-btn-approve:hover { background: #16a34a !important; color: #fff !important; }
.txn-btn-reject { color: #dc2626 !important; border-color: #dc2626 !important; }
.txn-btn-reject:hover { background: #dc2626 !important; color: #fff !important; }
.txn-btn-adjust { color: #00264D !important; border-color: #00264D !important; }
.txn-btn-adjust:hover { background: #00264D !important; color: #fff !important; }
.txn-btn-info { color: #0284c7 !important; border-color: #0284c7 !important; }
.txn-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.txn-btn-secondary { color: #6b7280 !important; border-color: #6b7280 !important; }
.txn-btn-secondary:hover { background: #6b7280 !important; color: #fff !important; }

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
}
.modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
}
.modal-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #002F6C;
}
.modal-body {
    padding: 20px;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
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
</style>

<!-- ══ Page Title / Header ══ -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-clipboard-check"></i> Stock Request Review</h1>
        <div class="sub">Manage and validate store merchandise replenishment requests.</div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="manager_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- ══ Summary Cards ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Requests -->
    <div style="background:#fff;border-left:5px solid #002F6C;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Requests</div>
            <div style="font-size:24px;font-weight:800;color:#002F6C;margin-top:4px;"><?= number_format($summary_total_requests) ?></div>
        </div>
        <div style="background:#e8f4fd;color:#002F6C;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-list-ol"></i></div>
    </div>
    <!-- Pending Requests -->
    <div style="background:#fff;border-left:5px solid #fd7e14;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Pending Requests</div>
            <div style="font-size:24px;font-weight:800;color:#fd7e14;margin-top:4px;"><?= number_format($summary_pending_requests) ?></div>
        </div>
        <div style="background:#fff3cd;color:#fd7e14;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-hourglass-half"></i></div>
    </div>
    <!-- Approved Requests -->
    <div style="background:#fff;border-left:5px solid #28a745;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Approved Requests</div>
            <div style="font-size:24px;font-weight:800;color:#28a745;margin-top:4px;"><?= number_format($summary_approved_requests) ?></div>
        </div>
        <div style="background:#e6f4ea;color:#28a745;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-check-circle"></i></div>
    </div>
    <!-- Rejected Requests -->
    <div style="background:#fff;border-left:5px solid #dc3545;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Rejected Requests</div>
            <div style="font-size:24px;font-weight:800;color:#dc3545;margin-top:4px;"><?= number_format($summary_rejected_requests) ?></div>
        </div>
        <div style="background:#fce8e6;color:#dc3545;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Total Requested Items -->
    <div style="background:#fff;border-left:5px solid #6f42c1;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Requested Items</div>
            <div style="font-size:24px;font-weight:800;color:#6f42c1;margin-top:4px;"><?= number_format($summary_total_items) ?></div>
        </div>
        <div style="background:#f3e8fd;color:#6f42c1;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-boxes"></i></div>
    </div>
</div>

<!-- ══ Catalog / Requests List ══ -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    
    <!-- Filter bar layout (as requested: Date From-To, Requested By, Product Category, Status, Search) -->
    <div style="padding:20px; border-bottom:1px solid #e9ecef; display:flex; flex-direction:column; gap:16px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clipboard-list"></i> Store Stock Requests
        </div>
        
        <!-- Grid layout for filters -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; align-items:end;">
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
                    <option value="pending">🟨 Pending</option>
                    <option value="approved">🟩 Approved</option>
                    <option value="rejected">🟥 Rejected</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Search</label>
                <input type="text" id="reqSearch" placeholder="Search ID / Product Name..." oninput="filterReqTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box;">
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
            <button onclick="exportReqTablePDF()" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            <button onclick="exportReqTableExcel()" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            <button onclick="exportReqTableCSV()" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="mgrReqTable">
            <thead>
                <tr>
                    <th style="width:90px;">Request ID</th>
                    <th>Date Requested</th>
                    <th>Product Name</th>
                    <th style="text-align:right;">Requested Qty</th>
                    <th>Requested By</th>
                    <th style="text-align:center;">Status</th>
                    <th>Approved/Rejected By</th>
                    <th>Decision Date</th>
                    <th>Remarks</th>
                    <th style="text-align:center;width:190px;">Actions</th>
                </tr>
            </thead>
            <tbody id="reqTableBody">
            <?php if (empty($requests_list)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:24px;color:#64748b;">
                        <i class="fas fa-check-circle" style="color:#28a745;font-size:24px;margin-bottom:8px;display:block;"></i>
                        No stock requests found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests_list as $r):
                    $date_str = $r['created_at'] ? date('M d, Y h:i A', strtotime($r['created_at'])) : '—';
                    $decision_date = $r['processed_at'] ? date('M d, Y h:i A', strtotime($r['processed_at'])) : '—';
                    $status_lower = strtolower($r['status'] ?? 'pending');
                    
                    $badge_class = 'badge-pending';
                    if ($status_lower === 'approved' || $status_lower === 'validated') {
                        $badge_class = 'badge-approved';
                    } elseif ($status_lower === 'rejected') {
                        $badge_class = 'badge-rejected';
                    }

                    // Format PO info for JS usage
                    $po_data = null;
                    if (!empty($r['po_number'])) {
                        $po_data = [
                            'po_number' => $r['po_number'],
                            'status' => $r['po_status'],
                            'total' => $r['po_total'],
                            'created' => $r['po_created'],
                            'remarks' => $r['po_remarks'],
                            'supplier' => $r['po_supplier']
                        ];
                    }
                ?>
                    <tr class="req-row"
                        data-category="<?= strtolower(htmlspecialchars($r['item_category'] ?? '')) ?>"
                        data-status="<?= $status_lower ?>"
                        data-requested-by="<?= strtolower(htmlspecialchars($r['staff_name'] ?? '')) ?>"
                        data-date="<?= date('Y-m-d', strtotime($r['created_at'])) ?>"
                        data-search="<?= strtolower(htmlspecialchars($r['id'] . ' ' . $r['item_name'] . ' ' . ($r['prod_sku'] ?? '') . ' ' . ($r['staff_name'] ?? '') . ' ' . ($r['remarks'] ?? ''))) ?>">
                        <td><code style="font-weight:700;">#<?= $r['id'] ?></code></td>
                        <td style="font-size:11px;color:#64748b;"><?= $date_str ?></td>
                        <td>
                             <strong><?= htmlspecialchars($r['item_name']) ?></strong><br>
                             <small style="color:#64748b;">SKU: <?= htmlspecialchars($r['prod_sku'] ?? '—') ?> | Category: <?= htmlspecialchars($r['item_category'] ?? '—') ?></small>
                        </td>
                        <td style="text-align:right;font-weight:700;color:#002F70;"><?= number_format($r['requested_quantity']) ?> <span style="font-size:10px;color:#64748b;"><?= htmlspecialchars($r['unit'] ?? 'pcs') ?></span></td>
                        <td><?= htmlspecialchars($r['staff_name'] ?? '—') ?></td>
                        <td style="text-align:center;">
                            <span class="<?= $badge_class ?>"><?= htmlspecialchars($r['status']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($r['manager_name'] ?? '—') ?></td>
                        <td style="font-size:11px;color:#64748b;"><?= $decision_date ?></td>
                        <td style="font-size:11px;color:#64748b;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['manager_notes'] ?? '') ?>">
                            <?= htmlspecialchars($r['manager_notes'] ?: '—') ?>
                        </td>
                        <td style="text-align:center;">
                            <div style="display:flex; flex-direction:column; gap:4px; width:170px; margin:0 auto;">
                                
                                <!-- 👁 View Details (shown in all cases) -->
                                <button class="txn-btn txn-btn-info" onclick="viewReqDetails(<?= htmlspecialchars(json_encode($r)) ?>)" title="View Details">
                                    <i class="fas fa-eye"></i> View Details
                                </button>

                                <?php if ($status_lower === 'pending'): ?>
                                    <!-- Pending Actions -->
                                    <button class="txn-btn txn-btn-approve" onclick="openApproveModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Approve Request">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="txn-btn txn-btn-reject" onclick="openRejectModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Reject Request">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                    <button class="txn-btn txn-btn-secondary" onclick="openRemarksModal(<?= htmlspecialchars(json_encode($r)) ?>)" title="Add Remarks">
                                        <i class="fas fa-comment-dots"></i> Add Remarks
                                    </button>
                                
                                <?php elseif ($status_lower === 'approved' || $status_lower === 'validated'): ?>
                                    <!-- Approved Actions -->
                                    <?php if ($po_data): ?>
                                        <button class="txn-btn txn-btn-adjust" onclick="viewPurchaseOrder(<?= htmlspecialchars(json_encode($po_data)) ?>)" title="View Purchase Order">
                                            <i class="fas fa-file-invoice"></i> View Purchase Order
                                        </button>
                                    <?php endif; ?>
                                    <button class="txn-btn txn-btn-secondary" onclick="printRequestRecord(<?= htmlspecialchars(json_encode($r)) ?>)" title="Print Record">
                                        <i class="fas fa-print"></i> Print Record
                                    </button>

                                <?php elseif ($status_lower === 'rejected'): ?>
                                    <!-- Rejected Actions -->
                                    <button class="txn-btn txn-btn-secondary" onclick="printRequestRecord(<?= htmlspecialchars(json_encode($r)) ?>)" title="Print Record">
                                        <i class="fas fa-print"></i> Print Record
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

<!-- ══ Modal 1: Details Modal ══ -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Request Details</h3>
            <button onclick="closeDetailsModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
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
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Status:</td><td id="detStatus"></td></tr>
                <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Manager Notes:</td><td id="detManagerNotes" style="font-weight:600;color:#1e293b;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closeDetailsModal()" class="btn-cancel">Close</button>
        </div>
    </div>
</div>

<!-- ══ Modal 2: Approve Request Modal ══ -->
<div class="modal-overlay" id="approveModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3 style="color:#28a745;"><i class="fas fa-check-circle"></i> Approve Stock Request</h3>
            <button onclick="closeApproveModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="approve_request">
            <input type="hidden" name="request_id" id="approveReqId">
            <div class="modal-body">
                <p style="margin-bottom:14px;font-size:13px;color:#475569;">
                    Confirming approval for request on <strong id="approveProdName"></strong>.
                </p>
                <div style="margin-bottom:14px;">
                    <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">Approved Quantity</label>
                    <input type="number" name="approved_quantity" id="approveQtyInput" min="1" step="1" required
                           style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;font-weight:700;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">Manager Remarks / Notes</label>
                    <textarea name="manager_notes" rows="3" placeholder="Optional approval details..."
                              style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeApproveModal()" class="btn-cancel">Cancel</button>
                <button type="submit" style="background:#28a745;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-weight:700;cursor:pointer;">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal 3: Reject Request Modal ══ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3 style="color:#dc3545;"><i class="fas fa-times-circle"></i> Reject Stock Request</h3>
            <button onclick="closeRejectModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="reject_request">
            <input type="hidden" name="request_id" id="rejectReqId">
            <div class="modal-body">
                <p style="margin-bottom:14px;font-size:13px;color:#475569;">
                    Rejecting stock request for <strong id="rejectProdName"></strong>.
                </p>
                <div>
                    <label style="font-size:12px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">Reason for Rejection <span style="color:#dc3545;">*</span></label>
                    <textarea name="manager_notes" rows="3" placeholder="Specify the reason for rejection..." required
                              style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeRejectModal()" class="btn-cancel">Cancel</button>
                <button type="submit" style="background:#dc3545;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-weight:700;cursor:pointer;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal 4: Add Remarks Modal ══ -->
<div class="modal-overlay" id="remarksModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-comment-dots"></i> Add / Edit Remarks</h3>
            <button onclick="closeRemarksModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
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
                <button type="submit" style="background:#002F70;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-weight:700;cursor:pointer;">Save Remarks</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal 5: View Purchase Order Modal ══ -->
<div class="modal-overlay" id="poModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice" style="color:#002F6C;"></i> Purchase Order Details</h3>
            <button onclick="closePOModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b; width:150px;">PO Number:</td><td id="poNumber" style="font-weight:700;color:#002F70;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Supplier Name:</td><td id="poSupplier" style="font-weight:600;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Created Date:</td><td id="poCreated"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Total Amount:</td><td id="poTotal" style="font-weight:700;color:#28a745;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">PO Status:</td><td id="poStatus"></td></tr>
                <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Remarks:</td><td id="poRemarks" style="font-style:italic;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closePOModal()" class="btn-cancel">Close</button>
        </div>
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
        if (status && rowStatus !== status) match = false;
        if (requestedBy && rowReqBy !== requestedBy) match = false;
        
        if (dateFrom && rowDate && rowDate < dateFrom) match = false;
        if (dateTo && rowDate && rowDate > dateTo) match = false;

        row.style.display = match ? '' : 'none';
    });
}

// ── View Details Modal ──
function viewReqDetails(r) {
    document.getElementById('detReqId').textContent = '#' + r.id;
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

// ── View Purchase Order Modal ──
function viewPurchaseOrder(po) {
    document.getElementById('poNumber').textContent = po.po_number || '—';
    document.getElementById('poSupplier').textContent = po.supplier || '—';
    document.getElementById('poCreated').textContent = po.created ? new Date(po.created).toLocaleString() : '—';
    document.getElementById('poTotal').textContent = '₱' + Number(po.total).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('poStatus').textContent = po.status || '—';
    document.getElementById('poRemarks').textContent = po.remarks || '—';
    document.getElementById('poModal').classList.add('open');
}
function closePOModal() {
    document.getElementById('poModal').classList.remove('open');
}

// ── Approve Modal ──
function openApproveModal(r) {
    document.getElementById('approveReqId').value = r.id;
    document.getElementById('approveProdName').textContent = r.item_name;
    document.getElementById('approveQtyInput').value = r.requested_quantity;
    document.getElementById('approveModal').classList.add('open');
}
function closeApproveModal() {
    document.getElementById('approveModal').classList.remove('open');
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
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Stock Request Slip — #' + r.id + '</title>');
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
    pw.document.write('.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;}');
    pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    
    pw.document.write('<div class="header"><h2>Stock Replenishment Request</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    
    pw.document.write('<div class="section"><h4>Request Details</h4><table class="info">');
    pw.document.write('<tr><td>Request ID:</td><td><strong>#' + r.id + '</strong></td></tr>');
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
        exportTableToPDF('mgrReqTable', 'Stock Requests Review Report');
    } else {
        window.print();
    }
}

function exportReqTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrReqTable', 'stock_requests_review.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}

function exportReqTableCSV() {
    var rows = document.querySelectorAll('#mgrReqTable tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        var data  = [];
        cells.forEach(function(cell, idx) {
            if (idx === cells.length - 1) return; // skip Actions column
            var text = cell.innerText.trim().replace(/"/g, '""');
            data.push('"' + text + '"');
        });
        if (data.length) csv.push(data.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type: 'text/csv'});
    var a    = document.createElement('a');
    a.href  = URL.createObjectURL(blob);
    a.download = 'stock_requests_review_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrReqTable', null, 'mgrReqPagination', 10);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
