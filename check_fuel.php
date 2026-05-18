<?php
require 'public/db_connect.php';
print_r($pdo->query('SELECT id, fuel_type, station_id, current_level FROM fuel_inventory')->fetchAll(PDO::FETCH_ASSOC));
