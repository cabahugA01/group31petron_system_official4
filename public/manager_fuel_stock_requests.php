<?php
$page_id = 'mgr_fuel_stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php'); exit;
}

// Ensure tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_stock_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL, station_id INT NOT NULL,
            fuel_type VARCHAR(100) NOT NULL,
            current_level DECIMAL(12,2) NOT NULL DEFAULT 0,
            capacity DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_status VARCHAR(30) NOT NULL DEFAULT 'LOW',
            requested_liters DECIMAL(12,2) NOT NULL,
            remarks TEXT,
            status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
            approved_liters DECIMAL(12,2) NULL,
            manager_id INT NULL, manager_notes TEXT NULL,
            processed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fuel_stock_request_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            performed_by INT NOT NULL,
            performed_by_role VARCHAR(50) NOT NULL,
            old_status VARCHAR(30) NULL, new_status VARCHAR(30) NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $ignored) {}

// Ensure fuel_purchase_orders.status is VARCHAR so 'Pending Admin Validation' can be stored
try {
    $pdo->exec("ALTER TABLE fuel_purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Pending Admin Validation'");
} catch (Exception $ignored) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $req_id = (int)($_POST['request_id'] ?? 0);

    if ($action === 'generate_po' && $req_id > 0) {
        try {
            // Load validated stock request
            $stmt = $pdo->prepare("
                SELECT sr.*, u.name AS staff_name
                FROM stock_requests sr
                LEFT JOIN users u ON sr.staff_id = u.id
                WHERE sr.id = ? AND sr.station_id = ? AND sr.status = 'Validated'
            ");
            $stmt->execute([$req_id, $station_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Validated stock request not found.');
            }

            // Check if PO already exists for this request
            $check_stmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE request_id = ?");
            $check_stmt->execute([$req_id]);
            if ($check_stmt->fetch()) {
                throw new Exception('Purchase Order already exists for this stock request.');
            }

            $pdo->beginTransaction();

            // Generate unique PO number
            $po_number = 'PO-' . date('Ymd') . '-SR' . str_pad($request['id'], 4, '0', STR_PAD_LEFT);

            // Get item price from station_inventory or use approved_price
            $price_stmt = $pdo->prepare("
                SELECT COALESCE(cost, 0) as unit_cost
                FROM station_inventory
                WHERE station_id = ? AND sku = ?
                LIMIT 1
            ");
            $price_stmt->execute([$station_id, $request['item_sku']]);
            $price_row = $price_stmt->fetch(PDO::FETCH_ASSOC);
            $unit_price = $price_row ? (float)$price_row['unit_cost'] : (float)($request['approved_price'] ?? 0);

            $approved_qty = $request['approved_quantity'] ?? $request['requested_quantity'];
            $total_amount = $unit_price * $approved_qty;

            // Create Purchase Order
            $po_stmt = $pdo->prepare("
                INSERT INTO purchase_orders
                    (request_id, product_name, quantity, unit_price, total_amount, type,
                     po_number, station_id, created_by, status, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, 'merch', ?, ?, ?, 'Pending Admin Validation', ?, NOW())
            ");
            $remarks = "Auto-generated from Stock Request #{$request['id']}. Manager: {$me['name']}.";
            $po_stmt->execute([
                $request['id'],
                $request['item_name'],
                $approved_qty,
                $unit_price,
                $total_amount,
                $po_number,
                $station_id,
                $me['id'],
                $remarks
            ]);

            $po_id = $pdo->lastInsertId();

            // Add PO line item
            $item_stmt = $pdo->prepare("
                INSERT INTO purchase_order_items
                    (po_id, item_name, quantity, product_id, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $item_stmt->execute([
                $po_id,
                $request['item_name'],
                $approved_qty,
                $request['item_id'],
                $unit_price,
                $total_amount
            ]);

            // Log activity
            try {
                log_activity($pdo, $me['id'], 'Generate PO',
                    "PO {$po_number} created from Stock Request #{$request['id']}. Item: {$request['item_name']}, Qty: {$approved_qty}");
            } catch (Exception $ignored) {}

            $pdo->commit();
            $_SESSION['success'] = "✓ Purchase Order {$po_number} generated successfully! Pending Admin validation.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = '✗ Error generating PO: ' . $e->getMessage();
        }

        header('Location: manager_fuel_stock_requests.php');
        exit;
    }

    if ($action === 'approve' && $req_id > 0) {
        $approved_liters = (float)($_POST['approved_liters'] ?? 0);
        $manager_notes   = trim($_POST['manager_notes'] ?? '');

        if ($approved_liters > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_stock_requests WHERE id = ? AND station_id = ?");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($req && strtolower($req['status']) === 'pending') {
                    $pdo->beginTransaction();
                    $pdo->prepare("
                        UPDATE fuel_stock_requests
                        SET status='Approved', approved_liters=?, manager_id=?, manager_notes=?,
                            processed_at=NOW(), updated_at=NOW()
                        WHERE id=?
                    ")->execute([$approved_liters, $me['id'], $manager_notes, $req_id]);

                    $note = "Approved: {$req['requested_liters']} L → {$approved_liters} L of {$req['fuel_type']}. Manager: {$me['name']}.";
                    if ($manager_notes) $note .= " Notes: {$manager_notes}";

                    $pdo->prepare("
                        INSERT INTO fuel_stock_request_audit
                            (request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                        VALUES (?, 'Approved', ?, ?, 'Pending', 'Approved', ?)
                    ")->execute([$req_id, $me['id'], $role, $note]);

                    // Log to main audit_trail
                    try {
                        $pdo->prepare("
                            INSERT INTO audit_trail (transaction_id, manager_id, station_id, action_type, new_value, notes, created_at)
                            VALUES (?, ?, ?, 'Approve Fuel Request', ?, ?, NOW())
                        ")->execute(['FSR-'.$req_id, $me['id'], $station_id, "Approved {$approved_liters} L of {$req['fuel_type']}", $note]);
                    } catch (Exception $ignored) {}

                    // ── Generate fuel PO for Admin approval ────────────────────────────
                    try {
                        // Resolve fuel_type_id from fuel_types table
                        $ft_stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                        $ft_stmt->execute([$req['fuel_type']]);
                        $fuel_type_id = (int)($ft_stmt->fetchColumn() ?: 0);

                        // Generate unique PO number
                        $fpo_number = 'POF-' . date('Y') . '-' . str_pad($req_id, 5, '0', STR_PAD_LEFT);

                        // Only insert if not already created
                        $chk = $pdo->prepare("SELECT id FROM fuel_purchase_orders WHERE po_number = ? AND station_id = ?");
                        $chk->execute([$fpo_number, $station_id]);
                        if (!$chk->fetchColumn()) {
                            $pdo->prepare("
                                INSERT INTO fuel_purchase_orders
                                    (po_number, station_id, fuel_type_id, volume, unit_price, total_amount,
                                     status, created_by, notes, created_at, updated_at)
                                VALUES (?, ?, ?, ?, 0, 0, 'Pending Admin Validation', ?, ?, NOW(), NOW())
                            ")->execute([
                                $fpo_number,
                                $station_id,
                                $fuel_type_id,
                                $approved_liters,
                                $me['id'],
                                "Fuel Stock Request #FSR-{$req_id} | {$req['fuel_type']} | {$approved_liters} L approved by {$me['name']}." . ($manager_notes ? " Notes: {$manager_notes}" : '')
                            ]);
                        }
                    } catch (Exception $fpo_err) {
                        error_log("fuel_purchase_orders insert failed: " . $fpo_err->getMessage());
                    }

                    $pdo->commit();
                    $_SESSION['success'] = "Fuel request approved & PO generated. {$approved_liters} L of {$req['fuel_type']} — Pending Admin approval.";
                } else {
                    $_SESSION['error'] = 'Request not found or already processed.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Approved liters must be greater than 0.';
        }

    } elseif ($action === 'reject' && $req_id > 0) {
        $manager_notes = trim($_POST['manager_notes'] ?? '');

        if (!empty($manager_notes)) {
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
    }

    header('Location: manager_fuel_stock_requests.php');
    exit;
}

// Fetch fuel stock requests
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT fsr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name, m.name AS manager_name
        FROM fuel_stock_requests fsr
        LEFT JOIN users u ON fsr.staff_id = u.id
        LEFT JOIN users m ON fsr.manager_id = m.id
        WHERE fsr.station_id = ?
        ORDER BY
            CASE fsr.status
                WHEN 'Pending' THEN 1
                WHEN 'Approved' THEN 2
                WHEN 'Rejected' THEN 3
            END,
            fsr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = 'Could not load requests: ' . $e->getMessage();
}

// Fetch merchandise stock requests (validated, ready for PO)
$merch_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, COALESCE(u.name, 'Unknown Staff') AS staff_name, m.name AS manager_name,
               po.id as po_id, po.po_number
        FROM stock_requests sr
        LEFT JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        LEFT JOIN purchase_orders po ON po.request_id = sr.id
        WHERE sr.station_id = ?
        ORDER BY
            CASE sr.status
                WHEN 'Pending' THEN 1
                WHEN 'Validated' THEN 2
            END,
            sr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $merch_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = 'Could not load merchandise requests: ' . $e->getMessage();
}

$pending_count = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));
$validated_merch_count = count(array_filter($merch_requests, fn($r) => $r['status'] === 'Validated' && empty($r['po_id'])));

include __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-clipboard-list" style="color:#667eea;"></i> Stock Requests Management</h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="fsr-summary-row">
    <div class="fsr-card fsr-card-total">
        <div class="fsr-card-num"><?php echo count($requests); ?></div>
        <div class="fsr-card-lbl">Total Fuel Requests</div>
    </div>
    <div class="fsr-card fsr-card-pending">
        <div class="fsr-card-num"><?php echo $pending_count; ?></div>
        <div class="fsr-card-lbl">Pending</div>
    </div>
    <div class="fsr-card fsr-card-approved">
        <div class="fsr-card-num"><?php echo count(array_filter($requests, fn($r) => $r['status'] === 'Approved')); ?></div>
        <div class="fsr-card-lbl">Approved</div>
    </div>
    <div class="fsr-card fsr-card-rejected">
        <div class="fsr-card-num"><?php echo count(array_filter($requests, fn($r) => $r['status'] === 'Rejected')); ?></div>
        <div class="fsr-card-lbl">Rejected</div>
    </div>
    <div class="fsr-card fsr-card-validated">
        <div class="fsr-card-num"><?php echo $validated_merch_count; ?></div>
        <div class="fsr-card-lbl">Ready for PO</div>
    </div>
</div>

<!-- Merchandise Stock Requests - Validated (Ready for PO Generation) -->
<?php if (!empty($merch_requests)): ?>
<div class="card" style="padding:0;margin-bottom:24px;">
    <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 24px;border-radius:12px 12px 0 0;">
        <h3 style="margin:0;font-size:17px;font-weight:700;"><i class="fas fa-box" style="margin-right:8px;"></i> Merchandise Stock Requests</h3>
        <p style="margin:6px 0 0 0;font-size:12px;opacity:0.9;">Validated requests ready for Purchase Order generation</p>
    </div>
    <div class="fsr-table-wrap">
        <table class="fsr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Status</th>
                    <th>Requested Qty</th>
                    <th>Approved Qty</th>
                    <th>Manager Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($merch_requests as $req): ?>
                <?php
                    $st  = $req['status'] ?? 'Pending';
                    $cls = 'fsr-badge-' . strtolower($st);
                    $has_po = !empty($req['po_id']);
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                    <td style="font-size:12px;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($req['item_name']); ?></strong>
                        <div style="font-size:10px;color:#888;font-family:monospace;"><?php echo htmlspecialchars($req['item_sku']); ?></div>
                    </td>
                    <td style="font-size:11px;"><?php echo htmlspecialchars($req['item_category']); ?></td>
                    <td style="text-align:center;">
                        <?php echo number_format($req['current_stock']); ?>
                        <?php if ($req['current_stock'] <= 10): ?>
                            <span style="color:#dc3545;font-size:11px;font-weight:700;display:block;">LOW</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="fsr-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                    <td style="font-weight:700;text-align:center;"><?php echo number_format($req['requested_quantity']); ?></td>
                    <td style="text-align:center;">
                        <?php if ($req['approved_quantity'] !== null): ?>
                            <strong style="color:#28a745;"><?php echo number_format($req['approved_quantity']); ?></strong>
                        <?php else: ?>
                            <span style="color:#adb5bd;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($req['manager_notes'] ?? ''); ?>">
                        <?php echo $req['manager_notes'] ? htmlspecialchars($req['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?>
                    </td>
                    <td>
                        <?php if ($st === 'Validated' && !$has_po): ?>
                            <button class="fsr-btn fsr-btn-generate-po" onclick="openGeneratePOModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['item_name'], ENT_QUOTES); ?>', <?php echo $req['approved_quantity'] ?? $req['requested_quantity']; ?>)">
                                <i class="fas fa-file-invoice"></i> Generate PO
                            </button>
                        <?php elseif ($has_po): ?>
                            <span style="font-size:11px;color:#28a745;font-weight:600;">
                                <i class="fas fa-check-circle"></i> PO: <?php echo htmlspecialchars($req['po_number']); ?>
                            </span>
                        <?php else: ?>
                            <span style="font-size:11px;color:#6c757d;">Not ready</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($merch_requests)): ?>
                <tr>
                    <td colspan="11" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-box" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.2;"></i>
                        No merchandise stock requests yet.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Fuel Stock Requests -->
<div class="card" style="padding:0;">
    <div style="background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);color:#fff;padding:16px 24px;border-radius:12px 12px 0 0;">
        <h3 style="margin:0;font-size:17px;font-weight:700;"><i class="fas fa-gas-pump" style="margin-right:8px;"></i> Fuel Stock Requests</h3>
        <p style="margin:6px 0 0 0;font-size:12px;opacity:0.9;">Pending fuel requests awaiting approval/rejection</p>
    </div>
    <div class="fsr-table-wrap">
        <table class="fsr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Fuel Type</th>
                    <th>Current Level</th>
                    <th>Status</th>
                    <th>Requested (L)</th>
                    <th>Approved (L)</th>
                    <th>Manager Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                <?php
                    $st  = $req['status'] ?? 'Pending';
                    $cls = 'fsr-badge-' . strtolower($st);
                    $stockSt = $req['stock_status'] ?? 'LOW';
                    $stockCls = in_array($stockSt, ['OUT OF STOCK', 'CRITICAL']) ? '#dc3545' : '#fd7e14';
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;color:#888;">#<?php echo $req['id']; ?></td>
                    <td style="font-size:12px;"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($req['staff_name']); ?></td>
                    <td><strong><?php echo htmlspecialchars($req['fuel_type']); ?></strong></td>
                    <td>
                        <?php echo number_format($req['current_level'], 2); ?> L
                        <span style="color:<?php echo $stockCls; ?>;font-size:11px;font-weight:700;display:block;">
                            <?php echo htmlspecialchars($stockSt); ?>
                        </span>
                    </td>
                    <td><span class="fsr-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                    <td style="font-weight:700;text-align:center;"><?php echo number_format($req['requested_liters'], 2); ?></td>
                    <td style="text-align:center;">
                        <?php if ($req['approved_liters'] !== null): ?>
                            <strong style="color:#28a745;"><?php echo number_format($req['approved_liters'], 2); ?></strong>
                        <?php else: ?>
                            <span style="color:#adb5bd;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($req['manager_notes'] ?? ''); ?>">
                        <?php echo $req['manager_notes'] ? htmlspecialchars($req['manager_notes']) : '<span style="color:#adb5bd;">—</span>'; ?>
                    </td>
                    <td>
                        <?php if ($st === 'Pending'): ?>
                            <button class="fsr-btn fsr-btn-approve" onclick="openApproveModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['fuel_type'], ENT_QUOTES); ?>', <?php echo $req['requested_liters']; ?>)">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="fsr-btn fsr-btn-reject" onclick="openRejectModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars($req['fuel_type'], ENT_QUOTES); ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php else: ?>
                            <span style="font-size:11px;color:#6c757d;">Processed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-gas-pump" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.2;"></i>
                        No fuel stock requests yet.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;margin-right:7px;"></i> Approve Fuel Request</div>
            <button class="modal-close" onclick="closeModal('approveModal')" title="Close">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="approve_id">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#888;margin-bottom:6px;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;color:#212529;font-size:16px;" id="approve_fuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Approved Liters <span style="color:red;">*</span></label>
                <input type="number" name="approved_liters" id="approve_liters" step="0.01" min="0.01" required
                       style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Manager Notes</label>
                <textarea name="manager_notes" rows="3" placeholder="Optional notes..."
                          style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-check"></i> Confirm Approve</button>
                <button type="button" onclick="closeModal('approveModal')" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;margin-right:7px;"></i> Reject Fuel Request</div>
            <button class="modal-close" onclick="closeModal('rejectModal')" title="Close">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject_id">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#888;margin-bottom:6px;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;color:#212529;font-size:16px;" id="reject_fuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Rejection Reason <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="3" required placeholder="Explain why this request is rejected..."
                          style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-danger btn-lg"><i class="fas fa-times"></i> Confirm Reject</button>
                <button type="button" onclick="closeModal('rejectModal')" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Generate PO Modal -->
<div id="generatePOModal" class="modal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-file-invoice" style="color:#667eea;margin-right:7px;"></i> Generate Purchase Order</div>
            <button class="modal-close" onclick="closeModal('generatePOModal')" title="Close">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="generate_po">
            <input type="hidden" name="request_id" id="po_request_id">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#888;margin-bottom:6px;text-transform:uppercase;">Item Name</div>
                <div style="font-weight:700;color:#212529;font-size:16px;" id="po_item_name">—</div>
            </div>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:14px;">
                <div style="font-size:12px;color:#856404;margin-bottom:6px;"><i class="fas fa-info-circle"></i> <strong>Approved Quantity</strong></div>
                <div style="font-weight:700;color:#212529;font-size:20px;text-align:center;" id="po_quantity">—</div>
            </div>
            <div style="padding:12px;background:#e7f3ff;border-radius:8px;font-size:13px;color:#004085;margin-bottom:14px;">
                <i class="fas fa-lightbulb" style="margin-right:6px;"></i>
                This will create a Purchase Order with status "Pending Admin Validation" and send it to the Admin for final approval.
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-file-invoice"></i> Generate PO</button>
                <button type="button" onclick="closeModal('generatePOModal')" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.alert-success { background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px; }
.alert-error   { background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px; }

.fsr-summary-row { display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px; }
.fsr-card { flex:1;background:#fff;border:1px solid#e2e8f0;border-radius:10px;padding:14px 18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05); }
.fsr-card-num { font-size:26px;font-weight:800;color:#002F6C; }
.fsr-card-lbl { font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-top:2px; }
.fsr-card-total .fsr-card-num { color:#002F6C; }
.fsr-card-pending .fsr-card-num { color:#fd7e14; }
.fsr-card-approved .fsr-card-num { color:#155724; }
.fsr-card-validated .fsr-card-num { color:#667eea; }

.fsr-btn-generate-po { background:#667eea;color:#fff; }
.fsr-btn-generate-po:hover { background:#5568d3; }

.fsr-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.fsr-table { width:100%;border-collapse:collapse;font-size:12px;}
.fsr-table thead th { background:#f8f9fa;color:#495057;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid#dee2e6;}
.fsr-table tbody td { padding:8px 10px;border-bottom:1px solid#f0f0f0;vertical-align:middle; }
.fsr-table tbody tr:hover td { background:#f8fbff; }

.fsr-badge { display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap; }
.fsr-badge-pending { background:#fff3cd;color:#856404; }
.fsr-badge-approved { background:#d1ecf1;color:#0c5460; }
.fsr-badge-rejected { background:#f8d7da;color:#721c24; }

.fsr-btn { padding:5px 12px;border:none;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;margin-right:4px;transition:all .15s; }
.fsr-btn-approve { background:#28a745;color:#fff; }
.fsr-btn-approve:hover { background:#218838; }
.fsr-btn-reject { background:#dc3545;color:#fff; }
.fsr-btn-reject:hover { background:#c82333; }

.modal { display:none;position:fixed;z-index:2000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.55); }
.modal.show { display:flex;align-items:center;justify-content:center; }
.modal-box { background:#fff;border-radius:14px;padding:28px;width:90%;max-width:520px;max-height:88vh;overflow-y:auto;position:relative;animation:modalIn .22s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
.modal-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid#e9ecef; }
.modal-title { font-size:1.05rem;font-weight:700;color:#002F70; }
.modal-close { background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1; }
.modal-close:hover { color:#333; }
.modal-footer { display:flex;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid#e9ecef; }
</style>

<script>
function openApproveModal(id, fuel, liters) {
    document.getElementById('approve_id').value = id;
    document.getElementById('approve_fuel').textContent = fuel;
    document.getElementById('approve_liters').value = liters;
    openModal('approveModal');
}

function openRejectModal(id, fuel) {
    document.getElementById('reject_id').value = id;
    document.getElementById('reject_fuel').textContent = fuel;
    openModal('rejectModal');
}

function openGeneratePOModal(id, itemName, quantity) {
    document.getElementById('po_request_id').value = id;
    document.getElementById('po_item_name').textContent = itemName;
    document.getElementById('po_quantity').textContent = quantity;
    openModal('generatePOModal');
}

function openModal(id) {
    document.getElementById(id).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    document.body.style.overflow = '';
}

document.querySelectorAll('.modal').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(function(m) {
            closeModal(m.id);
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
