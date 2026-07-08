<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "<h3>Distinct payments payment_mode</h3><pre>";
$stmt = $pdo->query("SELECT DISTINCT payment_mode FROM payments");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
