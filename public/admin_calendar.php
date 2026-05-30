<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$user_role = strtolower(trim($me['role'] ?? ''));
if (!in_array($user_role, ['admin', 'superadmin'])) {
    header('Location: ../index.php');
    exit();
}
$station_id = user_station_id();
if ((int)$station_id <= 0 && in_array($user_role, ['admin'])) {
    render_no_station_page('admin_dashboard.php');
}
$user_id = $me['id'];

// ── Define helper function first ──────────────────────────────────────────────
function fetch_calendar_events($pdo, $station_id, $start_date, $end_date, $filter_type = '', $filter_status = '') {
    $events = [];

    // 1. Job Orders
    if ($filter_type === '' || $filter_type === 'job_order') {
        try {
            $stmt = $pdo->prepare("
                SELECT jo.id, jo.job_order_number, jo.customer_name, jo.service_type,
                       jo.validation_status, jo.status AS raw_status, jo.created_at, jo.vehicle_plate, jo.total_cost,
                       u.name AS staff_name,
                       mu.name AS manager_name
                FROM job_orders jo
                LEFT JOIN users u ON COALESCE(jo.created_by, jo.user_id) = u.id
                LEFT JOIN users mu ON jo.validated_by = mu.id
                WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
                ORDER BY jo.created_at DESC");
            $stmt->execute([$station_id, $start_date, $end_date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $status = strtolower($r['validation_status'] ?? '');
                if ($status === '') $status = strtolower($r['raw_status'] ?? 'pending');
                
                $events[] = [
                    'id'               => 'jo_'.$r['id'],
                    'raw_id'           => $r['id'],
                    'ref_no'           => $r['job_order_number'] ?? ('JO-' . $r['id']),
                    'type_key'         => 'job_order',
                    'type_name'        => 'Job Order',
                    'icon_class'       => 'fas fa-wrench',
                    'staff_name'       => $r['staff_name'] ?? '—',
                    'manager_name'     => $r['manager_name'] ?? '—',
                    'event_date'       => date('Y-m-d', strtotime($r['created_at'])),
                    'start_time'       => '00:00',
                    'end_time'         => '00:00',
                    'work_description' => ($r['service_type'] ?? 'Job Order').' — '.($r['customer_name'] ?? 'Walk-in'),
                    'status'           => $status,
                    'customer_name'    => $r['customer_name'] ?? '',
                    'vehicle_plate'    => $r['vehicle_plate'] ?? '',
                    'amount'           => $r['total_cost'] ?? 0.00,
                    'auto_synced'      => true,
                ];
            }
        } catch (Exception $e) {}
    }

    // 2. Deliveries
    if ($filter_type === '' || $filter_type === 'delivery') {
        try {
            $stmt = $pdo->prepare("
                SELECT d.id, d.encoded_by, d.status, d.supplier, d.product, d.quantity, d.unit,
                       d.delivery_type, d.delivery_date, d.delivery_ref,
                       u.name AS staff_name,
                       mu.name AS manager_name
                FROM deliveries_oversight d
                LEFT JOIN users u  ON u.id  = d.encoded_by
                LEFT JOIN users mu ON mu.id = d.admin_id
                WHERE d.station_id = ? AND d.delivery_date BETWEEN ? AND ?
                ORDER BY d.created_at DESC");
            $stmt->execute([$station_id, $start_date, $end_date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'id'               => 'del_'.$r['id'],
                    'raw_id'           => $r['id'],
                    'ref_no'           => $r['delivery_ref'] ?? ('DEL-' . $r['id']),
                    'type_key'         => 'delivery',
                    'type_name'        => 'Delivery',
                    'icon_class'       => 'fas fa-truck',
                    'staff_name'       => $r['staff_name'] ?? '—',
                    'manager_name'     => $r['manager_name'] ?? '—',
                    'event_date'       => $r['delivery_date'],
                    'start_time'       => '00:00',
                    'end_time'         => '00:00',
                    'work_description' => 'Delivery #' . $r['id'] . ' — ' . ($r['supplier'] ?? '') . ' (' . ($r['product'] ?? '') . ': ' . $r['quantity'] . ' ' . $r['unit'] . ')',
                    'status'           => strtolower($r['status'] ?? 'pending'),
                    'customer_name'    => '',
                    'vehicle_plate'    => '',
                    'amount'           => 0.00,
                    'auto_synced'      => true,
                ];
            }
        } catch (Exception $e) {}
    }

    // 3. Purchase Orders
    if ($filter_type === '' || $filter_type === 'purchase_order') {
        try {
            $stmt = $pdo->prepare("
                SELECT po.id, po.po_number, po.created_by, po.status, po.product_name,
                       po.quantity, po.total_amount, po.created_at,
                       u.name AS staff_name
                FROM purchase_orders po
                LEFT JOIN users u ON u.id = po.created_by
                WHERE po.station_id = ? AND DATE(po.created_at) BETWEEN ? AND ?
                ORDER BY po.created_at DESC");
            $stmt->execute([$station_id, $start_date, $end_date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'id'               => 'po_'.$r['id'],
                    'raw_id'           => $r['id'],
                    'ref_no'           => $r['po_number'] ?? ('PO-' . $r['id']),
                    'type_key'         => 'purchase_order',
                    'type_name'        => 'Purchase Order',
                    'icon_class'       => 'fas fa-file-invoice-dollar',
                    'staff_name'       => $r['staff_name'] ?? '—',
                    'manager_name'     => '—',
                    'event_date'       => date('Y-m-d', strtotime($r['created_at'])),
                    'start_time'       => '00:00',
                    'end_time'         => '00:00',
                    'work_description' => 'PO #' . $r['id'] . ' — ' . ($r['product_name'] ?? '') . ' (Qty: ' . $r['quantity'] . ')',
                    'status'           => strtolower($r['status'] ?? 'pending'),
                    'customer_name'    => '',
                    'vehicle_plate'    => '',
                    'amount'           => $r['total_amount'] ?? 0.00,
                    'auto_synced'      => true,
                ];
            }
        } catch (Exception $e) {}
    }

    // 4. Fuel Calibration (calibration_logs)
    if ($filter_type === '' || $filter_type === 'fuel_calibration') {
        try {
            $stmt = $pdo->prepare("
                SELECT fc.id, fc.encoded_by, fc.encoded_at,
                       fc.pump_number, fc.fuel_type, fc.calibration_value, fc.shift_period,
                       u.name AS staff_name
                FROM calibration_logs fc
                LEFT JOIN users u ON u.id = fc.encoded_by
                WHERE fc.station_id = ? AND DATE(fc.encoded_at) BETWEEN ? AND ?
                ORDER BY fc.encoded_at DESC");
            $stmt->execute([$station_id, $start_date, $end_date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'id'               => 'fc_'.$r['id'],
                    'raw_id'           => $r['id'],
                    'ref_no'           => 'CAL-' . $r['id'],
                    'type_key'         => 'fuel_calibration',
                    'type_name'        => 'Fuel Calibration',
                    'icon_class'       => 'fas fa-tachometer-alt',
                    'staff_name'       => $r['staff_name'] ?? '—',
                    'manager_name'     => '—',
                    'event_date'       => date('Y-m-d', strtotime($r['encoded_at'])),
                    'start_time'       => '00:00',
                    'end_time'         => '00:00',
                    'work_description' => 'Pump #' . $r['pump_number'] . ' — ' . $r['fuel_type'] . ' (' . $r['calibration_value'] . 'L, Shift: ' . $r['shift_period'] . ')',
                    'status'           => 'completed',
                    'customer_name'    => '',
                    'vehicle_plate'    => '',
                    'amount'           => 0.00,
                    'auto_synced'      => true,
                ];
            }
        } catch (Exception $e) {}
    }

    // 5. Staff Shifts (staff_schedules)
    if ($filter_type === '' || $filter_type === 'staff_shift') {
        try {
            $stmt = $pdo->prepare("
                SELECT ss.id, ss.user_id, ss.shift, ss.scheduled_date, ss.status,
                       u.name AS staff_name, s.start_time, s.end_time
                FROM staff_schedules ss
                JOIN users u ON ss.user_id = u.id
                LEFT JOIN shifts s ON ss.shift = s.name
                WHERE u.station_id = ? AND ss.scheduled_date BETWEEN ? AND ?
                ORDER BY ss.scheduled_date, s.start_time");
            $stmt->execute([$station_id, $start_date, $end_date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $events[] = [
                    'id'               => 'shift_'.$r['id'],
                    'raw_id'           => $r['id'],
                    'ref_no'           => 'SHIFT-' . $r['id'],
                    'type_key'         => 'staff_shift',
                    'type_name'        => 'Staff Shift',
                    'icon_class'       => 'fas fa-user-clock',
                    'staff_name'       => $r['staff_name'] ?? '—',
                    'manager_name'     => '—',
                    'event_date'       => $r['scheduled_date'],
                    'start_time'       => $r['start_time'] ?? '00:00',
                    'end_time'         => $r['end_time'] ?? '00:00',
                    'work_description' => 'Duty Shift: ' . $r['shift'] . ' (' . ($r['start_time'] ? date('g:i A', strtotime($r['start_time'])) : '—') . ' - ' . ($r['end_time'] ? date('g:i A', strtotime($r['end_time'])) : '—') . ')',
                    'status'           => strtolower($r['status'] ?? 'completed'),
                    'customer_name'    => '',
                    'vehicle_plate'    => '',
                    'amount'           => 0.00,
                    'auto_synced'      => true,
                ];
            }
        } catch (Exception $e) {}
    }

    // Apply status filter if set
    if ($filter_status !== '') {
        $events = array_filter($events, function($ev) use ($filter_status) {
            $st = strtolower($ev['status'] ?? '');
            if ($filter_status === 'pending')   return in_array($st, ['pending','pending validation','pending manager approval']);
            if ($filter_status === 'approved')  return in_array($st, ['approved','confirmed','validated']);
            if ($filter_status === 'completed') return in_array($st, ['completed','done']);
            if ($filter_status === 'rejected')  return in_array($st, ['rejected','discrepancy','cancelled']);
            return true;
        });
    }

    return $events;
}

// Station info
try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stmt->execute([$station_id]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    $station_name = $station['name'] ?? 'Unknown Station';
} catch (Exception $e) { $station_name = 'Station'; }

// Week navigation
$today       = new DateTime();
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$week_start  = clone $today;
$week_start->modify('Monday this week');
$week_start->modify($week_offset . ' weeks');
$week_end = clone $week_start;
$week_end->modify('+6 days');

$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $week_start;
    $d->modify("+$i days");
    $week_dates[] = $d;
}

$prev_week  = $week_offset - 1;
$next_week  = $week_offset + 1;
$week_label = $week_start->format('F j') . ' – ' . $week_end->format('j, Y');
$today_str  = $today->format('Y-m-d');
$ws_str     = $week_start->format('Y-m-d');
$we_str     = $week_end->format('Y-m-d');

// ── Handle Export Report request early ────────────────────────────────────────
if (isset($_GET['export_report'])) {
    $format = $_GET['export_format'] ?? 'csv';
    $range  = $_GET['export_range'] ?? 'week';
    $from   = $_GET['export_from'] ?? '';
    $to     = $_GET['export_to'] ?? '';
    $status = $_GET['export_status'] ?? '';
    $type   = $_GET['export_type'] ?? '';
    
    // Calculate dates based on range
    $start_date = '';
    $end_date   = '';
    
    if ($range === 'week') {
        $start_date = $ws_str;
        $end_date   = $we_str;
    } elseif ($range === 'month') {
        $start_date = date('Y-m-01');
        $end_date   = date('Y-m-t');
    } elseif ($range === 'quarter') {
        $cur_month = date('n');
        $cur_year  = date('Y');
        if ($cur_month <= 3) {
            $start_date = "$cur_year-01-01";
            $end_date   = "$cur_year-03-31";
        } elseif ($cur_month <= 6) {
            $start_date = "$cur_year-04-01";
            $end_date = "$cur_year-06-30";
        } elseif ($cur_month <= 9) {
            $start_date = "$cur_year-07-01";
            $end_date   = "$cur_year-09-30";
        } else {
            $start_date = "$cur_year-10-01";
            $end_date   = "$cur_year-12-31";
        }
    } elseif ($range === 'custom') {
        $start_date = $from;
        $end_date   = $to;
    }
    
    // Fallbacks
    if (!$start_date) $start_date = date('Y-m-d');
    if (!$end_date) $end_date = date('Y-m-d');
    
    // Fetch events using our helper
    $export_events = fetch_calendar_events($pdo, $station_id, $start_date, $end_date, $type, $status);
    
    if ($format === 'csv') {
        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendar_oversight_report_' . $start_date . '_to_' . $end_date . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference No', 'Category', 'Date', 'Time', 'Description', 'Staff Encoder', 'Validator/Manager', 'Status', 'Cost/Amount']);
        foreach ($export_events as $ev) {
            $time_str = ($ev['start_time'] !== '00:00') ? ($ev['start_time'] . ' - ' . $ev['end_time']) : '—';
            fputcsv($out, [
                $ev['ref_no'],
                $ev['type_name'],
                $ev['event_date'],
                $time_str,
                $ev['work_description'],
                $ev['staff_name'],
                $ev['manager_name'],
                ucfirst($ev['status']),
                ($ev['amount'] > 0) ? money($ev['amount']) : '—'
            ]);
        }
        fclose($out);
        exit;
    } elseif ($format === 'print') {
        // Render print-friendly view
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Calendar Oversight Compliance Report</title>
            <style>
                body { font-family: Arial, sans-serif; color: #333; margin: 30px; line-height: 1.5; }
                .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #00264D; padding-bottom: 15px; }
                .report-header h1 { color: #00264D; margin: 0 0 5px; font-size: 24px; text-transform: uppercase; }
                .report-header h3 { margin: 5px 0; font-size: 16px; color: #333; }
                .report-header p { margin: 5px 0; font-size: 12px; color: #777; }
                .meta-table { width: 100%; margin-bottom: 25px; font-size: 14px; }
                .meta-table td { padding: 4px 0; }
                .meta-table td.label { font-weight: bold; color: #555; width: 15%; }
                .meta-table td.value { width: 35%; }
                table.data-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
                table.data-table th, table.data-table td { border: 1px solid #ddd; padding: 10px 8px; text-align: left; }
                table.data-table th { background-color: #00264D; color: white; text-transform: uppercase; font-size: 11px; }
                table.data-table tr:nth-child(even) { background-color: #f9f9f9; }
                .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
                .badge-pending { background-color: #fef3c7; color: #d97706; }
                .badge-approved { background-color: #d1fae5; color: #059669; }
                .badge-completed { background-color: #dbeafe; color: #2563eb; }
                .badge-rejected { background-color: #fee2e2; color: #dc2626; }
                .badge-cancelled { background-color: #f3f4f6; color: #4b5563; }
                .footer-notes { margin-top: 40px; font-size: 11px; color: #777; text-align: center; border-top: 1px dashed #ccc; padding-top: 15px; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 15px; }
                    table.data-table th { background-color: #00264D !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="margin-bottom: 20px; text-align: right;">
                <button onclick="window.print()" style="background:#00264D; color:white; border:none; padding:10px 20px; border-radius:5px; font-weight:bold; cursor:pointer;">
                    Print Document
                </button>
                <button onclick="window.close()" style="background:#6b7280; color:white; border:none; padding:10px 20px; border-radius:5px; font-weight:bold; cursor:pointer; margin-left:10px;">
                    Close Window
                </button>
            </div>
            <div class="report-header">
                <h1>Petron Station Management System</h1>
                <h3>Calendar Oversight Compliance Report</h3>
                <p>Generated on <?php echo date('F j, Y, g:i A'); ?></p>
            </div>
            
            <table class="meta-table">
                <tr>
                    <td class="label">Station:</td>
                    <td class="value"><?php echo htmlspecialchars($station_name); ?></td>
                    <td class="label">Report Period:</td>
                    <td class="value"><?php echo date('M d, Y', strtotime($start_date)) . ' to ' . date('M d, Y', strtotime($end_date)); ?></td>
                </tr>
                <tr>
                    <td class="label">Category:</td>
                    <td class="value"><?php echo $type ? htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) : 'All Categories'; ?></td>
                    <td class="label">Status:</td>
                    <td class="value"><?php echo $status ? htmlspecialchars(ucfirst($status)) : 'All Statuses'; ?></td>
                </tr>
            </table>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ref No</th>
                        <th>Category</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Description / Details</th>
                        <th>Staff Encoder</th>
                        <th>Validator / Manager</th>
                        <th>Status</th>
                        <th>Cost / Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($export_events)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #777; padding: 20px;">No records found matching the criteria.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($export_events as $ev): 
                            $st = strtolower($ev['status'] ?? 'pending');
                            $badge_cls = 'badge-pending';
                            if (in_array($st, ['approved','confirmed','validated'])) $badge_cls = 'badge-approved';
                            elseif (in_array($st, ['completed','done'])) $badge_cls = 'badge-completed';
                            elseif (in_array($st, ['rejected','discrepancy'])) $badge_cls = 'badge-rejected';
                            elseif ($st === 'cancelled') $badge_cls = 'badge-cancelled';
                            
                            $time_str = ($ev['start_time'] !== '00:00') ? ($ev['start_time'] . ' - ' . $ev['end_time']) : '—';
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ev['ref_no']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ev['type_name']); ?></td>
                                <td><?php echo htmlspecialchars($ev['event_date']); ?></td>
                                <td><?php echo htmlspecialchars($time_str); ?></td>
                                <td><?php echo htmlspecialchars($ev['work_description']); ?></td>
                                <td><?php echo htmlspecialchars($ev['staff_name']); ?></td>
                                <td><?php echo htmlspecialchars($ev['manager_name']); ?></td>
                                <td><span class="badge <?php echo $badge_cls; ?>"><?php echo htmlspecialchars($ev['status']); ?></span></td>
                                <td><?php echo ($ev['amount'] > 0) ? '₱' . money($ev['amount']) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer-notes">
                <p>This document is an official system-generated audit report for Petron Station Calendar Oversight.</p>
                <p>Confidential — Internal Use Only</p>
            </div>
            
            <script>
                window.onload = function() {
                    window.print();
                }
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

// ── Handle AJAX Audit request early ──────────────────────────────────────────
if (isset($_GET['ajax_audit'])) {
    $ev_type = $_GET['event_type'] ?? '';
    $ev_id   = (int)($_GET['event_id'] ?? 0);
    $logs    = [];
    
    // 1. Fetch from general audit_trail
    try {
        $stmt = $pdo->prepare("
            SELECT at.*, u.name AS user_name, u.role AS user_role
            FROM audit_trail at
            LEFT JOIN users u ON at.manager_id = u.id
            WHERE at.station_id = ? AND (at.transaction_id = ? OR at.transaction_id = ?)
            ORDER BY at.timestamp DESC
        ");
        $prefix = '';
        if ($ev_type === 'job_order') $prefix = 'JO-' . $ev_id;
        elseif ($ev_type === 'delivery') $prefix = 'del-' . $ev_id;
        elseif ($ev_type === 'purchase_order') $prefix = 'po-' . $ev_id;
        
        $stmt->execute([$station_id, (string)$ev_id, $prefix]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $logs[] = [
                'timestamp'   => $r['timestamp'],
                'user_name'   => $r['user_name'] ?? 'System',
                'user_role'   => $r['user_role'] ?? '',
                'action'      => $r['action_type'],
                'details'     => $r['new_value'] ?? ''
            ];
        }
    } catch (Exception $e) {}

    // 2. If it is a Job Order, also fetch from job_order_audit table
    if ($ev_type === 'job_order') {
        try {
            $stmt = $pdo->prepare("
                SELECT joa.*, u.name AS user_name, u.role AS user_role
                FROM job_order_audit joa
                LEFT JOIN users u ON joa.performed_by = u.id
                WHERE joa.job_order_id = ?
                ORDER BY joa.performed_at DESC
            ");
            $stmt->execute([$ev_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $logs[] = [
                    'timestamp'   => $r['performed_at'],
                    'user_name'   => $r['user_name'] ?? 'System',
                    'user_role'   => $r['user_role'] ?? '',
                    'action'      => $r['action'],
                    'details'     => ($r['notes'] ? $r['notes'] . ' ' : '') . '[Before: ' . $r['before_status'] . ' -> After: ' . $r['after_status'] . ']'
                ];
            }
        } catch (Exception $e) {}
    }
    
    // Sort logs by timestamp desc
    usort($logs, function($a, $b) {
        return strtotime($b['timestamp'] ?? 'now') - strtotime($a['timestamp'] ?? 'now');
    });
    
    header('Content-Type: application/json');
    echo json_encode($logs);
    exit;
}

// ── Handle POST early (before any output) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'flag_event') {
    $ev_type = $_POST['event_type'] ?? '';
    $ev_id   = (int)($_POST['event_id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? '');
    $wo      = (int)($_GET['week'] ?? 0);
    if ($ev_id && $reason) {
        try {
            if (function_exists('log_activity')) {
                log_activity($pdo, $user_id, 'Admin Flagged Event', "Type: $ev_type | ID: $ev_id | Reason: $reason");
            }
            $_SESSION['success'] = "Event #$ev_id flagged successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Flag failed: ' . $e->getMessage();
        }
    }
    header("Location: admin_calendar.php?week=$wo");
    exit;
}

// ── Filter params ─────────────────────────────────────────────────────────────
$filter_status = trim($_GET['filter_status'] ?? '');
$filter_type   = trim($_GET['filter_type']   ?? '');

// ── Load events ───────────────────────────────────────────────────────────────
$all_events = fetch_calendar_events($pdo, $station_id, $ws_str, $we_str);

// ── Sidebar: today + upcoming events ─────────────────────────────────────────
$today_events    = [];
$upcoming_events = [];
$weekly_stats    = ['pending'=>0,'approved'=>0,'completed'=>0,'rejected'=>0,'total'=>0];
$three_days      = date('Y-m-d', strtotime('+3 days'));

foreach ($all_events as $ev) {
    $weekly_stats['total']++;
    $st = strtolower($ev['status'] ?? 'pending');
    if (in_array($st, ['pending','pending validation','pending manager approval'])) $weekly_stats['pending']++;
    elseif (in_array($st, ['approved','confirmed','validated'])) $weekly_stats['approved']++;
    elseif (in_array($st, ['completed','done'])) $weekly_stats['completed']++;
    elseif (in_array($st, ['rejected','discrepancy','cancelled'])) $weekly_stats['rejected']++;

    if ($ev['event_date'] === $today_str) {
        $today_events[] = $ev;
    } elseif ($ev['event_date'] > $today_str && $ev['event_date'] <= $three_days) {
        $upcoming_events[] = $ev;
    }
}

// Now filter for the visual grid
$grid_events = $all_events;
if ($filter_status !== '') {
    $grid_events = array_filter($grid_events, function($ev) use ($filter_status) {
        $st = strtolower($ev['status'] ?? '');
        if ($filter_status === 'pending')   return in_array($st, ['pending','pending validation','pending manager approval']);
        if ($filter_status === 'approved')  return in_array($st, ['approved','confirmed','validated']);
        if ($filter_status === 'completed') return in_array($st, ['completed','done']);
        if ($filter_status === 'rejected')  return in_array($st, ['rejected','discrepancy','cancelled']);
        return true;
    });
}
if ($filter_type !== '') {
    $grid_events = array_filter($grid_events, function($ev) use ($filter_type) {
        return $ev['type_key'] === $filter_type;
    });
}

// Group grid_events by category and date for easy rendering
$week_events = [];
foreach ($grid_events as $ev) {
    $week_events[$ev['type_key']][$ev['event_date']][] = $ev;
}

// ── Summary widget counts ─────────────────────────────────────────────────────
function safe_count(PDO $pdo, string $sql, array $p = []): int {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

$upcoming_deliveries = safe_count($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) >= CURDATE() AND status NOT IN ('Confirmed','Rejected','Discrepancy')",
    [$station_id]);

$scheduled_calibrations = safe_count($pdo,
    "SELECT COUNT(*) FROM calibration_logs WHERE station_id=? AND DATE(encoded_at) = CURDATE()",
    [$station_id]);

$pending_job_orders = safe_count($pdo,
    "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (validation_status IS NULL OR validation_status='Pending')",
    [$station_id]);

$active_shifts_today = safe_count($pdo,
    "SELECT COUNT(*) FROM staff_schedules ss JOIN users u ON ss.user_id = u.id WHERE u.station_id=? AND ss.scheduled_date=CURDATE()",
    [$station_id]);


// ── Helper functions ──────────────────────────────────────────────────────────
function adm_cal_type_color(string $type_key): string {
    return [
        'job_order'       => '#2563eb',
        'delivery'        => '#16a34a',
        'purchase_order'  => '#d97706',
        'fuel_calibration'=> '#f59e0b',
        'staff_shift'     => '#0891b2',
    ][$type_key] ?? '#6b7280';
}

function adm_cal_status_badge(string $status): string {
    $map = [
        'pending'                    => ['badge-pending',   'Pending'],
        'pending validation'         => ['badge-pending',   'Pending'],
        'pending manager approval'   => ['badge-pending',   'Pending'],
        'approved'                   => ['badge-approved',  'Approved'],
        'confirmed'                  => ['badge-approved',  'Confirmed'],
        'validated'                  => ['badge-approved',  'Validated'],
        'completed'                  => ['badge-completed', 'Completed'],
        'done'                       => ['badge-completed', 'Done'],
        'rejected'                   => ['badge-rejected',  'Rejected'],
        'discrepancy'                => ['badge-rejected',  'Discrepancy'],
        'cancelled'                  => ['badge-cancelled', 'Cancelled'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['badge-pending', ucfirst($status)];
    return '<span class="cal-badge '.$cls.'">'.$lbl.'</span>';
}

require_once '../partials/header.php';
?>

<style>
.sc-wrap{display:flex;gap:20px;padding:20px;max-width:100%;overflow-x:hidden;}
.sc-main{flex:1;min-width:0;}
.sc-sidebar{width:300px;flex-shrink:0;display:flex;flex-direction:column;gap:16px;}
.sc-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#fff;border:1px solid #EAEAEA;margin-bottom:18px;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);flex-wrap:wrap;gap:10px;}
.sc-header-left h2{margin:0;font-size:18px;font-weight:800;color:#101828;display:flex;align-items:center;}
.sc-header-left p{margin:3px 0 0;color:#667085;font-size:12px;}
.sc-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.sc-nav-btn{background:#f8fafc;border:1px solid #EAEAEA;color:#344054;padding:7px 14px;border-radius:8px;font-size:13px;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.sc-nav-btn:hover{background:#f0f4ff;border-color:#c7d7f5;color:#00264D;}
.sc-week-label{font-weight:700;font-size:14px;min-width:160px;text-align:center;color:#101828;}
.sc-today-btn{background:#00264D;color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.sc-today-btn:hover{background:#003d7a;color:#fff;}
.sc-filter-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;background:#fff;border:1px solid #EAEAEA;border-radius:10px;padding:10px 14px;}
.sc-filter-bar select{padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:12px;color:#344054;}
.sc-filter-bar label{font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.4px;}
.sc-filter-bar .fg{display:flex;flex-direction:column;gap:3px;}
.sc-filter-btn{background:#00264D;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;}
.sc-filter-clear{background:#f8fafc;color:#344054;border:1px solid #dee2e6;padding:6px 12px;border-radius:6px;font-size:12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.sc-grid-wrap{background:#e9eaec;border-radius:14px;border:1px solid #d8dadf;overflow-x:auto;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.sc-grid{display:grid;grid-template-columns:180px repeat(7,minmax(100px,1fr));min-width:900px;}
.sc-col-head-label{background:#eef0f3;padding:10px 12px;border-bottom:2px solid #d8dadf;border-right:1px solid #d8dadf;font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;}
.sc-col-head{background:#eef0f3;padding:10px 8px;text-align:center;border-bottom:2px solid #d8dadf;border-right:1px solid #d8dadf;}
.sc-col-head:last-child{border-right:none;}
.sc-col-head.today-col{background:#eef4ff;}
.day-name{font-size:11px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.5px;}
.day-num{font-size:18px;font-weight:800;color:#101828;line-height:1.2;}
.day-num.today{background:#00264D;color:#fff;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:15px;}
.sc-section-cell{padding:12px 10px;background:#fff;border-bottom:1px solid #d8dadf;border-right:1px solid #d8dadf;display:flex;align-items:center;gap:10px;min-height:60px;}
.sc-section-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:14px;flex-shrink:0;}
.sc-section-name{font-weight:800;font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.4px;}
.sc-section-sub{font-size:10px;color:#667085;margin-top:2px;}
.sc-day-cell{padding:6px;background:#f5f6f8;border-bottom:1px solid #d8dadf;border-right:1px solid #d8dadf;min-height:80px;vertical-align:top;}
.sc-day-cell:last-child{border-right:none;}
.sc-day-cell.today-col{background:#eef4ff;}
.sc-off-label{font-size:11px;color:#9ca3af;text-align:center;padding-top:20px;}
.sc-event{padding:8px;border-radius:8px;margin-bottom:6px;border-left:4px solid;cursor:pointer;transition:all .2s;font-size:11px;}
.sc-event:hover{transform:translateX(2px);box-shadow:0 2px 4px rgba(0,0,0,.1);}
.sc-event-type{font-weight:600;margin-bottom:2px;display:flex;align-items:center;gap:4px;}
.sc-event-desc{color:#374151;line-height:1.3;margin-bottom:2px;}
.sc-event-time{color:#6b7280;font-size:10px;margin-bottom:2px;}
.sc-event-staff{font-size:10px;color:#374151;margin-bottom:2px;display:flex;align-items:center;}
.sc-event-mgr{color:#dc2626;font-size:10px;margin-bottom:2px;}
.cal-badge{font-size:9px;font-weight:700;padding:2px 6px;border-radius:12px;text-transform:uppercase;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-approved{background:#d1fae5;color:#065f46;}
.badge-completed{background:#dbeafe;color:#1e40af;}
.badge-cancelled{background:#f3f4f6;color:#374151;}
.badge-rejected{background:#fee2e2;color:#991b1b;}
.sc-synced{display:inline-flex;align-items:center;gap:3px;background:#D1FAE5;color:#065F46;font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;text-transform:uppercase;}
.sc-card{background:#f5f6f8;border-radius:14px;border:1px solid #e4e6ea;padding:16px;}
.sc-card-title{font-weight:600;color:#111827;margin:0 0 12px;display:flex;align-items:center;gap:6px;font-size:14px;}
.sc-today-item{display:flex;gap:8px;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f3f4f6;}
.sc-today-item:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.sc-today-dot{width:8px;height:8px;border-radius:50%;margin-top:6px;flex-shrink:0;}
.sc-today-info{flex:1;min-width:0;}
.sc-today-type{font-weight:600;font-size:12px;color:#111827;margin-bottom:2px;}
.sc-today-desc{font-size:11px;color:#6b7280;margin-bottom:1px;line-height:1.3;}
.sc-status-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.sc-status-row:last-child{margin-bottom:0;}
.sc-status-label{font-size:12px;color:#374151;display:flex;align-items:center;gap:6px;}
.sc-status-count{font-weight:600;font-size:13px;color:#111827;}
.sc-widget-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:0;}
.sc-widget{background:#fff;border-radius:10px;border:1px solid #e4e6ea;padding:12px;text-align:center;}
.sc-widget-num{font-size:24px;font-weight:800;line-height:1;}
.sc-widget-lbl{font-size:10px;font-weight:700;color:#667085;text-transform:uppercase;letter-spacing:.4px;margin-top:4px;}
.sc-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:9999;}
.sc-modal-overlay.open{display:flex;}
.sc-modal{background:#fff;border-radius:16px;width:min(520px,94vw);max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.sc-modal-body{padding:20px 24px;}
.sc-detail-row{display:flex;gap:12px;padding:8px 0;border-bottom:1px solid #f0f0f0;align-items:flex-start;}
.sc-detail-row:last-child{border-bottom:none;}
.sc-detail-label{font-size:12px;font-weight:700;color:#667085;width:120px;flex-shrink:0;padding-top:1px;}
.sc-detail-val{font-size:13px;color:#101828;flex:1;}
.sc-flag-btn{background:#CC0000;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;}
.sc-flag-btn:hover{background:#a00000;}
.sc-alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px;font-weight:600;}
.sc-alert.success{background:#D1FAE5;color:#065F46;border:1px solid #A7F3D0;}
.sc-alert.error{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;}
@media(max-width:900px){
  .sc-wrap{flex-direction:column;}
  .sc-sidebar{width:100%;flex:none;}
  .sc-grid{grid-template-columns:120px repeat(7,minmax(80px,1fr));}
  .sc-widget-grid{grid-template-columns:1fr 1fr;}
}
</style>

<div class="sc-wrap">
  <!-- ===== MAIN CALENDAR ===== -->
  <div class="sc-main">

    <?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
    <div class="sc-alert <?php echo isset($_SESSION['error']) ? 'error' : 'success'; ?>">
      <i class="fas fa-<?php echo isset($_SESSION['error']) ? 'exclamation-circle' : 'check-circle'; ?>"></i>
      <?php echo htmlspecialchars($_SESSION['success'] ?? $_SESSION['error'] ?? ''); ?>
      <?php unset($_SESSION['success'], $_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="sc-header">
      <div class="sc-header-left">
        <h2><i class="fas fa-calendar-check" style="margin-right:8px;color:#00264D;"></i>Admin Calendar Oversight</h2>
        <p>Deliveries, Job Orders, Fuel Calibration, Purchase Orders, Staff Shifts</p>
      </div>
      <div class="sc-nav">
        <a href="admin_calendar.php?week=<?php echo $prev_week; ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_type=<?php echo urlencode($filter_type); ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-left"></i>
        </a>
        <span class="sc-week-label"><?php echo htmlspecialchars($week_label); ?></span>
        <a href="admin_calendar.php?week=<?php echo $next_week; ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_type=<?php echo urlencode($filter_type); ?>" class="sc-nav-btn">
          <i class="fas fa-chevron-right"></i>
        </a>
        <a href="admin_calendar.php?week=0" class="sc-today-btn">Today</a>
        <button type="button" onclick="openExportModal()" class="sc-nav-btn" title="Export Compliance Report">
          <i class="fas fa-file-export"></i> Export Report
        </button>
      </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="admin_calendar.php" class="sc-filter-bar">
      <input type="hidden" name="week" value="<?php echo $week_offset; ?>">
      <div class="fg">
        <label>Status</label>
        <select name="filter_status">
          <option value="">All Status</option>
          <option value="pending" <?php echo $filter_status==='pending'?'selected':''; ?>>Pending</option>
          <option value="approved" <?php echo $filter_status==='approved'?'selected':''; ?>>Approved</option>
          <option value="completed" <?php echo $filter_status==='completed'?'selected':''; ?>>Completed</option>
          <option value="rejected" <?php echo $filter_status==='rejected'?'selected':''; ?>>Rejected</option>
        </select>
      </div>
      <div class="fg">
        <label>Event Type</label>
        <select name="filter_type">
          <option value="">All Types</option>
          <option value="job_order" <?php echo $filter_type==='job_order'?'selected':''; ?>>Job Orders</option>
          <option value="delivery" <?php echo $filter_type==='delivery'?'selected':''; ?>>Deliveries</option>
          <option value="purchase_order" <?php echo $filter_type==='purchase_order'?'selected':''; ?>>Purchase Orders</option>
          <option value="fuel_calibration" <?php echo $filter_type==='fuel_calibration'?'selected':''; ?>>Fuel Calibration</option>
          <option value="staff_shift" <?php echo $filter_type==='staff_shift'?'selected':''; ?>>Staff Shifts</option>
        </select>
      </div>
      <div class="fg" style="justify-content:flex-end;">
        <label>&nbsp;</label>
        <div style="display:flex;gap:6px;">
          <button type="submit" class="sc-filter-btn"><i class="fas fa-filter"></i> Filter</button>
          <a href="admin_calendar.php?week=<?php echo $week_offset; ?>" class="sc-filter-clear"><i class="fas fa-times"></i> Clear</a>
        </div>
      </div>
    </form>

    <!-- Weekly Grid -->
    <div class="sc-grid-wrap">
      <div class="sc-grid">

        <!-- Column headers -->
        <div class="sc-col-head-label">Category</div>
        <?php
        $day_names = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach ($week_dates as $i => $date):
          $is_today = ($date->format('Y-m-d') === $today_str);
        ?>
        <div class="sc-col-head <?php echo $is_today ? 'today-col' : ''; ?>">
          <div class="day-name"><?php echo $day_names[$i]; ?></div>
          <div class="day-num <?php echo $is_today ? 'today' : ''; ?>"><?php echo $date->format('j'); ?></div>
        </div>
        <?php endforeach; ?>

        <?php
        // Section definitions — 5 rows
        $sections = [
            ['key'=>'job_order',        'label'=>'Job Orders',       'icon'=>'fas fa-wrench',               'color'=>'#2563eb', 'desc'=>'Staff-encoded orders'],
            ['key'=>'delivery',         'label'=>'Deliveries',       'icon'=>'fas fa-truck',                'color'=>'#16a34a', 'desc'=>'Incoming deliveries'],
            ['key'=>'purchase_order',   'label'=>'Purchase Orders',  'icon'=>'fas fa-file-invoice-dollar',  'color'=>'#d97706', 'desc'=>'PO tracking'],
            ['key'=>'fuel_calibration', 'label'=>'Fuel Calibration', 'icon'=>'fas fa-tachometer-alt',       'color'=>'#f59e0b', 'desc'=>'Pump calibration'],
            ['key'=>'staff_shift',      'label'=>'Staff Shifts',     'icon'=>'fas fa-user-clock',           'color'=>'#0891b2', 'desc'=>'Active duty schedules'],
        ];

        foreach ($sections as $section):
          $tk = $section['key'];
          $sc = $section['color'];

          // Apply type filter
          if ($filter_type && $filter_type !== $tk) {
              continue;
          }
        ?>

        <!-- Section label -->
        <div class="sc-section-cell" style="border-left:4px solid <?php echo $sc; ?>">
          <div class="sc-section-icon" style="background:<?php echo $sc; ?>18;color:<?php echo $sc; ?>">
            <i class="<?php echo $section['icon']; ?>"></i>
          </div>
          <div>
            <div class="sc-section-name" style="color:<?php echo $sc; ?>"><?php echo $section['label']; ?></div>
            <div class="sc-section-sub"><?php echo $section['desc']; ?></div>
          </div>
        </div>

        <!-- Day cells -->
        <?php foreach ($week_dates as $date):
          $is_today  = ($date->format('Y-m-d') === $today_str);
          $date_str  = $date->format('Y-m-d');
          $all_evs   = $week_events[$tk][$date_str] ?? [];

          // Apply status filter
          if ($filter_status) {
              $all_evs = array_filter($all_evs, function($ev) use ($filter_status) {
                  $st = strtolower($ev['status'] ?? '');
                  if ($filter_status === 'pending')   return in_array($st, ['pending','pending validation','pending manager approval']);
                  if ($filter_status === 'approved')  return in_array($st, ['approved','confirmed','validated']);
                  if ($filter_status === 'completed') return in_array($st, ['completed','done']);
                  if ($filter_status === 'rejected')  return in_array($st, ['rejected','discrepancy']);
                  return true;
              });
          }
        ?>
        <div class="sc-day-cell <?php echo $is_today ? 'today-col' : ''; ?>">
          <?php if (empty($all_evs)): ?>
            <div class="sc-off-label">—</div>
          <?php else: ?>
            <?php foreach ($all_evs as $ev):
              $st     = strtolower($ev['status'] ?? 'pending');
              $bg     = $sc . '18';
              $ev_js  = htmlspecialchars(json_encode($ev), ENT_QUOTES);
              $time_str = (!empty($ev['start_time']) && $ev['start_time'] !== '00:00')
                ? date('g:ia', strtotime($ev['start_time'])).' – '.date('g:ia', strtotime($ev['end_time']))
                : '';
            ?>
            <div class="sc-event" style="background:<?php echo $bg; ?>;border-left-color:<?php echo $sc; ?>"
                 onclick='openDetailModal(<?php echo $ev_js; ?>)'>
              <div class="sc-event-type" style="color:<?php echo $sc; ?>">
                <i class="<?php echo htmlspecialchars($ev['icon_class']); ?>"></i>
                <?php echo htmlspecialchars($ev['type_name']); ?>
                <span class="sc-synced"><i class="fas fa-sync-alt"></i></span>
              </div>
              <div class="sc-event-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'], 0, 45, '…')); ?></div>
              <?php if ($time_str): ?><div class="sc-event-time"><?php echo $time_str; ?></div><?php endif; ?>
              <div class="sc-event-staff">
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $sc; ?>;margin-right:3px;"></span>
                <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?>
              </div>
              <?php if (!empty($ev['manager_name']) && $ev['manager_name'] !== '—'): ?>
              <div class="sc-event-mgr">
                <i class="fas fa-user-tie" style="font-size:9px;"></i>
                <?php echo htmlspecialchars($ev['manager_name']); ?>
              </div>
              <?php endif; ?>
              <?php echo adm_cal_status_badge($st); ?>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; // week_dates ?>

        <?php endforeach; // sections ?>

      </div><!-- .sc-grid -->
    </div><!-- .sc-grid-wrap -->

  </div><!-- .sc-main -->

  <!-- ===== RIGHT SIDEBAR ===== -->
  <div class="sc-sidebar">

    <!-- Summary Widgets -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-chart-bar" style="color:#00264D;"></i> Summary Dashboard</p>
      <div class="sc-widget-grid">
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#16a34a;"><?php echo $upcoming_deliveries; ?></div>
          <div class="sc-widget-lbl">Upcoming Deliveries</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#f59e0b;"><?php echo $scheduled_calibrations; ?></div>
          <div class="sc-widget-lbl">Scheduled Calibrations</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#2563eb;"><?php echo $pending_job_orders; ?></div>
          <div class="sc-widget-lbl">Pending Job Orders</div>
        </div>
        <div class="sc-widget">
          <div class="sc-widget-num" style="color:#0891b2;"><?php echo $active_shifts_today; ?></div>
          <div class="sc-widget-lbl">Shifts Active Today</div>
        </div>
      </div>
    </div>

    <!-- Today's Events -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-sun" style="color:#d97706;"></i> Today's Events
        <span style="margin-left:auto;background:#00264D;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;">
          <?php echo count($today_events); ?>
        </span>
      </p>
      <?php if (empty($today_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0;">No events today</p>
      <?php else: ?>
        <?php foreach (array_slice($today_events, 0, 6) as $ev):
          $tc = adm_cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>;"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'] ?? '', 0, 50, '…')); ?></div>
            <div class="sc-today-desc"><i class="fas fa-user" style="font-size:9px;"></i> <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?></div>
            <?php echo adm_cal_status_badge(strtolower($ev['status'] ?? 'pending')); ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($today_events) > 6): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px;">+<?php echo count($today_events) - 6; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- This Week Status -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-chart-pie" style="color:#6366f1;"></i> This Week Status</p>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#92400e;display:inline-block;"></span> Pending</span>
        <span class="sc-status-count"><?php echo $weekly_stats['pending']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#065f46;display:inline-block;"></span> Approved</span>
        <span class="sc-status-count"><?php echo $weekly_stats['approved']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#1e40af;display:inline-block;"></span> Completed</span>
        <span class="sc-status-count"><?php echo $weekly_stats['completed']; ?></span>
      </div>
      <div class="sc-status-row">
        <span class="sc-status-label"><span style="width:10px;height:10px;border-radius:50%;background:#991b1b;display:inline-block;"></span> Rejected</span>
        <span class="sc-status-count"><?php echo $weekly_stats['rejected']; ?></span>
      </div>
      <div class="sc-status-row" style="border-top:1px solid #e4e6ea;padding-top:8px;margin-top:4px;">
        <span class="sc-status-label" style="font-weight:700;">Total Events</span>
        <span class="sc-status-count"><?php echo $weekly_stats['total']; ?></span>
      </div>
    </div>

    <!-- Upcoming (next 3 days) -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-clock" style="color:#d97706;"></i> Upcoming (3 days)
        <span style="margin-left:auto;background:#d97706;color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;">
          <?php echo count($upcoming_events); ?>
        </span>
      </p>
      <?php if (empty($upcoming_events)): ?>
        <p style="font-size:12px;color:#9ca3af;text-align:center;padding:12px 0;">No upcoming events</p>
      <?php else: ?>
        <?php foreach (array_slice($upcoming_events, 0, 5) as $ev):
          $tc = adm_cal_type_color($ev['type_key'] ?? 'job_order');
        ?>
        <div class="sc-today-item">
          <div class="sc-today-dot" style="background:<?php echo $tc; ?>;"></div>
          <div class="sc-today-info">
            <div class="sc-today-type"><?php echo htmlspecialchars($ev['type_name'] ?? 'Event'); ?></div>
            <div class="sc-today-desc"><?php echo htmlspecialchars(mb_strimwidth($ev['work_description'] ?? '', 0, 45, '…')); ?></div>
            <div class="sc-today-desc" style="color:#374151;">
              <?php echo date('M j', strtotime($ev['event_date'])); ?>
              &bull; <i class="fas fa-user" style="font-size:9px;"></i> <?php echo htmlspecialchars($ev['staff_name'] ?? '—'); ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($upcoming_events) > 5): ?>
          <p style="font-size:11px;color:#667085;text-align:center;margin-top:8px;">+<?php echo count($upcoming_events) - 5; ?> more</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Event Type Legend -->
    <div class="sc-card">
      <p class="sc-card-title"><i class="fas fa-palette" style="color:#6b7280;"></i> Event Types</p>
      <?php
      $legend = [
          ['color'=>'#2563eb','label'=>'Job Orders'],
          ['color'=>'#16a34a','label'=>'Deliveries'],
          ['color'=>'#d97706','label'=>'Purchase Orders'],
          ['color'=>'#f59e0b','label'=>'Fuel Calibration'],
          ['color'=>'#0891b2','label'=>'Staff Shifts'],
      ];
      foreach ($legend as $l): ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <div style="width:12px;height:12px;border-radius:50%;background:<?php echo $l['color']; ?>;flex-shrink:0;"></div>
        <span style="font-size:12px;color:#374151;"><?php echo $l['label']; ?></span>
      </div>
      <?php endforeach; ?>
    </div>

  </div><!-- .sc-sidebar -->
</div><!-- .sc-wrap -->


<!-- ===== DETAIL MODAL ===== -->
<div class="sc-modal-overlay" id="detailModal">
  <div class="sc-modal">
    <div style="background:linear-gradient(135deg,#00264D,#003d7a);color:#fff;padding:20px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <h3 id="modalTitle" style="margin:0;font-size:17px;font-weight:800;color:#fff;"></h3>
      <button onclick="closeDetailModal()" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div class="sc-modal-body" id="modalBody"></div>
  </div>
</div>

<!-- ===== EXPORT REPORT MODAL ===== -->
<div class="sc-modal-overlay" id="exportModal">
  <div class="sc-modal" style="max-width: 480px;">
    <div style="background:linear-gradient(135deg,#00264D,#003d7a);color:#fff;padding:20px 24px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:17px;font-weight:800;color:#fff;"><i class="fas fa-file-export" style="margin-right:8px;"></i>Export Compliance Report</h3>
      <button onclick="closeExportModal()" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <form method="GET" action="admin_calendar.php" target="_blank" class="sc-modal-body" style="padding: 24px;">
      <input type="hidden" name="export_report" value="1">
      
      <div style="margin-bottom: 16px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#344054;margin-bottom:6px;">Report Period Preset</label>
        <select name="export_range" id="exportRange" onchange="toggleCustomDates()" style="width:100%;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;background:#fff;" required>
          <option value="week">Current Week (<?php echo htmlspecialchars($week_label); ?>)</option>
          <option value="month">Current Month (<?php echo date('F Y'); ?>)</option>
          <option value="quarter">Current Quarter (Q<?php echo ceil(date('n')/3); ?>)</option>
          <option value="custom">Custom Date Range</option>
        </select>
      </div>
      
      <div id="customDateFields" style="display:none;margin-bottom: 16px;gap: 12px;">
        <div style="flex:1;">
          <label style="display:block;font-size:12px;font-weight:600;color:#344054;margin-bottom:6px;">From Date</label>
          <input type="date" name="export_from" id="exportFrom" style="width:100%;padding:8px 10px;border:1px solid #d0d5dd;border-radius:8px;font-size:13px;">
        </div>
        <div style="flex:1;">
          <label style="display:block;font-size:12px;font-weight:600;color:#344054;margin-bottom:6px;">To Date</label>
          <input type="date" name="export_to" id="exportTo" style="width:100%;padding:8px 10px;border:1px solid #d0d5dd;border-radius:8px;font-size:13px;">
        </div>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#344054;margin-bottom:6px;">Event Category</label>
        <select name="export_type" style="width:100%;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;background:#fff;">
          <option value="">All Categories</option>
          <option value="job_order">Job Orders</option>
          <option value="delivery">Deliveries</option>
          <option value="purchase_order">Purchase Orders</option>
          <option value="fuel_calibration">Fuel Calibration</option>
          <option value="staff_shift">Staff Shifts</option>
        </select>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#344054;margin-bottom:6px;">Status Filter</label>
        <select name="export_status" style="width:100%;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;background:#fff;">
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved / Validated</option>
          <option value="completed">Completed / Done</option>
          <option value="rejected">Rejected / Discrepancy</option>
        </select>
      </div>
      
      <div style="margin-bottom: 24px;">
        <label style="display:block;font-size:13px;font-weight:600;color:#344054;margin-bottom:6px;">Export Format</label>
        <div style="display:flex;gap:16px;margin-top:6px;">
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:14px;color:#344054;cursor:pointer;">
            <input type="radio" name="export_format" value="csv" checked style="accent-color:#00264D;">
            Excel / CSV Sheet
          </label>
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:14px;color:#344054;cursor:pointer;">
            <input type="radio" name="export_format" value="print" style="accent-color:#00264D;">
            Printable PDF Layout
          </label>
        </div>
      </div>
      
      <div style="display:flex;justify-content:flex-end;gap:12px;border-top:1px solid #f0f0f0;padding-top:16px;">
        <button type="button" onclick="closeExportModal()" style="background:#fff;border:1px solid #d0d5dd;color:#344054;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;">Cancel</button>
        <button type="submit" onclick="closeExportModal()" style="background:#00264D;border:none;color:#fff;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;">
          <i class="fas fa-file-export" style="margin-right:4px;"></i> Generate Report
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let currentAuditLogs = [];
let auditLogsLoaded = false;
let auditLogsLoading = false;

function openDetailModal(ev) {
  const statusMap = {
    pending:'badge-pending', approved:'badge-approved', confirmed:'badge-approved',
    validated:'badge-approved', completed:'badge-completed', done:'badge-completed',
    rejected:'badge-rejected', discrepancy:'badge-rejected', cancelled:'badge-cancelled'
  };
  const st    = (ev.status || 'pending').toLowerCase();
  const badge = `<span class="cal-badge ${statusMap[st]||'badge-pending'}">${st.charAt(0).toUpperCase()+st.slice(1)}</span>`;

  const typeColors = {
    job_order:'#2563eb', delivery:'#16a34a', purchase_order:'#d97706',
    fuel_calibration:'#f59e0b', staff_shift:'#0891b2'
  };
  const tc = typeColors[ev.type_key] || '#6b7280';

  const evDate = ev.event_date
    ? new Date(ev.event_date + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',year:'numeric',month:'short',day:'numeric'})
    : '—';

  // Build the info content
  let infoHtml = `
    <div class="sc-detail-row"><span class="sc-detail-label">Reference No</span><span class="sc-detail-val"><strong>${ev.ref_no||'—'}</strong></span></div>
    <div class="sc-detail-row"><span class="sc-detail-label">Date</span><span class="sc-detail-val">${evDate}</span></div>
    <div class="sc-detail-row">
      <span class="sc-detail-label">Event Type</span>
      <span class="sc-detail-val"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:${tc};display:inline-block;"></span>${ev.type_name||'—'}</span></span>
    </div>
    <div class="sc-detail-row"><span class="sc-detail-label">Description</span><span class="sc-detail-val">${ev.work_description||'—'}</span></div>`;

  if (ev.staff_name && ev.staff_name !== '—') {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Encoded By</span><span class="sc-detail-val"><i class="fas fa-user" style="color:#2563eb;font-size:10px;margin-right:4px;"></i>${ev.staff_name}</span></div>`;
  }
  if (ev.manager_name && ev.manager_name !== '—') {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Validated By</span><span class="sc-detail-val"><i class="fas fa-user-tie" style="color:#dc2626;font-size:10px;margin-right:4px;"></i>${ev.manager_name}</span></div>`;
  }
  if (ev.customer_name) {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Customer</span><span class="sc-detail-val">${ev.customer_name}</span></div>`;
  }
  if (ev.vehicle_plate) {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Vehicle Plate</span><span class="sc-detail-val">${ev.vehicle_plate}</span></div>`;
  }
  if (Number(ev.amount) > 0) {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Total Cost / Value</span><span class="sc-detail-val">₱${Number(ev.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span></div>`;
  }
  if (ev.start_time && ev.start_time !== '00:00') {
    infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Time</span><span class="sc-detail-val">${ev.start_time} – ${ev.end_time}</span></div>`;
  }
  infoHtml += `<div class="sc-detail-row"><span class="sc-detail-label">Status</span><span class="sc-detail-val">${badge}</span></div>`;

  // Admin flag action
  infoHtml += `
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f0;">
      <p style="font-size:12px;font-weight:700;color:#344054;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px;">Compliance Actions</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="sc-flag-btn" onclick="flagEvent('${ev.type_key}',${ev.raw_id})">
          <i class="fas fa-flag"></i> Flag Discrepancy
        </button>
      </div>
    </div>`;

  // Wrap tab contents
  const modalBodyHtml = `
    <div class="sc-modal-tabs" style="display:flex;border-bottom:1px solid #e4e6ea;margin:0 -24px 16px -24px;padding:0 24px;">
      <button class="sc-modal-tab active" id="tabInfoBtn" onclick="showModalTab('info')" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid #00264D;font-weight:600;font-size:13px;cursor:pointer;color:#00264D;transition:all .2s;">Event Info</button>
      <button class="sc-modal-tab" id="tabAuditBtn" onclick="showModalTab('audit')" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;font-weight:600;font-size:13px;cursor:pointer;color:#667085;transition:all .2s;">Audit Trail & Logs</button>
    </div>
    <div id="modalTabInfoContent">${infoHtml}</div>
    <div id="modalTabAuditContent" style="display:none;">
      <div style="text-align:center;padding:20px 0;color:#6b7280;" id="auditLoader">
        <i class="fas fa-circle-notch fa-spin"></i> Loading audit history...
      </div>
      <div id="auditLogsTimeline" style="display:none;"></div>
    </div>
  `;

  document.getElementById('modalTitle').innerHTML =
    `<i class="${ev.icon_class||'fas fa-calendar-check'}" style="margin-right:8px;"></i>${ev.type_name||'Event Details'}`;
  document.getElementById('modalBody').innerHTML = modalBodyHtml;
  document.getElementById('detailModal').classList.add('open');

  // Trigger dynamic audit log loading
  auditLogsLoaded = false;
  auditLogsLoading = true;
  currentAuditLogs = [];
  
  fetch(`admin_calendar.php?ajax_audit=1&event_type=${ev.type_key}&event_id=${ev.raw_id}`)
    .then(r => r.json())
    .then(data => {
      currentAuditLogs = data;
      auditLogsLoaded = true;
      auditLogsLoading = false;
      renderAuditTimeline();
    })
    .catch(err => {
      document.getElementById('auditLoader').innerHTML = 
        `<span style="color:#d92d20;"><i class="fas fa-exclamation-triangle"></i> Failed to load audit trail</span>`;
      auditLogsLoading = false;
    });
}

function showModalTab(tab) {
  const infoBtn = document.getElementById('tabInfoBtn');
  const auditBtn = document.getElementById('tabAuditBtn');
  const infoContent = document.getElementById('modalTabInfoContent');
  const auditContent = document.getElementById('modalTabAuditContent');
  
  if (tab === 'info') {
    infoBtn.style.color = '#00264D';
    infoBtn.style.borderBottomColor = '#00264D';
    auditBtn.style.color = '#667085';
    auditBtn.style.borderBottomColor = 'transparent';
    infoContent.style.display = 'block';
    auditContent.style.display = 'none';
  } else {
    auditBtn.style.color = '#00264D';
    auditBtn.style.borderBottomColor = '#00264D';
    infoBtn.style.color = '#667085';
    infoBtn.style.borderBottomColor = 'transparent';
    infoContent.style.display = 'none';
    auditContent.style.display = 'block';
    
    if (auditLogsLoaded) {
      renderAuditTimeline();
    }
  }
}

function renderAuditTimeline() {
  const loader = document.getElementById('auditLoader');
  const timeline = document.getElementById('auditLogsTimeline');
  
  if (currentAuditLogs.length === 0) {
    loader.innerHTML = '<p style="text-align:center;color:#6b7280;font-size:12px;padding:12px 0;">No audit trail logs recorded for this event.</p>';
    loader.style.display = 'block';
    timeline.style.display = 'none';
    return;
  }
  
  loader.style.display = 'none';
  timeline.style.display = 'block';
  
  let html = '<div style="position:relative;padding-left:24px;border-left:2px solid #e4e6ea;margin-left:12px;padding-top:8px;">';
  
  currentAuditLogs.forEach((log, index) => {
    const dateFormatted = new Date(log.timestamp).toLocaleString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
    
    html += `
      <div style="position:relative;margin-bottom:20px;">
        <div style="position:absolute;left:-31px;top:2px;width:12px;height:12px;border-radius:50%;background:#00264D;border:2px solid #fff;box-shadow:0 0 0 2px #e4e6ea;"></div>
        <div style="font-size:11px;color:#667085;font-weight:500;">${dateFormatted}</div>
        <div style="font-size:13px;font-weight:700;color:#1d2939;margin-top:2px;">${log.action}</div>
        <div style="font-size:12px;color:#475467;margin-top:4px;">
          <span style="font-weight:600;">By:</span> ${log.user_name} (${log.user_role})
        </div>
        ${log.details ? `<div style="font-size:12px;color:#667085;background:#f8f9fa;padding:6px 10px;border-radius:6px;border-left:3px solid #00264D;margin-top:6px;font-style:italic;">${log.details}</div>` : ''}
      </div>
    `;
  });
  
  html += '</div>';
  timeline.innerHTML = html;
}

function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('open');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetailModal();
});

function flagEvent(type, id) {
  const reason = prompt('Enter reason for flagging this event (required):');
  if (!reason || !reason.trim()) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'admin_calendar.php?week=<?php echo (int)$week_offset; ?>';
  [['action','flag_event'],['event_type',type],['event_id',id],['reason',reason]].forEach(([n,v]) => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name=n; inp.value=v;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
}

function openExportModal() {
  document.getElementById('exportModal').classList.add('open');
}

function closeExportModal() {
  document.getElementById('exportModal').classList.remove('open');
}

document.getElementById('exportModal').addEventListener('click', function(e) {
  if (e.target === this) closeExportModal();
});

function toggleCustomDates() {
  const range = document.getElementById('exportRange').value;
  const customFields = document.getElementById('customDateFields');
  const fromInput = document.getElementById('exportFrom');
  const toInput = document.getElementById('exportTo');
  
  if (range === 'custom') {
    customFields.style.display = 'flex';
    fromInput.required = true;
    toInput.required = true;
  } else {
    customFields.style.display = 'none';
    fromInput.required = false;
    toInput.required = false;
  }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>