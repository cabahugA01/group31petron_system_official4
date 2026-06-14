<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = ['api_config', 'erp_connections', 'git_repos', 'git_commits', 'deployment_history', 'sync_jobs', 'sync_logs', 'integration_audit'];
foreach ($tables as $t) {
    echo "--- $t ---\n";
    $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} - {$c['Type']} - {$c['Null']} - {$c['Key']} - {$c['Default']}\n";
    }
}
