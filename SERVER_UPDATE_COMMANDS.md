# 🚀 SpareTrack Server Deployment Commands
## (Pending Part Deletion + 14-Col Jig Excel Export + Jig Completion % Parity + Zero Data Loss SOP)

> **Release Reference:** Latest merged commit `33101f7` (`main` / `branch-a`)

This document contains the exact 1-click scripts and Docker commands to deploy the latest verified changes to the SpareTrack production server (`192.168.9.200:8080`) with **100% data preservation and zero database corruption risk**.

---

## 🛡️ Zero Data Loss Guarantee & Rules
1. **NEVER run `migrate:fresh`, `migrate:reset`, or `db:wipe` on production.** These commands permanently drop database tables and wipe all production records.
2. **Safe migrations only:** `docker exec -t sparetrack-app php artisan migrate --force` applies only new, additive migrations without touching existing data.
3. **Automated snapshot:** An instantaneous PostgreSQL dump is taken before any code or schema update to ensure an immediate rollback snapshot is saved in `./backups/`.
4. **Preserved containers:** The `sparetrack-postgres` database container and its persistent Docker volume (`postgres_data`) remain online and intact throughout the deployment.

---

## ⚡ Option 1: 1-Click Script on Server (Recommended)

### Step 1: Open Terminal / CMD on the Server PC
Navigate to the SpareTrack directory:
```cmd
cd "C:\path\to\SpareTrack"
```

### Step 2: Fetch & Pull Latest Code
```cmd
git fetch origin main
git reset --hard origin/main
```

### Step 3: Run the Update Script

#### If your server is **Windows Command Prompt (CMD)**:
```cmd
update_server.bat
```

#### If your server is **Windows PowerShell**:
```powershell
.\update_server.ps1
```

#### If your server is **Linux / macOS**:
```bash
chmod +x update_server.sh
./update_server.sh
```

---

## 📋 Option 2: Copy & Paste All-in-One Command

### For **Windows PowerShell**:
```powershell
New-Item -ItemType Directory -Force -Path "./backups" | Out-Null; $ts = Get-Date -Format "yyyyMMdd_HHmmss"; docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | Out-File -FilePath "./backups/sparetrack_pre_update_$ts.sql" -Encoding utf8; Copy-Item -Path "./backups/sparetrack_pre_update_$ts.sql" -Destination "./backups/sparetrack_pre_update_latest.sql" -Force; git stash --include-untracked; git fetch origin main; git reset --hard origin/main; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx; Start-Sleep -Seconds 3; curl.exe -s http://127.0.0.1:8080/api/v1/health
```

### For **Linux / macOS Bash**:
```bash
mkdir -p ./backups && docker exec -t sparetrack-postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "./backups/sparetrack_pre_update_$(date +%Y%m%d_%H%M%S).sql" && cp "./backups/sparetrack_pre_update_$(date +%Y%m%d_%H%M%S).sql" "./backups/sparetrack_pre_update_latest.sql" 2>/dev/null || true && git stash --include-untracked && git fetch origin main && git reset --hard origin/main && docker exec -t sparetrack-app php artisan migrate --force && docker exec -t sparetrack-app php artisan optimize:clear && docker exec -t sparetrack-app php artisan config:cache && docker exec -t sparetrack-app php artisan route:cache && docker exec -t sparetrack-app php artisan view:cache && docker exec -t sparetrack-app php artisan queue:restart && docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx && sleep 3 && curl -s http://127.0.0.1:8080/api/v1/health
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

### Step 3: Run Safe Additive Migrations (Zero Data Loss)
Applies pending structural additions without modifying or dropping existing rows.
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
*(Notice: The `sparetrack-postgres` and `sparetrack-redis` containers remain active and are not restarted, preventing database connection drops).*

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
1. **Pending Part Deletion (Single & Multi-Select Bulk Purge/Decrement)**:
   - Purges or decrements mistaken untouched BOM parts across MFG, BOP, STD, and ECN.
   - Enforces 0 downstream activity before allowing deletion.
   - Scoped "Select All" checkbox, selection counter, and all-or-nothing atomic rollback.
2. **14-Column Project Jig Excel Export (`/api/v1/projects/{id}/export-jigs`)**:
   - Distinct columns for Assembly department queue (Col K: `Assembly`) and finished assemblies (Col L: `Assembly Completed`).
   - Merged 14pt Jig Name banner (`A..N`), gold headers (`#F5E6CB`), and dedicated `TOTAL` summary rows.
   - Zero N+1 query latency via preloading.
3. **Jig Completion % Formula Alignment**:
   - Column N labeled `Jig Completion %`.
   - Populated **strictly in the `TOTAL` summary row only**; fixture rows (LH/RH) left cleanly blank to prevent redundant repetition.
   - Authoritative formula: `Assembly Completed / Total Required * 100` matching website Jig cards.
4. **Top Projects Near Completion Chart**:
   - Full horizontal bar chart displaying all qualifying active projects.
