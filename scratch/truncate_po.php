<?php
$file = __DIR__ . '/../public/admin_purchase_orders.php';
$lines = file($file);

// Keep lines 1 to 497 (index 0 to 496)
$keep_lines = array_slice($lines, 0, 497);

$new_lines = [
    "include __DIR__ . '/../partials/header.php';\n",
    "?>\n",
    "<?php include __DIR__ . '/admin_po_css.php'; ?>\n",
    "<?php include __DIR__ . '/admin_po_body.php'; ?>\n",
    "<?php include __DIR__ . '/admin_po_modals.php'; ?>\n"
];

$output = implode("", $keep_lines) . implode("", $new_lines);
file_put_contents($file, $output);
echo "Truncated and appended successfully.\n";
