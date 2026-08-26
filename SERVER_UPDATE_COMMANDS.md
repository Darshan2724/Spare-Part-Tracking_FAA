# 🚀 SpareTrack Server & Mobile Deployment Commands

This guide contains everything required to deploy all updates onto the **Server PC** and push **Over-The-Air (OTA)** updates to the Mobile Application.

---

## 🔀 GitHub Pull Request Link
The feature branch has been pushed to GitHub:
👉 **[Create Pull Request on GitHub](https://github.com/Darshan2724/Spare-Part-Tracking_FAA/pull/new/feat/strict-department-revert-system)**

Once you merge the PR on GitHub, run the commands below on your Server PC.
*(If you want to pull directly from the branch without merging first, use `git checkout feat/strict-department-revert-system && git pull origin feat/strict-department-revert-system`).*

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

To publish the new mobile update directly from your terminal:

```powershell
cd "c:\Darshan Details\Internship Faith Automation\Projects\Spare Part Tracking\SpareTrack\mobile"
```

### 1. Publish to Preview Channel (If your app is Preview Build):
```powershell
npx --yes eas-cli update --branch preview --message "feat: strict multi-department revert system"
```

### 2. Publish to Production Channel (If your app is Production Build):
```powershell
npx --yes eas-cli update --branch production --message "feat: strict multi-department revert system"
```

### 3. How to Apply the Update on Phone:
1. Close the app completely on your phone (swipe it away from recent apps).
2. Re-open the app while connected to Wi-Fi/Internet (EAS will download the new bundle in background).
3. On next restart (or when tapping "Check for Updates" in app), the latest version with the Revert features will be live without reinstalling the APK!
