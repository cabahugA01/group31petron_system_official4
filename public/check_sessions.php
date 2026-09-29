<?php
header('Content-Type: application/json');

$tmpDir = 'C:/xampp/tmp';
$sessions = [];

if (is_dir($tmpDir)) {
    foreach (scandir($tmpDir) as $f) {
        if (strpos($f, 'sess_') === 0) {
            $path = $tmpDir . '/' . $f;
            $data = file_get_contents($path);
            if (stripos($data, 'larosa') !== false || stripos($data, 'kaloy') !== false || stripos($data, 'kauswagan') !== false || stripos($data, 'user') !== false) {
                $sessions[$f] = [
                    'mtime' => date('Y-m-d H:i:s', filemtime($path)),
                    'content' => substr($data, 0, 1000)
                ];
            }
        }
    }
}

echo json_encode($sessions, JSON_PRETTY_PRINT);
