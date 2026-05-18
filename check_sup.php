<?php
require 'public/db_connect.php';
print_r($pdo->query('SHOW TABLES LIKE "%supplier%"')->fetchAll(PDO::FETCH_ASSOC));
print_r($pdo->query('SELECT * FROM suppliers LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));
