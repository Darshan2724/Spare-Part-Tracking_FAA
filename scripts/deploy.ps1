# ==============================================================================
# FAITH AUTOMATION - Production Deployment and Update Pipeline
# Usage: .\scripts\deploy.ps1 [-SkipBackup] [-SkipPull]
# ==============================================================================

param (
    [switch]$SkipBackup,
    [switch]$SkipPull
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host " FAITH AUTOMATION (SpareTrack) - Production Deployment and Update     " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Pre-Deployment Database Backup
if (-not $SkipBackup) {
    Write-Host "[1/6] Performing pre-deployment database backup..." -ForegroundColor Yellow
    $backupScript = Join-Path $scriptDir "backup.ps1"
    if (Test-Path $backupScript) {
        & $backupScript -RetentionDays 30
    }
} else {
    Write-Host "[1/6] Skipping pre-deployment backup (-SkipBackup specified)..." -ForegroundColor Gray
}

# 2. Pull Latest Git Commits
if (-not $SkipPull) {
    Write-Host "[2/6] Pulling approved changes from Git repository..." -ForegroundColor Yellow
    Push-Location $rootDir
    try {
        git pull --ff-only
        $shortHash = git rev-parse --short HEAD
        Write-Host "  -> Repository updated to commit: $shortHash" -ForegroundColor Green
    } catch {
        Write-Warning "Git pull failed or repository is not connected to a remote. Proceeding with local code..."
    }
    Pop-Location
} else {
    Write-Host "[2/6] Skipping Git pull (-SkipPull specified)..." -ForegroundColor Gray
}

# 3. Build Web Assets
Write-Host "[3/6] Compiling production frontend assets..." -ForegroundColor Yellow
Push-Location $rootDir
try {
    npm run build
    Write-Host "  -> Vite assets built successfully." -ForegroundColor Green
} catch {
    Write-Warning "npm run build encountered an issue. Review output."
}
Pop-Location

# 4. Rebuild and Restart Docker Containers
Write-Host "[4/6] Starting / Rebuilding Docker Services..." -ForegroundColor Yellow
Push-Location $rootDir
docker compose up -d --build --remove-orphans
Pop-Location

# 5. Execute Database Migrations and Clear Cache
Write-Host "[5/6] Running Database Migrations and Clearing Cache..." -ForegroundColor Yellow
Start-Sleep -Seconds 5

docker exec sparetrack-app php artisan migrate --force
docker exec sparetrack-app php artisan config:cache
docker exec sparetrack-app php artisan route:cache
docker exec sparetrack-app php artisan view:cache
docker exec sparetrack-app php artisan queue:restart

Write-Host "  -> Migrations and cache refreshed." -ForegroundColor Green

# 6. Run Health Diagnostic Check
Write-Host "[6/6] Verifying System Health..." -ForegroundColor Yellow
$healthScript = Join-Path $scriptDir "health.ps1"
if (Test-Path $healthScript) {
    & $healthScript
}

Write-Host "======================================================================" -ForegroundColor Green
Write-Host " Deployment Complete! Existing Android APKs and Web users are now      " -ForegroundColor Green
Write-Host " using the latest backend version without reinstalling the APK!        " -ForegroundColor Green
Write-Host "======================================================================" -ForegroundColor Green
