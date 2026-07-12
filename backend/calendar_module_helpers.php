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

