<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    $stmt = $pdo->query("SELECT id, transaction_id, status, validated_by, validated_at, reject_reason FROM fuel_transactions ORDER BY id DESC LIMIT 5");
    echo "Transactions:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        echo " - ID: {$tx['id']} | TxnID: {$tx['transaction_id']} | Status: {$tx['status']} | ValBy: {$tx['validated_by']} | ValAt: {$tx['validated_at']} | Reason: {$tx['reject_reason']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
