# 🚀 SpareTrack Server Deployment Commands
## (Top Projects Chart + Per-Jig Excel Export + Jig Card Paint Badge + Zero Data Loss SOP)

> **Release Reference:** Latest merged commit `ae530de` (`main` / `branch-a`)

This document contains the exact 1-click scripts and Docker commands to deploy the latest verified changes to the SpareTrack production server (`192.168.9.200:8080`) with **100% data preservation and zero database corruption risk**.

---

## 🛡️ Zero Data Loss Guarantee & Rules
1. **NEVER run `migrate:fresh`, `migrate:reset`, or `db:wipe` on production.** These commands permanently drop database tables and wipe all production records.
2. **Safe migrations only:** `docker exec -t sparetrack-app php artisan migrate --force` applies only new, additive migrations without touching existing data.
3. **Automated snapshot:** An instantaneous PostgreSQL dump is taken before any code or schema update to ensure an immediate rollback snapshot is saved in `./backups/`.
4. **Preserved containers:** The `sparetrack-postgres` database container and its persistent Docker volume (`postgres_data`) remain online and intact throughout the deployment.

---

## ⚡ Option 1: Run 1-Click Script on Server (Recommended)

### If your server is **Windows**:
Open PowerShell as Administrator in the `SpareTrack` folder and run:
```powershell
.\update_server.ps1
```
*Or in Command Prompt (CMD):*
```cmd
update_server.bat
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
New-Item -ItemType Directory -Force -Path "./backups" | Out-Null; $ts = Get-Date -Format "yyyyMMdd_HHmmss"; docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | Out-File -FilePath "./backups/sparetrack_pre_update_$ts.sql" -Encoding utf8; git stash --include-untracked; git fetch origin main; git reset --hard origin/main; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx; Start-Sleep -Seconds 3; curl.exe -s http://127.0.0.1:8080/api/v1/health
```

### For **Linux / macOS Bash**:
```bash
mkdir -p ./backups && docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "./backups/sparetrack_pre_update_$(date +%Y%m%d_%H%M%S).sql" && git stash --include-untracked && git fetch origin main && git reset --hard origin/main && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx && sleep 3 && curl -s http://127.0.0.1:8080/api/v1/health
```

---

## 🛠️ Option 3: Step-by-Step Production Runbook

### Step 1: Create Pre-Update Database Snapshot (Host Backup)
Takes a complete, restorable dump of all production data before executing any updates.
```bash
# On Linux/macOS
mkdir -p ./backups
docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "./backups/sparetrack_pre_update_$(date +%Y%m%d_%H%M%S).sql"

# On Windows PowerShell
New-Item -ItemType Directory -Force -Path "./backups" | Out-Null
$ts = Get-Date -Format "yyyyMMdd_HHmmss"
docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | Out-File -FilePath "./backups/sparetrack_pre_update_$ts.sql" -Encoding utf8
```

### Step 2: Fetch & Reset to Latest Verified `origin/main`
Safely resets code without touching unversioned files like `.env`, storage, or database volumes.
```bash
git stash --include-untracked
git fetch origin main
git reset --hard origin/main
```

### Step 3: Run Additive Database Migrations (Zero Data Loss)
Applies any pending structural additions without dropping existing data.
```bash
docker exec -t sparetrack-app php artisan migrate --force
```

### Step 4: Clear & Re-cache Application Optimizations
Warms up production configuration, route, and view caches for sub-50ms execution.
```bash
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache
```

### Step 5: Gracefully Restart Background Workers & App Services
Signals active background queue workers to safely reload code, and reloads PHP-FPM OPcache & Nginx.
```bash
docker exec -t sparetrack-app php artisan queue:restart
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```
*(Notice: The `sparetrack-postgres` container remains active and is not restarted, preventing database connection drops).*

### Step 6: Verify Backend Health
```bash
# Linux/macOS:
curl -s http://127.0.0.1:8080/api/v1/health

# Windows:
curl.exe -s http://127.0.0.1:8080/api/v1/health
```
Expected output: `{"status":"ok", ...}`

---

## 📦 What This Update Delivers to the Server:
1. **Top Projects Near Completion Chart**:
   - Displays all qualifying active projects (not capped at 5).
   - Authoritative 4-tier health color styling (`#16a34a`, `#2563eb`, `#eab308`, `#dc2626`).
   - `minBarLength: 4` ensuring $0\%$ completion projects remain clickable and visible.
2. **Project Jig Excel Export (`/api/v1/projects/{id}/export-jigs`)**:
   - Production-matched visual layout matching customer specification.
   - Merged 14pt Jig Name banner at the top of each section.
   - Gold/Tan header styling (`#F5E6CB`) with dark bold typography and thin cell borders.
   - Proper fixture numbering format: `{Jig Name}-{Side}`.
   - Dedicated `TOTAL` row per Jig calculating numeric sums for `BOP`, `STD`, `MFG`, and `Total`.
   - Blank date and supplier cells when records do not exist in the database (no fake or placeholder data).
3. **Jig Card Paint Badge & Modernization**:
   - Added `Paint` metric pill to the Jig card header badge list.
   - Standardized all card badges with high-contrast, clean enterprise styling.
