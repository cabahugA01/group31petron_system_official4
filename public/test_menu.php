<?php
$_SESSION['user'] = [  'id' => 3,  'name' => 'Edgar Eslit',  'username' => 'Edgar',  'role' => 'manager',  'station_id' => 1253
];
require_once __DIR__ . '/../backend/lib.php';
$role = 'manager';
require_once __DIR__ . '/../partials/rbac_menu.php';
echo "ROLE: " . role_key($_SESSION['user']['role']) . "\n";
foreach ($items as $item) {  echo "- " . $item['label'] . " (href: " . $item['href'] . ")\n";  if (!empty($item['sub_items'])) {  foreach ($item['sub_items'] as $sub) {  echo "  * " . $sub['label'] . " (href: " . $sub['href'] . ")\n";  }  }
}
