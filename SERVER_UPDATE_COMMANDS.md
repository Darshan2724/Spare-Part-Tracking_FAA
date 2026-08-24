# 🚀 SpareTrack Server & Mobile Deployment Commands

This guide contains everything required to deploy all updates onto the **Server PC** and push **Over-The-Air (OTA)** updates to the Mobile Application.

---

## ⚡ Option 1: Automated 1-Click Update Script (Fastest)
On the Server PC, open PowerShell in the project root directory and run:
```powershell
.\update_server.bat
```

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

### About Preview vs Production Builds:
- **Preview Build**: If the app on your phone was installed as a Preview / Internal testing APK (the app badge says "Preview Build"), EAS Update checks the **`preview`** channel.
- **Production Build**: If the app was built for production release, it checks the **`production`** channel.

> **Note**: Both `preview` and `production` channels have already been built and published with the latest fixes!

If you ever need to publish a new mobile update manually from your terminal:

```powershell
cd "C:\Darshan Details\Internship Faith Automation\Projects\Spare Part Tracking\SpareTrack\mobile"
```

#### Publish to Preview Channel:
```powershell
npx eas-cli update --channel preview --message "Latest updates"
```

#### Publish to Production Channel:
```powershell
npx eas-cli update --channel production --message "Latest updates"
```

### How to apply on Phone:
1. Close the app completely on your phone (swipe it away from recent apps).
2. Open the app again while connected to internet $\to$ EAS automatically downloads the new bundle.
3. On next open (or by clicking "Check for Updates"), the app will run the latest version immediately without reinstalling the APK!
