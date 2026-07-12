$s = $null
Invoke-WebRequest "http://localhost/group31petron_system_official4/public/autologin_role.php?role=manager" -SessionVariable "s" -MaximumRedirection 5 | Out-Null
$r = Invoke-WebRequest "http://localhost/group31petron_system_official4/public/manager_stock_request_review.php" -WebSession $s -MaximumRedirection 5

Write-Host ("Status: " + $r.StatusCode)
Write-Host ("Page length: " + $r.Content.Length + " chars")

if ($r.Content -match "Purchase Request") {
    Write-Host "TITLE OK - Found 'Purchase Request'"
} else {
    Write-Host "WARNING - Title not found"
}

if ($r.Content -match "Fatal error|Parse error") {
    Write-Host "PHP ERROR FOUND"
} else {
    Write-Host "NO PHP ERRORS"
}

# Check tabs
foreach ($tab in @("Pending Requests", "Waiting Delivery", "Pending Stock-In", "Completed")) {
    if ($r.Content -match $tab) {
        Write-Host ("TAB OK: " + $tab)
    } else {
        Write-Host ("TAB MISSING: " + $tab)
    }
}
