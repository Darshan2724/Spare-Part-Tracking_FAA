# ==============================================================================
# FAITH AUTOMATION - Windows 11 On-Premise Server Setup Script
# Run as Administrator in PowerShell: .\scripts\setup_server.ps1
# ==============================================================================

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host " FAITH AUTOMATION (SpareTrack) - Windows 11 Server Setup and Init     " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

# 1. Verify Administrative Privileges
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Please run this PowerShell script as Administrator to configure firewall rules."
}

# 2. Resolve Root Project Directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$rootDir = Split-Path -Parent $scriptDir

Write-Host "[1/6] Initializing directories..." -ForegroundColor Yellow
$backupDir = Join-Path $rootDir "backups"
$logsDir = Join-Path $rootDir "logs"
$deployDir = Join-Path $rootDir "deploy"

New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
Write-Host "  -> Directories created: backups/, logs/, deploy/" -ForegroundColor Green

# 3. Check Docker Desktop Installation
Write-Host "[2/6] Verifying Docker Desktop..." -ForegroundColor Yellow
try {
    $dockerVersion = docker --version
    Write-Host "  -> Docker detected: $dockerVersion" -ForegroundColor Green
    $dockerInfo = docker info 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Docker Desktop is installed but the Docker daemon is NOT running. Please start Docker Desktop."
        exit 1
    }
} catch {
    Write-Error "Docker is not installed or not in system PATH. Please install Docker Desktop for Windows with WSL2 backend."
    exit 1
}

# 4. Configure Windows Firewall Rules for Factory LAN Access
Write-Host "[3/6] Configuring Windows Firewall for Factory LAN (Cross-Subnet Support)..." -ForegroundColor Yellow
if ($isAdmin) {
    # Remove existing conflicting rules if present
    netsh advfirewall firewall delete rule name="SpareTrack Web and API (Port 8080)" 2>&1 | Out-Null
    netsh advfirewall firewall delete rule name="SpareTrack Reverb WebSockets (Port 8085)" 2>&1 | Out-Null
    netsh advfirewall firewall delete rule name="SpareTrack Adminer DB Console (Port 8088)" 2>&1 | Out-Null

    # Port 8080: Web Application and REST API (Cross-Subnet Support)
    netsh advfirewall firewall add rule name="SpareTrack Web and API (Port 8080)" dir=in action=allow protocol=TCP localport=8080 profile=any remoteip=any | Out-Null
    # Port 8085: Laravel Reverb WebSockets (Cross-Subnet Support)
    netsh advfirewall firewall add rule name="SpareTrack Reverb WebSockets (Port 8085)" dir=in action=allow protocol=TCP localport=8085 profile=any remoteip=any | Out-Null
    # Port 8088: Adminer Database Admin (Restricted / Internal LAN)
    netsh advfirewall firewall add rule name="SpareTrack Adminer DB Console (Port 8088)" dir=in action=allow protocol=TCP localport=8088 profile=any remoteip=any | Out-Null
    Write-Host "  -> Windows Firewall rules configured for ports: 8080 (Web/API), 8085 (WebSockets), 8088 (Adminer) [Cross-Subnet Enabled]" -ForegroundColor Green
} else {
    Write-Host "  -> Skipped firewall configuration (requires Admin rights)." -ForegroundColor Gray
}

# 5. Environment File Setup
Write-Host "[4/6] Checking production environment file..." -ForegroundColor Yellow
$envPath = Join-Path $rootDir ".env"
$envExamplePath = Join-Path $rootDir ".env.production.example"
if (-not (Test-Path $envPath)) {
    if (Test-Path $envExamplePath) {
        Copy-Item $envExamplePath $envPath
        Write-Host "  -> Created .env from .env.production.example" -ForegroundColor Green
    } else {
        Write-Warning "  -> .env.production.example not found."
    }
} else {
    Write-Host "  -> Existing .env found." -ForegroundColor Green
}

# 6. Detect Host LAN IP
Write-Host "[5/6] Detecting Server LAN IPv4 Address..." -ForegroundColor Yellow
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notmatch "vEthernet|Loopback|Docker" -and $_.IPAddress -notmatch "^127\.|^169\.254\." } | Select-Object -First 1).IPAddress

if ($lanIp) {
    Write-Host "  -> Server Local LAN IP: $lanIp" -ForegroundColor Cyan
    Write-Host "  -> Web Application URL: http://$lanIp:8080" -ForegroundColor Cyan
    Write-Host "  -> Mobile API Base URL: http://$lanIp:8080/api/v1" -ForegroundColor Cyan
    Write-Host "  -> Adminer DB Console:  http://$lanIp:8088" -ForegroundColor Cyan
} else {
    Write-Host "  -> Could not auto-detect LAN IP. Use 'ipconfig' to verify." -ForegroundColor Yellow
}

Write-Host "======================================================================" -ForegroundColor Green
Write-Host " Setup Complete! You can now start the server with:                   " -ForegroundColor Green
Write-Host "   .\scripts\deploy.ps1                                               " -ForegroundColor White
Write-Host "======================================================================" -ForegroundColor Green
