<?php
$page_id = 'shift_transactions_view';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager','admin','superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php'); exit;
}

// ── Excel export ──────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="shift_transactions_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo '<table border="1">';
    echo '<tr><th>Shift ID</th><th>Staff Name</th><th>Shift Date</th><th>Merchandise Sales</th><th>Total Sales</th><th>Transactions</th><th>Notes</th></tr>';
    
    $stmt = $pdo->prepare("
        SELECT 
            ls.id,
            u.name as staff_name,
            DATE(ls.start_time) as shift_date,
            COALESCE(merch_sales.merch_total, 0) as merch_sales,
            COALESCE(merch_sales.merch_total, 0) as total_sales,
            COALESCE(merch_sales.merch_count, 0) as transaction_count,
            CASE WHEN COALESCE(merch_sales.merch_count, 0) > 0 
                 THEN CONCAT('Merch: ', merch_sales.merch_count, ' txn(s)') 
                 ELSE '' END as notes
        FROM labor_sessions ls
        LEFT JOIN users u ON ls.user_id = u.id
        LEFT JOIN (
            SELECT 
                shift_id,
                SUM(total_amount) as merch_total,
                COUNT(*) as merch_count
            FROM merchandise_transactions 
            GROUP BY shift_id
        ) merch_sales ON ls.id = merch_sales.shift_id
        WHERE ls.station_id = ? 
            AND DATE(ls.start_time) BETWEEN ? AND ?
        GROUP BY ls.id, u.name, DATE(ls.start_time), merch_sales.merch_total, merch_sales.merch_count
        ORDER BY ls.start_time DESC
    ");
    
    $start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end'] ?? date('Y-m-d');
    
    $stmt->execute([$station_id, $start_date, $end_date]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($shifts as $shift) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($shift['id']) . '</td>';
        echo '<td>' . htmlspecialchars($shift['staff_name']) . '</td>';
        echo '<td>' . htmlspecialchars($shift['shift_date']) . '</td>';
        echo '<td>₱' . number_format($shift['merch_sales'], 2) . '</td>';
        echo '<td>₱' . number_format($shift['total_sales'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($shift['transaction_count']) . '</td>';
        echo '<td>' . htmlspecialchars($shift['notes']) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit;
}

// ── POST: save manager note ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_manager_note') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $note     = trim($_POST['manager_note'] ?? '');
        try {
            // Store note in audit_trail as a manager remark
            $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?,?,'ManagerNote',?,?)")
                ->execute([$shift_id, $me['id'], $note, $station_id]);
            $_SESSION['success'] = 'Manager note saved.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error saving note: ' . $e->getMessage();
        }
        header('Location: transactions_shift.php?' . http_build_query(array_filter([
            'start'    => $_POST['_start']    ?? '',
            'end'      => $_POST['_end']      ?? '',
            'staff_id' => $_POST['_staff_id'] ?? '',
        ])));
        exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$start    = $_GET['start']    ?? date('Y-m-d', strtotime('-30 days'));
$end      = $_GET['end']      ?? date('Y-m-d');
$staff_id = (int)($_GET['staff_id'] ?? 0);

// ── Staff list for filter dropdown ───────────────────────────────────────────
$staff_list = [];
try {
    $sl = $pdo->prepare("SELECT DISTINCT u.id, u.name FROM labor_sessions ls JOIN users u ON ls.user_id=u.id WHERE ls.station_id=? ORDER BY u.name");
    $sl->execute([$station_id]);
    $staff_list = $sl->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $staff_list = []; }

// ── Load labor sessions (shifts) ─────────────────────────────────────────────
$shift_where  = "WHERE ls.station_id = ? AND DATE(ls.start_time) BETWEEN ? AND ?";
$shift_params = [$station_id, $start, $end];
if ($staff_id > 0) {
    $shift_where   .= " AND ls.user_id = ?";
    $shift_params[] = $staff_id;
}

$sessions = [];
try {
    $stmt = $pdo->prepare("
        SELECT ls.id, ls.user_id, u.name AS staff_name,
               ls.start_time, ls.end_time, ls.hours_worked,
               ls.shift_period, ls.shift_name, ls.station_id,
               CASE WHEN ls.end_time IS NULL THEN 'Active' ELSE 'Completed' END AS shift_status
        FROM labor_sessions ls
        LEFT JOIN users u ON ls.user_id = u.id
        $shift_where
        ORDER BY ls.start_time DESC
    ");
    $stmt->execute($shift_params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $sessions = []; }

// ── For each session: pull fuel + merch totals + variance + audit ─────────────
foreach ($sessions as &$s) {
    $sid        = (int)$s['id'];
    $uid        = (int)$s['user_id'];
    $st         = $s['start_time'];
    $et         = $s['end_time'] ?? date('Y-m-d H:i:s');
    $sp         = $s['shift_period'] ?? '';
    $shift_date = date('Y-m-d', strtotime($st));

    // Merchandise transactions — 3-tier match:
    // 1. exact shift_id link
    // 2. staff + same date (when shift_id is null/0)
    // 3. staff + within shift time window (broadest fallback)
    try {
        $m = $pdo->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(total_amount),0) AS total,
                   COALESCE(SUM(CASE WHEN LOWER(payment_method)='cash'   THEN total_amount ELSE 0 END),0) AS cash,
                   COALESCE(SUM(CASE WHEN LOWER(payment_method)='card'   THEN total_amount ELSE 0 END),0) AS card,
                   COALESCE(SUM(CASE WHEN LOWER(payment_method)='credit' THEN total_amount ELSE 0 END),0) AS credit
            FROM merchandise_transactions
            WHERE station_id=?
              AND (
                  shift_id = ?
                  OR (
                      staff_id = ?
                      AND (shift_id IS NULL OR shift_id = 0 OR shift_id != ?)
                      AND DATE(COALESCE(
                          CASE WHEN transaction_date IS NOT NULL AND transaction_date > '2000-01-01' THEN transaction_date ELSE NULL END,
                          created_at
                      )) = ?
                  )
              )
        ");
        $m->execute([$station_id, $sid, $uid, $sid, $shift_date]);
        $s['merch'] = $m->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $s['merch'] = ['cnt'=>0,'total'=>0,'cash'=>0,'card'=>0,'credit'=>0]; }

    // Fuel transactions — removed (fuel is managed in Fuel Management module)
    $s['fuel'] = ['cnt'=>0,'total'=>0,'liters'=>0,'cash'=>0,'card'=>0,'credit'=>0];
    $s['fuel_detail'] = [];

    // Job Orders per shift — match by date
    try {
        $jo = $pdo->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(total_cost),0) AS total,
                   SUM(CASE WHEN LOWER(COALESCE(payment_status,'unpaid'))='paid'    THEN 1 ELSE 0 END) AS paid_cnt,
                   SUM(CASE WHEN LOWER(COALESCE(payment_status,'unpaid'))='partial' THEN 1 ELSE 0 END) AS partial_cnt,
                   SUM(CASE WHEN LOWER(COALESCE(payment_status,'unpaid'))='unpaid'  THEN 1 ELSE 0 END) AS unpaid_cnt
            FROM job_orders
            WHERE station_id=?
              AND DATE(created_at) = ?
        ");
        $jo->execute([$station_id, $shift_date]);
        $s['jo'] = $jo->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'total'=>0,'paid_cnt'=>0,'partial_cnt'=>0,'unpaid_cnt'=>0];
    } catch (Exception $e) { $s['jo'] = ['cnt'=>0,'total'=>0,'paid_cnt'=>0,'partial_cnt'=>0,'unpaid_cnt'=>0]; }
    try {
        $v = $pdo->prepare("
            SELECT COUNT(*) AS cnt,
                   SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open_cnt,
                   SUM(CASE WHEN status='escalated' THEN 1 ELSE 0 END) AS esc_cnt
            FROM variance_alerts
            WHERE station_id=? AND user_id=? AND created_at BETWEEN ? AND ?
        ");
        $v->execute([$station_id, $uid, $st, $et]);
        $s['variance'] = $v->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $s['variance'] = ['cnt'=>0,'open_cnt'=>0,'esc_cnt'=>0]; }

    // Audit trail for this shift window
    try {
        $a = $pdo->prepare("
            SELECT at.action_type, at.new_value AS remarks, at.timestamp,
                   u2.name AS manager_name
            FROM audit_trail at
            LEFT JOIN users u2 ON at.manager_id = u2.id
            WHERE at.station_id=? AND at.timestamp BETWEEN ? AND ?
            ORDER BY at.timestamp DESC LIMIT 20
        ");
        $a->execute([$station_id, $st, $et]);
        $s['audit'] = $a->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $s['audit'] = []; }

    // Detailed merch transactions for modal
    try {
        $md = $pdo->prepare("
            SELECT mt.id, mt.transaction_id, mt.customer_name, mt.payment_method,
                   mt.total_amount, mt.validation_status,
                   COALESCE(
                       CASE WHEN mt.transaction_date IS NOT NULL AND mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE NULL END,
                       mt.created_at
                   ) AS txn_date,
                   COALESCE(
                       (SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                        FROM merchandise_transaction_items i WHERE i.transaction_id=mt.id),
                       mt.item_sku, 'No items'
                   ) AS items
            FROM merchandise_transactions mt
            WHERE mt.station_id=?
              AND (
                  mt.shift_id = ?
                  OR (
                      mt.staff_id = ?
                      AND (mt.shift_id IS NULL OR mt.shift_id = 0 OR mt.shift_id != ?)
                      AND DATE(COALESCE(
                          CASE WHEN mt.transaction_date IS NOT NULL AND mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE NULL END,
                          mt.created_at
                      )) = ?
                  )
              )
            ORDER BY mt.created_at DESC
        ");
        $md->execute([$station_id, $sid, $uid, $sid, $shift_date]);
        $s['merch_detail'] = $md->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $s['merch_detail'] = []; }

    // Totals — merchandise + job orders
    $s['combined_total'] = (float)$s['merch']['total'] + (float)($s['jo']['total'] ?? 0);
    $s['total_txn_count'] = (int)$s['merch']['cnt'] + (int)($s['jo']['cnt'] ?? 0);

    // Duration display
    if ($s['end_time']) {
        $diff = (strtotime($s['end_time']) - strtotime($s['start_time']));
        $h = floor($diff / 3600);
        $m2 = floor(($diff % 3600) / 60);
        $s['duration_display'] = $h . 'h ' . $m2 . 'm';
    } else {
        $s['duration_display'] = '<span style="color:#28a745;font-weight:600;">Active</span>';
    }
}
unset($s);

// ── Summary counts ────────────────────────────────────────────────────────────
$total_shifts     = count($sessions);
$active_shifts    = count(array_filter($sessions, fn($s) => $s['shift_status'] === 'Active'));
$completed_shifts = $total_shifts - $active_shifts;
$grand_merch    = array_sum(array_column(array_column($sessions, 'merch'), 'total'));
$grand_jo       = array_sum(array_column(array_column($sessions, 'jo'),    'total'));
$grand_combined = $grand_merch + $grand_jo;

// ── Helpers ───────────────────────────────────────────────────────────────────
function ns_color(string $s): string {
    $s = strtolower(trim($s));
    if (in_array($s,['verified','approved','complete','completed'])) return '#28a745';
    if (in_array($s,['pending','pending validation','pendingvalidation',''])) return '#e6a817';
    if (in_array($s,['rejected','returned'])) return '#E3001F';
    return '#6c757d';
}
function ns_label(string $s): string {
    $s2 = strtolower(trim($s));
    if (in_array($s2,['verified','approved','complete','completed'])) return 'Verified';
    if (in_array($s2,['pending','pending validation','pendingvalidation',''])) return 'Pending';
    if (in_array($s2,['rejected','returned'])) return 'Returned';
    return ucfirst($s);
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Shift Transactions View</h1>
        <div class="sub">Shift-based transaction summaries, consolidated sales, and audit oversight</div>
    </div>
    <div class="actions">
        <a href="?start=<?php echo $start; ?>&end=<?php echo $end; ?>&staff_id=<?php echo $staff_id; ?>&export=excel"
           style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;border-radius:7px;text-decoration:none;font-size:13px;font-weight:600;transition:filter .15s;" onmouseover="this.style.filter='brightness(.88)'" onmouseout="this.style.filter='none'">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="transactions.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#002F70;color:#fff;border-radius:7px;text-decoration:none;font-size:13px;font-weight:600;transition:filter .15s;" onmouseover="this.style.filter='brightness(.88)'" onmouseout="this.style.filter='none'">
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

<!-- ── Summary Cards ─────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px;">
    <div class="scard"><div class="scard-num"><?php echo $total_shifts; ?></div><div class="scard-lbl">Total Shifts</div></div>
    <div class="scard scard-active"><div class="scard-num"><?php echo $active_shifts; ?></div><div class="scard-lbl">Active</div></div>
    <div class="scard scard-done"><div class="scard-num"><?php echo $completed_shifts; ?></div><div class="scard-lbl">Completed</div></div>
    <div class="scard scard-merch"><div class="scard-num">&#8369;<?php echo number_format($grand_merch, 2); ?></div><div class="scard-lbl">Merch Sales</div></div>
    <div class="scard" style="background:#fff8f0;border-color:#fed7aa;"><div class="scard-num" style="color:#c2410c;">&#8369;<?php echo number_format($grand_jo, 2); ?></div><div class="scard-lbl">JO Sales</div></div>
    <div class="scard scard-total"><div class="scard-num">&#8369;<?php echo number_format($grand_combined, 2); ?></div><div class="scard-lbl">Total Sales</div></div>
</div>

<!-- ── Filter Form ───────────────────────────────────────────────────────── -->
<div class="card" style="padding:14px 18px;margin-bottom:18px;">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label class="flt-lbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="flt-inp">
                <span style="color:#999;font-size:12px;">to</span>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="flt-inp">
            </div>
        </div>
        <div>
            <label class="flt-lbl"><i class="fas fa-user"></i> Staff</label>
            <select name="staff_id" class="flt-inp flt-select">
                <option value="">All Staff</option>
                <?php foreach($staff_list as $sl): ?>
                <option value="<?php echo $sl['id']; ?>" <?php echo ($staff_id == $sl['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sl['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="transactions_shift.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- ── Shifts Table ──────────────────────────────────────────────────────── -->
<div class="card" style="padding:0;margin-bottom:18px;">
    <div class="stv-table-wrap">
        <table class="stv-table">
            <thead>
                <tr>
                    <th>Shift ID</th>
                    <th>Staff</th>
                    <th>Shift Period</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Merch Sales</th>
                    <th>JO Sales</th>
                    <th>Total Sales</th>
                    <th>Txns</th>
                    <th>Variances</th>
                    <th class="stv-sticky">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sessions)): ?>
                <tr>
                    <td colspan="13" style="text-align:center;padding:48px;color:#888;">
                        <i class="fas fa-clock" style="font-size:36px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                        No shifts found for the selected date range.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach($sessions as $idx => $s): ?>
                <?php
                    $isActive = ($s['shift_status'] === 'Active');
                    $statusBg = $isActive ? '#28a745' : '#002F70';
                    $varCnt   = (int)($s['variance']['cnt'] ?? 0);
                    $varOpen  = (int)($s['variance']['open_cnt'] ?? 0);
                    $varEsc   = (int)($s['variance']['esc_cnt'] ?? 0);
                ?>
                <tr>
                    <td style="font-weight:700;">#<?php echo $s['id']; ?></td>
                    <td><?php echo htmlspecialchars($s['staff_name']); ?></td>
                    <td>
                        <span style="font-size:11px;background:#f0f4ff;color:#002F70;padding:2px 8px;border-radius:10px;font-weight:600;">
                            <?php echo htmlspecialchars($s['shift_name'] ?? ucfirst($s['shift_period'] ?? 'N/A')); ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;"><?php echo date('M d, H:i', strtotime($s['start_time'])); ?></td>
                    <td style="white-space:nowrap;">
                        <?php echo $s['end_time'] ? date('M d, H:i', strtotime($s['end_time'])) : '<span style="color:#28a745;font-weight:600;">Active</span>'; ?>
                    </td>
                    <td><?php echo $s['duration_display']; ?></td>
                    <td>
                        <span style="background:<?php echo $statusBg; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">
                            <?php echo $s['shift_status']; ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:700;color:#007bff;">&#8369;<?php echo number_format($s['merch']['total'], 2); ?></div>
                        <div style="font-size:10px;color:#888;"><?php echo $s['merch']['cnt']; ?> txn</div>
                    </td>
                    <td>
                        <div style="font-weight:700;color:#c2410c;">&#8369;<?php echo number_format($s['jo']['total'] ?? 0, 2); ?></div>
                        <div style="font-size:10px;color:#888;"><?php echo (int)($s['jo']['cnt'] ?? 0); ?> JO</div>
                    </td>
                    <td style="font-weight:800;color:#002F70;">&#8369;<?php echo number_format($s['combined_total'], 2); ?></td>
                    <td style="text-align:center;"><?php echo $s['total_txn_count']; ?></td>
                    <td style="text-align:center;">
                        <?php if ($varCnt > 0): ?>
                        <span style="background:<?php echo $varEsc > 0 ? '#E3001F' : ($varOpen > 0 ? '#e6a817' : '#28a745'); ?>;color:<?php echo $varOpen > 0 ? '#212529' : '#fff'; ?>;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">
                            <?php echo $varCnt; ?> alert<?php echo $varCnt > 1 ? 's' : ''; ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#aaa;font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="stv-sticky">
                        <div class="stv-action-btns">
                            <button class="svab svab-view" onclick="openShiftModal(<?php echo $idx; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="svab svab-note" onclick="openNoteModal(<?php echo $s['id']; ?>)">
                                <i class="fas fa-sticky-note"></i> Note
                            </button>
                            <a href="?start=<?php echo $start; ?>&end=<?php echo $end; ?>&staff_id=<?php echo $staff_id; ?>&export=excel"
                               class="svab svab-export">
                                <i class="fas fa-file-excel"></i> Export
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Shift Detail Modal ────────────────────────────────────────────────── -->
<div id="shiftModal" class="stv-modal" onclick="if(event.target===this)closeShiftModal()">
    <div class="stv-modal-content">
        <div class="stv-modal-header">
            <h3><i class="fas fa-clock"></i> Shift Details</h3>
            <button class="stv-close" onclick="closeShiftModal()">&times;</button>
        </div>
        <div class="stv-modal-body" id="shiftModalBody">Loading…</div>
        <div class="stv-modal-footer">
            <button class="svab svab-note-lg" onclick="closeShiftModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- ── Manager Note Modal ────────────────────────────────────────────────── -->
<div id="noteModal" class="stv-modal" onclick="if(event.target===this)closeNoteModal()">
    <div class="stv-modal-content" style="max-width:480px;">
        <div class="stv-modal-header">
            <h3><i class="fas fa-sticky-note"></i> Add Manager Note</h3>
            <button class="stv-close" onclick="closeNoteModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="stv-modal-body">
                <input type="hidden" name="action" value="save_manager_note">
                <input type="hidden" id="note_shift_id" name="shift_id">
                <input type="hidden" name="_start" value="<?php echo htmlspecialchars($start); ?>">
                <input type="hidden" name="_end" value="<?php echo htmlspecialchars($end); ?>">
                <input type="hidden" name="_staff_id" value="<?php echo $staff_id; ?>">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;color:#495057;margin-bottom:6px;font-size:13px;">Manager Note <span style="color:red;">*</span></label>
                    <textarea id="note_text" name="manager_note" rows="5"
                        style="width:100%;padding:9px 12px;border:1px solid #ced4da;border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;"
                        placeholder="Enter your remarks about this shift…" required></textarea>
                </div>
            </div>
            <div class="stv-modal-footer">
                <button type="submit" class="svab svab-view" style="padding:9px 18px;"><i class="fas fa-save"></i> Save Note</button>
                <button type="button" class="svab svab-note-lg" onclick="closeNoteModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- PLACEHOLDER: old modals removed -->

<script>
// Shift data passed from PHP for modal rendering
var SHIFTS = <?php echo json_encode(array_map(function($s) {
    return [
        'id'           => $s['id'],
        'staff_name'   => $s['staff_name'],
        'shift_name'   => $s['shift_name'] ?? ucfirst($s['shift_period'] ?? ''),
        'start_time'   => $s['start_time'],
        'end_time'     => $s['end_time'],
        'duration'     => strip_tags($s['duration_display']),
        'shift_status' => $s['shift_status'],
        'fuel'         => $s['fuel'],
        'merch'        => $s['merch'],
        'combined'     => $s['combined_total'],
        'variance'     => $s['variance'],
        'merch_detail' => $s['merch_detail'],
        'audit'        => $s['audit'],
    ];
}, $sessions)); ?>;

function openShiftModal(idx) {
    var s = SHIFTS[idx];
    if (!s) return;
    var statusBg = s.shift_status === 'Active' ? '#28a745' : '#002F70';
    var html = '';

    html += '<div class="sm-info-grid">';
    html += '<div class="sm-info-item"><span class="sm-lbl">Shift ID</span><span class="sm-val">#' + s.id + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">Staff</span><span class="sm-val">' + esc(s.staff_name) + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">Shift Period</span><span class="sm-val">' + esc(s.shift_name) + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">Start Time</span><span class="sm-val">' + fmtDt(s.start_time) + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">End Time</span><span class="sm-val">' + (s.end_time ? fmtDt(s.end_time) : '<span style="color:#28a745;font-weight:700;">Active</span>') + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">Duration</span><span class="sm-val">' + esc(s.duration) + '</span></div>';
    html += '<div class="sm-info-item"><span class="sm-lbl">Status</span><span class="sm-val"><span style="background:' + statusBg + ';color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">' + esc(s.shift_status) + '</span></span></div>';
    html += '</div>';

    html += '<div class="sm-totals" style="grid-template-columns:1fr 1fr;">';
    html += '<div class="sm-tot-box sm-tot-merch"><div class="sm-tot-num">&#8369;' + fmt(s.merch.total) + '</div><div class="sm-tot-lbl">Merch Sales</div><div class="sm-tot-sub">' + s.merch.cnt + ' txn</div></div>';
    html += '<div class="sm-tot-box sm-tot-combined"><div class="sm-tot-num">&#8369;' + fmt(s.combined) + '</div><div class="sm-tot-lbl">Total Sales</div></div>';
    html += '</div>';

    var cashTotal   = parseFloat(s.merch.cash||0).toFixed(2);
    var cardTotal   = parseFloat(s.merch.card||0).toFixed(2);
    var creditTotal = parseFloat(s.merch.credit||0).toFixed(2);
    html += '<div class="sm-section-title"><i class="fas fa-credit-card"></i> Payment Breakdown</div>';
    html += '<div class="sm-pay-row">';
    html += '<div class="sm-pay-box"><i class="fas fa-money-bill-wave" style="color:#28a745;"></i><div>&#8369;' + cashTotal + '</div><div class="sm-pay-lbl">Cash</div></div>';
    html += '<div class="sm-pay-box"><i class="fas fa-credit-card" style="color:#007bff;"></i><div>&#8369;' + cardTotal + '</div><div class="sm-pay-lbl">Card</div></div>';
    html += '<div class="sm-pay-box"><i class="fas fa-handshake" style="color:#e6a817;"></i><div>&#8369;' + creditTotal + '</div><div class="sm-pay-lbl">Credit</div></div>';
    html += '</div>';

    html += '<div class="sm-section-title"><i class="fas fa-shopping-cart"></i> Merchandise Transactions (' + (s.merch_detail ? s.merch_detail.length : 0) + ')</div>';
    html += '<div style="overflow-x:auto;margin-bottom:16px;"><table class="sm-table"><thead><tr><th>Txn ID</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date/Time</th></tr></thead><tbody>';
    if (s.merch_detail && s.merch_detail.length > 0) {
        s.merch_detail.forEach(function(mt) {
            var sc = statusColor(mt.validation_status);
            html += '<tr><td>#' + esc(mt.transaction_id||mt.id) + '</td><td>' + esc(mt.customer_name||'Walk-in') + '</td>';
            html += '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(mt.items) + '">' + esc(mt.items||'—') + '</td>';
            html += '<td style="text-align:right;font-weight:700;color:#002F70;">&#8369;' + parseFloat(mt.total_amount||0).toFixed(2) + '</td>';
            html += '<td>' + esc(mt.payment_method||'—') + '</td>';
            html += '<td><span style="background:' + sc.bg + ';color:' + sc.fg + ';padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;">' + esc(sc.label) + '</span></td>';
            html += '<td style="white-space:nowrap;">' + fmtDt(mt.txn_date) + '</td></tr>';
        });
    } else { html += '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:12px;">No merchandise transactions for this shift.</td></tr>'; }
    html += '</tbody></table></div>';

    var varCnt = parseInt(s.variance.cnt||0);
    html += '<div class="sm-section-title">Variance Alerts (' + varCnt + ')</div>';
    if (varCnt > 0) {
        html += '<div style="background:#fff8f0;border:1px solid #fde8c8;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px;">';
        html += '<span style="color:#b45309;font-weight:600;">' + s.variance.open_cnt + ' open</span> &bull; ';
        html += '<span style="color:#E3001F;font-weight:600;">' + s.variance.esc_cnt + ' escalated</span>';
        html += ' — <a href="transactions_variance.php" style="color:#002F70;font-weight:600;">View in Variance Alerts &rarr;</a></div>';
    } else { html += '<div style="color:#aaa;font-size:12px;margin-bottom:16px;padding:8px;">No variance alerts for this shift.</div>'; }

    html += '<div class="sm-section-title"><i class="fas fa-history"></i> Audit Trail</div>';
    html += '<div style="overflow-x:auto;"><table class="sm-table"><thead><tr><th>Action</th><th>Manager</th><th>Remarks</th><th>Timestamp</th></tr></thead><tbody>';
    if (s.audit && s.audit.length > 0) {
        s.audit.forEach(function(a) {
            var ac = a.action_type === 'Approve' ? '#28a745' : (a.action_type === 'Return' ? '#E3001F' : '#6c757d');
            html += '<tr><td><span style="background:' + ac + ';color:#fff;padding:1px 8px;border-radius:8px;font-size:10px;font-weight:700;">' + esc(a.action_type) + '</span></td>';
            html += '<td>' + esc(a.manager_name||'—') + '</td><td>' + esc(a.remarks||'—') + '</td>';
            html += '<td style="white-space:nowrap;">' + fmtDt(a.timestamp) + '</td></tr>';
        });
    } else { html += '<tr><td colspan="4" style="text-align:center;color:#aaa;padding:12px;">No audit actions recorded for this shift.</td></tr>'; }
    html += '</tbody></table></div>';

    document.getElementById('shiftModalBody').innerHTML = html;
    document.getElementById('shiftModal').style.display = 'flex';
}
function closeShiftModal() { document.getElementById('shiftModal').style.display = 'none'; }

function openNoteModal(shiftId) {
    document.getElementById('note_shift_id').value = shiftId;
    document.getElementById('note_text').value = '';
    document.getElementById('noteModal').style.display = 'flex';
    setTimeout(function() { document.getElementById('note_text').focus(); }, 120);
}
function closeNoteModal() { document.getElementById('noteModal').style.display = 'none'; }

function esc(str) { if (!str && str !== 0) return ''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmt(n) { return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function fmtDt(d) { if (!d) return '—'; var dt = new Date(d); if (isNaN(dt)) return d; return dt.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function statusColor(s) {
    var sl = (s||'').toLowerCase().trim();
    if (['verified','approved','complete','completed'].includes(sl)) return {bg:'#d1fae5',fg:'#065f46',label:'Verified'};
    if (['pending','pending validation','pendingvalidation',''].includes(sl)) return {bg:'#fef3c7',fg:'#92400e',label:'Pending'};
    if (['rejected','returned'].includes(sl)) return {bg:'#fee2e2',fg:'#991b1b',label:'Returned'};
    if (sl === 'active') return {bg:'#d1fae5',fg:'#065f46',label:'Active'};
    return {bg:'#f3f4f6',fg:'#6b7280',label:s||'—'};
}
</script>

<style>
.scard { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.scard-num { font-size:20px; font-weight:800; color:#002F70; display:block; }
.scard-lbl { font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; display:block; margin-top:3px; }
.scard-active .scard-num { color:#28a745; } .scard-fuel .scard-num { color:#dc3545; } .scard-merch .scard-num { color:#007bff; } .scard-total .scard-num { color:#002F70; font-size:16px; }
.flt-lbl { font-size:11px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:4px; }
.flt-inp { height:36px; padding:0 10px; border:1px solid #ced4da; border-radius:7px; font-size:13px; color:#333; background:#fff; outline:none; box-sizing:border-box; }
.flt-inp:focus { border-color:#002F6C; box-shadow:0 0 0 3px rgba(0,47,108,.1); }
.flt-select { cursor:pointer; }
.flt-btn { display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:36px; border:none; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; white-space:nowrap; transition:filter .15s; }
.flt-btn:hover { filter:brightness(.88); }
.flt-btn-search { background:#002F6C; color:#fff; } .flt-btn-reset { background:#6c757d; color:#fff; }
.stv-table-wrap { width:100%; overflow-x:auto; }
.stv-table { width:100%; min-width:1000px; border-collapse:collapse; font-size:12px; }
.stv-table thead th { background:#f8f9fa; color:#495057; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:9px 10px; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.stv-table tbody td { padding:9px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.stv-table tbody tr:hover td { background:#f8fbff; }
.stv-sticky { position:sticky; right:0; background:#f8f9fa; box-shadow:-3px 0 8px rgba(0,0,0,.07); z-index:2; }
.stv-table tbody tr:hover .stv-sticky { background:#f8fbff; }
/* Action buttons — stacked, matching product_management.php */
.stv-action-btns { display:flex; flex-direction:column; gap:4px; align-items:stretch; min-width:90px; }
.svab { display:flex; align-items:center; justify-content:center; gap:5px; padding:5px 8px; border:none; border-radius:5px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap; width:100%; text-decoration:none; transition:filter .15s; }
.svab:hover { filter:brightness(.88); }
.svab-view    { background:#28a745; color:#fff; }
.svab-note    { background:#6c757d; color:#fff; }
.svab-export  { background:#002F70; color:#fff; }
.svab-note-lg { background:#6c757d; color:#fff; padding:9px 18px; width:auto; }
/* Modal */
.stv-modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.55); align-items:center; justify-content:center; }
.stv-modal-content { background:#fff; border-radius:12px; width:96%; max-width:1100px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 8px 32px rgba(0,0,0,.22); overflow:hidden; }
.stv-modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; background:#fff; color:#212529; border-bottom:2px solid #e9ecef; flex-shrink:0; }
.stv-modal-header h3 { margin:0; font-size:16px; color:#002F6C; }
.stv-close { background:none; border:none; color:#6c757d; font-size:26px; cursor:pointer; line-height:1; padding:0; }
.stv-close:hover { color:#212529; }
.stv-modal-body { padding:22px; overflow-y:auto; flex:1; }
.stv-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; background:#f8f9fa; border-top:1px solid #dee2e6; flex-shrink:0; }
/* Modal internals */
.sm-info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; background:#f8f9fa; padding:14px; border-radius:8px; margin-bottom:16px; }
.sm-lbl { font-size:10px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:2px; }
.sm-val { font-size:13px; color:#212529; font-weight:600; }
.sm-totals { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
.sm-tot-box { border-radius:8px; padding:12px; text-align:center; border:1px solid #e2e8f0; }
.sm-tot-num { font-size:18px; font-weight:800; } .sm-tot-lbl { font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; margin-top:2px; } .sm-tot-sub { font-size:10px; color:#aaa; margin-top:2px; }
.sm-tot-fuel { background:#fff5f5; } .sm-tot-fuel .sm-tot-num { color:#dc3545; }
.sm-tot-merch { background:#f0f8ff; } .sm-tot-merch .sm-tot-num { color:#007bff; }
.sm-tot-combined { background:#f0f4ff; } .sm-tot-combined .sm-tot-num { color:#002F70; }
.sm-pay-row { display:flex; gap:10px; margin-bottom:16px; }
.sm-pay-box { flex:1; text-align:center; background:#f8f9fa; border:1px solid #e2e8f0; border-radius:8px; padding:10px; font-size:13px; font-weight:700; color:#333; }
.sm-pay-lbl { font-size:10px; color:#6c757d; font-weight:600; text-transform:uppercase; margin-top:3px; }
.sm-section-title { font-size:12px; font-weight:700; color:#002F6C; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #e9ecef; padding-bottom:6px; margin:16px 0 10px; display:flex; align-items:center; gap:7px; }
.sm-table { width:100%; border-collapse:collapse; font-size:11.5px; }
.sm-table thead th { background:#002F6C; color:#fff; padding:6px 8px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }
.sm-table tbody td { padding:6px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.sm-table tbody tr:hover td { background:#f8fbff; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>