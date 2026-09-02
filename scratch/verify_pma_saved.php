<?php
$pma = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");

$stmt = $pma->query("SELECT COUNT(*) FROM pma__table_coords WHERE db_name = 'petron_pos_db_secure'");
echo "Total table coordinates mapped in phpMyAdmin: " . $stmt->fetchColumn() . "\n";

$stmt = $pma->query("SELECT * FROM pma__pdf_pages WHERE db_name = 'petron_pos_db_secure'");
echo "Saved PDF/Designer Pages:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pma->query("SELECT COUNT(*) FROM pma__relation WHERE master_db = 'petron_pos_db_secure'");
echo "Total relations in pma__relation: " . $stmt->fetchColumn() . "\n";
