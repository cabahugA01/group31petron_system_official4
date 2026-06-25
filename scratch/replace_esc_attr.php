<?php
$file = __DIR__ . '/../public/admin_fuel_adjustments_oversight.php';
$content = file_get_contents($file);
$old = "esc_attr(\$adj['fuel_type'])";
$new = "htmlspecialchars(\$adj['fuel_type'], ENT_QUOTES, 'UTF-8')";
$content = str_replace($old, $new, $content);

$old2 = "esc_attr(\$diff_str)";
$new2 = "htmlspecialchars(\$diff_str, ENT_QUOTES, 'UTF-8')";
$content = str_replace($old2, $new2, $content);

file_put_contents($file, $content);
echo "Successfully replaced esc_attr in admin_fuel_adjustments_oversight.php\n";
