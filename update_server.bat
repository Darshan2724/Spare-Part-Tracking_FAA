@echo off
echo ===================================================
echo       Updating SpareTrack Server Deployment
echo ===================================================

echo [1/4] Pulling latest changes from GitHub...
git pull origin main
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Git pull failed. Please check internet/Git status.
    pause
    exit /b %ERRORLEVEL%
)

echo [2/4] Running database migrations...
docker exec -t sparetrack-app php artisan migrate --force

echo [3/4] Clearing application caches...
docker exec -t sparetrack-app php artisan optimize:clear

echo [4/4] Restarting Docker services...
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

echo ===================================================
echo       SpareTrack Server Successfully Updated!
echo ===================================================
pause
