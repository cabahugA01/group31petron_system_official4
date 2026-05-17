<?php
$f = 'partials/rbac_menu.php';
$c = file_get_contents($f);

// 1. Remove the Audit Trail block from its current location inside 'fuel'
$c = preg_replace('/\s*\/\/  Audit Trail — standalone top-level item after Reports \s*\$filtered_menu\[\] = \[\s*\'id\'\s*=>\s*\'mgr_audit_trail\'.*?\];\s*/is', '', $c);

// 2. Insert it inside the 'reports' block, right after $filtered_menu[] = $filtered_item;
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

$c = preg_replace('/(\$filtered_menu\[\] = \$filtered_item;\s*)(continue;\s*\}\s*if \(\$user_role === \'manager\' && \(\$item\[\'id\'\] \?\? \'\'\) === \'purchase_orders\'\))/is', "$1$audit_trail_block\n                $2", $c);

file_put_contents($f, $c);
echo "Moved Audit Trail menu to be after Reports!\n";
