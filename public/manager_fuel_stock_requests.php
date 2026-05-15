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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $req_id = (int)($_POST['request_id'] ?? 0);

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

                    $pdo->commit();
                    $_SESSION['success'] = "Fuel request approved. {$approved_liters} L of {$req['fuel_type']} confirmed.";
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

// Fetch requests
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT fsr.*, u.name AS staff_name, m.name AS manager_name
        FROM fuel_stock_requests fsr
        JOIN users u ON fsr.staff_id = u.id
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

$pending_count = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump" style="color:#c0392b;"></i> Fuel Stock Requests</h1>
        <div class="sub">Review and approve/reject staff fuel stock requests</div>
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
        <div class="fsr-card-lbl">Total Requests</div>
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
</div>

<div class="card" style="padding:0;">
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
        <div class="modal-header" style="background:#28a745;border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:18px 24px;">
            <div class="modal-title" style="color:#fff;"><i class="fas fa-check-circle"></i> Approve Fuel Request</div>
            <button class="modal-close" onclick="closeModal('approveModal')" style="color:#fff;opacity:.8;">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="approve_id">
            <div style="background:#d4edda;border:1px solid#c3e6cb;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#155724;margin-bottom:6px;">Fuel Type</div>
                <div style="font-weight:700;color:#155724;font-size:16px;" id="approve_fuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Approved Liters <span style="color:red;">*</span></label>
                <input type="number" name="approved_liters" id="approve_liters" step="0.01" min="0.01" required
                       style="width:100%;padding:10px;border:1px solid#ced4da;border-radius:6px;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Manager Notes</label>
                <textarea name="manager_notes" rows="3" placeholder="Optional notes..."
                          style="width:100%;padding:10px;border:1px solid#ced4da;border-radius:6px;resize:vertical;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" style="background:#28a745;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;cursor:pointer;">
                    <i class="fas fa-check"></i> Confirm Approve
                </button>
                <button type="button" onclick="closeModal('approveModal')" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header" style="background:#dc3545;border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:18px 24px;">
            <div class="modal-title" style="color:#fff;"><i class="fas fa-times-circle"></i> Reject Fuel Request</div>
            <button class="modal-close" onclick="closeModal('rejectModal')" style="color:#fff;opacity:.8;">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject_id">
            <div style="background:#f8d7da;border:1px solid#f5c6cb;border-radius:8px;padding:12px;margin-bottom:14px;text-align:center;">
                <div style="font-size:12px;color:#721c24;margin-bottom:6px;">Fuel Type</div>
                <div style="font-weight:700;color:#721c24;font-size:16px;" id="reject_fuel">—</div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:700;font-size:13px;margin-bottom:6px;">Rejection Reason <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="3" required placeholder="Explain why this request is rejected..."
                          style="width:100%;padding:10px;border:1px solid#ced4da;border-radius:6px;resize:vertical;"></textarea>
            </div>
            <div class="modal-footer">
                <button type="submit" style="background:#dc3545;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;cursor:pointer;">
                    <i class="fas fa-times"></i> Confirm Reject
                </button>
                <button type="button" onclick="closeModal('rejectModal')" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.alert-success { background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px; }
.alert-error   { background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px; }

.fsr-summary-row { display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px; }
.fsr-card { flex:1;min-width:120px;background:#fff;border:1px solid#e2e8f0;border-radius:10px;padding:14px 18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05); }
.fsr-card-num { font-size:26px;font-weight:800;color:#002F6C; }
.fsr-card-lbl { font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-top:2px; }
.fsr-card-total .fsr-card-num { color:#002F6C; }
.fsr-card-pending .fsr-card-num { color:#fd7e14; }
.fsr-card-approved .fsr-card-num { color:#155724; }
.fsr-card-rejected .fsr-card-num { color:#721c24; }

.fsr-table-wrap { overflow-x:auto; }
.fsr-table { width:100%;border-collapse:collapse;font-size:12px;min-width:900px; }
.fsr-table thead th { background:#f8f9fa;color:#495057;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid#dee2e6;white-space:nowrap; }
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
