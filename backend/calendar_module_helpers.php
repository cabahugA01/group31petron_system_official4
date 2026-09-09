<?php

function calendar_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cache[$table] = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function calendar_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, calendar_table_columns($pdo, $table), true);
}

function calendar_ensure_schema(PDO $pdo): void
{
    if (!calendar_has_column($pdo, 'staff_calendar_events', 'metadata')) {
        try {
            $pdo->exec("ALTER TABLE staff_calendar_events ADD COLUMN metadata TEXT NULL");
        } catch (Throwable $e) {
            // Another request may have added it first.
        }
    }

    $defaults = [
        ['staff_shift', 'Staff Shift', 'Shift assignment or schedule', 'fas fa-clock', 'text-primary', 10],
        ['job_order', 'Job Order', 'Job order schedule or activity', 'fas fa-wrench', 'text-warning', 20],
        ['fuel_delivery', 'Fuel Delivery', 'Fuel delivery schedule', 'fas fa-gas-pump', 'text-danger', 30],
        ['merchandise_delivery', 'Merchandise Delivery', 'Merchandise delivery schedule', 'fas fa-box', 'text-info', 40],
        ['fuel_calibration', 'Fuel Calibration', 'Fuel calibration task', 'fas fa-tools', 'text-secondary', 50],
        ['meter_reading', 'Meter Reading', 'Meter reading task', 'fas fa-tachometer-alt', 'text-secondary', 60],
        ['customer_transaction', 'Customer Transaction', 'Customer transaction reminder', 'fas fa-receipt', 'text-success', 70],
        ['payment_collection', 'Payment Collection', 'Payment collection reminder', 'fas fa-money-bill-wave', 'text-success', 80],
        ['maintenance', 'Maintenance', 'Maintenance task', 'fas fa-screwdriver-wrench', 'text-muted', 90],
        ['meeting', 'Meeting', 'Meeting schedule', 'fas fa-users', 'text-primary', 100],
        ['training', 'Training', 'Training schedule', 'fas fa-chalkboard-teacher', 'text-primary', 110],
        ['other', 'Other', 'General calendar event', 'fas fa-calendar', 'text-primary', 120],
    ];

    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO staff_event_types
                (type_key, type_name, description, icon_class, color_class, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    } catch (Throwable $e) {
        // Calendar can still render existing events if defaults cannot be inserted.
    }
}

function calendar_clean_text($value, string $fallback = ''): string
{
    $text = trim((string)$value);
    return $text === '' ? $fallback : $text;
}

function calendar_normalize_date($value): string
{
    $date = trim((string)$value);
    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
    return $dt ? $dt->format('Y-m-d') : '';
}

function calendar_normalize_time($value, string $fallback = '00:00:00'): string
{
    $time = trim((string)$value);
    if ($time === '') {
        return $fallback;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        $second = isset($m[3]) ? (int)$m[3] : 0;
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59) {
            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }
    }

    return $fallback;
}

function calendar_has_time_range(string $start_time, string $end_time): bool
{
    return $start_time !== '00:00:00' && $end_time !== '00:00:00' && $end_time > $start_time;
}

function calendar_normalize_status($status): string
{
    $value = strtolower(trim((string)$status));
    $map = [
        'pending' => 'pending',
        'reviewed' => 'approved',
        'approved' => 'approved',
        'verified' => 'approved',
        'in_progress' => 'approved',
        'in progress' => 'approved',
        'completed' => 'completed',
        'complete' => 'completed',
        'done' => 'completed',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'rejected' => 'cancelled',
    ];

    return $map[$value] ?? 'pending';
}

function calendar_normalize_event_type($event_type): string
{
    $key = strtolower(trim((string)$event_type));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
    $key = trim($key, '_');
    return $key ?: 'other';
}

function calendar_event_type_id(PDO $pdo, string $event_type): int
{
    $event_type = calendar_normalize_event_type($event_type);

    $stmt = $pdo->prepare("SELECT id FROM staff_event_types WHERE type_key = ? LIMIT 1");
    $stmt->execute([$event_type]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $label = ucwords(str_replace('_', ' ', $event_type));
    $insert = $pdo->prepare("INSERT INTO staff_event_types (type_key, type_name, icon_class) VALUES (?, ?, ?)");
    $insert->execute([$event_type, $label, 'fas fa-calendar']);
    return (int)$pdo->lastInsertId();
}

function calendar_event_date_key(array $event, string $fallback): string
{
    foreach (['event_date', 'scheduled_date', 'deadline_date', 'transaction_date', 'meeting_date', 'delivery_date'] as $key) {
        if (!empty($event[$key])) {
            return substr((string)$event[$key], 0, 10);
        }
    }

    return $fallback;
}

function calendar_add_event(array &$month_events, array $event, string $fallback_date): void
{
    $date = calendar_event_date_key($event, $fallback_date);
    $event['event_date'] = $event['event_date'] ?? $date;
    $month_events[$date][] = $event;
}

/**
 * Fetch all comprehensive operational events across the station/system for calendar display.
 * Includes: Staff Calendar Events, Job Orders, Retail Merchandise Sales, Fuel Transactions,
 * Fuel Sales Closings, Approval Requests (Void/Adj), Deliveries, Calibrations, Labor Sessions, & Low Stock.
 */
function calendar_fetch_all_station_events(PDO $pdo, int $station_id, string $view_start, string $view_end, int $current_user_id = 0, string $role = 'manager'): array
{
    $events = [];
    $seen = [];

    $add = function($date, $item) use (&$events, &$seen) {
        if (empty($date)) return;
        $d = substr((string)$date, 0, 10);
        $k = ($item['type_key'] ?? '') . '_' . ($item['id'] ?? '') . '_' . $d;
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $item['event_date'] = $d;
        $events[$d][] = $item;
    };

    $st_p = $station_id > 0 ? [$station_id] : [];

    // 1. Staff Calendar Events (Manual/Custom Events: Meetings, Trainings, Maintenance, Inspections, etc.)
    try {
        $q = "SELECT sce.*, et.type_name, et.type_key, et.icon_class, su.name AS staff_name,
                     st.name AS station_name
              FROM staff_calendar_events sce
              JOIN staff_event_types et ON sce.event_type_id = et.id
              JOIN users su ON sce.staff_encoder_id = su.id
              LEFT JOIN stations st ON sce.station_id = st.id
              WHERE sce.event_date BETWEEN ? AND ? " . ($station_id > 0 ? "AND sce.station_id = ?" : "") . "
              ORDER BY sce.event_date, sce.start_time";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['auto_synced'] = false;
            $add($r['event_date'], $r);
        }
    } catch (Exception $e) {}

    // 2. Job Orders from merchandise_transactions (Transactions with services, vehicle plates, mechanics)
    try {
        $q = "SELECT mt.id, mt.transaction_id, DATE(mt.transaction_date) as event_date, TIME(mt.transaction_date) as start_time,
                     mt.job_order_service, mt.job_order_vehicle_plate, mt.job_order_mechanic_name,
                     mt.customer_name, mt.total_amount, mt.validation_status, mt.workflow_status,
                     u.name as staff_name, mt.staff_id, st.name as station_name
              FROM merchandise_transactions mt
              LEFT JOIN users u ON mt.staff_id = u.id
              LEFT JOIN stations st ON mt.station_id = st.id
              WHERE DATE(mt.transaction_date) BETWEEN ? AND ?
                AND (mt.transaction_type IN ('job_order', 'combined') OR (mt.job_order_service IS NOT NULL AND mt.job_order_service != ''))
                " . ($station_id > 0 ? "AND mt.station_id = ?" : "") . "
              ORDER BY mt.transaction_date DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['validation_status'] ?: ($r['workflow_status'] ?: 'pending'));
            $color = ($st === 'official' || $st === 'completed') ? '#10b981' : ($st === 'adjusted' ? '#6366f1' : ($st === 'voided' ? '#ef4444' : '#3b82f6'));
            $service = $r['job_order_service'] ?: 'Job Order Service';
            $plate = !empty($r['job_order_vehicle_plate']) ? " [{$r['job_order_vehicle_plate']}]" : '';
            $add($r['event_date'], [
                'id' => 'jo_tx_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Job Order',
                'type_key' => 'job_order',
                'icon_class' => 'fas fa-wrench',
                'staff_name' => $r['job_order_mechanic_name'] ?: ($r['staff_name'] ?: 'Mechanic'),
                'staff_encoder_id' => $r['staff_id'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "JO: {$service}{$plate} — " . ($r['customer_name'] ?: 'Customer') . " (₱" . number_format((float)$r['total_amount'], 2) . ")",
                'status' => $st,
                'color' => $color,
                'start_time' => $r['start_time'] ?? '08:00:00',
                'total_amount' => $r['total_amount'],
                'customer_name' => $r['customer_name'],
                'service_type' => $r['job_order_service'],
                'vehicle_plate' => $r['job_order_vehicle_plate'],
                'target_url' => "manager_validated_transactions.php?type=job_order&search=" . urlencode($r['id']),
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 3. Standalone job_orders table (if populated in the future)
    try {
        $q = "SELECT jo.id, jo.created_by, DATE(jo.created_at) AS event_date, jo.due_date,
                     jo.service_type, jo.status, u.name AS staff_name, jo.customer_name,
                     jo.vehicle_plate, jo.total_cost, st.name as station_name
              FROM job_orders jo
              JOIN users u ON jo.created_by = u.id
              LEFT JOIN stations st ON jo.station_id = st.id
              WHERE (DATE(jo.created_at) BETWEEN ? AND ? OR jo.due_date BETWEEN ? AND ?)
                " . ($station_id > 0 ? "AND jo.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end, $view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $edate = $r['due_date'] ?: $r['event_date'];
            $st = strtolower($r['status'] ?? 'pending');
            $color = ($st === 'completed' || $st === 'verified') ? '#10b981' : '#3b82f6';
            $add($edate, [
                'id' => 'jo_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Job Order',
                'type_key' => 'job_order',
                'icon_class' => 'fas fa-wrench',
                'staff_name' => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => 'JO #' . $r['id'] . ': ' . $r['service_type'] . ' — ' . ($r['customer_name'] ?: 'Customer'),
                'status' => $st,
                'color' => $color,
                'customer_name' => $r['customer_name'],
                'service_type' => $r['service_type'],
                'vehicle_plate' => $r['vehicle_plate'],
                'total_amount' => $r['total_cost'],
                'target_url' => "manager_validated_transactions.php?type=job_order&search=" . urlencode($r['id']),
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 4. Retail Merchandise Transactions
    try {
        $q = "SELECT mt.id, mt.transaction_id, DATE(mt.transaction_date) as event_date, TIME(mt.transaction_date) as start_time,
                     mt.item_sku, mt.customer_name, mt.total_amount, mt.validation_status, mt.workflow_status,
                     u.name as staff_name, mt.staff_id, mt.payment_method, st.name as station_name
              FROM merchandise_transactions mt
              LEFT JOIN users u ON mt.staff_id = u.id
              LEFT JOIN stations st ON mt.station_id = st.id
              WHERE DATE(mt.transaction_date) BETWEEN ? AND ?
                AND (mt.transaction_type = 'merchandise' OR mt.job_order_service IS NULL OR mt.job_order_service = '')
                " . ($station_id > 0 ? "AND mt.station_id = ?" : "") . "
              ORDER BY mt.transaction_date DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['validation_status'] ?: ($r['workflow_status'] ?: 'completed'));
            $color = ($st === 'official' || $st === 'completed') ? '#0284c7' : '#94a3b8';
            $sku_label = !empty($r['item_sku']) ? " ({$r['item_sku']})" : '';
            $add($r['event_date'], [
                'id' => 'mt_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Merchandise Sale',
                'type_key' => 'merchandise_sale',
                'icon_class' => 'fas fa-shopping-bag',
                'staff_name' => $r['staff_name'] ?: 'Cashier',
                'staff_encoder_id' => $r['staff_id'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Merch Sale: ₱" . number_format((float)$r['total_amount'], 2) . " — " . ($r['customer_name'] ?: 'Walk-in') . $sku_label,
                'status' => $st,
                'color' => $color,
                'start_time' => $r['start_time'] ?? '09:00:00',
                'total_amount' => $r['total_amount'],
                'customer_name' => $r['customer_name'],
                'product' => $r['item_sku'],
                'target_url' => "manager_validated_transactions.php?type=merchandise&search=" . urlencode($r['id']),
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 5. Fuel Transactions (Pump Readings & Sales)
    try {
        $q = "SELECT ft.id, ft.transaction_id, DATE(ft.transaction_date) as event_date, TIME(ft.transaction_date) as start_time,
                     ft.fuel_type, ft.liters_sold, ft.total_amount, ft.status, u.name as staff_name, ft.staff_id,
                     st.name as station_name
              FROM fuel_transactions ft
              LEFT JOIN users u ON ft.staff_id = u.id
              LEFT JOIN stations st ON ft.station_id = st.id
              WHERE DATE(ft.transaction_date) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND ft.station_id = ?" : "") . "
              ORDER BY ft.transaction_date DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['status'] ?: 'completed');
            $add($r['event_date'], [
                'id' => 'ft_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Fuel Transaction',
                'type_key' => 'fuel_sale',
                'icon_class' => 'fas fa-gas-pump',
                'staff_name' => $r['staff_name'] ?: 'Pump Attendant',
                'staff_encoder_id' => $r['staff_id'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Fuel Sale: {$r['fuel_type']} (" . number_format((float)$r['liters_sold'], 2) . " L) — ₱" . number_format((float)$r['total_amount'], 2),
                'status' => $st,
                'color' => '#d97706',
                'start_time' => $r['start_time'] ?? '06:00:00',
                'total_amount' => $r['total_amount'],
                'fuel_type' => $r['fuel_type'],
                'liters' => $r['liters_sold'],
                'target_url' => "manager_fuel_sales.php",
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 6. Fuel Sales Closing (Shift & Daily Closing Reports)
    try {
        $q = "SELECT fsc.id, fsc.report_date as event_date, fsc.shift, fsc.total_fuel_sales, fsc.gross_sales,
                     fsc.total_liters, fsc.status, u.name as staff_name, st.name as station_name
              FROM fuel_sales_closing fsc
              LEFT JOIN users u ON fsc.encoded_by = u.id
              LEFT JOIN stations st ON fsc.station_id = st.id
              WHERE fsc.report_date BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND fsc.station_id = ?" : "") . "
              ORDER BY fsc.report_date DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['status'] ?: 'completed');
            $shift_str = !empty($r['shift']) ? " ({$r['shift']})" : '';
            $add($r['event_date'], [
                'id' => 'fsc_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Daily Sales Closing',
                'type_key' => 'sales_closing',
                'icon_class' => 'fas fa-file-invoice-dollar',
                'staff_name' => $r['staff_name'] ?: 'Closing Attendant',
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Daily Closing{$shift_str}: Gross ₱" . number_format((float)$r['gross_sales'], 2) . " (" . number_format((float)$r['total_liters'], 2) . " L)",
                'status' => $st,
                'color' => '#059669',
                'gross_sales' => $r['gross_sales'],
                'shift' => $r['shift'],
                'target_url' => "reports/fuel_sales_closing.php",
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 7. Transaction Requests (Void & Adjustment Requests)
    try {
        $q = "SELECT tr.id, DATE(tr.requested_at) AS event_date, TIME(tr.requested_at) as start_time,
                     tr.request_type, tr.transaction_id, tr.status, u.name AS staff_name,
                     tr.record_source, tr.request_reason, tr.correction_field, tr.requested_value, tr.remarks,
                     st.name as station_name
              FROM transaction_requests tr
              JOIN users u ON tr.requested_by = u.id
              LEFT JOIN stations st ON tr.station_id = st.id
              WHERE DATE(tr.requested_at) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND tr.station_id = ?" : "") . "
              ORDER BY tr.requested_at DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $req_type = ucfirst($r['request_type']);
            $st = strtolower($r['status'] ?: 'pending');
            $color = $st === 'approved' ? '#16a34a' : ($st === 'rejected' ? '#ef4444' : '#dc2626');
            $reason_str = !empty($r['request_reason']) ? " ({$r['request_reason']})" : '';
            $add($r['event_date'], [
                'id' => 'req_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => $req_type . ' Request',
                'type_key' => 'manager_approval',
                'icon_class' => 'fas fa-exclamation-triangle',
                'staff_name' => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "[{$req_type} Request] Txn #{$r['transaction_id']}{$reason_str} — " . ucfirst($st),
                'status' => $st,
                'color' => $color,
                'start_time' => $r['start_time'] ?? '10:00:00',
                'priority' => $st === 'pending' ? 'urgent' : 'normal',
                'request_type' => $r['request_type'],
                'transaction_id' => $r['transaction_id'],
                'request_reason' => $r['request_reason'],
                'correction_field' => $r['correction_field'],
                'requested_value' => $r['requested_value'],
                'remarks' => $r['remarks'],
                'target_url' => "manager_validated_transactions.php",
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 8. Deliveries Oversight (Merchandise Deliveries)
    try {
        $q = "SELECT d.id, d.encoded_by, DATE(d.delivery_date) AS event_date, u.name AS staff_name,
                     d.status, d.supplier, d.product, d.expected_quantity, d.actual_quantity, st.name AS station_name
              FROM deliveries_oversight d
              JOIN users u ON d.encoded_by = u.id
              LEFT JOIN stations st ON d.station_id = st.id
              WHERE DATE(d.delivery_date) BETWEEN ? AND ? " . ($station_id > 0 ? "AND d.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['event_date'], [
                'id' => 'del_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Merchandise Delivery',
                'type_key' => 'merchandise_delivery',
                'icon_class' => 'fas fa-box',
                'staff_name' => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Delivery: {$r['supplier']} - {$r['product']}",
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => '#06b6d4',
                'supplier' => $r['supplier'],
                'product' => $r['product'],
                'expected_qty' => $r['expected_quantity'],
                'actual_qty' => $r['actual_quantity'],
                'target_url' => "manager_deliveries.php?id=" . $r['id'],
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 9. Fuel Deliveries
    try {
        $q = "SELECT fd.id, DATE(fd.delivery_date) AS event_date, fd.fuel_type, fd.delivery_liters, fd.status,
                     fd.supplier, st.name AS station_name
              FROM fuel_deliveries fd
              LEFT JOIN stations st ON fd.station_id = st.id
              WHERE fd.delivery_date BETWEEN ? AND ? " . ($station_id > 0 ? "AND fd.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['event_date'], [
                'id' => 'fuel_del_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Fuel Delivery',
                'type_key' => 'fuel_delivery',
                'icon_class' => 'fas fa-truck-droplet',
                'staff_name' => $r['supplier'] ?: 'Fuel Supplier',
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Fuel Delivery: {$r['fuel_type']} (" . number_format((float)($r['delivery_liters'] ?? 0), 2) . " L)",
                'status' => strtolower($r['status'] ?? 'pending'),
                'color' => '#ef4444',
                'supplier' => $r['supplier'],
                'product' => $r['fuel_type'],
                'liters' => $r['delivery_liters'],
                'target_url' => "manager_fuel_delivery.php?id=" . $r['id'],
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 10. Fuel Calibration Records
    try {
        $q = "SELECT fcr.id, DATE(fcr.calibration_date) AS event_date, fcr.fuel_type, fcr.calibration_liters,
                     fcr.pump_number, fcr.status, u.name AS staff_name, st.name AS station_name
              FROM fuel_calibration_records fcr
              LEFT JOIN users u ON fcr.staff_id = u.id
              LEFT JOIN stations st ON fcr.station_id = st.id
              WHERE DATE(fcr.calibration_date) BETWEEN ? AND ? " . ($station_id > 0 ? "AND fcr.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['event_date'], [
                'id' => 'calib_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Fuel Calibration',
                'type_key' => 'fuel_calibration',
                'icon_class' => 'fas fa-tachometer-alt',
                'staff_name' => $r['staff_name'] ?: 'Technician',
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Calibration: Pump {$r['pump_number']} - {$r['fuel_type']} (" . number_format((float)$r['calibration_liters'], 2) . " L)",
                'status' => strtolower($r['status'] ?? 'active'),
                'color' => '#64748b',
                'pump_number' => $r['pump_number'],
                'fuel_type' => $r['fuel_type'],
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 11. Labor Sessions (Mechanic & Staff Labor Hours)
    try {
        $q = "SELECT ls.id, DATE(ls.start_time) AS event_date, TIME(ls.start_time) as start_time,
                     TIME(ls.end_time) as end_time, ls.hours_worked, ls.shift_period, ls.shift_name,
                     u.name AS staff_name, st.name AS station_name
              FROM labor_sessions ls
              JOIN users u ON ls.user_id = u.id
              LEFT JOIN stations st ON ls.station_id = st.id
              WHERE DATE(ls.start_time) BETWEEN ? AND ? " . ($station_id > 0 ? "AND ls.station_id = ?" : "") . "
              ORDER BY ls.start_time DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($r['event_date'], [
                'id' => 'labor_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Labor Session',
                'type_key' => 'labor_session',
                'icon_class' => 'fas fa-user-clock',
                'staff_name' => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'work_description' => "Labor: {$r['staff_name']} (" . number_format((float)$r['hours_worked'], 2) . " hrs)",
                'status' => 'completed',
                'color' => '#7c3aed',
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time'],
                'hours_worked' => $r['hours_worked'],
                'auto_synced' => true
            ]);
        }
    } catch (Exception $e) {}

    // 12. Low Inventory Restock Reminders (Current day alert)
    try {
        $today = date('Y-m-d');
        if ($today >= $view_start && $today <= $view_end) {
            $low_stock = $pdo->prepare("
                SELECT ip.id, ip.product_name, 
                       COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
                       COALESCE(si.reorder_level, ip.min_stock, 10) AS minimum_stock,
                       COALESCE(si.unit, ip.size, 'pcs') AS unit
                FROM inventory_products ip
                LEFT JOIN station_inventory si ON si.product_id = ip.id " . ($station_id > 0 ? "AND si.station_id = ?" : "") . "
                WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
                  AND ip.status = 'Active'
                  AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, ip.min_stock, 10)
                LIMIT 5
            ");
            $low_stock->execute($station_id > 0 ? [$station_id] : []);
            foreach ($low_stock->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $add($today, [
                    'id' => 'restock_' . $r['id'],
                    'numeric_id' => $r['id'],
                    'type_name' => 'Low Stock Alert',
                    'type_key' => 'restock_reminder',
                    'icon_class' => 'fas fa-bell',
                    'staff_name' => 'System Alert',
                    'work_description' => "Low Stock: {$r['product_name']} ({$r['current_stock']} {$r['unit']} left)",
                    'status' => 'pending',
                    'color' => '#ef4444',
                    'target_url' => "manager_inventory_overview.php",
                    'auto_synced' => true
                ]);
            }
        }
    } catch (Exception $e) {}

    return $events;
}


