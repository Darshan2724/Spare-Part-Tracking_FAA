@echo off
echo ===================================================
echo       Updating SpareTrack Server Deployment
echo ===================================================

echo [1/5] Pulling latest changes from GitHub...
git pull origin main
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Git pull failed. Please check internet/Git status.
    pause
    exit /b %ERRORLEVEL%
)

echo [2/5] Building frontend assets...
call npm run build

echo [3/5] Running safe database migrations...
docker exec -t sparetrack-app php artisan migrate --force

echo [4/5] Clearing and optimizing application caches...
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

echo [5/5] Restarting Docker services...
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

echo ===================================================
echo       SpareTrack Server Successfully Updated!
echo ===================================================
pause
