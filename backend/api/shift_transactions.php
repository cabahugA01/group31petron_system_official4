<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
}

if (ob_get_level() === 0) {
    ob_start();
}

function shift_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload);
    exit;
}

function shift_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function shift_table_columns(PDO $pdo, string $tableName): array {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[] = $row['Field'];
    }
    return $columns;
}

function shift_time_sql(string $column, ?string $shiftEnd): string {
    return $shiftEnd ? ($column . ' BETWEEN ? AND ?') : ($column . ' >= ?');
}

function shift_time_params(string $shiftStart, ?string $shiftEnd): array {
    return $shiftEnd ? [$shiftStart, $shiftEnd] : [$shiftStart];
}

function shift_first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

try {
    require_login();

    $me = current_user();
    $station_id = user_station_id();
    $role = role_key($me['role'] ?? '');

    if (!in_array($role, ['staff', 'cashier', 'pump_attendant'], true)) {
        shift_json_response(['error' => 'Access denied. Staff role required.'], 403);
    }

    $stmt = $pdo->prepare('SELECT * FROM labor_sessions WHERE user_id = ? AND station_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
    $stmt->execute([$me['id'], $station_id]);
    $current_shift = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$current_shift) {
        shift_json_response([
            'shift_info' => null,
            'summary' => [
                'fuel_sales' => 0,
                'merchandise_sales' => 0,
                'job_orders_processed' => 0,
                'total_revenue' => 0,
                'total_liters' => 0,
                'transaction_count' => 0,
            ],
            'fuel_breakdown' => [],
            'merchandise_breakdown' => [],
            'job_orders' => [],
            'variance_flags' => [],
            'audit_trail' => [],
        ]);
    }

    $shiftStart = (string)$current_shift['start_time'];
    $shiftEnd = !empty($current_shift['end_time']) ? (string)$current_shift['end_time'] : null;
    $timeParams = shift_time_params($shiftStart, $shiftEnd);

    $summary = [
        'fuel_sales' => 0.0,
        'merchandise_sales' => 0.0,
        'job_orders_processed' => 0,
        'total_revenue' => 0.0,
        'total_liters' => 0.0,
        'transaction_count' => 0,
    ];

    $fuel_breakdown = [];
    if (shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = 'SELECT fuel_type,
                       COUNT(*) AS entry_count,
                       COALESCE(SUM(liters_sold), 0) AS liters_sold,
                       COALESCE(SUM(total_amount), 0) AS total_amount,
                       COALESCE(MAX(price_per_liter), 0) AS latest_price,
                       COALESCE(MAX(status), "pending") AS latest_status,
                       MAX(transaction_date) AS latest_entry_at
                FROM fuel_transactions
                WHERE staff_id = ?
                  AND station_id = ?
                  AND ' . shift_time_sql('transaction_date', $shiftEnd) . '
                GROUP BY fuel_type
                ORDER BY fuel_type';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $fuel_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sql = 'SELECT COALESCE(SUM(total_amount), 0) AS fuel_sales,
                       COALESCE(SUM(liters_sold), 0) AS total_liters,
                       COUNT(*) AS transaction_count
                FROM fuel_transactions
                WHERE staff_id = ?
                  AND station_id = ?
                  AND ' . shift_time_sql('transaction_date', $shiftEnd);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $fuelSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['fuel_sales'] = (float)($fuelSummary['fuel_sales'] ?? 0);
        $summary['total_liters'] = (float)($fuelSummary['total_liters'] ?? 0);
        $summary['transaction_count'] += (int)($fuelSummary['transaction_count'] ?? 0);
    }

    $merchandise_breakdown = [];
    if (shift_table_exists($pdo, 'merchandise_transactions')) {
        $sql = 'SELECT mt.item_sku,
                       COUNT(*) AS entry_count,
                       COALESCE(SUM(mt.quantity), 0) AS quantity,
                       COALESCE(MAX(mt.unit_price), 0) AS unit_price,
                       COALESCE(SUM(mt.total_amount), 0) AS total_amount,
                       MAX(mt.transaction_date) AS latest_entry_at
                FROM merchandise_transactions mt
                WHERE mt.staff_id = ?
                  AND mt.station_id = ?
                  AND ' . shift_time_sql('mt.transaction_date', $shiftEnd) . '
                GROUP BY mt.item_sku
                ORDER BY total_amount DESC, mt.item_sku';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $merchandise_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sql = 'SELECT COALESCE(SUM(total_amount), 0) AS merchandise_sales,
                       COUNT(*) AS transaction_count
                FROM merchandise_transactions
                WHERE staff_id = ?
                  AND station_id = ?
                  AND ' . shift_time_sql('transaction_date', $shiftEnd);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $merchSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['merchandise_sales'] = (float)($merchSummary['merchandise_sales'] ?? 0);
        $summary['transaction_count'] += (int)($merchSummary['transaction_count'] ?? 0);
    }

    $job_orders = [];
    if (shift_table_exists($pdo, 'job_orders')) {
        $jobOrderColumns = shift_table_columns($pdo, 'job_orders');
        $jobOrderStaffColumn = shift_first_existing_column($jobOrderColumns, ['created_by', 'user_id', 'staff_id', 'assigned_by', 'reviewed_by', 'approved_by']);
        $customerExpr = in_array('customer_name', $jobOrderColumns, true)
            ? 'jo.customer_name'
            : '"Walk-in"';
        $serviceExpr = in_array('service_type', $jobOrderColumns, true)
            ? 'COALESCE(jo.service_type, "-")'
            : (in_array('service_description', $jobOrderColumns, true)
                ? 'COALESCE(jo.service_description, "-")'
                : '"-"');
        $vehicleExpr = in_array('vehicle_plate', $jobOrderColumns, true)
            ? 'COALESCE(jo.vehicle_plate, "-")'
            : '"-"';
        $completedExpr = in_array('completed_at', $jobOrderColumns, true)
            ? 'jo.completed_at'
            : 'NULL';
        $paymentExpr = in_array('payment_method', $jobOrderColumns, true)
            ? 'COALESCE(jo.payment_method, "-")'
            : '"-"';
        $jobOrderReferenceExpr = in_array('job_order_number', $jobOrderColumns, true)
            ? 'COALESCE(jo.job_order_number, CAST(jo.id AS CHAR))'
            : 'CAST(jo.id AS CHAR)';
        $jobOrderStaffFilter = $jobOrderStaffColumn ? ('AND jo.`' . $jobOrderStaffColumn . '` = ?') : '';

        $sql = 'SELECT jo.id,
                       ' . $jobOrderReferenceExpr . ' AS job_order_number,
                       ' . $customerExpr . ' AS customer_name,
                       ' . $serviceExpr . ' AS service_type,
                       ' . $vehicleExpr . ' AS vehicle_plate,
                       jo.status,
                       ' . $paymentExpr . ' AS payment_method,
                       jo.created_at,
                       ' . $completedExpr . ' AS completed_at,
                       m.full_name AS mechanic_name
                FROM job_orders jo
                LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                WHERE jo.station_id = ?
                                    ' . $jobOrderStaffFilter . '
                  AND ' . shift_time_sql('jo.created_at', $shiftEnd) . '
                ORDER BY jo.created_at DESC';
        $stmt = $pdo->prepare($sql);
                $jobOrderParams = [$station_id];
                if ($jobOrderStaffColumn) {
                        $jobOrderParams[] = $me['id'];
                }
                $stmt->execute(array_merge($jobOrderParams, $timeParams));
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary['job_orders_processed'] = count($job_orders);
    }

    $summary['total_revenue'] = $summary['fuel_sales'] + $summary['merchandise_sales'];

    $variance_flags = [];
    if (shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = 'SELECT ft.transaction_id,
                       ft.fuel_type,
                       ft.previous_reading,
                       ft.present_reading,
                       ft.calibration,
                       ft.liters_sold,
                       ft.status,
                       ft.validated_at,
                       validator.name AS validated_by_name,
                       CASE
                           WHEN ft.liters_sold < 0 THEN "Negative liters sold"
                           WHEN ft.status IN ("rejected", "flagged") THEN "Manager flagged transaction"
                           WHEN ft.validated_by IS NOT NULL THEN "Validated by manager"
                           ELSE "Pending review"
                       END AS variance_note,
                       ft.transaction_date
                FROM fuel_transactions ft
                LEFT JOIN users validator ON validator.id = ft.validated_by
                WHERE ft.staff_id = ?
                  AND ft.station_id = ?
                  AND ' . shift_time_sql('ft.transaction_date', $shiftEnd) . '
                  AND (
                      ft.liters_sold < 0
                      OR ft.status <> "pending"
                      OR ft.validated_by IS NOT NULL
                  )
                ORDER BY ft.transaction_date DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $variance_flags = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $auditRows = [];

    if (shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = 'SELECT transaction_date AS event_time,
                       "Fuel" AS source,
                       transaction_id AS reference_id,
                       CONCAT(fuel_type, " | ", FORMAT(liters_sold, 2), " L | ₱", FORMAT(total_amount, 2)) AS details,
                       status AS status_text,
                       validated_at,
                       validated_by
                FROM fuel_transactions
                WHERE staff_id = ?
                  AND station_id = ?
                  AND ' . shift_time_sql('transaction_date', $shiftEnd);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $auditRows = array_merge($auditRows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    if (shift_table_exists($pdo, 'merchandise_transactions')) {
        $sql = 'SELECT transaction_date AS event_time,
                       "Merchandise" AS source,
                       transaction_id AS reference_id,
                       CONCAT(item_sku, " | Qty ", quantity, " | ₱", FORMAT(total_amount, 2)) AS details,
                       "completed" AS status_text,
                       NULL AS validated_at,
                       NULL AS validated_by
                FROM merchandise_transactions
                WHERE staff_id = ?
                  AND station_id = ?
                  AND ' . shift_time_sql('transaction_date', $shiftEnd);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$me['id'], $station_id], $timeParams));
        $auditRows = array_merge($auditRows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    if (shift_table_exists($pdo, 'job_orders')) {
        $jobOrderColumns = isset($jobOrderColumns) ? $jobOrderColumns : shift_table_columns($pdo, 'job_orders');
        $jobOrderStaffColumn = isset($jobOrderStaffColumn) ? $jobOrderStaffColumn : shift_first_existing_column($jobOrderColumns, ['created_by', 'user_id', 'staff_id', 'assigned_by', 'reviewed_by', 'approved_by']);
        $auditCustomerExpr = in_array('customer_name', $jobOrderColumns, true)
            ? 'COALESCE(customer_name, "Walk-in")'
            : '"Walk-in"';
        $auditServiceExpr = in_array('service_type', $jobOrderColumns, true)
            ? 'COALESCE(service_type, "-")'
            : (in_array('service_description', $jobOrderColumns, true)
                ? 'COALESCE(service_description, "-")'
                : '"-"');
        $auditReferenceExpr = in_array('job_order_number', $jobOrderColumns, true)
            ? 'COALESCE(job_order_number, CAST(id AS CHAR))'
            : 'CAST(id AS CHAR)';
        $jobOrderAuditStaffFilter = $jobOrderStaffColumn ? ('AND `' . $jobOrderStaffColumn . '` = ?') : '';
        $sql = 'SELECT created_at AS event_time,
                       "Job Order" AS source,
                       ' . $auditReferenceExpr . ' AS reference_id,
                       CONCAT(' . $auditServiceExpr . ', " | ", ' . $auditCustomerExpr . ', " | ", COALESCE(status, "Pending")) AS details,
                       COALESCE(status, "Pending") AS status_text,
                       NULL AS validated_at,
                       NULL AS validated_by
                FROM job_orders
                WHERE station_id = ?
                  ' . $jobOrderAuditStaffFilter . '
                  AND ' . shift_time_sql('created_at', $shiftEnd);
        $stmt = $pdo->prepare($sql);
        $jobOrderAuditParams = [$station_id];
        if ($jobOrderStaffColumn) {
            $jobOrderAuditParams[] = $me['id'];
        }
        $stmt->execute(array_merge($jobOrderAuditParams, $timeParams));
        $auditRows = array_merge($auditRows, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    usort($auditRows, static function (array $left, array $right): int {
        return strcmp((string)($right['event_time'] ?? ''), (string)($left['event_time'] ?? ''));
    });

    $durationSeconds = max(0, time() - strtotime($shiftStart));
    $durationHours = floor($durationSeconds / 3600);
    $durationMinutes = floor(($durationSeconds % 3600) / 60);

    shift_json_response([
        'shift_info' => [
            'staff_id' => $me['id'],
            'staff_name' => $me['name'] ?? $me['username'] ?? 'Unknown Staff',
            'station_id' => $station_id,
            'shift_start' => $shiftStart,
            'shift_end' => $shiftEnd,
            'duration' => sprintf('%dh %02dm', $durationHours, $durationMinutes),
        ],
        'summary' => $summary,
        'fuel_breakdown' => $fuel_breakdown,
        'merchandise_breakdown' => $merchandise_breakdown,
        'job_orders' => $job_orders,
        'variance_flags' => $variance_flags,
        'audit_trail' => $auditRows,
    ]);
} catch (Throwable $e) {
    shift_json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
}
