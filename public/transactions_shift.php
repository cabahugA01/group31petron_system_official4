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

// ── Filters ───────────────────────────────────────────────────────────────────
$start      = $_GET['start']      ?? date('Y-m-d', strtotime('-30 days'));
$end        = $_GET['end']        ?? date('Y-m-d');
$shift_filter = $_GET['shift']    ?? 'all'; // all | shift1 | shift2

// ── Load individual transactions (merch + job orders) with shift info ─────────
$transactions = [];

// Merchandise transactions
try {
    $q = $pdo->prepare("
        SELECT
            mt.transaction_id AS txn_id,
            COALESCE(mt.customer_name, 'Walk-in') AS customer_name,
            'Merchandise' AS txn_type,
            mt.total_amount AS amount,
            COALESCE(mt.payment_method, 'Cash') AS payment_method,
            COALESCE(u.name, 'Unknown') AS staff_encoder,
            COALESCE(
                CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE NULL END,
                mt.created_at
            ) AS txn_datetime,
            COALESCE(ls.shift_name, ls.shift_period, 'Unknown') AS shift_label,
            COALESCE(ls.shift_period, '') AS shift_period,
            mt.id AS raw_id,
            mt.shift_id
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id = u.id
        LEFT JOIN labor_sessions ls ON mt.shift_id = ls.id
        WHERE mt.station_id = ?
          AND DATE(COALESCE(
              CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE NULL END,
              mt.created_at
          )) BETWEEN ? AND ?
        ORDER BY txn_datetime DESC
    ");
    $q->execute([$station_id, $start, $end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['shift_num'] = str_contains(strtolower($r['shift_label']), 'first') || str_contains(strtolower($r['shift_period']), 'first') ? '1' : (str_contains(strtolower($r['shift_label']), 'second') || str_contains(strtolower($r['shift_period']), 'second') ? '2' : '?');
        $transactions[] = $r;
    }
} catch (Exception $e) {}

// Job Orders
try {
    $q = $pdo->prepare("
        SELECT
            COALESCE(jo.order_number, CONCAT('JO-', jo.id)) AS txn_id,
            COALESCE(jo.customer_name, 'Walk-in') AS customer_name,
            'Job Order' AS txn_type,
            COALESCE(jo.total_cost, 0) AS amount,
            COALESCE(jo.payment_status, 'Pending') AS payment_method,
            COALESCE(u.name, 'Unknown') AS staff_encoder,
            jo.created_at AS txn_datetime,
            COALESCE(ls.shift_name, ls.shift_period, 'Unknown') AS shift_label,
            COALESCE(ls.shift_period, '') AS shift_period,
            jo.id AS raw_id,
            jo.shift_id
        FROM job_orders jo
        LEFT JOIN users u ON COALESCE(jo.created_by, jo.user_id) = u.id
        LEFT JOIN labor_sessions ls ON jo.shift_id = ls.id
        WHERE jo.station_id = ?
          AND DATE(jo.created_at) BETWEEN ? AND ?
        ORDER BY jo.created_at DESC
    ");
    $q->execute([$station_id, $start, $end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['shift_num'] = str_contains(strtolower($r['shift_label']), 'first') || str_contains(strtolower($r['shift_period']), 'first') ? '1' : (str_contains(strtolower($r['shift_label']), 'second') || str_contains(strtolower($r['shift_period']), 'second') ? '2' : '?');
        $transactions[] = $r;
    }
} catch (Exception $e) {}

// Sort combined by datetime desc
usort($transactions, fn($a,$b) => strcmp($b['txn_datetime'], $a['txn_datetime']));

// Apply shift filter
$filtered = $transactions;
if ($shift_filter === 'shift1') $filtered = array_filter($transactions, fn($t) => $t['shift_num'] === '1');
elseif ($shift_filter === 'shift2') $filtered = array_filter($transactions, fn($t) => $t['shift_num'] === '2');

// ── KPIs ─────────────────────────────────────────────────────────────────────
$s1 = array_filter($transactions, fn($t) => $t['shift_num'] === '1');
$s2 = array_filter($transactions, fn($t) => $t['shift_num'] === '2');
$kpi_s1_sales = array_sum(array_column($s1, 'amount'));
$kpi_s2_sales = array_sum(array_column($s2, 'amount'));
$kpi_s1_txns  = count($s1);
$kpi_s2_txns  = count($s2);

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-exchange-alt"></i> Shift Transactions</h1>
        <div class="sub">Monitor transactions per shift.</div>
    </div>
    <div class="actions txn-head-actions">
        <a href="transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
        <button onclick="exportShiftTransactions('excel')" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
        <button onclick="exportShiftTransactions('csv')" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</button>
        <button onclick="exportShiftTransactions('pdf')" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
    </div>
</div>

<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-dollar-sign"></i> Shift 1 Sales</div>
        <div class="txn-kpi-val">₱<?= number_format($kpi_s1_sales, 2) ?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-dollar-sign"></i> Shift 2 Sales</div>
        <div class="txn-kpi-val">₱<?= number_format($kpi_s2_sales, 2) ?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Shift 1 Transactions</div>
        <div class="txn-kpi-val"><?= number_format($kpi_s1_txns) ?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Shift 2 Transactions</div>
        <div class="txn-kpi-val"><?= number_format($kpi_s2_txns) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="padding:14px 18px;margin-bottom:18px;border-radius:10px;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.07);">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label class="sht-flbl"><i class="fas fa-calendar-alt"></i> Date Range</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="sht-finp">
                <span style="color:#999;font-size:12px;">to</span>
                <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="sht-finp">
            </div>
        </div>
        <div>
            <label class="sht-flbl"><i class="fas fa-clock"></i> Shift</label>
            <select name="shift" class="sht-finp" style="cursor:pointer;">
                <option value="all"   <?= $shift_filter==='all'    ? 'selected':'' ?>>All Shifts</option>
                <option value="shift1"<?= $shift_filter==='shift1' ? 'selected':'' ?>>Shift 1 (Morning)</option>
                <option value="shift2"<?= $shift_filter==='shift2' ? 'selected':'' ?>>Shift 2 (Evening)</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Search</button>
            <a href="transactions_shift.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div style="background:#fff;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.07);overflow:hidden;margin-bottom:30px;">
    <div style="overflow-x:auto;">
        <table class="sht-table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer Name</th>
                    <th>Transaction Type</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Staff Encoder</th>
                    <th>Date & Time</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filtered)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:60px 20px;color:#94a3b8;">
                        <i class="fas fa-exchange-alt" style="font-size:32px;display:block;margin-bottom:10px;opacity:0.3;"></i>
                        No transactions found for the selected filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($filtered as $idx => $t):
                    $isJO    = $t['txn_type'] === 'Job Order';
                    $typeBg  = $isJO ? '#fff0e8' : '#e0f0ff';
                    $typeFg  = $isJO ? '#c2410c' : '#0056b3';
                    $shiftLbl = $t['shift_num'] === '1' ? 'Shift 1' : ($t['shift_num'] === '2' ? 'Shift 2' : '—');
                    $shiftClr = $t['shift_num'] === '1' ? '#002F70' : '#7c3aed';
                    try {
                        $dtObj = new DateTime($t['txn_datetime']);
                        $dtFmt = $dtObj->format('M j, Y g:i A');
                    } catch (Exception $e) { $dtFmt = $t['txn_datetime']; }
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;color:#002F70;"><?= htmlspecialchars($t['txn_id']) ?></div>
                        <div style="font-size:10px;margin-top:2px;">
                            <span style="color:<?= $shiftClr ?>;font-weight:700;font-size:10px;"><?= $shiftLbl ?></span>
                        </div>
                    </td>
                    <td style="font-weight:500;"><?= htmlspecialchars($t['customer_name']) ?></td>
                    <td>
                        <span style="background:<?= $typeBg ?>;color:<?= $typeFg ?>;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;">
                            <?= htmlspecialchars($t['txn_type']) ?>
                        </span>
                    </td>
                    <td style="font-weight:700;color:#002F70;">₱<?= number_format((float)$t['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($t['payment_method']) ?></td>
                    <td style="color:#475569;"><?= htmlspecialchars($t['staff_encoder']) ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:#64748b;"><?= htmlspecialchars($dtFmt) ?></td>
                    <td style="text-align:center;">
                        <button type="button"
                                onclick="viewTxnDetail(<?= $idx ?>)"
                                class="txn-btn txn-btn-info" style="font-size:11px;padding:5px 12px;width:auto;display:inline-flex;">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div id="shtModal" style="display:none;position:fixed;inset:0;z-index:1050;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:8px 8px 8px 205px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;border-bottom:1px solid #e9ecef;">
            <h3 style="margin:0;font-size:15px;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-receipt"></i> Transaction Details
            </h3>
            <button onclick="closeShtModal()" style="background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;line-height:1;">×</button>
        </div>
        <div id="shtModalBody" style="padding:20px 24px;overflow-y:auto;flex:1;"></div>
        <div style="display:flex;justify-content:flex-end;gap:8px;padding:14px 24px;border-top:1px solid #e9ecef;">
            <button onclick="closeShtModal()" class="txn-btn txn-btn-secondary" style="width:auto;display:inline-flex;">Close</button>
        </div>
    </div>
</div>

<script>
var SHT_DATA = <?php
$jsData = array_values(array_map(function($t) {
    try { $dt = new DateTime($t['txn_datetime']); $dtf = $dt->format('M j, Y g:i A'); } catch(Exception $e) { $dtf = $t['txn_datetime'] ?? ''; }
    return [
        'txn_id'        => $t['txn_id'],
        'customer_name' => $t['customer_name'],
        'txn_type'      => $t['txn_type'],
        'amount'        => (float)$t['amount'],
        'payment_method'=> $t['payment_method'],
        'staff_encoder' => $t['staff_encoder'],
        'txn_datetime'  => $dtf,
        'shift_label'   => $t['shift_label'],
        'shift_num'     => $t['shift_num'],
    ];
}, $filtered));
echo json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>;

function viewTxnDetail(idx) {
    var t = SHT_DATA[idx];
    if (!t) return;
    var isJO = t.txn_type === 'Job Order';
    var typeBg = isJO ? '#fff0e8' : '#e0f0ff';
    var typeFg = isJO ? '#c2410c' : '#0056b3';
    var shiftClr = t.shift_num === '1' ? '#002F70' : '#7c3aed';
    function row(label, val) {
        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #f1f5f9;">'
             + '<span style="font-size:12px;color:#64748b;font-weight:600;">' + esc(label) + '</span>'
             + '<span style="font-size:13px;color:#1e293b;font-weight:700;text-align:right;max-width:60%;">' + val + '</span>'
             + '</div>';
    }
    var html = '<div style="background:#f8fafc;border-radius:8px;padding:14px 16px;margin-bottom:14px;">';
    html += row('Transaction ID', '<span style="color:#002F70;font-weight:800;">' + esc(t.txn_id) + '</span>');
    html += row('Customer Name', esc(t.customer_name));
    html += row('Transaction Type', '<span style="background:'+typeBg+';color:'+typeFg+';padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">' + esc(t.txn_type) + '</span>');
    html += row('Amount', '<span style="color:#002F70;font-size:15px;">₱' + parseFloat(t.amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',') + '</span>');
    html += row('Payment Method', esc(t.payment_method));
    html += row('Staff Encoder', esc(t.staff_encoder));
    html += row('Date & Time', esc(t.txn_datetime));
    html += row('Shift', '<span style="color:'+shiftClr+';font-weight:700;">' + esc(t.shift_label || (t.shift_num !== '?' ? 'Shift ' + t.shift_num : '—')) + '</span>');
    html += '</div>';
    document.getElementById('shtModalBody').innerHTML = html;
    document.getElementById('shtModal').style.display = 'flex';
}
function closeShtModal() { document.getElementById('shtModal').style.display = 'none'; }
function esc(s) { if (!s && s !== 0) return '—'; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
document.getElementById('shtModal').addEventListener('click', function(e) { if (e.target === this) closeShtModal(); });

function exportShiftTransactions(format) {
    const table = document.querySelector('.sht-table');
    if (!table) { alert('No transaction data found.'); return; }

    const dateStart = "<?= htmlspecialchars($start) ?>";
    const dateEnd = "<?= htmlspecialchars($end) ?>";
    const shift = "<?= htmlspecialchars($shift_filter) ?>";
    
    let shiftLbl = "All Shifts";
    if (shift === 'shift1') shiftLbl = "Shift 1";
    else if (shift === 'shift2') shiftLbl = "Shift 2";
    
    const filename = `Shift_Transactions_${shiftLbl}_${dateStart}_to_${dateEnd}`;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Export library not loaded. Please wait a moment and try again.');
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
            if (cells.length > 1) { // Skip empty state row
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
        XLSX.utils.book_append_sheet(wb, ws, 'Shift Transactions');
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
        doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Shift Transactions Report</title>
        <style>
            @page{size: A4 landscape;margin:.3in .4in;}
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
            tfoot tr{border-top:2px solid #002F70;background:#f2f2f2 !important;}
            tfoot td{padding:6px 5px;font-weight:700;}
        </style></head><body>
            <div class="header-container">
                <img src="${logo_url}" alt="Petron">
                <div class="header-title">
                    <h1>Petron Station Management System</h1>
                    <p>Shift Transactions Report (${shiftLbl})</p>
                </div>
                <div class="meta-info">
                    <strong>Date Range:</strong> ${dateStart} to ${dateEnd}<br>
                    <strong>Generated:</strong> ${generated}
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
/* == Petron Clean KPI Summary Cards == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .05);
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 24px;
    font-weight: 800;
    color: #002F70;
}

.sht-flbl { font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px; }
.sht-finp { height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box; }
.sht-finp:focus { border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1); }

.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
}
.txn-btn-info { color:#00264D !important; border-color:#00264D !important; }
.txn-btn-info:hover { background:#00264D !important; color:#fff !important; }
.txn-btn-secondary { color:#6b7280 !important; border-color:#6b7280 !important; }
.txn-btn-secondary:hover { background:#6b7280 !important; color:#fff !important; }

.sht-table { width:100%;border-collapse:collapse;font-size:13px; }
.sht-table thead th { background:#002F70;color:#fff;padding:12px 14px;text-align:left;font-weight:600;font-size:12px;white-space:nowrap; }
.sht-table tbody tr { border-bottom:1px solid #f1f5f9;transition:background .15s; }
.sht-table tbody tr:hover td { background:#f8faff; }
.sht-table tbody td { padding:11px 14px;vertical-align:middle;color:#334155; }
</style>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
