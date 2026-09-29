<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

// Ensure columns barangay, city, province exist
try {
    $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('barangay', $cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN barangay VARCHAR(150) NULL AFTER address");
    }
    if (!in_array('city', $cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN city VARCHAR(150) NULL AFTER barangay");
    }
    if (!in_array('province', $cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN province VARCHAR(150) NULL AFTER city");
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Failed to add columns: ' . $e->getMessage()]);
    exit;
}

$updated_cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'columns' => $updated_cols
], JSON_PRETTY_PRINT);
