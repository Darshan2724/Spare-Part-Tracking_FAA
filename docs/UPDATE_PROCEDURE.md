# Continuous Update & Change Management Procedure

This guide defines how to push ongoing updates to the live production server and mobile devices.

---

## 1. The 3 Update Types & Procedures

### Path A: Backend / Database / Web Changes (Zero APK Reinstallation)
* **What changes**: Laravel APIs, controllers, PostgreSQL queries/migrations, workflow logic, dashboard calculations, or Vue web pages.
* **Update Method**:
  1. Developer commits and pushes changes to Git (`git push origin main`).
  2. On the Windows 11 Server, run:
     ```powershell
     cd C:\SpareTrack
     .\scripts\deploy.ps1
     ```
* **Mobile Effect**: Existing installed Android APKs automatically communicate with the updated server backend immediately. **Zero APK reinstallation required!**

---

### Path B: Mobile UI / JavaScript / Asset Changes (Over-The-Air EAS Update)
* **What changes**: Screen redesigns, buttons, colors, JavaScript logic, layout fixes, or text changes in `mobile/App.js`.
* **Update Method**:
  1. Developer tests mobile changes locally.
  2. Inside `mobile/`, publish an OTA update:
     ```bash
     cd mobile
     eas update --branch production --message "Updated mobile UI and high density layout"
     ```
* **Mobile Effect**: When operators open the installed APK on their phones, the app automatically downloads the new JavaScript bundle in the background. **Zero APK reinstallation required!**

---

### Path C: Native Module / SDK Upgrades (Requires New APK)
* **What changes**: Adding new native libraries, upgrading Expo SDK, changing Android permissions, or changing the app launcher icon.
* **Update Method**:
  1. Increment `versionCode` in `mobile/app.json` (e.g. `versionCode: 2`).
  2. Build a new APK:
     ```bash
     cd mobile
     eas build --platform android --profile preview
     ```
  3. Distribute the new `.apk` file to department phones.
