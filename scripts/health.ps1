# ==============================================================================
# FAITH AUTOMATION - System Health and Diagnostics Monitor
# Usage: .\scripts\health.ps1
# ==============================================================================

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host " FAITH AUTOMATION (SpareTrack) - System Health Check                  " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Check Container Health
Write-Host "[1/4] Docker Containers Status:" -ForegroundColor Yellow
$containers = @("sparetrack-app", "sparetrack-nginx", "sparetrack-postgres", "sparetrack-redis", "sparetrack-reverb", "sparetrack-worker", "sparetrack-adminer")

$allContainersUp = $true
foreach ($c in $containers) {
    $running = docker inspect -f "{{.State.Running}}" $c 2>$null
    $health = docker inspect -f "{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}" $c 2>$null
    if ($running -eq 'true') {
        $healthMsg = ""
        if ($health -ne 'none') {
            $healthMsg = " (Health: $health)"
        }
        Write-Host "  [OK] $c is RUNNING$healthMsg" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] $c is STOPPED or MISSING" -ForegroundColor Red
        $allContainersUp = $false
    }
}

# 2. Check HTTP API Endpoint
Write-Host "[2/4] Testing Laravel REST API and Database Health..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "http://localhost:8080/api/v1/health" -Method Get -TimeoutSec 5 -ErrorAction Stop
    if ($response.status -eq 'healthy') {
        Write-Host "  [OK] API Status: HEALTHY" -ForegroundColor Green
        Write-Host "     -> DB Engine: $($response.checks.database.driver) ($($response.checks.database.version)) - Status: $($response.checks.database.status)" -ForegroundColor Gray
        Write-Host "     -> Storage: Status: $($response.checks.storage.status) (Writable: $($response.checks.storage.writable))" -ForegroundColor Gray
        Write-Host "     -> Server Time: $($response.checks.application.server_time)" -ForegroundColor Gray
    } else {
        Write-Host "  [WARN] API Status: $($response.status)" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  [FAIL] Failed to reach API health endpoint: $($_.Exception.Message)" -ForegroundColor Red
}

# 3. Check WebSocket and Admin Ports
Write-Host "[3/4] Testing Network Service Ports..." -ForegroundColor Yellow

function Test-PortAvailability ($port, $serviceName) {
    $conn = Test-NetConnection -ComputerName "localhost" -Port $port -InformationLevel Quiet 2>$null
    if ($conn) {
        Write-Host "  [OK] Port $port ($serviceName) is OPEN" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] Port $port ($serviceName) is CLOSED" -ForegroundColor Red
    }
}

Test-PortAvailability 8080 "Web UI and REST API"
Test-PortAvailability 8085 "Laravel Reverb WebSockets"
Test-PortAvailability 8088 "Adminer Database Console"

# 4. Show Local LAN IP
Write-Host "[4/4] Network Access Points:" -ForegroundColor Yellow
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notmatch "vEthernet|Loopback|Docker" -and $_.IPAddress -notmatch "^127\.|^169\.254\." } | Select-Object -First 1).IPAddress
if ($lanIp) {
    Write-Host "  -> Web Application:     http://${lanIp}:8080" -ForegroundColor Cyan
    Write-Host "  -> Android API Target:  http://${lanIp}:8080/api/v1" -ForegroundColor Cyan
    Write-Host "  -> Adminer DB Admin:    http://${lanIp}:8088" -ForegroundColor Cyan
}

Write-Host "======================================================================" -ForegroundColor Cyan
