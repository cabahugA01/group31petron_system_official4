<?php
require_once __DIR__ . '/db_connect.php';

// Fetch stations
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name, address, location, region FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("ERROR: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Module Config Stations</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { font-family: Arial; padding: 20px; }
        .am-combo { position: relative; width: 450px; }
        .am-combo-input { width: 100%; padding: 10px 36px 10px 13px; border: 1px solid #ddd; border-radius: 10px; cursor: pointer; }
        .am-combo-arrow { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); }
        .am-combo-dropdown { display: none; position: absolute; width: 100%; background: white; border: 1px solid #ddd; border-radius: 10px; max-height: 300px; overflow: hidden; flex-direction: column; margin-top: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .am-combo.open .am-combo-dropdown { display: flex; }
        .am-combo.open .am-combo-arrow { transform: translateY(-50%) rotate(180deg); }
        .am-combo-search { padding: 10px; border-bottom: 1px solid #eee; display: flex; gap: 8px; }
        .am-combo-search input { flex: 1; border: none; outline: none; }
        .am-combo-list { overflow-y: auto; flex: 1; }
        .am-combo-option { padding: 10px 14px; cursor: pointer; display: flex; gap: 8px; align-items: center; }
        .am-combo-option:hover { background: #f0f5ff; }
        .opt-icon { color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <h2>Module Configuration - Station Dropdown Test</h2>
    <p><strong>Stations Loaded:</strong> <?php echo count($stations); ?></p>
    
    <div class="am-combo" id="test_combo">
        <input type="text" class="am-combo-input" id="test_display" value="All Stations" readonly>
        <i class="fas fa-chevron-down am-combo-arrow"></i>
        <div class="am-combo-dropdown">
            <div class="am-combo-search">
                <i class="fas fa-search"></i>
                <input type="text" id="test_search" placeholder="Search station...">
            </div>
            <div class="am-combo-list" id="test_list">
                <div class="am-combo-option" data-value="" data-label="All Stations" style="font-style:italic;color:#888;">
                    All Stations (<?php echo count($stations); ?>)
                </div>
                <?php foreach ($stations as $st): ?>
                <div class="am-combo-option" data-value="<?php echo htmlspecialchars($st['name']); ?>" data-label="<?php echo htmlspecialchars($st['name']); ?>">
                    <i class="fas fa-building opt-icon"></i>
                    <span><?php echo htmlspecialchars($st['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        const combo = document.getElementById('test_combo');
        const display = document.getElementById('test_display');
        const search = document.getElementById('test_search');
        const list = document.getElementById('test_list');
        
        // Toggle dropdown
        display.addEventListener('click', () => {
            combo.classList.toggle('open');
            if (combo.classList.contains('open')) {
                search.focus();
            }
        });
        
        // Filter on search
        search.addEventListener('input', () => {
            const query = search.value.toLowerCase();
            list.querySelectorAll('.am-combo-option').forEach(opt => {
                if (!opt.dataset.value) {
                    opt.style.display = '';
                    return;
                }
                const match = !query || opt.dataset.label.toLowerCase().includes(query);
                opt.style.display = match ? '' : 'none';
            });
        });
        
        // Select option
        list.addEventListener('click', (e) => {
            const opt = e.target.closest('.am-combo-option');
            if (opt) {
                display.value = opt.dataset.label || 'All Stations';
                combo.classList.remove('open');
                console.log('Selected:', opt.dataset.value, opt.dataset.label);
            }
        });
        
        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!combo.contains(e.target)) {
                combo.classList.remove('open');
            }
        });
        
        console.log('Total stations:', <?php echo count($stations); ?>);
    </script>
</body>
</html>
