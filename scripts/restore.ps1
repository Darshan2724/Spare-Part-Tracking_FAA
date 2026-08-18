# ==============================================================================
# FAITH AUTOMATION - PostgreSQL Database Restore Script
# Usage: .\scripts\restore.ps1 [-BackupFile "path\to\backup.sql"]
# ==============================================================================

param (
    [string]$BackupFile
)

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir
$backupDir = Join-Path $rootDir "backups"

Write-Host "======================================================================" -ForegroundColor Red
Write-Host " FAITH AUTOMATION - PostgreSQL Database Restore Utility               " -ForegroundColor Red
Write-Host "======================================================================" -ForegroundColor Red

# 1. Select Backup File if Not Provided
if (-not $BackupFile) {
    $backups = Get-ChildItem -Path $backupDir -Filter "*.sql*" | Sort-Object LastWriteTime -Descending
    if (-not $backups -or $backups.Count -eq 0) {
        Write-Error "No backup files found in $backupDir."
        exit 1
    }

    Write-Host "Available Backups:" -ForegroundColor Yellow
    for ($i = 0; $i -lt $backups.Count; $i++) {
        $len = $backups[$i].Length
        $sizeKb = [math]::Round($len / 1024, 2)
        $timeStr = $backups[$i].LastWriteTime
        Write-Host "  [$($i + 1)] $($backups[$i].Name) ($sizeKb KB - $timeStr)" -ForegroundColor White
    }

    $selection = Read-Host "Select backup number to restore (1-$($backups.Count))"
    $index = [int]$selection - 1
    if ($index -lt 0 -or $index -ge $backups.Count) {
        Write-Error "Invalid selection."
        exit 1
    }
    $BackupFile = $backups[$index].FullName
}

if (-not (Test-Path $BackupFile)) {
    Write-Error "Specified backup file does not exist: $BackupFile"
    exit 1
}

Write-Host ""
Write-Warning "WARNING: Restoring will overwrite the current PostgreSQL database with:"
Write-Warning "  $BackupFile"
$confirm = Read-Host "Type RESTORE to confirm and proceed"
if ($confirm -ne 'RESTORE') {
    Write-Host "Restore operation cancelled by user." -ForegroundColor Yellow
    exit 0
}

# 2. Verify Container Status
$pgStatus = docker inspect -f '{{.State.Running}}' sparetrack-postgres 2>$null
if ($pgStatus -ne 'true') {
    Write-Error "PostgreSQL container 'sparetrack-postgres' is not running."
    exit 1
}

# 3. Execute Restore
Write-Host "Restoring database from $BackupFile..." -ForegroundColor Yellow
Get-Content $BackupFile | docker exec -i sparetrack-postgres psql -U sparetrack_user -d sparetrack

if ($LASTEXITCODE -eq 0) {
    Write-Host "Database successfully restored from $BackupFile!" -ForegroundColor Green
} else {
    Write-Error "Database restore reported errors. Review terminal output above."
    exit 1
}
