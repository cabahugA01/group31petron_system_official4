<?php
$hash = '$2y$10$BBvzfSYWrEP5aqAxpUyMYe8Ss72YgyLZDGyBVdweq9tBA0qimuXBm';
$words = ['Edgar', 'manager', 'password', '123456', 'admin', 'manager123', 'petron', 'petron123', 'Eslit'];
foreach ($words as $w) {
    if (password_verify($w, $hash)) {
        echo "MATCH FOUND: '$w'\n";
        exit;
    }
}
echo "No match found.\n";
