<?php
require_once __DIR__ . '/public/db_connect.php';  echo "<h2>Station Test</h2>";  try {  $stmt = $pdo->query("SELECT id, name, address, location, region FROM stations ORDER BY name ASC");  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "<p><strong>Total Stations Found: " . count($stations) . "</strong></p>";  if (empty($stations)) {  echo "<p style='color:red;'>ERROR: No stations in database!</p>";  } else {  echo "<ol>";  foreach ($stations as $station) {  echo "<li>";  echo "<strong>" . htmlspecialchars($station['name']) . "</strong><br>";  echo "ID: " . $station['id'] . "<br>";  if (!empty($station['location'])) echo "Location: " . htmlspecialchars($station['location']) . "<br>";  if (!empty($station['region'])) echo "Region: " . htmlspecialchars($station['region']) . "<br>";  echo "</li>";  }  echo "</ol>";  }
} catch (Exception $e) {  echo "<p style='color:red;'>ERROR: " . $e->getMessage() . "</p>";
}
?>
