<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$stmt = $pdo->query("SELECT * FROM system_settings");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
