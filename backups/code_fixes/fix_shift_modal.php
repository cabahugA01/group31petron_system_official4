<?php
$file = __DIR__ . '/public/transactions_shift.php';
$content = file_get_contents($file);

// Find the first </script> after the JS functions
// Then find where the real <style> block begins (with .scard {)
// Remove everything between them

$scriptClose = '</script>';
$styleStart  = '<style>';
$scardMarker = '.scard {';

// Find position of first </script>
$pos1 = strpos($content, $scriptClose);
if ($pos1 === false) { die('Cannot find </script>'); }

// Find position of .scard { (the real CSS start)
$pos2 = strpos($content, $scardMarker, $pos1);
if ($pos2 === false) { die('Cannot find .scard {'); }

// Find the <style> tag just before .scard {
$pos3 = strrpos(substr($content, 0, $pos2), $styleStart);
if ($pos3 === false) { die('Cannot find <style> before .scard'); }

// Build clean content: everything up to and including first </script>
// then newline + <style> block starting from $pos3
$before = substr($content, 0, $pos1 + strlen($scriptClose));
$after  = substr($content, $pos3);

$clean = $before . "\r\n\r\n" . $after;

file_put_contents($file, $clean);
echo 'Done. Lines: ' . substr_count($clean, "\n") . PHP_EOL;
echo 'Removed chars: ' . (strlen($content) - strlen($clean)) . PHP_EOL;
