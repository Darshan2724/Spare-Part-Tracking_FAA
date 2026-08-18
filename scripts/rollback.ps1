# ==============================================================================
# FAITH AUTOMATION - Production Rollback Script
# Usage: .\scripts\rollback.ps1 [-CommitHash <hash>] [-RestoreDatabase]
# ==============================================================================

param (
    [string]$CommitHash,
    [switch]$RestoreDatabase
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir

Write-Host "======================================================================" -ForegroundColor Red
Write-Host " FAITH AUTOMATION (SpareTrack) - Emergency Rollback Utility           " -ForegroundColor Red
Write-Host "======================================================================" -ForegroundColor Red

Push-Location $rootDir

if (-not $CommitHash) {
    Write-Host "Recent Git Commits:" -ForegroundColor Yellow
    git log -n 5 --oneline
    $CommitHash = Read-Host "Enter Commit Hash to revert to (or press Enter for HEAD~1)"
    if (-not $CommitHash) {
        $CommitHash = "HEAD~1"
    }
}

Write-Warning "Rolling back code to: $CommitHash"
$confirm = Read-Host "Type ROLLBACK to confirm"
if ($confirm -ne 'ROLLBACK') {
    Write-Host "Rollback cancelled." -ForegroundColor Yellow
    Pop-Location
    exit 0
}

# 1. Revert Code
git checkout $CommitHash
Write-Host "Code checked out at $CommitHash" -ForegroundColor Green

# 2. Optionally Restore Database
if ($RestoreDatabase) {
    $restoreScript = Join-Path $scriptDir "restore.ps1"
    if (Test-Path $restoreScript) {
        & $restoreScript
    }
}

# 3. Rebuild Containers
Write-Host "Rebuilding containers on rollback commit..." -ForegroundColor Yellow
docker compose up -d --build
docker exec sparetrack-app php artisan config:cache
docker exec sparetrack-app php artisan route:cache
docker exec sparetrack-app php artisan view:cache
docker exec sparetrack-app php artisan queue:restart

Pop-Location

Write-Host "Rollback completed. System restored." -ForegroundColor Green
