<?php
// ============================================================
// Petron Station Management System - Automated Scheduled Backup Runner
// backend/cron_backup.php
//
// Can be executed via:
// 1. CLI: php backend/cron_backup.php [--force]
// 2. HTTP/cURL (restricted to cron trigger): /backend/cron_backup.php?key=petron_cron_key
// ============================================================

if (php_sapi_name() !== 'cli') {
    // Basic security token check for HTTP triggers
    $cron_key = $_GET['key'] ?? '';
    $expected_key = 'petron_cron_key';
    if ($cron_key !== $expected_key) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized cron request.']);
        exit;
    }
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$backup_dir = __DIR__ . '/../backups/';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}

// ── Ensure helper functions exist ──
if (!function_exists('cfg_get')) {
    function cfg_get(PDO $pdo, string $key, string $default = ''): string {
        try {
            $r = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
            $r->execute([$key]);
            $v = $r->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Exception $e) { return $default; }
    }
}

if (!function_exists('cfg_set')) {
    function cfg_set(PDO $pdo, string $key, string $value, int $uid = 0): void {
        try {
            $pdo->prepare("INSERT INTO system_config (config_key, config_value)
                VALUES(?, ?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), updated_at=NOW()")
                ->execute([$key, $value]);
        } catch (Exception $e) {
            error_log("cfg_set failed for key={$key}: " . $e->getMessage());
        }
    }
}

if (!function_exists('apply_backup_retention_policy')) {
    function apply_backup_retention_policy(PDO $pdo, int $retention_days): int {
        if ($retention_days <= 0) return 0;
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
            $stmt = $pdo->prepare("UPDATE database_backups 
                SET status = 'Archived' 
                WHERE created_at < ? AND status = 'Completed'");
            $stmt->execute([$cutoff]);
            return (int)$stmt->rowCount();
        } catch (Exception $e) {
            error_log("Retention policy error: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('execute_database_backup')) {
    function execute_database_backup(PDO $pdo, string $backup_dir, ?int $user_id = null, string $trigger_label = 'Automated'): array {
        $btype = 'Full Backup';
        $comp  = 'SQL';
        $fname = 'petron_pos_db_secure.sql';
        $fpath = $backup_dir . $fname;
        $db_name = 'petron_pos_db_secure';

        if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

        // 1. Try real mysqldump first
        $mysqldump_bin = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldump_bin)) $mysqldump_bin = 'mysqldump';

        $dump_args  = "--host=localhost --user=root --single-transaction --quick --skip-lock-tables --routines --triggers";
        $dump_cmd   = "\"{$mysqldump_bin}\" {$dump_args} {$db_name} > " . escapeshellarg($fpath) . " 2>&1";
        $dump_out_arr = [];
        @exec($dump_cmd, $dump_out_arr, $dump_ret);

        $fsize  = file_exists($fpath) ? filesize($fpath) : 0;
        $status = ($dump_ret === 0 && $fsize > 500) ? 'Completed' : 'Simulated';

        // 2. Fallback: PHP-PDO full SQL dump
        if ($status === 'Simulated') {
            try {
                $header  = "-- ============================================================\n";
                $header .= "-- Petron Station Management System\n";
                $header .= "-- Database: {$db_name}\n";
                $header .= "-- Backup Type: Full Backup\n";
                $header .= "-- Trigger: {$trigger_label}\n";
                $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
                $header .= "-- ============================================================\n\n";
                $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
                $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
                $header .= "SET NAMES utf8mb4;\n\n";
                file_put_contents($fpath, $header);

                $tables  = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
                $skipped = [];

                foreach ($tables as $tbl) {
                    try {
                        $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
                        $block  = "\n-- -----------------------------------------------------------\n";
                        $block .= "-- Table: `{$tbl}`\n";
                        $block .= "-- -----------------------------------------------------------\n";
                        $block .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
                        $block .= $create[1] . ";\n";

                        $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($rows)) {
                            $cols    = array_map(fn($c) => "`{$c}`", array_keys($rows[0]));
                            $block  .= "\nINSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES\n";
                            $vblocks = [];
                            foreach ($rows as $row) {
                                $vals    = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                                $vblocks[] = '(' . implode(', ', $vals) . ')';
                            }
                            $block .= implode(",\n", $vblocks) . ";\n";
                        }
                        $block .= "\n";
                        file_put_contents($fpath, $block, FILE_APPEND);

                    } catch (Exception $tblEx) {
                        $skipped[] = $tbl;
                        file_put_contents($fpath, "\n-- SKIPPED `{$tbl}`: " . $tblEx->getMessage() . "\n\n", FILE_APPEND);
                    }
                }

                $footer  = "SET FOREIGN_KEY_CHECKS=1;\n";
                $footer .= "\n-- Dump completed: " . date('Y-m-d H:i:s') . "\n";
                if (!empty($skipped)) {
                    $footer .= "-- Skipped tables (" . count($skipped) . "): " . implode(', ', $skipped) . "\n";
                }
                file_put_contents($fpath, $footer, FILE_APPEND);

                $fsize  = filesize($fpath);
                $status = 'Completed';

            } catch (Exception $dumpEx) {
                $stub = "-- Backup generation failed: " . $dumpEx->getMessage() . "\n";
                file_put_contents($fpath, $stub);
                $fsize  = strlen($stub);
                $status = 'Simulated';
            }
        }

        // Maintain archive copy
        $timestamp = date('Ymd_His');
        $archive_name = "petron_pos_db_{$timestamp}.sql";
        if (file_exists($fpath) && $fsize > 0) {
            @copy($fpath, $backup_dir . $archive_name);
        }

        // Save record to database_backups
        $pdo->prepare("INSERT INTO database_backups
            (backup_name, backup_file, backup_size, backup_type, compression, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$fname, '/backup/database/' . $fname, $fsize, $btype, $comp, $status, $user_id]);

        $bid = (int)$pdo->lastInsertId();

        log_activity($pdo, $user_id ?: 0, 'Database Management',
            "Automated system backup: {$fname} (Status:{$status}, Size:" . round($fsize/1024,1) . " KB)");

        return [
            'success'  => true,
            'id'       => $bid,
            'filename' => $fname,
            'size'     => $fsize,
            'status'   => $status,
        ];
    }
}

// ── Check execution conditions ──
$force = in_array('--force', $argv ?? [], true) || (isset($_GET['force']) && $_GET['force'] == '1');
$freq  = cfg_get($pdo, 'backup_frequency', 'manual');

if (!$force && ($freq === 'manual' || empty($freq))) {
    echo json_encode([
        'status'  => 'skipped',
        'message' => 'Backup frequency is currently set to Manual Only. Scheduled backup skipped.'
    ]);
    exit;
}

$last_run_str = cfg_get($pdo, 'backup_last_auto_run', '');
$last_run = !empty($last_run_str) ? strtotime($last_run_str) : 0;
$now = time();
$is_due = $force;

if (!$is_due) {
    if ($freq === 'hourly') {
        if ($last_run === 0 || ($now - $last_run) >= 3600) {
            $is_due = true;
        }
    } elseif ($freq === 'daily') {
        $stime = cfg_get($pdo, 'backup_scheduled_time', '02:00');
        $todayScheduled = strtotime(date('Y-m-d') . ' ' . $stime);
        if ($now >= $todayScheduled && $last_run < $todayScheduled) {
            $is_due = true;
        }
    } elseif ($freq === 'weekly') {
        if ($last_run === 0 || ($now - $last_run) >= 604800) {
            $is_due = true;
        }
    } elseif ($freq === 'monthly') {
        if ($last_run === 0 || ($now - $last_run) >= 2592000) {
            $is_due = true;
        }
    }
}

if ($is_due) {
    cfg_set($pdo, 'backup_last_auto_run', date('Y-m-d H:i:s'), 0);
    $res = execute_database_backup($pdo, $backup_dir, null, 'Automated');
    $ret = max(1, (int)cfg_get($pdo, 'backup_retention_days', '30'));
    $archived = apply_backup_retention_policy($pdo, $ret);

    echo json_encode([
        'status'            => 'success',
        'frequency'         => $freq,
        'backup_executed'   => $res,
        'retention_archived'=> $archived,
        'timestamp'         => date('Y-m-d H:i:s')
    ]);
} else {
    echo json_encode([
        'status'    => 'not_due',
        'frequency' => $freq,
        'last_run'  => $last_run_str ?: 'Never',
        'message'   => 'Scheduled backup is not yet due.'
    ]);
}
