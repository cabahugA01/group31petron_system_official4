<?php
$f = "C:/xampp/htdocs/group31petron_system_official4/public/staff_record_delivery.php";
$c = file_get_contents($f);
$c = preg_replace('/(setInterval\s*\(\s*updateRealTimeDeliveryInputs\s*,\s*)15000(\s*\))/i', '${1}10000${2}', $c);
file_put_contents($f, $c);
echo "Updated staff_record_delivery.php auto-refresh to 10s.\n";
