# ==============================================================================
# FAITH AUTOMATION - PostgreSQL Automated Database Backup Script
# Usage: .\scripts\backup.ps1 [-RetentionDays 14]
# ==============================================================================

param (
    [int]$RetentionDays = 14
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir
$backupDir = Join-Path $rootDir "backups"

New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$backupFile = Join-Path $backupDir "sparetrack_db_backup_$timestamp.sql"

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host " FAITH AUTOMATION - PostgreSQL Database Backup                        " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "Starting backup at $(Get-Date)..." -ForegroundColor Yellow

# 1. Verify PostgreSQL Container is Running
$pgStatus = docker inspect -f '{{.State.Running}}' sparetrack-postgres 2>$null
if ($pgStatus -ne 'true') {
    Write-Error "Container 'sparetrack-postgres' is not running. Cannot execute backup."
    exit 1
}

# 2. Execute pg_dump inside container
Write-Host "Dumping PostgreSQL database 'sparetrack'..." -ForegroundColor Yellow
$dumpCmd = "docker exec sparetrack-postgres pg_dump -U sparetrack_user -d sparetrack --clean --if-exists"

Invoke-Expression $dumpCmd | Out-File -FilePath $backupFile -Encoding utf8

if ((Test-Path $backupFile) -and (Get-Item $backupFile).Length -gt 100) {
    $len = (Get-Item $backupFile).Length
    $sizeKb = [math]::Round($len / 1024, 2)
    Write-Host "Backup completed successfully!" -ForegroundColor Green
    Write-Host "  -> File: $backupFile ($sizeKb KB)" -ForegroundColor Green
} else {
    Write-Error "Database dump failed or produced an empty backup file."
    exit 1
}

# 3. Clean up old backups based on retention policy
Write-Host "Pruning backups older than $RetentionDays days..." -ForegroundColor Gray
$cutoffDate = (Get-Date).AddDays(-$RetentionDays)
Get-ChildItem -Path $backupDir -Filter "*.sql*" | Where-Object { $_.LastWriteTime -lt $cutoffDate } | ForEach-Object {
    Write-Host "  -> Removing expired backup: $($_.Name)" -ForegroundColor DarkGray
    Remove-Item $_.FullName -Force
}

Write-Host "Backup process finished." -ForegroundColor Cyan
