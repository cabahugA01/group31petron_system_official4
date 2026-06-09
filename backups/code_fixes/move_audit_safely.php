<?php
$f = 'partials/rbac_menu.php';
$c = file_get_contents($f);

// The exact string to find and remove from the 'fuel' block
$audit_trail_block = <<<PHP
                // ── Audit Trail — standalone top-level item after Reports ──
                \$filtered_menu[] = [
                    'id'               => 'mgr_audit_trail',
                    'label'            => 'Audit Trail',
                    'ico'              => 'fas fa-shield-halved',
                    'href'             => 'manager_reports.php?section=audit_trail',
                    'permissions'      => ['approve_transactions','manage_job_orders'],
                    'station_specific' => true,
                    'sub_items'        => [],
                ];
PHP;

// Remove it from the fuel block
// Note: we replace it with nothing.
$c = str_replace($audit_trail_block, "", $c);

// Now, find the end of the reports block:
// "                $filtered_menu[] = $filtered_item;"
// "                continue;"
// And inject the audit trail block between them.

$target_injection = <<<PHP
                \$filtered_menu[] = \$filtered_item;
                continue;
            }

            if (\$user_role === 'manager' && (\$item['id'] ?? '') === 'purchase_orders') {
PHP;

$replacement = <<<PHP
                \$filtered_menu[] = \$filtered_item;

$audit_trail_block
                continue;
            }

            if (\$user_role === 'manager' && (\$item['id'] ?? '') === 'purchase_orders') {
PHP;

$c = str_replace($target_injection, $replacement, $c);

file_put_contents($f, $c);
echo "Perfectly moved Audit Trail to be under Reports!\n";
