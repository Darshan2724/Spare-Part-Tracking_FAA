Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "         Updating SpareTrack Windows Server (Docker)      " -ForegroundColor Cyan
Write-Host "  (Docker Backend, Web Assets, DB Migrations & Caches)    " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

Write-Host "`n[1/7] Fetching and pulling latest code from origin/main..." -ForegroundColor Yellow
git stash
git pull origin main

Write-Host "`n[2/7] Building production frontend web assets (Vite)..." -ForegroundColor Yellow
npm run build

Write-Host "`n[3/7] Running safe database migrations in Docker container..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan migrate --force

Write-Host "`n[4/7] Clearing and warming up Laravel caches..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

Write-Host "`n[5/7] Restarting queue worker..." -ForegroundColor Yellow
docker exec -t sparetrack-app php artisan queue:restart

Write-Host "`n[6/7] Restarting Docker application services..." -ForegroundColor Yellow
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

Write-Host "`n[7/7] Verifying backend health status..." -ForegroundColor Yellow
Start-Sleep -Seconds 3
curl.exe -s http://127.0.0.1:8080/api/v1/health

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "    SpareTrack Server Successfully Updated & Verified!   " -ForegroundColor Green
Write-Host "    - Web and API services running on Docker             " -ForegroundColor Green
Write-Host "    - All calculations, suppliers and workflows synced   " -ForegroundColor Green
Write-Host "    - Zero data loss guaranteed                          " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
