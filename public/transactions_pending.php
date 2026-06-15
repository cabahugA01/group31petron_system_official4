<?php
$page_id = 'pending_validation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

$allowed_roles = ['manager', 'admin', 'superadmin'];
if (!in_array($role, $allowed_roles)) {
    $_SESSION['error'] = 'Access denied. Manager access required for Transactions Oversight.';
    header('Location: dashboard.php');
    exit;
}

// Handle transaction actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_transaction') {
        $transaction_id = $_POST['transaction_id'] ?? '';
        $transaction_type = $_POST['transaction_type'] ?? '';
        try {
            $updated = false;
            if ($transaction_type === 'fuel') {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM fuel_transactions WHERE transaction_id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("UPDATE fuel_transactions SET status = 'Verified', manager_id = ?, action = 'Approve' WHERE transaction_id = ? AND station_id = ?");
                $stmt->execute([$me['id'], $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            } else {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM merchandise_transactions WHERE id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("UPDATE merchandise_transactions SET validation_status = 'Verified', validated_by = ?, validated_at = NOW() WHERE id = ? AND station_id = ?");
                $stmt->execute([$me['id'], $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            }
            if ($updated) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, station_id) VALUES (?, ?, 'Approve', ?)");
                    $stmt->execute([$transaction_id, $me['id'], $station_id]);
                } catch(Exception $ae) {}
                $_SESSION['success'] = 'Transaction approved/verified successfully';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error approving transaction: ' . $e->getMessage();
        }
        header('Location: transactions_pending.php');
        exit;
    }

    if ($action === 'reject_transaction') {
        $transaction_id = $_POST['transaction_id'] ?? '';
        $transaction_type = $_POST['transaction_type'] ?? '';
        $reason = $_POST['reason'] ?? '';
        try {
            $updated = false;
            if ($transaction_type === 'fuel') {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM fuel_transactions WHERE transaction_id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("UPDATE fuel_transactions SET status = 'Rejected', manager_id = ?, action = 'Reject', reason = ? WHERE transaction_id = ? AND station_id = ?");
                $stmt->execute([$me['id'], $reason, $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            } else {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM merchandise_transactions WHERE id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("UPDATE merchandise_transactions SET validation_status = 'Rejected', validated_by = ?, validated_at = NOW(), rejection_reason = ? WHERE id = ? AND station_id = ?");
                $stmt->execute([$me['id'], $reason, $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            }
            if ($updated) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?, ?, 'Reject', ?, ?)");
                    $stmt->execute([$transaction_id, $me['id'], $reason, $station_id]);
                } catch(Exception $ae) {}
                $_SESSION['success'] = 'Transaction rejected successfully';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error rejecting transaction: ' . $e->getMessage();
        }
        header('Location: transactions_pending.php');
        exit;
    }

    if ($action === 'adjust_transaction') {
        $transaction_id = $_POST['transaction_id'] ?? '';
        $transaction_type = $_POST['transaction_type'] ?? '';
        $new_value = $_POST['new_value'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        try {
            $updated = false;
            $old_amount = 0;
            if ($transaction_type === 'fuel') {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM fuel_transactions WHERE transaction_id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("SELECT total_amount FROM fuel_transactions WHERE transaction_id = ? AND station_id = ?");
                $stmt->execute([$transaction_id, $station_id]);
                $old_amount = $stmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE fuel_transactions SET status = 'Adjusted', total_amount = ?, notes = ?, manager_id = ? WHERE transaction_id = ? AND station_id = ?");
                $stmt->execute([$new_value, $remarks, $me['id'], $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            } else {
                if (!$station_id) {
                    $stmt = $pdo->prepare("SELECT station_id FROM merchandise_transactions WHERE id = ?");
                    $stmt->execute([$transaction_id]);
                    $station_id = $stmt->fetchColumn();
                }
                $stmt = $pdo->prepare("SELECT total_amount FROM merchandise_transactions WHERE id = ? AND station_id = ?");
                $stmt->execute([$transaction_id, $station_id]);
                $old_amount = $stmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE merchandise_transactions SET validation_status = 'Adjusted', total_amount = ?, adjustment_reason = ?, validated_by = ?, validated_at = NOW() WHERE id = ? AND station_id = ?");
                $stmt->execute([$new_value, $remarks, $me['id'], $transaction_id, $station_id]);
                $updated = $stmt->rowCount() > 0;
            }
            if ($updated) {
                try {
                    $old_val = json_encode(['total_amount' => $old_amount]);
                    $new_val = json_encode(['total_amount' => $new_value, 'remarks' => $remarks]);
                    $stmt = $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, old_value, new_value, station_id) VALUES (?, ?, 'Adjust', ?, ?, ?)");
                    $stmt->execute([$transaction_id, $me['id'], $old_val, $new_val, $station_id]);
                } catch(Exception $ae) {}
                $_SESSION['success'] = 'Transaction adjusted successfully';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error adjusting transaction: ' . $e->getMessage();
        }
        header('Location: transactions_pending.php');
        exit;
    }
}

// Get pending transactions
$pending_transactions = [];
try {
    $fuel_sql = "
        SELECT
            ft.transaction_id,
            'Fuel' as category,
            ft.fuel_type as product_name,
            ft.total_amount,
            ft.liters_sold as quantity,
            ft.price_per_liter as unit_price,
            ft.created_at,
            u.name as staff_name,
            ft.status,
            'fuel' as transaction_type
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ? AND ft.status IN ('Pending','Pending Validation','pending','pending validation')
    ";
    $merch_sql = "
        SELECT
            mt.id as transaction_id,
            COALESCE(mti.category, 'Merchandise') as category,
            COALESCE(mti.product_name, 'Unknown Product') as product_name,
            mt.total_amount,
            COALESCE(mti.quantity, 0) as quantity,
            COALESCE(mti.unit_price, 0.00) AS unit_price,
            mt.created_at,
            u.name as staff_name,
            mt.validation_status as status,
            'merchandise' as transaction_type
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id
        WHERE mt.station_id = ? AND mt.validation_status IN ('Pending','Pending Validation','pending','pending validation')
    ";
    $combined_sql = "($fuel_sql) UNION ALL ($merch_sql) ORDER BY created_at DESC";
    $stmt = $pdo->prepare($combined_sql);
    $stmt->execute([$station_id, $station_id]);
    $pending_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_transactions = [];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-clock"></i> Pending Validation</h1>
        <div class="sub">Transactions awaiting manager approval and validation</div>
    </div>
    <div class="actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" onclick="exportPending('excel')" title="Export to Excel"
                style="background:white;color:#1d6f42;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #1d6f42;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#1d6f42';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#1d6f42'">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="exportPending('csv')" title="Export to CSV"
                style="background:white;color:#003d7a;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #003d7a;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#003d7a';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#003d7a'">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="exportPending('pdf')" title="Export to PDF"
                style="background:white;color:#dc2626;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #dc2626;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
                onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#dc2626'">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="transactions.php"
           style="background:white;color:#4b5563;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;border:1px solid #6b7280;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .15s;"
           onmouseover="this.style.background='#6b7280';this.style.color='#fff'"
           onmouseout="this.style.background='white';this.style.color='#4b5563'">
            <i class="fas fa-arrow-left"></i> Back to Transactions
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

<div class="card" style="padding:20px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;"><i class="fas fa-clock" style="color:#002F70;"></i> Pending Validation Summary</h3>
        <div style="text-align:center;background:#f0f4ff;border:1px solid #002F70;border-radius:8px;padding:12px 24px;">
            <span style="font-size:36px;font-weight:700;color:#002F70;display:block;"><?php echo count($pending_transactions); ?></span>
            <span style="font-size:14px;color:#002F70;font-weight:600;"><?php echo count($pending_transactions) == 1 ? 'Transaction' : 'Transactions'; ?> Pending Validation</span>
        </div>
    </div>
</div>

<div class="card" style="padding:0;">
    <div class="po-table-wrap">
        <table class="po-table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Staff</th>
                    <th>Type</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total Amount</th>
                    <th>Date/Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pending_transactions as $t): ?>
                <?php
                    $isFuel = ($t['transaction_type'] === 'fuel');
                    $txnIdJs = addslashes($t['transaction_id']);
                    $txnTypeJs = $t['transaction_type'];
                    $productJs = addslashes($t['product_name']);
                    $totalJs = number_format($t['total_amount'], 2);
                    $dateJs = addslashes(date('M d, Y H:i', strtotime($t['created_at'])));
                    $staffJs = addslashes($t['staff_name']);
                    $qtyJs = number_format($t['quantity'], 2);
                    $unitJs = number_format($t['unit_price'], 2);
                ?>
                <tr>
                    <td>#<?php echo htmlspecialchars($t['transaction_id']); ?></td>
                    <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    <td>
                        <span style="background:<?php echo $isFuel ? '#dc3545' : '#0d6efd'; ?>;color:white;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;">
                            <?php echo $isFuel ? 'Fuel' : 'Merchandise'; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($t['product_name']); ?></td>
                    <td><?php echo number_format($t['quantity'], 2); ?><?php echo $isFuel ? ' L' : ''; ?></td>
                    <td>&#8369;<?php echo number_format($t['unit_price'], 2); ?></td>
                    <td style="font-weight:bold;">&#8369;<?php echo number_format($t['total_amount'], 2); ?></td>
                    <td><?php echo date('M d, H:i', strtotime($t['created_at'])); ?></td>
                    <td>
                        <div class="actions-cell">
                            <button class="btn-action btn-view" onclick="viewDetails('<?php echo $txnIdJs; ?>','<?php echo $txnTypeJs; ?>','<?php echo $productJs; ?>','<?php echo $qtyJs; ?>','<?php echo $unitJs; ?>','<?php echo $totalJs; ?>','<?php echo $staffJs; ?>','<?php echo $dateJs; ?>')">
                                <i class="fas fa-search"></i> View
                            </button>
                            <form method="POST" style="display:contents;" onsubmit="return confirm('Approve this transaction?');">
                                <input type="hidden" name="action" value="approve_transaction">
                                <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($t['transaction_id']); ?>">
                                <input type="hidden" name="transaction_type" value="<?php echo $txnTypeJs; ?>">
                                <button type="submit" class="btn-action btn-approve"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <button class="btn-action btn-reject" onclick="openRejectModal('<?php echo $txnIdJs; ?>','<?php echo $txnTypeJs; ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <button class="btn-action btn-adjust" onclick="openAdjustModal('<?php echo $txnIdJs; ?>','<?php echo $txnTypeJs; ?>','<?php echo $totalJs; ?>')">
                                <i class="fas fa-edit"></i> Adjust
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($pending_transactions)): ?>
                <tr><td colspan="9">
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Pending Transactions</h3>
                        <p>All transactions have been processed and validated.</p>
                    </div>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewDetailsModal" class="txn-modal" onclick="if(event.target===this)closeViewModal()">
    <div class="txn-modal-content">
        <div class="txn-modal-header">
            <h3><i class="fas fa-search"></i> Transaction Details</h3>
            <button class="txn-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="txn-modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Transaction ID</span><span class="detail-value" id="vd_id"></span></div>
                <div class="detail-item"><span class="detail-label">Type</span><span class="detail-value" id="vd_type"></span></div>
                <div class="detail-item"><span class="detail-label">Staff</span><span class="detail-value" id="vd_staff"></span></div>
                <div class="detail-item"><span class="detail-label">Product</span><span class="detail-value" id="vd_product"></span></div>
                <div class="detail-item"><span class="detail-label">Quantity</span><span class="detail-value" id="vd_qty"></span></div>
                <div class="detail-item"><span class="detail-label">Unit Price</span><span class="detail-value" id="vd_unit"></span></div>
                <div class="detail-item"><span class="detail-label">Total Amount</span><span class="detail-value" id="vd_total" style="font-weight:bold;font-size:16px;"></span></div>
                <div class="detail-item"><span class="detail-label">Date/Time</span><span class="detail-value" id="vd_date"></span></div>
            </div>
        </div>
        <div class="txn-modal-footer">
            <button class="btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="txn-modal" onclick="if(event.target===this)closeRejectModal()">
    <div class="txn-modal-content" style="max-width:480px;">
        <div class="txn-modal-header" style="background:#fff;border-color:#e9ecef;">
            <h3 style="color:#212529;"><i class="fas fa-times-circle" style="color:#dc3545;margin-right:7px;"></i> Reject Transaction</h3>
            <button class="txn-close" style="color:#6c757d;" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateReject()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="reject_transaction">
                <input type="hidden" id="reject_txn_id" name="transaction_id">
                <input type="hidden" id="reject_txn_type" name="transaction_type">
                <div class="form-group">
                    <label class="form-label">Rejection Reason <span style="color:red;">*</span></label>
                    <textarea id="reject_reason" name="reason" class="form-control" rows="4" placeholder="Enter reason for rejection..." required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-danger"><i class="fas fa-times"></i> Confirm Reject</button>
                <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div id="adjustModal" class="txn-modal" onclick="if(event.target===this)closeAdjustModal()">
    <div class="txn-modal-content" style="max-width:500px;">
        <div class="txn-modal-header" style="background:#fff;border-color:#e9ecef;">
            <h3 style="color:#002F70;"><i class="fas fa-edit" style="margin-right:7px;"></i> Adjust Transaction</h3>
            <button class="txn-close" style="color:#6c757d;" onclick="closeAdjustModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateAdjust()">
            <div class="txn-modal-body">
                <input type="hidden" name="action" value="adjust_transaction">
                <input type="hidden" id="adjust_txn_id" name="transaction_id">
                <input type="hidden" id="adjust_txn_type" name="transaction_type">
                <div class="form-group">
                    <label class="form-label">Current Total Amount</label>
                    <input type="text" id="adjust_old_value" class="form-control" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label class="form-label">New Total Amount <span style="color:red;">*</span></label>
                    <input type="number" id="adjust_new_value" name="new_value" class="form-control" step="0.01" min="0" placeholder="Enter corrected amount..." required>
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks <span style="color:red;">*</span></label>
                    <textarea id="adjust_remarks" name="remarks" class="form-control" rows="3" placeholder="Enter reason for adjustment..." required></textarea>
                </div>
            </div>
            <div class="txn-modal-footer">
                <button type="submit" class="btn-warning"><i class="fas fa-save"></i> Save Adjustment</button>
                <button type="button" class="btn-secondary" onclick="closeAdjustModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function viewDetails(id, type, product, qty, unit, total, staff, date) {
    document.getElementById('vd_id').textContent = '#' + id;
    document.getElementById('vd_type').textContent = type.charAt(0).toUpperCase() + type.slice(1);
    document.getElementById('vd_staff').textContent = staff;
    document.getElementById('vd_product').textContent = product;
    document.getElementById('vd_qty').textContent = qty + (type === 'fuel' ? ' L' : '');
    document.getElementById('vd_unit').textContent = '\u20B1' + unit;
    document.getElementById('vd_total').textContent = '\u20B1' + total;
    document.getElementById('vd_date').textContent = date;
    document.getElementById('viewDetailsModal').style.display = 'flex';
}
function closeViewModal() { document.getElementById('viewDetailsModal').style.display = 'none'; }

function openRejectModal(id, type) {
    document.getElementById('reject_txn_id').value = id;
    document.getElementById('reject_txn_type').value = type;
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('reject_reason').focus(), 100);
}
function closeRejectModal() { document.getElementById('rejectModal').style.display = 'none'; }
function validateReject() {
    if (!document.getElementById('reject_reason').value.trim()) {
        alert('Please enter a rejection reason.');
        return false;
    }
    return confirm('Are you sure you want to reject this transaction?');
}

function openAdjustModal(id, type, currentTotal) {
    document.getElementById('adjust_txn_id').value = id;
    document.getElementById('adjust_txn_type').value = type;
    document.getElementById('adjust_old_value').value = '\u20B1' + currentTotal;
    document.getElementById('adjust_new_value').value = '';
    document.getElementById('adjust_remarks').value = '';
    document.getElementById('adjustModal').style.display = 'flex';
    setTimeout(() => document.getElementById('adjust_new_value').focus(), 100);
}
function closeAdjustModal() { document.getElementById('adjustModal').style.display = 'none'; }
function validateAdjust() {
    const newVal = document.getElementById('adjust_new_value').value;
    const remarks = document.getElementById('adjust_remarks').value.trim();
    if (!newVal || parseFloat(newVal) < 0) {
        alert('Please enter a valid new amount.');
        return false;
    }
    if (!remarks) {
        alert('Please enter remarks for the adjustment.');
        return false;
    }
    return confirm('Are you sure you want to adjust this transaction?');
}

function exportPending(format) {
    const table = document.querySelector('.po-table');
    if (!table) { alert('No pending transaction data found.'); return; }

    const filename = `Pending_Validation_Transactions`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please try again.');
            return;
        }
        const aoa = [];
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop(); // Remove "Actions"
            aoa.push(cells.map(th => th.innerText.trim()));
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) { // Skip "No records" row if it spans
                cells.pop(); // Remove "Actions"
                aoa.push(cells.map(td => td.innerText.trim()));
            } else {
                aoa.push(cells.map(td => td.innerText.trim()));
            }
        });
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, 'Pending Validation');
        XLSX.writeFile(wb, filename + '.xlsx');
    } else if (format === 'csv') {
        let csv = '';
        // Headers
        table.querySelectorAll('thead tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('th')];
            cells.pop();
            csv += cells.map(th => '"' + th.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
        });
        // Body
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [...tr.querySelectorAll('td')];
            if (cells.length > 1) {
                cells.pop();
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            } else {
                csv += cells.map(td => '"' + td.innerText.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            }
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    } else if (format === 'pdf') {
        const logo_url  = '../assets/img/Petron%20Logo.png';
        const generated = new Date().toLocaleString();
        
        // Let's clone the table and remove the last column from the print HTML
        const tableClone = table.cloneNode(true);
        tableClone.querySelectorAll('tr').forEach(tr => {
            const lastCell = tr.lastElementChild;
            if (lastCell) lastCell.remove();
        });
        
        let tableHtml = tableClone.outerHTML;
        
        let iframe = document.getElementById('print-iframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'print-iframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pending Validation Report</title>
        <style>
            @page{size:legal landscape;margin:.3in .4in;}
            *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
            body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:20px;}
            .header-container{display:flex;align-items:center;gap:15px;border-bottom:2px solid #002F70;padding-bottom:12px;margin-bottom:15px;}
            .header-container img{height:45px;}
            .header-title h1{font-size:16px;margin:0;color:#002F70;text-transform:uppercase;}
            .header-title p{font-size:10px;margin:3px 0 0;color:#666;}
            .meta-info{margin-left:auto;text-align:right;font-size:10px;color:#444;}
            table{width:100%;border-collapse:collapse;font-size:9.5px;}
            thead tr{background:#f2f2f2 !important;border-top:2px solid #002F70;border-bottom:1px solid #999;}
            thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;color:#000;}
            tbody tr{border-bottom:1px solid #ddd;}
            tbody td{padding:5px;color:#333;}
            .status-badge {border:none;background:none;padding:0;font-weight:normal;}
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Pending Validation Report</p>
                </div>
                <div class="meta-info">
                    Generated: ${generated}
                </div>
            </div>
            ${tableHtml}
        </body></html>`);
        doc.close();

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 250);
    }
}
</script>

<style>
/* ── Design System ── */
.po-table-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow-x:auto; }
.po-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
.po-table thead th { background:#002F70; color:#fff; padding:12px 14px; text-align:left; font-weight:600; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.15s; }
.po-table tbody tr:hover { background:#f5f8ff; }
.po-table tbody td { padding:11px 14px; vertical-align:middle; color:#333; }
.status-badge { display:inline-block; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; color:#333; }
.badge-pending   { color:#002F70; }
.badge-approved  { color:#28a745; }
.badge-rejected  { color:#dc3545; }
.badge-validated { color:#28a745; }
.badge-adjusted  { color:#6c757d; }
.badge-other     { color:#6c757d; }
.btn-action { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border:1px solid transparent; border-radius:6px; cursor:pointer; font-size:0.82rem; font-weight:600; text-decoration:none; transition:all 0.15s; white-space:nowrap; margin-bottom:3px; background:white !important; }
.btn-action:hover { opacity:1; }
.btn-approve { color:#16a34a !important; border-color:#16a34a !important; }
.btn-approve:hover { background:#16a34a !important; color:#fff !important; }
.btn-reject  { color:#dc2626 !important; border-color:#dc2626 !important; }
.btn-reject:hover  { background:#dc2626 !important; color:#fff !important; }
.btn-adjust  { color:#002F70 !important; border-color:#002F70 !important; }
.btn-adjust:hover  { background:#002F70 !important; color:#fff !important; }
.btn-view    { color:#4b5563 !important; border-color:#6b7280 !important; }
.btn-view:hover    { background:#6b7280 !important; color:#fff !important; }
.page-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:6px; }
.page-head h1 { margin:0 0 2px; font-size:1.4rem; font-weight:700; color:#002F70; }
.page-head .sub { font-size:0.8rem; color:#6c757d; }
.alert { padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; font-weight:500; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.actions-cell { display:flex; flex-direction:column; gap:4px; min-width:110px; }
.actions-cell .btn-action { width:100%; justify-content:center; margin-bottom:0; }
.empty-state { text-align:center; padding:60px 20px; color:#666; }
.empty-state i { font-size:3rem; color:#002F70; margin-bottom:16px; display:block; opacity:0.4; }
.empty-state h3 { font-size:1.1rem; font-weight:700; color:#333; margin:0 0 6px; }
/* Modal */
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,0.55); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:90%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,0.2); overflow:hidden; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 24px; background:#fff; color:#212529; border-bottom:1px solid #e9ecef; }
.txn-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.txn-modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.txn-modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.txn-modal-header h3 { margin:0; font-size:1.05rem; font-weight:700; color:#002F70; }
.txn-close { background:none; border:none; color:#888; font-size:1.4rem; cursor:pointer; line-height:1; }
.txn-close:hover { color:#333; }
.txn-modal-body { padding:20px 24px; overflow-y:auto; flex:1; }
.txn-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; background:#fff; border-top:1px solid #e9ecef; }
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.detail-item { background:#f8f9fa; padding:12px; border-radius:8px; border:1px solid #e9ecef; }
.detail-label { display:block; font-size:11px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; }
.detail-value { display:block; font-size:0.9rem; color:#212529; }
.form-group { margin-bottom:16px; }
.form-label { display:block; font-weight:600; color:#333; margin-bottom:6px; font-size:0.88rem; }
.form-control { width:100%; padding:9px 12px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem; box-sizing:border-box; }
.form-control:focus { outline:none; border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,0.1); }
.btn-danger    { padding:9px 20px; background:white; color:#dc3545; border:1px solid #dc3545; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }
.btn-danger:hover { background:#dc3545; color:#fff; }
.btn-warning   { padding:9px 20px; background:white; color:#002F70; border:1px solid #002F70; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }
.btn-warning:hover { background:#002F70; color:#fff; }
.btn-secondary { padding:9px 20px; background:white; color:#4b5563; border:1px solid #6b7280; border-radius:6px; font-size:0.9rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }
.btn-secondary:hover { background:#6b7280; color:#fff; }
</style>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>