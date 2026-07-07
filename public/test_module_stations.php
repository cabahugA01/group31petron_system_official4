<?php
// Test if stations are loading in module_configuration
require_once __DIR__ . '/db_connect.php';  echo "<h2>Station Loading Test</h2>";  // Fetch all stations
$stations = [];
try {  $stmt = $pdo->query("SELECT id, name, address, location, region, status FROM stations ORDER BY name ASC");  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "<p>Successfully loaded <strong>" . count($stations) . " stations</strong> from database</p>";
} catch (Exception $e) {  echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";  $stations = [];
}  // Display first 10 stations
if (!empty($stations)) {  echo "<h3>First 10 Stations:</h3>";  echo "<ul>";  foreach (array_slice($stations, 0, 10) as $st) {  echo "<li>" . htmlspecialchars($st['name']) . " (ID: " . $st['id'] . ")</li>";  }  echo "</ul>";  // Show the dropdown HTML that would be generated  echo "<h3>Dropdown HTML Preview:</h3>";  echo "<div style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 300px; overflow-y: auto;'>";  echo "<pre>";  echo htmlspecialchars('<div class="am-combo-option" data-value="" data-label="All Stations">All Stations</div>') . "\n";  foreach (array_slice($stations, 0, 5) as $st) {  $html = '<div class="am-combo-option" data-value="' . htmlspecialchars($st['name']) . '" data-label="' . htmlspecialchars($st['name']) . '">';  $html .= '<i class="fas fa-building opt-icon"></i> ' . htmlspecialchars($st['name']);  $html .= '</div>';  echo htmlspecialchars($html) . "\n";  }  echo "... (" . (count($stations) - 5) . " more stations)";  echo "</pre>";  echo "</div>";
}
?>
