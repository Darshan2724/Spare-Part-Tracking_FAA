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

---

## 📋 Option 2: Copy & Paste All-in-One Command (With Auto-Stash)

### For **Windows PowerShell**:
```powershell
git stash; git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

### For **Linux / macOS Bash**:
```bash
git stash && git pull origin main && npm run build && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 🛠️ Option 3: Step-by-Step Individual Commands

```bash
# 1. Stash any locally generated server build artifacts
git stash

# 2. Pull latest code & self-healing migration
git pull origin main

# 3. Build web frontend assets
npm run build

# 4. Run self-healing database migration (Heals past reverted parts into QC)
docker exec -t sparetrack-app php artisan migrate --force

# 5. Clear and rebuild optimizations
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

# 6. Restart worker queue
docker exec -t sparetrack-app php artisan queue:restart

# 7. Gracefully restart application containers (PostgreSQL database stays running untouched)
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```
