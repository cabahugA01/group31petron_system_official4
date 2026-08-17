<?php
/**
 * Prepend PHP auth guard to legacy HTML files that start with <!DOCTYPE>
 */
$dir = __DIR__ . '/../public';

$html_first_files = [
    'fuel_inventory_management.php',
    'fuel_reconciliation_manager.php',
    'fuel_super_admin_oversight.php',
];

$guard = <<<'PHPGUARD'
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();
?>

PHPGUARD;

foreach ($html_first_files as $fname) {
    $fpath = $dir . '/' . $fname;
    if (!file_exists($fpath)) { echo "NOT FOUND: $fname\n"; continue; }

    $content = file_get_contents($fpath);

    // Skip if already guarded
    if (strpos($content, 'require_login') !== false) {
        echo "ALREADY GUARDED: $fname\n"; continue;
    }

    // Remove BOM if present
    $bom = "\xEF\xBB\xBF";
    if (substr($content, 0, 3) === $bom) {
        $content = substr($content, 3);
    }

    file_put_contents($fpath, $guard . $content);
    echo "GUARDED (prepend): $fname\n";
}

echo "Done.\n";
