<?php
/**
 * Manager / Admin — Vehicle Types Validation
 * Approve or reject vehicle types submitted by staff.
 */
$page_id = 'master_data';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
$flash_success = '';
$flash_error   = '';

// ── Handle POST actions (approve / reject / delete) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if ($id && in_array($action, ['approve', 'reject', 'delete'])) {
        try {
            if ($action === 'delete') {
                $pdo->prepare("DELETE FROM vehicle_types WHERE id = ?")->execute([$id]);
                $flash_success = 'Vehicle type deleted.';
            } else {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';
                $pdo->prepare("
                    UPDATE vehicle_types
                    SET    status = ?, reviewed_by = ?, review_note = ?
                    WHERE  id = ?
                ")->execute([$newStatus, $me['id'], $note ?: null, $id]);
                $flash_success = 'Vehicle type ' . $newStatus . '.';
            }
        } catch (Exception $e) {
            $flash_error = 'Error: ' . $e->getMessage();
        }
    }
    header('Location: manager_vehicle_types.php');
    exit;
}

// ── Ensure table exists ───────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_types (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            category     VARCHAR(100)  NOT NULL,
            vehicle_name VARCHAR(150)  NOT NULL,
            sort_order   INT           NOT NULL DEFAULT 0,
            status       ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            submitted_by INT           NULL,
            reviewed_by  INT           NULL,
            review_note  VARCHAR(255)  NULL,
            is_active    TINYINT(1)    NOT NULL DEFAULT 1,
            created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vehicle_name (vehicle_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) { /* ignore */ }

// ── Fetch all vehicle types ───────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'])) $filter = 'pending';

$where = $filter !== 'all' ? "WHERE vt.status = '$filter'" : '';

$rows = $pdo->query("
    SELECT vt.*,
           sub.name AS submitted_by_name,
           rev.name AS reviewed_by_name
    FROM   vehicle_types vt
    LEFT JOIN users sub ON sub.user_id = vt.submitted_by
    LEFT JOIN users rev ON rev.user_id = vt.reviewed_by
    $where
    ORDER  BY vt.status = 'pending' DESC, vt.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_count = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_types WHERE status = 'pending'")->fetchColumn();

include __DIR__ . '/../partials/header.php';
?>

<style>
.vt-card { background:#fff; border-radius:10px; border:1px solid #e2e8f0; overflow:hidden; margin-bottom:24px; }
.vt-card-header { padding:14px 20px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:10px; }
.vt-table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
.vt-table th { padding:14px 14px !important; text-align:left; font-size:11px; font-weight:600; color:#fff !important;
               text-transform:uppercase; letter-spacing:.3px; border:none !important; background:#002F70 !important; white-space:nowrap; }
.vt-table th:last-child { text-align:center !important; }
.vt-table td { padding:12px 14px !important; border-bottom:1px solid #e9ecef !important; vertical-align:middle; color:#212529; }
.vt-table td:last-child { text-align:center !important; border-bottom:1px solid #e9ecef !important; }
.vt-table tr:last-child td { border-bottom:1px solid #e9ecef !important; }
.vt-table tbody tr:hover td { background:#e3f2fd !important; }
.vt-table tbody tr { transition:background 0.2s ease; }
.badge-pending  { color:#4338ca !important; background:transparent !important; border:none !important; padding:0 !important; font-size:11px; font-weight:600; }
.badge-approved { color:#0d7d3e !important; background:transparent !important; border:none !important; padding:0 !important; font-size:11px; font-weight:600; }
.badge-rejected { color:#c62828 !important; background:transparent !important; border:none !important; padding:0 !important; font-size:11px; font-weight:600; }
.filter-tab { padding:9px 18px; border:none; background:#f8fafc; border-bottom:2px solid transparent;
              font-size:13px; font-weight:500; color:#64748b; cursor:pointer; transition:all .15s; }
.filter-tab.active { background:#fff; font-weight:700; color:#003d7a; border-bottom-color:#003d7a; }
.action-btn { padding:5px 12px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; border:none; }
.btn-approve { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
.btn-approve:hover { background:#dcfce7; }
.btn-reject  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.btn-reject:hover  { background:#fecaca; }
.btn-delete  { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
.btn-delete:hover  { background:#e2e8f0; }
</style>

<div class="txn-content" style="max-width:1100px;margin:0 auto;padding:24px 20px;">

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap;">
        <div style="width:44px;height:44px;background:#eff6ff;border-radius:10px;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-car" style="color:#003d7a;font-size:18px;"></i>
        </div>
        <div>
            <h1 style="font-size:20px;font-weight:800;color:#1e293b;margin:0;">Vehicle Types</h1>
            <p style="font-size:12px;color:#64748b;margin:3px 0 0;">Review and validate vehicle types submitted by staff</p>
        </div>
        <?php if ($pending_count > 0): ?>
        <span style="background:#002F70;color:#fff;font-size:11px;font-weight:800;
                     padding:3px 10px;border-radius:20px;margin-left:4px;">
            <?= $pending_count ?> pending
        </span>
        <?php endif; ?>
    </div>

    <?php if ($flash_success): ?>
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 16px;
                margin-bottom:16px;color:#166534;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_success) ?>
    </div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;
                margin-bottom:16px;color:#991b1b;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_error) ?>
    </div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <div style="display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:20px;flex-wrap:wrap;">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
        <a href="?filter=<?= $k ?>"
           class="filter-tab <?= $filter === $k ? 'active' : '' ?>"
           style="text-decoration:none;">
            <?= $label ?>
            <?php if ($k === 'pending' && $pending_count > 0): ?>
            <span style="background:#002F70;color:#fff;font-size:9px;font-weight:800;
                         padding:1px 6px;border-radius:20px;margin-left:4px;"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <div class="vt-card">
        <?php if (empty($rows)): ?>
        <div style="text-align:center;padding:48px;color:#94a3b8;">
            <i class="fas fa-car" style="font-size:28px;display:block;margin-bottom:10px;"></i>
            No <?= $filter !== 'all' ? $filter : '' ?> vehicle types found.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="vt-table">
            <thead>
                <tr>
                    <th>Vehicle Name</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Submitted By</th>
                    <th>Reviewed By</th>
                    <th>Note</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><strong style="color:#1e293b;"><?= htmlspecialchars($row['vehicle_name']) ?></strong></td>
                <td><span style="font-size:11px;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:12px;">
                    <?= htmlspecialchars($row['category']) ?></span></td>
                <td>
                    <span class="badge-<?= $row['status'] ?>">
                        <?= ucfirst($row['status']) ?>
                    </span>
                </td>
                <td style="font-size:12px;color:#475569;"><?= htmlspecialchars($row['submitted_by_name'] ?? '—') ?></td>
                <td style="font-size:12px;color:#475569;"><?= htmlspecialchars($row['reviewed_by_name'] ?? '—') ?></td>
                <td style="font-size:11px;color:#64748b;max-width:160px;">
                    <?= $row['review_note'] ? htmlspecialchars($row['review_note']) : '<span style="color:#cbd5e1;">—</span>' ?>
                </td>
                <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                    <?= date('M j, Y', strtotime($row['created_at'])) ?>
                </td>
                <td>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <?php if ($row['status'] === 'pending'): ?>
                        <form method="POST" style="margin:0;" onsubmit="return confirmAction('approve', '<?= htmlspecialchars($row['vehicle_name']) ?>')">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="action-btn btn-approve">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        </form>
                        <button type="button" class="action-btn btn-reject"
                                onclick="openRejectModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['vehicle_name'], ENT_QUOTES) ?>')">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <?php elseif ($row['status'] === 'rejected'): ?>
                        <form method="POST" style="margin:0;" onsubmit="return confirmAction('approve', '<?= htmlspecialchars($row['vehicle_name']) ?>')">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="action-btn btn-approve">
                                <i class="fas fa-redo"></i> Re-approve
                            </button>
                        </form>
                        <?php elseif ($row['status'] === 'approved'): ?>
                        <button type="button" class="action-btn btn-reject"
                                onclick="openRejectModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['vehicle_name'], ENT_QUOTES) ?>')">
                            <i class="fas fa-ban"></i> Revoke
                        </button>
                        <?php endif; ?>
                        <form method="POST" style="margin:0;"
                              onsubmit="return confirm('Delete \'<?= htmlspecialchars($row['vehicle_name'], ENT_QUOTES) ?>\'? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="action-btn btn-delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
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

<!-- Reject / Revoke modal -->
<div id="rejectModal"
     style="display:none;position:fixed;inset:0;z-index:10000;
            background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:28px;width:100%;max-width:400px;
                box-shadow:0 20px 60px rgba(0,0,0,.25);margin:16px;">
        <div style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:6px;">
            <i class="fas fa-times-circle" style="color:#dc2626;margin-right:8px;"></i>
            Reject / Revoke Vehicle Type
        </div>
        <div id="rejectVehicleName" style="font-size:13px;color:#64748b;margin-bottom:16px;"></div>
        <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">
            Reason (optional)
        </label>
        <textarea id="rejectNote" rows="3"
                  style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;
                         font-size:13px;resize:vertical;box-sizing:border-box;"
                  placeholder="e.g. Duplicate entry, incorrect spelling…"></textarea>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeRejectModal()"
                    style="padding:8px 16px;border:1px solid #e2e8f0;background:#f8fafc;
                           border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>
            <form id="rejectForm" method="POST" style="margin:0;">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                <input type="hidden" name="note" id="rejectNoteHidden">
                <button type="submit"
                        style="padding:8px 18px;background:#dc2626;color:#fff;border:none;
                               border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-times"></i> Confirm Reject
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmAction(action, name) {
    return confirm(action.charAt(0).toUpperCase() + action.slice(1) + ' "' + name + '"?');
}

function openRejectModal(id, name) {
    document.getElementById('rejectId').value          = id;
    document.getElementById('rejectVehicleName').textContent = '"' + name + '"';
    document.getElementById('rejectNote').value        = '';
    document.getElementById('rejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('rejectNote').focus(), 80);
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

document.getElementById('rejectForm').addEventListener('submit', function() {
    document.getElementById('rejectNoteHidden').value = document.getElementById('rejectNote').value;
});

document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
