path = r'c:\xampp\htdocs\group31petron_system_official4\partials\header.php'

with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Let's insert the DB fetching code right before '<!DOCTYPE html>'
db_fetch_code = """
// --- FETCH SYSTEM SETTINGS (GLOBAL & STATION-SPECIFIC) ---
$station_settings = [];
try {
    $stmt0 = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = 0");
    $stmt0->execute();
    while ($row = $stmt0->fetch(PDO::FETCH_ASSOC)) {
        $station_settings[$row['setting_key']] = $row['setting_value'];
    }
    if ($myStationId > 0) {
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = ?");
        $stmtS->execute([$myStationId]);
        while ($row = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $station_settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
} catch (Exception $e) {}

$theme_primary_color = $station_settings['primary_color'] ?? '#002F6C';
$theme_button_color  = $station_settings['button_color'] ?? '#002F6C';
$theme_sidebar_color = $station_settings['sidebar_color'] ?? '#00264D';
$theme_font_scale    = $station_settings['font_scale_accessibility'] ?? '100';
$theme_high_contrast = (isset($station_settings['high_contrast']) && ($station_settings['high_contrast'] === '1' || $station_settings['high_contrast'] === 'true'));
"""

# Let's check if db_fetch_code is already there to avoid duplicate insertions
if "FETCH SYSTEM SETTINGS (GLOBAL & STATION-SPECIFIC)" not in content:
    doctype_pos = content.find('<!DOCTYPE html>')
    if doctype_pos != -1:
        # insert before doctype within a php block
        # we need to find the preceding '?>' or just put it in a separate php block
        content = content[:doctype_pos] + "<?php " + db_fetch_code + " ?>\n" + content[doctype_pos:]

# Now let's insert custom style variables inside the head tag or in the style tag
# We can search for the '<style>' tag and insert our dynamic CSS properties in :root
style_pos = content.find('<style>')
if style_pos != -1:
    insert_pos = style_pos + len('<style>')
    dynamic_css = """
    :root {
        --petron-blue: <?php echo htmlspecialchars($theme_primary_color); ?> !important;
        --primary: <?php echo htmlspecialchars($theme_primary_color); ?> !important;
        --sidebar-bg: <?php echo htmlspecialchars($theme_sidebar_color); ?> !important;
        font-size: <?php echo htmlspecialchars($theme_font_scale); ?>% !important;
    }
    button, .btn, .ss-btn-primary {
        background-color: <?php echo htmlspecialchars($theme_button_color); ?> !important;
    }
    <?php if ($theme_high_contrast): ?>
    body, html, div, p, span, table, td, th, a, button, input, select {
        filter: contrast(1.15) !important;
    }
    <?php endif; ?>
    """
    content = content[:insert_pos] + dynamic_css + content[insert_pos:]

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("partials/header.php updated with dynamic station settings and styles!")
