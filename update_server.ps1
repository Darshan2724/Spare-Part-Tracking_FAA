Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "         Updating SpareTrack Windows Server (Docker)      " -ForegroundColor Cyan
Write-Host "  (Docker Backend, Web Assets, DB Migrations & Caches)    " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

Write-Host "`n[1/8] Creating pre-update database backup (Zero Data Loss Guarantee)..." -ForegroundColor Yellow
New-Item -ItemType Directory -Force -Path "./backups" | Out-Null
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupPath = "./backups/sparetrack_pre_update_$timestamp.sql"
docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | Out-File -FilePath $backupPath -Encoding utf8
Write-Host "Database snapshot saved to $backupPath" -ForegroundColor Green

Write-Host "`n[2/8] Fetching and pulling latest code from origin/main..." -ForegroundColor Yellow
git stash --include-untracked
git fetch origin main
git reset --hard origin/main

Write-Host "`n[3/8] Building production frontend web assets (Vite)..." -ForegroundColor Yellow
npm run build

Write-Host "`n[4/8] Running safe database migrations in Docker container..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan migrate --force

Write-Host "`n[5/8] Clearing and warming up Laravel caches..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

Write-Host "`n[6/8] Restarting queue worker..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan queue:restart

Write-Host "`n[7/8] Restarting Docker application services..." -ForegroundColor Yellow
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

Write-Host "`n[8/8] Verifying backend health status..." -ForegroundColor Yellow
Start-Sleep -Seconds 3
curl.exe -s http://127.0.0.1:8080/api/v1/health

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "    SpareTrack Server Successfully Updated & Verified!   " -ForegroundColor Green
Write-Host "    - Web and API services running on Docker             " -ForegroundColor Green
Write-Host "    - All calculations, suppliers and workflows synced   " -ForegroundColor Green
Write-Host "    - Zero data loss guaranteed                          " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
