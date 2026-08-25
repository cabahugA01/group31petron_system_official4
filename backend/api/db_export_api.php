<?php
// ============================================================
// Database Export API
// backend/api/db_export_api.php
// Supports: SQL, CSV, JSON, XML
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// ── Auth ───────────────────────────────────────────────────────────────
require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

// ── CSRF check ──────────────────────────────────────────────────────────
$csrf = $_GET['csrf'] ?? $_POST['csrf_token'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']); exit;
}

// ── Parameters ─────────────────────────────────────────────────────────
$format    = strtolower(trim($_GET['format'] ?? 'sql'));
$tbl_param = trim($_GET['table'] ?? '__all__');
$ts        = date('Y_m_d_His');
$db_name   = 'u285762786_petrondbs
';

// Whitelist formats
if (!in_array($format, ['sql', 'csv', 'json', 'xml'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported format']); exit;
}

// ── Get tables to export ────────────────────────────────────────────────
$all_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

if ($tbl_param === '__all__') {
    $tables_to_export = $all_tables;
} else {
    // Validate requested table exists
    if (!in_array($tbl_param, $all_tables)) {
        http_response_code(400);
        echo json_encode(['error' => 'Table not found: ' . htmlspecialchars($tbl_param)]); exit;
    }
    $tables_to_export = [$tbl_param];
}

// ── Log the export action ───────────────────────────────────────────────
log_activity($pdo, $me['id'], 'DB Export',
    "Export format={$format}, tables=" . ($tbl_param === '__all__' ? 'ALL' : $tbl_param));

// ═══════════════════════════════════════════════════════════════════════
// FORMAT: SQL
// ═══════════════════════════════════════════════════════════════════════
if ($format === 'sql') {
    $filename = "u285762786_petrondbs
.sql";
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: no-cache, no-store, must-revalidate");

    $out  = "-- ============================================================\n";
    $out .= "-- Petron Station Management System — Database Backup\n";
    $out .= "-- Database: u285762786_petrondbs
\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Tables: " . ($tbl_param === '__all__' ? 'ALL (' . count($tables_to_export) . ' tables)' : $tbl_param) . "\n";
    $out .= "-- Exported by: " . htmlspecialchars($me['first_name'] . ' ' . ($me['last_name'] ?? '')) . "\n";
    $out .= "-- ============================================================\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $out .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $out .= "SET NAMES utf8mb4;\n\n";

    foreach ($tables_to_export as $tbl) {
        try {
            // Table structure
            $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
            $out .= "\n-- -----------------------------------------------------------\n";
            $out .= "-- Table structure for `{$tbl}`\n";
            $out .= "-- -----------------------------------------------------------\n";
            $out .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
            $out .= $create[1] . ";\n\n";

            // Table data
            $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $out .= "-- Data for `{$tbl}` (" . count($rows) . " rows)\n";
                $cols = array_map(fn($c) => "`{$c}`", array_keys($rows[0]));
                $out .= "INSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES\n";
                $vals_rows = [];
                foreach ($rows as $row) {
                    $vals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, array_values($row));
                    $vals_rows[] = '(' . implode(', ', $vals) . ')';
                }
                $out .= implode(",\n", $vals_rows) . ";\n\n";
            }
        } catch (Exception $e) {
            $out .= "-- SKIPPED `{$tbl}`: " . $e->getMessage() . "\n\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $out .= "\n-- Export completed: " . date('Y-m-d H:i:s') . "\n";
    echo $out;
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// FORMAT: CSV
// ═══════════════════════════════════════════════════════════════════════
if ($format === 'csv') {
    if (count($tables_to_export) === 1) {
        // Single table → inline CSV download
        $tbl = $tables_to_export[0];
        $filename = "petron_export_{$tbl}_{$ts}.csv";
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Cache-Control: no-cache, no-store, must-revalidate");

        $output = fopen('php://output', 'w');
        // BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");

        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                // Header row
                fputcsv($output, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($output, $row);
                }
            } else {
                fputcsv($output, ['No data found in table: ' . $tbl]);
            }
        } catch (Exception $e) {
            fputcsv($output, ['ERROR: ' . $e->getMessage()]);
        }
        fclose($output);
        exit;
    } else {
        // Multiple tables → combined CSV with section headers
        $filename = "petron_export_all_{$ts}.csv";
        header("Content-Type: text/csv; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Cache-Control: no-cache, no-store, must-revalidate");

        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");

        // Export summary header
        fputcsv($output, ['Petron Station Management System — Database CSV Export']);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, ['Tables Exported:', count($tables_to_export)]);
        fputcsv($output, []);

        foreach ($tables_to_export as $tbl) {
            try {
                $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                fputcsv($output, ["=== TABLE: {$tbl} (" . count($rows) . " rows) ==="]);
                if (!empty($rows)) {
                    fputcsv($output, array_keys($rows[0]));
                    foreach ($rows as $row) fputcsv($output, $row);
                } else {
                    fputcsv($output, ['(empty table)']);
                }
                fputcsv($output, []);
            } catch (Exception $e) {
                fputcsv($output, ["ERROR exporting {$tbl}: " . $e->getMessage()]);
            }
        }
        fclose($output);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// FORMAT: JSON
// ═══════════════════════════════════════════════════════════════════════
if ($format === 'json') {
    $filename = "petron_export_{$ts}.json";
    header("Content-Type: application/json; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: no-cache, no-store, must-revalidate");

    $export = [
        'export_info' => [
            'system'     => 'Petron Station Management System',
            'generated'  => date('Y-m-d\TH:i:sP'),
            'exported_by'=> trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')),
            'format'     => 'JSON',
            'tables'     => count($tables_to_export),
        ],
        'data' => []
    ];

    foreach ($tables_to_export as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            $export['data'][$tbl] = [
                'row_count' => count($rows),
                'rows'      => $rows
            ];
        } catch (Exception $e) {
            $export['data'][$tbl] = ['error' => $e->getMessage()];
        }
    }

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// FORMAT: XML
// ═══════════════════════════════════════════════════════════════════════
if ($format === 'xml') {
    $filename = "petron_export_{$ts}.xml";
    header("Content-Type: application/xml; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Cache-Control: no-cache, no-store, must-revalidate");

    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<petron_export>\n";
    $xml .= "  <export_info>\n";
    $xml .= "    <system>Petron Station Management System</system>\n";
    $xml .= "    <generated>" . date('Y-m-d\TH:i:sP') . "</generated>\n";
    $xml .= "    <exported_by>" . htmlspecialchars(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))) . "</exported_by>\n";
    $xml .= "    <format>XML</format>\n";
    $xml .= "    <table_count>" . count($tables_to_export) . "</table_count>\n";
    $xml .= "  </export_info>\n";
    $xml .= "  <tables>\n";

    foreach ($tables_to_export as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            $safe_tbl = preg_replace('/[^a-zA-Z0-9_]/', '_', $tbl);
            $xml .= "    <table name=\"{$safe_tbl}\" row_count=\"" . count($rows) . "\">\n";
            foreach ($rows as $row) {
                $xml .= "      <row>\n";
                foreach ($row as $col => $val) {
                    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '_', $col);
                    if ($val === null) {
                        $xml .= "        <{$safe_col} nil=\"true\"/>\n";
                    } else {
                        $xml .= "        <{$safe_col}>" . htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</{$safe_col}>\n";
                    }
                }
                $xml .= "      </row>\n";
            }
            $xml .= "    </table>\n";
        } catch (Exception $e) {
            $safe_tbl = preg_replace('/[^a-zA-Z0-9_]/', '_', $tbl);
            $xml .= "    <table name=\"{$safe_tbl}\" error=\"" . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\"/>\n";
        }
    }

    $xml .= "  </tables>\n";
    $xml .= "</petron_export>\n";
    echo $xml;
    exit;
}
