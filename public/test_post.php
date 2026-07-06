<?php
$ch = curl_init('http://localhost/group31petron_system_official4/public/api_fuel_readings.php');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, [  'action' => 'encode_reading',  'fuel_type' => 'Diesel',  'previous_reading' => '30000.09',  'present_reading' => '30010.09',  'calibration' => '3.000',  'shift_id' => '0'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
echo curl_exec($ch);
?>
