<?php
$dirs = ['backend', 'public', 'partials', 'backend/api'];
$checks = [
  'check_table_exists',
  'save_user_draft',
  'get_user_draft',
  'ensure_drafts_table',
  'require_login',
  'sendPasswordResetOTP',
];

$results = [];
foreach ($checks as $fn) $results[$fn] = [];

foreach ($dirs as $dir) {
  $files = glob($dir . '/*.php');
  if (!$files) continue;
  foreach ($files as $f) {
    $content = @file_get_contents($f);
    if ($content === false) continue;
    foreach ($checks as $fn) {
      if (preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $content)) {
        $results[$fn][] = $f;
      }
    }
  }
}

echo "=== Duplicate Function Check ===\n";
foreach ($results as $fn => $files) {
  $count = count($files);
  $flag = $count > 1 ? ' *** DUPLICATE ***' : '';
  echo sprintf("%-30s %d definition(s)%s\n", $fn, $count, $flag);
  foreach ($files as $f) echo "    -> $f\n";
}
