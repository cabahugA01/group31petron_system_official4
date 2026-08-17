<?php
$apiFiles = glob('backend/api/*.php');
foreach ($apiFiles as $f) {
    $c = file_get_contents($f);
    $hasMain = (
        strpos($c, "require_once __DIR__ . '/../lib.php'") !== false ||
        strpos($c, "require_once('../lib.php')") !== false ||
        strpos($c, "require '../lib.php'") !== false
    );
    $hasApiLib = (
        strpos($c, "require_once __DIR__ . '/lib.php'") !== false ||
        strpos($c, "require_once 'lib.php'") !== false ||
        strpos($c, "require 'lib.php'") !== false ||
        strpos($c, "require_once('./lib.php')") !== false
    );
    $usesRequireLogin = strpos($c, 'require_login()') !== false;
    $label = '';
    if ($usesRequireLogin && $hasApiLib && !$hasMain) $label = ' << USES OLD LIB - NO TIMEOUT';
    elseif ($usesRequireLogin && $hasMain)             $label = ' OK (uses main lib)';
    elseif (!$usesRequireLogin)                       $label = ' (no auth)';
    echo sprintf("%-55s main=%s api=%s login=%s%s\n",
        basename($f),
        $hasMain   ? 'Y' : 'N',
        $hasApiLib ? 'Y' : 'N',
        $usesRequireLogin ? 'Y' : 'N',
        $label
    );
}
