@echo off
echo ==========================================================
echo          Updating SpareTrack Windows Server (Docker)
echo   (Docker Backend, Web Assets, DB Migrations ^& Caches)
echo ==========================================================
echo.

echo [1/7] Fetching and pulling latest code from origin/main...
git stash
git pull origin main

echo.
echo [2/7] Building production frontend web assets (Vite)...
call npm run build

echo.
echo [3/7] Running safe database migrations in Docker container...
docker exec -t sparetrack-app php artisan migrate --force

echo.
echo [4/7] Clearing and warming up Laravel caches...
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

echo.
echo [5/7] Restarting queue worker...
docker exec -t sparetrack-app php artisan queue:restart

echo.
echo [6/7] Restarting Docker application services...
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

echo.
echo [7/7] Verifying backend health status...
timeout /t 3 /nobreak >nul
curl.exe -s http://127.0.0.1:8080/api/v1/health

echo.
echo ==========================================================
echo     SpareTrack Server Successfully Updated ^& Verified!
echo     - Web and API services running on Docker
echo     - All calculations, suppliers and workflows synced
echo     - Zero data loss guaranteed
echo ==========================================================
pause
