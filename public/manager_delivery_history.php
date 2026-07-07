<?php
$page_id = "mgr_delivery_history";
require_once __DIR__ . "/../backend/lib.php";
require_once __DIR__ . "/db_connect.php";
require_login();

$me         = current_user();
$role       = role_key($me["role"] ?? "");
$station_id = user_station_id();

if (!in_array($role, ["manager", "admin", "superadmin"])) {
    header("Location: dashboard.php");
    exit;
}

$msg      = "";
$msg_type = "success";

/* POST — Confirm / Reject / Close */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $del_id  = (int)($_POST["delivery_id"] ?? 0);
    $act     = $_POST["action"] ?? "";
    $notes   = trim($_POST["admin_notes"] ?? "");

    $status_map = [
        "confirm" => "Approved",
        "reject"  => "Rejected",
        "close"   => "Closed",
    ];

    if ($del_id > 0 && isset($status_map[$act])) {
        try {
            $new_status = $status_map[$act];

            // Fetch the delivery row BEFORE updating status (need delivery_type, product, quantity)
            $row = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ?");
            $row->execute([$del_id, $station_id]);
            $dr = $row->fetch(PDO::FETCH_ASSOC);

            if (!$dr) {
                $msg      = "Delivery not found.";
                $msg_type = "error";
                goto end_post;
            }

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE deliveries_oversight
                SET status = ?, manager_id = ?, manager_action_at = NOW(), manager_notes = ?, updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([$new_status, $me["id"], $notes ?: null, $del_id, $station_id]);

            /* ── Inventory auto-update on Approve ── */
            if ($act === "confirm") {
                $qty     = (float)$dr["quantity"];
                $product = trim($dr["product"]);

                if ($dr["delivery_type"] === "fuel") {
                    // ── FUEL: update both current_level and current_stock ──────
                    $upd = $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = COALESCE(current_level, 0) + ?,
                            current_stock = COALESCE(current_stock, 0) + ?,
                            last_updated  = NOW(),
                            updated_by    = ?
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $upd->execute([$qty, $qty, $me["id"], $station_id, $product]);

                    if ($upd->rowCount() === 0) {
                        // Fallback: match via fuel_types join
                        $pdo->prepare("
                            UPDATE fuel_inventory fi
                            JOIN fuel_types ft ON fi.fuel_type_id = ft.id
                            SET fi.current_level = COALESCE(fi.current_level, 0) + ?,
                                fi.current_stock = COALESCE(fi.current_stock, 0) + ?,
                                fi.last_updated  = NOW(),
                                fi.updated_by    = ?
                            WHERE fi.station_id = ? AND LOWER(TRIM(ft.name)) = LOWER(TRIM(?))
                            LIMIT 1
                        ")->execute([$qty, $qty, $me["id"], $station_id, $product]);
                    }

                } else {
                    // ── MERCHANDISE: update inventory_products.stock ──────────
                    $upd = $pdo->prepare("
                        UPDATE inventory_products
                        SET stock = stock + ?
                        WHERE product_name = ?
                        LIMIT 1
                    ");
                    $upd->execute([$qty, $product]);

                    // Also update station_inventory.stock_level (per-station stock)
                    $upd2 = $pdo->prepare("
                        UPDATE station_inventory si
                        JOIN inventory_products ip ON si.product_id = ip.id
                        SET si.stock_level = si.stock_level + ?,
                            si.last_updated = NOW()
                        WHERE si.station_id = ? AND ip.product_name = ?
                        LIMIT 1
                    ");
                    $upd2->execute([$qty, $station_id, $product]);
                }
            }

            /* Audit trail */
            try {
                $pdo->prepare("
                    INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id, entity_type)
                    VALUES (?, ?, ?, ?, ?, ?, 'delivery')
                ")->execute([
                    $dr["delivery_ref"],
                    $me["id"],
                    ucfirst($act) . " Delivery",
                    $dr["status"],
                    $new_status,
                    $station_id
                ]);
            } catch (Exception $ae) {}

            log_activity($pdo, $me["id"], ucfirst($act) . " Delivery",
                "Delivery ID #{$del_id} ({$dr['delivery_type']}) | Product: {$dr['product']} | Qty: {$dr['quantity']} | Status: {$new_status}");

            $pdo->commit();

            $inv_note = "";
            if ($act === "confirm") {
                $inv_note = $dr["delivery_type"] === "fuel"
                    ? " Fuel inventory updated (+{$dr['quantity']} L of {$dr['product']})."
                    : " Merchandise inventory updated (+{$dr['quantity']} {$dr['unit']} of {$dr['product']}).";
            }
            $msg      = "&#10003; Delivery #{$del_id} status updated to <strong>{$new_status}</strong>.{$inv_note}";
            $msg_type = "success";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg      = "Error: " . $e->getMessage();
            $msg_type = "error";
        }
    }
    end_post:
}

/* Filters */
$filter_status   = trim($_GET["status"]   ?? "");
$filter_type     = trim($_GET["type"]     ?? "");
$filter_supplier = trim($_GET["supplier"] ?? "");
$filter_start    = trim($_GET["start"]    ?? date("Y-m-d", strtotime("-30 days")));
$filter_end      = trim($_GET["end"]      ?? date("Y-m-d"));

/* Fetch deliveries */
$deliveries = [];
$counts     = ["Pending" => 0, "Approved" => 0, "Rejected" => 0, "Closed" => 0];

try {
    /* Ensure table exists — use VARCHAR for status to avoid ENUM conflicts */
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS deliveries_oversight (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                delivery_type   VARCHAR(20)  NOT NULL DEFAULT 'merchandise',
                delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
                supplier        VARCHAR(200) NOT NULL DEFAULT '',
                product         VARCHAR(200) NOT NULL DEFAULT '',
                quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
                unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
                delivery_date   DATE         NOT NULL,
                dr_number       VARCHAR(100) DEFAULT NULL,
                encoded_by      INT          DEFAULT NULL,
                station_id      INT          NOT NULL,
                status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
                admin_id        INT          DEFAULT NULL,
                admin_action_at DATETIME     DEFAULT NULL,
                admin_notes     TEXT         DEFAULT NULL,
                remarks         TEXT         DEFAULT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_station (station_id),
                INDEX idx_status  (status),
                INDEX idx_date    (delivery_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $ce) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight MODIFY COLUMN status VARCHAR(60) NOT NULL DEFAULT 'Pending Manager Approval'"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN dr_number VARCHAR(100) DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"); } catch (Exception $ae) {}

    // Ensure manager_id and manager_notes columns exist
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $ae) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"); } catch (Exception $ae) {}

    $where  = "WHERE do2.station_id = ? AND do2.delivery_date BETWEEN ? AND ?";
    $params = [$station_id, $filter_start, $filter_end];

    if ($filter_status !== "") {
        if ($filter_status === 'Pending') {
            $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation','Pending Validation')";
        } elseif ($filter_status === 'Approved') {
            $where .= " AND do2.status IN ('Confirmed', 'Approved', 'Validated', 'Adjusted', 'Pending Admin Oversight')";
        } elseif ($filter_status === 'Rejected') {
            $where .= " AND do2.status IN ('Discrepancy', 'Rejected', 'Flagged', 'Pending Resolution', 'Awaiting Replacement', 'Returned to Supplier')";
        } else {
            $where .= " AND do2.status = ?";
            $params[] = $filter_status;
        }
    }
    if ($filter_type     !== "") { $where .= " AND do2.delivery_type = ?";   $params[] = $filter_type; }
    if ($filter_supplier !== "") { $where .= " AND do2.supplier LIKE ?";     $params[] = "%" . $filter_supplier . "%"; }

    $stmt = $pdo->prepare("
        SELECT do2.*, u_enc.name AS encoded_by_name, u_act.name AS action_by_name
        FROM deliveries_oversight do2
        LEFT JOIN users u_enc ON do2.encoded_by  = u_enc.id
        LEFT JOIN users u_act ON do2.manager_id  = u_act.id
        $where
        ORDER BY FIELD(do2.status,
            'Pending Manager Approval','Pending Manager Confirmation','Pending Validation',
            'Pending Resolution','Awaiting Replacement',
            'Discrepancy','Rejected','Flagged',
            'Confirmed','Approved','Validated','Adjusted','Pending Admin Oversight',
            'Returned to Supplier','Closed'
        ), do2.created_at DESC
    ");
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $r) {
        $s = $r["status"];
        if (strpos($s, 'Pending') !== false) {
            $counts['Pending']++;
        } elseif (in_array($s, ['Confirmed', 'Approved', 'Validated', 'Adjusted'])) {
            $counts['Approved']++;
        } elseif (in_array($s, ['Discrepancy', 'Rejected', 'Flagged', 'Pending Resolution', 'Awaiting Replacement', 'Returned to Supplier'])) {
            $counts['Rejected']++;
        } elseif ($s === 'Closed') {
            $counts['Closed']++;
        }
    }

} catch (Exception $e) {
    $msg      = "Error loading deliveries: " . $e->getMessage();
    $msg_type = "error";
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.del-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef; margin-bottom: 24px;
}
.del-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px;
}
.del-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.del-card-body  { padding: 20px; }

/* Status badges */
.del-badge-pending  { background: #fff3cd; color: #856404; border: 1px solid #ffc107; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; white-space: nowrap; display: inline-block; }
.del-badge-confirmed{ background: #d4edda; color: #155724; border: 1px solid #28a745; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; white-space: nowrap; display: inline-block; }
.del-badge-discrepancy{ background: #f8d7da; color: #721c24; border: 1px solid #dc3545; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; white-space: nowrap; display: inline-block; }
.del-badge-closed   { background: #e2e3e5; color: #383d41; border: 1px solid #6c757d; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; white-space: nowrap; display: inline-block; }

/* Summary cards */
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
.summary-card {
    background: #fff; border-radius: 10px; padding: 16px 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,.05); border: 1px solid #e9ecef;
    display: flex; flex-direction: column; gap: 4px;
}
.summary-card .sc-num  { font-size: 2rem; font-weight: 700; line-height: 1; }
.summary-card .sc-label{ font-size: 12px; color: #6c757d; font-weight: 500; }
.sc-pending   .sc-num  { color: #856404; }
.sc-confirmed .sc-num  { color: #155724; }
.sc-discrepancy .sc-num{ color: #721c24; }
.sc-closed    .sc-num  { color: #383d41; }

/* Filter bar */
.filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
.filter-bar .form-group { display: flex; flex-direction: column; gap: 4px; }
.filter-bar label { font-size: 12px; font-weight: 600; color: #495057; }
.filter-bar input, .filter-bar select {
    border: 1px solid #ced4da; border-radius: 6px;
    padding: 7px 10px; font-size: 13px;
}
.filter-bar input:focus, .filter-bar select:focus {
    border-color: #002F70; outline: 0; box-shadow: 0 0 0 .15rem rgba(0,47,112,.15);
}

/* Table */
.del-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.del-table th { background: #f8f9fa; color: #495057; font-weight: 700; padding: 10px 12px; text-align: left; border-bottom: 2px solid #dee2e6; white-space: nowrap; }
.del-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.del-table tr:hover td { background: #f8f9fa; }
.del-table .type-fuel  { color: #002F70; font-weight: 600; }
.del-table .type-merch { color: #28a745; font-weight: 600; }

/* Action buttons */
.btn-sm-confirm { background: #28a745; color: #fff; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; }
.btn-sm-reject  { background: #dc3545; color: #fff; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; }
.btn-sm-close   { background: #6c757d; color: #fff; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; }
.btn-sm-view    { background: #002F70; color: #fff; border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 4px; }

/* Alert */
.alert-success-del { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; }
.alert-error-del   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; }

/* Modal */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9000; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-box { background: #fff; border-radius: 12px; padding: 28px; max-width: 480px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
.modal-title { font-size: 1.1rem; font-weight: 700; color: #002F70; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; }
.btn-record-del { background: #002F70; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.btn-record-del:hover { background: #003d99; color: #fff; }
.btn-cancel-del { background: #6c757d; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-truck"></i> Manager &ndash; Delivery History</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; All deliveries (Fuel &amp; Merchandise) &mdash; history &amp; actions</div>
    </div>
    <div class="header-actions">
        <a href="manager_merchandise_deliveries.php" class="btn-record-del">
            <i class="fas fa-tasks"></i> Manage Deliveries
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>-del">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card sc-pending">
        <div class="sc-num"><?php echo $counts['Pending']; ?></div>
        <div class="sc-label"><i class="fas fa-clock"></i> Pending Approval</div>
    </div>
    <div class="summary-card sc-confirmed">
        <div class="sc-num"><?php echo $counts['Approved']; ?></div>
        <div class="sc-label"><i class="fas fa-check-circle"></i> Approved</div>
    </div>
    <div class="summary-card sc-discrepancy">
        <div class="sc-num"><?php echo $counts['Rejected']; ?></div>
        <div class="sc-label"><i class="fas fa-times-circle"></i> Rejected / Discrepancy</div>
    </div>
    <div class="summary-card sc-closed">
        <div class="sc-num"><?php echo $counts['Closed']; ?></div>
        <div class="sc-label"><i class="fas fa-lock"></i> Closed</div>
    </div>
</div>

<!-- Filters + Table -->
<div class="del-card">
    <div class="del-card-head">
        <div class="del-card-title"><i class="fas fa-list"></i> Delivery Records</div>
        <span style="font-size:12px;color:#6c757d;"><?php echo count($deliveries); ?> record(s) found</span>
    </div>
    <div class="del-card-body">

        <!-- Filter Bar -->
        <form method="GET" id="filterForm">
            <div class="filter-bar">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="Pending"  <?php echo $filter_status === 'Pending'  ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="Approved" <?php echo $filter_status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $filter_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="Closed"   <?php echo $filter_status === 'Closed'   ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type">
                        <option value="">All Types</option>
                        <option value="fuel"        <?php echo $filter_type === 'fuel'        ? 'selected' : ''; ?>>Fuel</option>
                        <option value="merchandise" <?php echo $filter_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Supplier</label>
                    <input type="text" name="supplier" placeholder="Search supplier..." value="<?php echo htmlspecialchars($filter_supplier); ?>">
                </div>
                <div class="form-group">
                    <label>From</label>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($filter_start); ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($filter_end); ?>">
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" style="background:#002F70;color:#fff;border:none;padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <a href="manager_delivery_history.php" style="background:#6c757d;color:#fff;border:none;padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>

        <!-- Table -->
        <?php if (empty($deliveries)): ?>
        <div style="text-align:center;padding:48px 20px;color:#6c757d;">
            <i class="fas fa-truck" style="font-size:3rem;margin-bottom:12px;opacity:.3;"></i>
            <p style="font-size:15px;margin:0;">No delivery records found for the selected filters.</p>
            <a href="manager_record_delivery.php" style="display:inline-block;margin-top:16px;background:#002F70;color:#fff;padding:9px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">
                <i class="fas fa-plus"></i> Record First Delivery
            </a>
        </div>
        <?php else: ?>
        <div style="overflow:hidden;">
            <table class="del-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Type</th>
                        <th>Supplier Name</th>
                        <th>Product / Fuel Type</th>
                        <th>Quantity Delivered</th>
                        <th>Date &amp; Time</th>
                        <th>Encoded By (Staff)</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Manager Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deliveries as $d): ?>
                    <?php
                    $status = (string)$d['status'];
                    $lower_status = strtolower($status);
                    $is_pending  = (strpos($lower_status, 'pending') !== false);
                    $is_approved = in_array($lower_status, ['confirmed', 'approved']);
                    $is_rejected = in_array($lower_status, ['discrepancy', 'rejected']);
                    
                    $badge_class = match(true) {
                        $is_pending  => 'del-badge-pending',
                        $is_approved => 'del-badge-confirmed',
                        $is_rejected => 'del-badge-discrepancy',
                        $lower_status === 'closed' => 'del-badge-closed',
                        default      => 'del-badge-pending',
                    };
                    $badge_label = match(true) {
                        $is_pending  => 'Pending',
                        $is_approved => 'Approved',
                        $is_rejected => 'Rejected',
                        default      => ucfirst($status),
                    };
                    ?>
                    <tr>
                        <td><strong style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($d['delivery_ref']); ?></strong></td>
                        <td><span class="<?php echo $d['delivery_type'] === 'fuel' ? 'type-fuel' : 'type-merch'; ?>" style="font-size:12px;"><?php echo ucfirst($d['delivery_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($d['supplier']); ?></td>
                        <td><?php echo htmlspecialchars($d['product']); ?></td>
                        <td><?php echo number_format((float)$d['quantity'], 2); ?> <?php echo htmlspecialchars($d['unit']); ?></td>
                        <td style="white-space:nowrap;"><?php echo date('M j, Y', strtotime($d['delivery_date'])); ?><br><span style="font-size:11px;color:#6c757d;"><?php echo date('h:i A', strtotime($d['created_at'])); ?></span></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($d['encoded_by_name'] ?? '—'); ?></td>
                        <td><span class="<?php echo $badge_class; ?>"><?php echo htmlspecialchars($badge_label); ?></span></td>
                        <td style="font-size:12px;color:#6c757d;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($d['remarks'] ?? ''); ?>">
                            <?php echo (!empty($d['remarks']) && trim($d['remarks']) !== '') ? htmlspecialchars($d['remarks']) : '—'; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <?php if ($is_pending): ?>
                                <button class="btn-sm-confirm" onclick="openAction(<?php echo $d['id']; ?>, 'confirm', '<?php echo htmlspecialchars($d['delivery_ref'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-sm-reject" onclick="openAction(<?php echo $d['id']; ?>, 'reject', '<?php echo htmlspecialchars($d['delivery_ref'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <?php elseif ($is_rejected): ?>
                                <button class="btn-sm-confirm" onclick="openAction(<?php echo $d['id']; ?>, 'confirm', '<?php echo htmlspecialchars($d['delivery_ref'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-sm-close" onclick="openAction(<?php echo $d['id']; ?>, 'close', '<?php echo htmlspecialchars($d['delivery_ref'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-lock"></i> Close
                                </button>
                                <?php elseif ($is_approved): ?>
                                <button class="btn-sm-close" onclick="openAction(<?php echo $d['id']; ?>, 'close', '<?php echo htmlspecialchars($d['delivery_ref'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-lock"></i> Close
                                </button>
                                <?php endif; ?>
                                <button class="btn-sm-view" onclick="viewDelivery(<?php echo $d['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
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

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-title"><i class="fas fa-truck"></i> Delivery Details</div>
        <div id="viewModalContent" style="font-size:14px;line-height:1.8;"></div>
        <div class="modal-actions">
            <button class="btn-cancel-del" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Action Modal (Confirm / Reject / Close) -->
<div class="modal-overlay" id="actionModal">
    <div class="modal-box">
        <div class="modal-title" id="actionModalTitle"><i class="fas fa-check-circle"></i> Confirm Delivery</div>
        <form method="POST" id="actionForm">
            <input type="hidden" name="delivery_id" id="actionDeliveryId">
            <input type="hidden" name="action"      id="actionType">
            <div style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:#495057;display:block;margin-bottom:6px;">
                    Manager Remarks / Reason <span style="font-size:11px;color:#6c757d;">(Required for Rejection)</span>
                </label>
                <textarea name="admin_notes" id="actionNotes" rows="3"
                    style="width:100%;border:1px solid #ced4da;border-radius:6px;padding:9px 12px;font-size:13px;resize:vertical;"
                    placeholder="Enter remarks for the staff..."></textarea>
            </div>
            <div id="actionWarning" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:13px;color:#856404;margin-bottom:14px;">
                <i class="fas fa-exclamation-triangle"></i> <span id="actionWarningText"></span>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-del" onclick="closeModal('actionModal')">Cancel</button>
                <button type="submit" id="actionSubmitBtn" class="btn-sm-confirm" style="padding:9px 20px;font-size:13px;">
                    <i class="fas fa-check"></i> Submit
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delivery data for JS -->
<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id')); ?>;

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;
    
    let statusLabel = d.status;
    if (d.status.includes('Pending')) statusLabel = 'Pending';
    if (d.status === 'Confirmed' || d.status === 'Approved') statusLabel = 'Approved';
    if (d.status === 'Discrepancy' || d.status === 'Rejected') statusLabel = 'Rejected';
    
    const statusColors = {
        'Pending': '#856404',
        'Approved': '#155724',
        'Rejected': '#721c24',
        'Closed': '#383d41'
    };
    const color = statusColors[statusLabel] || '#333';
    document.getElementById('viewModalContent').innerHTML = `
        <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:6px 0;color:#6c757d;width:150px;">Delivery ID</td><td style="padding:6px 0;font-family:monospace;font-weight:700;">${d.delivery_ref}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Supplier Name</td><td style="padding:6px 0;font-weight:600;">${d.supplier}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Product / Fuel Type</td><td style="padding:6px 0;">${d.product} <span style="font-size:12px;color:#6c757d;">(${d.delivery_type})</span></td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Quantity Delivered</td><td style="padding:6px 0;font-weight:600;">${parseFloat(d.quantity).toFixed(2)} ${d.unit}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Date & Time</td><td style="padding:6px 0;">${d.delivery_date} / ${d.created_at}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Encoded By (Staff)</td><td style="padding:6px 0;">${d.encoded_by_name || '—'}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Supplier DR #</td><td style="padding:6px 0;font-family:monospace;">${d.dr_number || '—'}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Status</td><td style="padding:6px 0;font-weight:700;color:${color};">${statusLabel}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Staff Remarks</td><td style="padding:6px 0;">${d.remarks || '—'}</td></tr>
            <tr><td style="padding:6px 0;color:#6c757d;">Manager Remarks</td><td style="padding:6px 0;color:#d9534f;font-weight:600;">${d.admin_notes || '—'}</td></tr>
        </table>
    `;
    document.getElementById('viewModal').classList.add('show');
}

function openAction(id, action, ref) {
    document.getElementById('actionDeliveryId').value = id;
    document.getElementById('actionType').value = action;
    document.getElementById('actionNotes').value = '';

    const titles = {
        confirm: '<i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Delivery',
        reject:  '<i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Delivery',
        close:   '<i class="fas fa-lock" style="color:#6c757d;"></i> Close Delivery'
    };
    const warnings = {
        confirm: `Approving delivery <strong>${ref}</strong> will automatically update the inventory stock.`,
        reject:  `Rejecting delivery <strong>${ref}</strong> will send it back to Staff for correction. Please add remarks.`,
        close:   `Closing delivery <strong>${ref}</strong> will finalize the record permanently.`
    };
    const btnColors = { confirm: '#28a745', reject: '#dc3545', close: '#6c757d' };
    const btnLabels = { confirm: 'Approve', reject: 'Reject', close: 'Close Delivery' };

    document.getElementById('actionModalTitle').innerHTML = titles[action];
    document.getElementById('actionWarning').style.display = 'block';
    document.getElementById('actionWarningText').innerHTML = warnings[action];
    
    // Require notes for rejection
    const notesField = document.getElementById('actionNotes');
    if(action === 'reject') {
        notesField.required = true;
        notesField.placeholder = "Required: Explain why this delivery is rejected...";
    } else {
        notesField.required = false;
        notesField.placeholder = "Optional: Add any remarks...";
    }

    const btn = document.getElementById('actionSubmitBtn');
    btn.style.background = btnColors[action];
    btn.innerHTML = `<i class="fas ${action === 'confirm' ? 'fa-check' : (action === 'reject' ? 'fa-times' : 'fa-lock')}"></i> ${btnLabels[action]}`;

    document.getElementById('actionModal').classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
