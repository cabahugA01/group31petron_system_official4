<?php
$pdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");

$tables = ['pma__pdf_pages', 'pma__table_coords', 'pma__designer_settings', 'pma__relation'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    $stmt = $pdo->query("DESCRIBE `$t`");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}
