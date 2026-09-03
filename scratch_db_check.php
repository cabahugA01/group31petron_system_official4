<?php
require 'C:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
 = ->query('SELECT id, station_id, fuel_type, ugt_no, status, price_per_liter FROM fuel_inventory');
echo json_encode(->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
