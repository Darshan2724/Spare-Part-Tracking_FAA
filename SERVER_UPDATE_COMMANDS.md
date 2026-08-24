# 🚀 SpareTrack Server & Mobile Deployment Commands

This guide contains everything required to deploy all updates onto the **Server PC** and push **Over-The-Air (OTA)** updates to the Mobile Application.

---

## ⚡ Option 1: Automated 1-Click Update Script
On the Server PC, simply double-click:
```bat
update_server.bat
```
*(Or execute `.\update_server.bat` in PowerShell/Command Prompt).*

---

## 📋 Option 2: PowerShell One-Liner (Copy & Paste All at Once)
Open **PowerShell** in `C:\Darshan Details\Internship Faith Automation\Projects\Spare Part Tracking\SpareTrack` and paste:

```powershell
git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 🛠️ Option 3: Step-by-Step Commands (Zero Data Loss Guaranteed)

### 1. Pull Latest Source from GitHub
```powershell
git pull origin main
```

### 2. Build Web Frontend Assets
```powershell
npm run build
```

### 3. Run Safe Database Migrations Inside Docker
```powershell
docker exec -t sparetrack-app php artisan migrate --force
```

### 4. Clear and Rebuild Laravel Caches
```powershell
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache
```

### 5. Gracefully Restart Application Services
*(PostgreSQL database and Redis remain running untouched)*
```powershell
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

## 📱 Mobile App Over-The-Air (OTA) Updates

Navigate to the mobile directory:
```powershell
cd "C:\Darshan Details\Internship Faith Automation\Projects\Spare Part Tracking\SpareTrack\mobile"
```

### Method A: EAS Over-The-Air (OTA) Publish
Publish the update directly to all installed mobile devices without rebuilding the APK:
```powershell
npx eas-cli update --auto
```
*Or with a specific update message:*
```powershell
npx eas-cli update --branch main --message "Performance optimization, zero-delay tab caching, and assembly sync stability"
```

### Method B: Expo Go / Local Reload
```powershell
npx expo start --clear
```
*(Press `r` in terminal to reload the bundle on connected devices).*

---

## 💾 Optional: Timestamped Database Backup
To create a backup before or after updates:
```powershell
docker exec -t sparetrack-postgres pg_dump -U sparetrack_user -d sparetrack -c > backups/sparetrack_backup_latest.sql
```

---

## 🩺 System Verification & Health Check
```powershell
# Check running containers
docker ps

# View live Laravel logs
docker exec -t sparetrack-app tail -n 50 storage/logs/laravel.log
```
