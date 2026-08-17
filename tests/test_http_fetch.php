<?php
// Isolated check via CURL/HTTP to localhost
$ch = curl_init('http://localhost/group31petron_system_official4/backend/get_transaction_details.php?type=merchandise_transactions&id=1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Mock cookie / login
curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=test_session');
$res = curl_exec($ch);
curl_close($ch);

echo "HTTP Response length: " . strlen($res) . "\n";
$data = json_decode($res, true);
echo "JSON Parse ok: " . ($data ? 'YES' : 'NO') . "\n";
if ($data) {
    echo "Keys: " . implode(', ', array_keys($data)) . "\n";
}
