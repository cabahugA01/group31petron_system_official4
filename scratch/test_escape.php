<?php
$data = [
    'voidId'   => 'VOID-4',
    'txnId'    => 'MERCH2026062616435012539615',
    'customer' => 'Judy',
    'fields_changed' => ['voided_items' => [['product_name' => 'Oil Filter C-series']]]
];

$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$escaped = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');

echo "HTML attribute:\n";
echo "onclick=\"openVoidModal(" . $escaped . ")\"\n";
