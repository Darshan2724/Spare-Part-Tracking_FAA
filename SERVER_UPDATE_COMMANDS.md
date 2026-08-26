# 🚀 SpareTrack Server Deployment Commands
## (Self-Healing Reverts + Zero Data Loss Migration)

This document contains the exact 1-click script and commands to update the SpareTrack server (Docker).

---

## ⚡ Option 1: Run 1-Click File on Server (Recommended)

### If your server is **Windows**:
Open Command Prompt or PowerShell in `SpareTrack` folder and run:
```bat
.\update_server.bat
```

### If your server is **Linux / macOS**:
Open terminal in `SpareTrack` folder and run:
```bash
chmod +x update_server.sh
./update_server.sh
```

*(This single file automatically pulls latest code, runs the self-healing migration to restore all past reverted parts to QC without data loss, optimizes caches, and restarts Docker containers).*

---

## 📋 Option 2: Copy & Paste All-in-One Command

### For **Windows PowerShell**:
```powershell
git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

### For **Linux / macOS Bash**:
```bash
git pull origin main && npm run build && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 🛠️ Option 3: Step-by-Step Individual Commands

```bash
# 1. Pull latest code & self-healing migration
git pull origin main

# 2. Build web frontend assets
npm run build

# 3. Run self-healing database migration (Heals past reverted parts into QC)
docker exec -t sparetrack-app php artisan migrate --force

# 4. Clear and rebuild optimizations
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

# 5. Restart worker queue
docker exec -t sparetrack-app php artisan queue:restart

# 6. Gracefully restart application containers (PostgreSQL database stays running untouched)
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```
