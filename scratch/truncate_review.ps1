$f = "c:/xampp/htdocs/group31petron_system_official4/public/manager_stock_request_review.php"
$lines = Get-Content $f
$lines[0..1149] | Set-Content $f -Encoding UTF8
Write-Host "Done. Saved $($lines[0..1149].Count) lines."
