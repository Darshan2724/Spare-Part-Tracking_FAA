# 🚀 SpareTrack Server Deployment Commands
## (QC ECN Queue Fix + Scoped Dashboard Indicators + Zero Downtime Docker Deployment)

This document contains the exact 1-click script and Docker commands to apply all changes from today to the SpareTrack server.

---

## ⚡ Option 1: Run 1-Click Script on Server (Recommended)

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

## 📋 Option 2: Copy & Paste All-in-One Command

### For **Windows PowerShell**:
```powershell
git stash; git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

### For **Linux / macOS Bash**:
```bash
git stash && git pull origin main && npm run build && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 🛠️ Option 3: Step-by-Step Docker & Server Commands

```bash
# 1. Stash any locally generated server build artifacts
git stash

# 2. Pull latest code from main
git pull origin main

# 3. Build web frontend production assets
npm run build

# 4. Run database migrations (Zero data loss, PostgreSQL safe)
docker exec -t sparetrack-app php artisan migrate --force

# 5. Clear and re-cache Laravel optimizations
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

# 6. Restart background worker queue
docker exec -t sparetrack-app php artisan queue:restart

# 7. Gracefully restart application containers (PostgreSQL database container stays running untouched)
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 📦 What Today's Update Applies to the Server:
1. **QC ECN Queue Stale State Fix**: Rejected ECN parts no longer reappear in QC inspection queue.
2. **ECN Reject Idempotency**: Prevents repeated rejections from creating duplicate purchase records.
3. **ECN Revert Record ID Fix**: Fully resolves requirement IDs and receipt item IDs seamlessly.
4. **Main Dashboard Scoped ECN Badges**: Clean, bright amber `[ECN]` indicators on Jig, Unit, LH, and RH cards that auto-vanish once assembled.
5. **Pure Regular Part Lists**: Main dashboard part lists display strictly 100% regular BOM parts.
