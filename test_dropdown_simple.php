<?php
require_once __DIR__ . '/public/db_connect.php';  $stations = [];
try {  $stmt = $pdo->query("SELECT id, name, location, region FROM stations ORDER BY name ASC");  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {  error_log("ERROR: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>  <title>Test Station Dropdown</title>  <style>  body { font-family: Arial; padding: 20px; }  .dropdown { position: relative; width: 400px; }  .dropdown-box { padding: 10px; border: 1px solid #ccc; cursor: pointer; }  .dropdown-list {  display: none;  position: absolute;  width: 100%;  border: 1px solid #ccc;  background: white;  max-height: 300px;  overflow-y: auto;  z-index: 1000;  }  .dropdown.open .dropdown-list { display: block; }  .option { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }  .option:hover { background: #f0f0f0; }  </style>
</head>
<body>  <h2>Simple Station Dropdown Test</h2>  <p>Stations loaded: <strong><?php echo count($stations); ?></strong></p>  <div class="dropdown" id="myDropdown">  <div class="dropdown-box" onclick="toggleDropdown()">  <span id="selected">Click to select station</span>  </div>  <div class="dropdown-list">  <div class="option" onclick="selectStation('', 'All Stations')">  All Stations (<?php echo count($stations); ?>)  </div>  <?php foreach ($stations as $station): ?>  <div class="option" onclick="selectStation(<?php echo $station['id']; ?>, '<?php echo addslashes($station['name']); ?>')">  <?php echo htmlspecialchars($station['name']); ?>  <?php if (!empty($station['region'])): ?>  - <?php echo htmlspecialchars($station['region']); ?>  <?php endif; ?>  </div>  <?php endforeach; ?>  </div>  </div>  <script>  function toggleDropdown() {  document.getElementById('myDropdown').classList.toggle('open');  }  function selectStation(id, name) {  document.getElementById('selected').textContent = name;  document.getElementById('myDropdown').classList.remove('open');  console.log('Selected:', id, name);  }  // Close when clicking outside  document.addEventListener('click', function(e) {  if (!e.target.closest('.dropdown')) {  document.getElementById('myDropdown').classList.remove('open');  }  });  console.log('Total stations in dropdown:', <?php echo count($stations); ?>);  </script>
</body>
</html>
