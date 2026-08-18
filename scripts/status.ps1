# ==============================================================================
# FAITH AUTOMATION - Server Status and Summary
# Usage: .\scripts\status.ps1
# ==============================================================================

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host " FAITH AUTOMATION (SpareTrack) - Server Status Dashboard              " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Host Resources
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notmatch "vEthernet|Loopback|Docker" -and $_.IPAddress -notmatch "^127\.|^169\.254\." } | Select-Object -First 1).IPAddress
$uptime = (Get-CimInstance Win32_OperatingSystem).LastBootUpTime
$disk = Get-PSDrive C

$freeGb = [math]::Round($disk.Free / 1073741824, 2)
$totalGb = [math]::Round(($disk.Used + $disk.Free) / 1073741824, 2)

Write-Host "Server Host Details:" -ForegroundColor Yellow
Write-Host "  -> LAN IP:        $lanIp" -ForegroundColor White
Write-Host "  -> Last Boot:     $uptime" -ForegroundColor White
Write-Host "  -> Disk (C: Free): $freeGb GB of $totalGb GB" -ForegroundColor White

Write-Host ""
Write-Host "Docker Containers:" -ForegroundColor Yellow
docker compose ps

Write-Host ""
Write-Host "Access Endpoints:" -ForegroundColor Yellow
Write-Host "  -> Management Web Dashboard: http://${lanIp}:8080" -ForegroundColor Cyan
Write-Host "  -> Android API Target:        http://${lanIp}:8080/api/v1" -ForegroundColor Cyan
Write-Host "  -> Reverb WebSocket Server:   http://${lanIp}:8085" -ForegroundColor Cyan
Write-Host "  -> Adminer DB Console:        http://${lanIp}:8088" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
