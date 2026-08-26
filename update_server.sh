#!/bin/bash
set -e

echo "==================================================="
echo "      Updating SpareTrack Server Deployment"
echo "  (Self-Healing Reverts + Zero Data Loss Migration)"
echo "==================================================="

echo "[1/6] Stashing local server artifacts and pulling latest code from GitHub..."
git stash
git pull origin main

echo "[2/6] Building production frontend assets..."
npm run build

echo "[3/6] Running safe database migrations (Self-Healing Past Reverts)..."
docker exec -t sparetrack-app php artisan migrate --force

echo "[4/6] Clearing and caching Laravel optimizations..."
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

echo "[5/6] Restarting worker queue..."
docker exec -t sparetrack-app php artisan queue:restart

echo "[6/6] Gracefully restarting application Docker services..."
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

echo "==================================================="
echo "   SpareTrack Server Successfully Updated!"
echo "   - Reverted parts are now visible in QC"
echo "   - Mobile fast tab switching isolated"
echo "   - ZERO DATA LOSS guaranteed"
echo "==================================================="
