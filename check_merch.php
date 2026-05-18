<?php
require 'public/db_connect.php';
$apps = $pdo->query("SELECT * FROM pending_price_approvals WHERE product_type = 'merchandise'")->fetchAll(PDO::FETCH_ASSOC);
print_r($apps);
