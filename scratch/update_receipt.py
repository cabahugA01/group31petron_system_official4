path = r'c:\xampp\htdocs\group31petron_system_official4\public\receipt.php'

with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

target = "$logo = '/group31petron_system_official4/assets/img/Petron Logo.png';"
replacement = """// Logo path - try database first
$logo = '/group31petron_system_official4/assets/img/Petron Logo.png';
try {
    $myStationId = 0;
    if (isset($sale['station_id'])) {
        $myStationId = (int)$sale['station_id'];
    } elseif (isset($jo['station_id'])) {
        $myStationId = (int)$jo['station_id'];
    } elseif (isset($txn['station_id'])) {
        $myStationId = (int)$txn['station_id'];
    }
    
    $logo_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_logo' AND station_id = ?");
    $logo_stmt->execute([$myStationId]);
    $db_logo = $logo_stmt->fetchColumn();
    
    if (!$db_logo && $myStationId > 0) {
        $logo_stmt->execute([0]);
        $db_logo = $logo_stmt->fetchColumn();
    }
    
    if ($db_logo) {
        $logo = '/group31petron_system_official4/' . $db_logo;
    }
} catch (Exception $e) {}"""

if target in content:
    content = content.replace(target, replacement)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("receipt.php updated successfully.")
else:
    print("Error: Target not found in receipt.php")
