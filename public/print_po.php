<?php
// Redirect all old print_po.php links to the new official PO print page
$id = (int)($_GET['id'] ?? 0);
header('Location: print_po_new.php' . ($id ? '?id=' . $id : ''));
exit;
