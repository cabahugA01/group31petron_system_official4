$file = 'c:\xampp\htdocs\group31petron_system_official4\public\manager_stock_request_review.php'
$all = [System.IO.File]::ReadAllLines($file)
# Keep lines 0-698 (indexes) and 969 onwards (0-indexed), removing the orphaned block
$kept = $all[0..698] + $all[969..($all.Length - 1)]
[System.IO.File]::WriteAllLines($file, $kept, [System.Text.Encoding]::UTF8)
Write-Host "Done. Lines remaining: $($kept.Length)"
