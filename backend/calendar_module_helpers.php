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
 * Fetch strictly staff-relevant operational events:
 * 1. Shift Schedule (Shift 1: 6am-2pm, Shift 2: 2pm-12mn)
 * 2. Job Order / Service Schedule (JO #, Customer, Vehicle, Service, Assigned mechanic)
 * 3. Delivery / Receiving Schedule (PO/Delivery ref, Expected date/time, Fuel/Merchandise, Receiving status)
 * 4. Fuel Closing / Shift Turnover (Shift 1 closing 2pm, Shift 2 closing 12mn, Submission status, Gross sales)
 * 5. Other Operational Events (Station Maintenance, Fuel Calibrations, Inspections, Meetings)
 *
 * Strict Exclusions:
 * - NO Attendance / Labor hours (labor_sessions)
 * - NO Individual fuel pump transactions (fuel_transactions)
 * - NO Individual retail merchandise sales (merchandise_transactions without JO)
 * - NO Manager approval requests (transaction_requests)
 * - NO Low inventory alerts
 */
function calendar_fetch_staff_station_events(PDO $pdo, int $station_id, string $view_start, string $view_end, int $current_user_id = 0): array
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

    // 1. Shift Schedule — event-driven only (shows on dates where a shift is actually recorded in staff_calendar_events)
    try {
        $q = "SELECT sce.id, sce.event_date, sce.start_time, sce.end_time,
                     sce.work_description, sce.status, sce.station_id,
                     COALESCE(et.type_name, 'Shift Schedule') AS type_name,
                     COALESCE(et.type_key, 'staff_shift')     AS type_key,
                     COALESCE(et.icon_class, 'fas fa-clock')  AS icon_class,
                     su.name AS staff_name,
                     st.name AS station_name
              FROM staff_calendar_events sce
              LEFT JOIN staff_event_types et ON sce.event_type_id = et.id
              LEFT JOIN users su ON sce.staff_encoder_id = su.id
              LEFT JOIN stations st ON sce.station_id = st.id
              WHERE sce.event_date BETWEEN ? AND ?
                AND (et.type_key = 'staff_shift' OR et.type_name LIKE '%Shift%')
                " . ($station_id > 0 ? "AND sce.station_id = ?" : "") . "
              ORDER BY sce.event_date, sce.start_time";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['type_key']       = 'staff_shift';
            $r['color']          = '#0284c7';
            $r['bg_color']       = '#eff6ff';
            $r['text_color']     = '#1e40af';
            $r['border_color']   = '#bfdbfe';
            $r['status_clean']   = 'Scheduled';
            $r['calendar_title'] = !empty($r['work_description']) ? $r['work_description'] : ($r['type_name'] ?? 'Shift Schedule');
            $r['target_url']     = '#';
            $r['auto_synced']    = false;
            $add($r['event_date'], $r);
        }
    } catch (Throwable $e) {}

    // 2. Job Order / Service Schedule (merchandise_transactions with services)
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
            $st_clean = ucwords(str_replace('_', ' ', $st));
            if ($st === 'official' || $st === 'completed') {
                $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0'; $color = '#10b981';
            } elseif ($st === 'adjusted') {
                $bg_color = '#eef2ff'; $text_color = '#3730a3'; $border_color = '#c7d2fe'; $color = '#6366f1';
            } elseif ($st === 'voided') {
                $bg_color = '#fef2f2'; $text_color = '#991b1b'; $border_color = '#fecaca'; $color = '#ef4444';
            } else {
                $bg_color = '#fffbeb'; $text_color = '#92400e'; $border_color = '#fde68a'; $color = '#f59e0b';
            }

            $service = $r['job_order_service'] ?: 'Service';
            $plate = !empty($r['job_order_vehicle_plate']) ? " [{$r['job_order_vehicle_plate']}]" : '';
            $cust = $r['customer_name'] ?: 'Customer';
            $mech = !empty($r['job_order_mechanic_name']) ? " (Mech: {$r['job_order_mechanic_name']})" : '';

            // Concise JO reference for clean display without giant timestamp
            $jo_clean = (!empty($r['transaction_id']) && strpos($r['transaction_id'], 'MERCH') === false)
                ? $r['transaction_id']
                : ('JO #' . $r['id']);
            $cal_title = "{$jo_clean}: {$service}{$plate}";

            $add($r['event_date'], [
                'id' => 'jo_tx_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Job Order',
                'type_key' => 'job_order',
                'icon_class' => 'fas fa-wrench',
                'staff_name' => $r['job_order_mechanic_name'] ?: ($r['staff_name'] ?: 'Mechanic'),
                'staff_encoder_id' => $r['staff_id'],
                'station_name' => $r['station_name'] ?? '',
                'jo_number' => $jo_clean,
                'customer_name' => $cust,
                'vehicle_plate' => $r['job_order_vehicle_plate'],
                'service_type' => $service,
                'mechanic_name' => $r['job_order_mechanic_name'] ?? '',
                'total_amount' => $r['total_amount'],
                'start_time' => $r['start_time'] ?? '08:00:00',
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => $color,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'work_description' => "{$jo_clean}: {$service}{$plate} — {$cust}{$mech}",
                'target_url' => 'staff_transactions_hub.php?tab=job_orders',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

    // Standalone job_orders table (if populated)
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
            $st_clean = ucwords(str_replace('_', ' ', $st));
            $color = ($st === 'completed' || $st === 'verified') ? '#10b981' : '#f59e0b';
            $bg_color = ($st === 'completed' || $st === 'verified') ? '#ecfdf5' : '#fffbeb';
            $text_color = ($st === 'completed' || $st === 'verified') ? '#065f46' : '#92400e';
            $border_color = ($st === 'completed' || $st === 'verified') ? '#a7f3d0' : '#fde68a';
            $cust = $r['customer_name'] ?: 'Customer';
            $plate = !empty($r['vehicle_plate']) ? " [{$r['vehicle_plate']}]" : '';
            $jo_clean = 'JO #' . $r['id'];
            $cal_title = "{$jo_clean}: {$r['service_type']}{$plate}";

            $add($edate, [
                'id' => 'jo_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Job Order',
                'type_key' => 'job_order',
                'icon_class' => 'fas fa-wrench',
                'staff_name' => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'jo_number' => $jo_clean,
                'customer_name' => $cust,
                'vehicle_plate' => $r['vehicle_plate'],
                'service_type' => $r['service_type'],
                'total_amount' => $r['total_cost'],
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => $color,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'work_description' => "{$jo_clean}: {$r['service_type']}{$plate} — {$cust}",
                'target_url' => 'staff_transactions_hub.php?tab=job_orders',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

    // 3. Delivery / Receiving Schedule (Merchandise & Fuel Deliveries)
    try {
        $q = "SELECT d.id, d.encoded_by, DATE(d.delivery_date) AS event_date, d.delivery_time, u.name AS staff_name,
                     d.status, d.supplier, d.product, d.expected_quantity, d.actual_quantity, d.unit,
                     d.dr_number, d.delivery_ref, d.sales_invoice_no, d.delivery_type, st.name AS station_name
              FROM deliveries_oversight d
              LEFT JOIN users u ON d.encoded_by = u.id
              LEFT JOIN stations st ON d.station_id = st.id
              WHERE DATE(d.delivery_date) BETWEEN ? AND ? " . ($station_id > 0 ? "AND d.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st));
            if ($st === 'stock-in complete' || $st === 'verified' || $st === 'completed') {
                $bg_color = '#f0fdfa'; $text_color = '#115e59'; $border_color = '#99f6e4'; $color = '#10b981';
            } else {
                $bg_color = '#f0f9ff'; $text_color = '#075985'; $border_color = '#bae6fd'; $color = '#0891b2';
            }
            $po_ref = !empty($r['dr_number']) ? $r['dr_number'] : (!empty($r['delivery_ref']) ? $r['delivery_ref'] : (!empty($r['sales_invoice_no']) ? $r['sales_invoice_no'] : ('DEL-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT))));
            $supp = $r['supplier'] ?: 'Petron';
            $prod = $r['product'] ?: 'Merchandise';
            $cal_title = "Delivery: {$prod}";

            $add($r['event_date'], [
                'id' => 'del_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Merchandise Delivery',
                'type_key' => 'merchandise_delivery',
                'icon_class' => 'fas fa-box',
                'category_label' => (!empty($r['delivery_type']) ? ucfirst($r['delivery_type']) : 'Merchandise'),
                'staff_name' => $r['staff_name'] ?: 'Staff Receiver',
                'station_name' => $r['station_name'] ?? '',
                'delivery_ref' => $po_ref,
                'po_reference' => $po_ref,
                'supplier' => $supp,
                'product' => $prod,
                'expected_qty' => $r['expected_quantity'],
                'actual_qty' => $r['actual_quantity'],
                'unit' => $r['unit'] ?? 'units',
                'receiving_status' => $st_clean,
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => $color,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'start_time' => (!empty($r['delivery_time']) && $r['delivery_time'] !== '00:00:00') ? $r['delivery_time'] : '09:00:00',
                'work_description' => "Delivery: {$supp} - {$prod} [{$st_clean}]",
                'target_url' => 'staff_deliveries_report.php',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

    try {
        $q = "SELECT fd.id, DATE(fd.delivery_date) AS event_date, fd.fuel_type, fd.delivery_liters, fd.status,
                     fd.supplier, fd.invoice_no, st.name AS station_name
              FROM fuel_deliveries fd
              LEFT JOIN stations st ON fd.station_id = st.id
              WHERE fd.delivery_date BETWEEN ? AND ? " . ($station_id > 0 ? "AND fd.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st));
            if ($st === 'verified' || $st === 'completed') {
                $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0'; $color = '#10b981';
            } else {
                $bg_color = '#fff7ed'; $text_color = '#9a3412'; $border_color = '#fed7aa'; $color = '#ea580c';
            }
            $po_ref = !empty($r['invoice_no']) ? $r['invoice_no'] : ('FDEL-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT));
            $supp = $r['supplier'] ?: 'Petron Corporation';
            $liters_str = number_format((float)($r['delivery_liters'] ?? 0), 0) . ' L';
            $cal_title = "Fuel Delivery: {$r['fuel_type']} ({$liters_str})";

            $add($r['event_date'], [
                'id' => 'fuel_del_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Fuel Delivery',
                'type_key' => 'fuel_delivery',
                'category_label' => 'Fuel',
                'icon_class' => 'fas fa-truck-droplet',
                'staff_name' => $supp,
                'station_name' => $r['station_name'] ?? '',
                'delivery_ref' => $po_ref,
                'po_reference' => $po_ref,
                'supplier' => $supp,
                'product' => $r['fuel_type'],
                'fuel_type' => $r['fuel_type'],
                'liters' => $r['delivery_liters'],
                'receiving_status' => $st_clean,
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => $color,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'start_time' => '10:00:00',
                'work_description' => "Fuel Delivery: {$supp} - {$r['fuel_type']} ({$liters_str}) [{$st_clean}]",
                'target_url' => 'staff_deliveries_report.php',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

    // 4. Fuel Closing / Shift Turnover (Daily shift closing reports)
    try {
        $q = "SELECT fsc.id, fsc.report_date as event_date, fsc.shift, fsc.shift_period, fsc.total_fuel_sales, fsc.gross_sales,
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
            $shift_label = !empty($r['shift']) ? $r['shift'] : (($r['shift_period'] ?? '') === 'first' ? 'Shift 1' : 'Shift 2');
            $closing_time = (stripos($shift_label, '1') !== false) ? '2:00 PM' : '12:00 MN';
            $st = strtolower($r['status'] ?? 'readings_submitted');
            $st_clean = ucwords(str_replace('_', ' ', $st));
            if ($st === 'closing_completed' || $st === 'verified' || $st === 'official') {
                $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0'; $color = '#059669';
            } else {
                $bg_color = '#f0fdfa'; $text_color = '#0f766e'; $border_color = '#99f6e4'; $color = '#0d9488';
            }
            $sales_num = (float)($r['gross_sales'] ?? $r['total_fuel_sales'] ?? 0);
            $sales_str = $sales_num > 0 ? " (₱" . number_format($sales_num, 2) . ")" : "";
            $cal_title = "{$shift_label} Closing";

            $add($r['event_date'], [
                'id' => 'fsc_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Fuel Closing / Shift Turnover',
                'type_key' => 'sales_closing',
                'icon_class' => 'fas fa-file-invoice-dollar',
                'staff_name' => $r['staff_name'] ?: 'Closing Attendant',
                'station_name' => $r['station_name'] ?? '',
                'shift' => $shift_label,
                'shift_name' => $shift_label,
                'closing_time' => $closing_time,
                'submission_status' => $st_clean,
                'gross_sales' => $sales_num,
                'total_liters' => $r['total_liters'],
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => $color,
                'bg_color' => $bg_color,
                'text_color' => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'start_time' => (stripos($shift_label, '1') !== false) ? '14:00:00' : '23:59:00',
                'work_description' => "Fuel Closing ({$shift_label} — {$closing_time}): {$st_clean}{$sales_str}",
                'target_url' => 'staff_fuel_sales_summary.php',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

    // 5. Other Operational Events (Fuel Calibrations & Station Operational Schedules)
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
            $st = strtolower($r['status'] ?? 'completed');
            $st_clean = ucwords(str_replace('_', ' ', $st));
            $cal_title = "Calibration: Pump {$r['pump_number']} (" . ($r['fuel_type'] ?? 'Fuel') . ")";

            $add($r['event_date'], [
                'id' => 'calib_' . $r['id'],
                'numeric_id' => $r['id'],
                'type_name' => 'Pump Calibration',
                'type_key' => 'fuel_calibration',
                'icon_class' => 'fas fa-tachometer-alt',
                'staff_name' => $r['staff_name'] ?: 'Technician',
                'station_name' => $r['station_name'] ?? '',
                'pump_number' => $r['pump_number'],
                'fuel_type' => $r['fuel_type'],
                'calibration_liters' => $r['calibration_liters'],
                'status' => $st,
                'status_clean' => $st_clean,
                'color' => '#64748b',
                'bg_color' => '#f8fafc',
                'text_color' => '#334155',
                'border_color' => '#cbd5e1',
                'calendar_title' => $cal_title,
                'start_time' => '07:30:00',
                'work_description' => "Calibration: Pump {$r['pump_number']} - {$r['fuel_type']} (" . number_format((float)$r['calibration_liters'], 2) . " L)",
                'target_url' => 'staff_transactions_hub.php?tab=meter_readings',
                'auto_synced' => true
            ]);
        }
    } catch (Throwable $e) {}

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
            $r['status_clean'] = ucwords(str_replace('_', ' ', $r['status'] ?? 'active'));
            $r['calendar_title'] = !empty($r['work_description']) ? $r['work_description'] : ($r['type_name'] ?? 'Operational Event');
            $r['bg_color'] = '#f8fafc';
            $r['text_color'] = '#334155';
            $r['border_color'] = '#cbd5e1';
            $r['auto_synced'] = false;
            $add($r['event_date'], $r);
        }
    } catch (Throwable $e) {}

    return $events;
}

/**
 * Fetch manager-relevant operational events for the calendar.
 *
 * Manager Calendar events (event-driven only):
 *   1. Fuel Price Effectivity   — fuel_pricing.effective_date in range
 *   2. Delivery Schedule        — purchase_orders.expected_delivery_date in range
 *   3. Stock-In / Delivery Validation — deliveries_oversight.delivery_date + fuel_deliveries.delivery_date
 *   4. Job Order / Service Schedule   — job_orders.created_at / merchandise_transactions with job service
 *   5. Pending Approval Schedule      — transaction_requests.requested_at
 *   6. Fuel / Shift Report Review     — fuel_sales_closing.report_date
 *   7. Fuel Calibration Events        — fuel_calibration_records.calibration_date
 *   8. Other Scheduled Activities     — staff_calendar_events (manual/custom events)
 *
 * Excluded (NOT shown):
 *   ❌ Individual fuel pump transactions (fuel_transactions)
 *   ❌ Retail merchandise sales without JO
 *   ❌ Labor/attendance sessions (labor_sessions)
 *   ❌ Auto low-stock alerts (no scheduled source)
 *   ❌ Super Admin technical activities
 */
function calendar_fetch_all_station_events(PDO $pdo, int $station_id, string $view_start, string $view_end, int $current_user_id = 0, string $role = 'manager'): array
{
    if (strtolower($role) === 'staff') {
        return calendar_fetch_staff_station_events($pdo, $station_id, $view_start, $view_end, $current_user_id);
    }
    $events = [];
    $seen   = [];

    $add = function(string $date, array $item) use (&$events, &$seen): void {
        if ($date === '') return;
        $d = substr($date, 0, 10);
        $k = ($item['type_key'] ?? '') . '_' . ($item['id'] ?? '') . '_' . $d;
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $item['event_date'] = $d;
        $events[$d][] = $item;
    };

    $st_p = $station_id > 0 ? [$station_id] : [];

    // ─── 1. Fuel Price Effectivity ──────────────────────────────────────────
    // Shows on the day the new fuel price actually takes effect.
    try {
        $q = "SELECT fp.id, DATE(fp.effective_date) AS event_date, TIME(fp.effective_date) AS start_time,
                     fp.price_per_liter, fp.fuel_type_id,
                     COALESCE(ft.fuel_type, ft.type_name, CONCAT('Fuel #', fp.fuel_type_id)) AS fuel_label,
                     u.name AS created_by_name
              FROM fuel_pricing fp
              LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
              LEFT JOIN users u ON fp.created_by = u.id
              WHERE DATE(fp.effective_date) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND fp.station_id = ?" : "") . "
              ORDER BY fp.effective_date ASC";
        // fuel_types column name detection
        try { $ft_col = $pdo->query("SHOW COLUMNS FROM fuel_types LIKE 'fuel_type'")->fetchColumn(); } catch (Throwable $ex) { $ft_col = false; }
        $q_safe = $ft_col
            ? $q
            : str_replace('ft.fuel_type, ft.type_name', 'ft.type_name, ft.type_name', $q);
        $stmt = $pdo->prepare($q_safe);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $label   = $r['fuel_label'] ?: 'Fuel';
            $price   = '₱' . number_format((float)$r['price_per_liter'], 2) . '/L';
            $cal_title = "Price: {$label} – {$price}";
            $add($r['event_date'], [
                'id'          => 'fp_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Fuel Price Effectivity',
                'type_key'    => 'fuel_price',
                'icon_class'  => 'fas fa-tag',
                'staff_name'  => $r['created_by_name'] ?: 'System',
                'fuel_label'  => $label,
                'price_per_liter' => $r['price_per_liter'],
                'status'      => 'approved',
                'status_clean' => 'Effective',
                'color'       => '#7c3aed',
                'bg_color'    => '#f5f3ff',
                'text_color'  => '#4c1d95',
                'border_color' => '#ddd6fe',
                'calendar_title' => $cal_title,
                'work_description' => "Fuel Price Effectivity: {$label} at {$price}",
                'start_time'  => (!empty($r['start_time']) && $r['start_time'] !== '00:00:00') ? $r['start_time'] : '08:00:00',
                'target_url'  => 'manager_set_prices.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 2. Purchase Order / Delivery Schedule ──────────────────────────────
    // Shows on expected_delivery_date so Manager knows when a PO delivery is due.
    try {
        $q = "SELECT po.id, po.po_number, po.product_name, po.status,
                     COALESCE(po.expected_delivery_date, po.expected_delivery) AS event_date,
                     po.quantity, po.unit_price, po.total_amount, po.type,
                     su.company_name AS supplier_name_co,
                     po.supplier_name AS supplier_name_raw,
                     u.name AS created_by_name
              FROM purchase_orders po
              LEFT JOIN suppliers su ON po.supplier_id = su.id
              LEFT JOIN users u ON po.created_by = u.id
              WHERE COALESCE(po.expected_delivery_date, po.expected_delivery) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND po.station_id = ?" : "") . "
              ORDER BY COALESCE(po.expected_delivery_date, po.expected_delivery) ASC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ptype   = ucfirst($r['type'] ?? 'Merchandise');
            $po_ref  = $r['po_number'] ?: ('PO-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT));
            $supp    = $r['supplier_name_co'] ?: ($r['supplier_name_raw'] ?: 'Supplier');
            $prod    = $r['product_name'] ?: 'Product';
            $st_po   = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st_po));
            if (in_array($st_po, ['received', 'completed', 'admin finalized', 'official'])) {
                $color = '#10b981'; $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0';
            } elseif (in_array($st_po, ['rejected', 'rejected by admin', 'cancelled'])) {
                $color = '#ef4444'; $bg_color = '#fef2f2'; $text_color = '#991b1b'; $border_color = '#fecaca';
            } else {
                $color = '#06b6d4'; $bg_color = '#ecfeff'; $text_color = '#0e7490'; $border_color = '#a5f3fc';
            }
            $cal_title = "{$po_ref} – {$prod}";
            $add((string)$r['event_date'], [
                'id'          => 'po_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Delivery Schedule',
                'type_key'    => 'merchandise_delivery',
                'icon_class'  => 'fas fa-box',
                'staff_name'  => $supp,
                'delivery_ref' => $po_ref,
                'po_reference' => $po_ref,
                'supplier'    => $supp,
                'product'     => $prod,
                'expected_qty' => $r['quantity'],
                'category_label' => $ptype,
                'status'      => $st_po,
                'status_clean' => $st_clean,
                'receiving_status' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => $cal_title,
                'work_description' => "Delivery: {$po_ref} – {$prod} ({$supp}) [{$st_clean}]",
                'start_time'  => '09:00:00',
                'target_url'  => 'manager_merchandise_deliveries.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 3. Stock-In / Merchandise Delivery Validation ─────────────────────
    // deliveries_oversight = physical receiving record (per delivery_date)
    try {
        $q = "SELECT d.id, DATE(d.delivery_date) AS event_date, d.delivery_time,
                     d.status, d.supplier, d.product, d.expected_quantity, d.actual_quantity,
                     d.unit, d.dr_number, d.delivery_ref, d.sales_invoice_no,
                     u.name AS staff_name, st.name AS station_name
              FROM deliveries_oversight d
              LEFT JOIN users u ON d.encoded_by = u.id
              LEFT JOIN stations st ON d.station_id = st.id
              WHERE DATE(d.delivery_date) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND d.station_id = ?" : "") . "
              ORDER BY d.delivery_date ASC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_d = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st_d));
            if (in_array($st_d, ['stock-in complete', 'verified', 'completed', 'finalized'])) {
                $color = '#10b981'; $bg_color = '#f0fdfa'; $text_color = '#115e59'; $border_color = '#99f6e4';
            } else {
                $color = '#0891b2'; $bg_color = '#f0f9ff'; $text_color = '#075985'; $border_color = '#bae6fd';
            }
            $po_ref = $r['dr_number'] ?: ($r['delivery_ref'] ?: ($r['sales_invoice_no'] ?: ('DEL-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT))));
            $prod   = $r['product'] ?: 'Merchandise';
            $supp   = $r['supplier'] ?: 'Supplier';
            $add($r['event_date'], [
                'id'          => 'del_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Delivery Validation',
                'type_key'    => 'merchandise_delivery',
                'icon_class'  => 'fas fa-box',
                'staff_name'  => $r['staff_name'] ?: 'Receiver',
                'station_name' => $r['station_name'] ?? '',
                'delivery_ref' => $po_ref,
                'po_reference' => $po_ref,
                'supplier'    => $supp,
                'product'     => $prod,
                'expected_qty' => $r['expected_quantity'],
                'actual_qty'  => $r['actual_quantity'],
                'unit'        => $r['unit'] ?? 'units',
                'receiving_status' => $st_clean,
                'status'      => $st_d,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "Delivery: {$prod}",
                'work_description' => "Delivery: {$supp} – {$prod} [{$st_clean}]",
                'start_time'  => (!empty($r['delivery_time']) && $r['delivery_time'] !== '00:00:00') ? $r['delivery_time'] : '09:00:00',
                'target_url'  => 'manager_merchandise_deliveries.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // Fuel deliveries (delivery_date)
    try {
        $q = "SELECT fd.id, DATE(fd.delivery_date) AS event_date, fd.fuel_type,
                     fd.delivery_liters, fd.status, fd.supplier, fd.invoice_no,
                     st.name AS station_name
              FROM fuel_deliveries fd
              LEFT JOIN stations st ON fd.station_id = st.id
              WHERE fd.delivery_date BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND fd.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_d = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st_d));
            if (in_array($st_d, ['verified', 'completed'])) {
                $color = '#10b981'; $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0';
            } else {
                $color = '#ea580c'; $bg_color = '#fff7ed'; $text_color = '#9a3412'; $border_color = '#fed7aa';
            }
            $po_ref   = $r['invoice_no'] ?: ('FDEL-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT));
            $supp     = $r['supplier'] ?: 'Petron Corporation';
            $liters_s = number_format((float)($r['delivery_liters'] ?? 0), 0) . ' L';
            $add($r['event_date'], [
                'id'          => 'fuel_del_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Fuel Delivery',
                'type_key'    => 'fuel_delivery',
                'icon_class'  => 'fas fa-truck-droplet',
                'staff_name'  => $supp,
                'station_name' => $r['station_name'] ?? '',
                'delivery_ref' => $po_ref,
                'po_reference' => $po_ref,
                'supplier'    => $supp,
                'product'     => $r['fuel_type'],
                'fuel_type'   => $r['fuel_type'],
                'liters'      => $r['delivery_liters'],
                'category_label' => 'Fuel',
                'receiving_status' => $st_clean,
                'status'      => $st_d,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "Fuel Delivery: {$r['fuel_type']} ({$liters_s})",
                'work_description' => "Fuel Delivery: {$supp} – {$r['fuel_type']} ({$liters_s}) [{$st_clean}]",
                'start_time'  => '10:00:00',
                'target_url'  => 'manager_fuel_delivery.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 4. Job Order / Service Schedule ───────────────────────────────────
    // From merchandise_transactions (job_order / combined type)
    try {
        $q = "SELECT mt.id, mt.transaction_id, DATE(mt.transaction_date) AS event_date,
                     TIME(mt.transaction_date) AS start_time,
                     mt.job_order_service, mt.job_order_vehicle_plate,
                     mt.job_order_mechanic_name, mt.customer_name,
                     mt.total_amount, mt.validation_status, mt.workflow_status,
                     u.name AS staff_name, mt.staff_id, st.name AS station_name
              FROM merchandise_transactions mt
              LEFT JOIN users u ON mt.staff_id = u.id
              LEFT JOIN stations st ON mt.station_id = st.id
              WHERE DATE(mt.transaction_date) BETWEEN ? AND ?
                AND (mt.transaction_type IN ('job_order','combined')
                     OR (mt.job_order_service IS NOT NULL AND mt.job_order_service != ''))
                " . ($station_id > 0 ? "AND mt.station_id = ?" : "") . "
              ORDER BY mt.transaction_date DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_jo = strtolower($r['validation_status'] ?: ($r['workflow_status'] ?: 'pending'));
            $st_clean = ucwords(str_replace('_', ' ', $st_jo));
            if (in_array($st_jo, ['official','completed'])) {
                $color = '#10b981'; $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0';
            } elseif ($st_jo === 'adjusted') {
                $color = '#6366f1'; $bg_color = '#eef2ff'; $text_color = '#3730a3'; $border_color = '#c7d2fe';
            } elseif ($st_jo === 'voided') {
                $color = '#ef4444'; $bg_color = '#fef2f2'; $text_color = '#991b1b'; $border_color = '#fecaca';
            } else {
                $color = '#f59e0b'; $bg_color = '#fffbeb'; $text_color = '#92400e'; $border_color = '#fde68a';
            }
            $service = $r['job_order_service'] ?: 'Service';
            $plate   = !empty($r['job_order_vehicle_plate']) ? " [{$r['job_order_vehicle_plate']}]" : '';
            $cust    = $r['customer_name'] ?: 'Customer';
            $mech    = !empty($r['job_order_mechanic_name']) ? " (Mech: {$r['job_order_mechanic_name']})" : '';
            $jo_ref  = (!empty($r['transaction_id']) && strpos($r['transaction_id'], 'MERCH') === false)
                ? $r['transaction_id'] : ('JO #' . $r['id']);
            $add($r['event_date'], [
                'id'          => 'jo_tx_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Job Order',
                'type_key'    => 'job_order',
                'icon_class'  => 'fas fa-wrench',
                'staff_name'  => $r['job_order_mechanic_name'] ?: ($r['staff_name'] ?: 'Mechanic'),
                'staff_encoder_id' => $r['staff_id'],
                'station_name' => $r['station_name'] ?? '',
                'jo_number'   => $jo_ref,
                'customer_name' => $cust,
                'vehicle_plate' => $r['job_order_vehicle_plate'],
                'service_type' => $service,
                'mechanic_name' => $r['job_order_mechanic_name'] ?? '',
                'total_amount' => $r['total_amount'],
                'start_time'  => $r['start_time'] ?? '08:00:00',
                'status'      => $st_jo,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "{$jo_ref}: {$service}{$plate}",
                'work_description' => "{$jo_ref}: {$service}{$plate} — {$cust}{$mech}",
                'target_url'  => 'manager_validated_transactions.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // Standalone job_orders table
    try {
        $q = "SELECT jo.id, jo.job_order_number, DATE(jo.created_at) AS event_date,
                     jo.service_type, jo.status, jo.customer_name,
                     jo.vehicle_plate, jo.total_cost, jo.assigned_mechanic_id,
                     u.name AS staff_name, st.name AS station_name
              FROM job_orders jo
              LEFT JOIN users u ON jo.created_by = u.id
              LEFT JOIN stations st ON jo.station_id = st.id
              WHERE DATE(jo.created_at) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND jo.station_id = ?" : "") . "
              ORDER BY jo.created_at DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_jo = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st_jo));
            $color = in_array($st_jo, ['completed','verified']) ? '#10b981' : '#f59e0b';
            $bg_color = in_array($st_jo, ['completed','verified']) ? '#ecfdf5' : '#fffbeb';
            $text_color = in_array($st_jo, ['completed','verified']) ? '#065f46' : '#92400e';
            $border_color = in_array($st_jo, ['completed','verified']) ? '#a7f3d0' : '#fde68a';
            $cust  = $r['customer_name'] ?: 'Customer';
            $plate = !empty($r['vehicle_plate']) ? " [{$r['vehicle_plate']}]" : '';
            $jo_ref = !empty($r['job_order_number']) ? $r['job_order_number'] : ('JO #' . $r['id']);
            $add($r['event_date'], [
                'id'          => 'jo_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Job Order',
                'type_key'    => 'job_order',
                'icon_class'  => 'fas fa-wrench',
                'staff_name'  => $r['staff_name'] ?: 'Mechanic',
                'station_name' => $r['station_name'] ?? '',
                'jo_number'   => $jo_ref,
                'customer_name' => $cust,
                'vehicle_plate' => $r['vehicle_plate'],
                'service_type' => $r['service_type'],
                'total_amount' => $r['total_cost'],
                'status'      => $st_jo,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "{$jo_ref}: {$r['service_type']}{$plate}",
                'work_description' => "{$jo_ref}: {$r['service_type']}{$plate} — {$cust}",
                'target_url'  => 'manager_validated_transactions.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 5. Pending Approval Schedule (Void / Adjustment Requests) ─────────
    try {
        $q = "SELECT tr.id, DATE(tr.requested_at) AS event_date, TIME(tr.requested_at) AS start_time,
                     tr.request_type, tr.transaction_id, tr.status,
                     tr.request_reason, tr.correction_field, tr.requested_value, tr.remarks,
                     tr.record_source, u.name AS staff_name, st.name AS station_name
              FROM transaction_requests tr
              JOIN users u ON tr.requested_by = u.id
              LEFT JOIN stations st ON tr.station_id = st.id
              WHERE DATE(tr.requested_at) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND tr.station_id = ?" : "") . "
              ORDER BY tr.requested_at DESC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $req_type = ucfirst($r['request_type'] ?? 'Approval');
            $st_ap    = strtolower($r['status'] ?? 'pending');
            $st_clean = ucwords(str_replace('_', ' ', $st_ap));
            $color  = $st_ap === 'approved' ? '#16a34a' : ($st_ap === 'rejected' ? '#ef4444' : '#dc2626');
            $bg_color = $st_ap === 'approved' ? '#f0fdf4' : ($st_ap === 'rejected' ? '#fef2f2' : '#fff1f2');
            $text_color = $st_ap === 'approved' ? '#14532d' : ($st_ap === 'rejected' ? '#7f1d1d' : '#881337');
            $border_color = $st_ap === 'approved' ? '#bbf7d0' : ($st_ap === 'rejected' ? '#fecaca' : '#fecdd3');
            $reason_s = !empty($r['request_reason']) ? " ({$r['request_reason']})" : '';
            $add($r['event_date'], [
                'id'          => 'req_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => $req_type . ' Request',
                'type_key'    => 'manager_approval',
                'icon_class'  => 'fas fa-exclamation-triangle',
                'staff_name'  => $r['staff_name'],
                'station_name' => $r['station_name'] ?? '',
                'request_type' => $r['request_type'],
                'transaction_id' => $r['transaction_id'],
                'request_reason' => $r['request_reason'],
                'correction_field' => $r['correction_field'],
                'requested_value' => $r['requested_value'],
                'remarks'     => $r['remarks'],
                'status'      => $st_ap,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "{$req_type} Request – #{$r['transaction_id']}",
                'work_description' => "[{$req_type} Request] Txn #{$r['transaction_id']}{$reason_s} — {$st_clean}",
                'start_time'  => $r['start_time'] ?? '10:00:00',
                'priority'    => $st_ap === 'pending' ? 'urgent' : 'normal',
                'target_url'  => 'manager_validated_transactions.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 6. Fuel / Shift Report Review (Shift Closing & Reconciliation) ─────
    try {
        $q = "SELECT fsc.id, fsc.report_date AS event_date, fsc.shift, fsc.shift_period,
                     fsc.gross_sales, fsc.total_liters, fsc.total_fuel_sales, fsc.status,
                     u.name AS staff_name, st.name AS station_name
              FROM fuel_sales_closing fsc
              LEFT JOIN users u ON fsc.encoded_by = u.id
              LEFT JOIN stations st ON fsc.station_id = st.id
              WHERE fsc.report_date BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND fsc.station_id = ?" : "") . "
              ORDER BY fsc.report_date ASC";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $shift_label = !empty($r['shift']) ? $r['shift']
                : (($r['shift_period'] ?? '') === 'first' ? 'Shift 1' : 'Shift 2');
            $closing_time = (stripos($shift_label, '1') !== false) ? '2:00 PM' : '12:00 MN';
            $st_sc = strtolower($r['status'] ?? 'submitted');
            $st_clean = ucwords(str_replace('_', ' ', $st_sc));
            if (in_array($st_sc, ['closing_completed','verified','official','locked'])) {
                $color = '#059669'; $bg_color = '#ecfdf5'; $text_color = '#065f46'; $border_color = '#a7f3d0';
            } else {
                $color = '#0d9488'; $bg_color = '#f0fdfa'; $text_color = '#0f766e'; $border_color = '#99f6e4';
            }
            $sales_num = (float)($r['gross_sales'] ?? $r['total_fuel_sales'] ?? 0);
            $sales_s   = $sales_num > 0 ? " (₱" . number_format($sales_num, 2) . ")" : '';
            $add((string)$r['event_date'], [
                'id'          => 'fsc_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Shift Report Review',
                'type_key'    => 'sales_closing',
                'icon_class'  => 'fas fa-file-invoice-dollar',
                'staff_name'  => $r['staff_name'] ?: 'Closing Attendant',
                'station_name' => $r['station_name'] ?? '',
                'shift'       => $shift_label,
                'shift_name'  => $shift_label,
                'closing_time' => $closing_time,
                'submission_status' => $st_clean,
                'gross_sales' => $r['gross_sales'],
                'total_liters' => $r['total_liters'],
                'status'      => $st_sc,
                'status_clean' => $st_clean,
                'color'       => $color,
                'bg_color'    => $bg_color,
                'text_color'  => $text_color,
                'border_color' => $border_color,
                'calendar_title' => "{$shift_label} Report Review",
                'work_description' => "{$shift_label} Report Review / Reconciliation ({$closing_time}){$sales_s} [{$st_clean}]",
                'start_time'  => (stripos($shift_label, '1') !== false) ? '14:00:00' : '23:59:00',
                'target_url'  => 'manager_reports.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 7. Fuel Calibration Records ───────────────────────────────────────
    try {
        $q = "SELECT fcr.id, DATE(fcr.calibration_date) AS event_date, fcr.fuel_type,
                     fcr.calibration_liters, fcr.pump_number, fcr.status,
                     u.name AS staff_name, st.name AS station_name
              FROM fuel_calibration_records fcr
              LEFT JOIN users u ON fcr.staff_id = u.id
              LEFT JOIN stations st ON fcr.station_id = st.id
              WHERE DATE(fcr.calibration_date) BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND fcr.station_id = ?" : "");
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st_c  = strtolower($r['status'] ?? 'completed');
            $st_clean = ucwords(str_replace('_', ' ', $st_c));
            $add($r['event_date'], [
                'id'          => 'calib_' . $r['id'],
                'numeric_id'  => $r['id'],
                'type_name'   => 'Fuel Calibration',
                'type_key'    => 'fuel_calibration',
                'icon_class'  => 'fas fa-tachometer-alt',
                'staff_name'  => $r['staff_name'] ?: 'Technician',
                'station_name' => $r['station_name'] ?? '',
                'pump_number' => $r['pump_number'],
                'fuel_type'   => $r['fuel_type'],
                'calibration_liters' => $r['calibration_liters'],
                'status'      => $st_c,
                'status_clean' => $st_clean,
                'color'       => '#64748b',
                'bg_color'    => '#f8fafc',
                'text_color'  => '#334155',
                'border_color' => '#cbd5e1',
                'calendar_title' => "Calibration: Pump {$r['pump_number']} ({$r['fuel_type']})",
                'work_description' => "Calibration: Pump {$r['pump_number']} – {$r['fuel_type']} (" . number_format((float)($r['calibration_liters'] ?? 0), 2) . " L) [{$st_clean}]",
                'start_time'  => '07:30:00',
                'target_url'  => 'manager_fuel_adjustments.php',
                'auto_synced' => true,
            ]);
        }
    } catch (Throwable $e) {}

    // ─── 8. Custom / Manual Calendar Events ────────────────────────────────
    // Includes: meetings, trainings, maintenance, inspections, other scheduled events
    try {
        $q = "SELECT sce.*, et.type_name, et.type_key, et.icon_class, su.name AS staff_name,
                     st.name AS station_name
              FROM staff_calendar_events sce
              JOIN staff_event_types et ON sce.event_type_id = et.id
              JOIN users su ON sce.staff_encoder_id = su.id
              LEFT JOIN stations st ON sce.station_id = st.id
              WHERE sce.event_date BETWEEN ? AND ?
                " . ($station_id > 0 ? "AND sce.station_id = ?" : "") . "
              ORDER BY sce.event_date, sce.start_time";
        $stmt = $pdo->prepare($q);
        $stmt->execute(array_merge([$view_start, $view_end], $st_p));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['status_clean']  = ucwords(str_replace('_', ' ', $r['status'] ?? 'scheduled'));
            $r['calendar_title'] = !empty($r['work_description']) ? $r['work_description'] : ($r['type_name'] ?? 'Operational Event');
            $r['bg_color']      = '#f8fafc';
            $r['text_color']    = '#334155';
            $r['border_color']  = '#cbd5e1';
            $r['auto_synced']   = false;
            if (empty($r['color'])) $r['color'] = '#64748b';
            $add($r['event_date'], $r);
        }
    } catch (Throwable $e) {}

    return $events;
}


