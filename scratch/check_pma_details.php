<?php
$pdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");

echo "=== pma__pdf_pages ===\n";
$stmt = $pdo->query("SELECT * FROM pma__pdf_pages");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== pma__table_coords count ===\n";
$stmt = $pdo->query("SELECT db_name, pdf_page_number, COUNT(*) as cnt FROM pma__table_coords GROUP BY db_name, pdf_page_number");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== pma__designer_settings ===\n";
$stmt = $pdo->query("SELECT * FROM pma__designer_settings");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
