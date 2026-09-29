<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$found = [];

foreach ($tables as $t) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        $textCols = [];
        foreach ($cols as $c) {
            if (preg_match('/char|text|varchar/i', $c['Type'])) {
                $textCols[] = "`{$c['Field']}`";
            }
        }
        if (empty($textCols)) continue;

        $where = [];
        foreach ($textCols as $col) {
            $where[] = "{$col} LIKE '%larosa%' OR {$col} LIKE '%kaloy%'";
        }
        $sql = "SELECT * FROM `{$t}` WHERE " . implode(' OR ', $where) . " LIMIT 10";
        $matches = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($matches)) {
            $found[$t] = $matches;
        }
    } catch (Exception $e) {}
}

echo json_encode([
    'db_name' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
    'found' => $found
], JSON_PRETTY_PRINT);
