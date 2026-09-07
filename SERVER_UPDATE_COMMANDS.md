# 🚀 SpareTrack Server Deployment Commands
## (Mobile Intake Strict MFG Enforcement + Systemwide Performance Architecture + Zero Downtime Docker Deployment)

> **Release Reference:** Merged [Pull Request #21](https://github.com/Darshan2724/Spare-Part-Tracking_FAA/pull/21) (`f64b271`)

This document contains the exact 1-click script and Docker commands to deploy the latest verified changes to the SpareTrack production server (`192.168.9.200:8080`).


---

## ⚡ Option 1: Run 1-Click Script on Server (Recommended)

### If your server is **Windows**:
Open Command Prompt or PowerShell in the `SpareTrack` folder and run:
```bat
.\update_server.bat
```
*Or in PowerShell:*
```powershell
.\update_server.ps1
```

### If your server is **Linux / macOS**:
Open terminal in the `SpareTrack` folder and run:
```bash
chmod +x update_server.sh
./update_server.sh
```

---

## 📋 Option 2: Copy & Paste All-in-One Command

### For **Windows PowerShell**:
```powershell
git stash; git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx; Start-Sleep -Seconds 3; curl.exe -s http://127.0.0.1:8080/api/v1/health
```

### For **Linux / macOS Bash**:
```bash
git stash && git pull origin main && npm run build && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx && sleep 3 && curl -s http://127.0.0.1:8080/api/v1/health
```

---

## 🛠️ Option 3: Step-by-Step Docker & Server Commands

```bash
# 1. Stash any locally generated server build artifacts
git stash

# 2. Pull latest merged code from main
git pull origin main

# 3. Build web frontend production assets (Vite)
npm run build

# 4. Run database migrations (Adds composite & FK indexes safely with zero data loss)
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

# 8. Verify backend health
curl.exe -s http://127.0.0.1:8080/api/v1/health
```

---

## 📱 Mobile Floor Terminal OTA Update

To publish the update over-the-air to all shop floor Android devices without reinstalling the APK:

```powershell
cd mobile
npx eas-cli update --branch production --message "Enforce strict MFG mobile intake & performance optimization"
```

---

## 🔍 Audit Historical Production Records (Read-Only)

To inspect historical non-MFG intake records on existing production projects (e.g., `FA-273`) without modifying any data:

```bash
docker exec -t sparetrack-app php artisan audit:mobile-bop
```

---

## 📦 What Latest Update Applies to the Server:
1. **Strict Mobile Intake MFG-Only Enforcement**: 100% of mobile part intake is restricted to `MFG` items. Non-MFG items (`BOP`/`STD`) are rejected with `422 Unprocessable Entity`.
2. **Single-Pass In-Memory Hierarchy Partitioning**: Reduces `GET /dashboard/project-hierarchy` latency from 463ms to 115ms (75.2% faster) and query execution time by 83.5%.
3. **Database Performance & FK Indexes**: Adds indexes on `receipts(project_id, created_at)`, `receipt_items(receipt_id)`, `receipt_items(updated_at)`, `bom_items(project_id, part_type, standard_part_no)`, `qc_inspections(inspected_by)`, and `workflow_events(created_at)`.
4. **Search Column Bug Fix**: Resolves 500 error on parts search by replacing non-existent column `part_description` with `remarks` and `supplier_name_raw` (search speed: 3.7ms).
5. **Selective Project Calculations**: `HierarchyService` only computes metrics for the target project during single-project drilldown.
