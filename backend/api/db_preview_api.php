<?php
// backend/api/db_preview_api.php
// Returns a live preview (first 20 rows) for Export Database tab
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']); exit;
}

header('Content-Type: application/json; charset=UTF-8');

$format    = strtolower(trim($_GET['format'] ?? 'sql'));
$tbl_param = trim($_GET['table'] ?? '__all__');

// Validate format
if (!in_array($format, ['sql', 'csv', 'json', 'xml'])) {
    echo json_encode(['error' => 'Unsupported format']); exit;
}

// Get tables list
$all_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

if ($tbl_param === '__all__') {
    $tables_to_preview = array_slice($all_tables, 0, 3); // preview first 3 tables
} else {
    if (!in_array($tbl_param, $all_tables)) {
        echo json_encode(['error' => 'Table not found: ' . htmlspecialchars($tbl_param)]); exit;
    }
    $tables_to_preview = [$tbl_param];
}

$preview = '';

if ($format === 'sql') {
    $preview  = "-- Petron Station Management System — SQL Export Preview\n";
    $preview .= "-- Tables: " . ($tbl_param === '__all__' ? 'ALL (' . count($all_tables) . ' tables)' : $tbl_param) . "\n";
    $preview .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $preview .= "-- (Showing first 20 rows per table)\n\n";
    $preview .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables_to_preview as $tbl) {
        try {
            $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
            $preview .= "-- -----------------------------------------------------------\n";
            $preview .= "-- Table: `{$tbl}`\n";
            $preview .= "-- -----------------------------------------------------------\n";
            $preview .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
            $preview .= $create[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `{$tbl}` LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = array_map(fn($c) => "`{$c}`", array_keys($rows[0]));
                $preview .= "INSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES\n";
                $vrows = [];
                foreach ($rows as $row) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                    $vrows[] = '(' . implode(', ', $vals) . ')';
                }
                $preview .= implode(",\n", $vrows) . ";\n\n";
            }
        } catch (Exception $e) {
            $preview .= "-- SKIPPED `{$tbl}`: " . $e->getMessage() . "\n\n";
        }
    }
    if ($tbl_param === '__all__') {
        $preview .= "-- ... (" . (count($all_tables) - count($tables_to_preview)) . " more tables in full export)\n";
    }
}

if ($format === 'csv') {
    $lines = [];
    foreach ($tables_to_preview as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}` LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            $lines[] = "=== TABLE: {$tbl} ===";
            if (!empty($rows)) {
                $lines[] = implode(',', array_map(fn($k) => '"' . $k . '"', array_keys($rows[0])));
                foreach ($rows as $row) {
                    $lines[] = implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"', array_values($row)));
                }
            } else {
                $lines[] = '(empty table)';
            }
            $lines[] = '';
        } catch (Exception $e) {
            $lines[] = "ERROR: " . $e->getMessage();
        }
    }
    if ($tbl_param === '__all__') {
        $lines[] = "... (" . (count($all_tables) - count($tables_to_preview)) . " more tables in full export)";
    }
    $preview = implode("\n", $lines);
}

if ($format === 'json') {
    $data = [
        'export_info' => [
            'system'    => 'Petron Station Management System',
            'generated' => date('Y-m-d\TH:i:sP'),
            'format'    => 'JSON',
            'total_tables' => count($all_tables),
            'preview_tables' => count($tables_to_preview),
        ],
        'data' => []
    ];
    foreach ($tables_to_preview as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}` LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            $data['data'][$tbl] = ['row_count' => count($rows), 'rows' => $rows];
        } catch (Exception $e) {
            $data['data'][$tbl] = ['error' => $e->getMessage()];
        }
    }
    if ($tbl_param === '__all__') {
        $data['data']['...'] = ['note' => (count($all_tables) - count($tables_to_preview)) . ' more tables in full export'];
    }
    $preview = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if ($format === 'xml') {
    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<petron_export>\n";
    $xml .= "  <export_info>\n";
    $xml .= "    <system>Petron Station Management System</system>\n";
    $xml .= "    <generated>" . date('Y-m-d\TH:i:sP') . "</generated>\n";
    $xml .= "    <format>XML</format>\n";
    $xml .= "    <total_tables>" . count($all_tables) . "</total_tables>\n";
    $xml .= "  </export_info>\n";
    $xml .= "  <tables>\n";
    foreach ($tables_to_preview as $tbl) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$tbl}` LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
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
    if ($tbl_param === '__all__') {
        $xml .= "    <!-- " . (count($all_tables) - count($tables_to_preview)) . " more tables in full export -->\n";
    }
    $xml .= "  </tables>\n";
    $xml .= "</petron_export>\n";
    $preview = $xml;
}

echo json_encode([
    'preview' => $preview,
    'format'  => $format,
    'table'   => $tbl_param,
    'total_tables' => count($all_tables),
    'preview_tables' => count($tables_to_preview),
    'note' => $tbl_param === '__all__' ? 'Showing first ' . count($tables_to_preview) . ' of ' . count($all_tables) . ' tables' : 'Showing first 20 rows',
]);
