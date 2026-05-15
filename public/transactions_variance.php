<?php
$page_id = 'variance_alerts';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

$allowed_roles = ['manager', 'admin', 'superadmin'];
if (!in_array($role, $allowed_roles)) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_variance') {
        $variance_id = $_POST['variance_id'] ?? '';
        $new_status = $_POST['new_status'] ?? '';
        $notes = $_POST['notes'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE variance_alerts SET status = ?, investigation_notes = ?, updated_at = NOW() WHERE id = ? AND station_id = ?");
            $stmt->execute([$new_status, $notes, $variance_id, $station_id]);
            $_SESSION['success'] = 'Variance alert updated successfully';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating variance: ' . $e->getMessage();
        }
        header('Location: transactions_variance.php');
        exit;
    }

    if ($action === 'escalate_variance') {
        $variance_id = $_POST['variance_id'] ?? '';
        $notes = $_POST['notes'] ?? '';
        try {
            $stmt = $pdo->prepare("UPDATE variance_alerts SET status = 'escalated', investigation_notes = ?, updated_at = NOW() WHERE id = ? AND station_id = ?");
            $stmt->execute([$notes, $variance_id, $station_id]);
            $_SESSION['success'] = 'Variance alert escalated to admin level';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error escalating variance: ' . $e->getMessage();
        }
        header('Location: transactions_variance.php');
        exit;
    }

    if ($action === 'export_variance') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=variance_reports_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        
        // Add formula instructions at the top
        fputcsv($output, ['VARIANCE ALERTS EXPORT - WITH FORMULAS']);
        fputcsv($output, ['INSTRUCTIONS:']);
        fputcsv($output, ['Total Column → =Qty * UnitPrice (auto-compute per row)']);
        fputcsv($output, ['Grand Total → =SUM(TotalColumn) (auto-sum all transactions)']);
        fputcsv($output, ['Count Pending/Verified/Returned → =COUNTIF(StatusRange,"Pending") and similar formulas']);
        fputcsv($output, ['Variance Check (optional) → =ActualReading - EncodedQty (for variance data)']);
        fputcsv($output, []);
        
        // Main data headers
        fputcsv($output, ['Alert ID', 'Transaction Type', 'Product/SKU', 'Variance Amount', 'Status', 'Staff', 'Date/Time', 'Notes']);
        
        $stmt = $pdo->prepare("
            SELECT
                va.id,
                va.transaction_type,
                va.item_identifier,
                va.variance_amount,
                va.status,
                u.name as staff_name,
                va.created_at,
                va.investigation_notes
            FROM variance_alerts va
            LEFT JOIN users u ON va.user_id = u.id
            WHERE va.station_id = ?
              AND va.transaction_type = 'merchandise'
            ORDER BY va.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $startRow = 7; // Data starts at row 7 (after instructions)
        $currentRow = $startRow;
        
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['id'],
                $row['transaction_type'],
                $row['item_identifier'],
                $row['variance_amount'],
                $row['status'],
                $row['staff_name'] ?? 'System',
                $row['created_at'],
                $row['investigation_notes']
            ]);
            $currentRow++;
        }
        
        // Add summary rows with formulas at the end
        $dataEndRow = $currentRow - 1;
        fputcsv($output, []);
        fputcsv($output, ['SUMMARY FORMULAS:']);
        fputcsv($output, ['Grand Total Variance', '=SUM(D' . $startRow . ':D' . $dataEndRow . ')']);
        fputcsv($output, ['Count Status:']);
        fputcsv($output, ['Pending Count', '=COUNTIF(E' . $startRow . ':E' . $dataEndRow . ',"Pending")']);
        fputcsv($output, ['Investigating Count', '=COUNTIF(E' . $startRow . ':E' . $dataEndRow . ',"Investigating")']);
        fputcsv($output, ['Resolved Count', '=COUNTIF(E' . $startRow . ':E' . $dataEndRow . ',"Resolved")']);
        fputcsv($output, ['Escalated Count', '=COUNTIF(E' . $startRow . ':E' . $dataEndRow . ',"Escalated")']);
        
        fclose($output);
        exit;
    }
}

// Get variance alerts — merchandise AND job orders, DB-driven columns
$variance_alerts = [];
try {
    // Detect available columns in variance_alerts
    $va_cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM variance_alerts")->fetchAll(PDO::FETCH_ASSOC) as $c)
        $va_cols[strtolower($c['Field'])] = true;
    $va_has = fn($col) => isset($va_cols[strtolower($col)]);

    $txn_id_expr    = $va_has('transaction_id')    ? 'va.transaction_id'    : 'NULL';
    $shift_id_expr  = $va_has('shift_id')          ? 'va.shift_id'          : 'NULL';
    $qty_actual_expr= $va_has('actual_qty')        ? 'va.actual_qty'        : ($va_has('qty_actual') ? 'va.qty_actual' : 'NULL');
    $qty_stock_expr = $va_has('stock_balance')     ? 'va.stock_balance'     : ($va_has('expected_qty') ? 'va.expected_qty' : 'NULL');
    $price_actual_expr = $va_has('actual_price')   ? 'va.actual_price'      : ($va_has('unit_price') ? 'va.unit_price' : 'NULL');
    $price_expected_expr = $va_has('expected_price') ? 'va.expected_price'  : 'NULL';
    $staff_id_expr  = $va_has('user_id')           ? 'va.user_id'           : 'NULL';

    $stmt = $pdo->prepare("
        SELECT
            va.id,
            va.transaction_type,
            {$txn_id_expr} AS transaction_id,
            va.item_identifier,
            va.variance_amount,
            {$qty_actual_expr} AS actual_qty,
            {$qty_stock_expr} AS stock_balance,
            {$price_actual_expr} AS actual_price,
            {$price_expected_expr} AS expected_price,
            {$staff_id_expr} AS staff_id,
            {$shift_id_expr} AS shift_id,
            va.status,
            va.created_at,
            va.investigation_notes,
            u.name AS staff_name
        FROM variance_alerts va
        LEFT JOIN users u ON va.user_id = u.id
        WHERE va.station_id = ?
        ORDER BY
            CASE WHEN va.status IN ('open','escalated') THEN 0 ELSE 1 END,
            va.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $variance_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $variance_alerts = [];
}

// Summary counts
$va_open = $va_investigating = $va_resolved = $va_escalated = 0;
foreach ($variance_alerts as $v) {
    $s = strtolower($v['status'] ?? 'open');
    if ($s === 'open')          $va_open++;
    elseif ($s === 'investigating') $va_investigating++;
    elseif ($s === 'resolved')  $va_resolved++;
    elseif ($s === 'escalated') $va_escalated++;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Variance Alerts</h1>
        <div class="sub">Anomaly detection for Merchandise &amp; Job Orders — Qty vs Stock, Price vs Expected</div>
    </div>
    <div class="actions">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="export_variance">
            <button type="submit" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;" onmouseover="this.style.filter='brightness(.88)'" onmouseout="this.style.filter='none'">
                <i class="fas fa-file-export"></i> Export Report
            </button>
        </form>
        <button onclick="location.reload()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#002F70;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;" onmouseover="this.style.filter='brightness(.88)'" onmouseout="this.style.filter='none'">
            <i class="fas fa-sync"></i> Refresh
        </button>
        <a href="transactions.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#6c757d;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;" onmouseover="this.style.filter='brightness(.88)'" onmouseout="this.style.filter='none'">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:18px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05);">
        <div style="font-size:24px;font-weight:800;color:#002F6C;"><?php echo count($variance_alerts); ?></div>
        <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Total Alerts</div>
    </div>
    <div style="background:#fff3cd;border:1px solid #fde68a;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#92400e;"><?php echo $va_open; ?></div>
        <div style="font-size:11px;color:#92400e;text-transform:uppercase;letter-spacing:.5px;">Open</div>
    </div>
    <div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#1e40af;"><?php echo $va_investigating; ?></div>
        <div style="font-size:11px;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;">Investigating</div>
    </div>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#065f46;"><?php echo $va_resolved; ?></div>
        <div style="font-size:11px;color:#065f46;text-transform:uppercase;letter-spacing:.5px;">Resolved</div>
    </div>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#991b1b;"><?php echo $va_escalated; ?></div>
        <div style="font-size:11px;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;">Escalated</div>
    </div>
</div>

<!-- Variance Table -->
<div class="card" style="padding:0;">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Alert ID</th>
                    <th>Transaction ID</th>
                    <th>Type</th>
                    <th>Product / SKU</th>
                    <th>Qty vs Stock</th>
                    <th>Unit Price vs Expected</th>
                    <th>Variance Amt</th>
                    <th>Staff ID</th>
                    <th>Shift ID</th>
                    <th>Date/Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($variance_alerts as $v): ?>
                <?php
                    $statusColors = [
                        'open'          => ['#ffc107','#212529'],
                        'investigating' => ['#0d6efd','#fff'],
                        'resolved'      => ['#28a745','#fff'],
                        'escalated'     => ['#dc3545','#fff'],
                    ];
                    $sc_pair = $statusColors[strtolower($v['status'] ?? 'open')] ?? ['#6c757d','#fff'];
                    $vid      = $v['id'];
                    $notesJs  = json_encode($v['investigation_notes'] ?? '');
                    $typeJs   = json_encode($v['transaction_type'] ?? '');
                    $itemJs   = json_encode($v['item_identifier'] ?? '');
                    $staffJs  = json_encode($v['staff_name'] ?? 'System');
                    $dateJs   = json_encode(date('M d, Y H:i', strtotime($v['created_at'])));
                    $varJs    = json_encode(number_format((float)$v['variance_amount'], 2));
                    $statusJs = json_encode($v['status'] ?? 'open');
                    $txnIdJs  = json_encode($v['transaction_id'] ?? '—');
                    $shiftJs  = json_encode($v['shift_id'] ?? '—');
                    $staffIdJs= json_encode($v['staff_id'] ?? '—');
                    $qtyActual = $v['actual_qty'] ?? null;
                    $qtyStock  = $v['stock_balance'] ?? null;
                    $priceActual   = $v['actual_price'] ?? null;
                    $priceExpected = $v['expected_price'] ?? null;
                ?>
                <tr>
                    <td style="font-weight:700;color:#002F6C;">#<?php echo $v['id']; ?></td>
                    <td style="font-size:11px;font-family:monospace;"><?php echo htmlspecialchars($v['transaction_id'] ?? '—'); ?></td>
                    <td>
                        <?php $txnType = strtolower($v['transaction_type'] ?? ''); ?>
                        <span style="background:<?php echo $txnType==='job_order'?'#fd7e14':'#0d6efd'; ?>;color:#fff;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">
                            <?php echo $txnType === 'job_order' ? 'JO' : 'Merch'; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($v['item_identifier'] ?? '—'); ?></td>
                    <td style="text-align:center;font-size:12px;">
                        <?php if ($qtyActual !== null || $qtyStock !== null): ?>
                        <span style="color:#dc3545;font-weight:700;"><?php echo $qtyActual !== null ? number_format((float)$qtyActual,2) : '?'; ?></span>
                        <span style="color:#888;"> / </span>
                        <span style="color:#28a745;"><?php echo $qtyStock !== null ? number_format((float)$qtyStock,2) : '?'; ?></span>
                        <?php else: ?>
                        <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-size:12px;">
                        <?php if ($priceActual !== null || $priceExpected !== null): ?>
                        <span style="color:#dc3545;font-weight:700;">&#8369;<?php echo $priceActual !== null ? number_format((float)$priceActual,2) : '?'; ?></span>
                        <span style="color:#888;"> / </span>
                        <span style="color:#28a745;">&#8369;<?php echo $priceExpected !== null ? number_format((float)$priceExpected,2) : '?'; ?></span>
                        <?php else: ?>
                        <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;color:<?php echo (float)$v['variance_amount'] > 0 ? '#dc3545' : '#28a745'; ?>;">
                        <?php echo number_format((float)$v['variance_amount'], 2); ?>
                    </td>
                    <td style="font-size:11px;color:#888;"><?php echo htmlspecialchars($v['staff_id'] ?? '—'); ?></td>
                    <td style="font-size:11px;color:#888;"><?php echo htmlspecialchars($v['shift_id'] ?? '—'); ?></td>
                    <td style="white-space:nowrap;font-size:11px;"><?php echo date('M d, H:i', strtotime($v['created_at'])); ?></td>
                    <td>
                        <span style="background:<?php echo $sc_pair[0]; ?>;color:<?php echo $sc_pair[1]; ?>;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;">
                            <?php echo ucfirst(htmlspecialchars($v['status'] ?? 'open')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="var-action-btns">
                            <button class="vab vab-view" onclick="openInvestigateModal(<?php echo $vid; ?>,<?php echo $typeJs; ?>,<?php echo $itemJs; ?>,<?php echo $varJs; ?>,<?php echo $staffJs; ?>,<?php echo $dateJs; ?>,<?php echo $statusJs; ?>,<?php echo $notesJs; ?>,<?php echo $txnIdJs; ?>,<?php echo $shiftJs; ?>,<?php echo $staffIdJs; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <?php if (strtolower($v['status'] ?? '') !== 'resolved'): ?>
                            <button class="vab vab-resolve" onclick="openResolveModal(<?php echo $vid; ?>,<?php echo $notesJs; ?>)">
                                <i class="fas fa-check-circle"></i> Resolve
                            </button>
                            <?php endif; ?>
                            <?php if (strtolower($v['status'] ?? '') !== 'escalated'): ?>
                            <button class="vab vab-escalate" onclick="openEscalateModal(<?php echo $vid; ?>,<?php echo $notesJs; ?>)">
                                <i class="fas fa-arrow-up"></i> Escalate
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($variance_alerts)): ?>
                <tr><td colspan="12" style="text-align:center;padding:48px;color:#888;">
                    <i class="fas fa-shield-alt" style="font-size:40px;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>
                    No variance alerts found. All transactions are properly accounted.
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Investigate Modal -->
<div id="investigateModal" class="txn-modal" onclick="if(event.target===this)closeInvestigateModal()">
    <div class="txn-modal-content" style="max-width:580px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-search"></i> Investigate Variance Alert</h3>
            <button class="txn-close" onclick="closeInvestigateModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateInvestigate()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="update_variance">
                <input type="hidden" id="inv_id" name="variance_id">
                <div class="detail-grid" style="margin-bottom:16px;">
                    <div class="detail-item"><span class="detail-label">Alert ID</span><span class="detail-value" id="inv_alert_id"></span></div>
                    <div class="detail-item"><span class="detail-label">Transaction ID</span><span class="detail-value" id="inv_txn_id"></span></div>
                    <div class="detail-item"><span class="detail-label">Transaction Type</span><span class="detail-value" id="inv_txn_type"></span></div>
                    <div class="detail-item"><span class="detail-label">Product/SKU</span><span class="detail-value" id="inv_item"></span></div>
                    <div class="detail-item"><span class="detail-label">Variance Amount</span><span class="detail-value" id="inv_variance"></span></div>
                    <div class="detail-item"><span class="detail-label">Staff (ID)</span><span class="detail-value" id="inv_staff"></span></div>
                    <div class="detail-item"><span class="detail-label">Shift ID</span><span class="detail-value" id="inv_shift"></span></div>
                    <div class="detail-item"><span class="detail-label">Date/Time</span><span class="detail-value" id="inv_date"></span></div>
                    <div class="detail-item" style="grid-column: 1 / -1;"><span class="detail-label">Current Status</span><span class="detail-value" id="inv_current_status"></span></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Update Status</label>
                    <select id="inv_status" name="new_status" class="form-control" required>
                        <option value="open">Open</option>
                        <option value="investigating">Investigating</option>
                        <option value="resolved">Resolved</option>
                        <option value="escalated">Escalated</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Investigation Notes</label>
                    <textarea id="inv_notes" name="notes" class="form-control" rows="4" placeholder="Enter investigation findings, actions taken, or resolution details..."></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-primary-btn"><i class="fas fa-save"></i> Update Status</button>
                <button type="button" class="btn-secondary-btn" onclick="closeInvestigateModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Annotate Modal -->
<div id="annotateModal" class="txn-modal" onclick="if(event.target===this)closeAnnotateModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-sticky-note"></i> Add/Update Notes</h3>
            <button class="txn-close" onclick="closeAnnotateModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="update_variance">
                <input type="hidden" id="ann_id" name="variance_id">
                <input type="hidden" name="new_status" id="ann_keep_status">
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="ann_notes" name="notes" class="form-control" rows="5" placeholder="Enter notes or annotations..."></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-secondary-btn"><i class="fas fa-save"></i> Save Notes</button>
                <button type="button" class="btn-secondary-btn" onclick="closeAnnotateModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="txn-modal" onclick="if(event.target===this)closeResolveModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-check-circle"></i> Resolve Variance Alert</h3>
            <button class="txn-close" onclick="closeResolveModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateResolve()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="update_variance">
                <input type="hidden" id="res_id" name="variance_id">
                <input type="hidden" name="new_status" value="resolved">
                <div class="form-group">
                    <label class="form-label">Resolution Notes <span style="color:red;">*</span></label>
                    <textarea id="res_notes" name="notes" class="form-control" rows="4" placeholder="Describe how this variance was resolved..." required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-success-btn"><i class="fas fa-check"></i> Mark as Resolved</button>
                <button type="button" class="btn-secondary-btn" onclick="closeResolveModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Escalate Modal -->
<div id="escalateModal" class="txn-modal" onclick="if(event.target===this)closeEscalateModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header">
            <h3><i class="fas fa-arrow-up"></i> Escalate to Admin</h3>
            <button class="txn-close" onclick="closeEscalateModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateEscalate()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="escalate_variance">
                <input type="hidden" id="esc_id" name="variance_id">
                <div class="form-group">
                    <label class="form-label">Escalation Notes <span style="color:red;">*</span></label>
                    <textarea id="esc_notes" name="notes" class="form-control" rows="4" placeholder="Describe why this is being escalated to admin level..." required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger-btn"><i class="fas fa-arrow-up"></i> Escalate to Admin</button>
                <button type="button" class="btn-secondary-btn" onclick="closeEscalateModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
var varianceData = <?php echo json_encode($variance_alerts); ?>;

function openInvestigateModal(id, txnType, item, variance, staff, date, status, notes, txnId, shiftId, staffId) {
    document.getElementById('inv_id').value = id;
    document.getElementById('inv_alert_id').textContent = '#' + id;
    document.getElementById('inv_txn_id').textContent   = txnId || '—';
    document.getElementById('inv_txn_type').textContent = txnType;
    document.getElementById('inv_item').textContent     = item;
    document.getElementById('inv_variance').textContent = variance;
    document.getElementById('inv_staff').textContent    = staff + (staffId && staffId !== '—' ? ' (ID: ' + staffId + ')' : '');
    document.getElementById('inv_shift').textContent    = shiftId || '—';
    document.getElementById('inv_date').textContent     = date;
    document.getElementById('inv_current_status').textContent = status.charAt(0).toUpperCase() + status.slice(1);
    document.getElementById('inv_status').value  = status;
    document.getElementById('inv_notes').value   = notes;
    document.getElementById('investigateModal').style.display = 'flex';
}
function closeInvestigateModal() { document.getElementById('investigateModal').style.display = 'none'; }
function validateInvestigate() {
    const status = document.getElementById('inv_status').value;
    const notes = document.getElementById('inv_notes').value.trim();
    if (status === 'resolved' && !notes) {
        alert('Please provide investigation notes when marking as resolved.');
        return false;
    }
    return confirm('Update this variance alert?');
}

function openAnnotateModal(id, notes) {
    var v = varianceData.find(x => x.id == id);
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_notes').value = notes;
    document.getElementById('ann_keep_status').value = v ? v.status : 'open';
    document.getElementById('annotateModal').style.display = 'flex';
    setTimeout(() => document.getElementById('ann_notes').focus(), 100);
}
function closeAnnotateModal() { document.getElementById('annotateModal').style.display = 'none'; }

function openResolveModal(id, notes) {
    document.getElementById('res_id').value = id;
    document.getElementById('res_notes').value = notes;
    document.getElementById('resolveModal').style.display = 'flex';
    setTimeout(() => document.getElementById('res_notes').focus(), 100);
}
function closeResolveModal() { document.getElementById('resolveModal').style.display = 'none'; }
function validateResolve() {
    if (!document.getElementById('res_notes').value.trim()) {
        alert('Resolution notes are required.');
        return false;
    }
    return confirm('Mark this variance as resolved?');
}

function openEscalateModal(id, notes) {
    document.getElementById('esc_id').value = id;
    document.getElementById('esc_notes').value = notes;
    document.getElementById('escalateModal').style.display = 'flex';
    setTimeout(() => document.getElementById('esc_notes').focus(), 100);
}
function closeEscalateModal() { document.getElementById('escalateModal').style.display = 'none'; }
function validateEscalate() {
    if (!document.getElementById('esc_notes').value.trim()) {
        alert('Escalation notes are required.');
        return false;
    }
    return confirm('Escalate this variance to admin level?');
}
</script>

<style>
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,0.55); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:90%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,0.2); overflow:hidden; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; background:#fff; color:#212529; border-bottom:2px solid #e9ecef; }
.txn-modal-header h3 { margin:0; font-size:16px; color:#002F6C; }
.txn-close { background:none; border:none; color:#6c757d; font-size:26px; cursor:pointer; line-height:1; }
.txn-close:hover { color:#212529; }
.txn-modal-body { padding:24px; }
.txn-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; background:#f8f9fa; border-top:1px solid #dee2e6; }
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.detail-item { background:#f8f9fa; padding:10px; border-radius:8px; border:1px solid #e9ecef; }
.detail-label { display:block; font-size:11px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px; }
.detail-value { display:block; font-size:14px; color:#212529; }
.form-group { margin-bottom:16px; }
.form-label { display:block; font-weight:600; color:#495057; margin-bottom:6px; }
.form-control { width:100%; padding:10px 12px; border:1px solid #ced4da; border-radius:6px; font-size:14px; box-sizing:border-box; }
.form-control:focus { outline:none; border-color:#0056b3; box-shadow:0 0 0 2px rgba(0,86,179,0.2); }
.btn-action { padding:5px 10px; border:none; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
.btn-investigate { background:#007bff; color:white; }
.btn-annotate { background:#6c757d; color:white; }
.btn-resolve { background:#28a745; color:white; }
.btn-escalate { background:#dc3545; color:white; }
.btn-primary-btn { padding:10px 20px; background:#0056b3; color:white; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.btn-success-btn { padding:10px 20px; background:#28a745; color:white; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.btn-danger-btn { padding:10px 20px; background:#dc3545; color:white; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.btn-secondary-btn { padding:10px 20px; background:#6c757d; color:white; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.table-wrap{overflow-x:auto;}
.table{width:100%;border-collapse:collapse;font-size:13px;}
.table th,.table td{padding:8px 12px;border-bottom:1px solid #eef1f4;text-align:center;}
.table th{font-weight:700;background:#f8f9fa;color:#2c3e50;}

/* ── Variance action buttons — stacked, matching product_management.php ── */
.var-action-btns {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: stretch;
    min-width: 90px;
}
.vab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 8px;
    border: none;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    width: 100%;
    transition: filter .15s;
}
.vab:hover { filter: brightness(.88); }
/* Match product_management.php exactly */
.vab-view    { background: #28a745; color: #fff; }  /* green     — View       */
.vab-notes   { background: #6c757d; color: #fff; }  /* grey      — Notes      */
.vab-resolve { background: #002F70; color: #fff; }  /* dark blue — Resolve    */
.vab-escalate{ background: #E3001F; color: #fff; }  /* petron red — Escalate  */
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>