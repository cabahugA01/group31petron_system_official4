<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$stmt = $pdo->query("DESCRIBE users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
