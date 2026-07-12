<?php
/**
 * Shared compatibility helpers for the customer module.
 *
 * The deployed database has both legacy customer columns and newer module
 * columns. These helpers keep Staff, Manager, and Admin screens reading the
 * same values without hard failing when an optional column is absent.
 */

if (!function_exists('customer_table_columns')) {
    function customer_table_columns(PDO $pdo, bool $refresh = false): array {
        static $columns = null;
        if ($columns !== null && !$refresh) {
            return $columns;
        }

        $columns = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM customers");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[strtolower($row['Field'])] = $row['Field'];
            }
        } catch (Exception $e) {
            $columns = [];
        }

        return $columns;
    }
}

if (!function_exists('customer_has_column')) {
    function customer_has_column(PDO $pdo, string $column): bool {
        $columns = customer_table_columns($pdo);
        return isset($columns[strtolower($column)]);
    }
}

if (!function_exists('customer_ensure_optional_columns')) {
    function customer_ensure_optional_columns(PDO $pdo): void {
        static $done = false;
        if ($done) {
            return;
        }

        $definitions = [
            'gov_id_type'            => "VARCHAR(100) NULL DEFAULT NULL",
            'company_name'           => "VARCHAR(255) NULL DEFAULT NULL",
            'company_address'        => "TEXT NULL DEFAULT NULL",
            'company_contact_person' => "VARCHAR(255) NULL DEFAULT NULL",
            'company_contact_number' => "VARCHAR(50) NULL DEFAULT NULL",
            'verification_remarks'   => "TEXT NULL DEFAULT NULL",
            'updated_by'             => "INT(11) NULL DEFAULT NULL",
            'updated_at'             => "DATETIME NULL DEFAULT NULL",
        ];

        $columns = customer_table_columns($pdo, true);
        foreach ($definitions as $column => $definition) {
            if (!isset($columns[strtolower($column)])) {
                try {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN `$column` $definition");
                } catch (Exception $e) {
                    // Never block the module if the DB user cannot alter schema.
                }
            }
        }

        customer_table_columns($pdo, true);
        $done = true;
    }
}

if (!function_exists('customer_expr_col')) {
    function customer_expr_col(PDO $pdo, string $alias, string $column, string $fallback = "NULL"): string {
        return customer_has_column($pdo, $column) ? "{$alias}.{$column}" : $fallback;
    }
}

if (!function_exists('customer_id_expr')) {
    function customer_id_expr(PDO $pdo, string $alias = 'c'): string {
        $raw = customer_expr_col($pdo, $alias, 'customer_id');
        if ($raw !== "NULL") {
            return "COALESCE(NULLIF($raw,''), CONCAT('CUS-', COALESCE({$alias}.station_id,0), '-', LPAD({$alias}.id,5,'0')))";
        }
        return "CONCAT('CUS-', COALESCE({$alias}.station_id,0), '-', LPAD({$alias}.id,5,'0'))";
    }
}

if (!function_exists('customer_display_name_expr')) {
    function customer_display_name_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }

        $fullName = $parts ? "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $parts) . ")), '')" : "NULL";
        $legacyName = customer_has_column($pdo, 'name') ? "NULLIF({$alias}.name,'')" : "NULL";

        return "COALESCE($fullName, $legacyName, 'Unknown Customer')";
    }
}

if (!function_exists('customer_first_name_expr')) {
    function customer_first_name_expr(PDO $pdo, string $alias = 'c'): string {
        $first = customer_expr_col($pdo, $alias, 'first_name');
        $name = customer_expr_col($pdo, $alias, 'name');
        if ($first !== "NULL" && $name !== "NULL") {
            return "COALESCE(NULLIF($first,''), SUBSTRING_INDEX($name, ' ', 1), '')";
        }
        if ($first !== "NULL") {
            return "COALESCE(NULLIF($first,''), '')";
        }
        if ($name !== "NULL") {
            return "COALESCE(SUBSTRING_INDEX($name, ' ', 1), '')";
        }
        return "''";
    }
}

if (!function_exists('customer_middle_name_expr')) {
    function customer_middle_name_expr(PDO $pdo, string $alias = 'c'): string {
        $middle = customer_expr_col($pdo, $alias, 'middle_name');
        return $middle === "NULL" ? "''" : "COALESCE($middle,'')";
    }
}

if (!function_exists('customer_last_name_expr')) {
    function customer_last_name_expr(PDO $pdo, string $alias = 'c'): string {
        $last = customer_expr_col($pdo, $alias, 'last_name');
        $name = customer_expr_col($pdo, $alias, 'name');
        if ($last !== "NULL" && $name !== "NULL") {
            return "COALESCE(NULLIF($last,''), CASE WHEN LOCATE(' ', $name) > 0 THEN SUBSTRING($name, LOCATE(' ', $name) + 1) ELSE '' END, '')";
        }
        if ($last !== "NULL") {
            return "COALESCE(NULLIF($last,''), '')";
        }
        if ($name !== "NULL") {
            return "CASE WHEN LOCATE(' ', $name) > 0 THEN SUBSTRING($name, LOCATE(' ', $name) + 1) ELSE '' END";
        }
        return "''";
    }
}

if (!function_exists('customer_contact_expr')) {
    function customer_contact_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['contact_number', 'phone'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_type_expr')) {
    function customer_type_expr(PDO $pdo, string $alias = 'c'): string {
        return "'registered'";
    }
}

if (!function_exists('customer_status_expr')) {
    function customer_status_expr(PDO $pdo, string $alias = 'c'): string {
        $status = customer_expr_col($pdo, $alias, 'status');
        $accountStatus = customer_expr_col($pdo, $alias, 'account_status');
        if ($status !== "NULL" && $accountStatus !== "NULL") {
            return "COALESCE(NULLIF($status,''), NULLIF($accountStatus,''), 'active')";
        }
        if ($status !== "NULL") {
            return "COALESCE(NULLIF($status,''), 'active')";
        }
        if ($accountStatus !== "NULL") {
            return "COALESCE(NULLIF($accountStatus,''), 'active')";
        }
        return "'active'";
    }
}

if (!function_exists('customer_registered_at_expr')) {
    function customer_registered_at_expr(PDO $pdo, string $alias = 'c'): string {
        $registered = customer_expr_col($pdo, $alias, 'registered_at');
        $created = customer_expr_col($pdo, $alias, 'created_at');
        if ($registered !== "NULL" && $created !== "NULL") {
            return "COALESCE($registered, $created)";
        }
        return $registered !== "NULL" ? $registered : ($created !== "NULL" ? $created : "NOW()");
    }
}

if (!function_exists('customer_balance_expr')) {
    function customer_balance_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['current_balance', 'outstanding_balance', 'balance'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "{$alias}.{$column}";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", 0)" : "0";
    }
}

if (!function_exists('customer_credit_limit_expr')) {
    function customer_credit_limit_expr(PDO $pdo, string $alias = 'c'): string {
        $credit = customer_expr_col($pdo, $alias, 'credit_limit');
        return $credit === "NULL" ? "0" : "COALESCE($credit, 0)";
    }
}

if (!function_exists('customer_verification_status_expr')) {
    function customer_verification_status_expr(PDO $pdo, string $alias = 'c'): string {
        $verification = customer_expr_col($pdo, $alias, 'verification_status');
        $managerStatus = customer_expr_col($pdo, $alias, 'mgr_status');
        if ($verification !== "NULL" && $managerStatus !== "NULL") {
            return "COALESCE(NULLIF($verification,''), NULLIF($managerStatus,''), 'pending')";
        }
        if ($verification !== "NULL") {
            return "COALESCE(NULLIF($verification,''), 'pending')";
        }
        if ($managerStatus !== "NULL") {
            return "COALESCE(NULLIF($managerStatus,''), 'pending')";
        }
        return "'pending'";
    }
}

if (!function_exists('customer_gov_id_type_expr')) {
    function customer_gov_id_type_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['gov_id_type', 'id_type'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_company_expr')) {
    function customer_company_expr(PDO $pdo, string $field, string $alias = 'c'): string {
        $fallbacks = [
            'company_name'           => [],
            'company_address'        => [],
            'company_contact_person' => ['contact_person'],
            'company_contact_number' => ['phone', 'contact_number'],
        ];

        $parts = [];
        if (customer_has_column($pdo, $field)) {
            $parts[] = "NULLIF({$alias}.{$field},'')";
        }
        foreach ($fallbacks[$field] ?? [] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_verification_remarks_expr')) {
    function customer_verification_remarks_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['verification_remarks', 'mgr_notes'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_user_name_expr')) {
    function customer_user_name_expr(string $alias): string {
        return "COALESCE(NULLIF({$alias}.name,''), NULLIF(TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.last_name)), ''), NULLIF({$alias}.username,''), 'System')";
    }
}

if (!function_exists('customer_can_view_all_stations')) {
    function customer_can_view_all_stations(string $role): bool {
        return in_array($role, ['superadmin', 'developer'], true);
    }
}

if (!function_exists('customer_apply_station_scope')) {
    function customer_apply_station_scope(array &$where, array &$params, string $alias, string $role, int $stationId): void {
        if (!customer_can_view_all_stations($role)) {
            $where[] = "{$alias}.station_id = ?";
            $params[] = $stationId;
        }
    }
}

if (!function_exists('customer_station_sql')) {
    function customer_station_sql(string $alias, string $role, int $stationId, array &$params): string {
        if (customer_can_view_all_stations($role)) {
            return "1=1";
        }
        $params[] = $stationId;
        return "{$alias}.station_id = ?";
    }
}

if (!function_exists('customer_legacy_billing_type')) {
    function customer_legacy_billing_type(string $customerType): string {
        return 'cash';
    }
}

if (!function_exists('customer_insert_existing')) {
    function customer_insert_existing(PDO $pdo, array $values, array $rawValues = []): int {
        $columns = customer_table_columns($pdo);
        $names = [];
        $holders = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (isset($columns[strtolower($column)])) {
                $names[] = "`$column`";
                $holders[] = "?";
                $params[] = $value;
            }
        }
        foreach ($rawValues as $column => $raw) {
            if (isset($columns[strtolower($column)])) {
                $names[] = "`$column`";
                $holders[] = $raw;
            }
        }

        if (!$names) {
            throw new Exception('No customer fields available to save.');
        }

        $sql = "INSERT INTO customers (" . implode(',', $names) . ") VALUES (" . implode(',', $holders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('customer_update_existing')) {
    function customer_update_existing(PDO $pdo, array $values, string $whereSql, array $whereParams = [], array $rawValues = []): int {
        $columns = customer_table_columns($pdo);
        $sets = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (isset($columns[strtolower($column)])) {
                $sets[] = "`$column` = ?";
                $params[] = $value;
            }
        }
        foreach ($rawValues as $column => $raw) {
            if (isset($columns[strtolower($column)])) {
                $sets[] = "`$column` = $raw";
            }
        }

        if (!$sets) {
            return 0;
        }

        $stmt = $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE $whereSql");
        $stmt->execute(array_merge($params, $whereParams));
        return $stmt->rowCount();
    }
}
?>
