<?php
$page_id = 'inv_history';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$stock_requests = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, u.name AS staff_name, m.name AS manager_name
        FROM stock_requests sr
        JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        WHERE sr.staff_id = ? AND sr.station_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$me['id'], $station_id]);
    $stock_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading history: ' . $e->getMessage();
}

$pending_count  = count(array_filter($stock_requests, fn($r) => $r['status'] === 'Pending'));
$validated_count= count(array_filter($stock_requests, fn($r) => $r['status'] === 'Validated'));
$approved_count = $validated_count; // backward compat
$completed_count= 0;
$rejected_count = 0;

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef; margin-bottom: 20px;
}
.inv-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px;
}
.inv-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.inv-card-body  { padding: 20px; }

/* Status badges */
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending   { background:#fff3cd; color:#856404; }
.sbadge-validated { background:#d4edda; color:#155724; }
.sbadge-approved  { background:#d4edda; color:#155724; }
.sbadge-completed { background:#d4edda; color:#155724; }

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:12px; margin-bottom:20px; }
.summary-card { border-radius:10px; padding:14px 16px; text-align:center; border-left:4px solid #dee2e6; background:#f8f9fa; }
.summary-card .s-val { font-size:1.8rem; font-weight:700; }
.summary-card .s-lbl { font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#666; margin-top:2px; }
.s-pending   { border-left-color:#ffc107; } .s-pending   .s-val { color:#856404; }
.s-approved  { border-left-color:#17a2b8; } .s-approved  .s-val { color:#0c5460; }
.s-completed { border-left-color:#28a745; } .s-completed .s-val { color:#155724; }
.s-rejected  { border-left-color:#dc3545; } .s-rejected  .s-val { color:#721c24; }

/* Legend */
.hist-legend {
    display:flex; gap:14px; flex-wrap:wrap;
    background:#f8f9fa; border:1px solid #dee2e6;
    border-radius:8px; padding:10px 16px; margin-bottom:16px; font-size:12px;
}
.hist-legend span { display:flex; align-items:center; gap:5px; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-history"></i> Inventory History</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Your stock request lifecycle</div>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/staff_inventory_summary.php'; ?>

<!-- Flat Summary Table of Requests -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;margin-bottom:20px;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:center;">
        <thead>
            <tr style="background:#f8f9fa;border-bottom:1px solid #dee2e6;">
                <th style="padding:10px;font-weight:700;color:#555;border-right:1px solid #dee2e6;">Pending Requests</th>
                <th style="padding:10px;font-weight:700;color:#555;">Validated Requests</th>
            </tr>
        </thead>
        <tbody>
            <tr style="font-size:16px;font-weight:800;">
                <td style="padding:12px;color:#856404;border-right:1px solid #dee2e6;"><?php echo $pending_count; ?></td>
                <td style="padding:12px;color:#155724;"><?php echo $validated_count; ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title">
            <i class="fas fa-list-alt"></i> All Stock Requests
            <?php if ($pending_count > 0): ?>
                <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo $pending_count; ?> Pending</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'requestsHistoryTable';
            $export_filename       = 'stock_requests_history_' . date('Ymd');
            $export_title          = 'Stock Requests History';
            $export_rows_select_id = 'historyRowsLimit';
            $export_default_rows   = 25;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
        </div>
    </div>
    <div class="inv-card-body">

        <div class="hist-legend">
            <span><span class="sbadge sbadge-pending">Pending</span> Waiting for Manager validation</span>
            <span><span class="sbadge sbadge-validated">Validated</span> Manager validated, PO auto-generated, pending Admin finalization</span>
        </div>

        <div class="table-wrap">
            <table class="table" id="requestsHistoryTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date Submitted</th>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Qty Requested</th>
                        <th>Qty Approved</th>
                        <th>Status</th>
                        <th>Manager Notes</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stock_requests)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:36px;color:#6c757d;">
                            <i class="fas fa-inbox" style="font-size:2.5em;display:block;margin-bottom:10px;opacity:.3;"></i>
                            No stock requests yet.<br>
                            Go to <a href="staff_inventory_merchandise.php" style="color:#002F70;font-weight:600;">Merchandise Inventory</a> to submit one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stock_requests as $req):
                        $st  = $req['status'] ?? 'Pending';
                        $cls = 'sbadge sbadge-' . strtolower($st);
                    ?>
                    <tr>
                        <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$req['id']; ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></td>
                        <td><code><?php echo htmlspecialchars($req['item_sku']); ?></code></td>
                        <td><?php echo htmlspecialchars($req['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($req['item_category']); ?></td>
                        <td style="text-align:center;font-weight:600;"><?php echo (int)$req['requested_quantity']; ?></td>
                        <td style="text-align:center;">
                            <?php if ($req['approved_quantity'] !== null): ?>
                                <strong style="color:#28a745;"><?php echo (int)$req['approved_quantity']; ?></strong>
                            <?php else: ?>
                                <span style="color:#adb5bd;">&#8212;</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                        <td style="font-size:13px;color:#495057;">
                            <?php echo $req['manager_notes']
                                ? htmlspecialchars($req['manager_notes'])
                                : '<span style="color:#adb5bd;">&#8212;</span>'; ?>
                        </td>
                        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y H:i', strtotime($req['updated_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="requestsHistoryPagination" style="margin-top:10px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    setupTablePagination('requestsHistoryTable', 'historyRowsLimit', 'requestsHistoryPagination', 25);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
