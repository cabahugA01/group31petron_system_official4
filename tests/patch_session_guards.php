<?php
/**
 * Bulk adds require_login() guard to pages that:
 *   - Have session_start() but no require_login()   → NEEDS_GUARD list
 *   - Have no session protection at all              → UNPROTECTED list
 *
 * Strategy:
 *   For NEEDS_GUARD: replace the first session_start() line with
 *       session_start(); require_login();
 *
 *   For UNPROTECTED: prepend a require_login block right after <?php
 *
 * Excluded files (public/utility, not real back-office pages):
 *   - forgot_password.php, forgot_password_reset.php  — pre-login public pages
 *   - db_check.php, db_table_check.php                — dev/maintenance only
 *   - import_database.php                             — dev/maintenance only
 *   - admin_po_body/css/modals.php                    — partials included by parent
 *   - generate_reports_scheduled.php                  — cron/CLI runner
 *   - staff_calendar_widget.php                       — widget partial
 *   - refresh_captcha.php                             — already excluded
 */

$dir = __DIR__ . '/../public';

// Pages that have session_start but no require_login
$needs_guard = [
    'api_fuel_readings.php',
    'deliveries_management.php',
    'delivery_management.php',
    'developer_backend.php',
    'fix_price_approval_constraints.php',
    'fuel_delivery_dashboard.php',
    'inventory_history.php',
    'manager_customer_operations.php',
    'merchandise_receipt.php',
    'purchase_orders_management.php',
    'staff_fuel_sales_closing.php',
    'staff_fuel_sales_closing_handler.php',
    'staff_fuel_sales_report.php',
    'staff_home.php',
    'system_alerts.php',
    'system_logs_ajax.php',
];

// Pages with no session protection at all
$unprotected = [
    'admin_compliance_reports.php',
    'admin_finance_reports.php',
    'admin_procurement_reports.php',
    'admin_purchase_orders_view.php',
    'api_merchandise_transactions.php',
    'check_fuel_status.php',
    'fuel_inventory_management.php',
    'fuel_reconciliation_manager.php',
    'fuel_super_admin_oversight.php',
    'inventory_export.php',
    'manager_shift_transactions.php',
    'reports_developer_audit.php',
    'reports_security.php',
    'staff_clock_in_out.php',
    'staff_txn_history.php',
    'stock_request.php',
];

$patched = 0;
$errors = [];

// ── Process NEEDS_GUARD: replace session_start() with session guard ─────────
foreach ($needs_guard as $fname) {
    $fpath = $dir . '/' . $fname;
    if (!file_exists($fpath)) {
        $errors[] = "NOT FOUND: $fname";
        continue;
    }

    $content = file_get_contents($fpath);

    // Check if already guarded (safety)
    if (preg_match('/require_login\s*\(\s*\)/', $content)) {
        echo "ALREADY GUARDED: $fname\n";
        continue;
    }

    // Replace first occurrence of session_start() with session_start(); require_login();
    $new = preg_replace(
        '/if\s*\(\s*session_status\s*\(\s*\)\s*===\s*PHP_SESSION_NONE\s*\)\s*session_start\s*\(\s*\)\s*;/',
        'if (session_status() === PHP_SESSION_NONE) session_start();' . "\n" . 'require_login();',
        $content,
        1, // limit to first match
        $count
    );

    // Fallback: plain session_start()
    if ($count === 0) {
        $new = preg_replace(
            '/session_start\s*\(\s*\)\s*;/',
            'session_start();' . "\n" . 'require_login();',
            $content,
            1,
            $count
        );
    }

    if ($count > 0 && $new !== null) {
        file_put_contents($fpath, $new);
        $patched++;
        echo "GUARDED: $fname\n";
    } else {
        $errors[] = "COULD NOT PATCH session_start in: $fname";
    }
}

// ── Process UNPROTECTED: inject after <?php ──────────────────────────────────
$auth_block = <<<'PHP'

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

PHP;

foreach ($unprotected as $fname) {
    $fpath = $dir . '/' . $fname;
    if (!file_exists($fpath)) {
        $errors[] = "NOT FOUND: $fname";
        continue;
    }

    $content = file_get_contents($fpath);

    // Check if already guarded (safety)
    if (preg_match('/require_login\s*\(\s*\)/', $content)) {
        echo "ALREADY GUARDED: $fname\n";
        continue;
    }

    // Check if lib.php is already included
    $has_lib = preg_match('/require.*lib\.php/', $content);

    // Inject after the opening <?php tag
    $new = preg_replace(
        '/^<\?php\s*/m',
        '<?php' . "\n" . ($has_lib ? '' : 'if (session_status() === PHP_SESSION_NONE) session_start();' . "\n" . 'require_once __DIR__ . \'/../backend/lib.php\';' . "\n") . 'require_login();' . "\n\n",
        $content,
        1,
        $count
    );

    if ($count > 0 && $new !== null) {
        file_put_contents($fpath, $new);
        $patched++;
        echo "PROTECTED: $fname\n";
    } else {
        $errors[] = "COULD NOT INJECT in: $fname";
    }
}

echo "\n=== Done. Patched: $patched files ===\n";
if ($errors) {
    echo "=== Errors: ===\n";
    foreach ($errors as $e) echo "  $e\n";
}
