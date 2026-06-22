<?php
/**
 * Manager Transaction Monitoring
 * View and validate all station transactions by shift
 * Correct transactions, void transactions, add correction notes
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = (int)user_station_id();
$role = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'supervisor'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php'); exit;
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// ── POST Actions (Approve, Reject, Adjust, Void) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE merchandise_transactions 
                SET validation_status = 'Approved',
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
            ");
            $stmt->execute([$me['id'], $notes, $txn_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit
                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, station_id, ip_address, created_at)
                    VALUES (?, 'TRANSACTION', 'Approve', ?, 'merchandise_transactions', ?, ?, ?, NOW())
                ")->execute([
                    $me['id'],
                    "Transaction #{$txn_id} approved" . ($notes ? " - Notes: {$notes}" : ""),
                    $txn_id,
                    $station_id,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $_SESSION['success'] = 'Transaction approved successfully';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed';
            }
        }
        elseif ($action === 'reject') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('Rejection reason is required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE merchandise_transactions 
                SET validation_status = 'Rejected',
                    validated_by = ?,
                    validated_at = NOW(),
                    rejection_reason = ?,
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
            ");
            $stmt->execute([$me['id'], $reason, "Rejected: {$reason}", $txn_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log audit
                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, station_id, ip_address, created_at)
                    VALUES (?, 'TRANSACTION', 'Reject', ?, 'merchandise_transactions', ?, ?, ?, NOW())
                ")->execute([
                    $me['id'],
                    "Transaction #{$txn_id} rejected - Reason: {$reason}",
                    $txn_id,
                    $station_id,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                // Send notification to staff
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, event_type, severity, created_at)
                    SELECT staff_id, 'transaction', 'Transaction Rejected', ?, 'transaction_rejected', 'medium', NOW()
                    FROM merchandise_transactions WHERE id = ?
                ")->execute(["Your transaction #{$txn_id} was rejected: {$reason}", $txn_id]);
                
                $_SESSION['success'] = 'Transaction rejected and returned to staff';
            } else {
                $_SESSION['error'] = 'Transaction not found or already processed';
            }
        }
        elseif ($action === 'adjust') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $new_quantity = (int)($_POST['quantity'] ?? 0);
            $new_rate = (float)($_POST['rate'] ?? 0);
            $new_payment_type = $_POST['payment_type'] ?? '';
            $new_payment_status = $_POST['payment_status'] ?? '';
            $correction_reason = trim($_POST['correction_reason'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            
            if (empty($correction_reason)) {
                throw new Exception('Correction reason is required');
            }
            
            // Get original transaction
            $orig_stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ?");
            $orig_stmt->execute([$txn_id, $station_id]);
            $orig = $orig_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$orig) {
                throw new Exception('Transaction not found');
            }
            
            $new_total = $new_quantity * $new_rate;
            
            // Update transaction (preserve original, mark as adjusted)
            $stmt = $pdo->prepare("
                UPDATE merchandise_transactions 
                SET quantity = ?,
                    unit_price = ?,
                    total_amount = ?,
                    payment_method = ?,
                    payment_status = ?,
                    validation_status = 'Adjusted',
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            
            $manager_note = "Adjusted: {$correction_reason}";
            if ($remarks) $manager_note .= " | {$remarks}";
            
            $stmt->execute([
                $new_quantity, $new_rate, $new_total,
                $new_payment_type, $new_payment_status,
                $me['id'], $manager_note, $txn_id, $station_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                // Create audit trail with before/after values
                $audit_old = json_encode([
                    'quantity' => $orig['quantity'],
                    'rate' => $orig['unit_price'],
                    'total' => $orig['total_amount'],
                    'payment_method' => $orig['payment_method'],
                    'payment_status' => $orig['payment_status']
                ]);
                
                $audit_new = json_encode([
                    'quantity' => $new_quantity,
                    'rate' => $new_rate,
                    'total' => $new_total,
                    'payment_method' => $new_payment_type,
                    'payment_status' => $new_payment_status
                ]);
                
                $pdo->prepare("
                    INSERT INTO audit_logs (
                        user_id, log_type, action_type, action_details,
                        entity_type, entity_id, old_values, new_values,
                        station_id, ip_address, created_at
                    ) VALUES (?, 'TRANSACTION', 'Adjust', ?, 'merchandise_transactions', ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $me['id'],
                    "Transaction #{$txn_id} adjusted - Reason: {$correction_reason}",
                    $txn_id,
                    $audit_old,
                    $audit_new,
                    $station_id,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $_SESSION['success'] = 'Transaction adjusted successfully';
            } else {
                $_SESSION['error'] = 'Failed to adjust transaction';
            }
        }
        elseif ($action === 'void') {
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $void_reason = trim($_POST['void_reason'] ?? '');
            
            if (empty($void_reason)) {
                throw new Exception('Void reason is required');
            }
            
            // Soft delete - mark as voided
            $stmt = $pdo->prepare("
                UPDATE merchandise_transactions 
                SET validation_status = 'Voided',
                    validated_by = ?,
                    validated_at = NOW(),
                    manager_notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], "Voided: {$void_reason}", $txn_id, $station_id]);
            
            if ($stmt->rowCount() > 0) {
                // Reverse inventory deductions
                $items_stmt = $pdo->prepare("SELECT product_id, quantity FROM merchandise_transaction_items WHERE transaction_id = ?");
                $items_stmt->execute([$txn_id]);
                
                while ($item = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("
                        UPDATE station_inventory 
                        SET stock_level = stock_level + ?,
                            last_updated = NOW()
                        WHERE product_id = ? AND station_id = ?
                    ")->execute([$item['quantity'], $item['product_id'], $station_id]);
                }
                
                // Log audit
                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, station_id, ip_address, created_at)
                    VALUES (?, 'TRANSACTION', 'Void', ?, 'merchandise_transactions', ?, ?, ?, NOW())
                ")->execute([
                    $me['id'],
                    "Transaction #{$txn_id} voided - Reason: {$void_reason}",
                    $txn_id,
                    $station_id,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $_SESSION['success'] = 'Transaction voided successfully';
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header('Location: manager_transaction_monitoring.php');
    exit;
}


// ── FETCH TRANSACTIONS ──────────────────────────────────────────────
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_shift = $_GET['shift'] ?? '';
$filter_staff = $_GET['staff'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = ["mt.station_id = ?"];
$params = [$station_id];

if ($filter_date_from && $filter_date_to) {
    $where[] = "DATE(mt.transaction_date) BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
}

if ($filter_shift) {
    $where[] = "mt.shift_period = ?";
    $params[] = $filter_shift;
}

if ($filter_staff) {
    $where[] = "mt.staff_id = ?";
    $params[] = $filter_staff;
}

if ($filter_status) {
    $where[] = "mt.validation_status = ?";
    $params[] = $filter_status;
}

if ($filter_type) {
    $where[] = "mt.transaction_type = ?";
    $params[] = $filter_type;
}

$sql = "
    SELECT 
        mt.*,
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS staff_name,
        COUNT(mti.id) AS item_count
    FROM merchandise_transactions mt
    LEFT JOIN users u ON u.id = mt.staff_id
    LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY mt.id
    ORDER BY mt.transaction_date DESC, mt.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff list for filter
$staff_list = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE station_id = ? AND role IN ('staff','cashier','pump_attendant') ORDER BY name");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.transaction-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.transaction-table th,
.transaction-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.transaction-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #1e293b;
}
.transaction-table tr:hover {
    background: #f8fafc;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.status-adjusted { background: #dbeafe; color: #1e40af; }
.status-voided { background: #e5e7eb; color: #374151; }
.action-buttons {
    display: flex;
    gap: 8px;
}
.btn-approve { background: #10b981; color: white; }
.btn-reject { background: #ef4444; color: white; }
.btn-adjust { background: #3b82f6; color: white; }
.btn-void { background: #f97316; color: white; }
.filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
}
</style>

<div class="page-head">
    <h1>📊 Transaction Monitoring</h1>
    <div class="sub">Review and validate all station transactions</div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div class="card-title">Filter Transactions</div>
    </div>
    
    <form method="GET" class="filters">
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" class="input" value="<?php echo htmlspecialchars($filter_date_from); ?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" class="input" value="<?php echo htmlspecialchars($filter_date_to); ?>">
        </div>
        <div>
            <label>Shift</label>
            <select name="shift" class="input">
                <option value="">All Shifts</option>
                <option value="first" <?php echo $filter_shift === 'first' ? 'selected' : ''; ?>>Shift 1 (6AM-2PM)</option>
                <option value="second" <?php echo $filter_shift === 'second' ? 'selected' : ''; ?>>Shift 2 (2PM-12MN)</option>
            </select>
        </div>
        <div>
            <label>Staff</label>
            <select name="staff" class="input">
                <option value="">All Staff</option>
                <?php foreach ($staff_list as $staff): ?>
                <option value="<?php echo $staff['id']; ?>" <?php echo $filter_staff == $staff['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($staff['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status" class="input">
                <option value="">All Status</option>
                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Approved" <?php echo $filter_status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="Rejected" <?php echo $filter_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="Adjusted" <?php echo $filter_status === 'Adjusted' ? 'selected' : ''; ?>>Adjusted</option>
                <option value="Voided" <?php echo $filter_status === 'Voided' ? 'selected' : ''; ?>>Voided</option>
            </select>
        </div>
        <div>
            <label>Type</label>
            <select name="type" class="input">
                <option value="">All Types</option>
                <option value="merchandise" <?php echo $filter_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                <option value="job_order" <?php echo $filter_type === 'job_order' ? 'selected' : ''; ?>>Job Order</option>
                <option value="combined" <?php echo $filter_type === 'combined' ? 'selected' : ''; ?>>Combined</option>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn primary">Filter</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <div class="card-title">Transactions (<?php echo count($transactions); ?>)</div>
    </div>
    
    <table class="transaction-table">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Customer</th>
                <th>Shift</th>
                <th>Staff</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
            <tr>
                <td colspan="10" style="text-align: center; padding: 40px;">
                    No transactions found
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($txn['transaction_id']); ?></strong></td>
                <td><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                <td><?php echo htmlspecialchars($txn['shift_name'] ?? $txn['shift_period']); ?></td>
                <td><?php echo htmlspecialchars($txn['staff_name']); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($txn['transaction_type'])); ?></td>
                <td>₱<?php echo number_format($txn['total_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($txn['payment_method']); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($txn['transaction_date'])); ?></td>
                <td>
                    <?php 
                    $status = $txn['validation_status'];
                    $badge_class = 'status-' . strtolower($status);
                    ?>
                    <span class="status-badge <?php echo $badge_class; ?>">
                        <?php echo htmlspecialchars($status); ?>
                    </span>
                </td>
                <td>
                    <?php if ($status === 'Pending'): ?>
                    <div class="action-buttons">
                        <button class="btn btn-approve" onclick="openApproveModal(<?php echo $txn['id']; ?>, '<?php echo htmlspecialchars($txn['transaction_id'], ENT_QUOTES); ?>')">
                            ✓ Approve
                        </button>
                        <button class="btn btn-reject" onclick="openRejectModal(<?php echo $txn['id']; ?>, '<?php echo htmlspecialchars($txn['transaction_id'], ENT_QUOTES); ?>')">
                            ✗ Reject
                        </button>
                        <button class="btn btn-adjust" onclick="openAdjustModal(<?php echo $txn['id']; ?>, <?php echo htmlspecialchars(json_encode($txn), ENT_QUOTES); ?>)">
                            ⚙ Adjust
                        </button>
                        <button class="btn btn-void" onclick="openVoidModal(<?php echo $txn['id']; ?>, '<?php echo htmlspecialchars($txn['transaction_id'], ENT_QUOTES); ?>')">
                            ⊘ Void
                        </button>
                    </div>
                    <?php else: ?>
                    <span style="color: #64748b;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- Approve Modal -->
<div class="modal" id="approveModal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal('approveModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">✓ Approve Transaction</div>
            <button class="icon-btn" onclick="closeModal('approveModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="transaction_id" id="approve_txn_id">
            
            <div class="modal-body">
                <p>Transaction ID: <strong id="approve_txn_display"></strong></p>
                <div style="margin-top: 15px;">
                    <label class="form-label">Approval Notes (Optional)</label>
                    <textarea name="notes" class="input" rows="3" placeholder="Add any notes..."></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn primary">Approve Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal" id="rejectModal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal('rejectModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">✗ Reject Transaction</div>
            <button class="icon-btn" onclick="closeModal('rejectModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="transaction_id" id="reject_txn_id">
            
            <div class="modal-body">
                <p>Transaction ID: <strong id="reject_txn_display"></strong></p>
                <div style="margin-top: 15px;">
                    <label class="form-label">Rejection Reason (Required) <span style="color: red;">*</span></label>
                    <textarea name="reason" class="input" rows="3" placeholder="Enter reason for rejection..." required></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn danger">Reject Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Modal -->
<div class="modal" id="adjustModal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal('adjustModal')"></div>
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-head">
            <div class="modal-title">⚙ Adjust Transaction</div>
            <button class="icon-btn" onclick="closeModal('adjustModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="transaction_id" id="adjust_txn_id">
            
            <div class="modal-body">
                <p style="margin-bottom: 20px;">Transaction ID: <strong id="adjust_txn_display"></strong></p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" id="adjust_quantity" class="input" step="1" min="1" required>
                    </div>
                    <div>
                        <label class="form-label">Rate/Unit Price</label>
                        <input type="number" name="rate" id="adjust_rate" class="input" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div style="margin-top: 15px;">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_type" id="adjust_payment_type" class="input">
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="E-Wallet">E-Wallet</option>
                        <option value="Credit">Credit</option>
                        <option value="Fleet Card">Fleet Card</option>
                        <option value="Petron E-Fuel">Petron E-Fuel</option>
                    </select>
                </div>
                
                <div style="margin-top: 15px;">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" id="adjust_payment_status" class="input">
                        <option value="Paid">Paid</option>
                        <option value="Partial">Partial</option>
                        <option value="Unpaid">Unpaid</option>
                    </select>
                </div>
                
                <div style="margin-top: 15px;">
                    <label class="form-label">Correction Reason (Required) <span style="color: red;">*</span></label>
                    <textarea name="correction_reason" class="input" rows="2" placeholder="Why is this adjustment needed?" required></textarea>
                </div>
                
                <div style="margin-top: 15px;">
                    <label class="form-label">Additional Remarks (Optional)</label>
                    <textarea name="remarks" class="input" rows="2" placeholder="Any additional notes..."></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="btn primary">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Void Modal -->
<div class="modal" id="voidModal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal('voidModal')"></div>
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">⊘ Void Transaction</div>
            <button class="icon-btn" onclick="closeModal('voidModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="transaction_id" id="void_txn_id">
            
            <div class="modal-body">
                <p>Transaction ID: <strong id="void_txn_display"></strong></p>
                <p style="color: #ef4444; margin-top: 10px;">⚠ This action will void the transaction and reverse inventory deductions.</p>
                <div style="margin-top: 15px;">
                    <label class="form-label">Void Reason (Required) <span style="color: red;">*</span></label>
                    <textarea name="void_reason" class="input" rows="3" placeholder="Enter reason for voiding this transaction..." required></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn ghost" onclick="closeModal('voidModal')">Cancel</button>
                <button type="submit" class="btn danger">Void Transaction</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(id, txnId) {
    document.getElementById('approve_txn_id').value = id;
    document.getElementById('approve_txn_display').textContent = txnId;
    document.getElementById('approveModal').style.display = 'flex';
}

function openRejectModal(id, txnId) {
    document.getElementById('reject_txn_id').value = id;
    document.getElementById('reject_txn_display').textContent = txnId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function openAdjustModal(id, txnData) {
    document.getElementById('adjust_txn_id').value = id;
    document.getElementById('adjust_txn_display').textContent = txnData.transaction_id;
    document.getElementById('adjust_quantity').value = txnData.quantity || 1;
    document.getElementById('adjust_rate').value = txnData.unit_price || 0;
    document.getElementById('adjust_payment_type').value = txnData.payment_method || 'Cash';
    document.getElementById('adjust_payment_status').value = txnData.payment_status || 'Paid';
    document.getElementById('adjustModal').style.display = 'flex';
}

function openVoidModal(id, txnId) {
    document.getElementById('void_txn_id').value = id;
    document.getElementById('void_txn_display').textContent = txnId;
    document.getElementById('voidModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Modal styling
document.querySelectorAll('.modal').forEach(modal => {
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100%';
    modal.style.height = '100%';
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    modal.style.zIndex = '9999';
});

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.style.position = 'absolute';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.background = 'rgba(0,0,0,0.5)';
});

document.querySelectorAll('.modal-card').forEach(card => {
    card.style.position = 'relative';
    card.style.background = 'white';
    card.style.borderRadius = '8px';
    card.style.maxWidth = '500px';
    card.style.width = '90%';
    card.style.maxHeight = '90vh';
    card.style.overflow = 'auto';
    card.style.boxShadow = '0 20px 25px -5px rgba(0,0,0,0.1)';
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
