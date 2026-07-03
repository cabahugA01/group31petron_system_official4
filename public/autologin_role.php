<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_GET['role'] ?? 'staff';
$users = [
    'staff'   => ['id'=>8,  'name'=>'Yyang Cabahug',     'username'=>'yyang',             'role'=>'staff',   'station_id'=>1253],
    'manager' => ['id'=>3,  'name'=>'Edgar Eslit',        'username'=>'Edgar',             'role'=>'manager', 'station_id'=>1253],
    'admin'   => ['id'=>4,  'name'=>'Kathrine Pepito',    'username'=>'pepito@gmail.com',  'role'=>'admin',   'station_id'=>1253],
];
if (!isset($users[$role])) die("Invalid role");
$_SESSION['user'] = $users[$role];
header('Location: index.php');
exit;
