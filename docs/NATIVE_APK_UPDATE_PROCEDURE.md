# Native Android APK Rebuild & Update Procedure

This procedure is used when a native binary change occurs that cannot be delivered over-the-air.

---

## 1. When is a New APK Required?

A new APK build is required **ONLY** when:
1. Adding or removing native third-party modules (e.g. barcode scanner, NFC, Bluetooth printer).
2. Upgrading the Expo SDK version (e.g. from SDK 51 to 52).
3. Modifying Android permissions (e.g. Camera, Location) in `app.json`.
4. Changing the application launcher icon, package name, or splash screen.

---

## 2. Version Increment & Build Steps

### Step 1: Increment Version Numbers in `mobile/app.json`
```json
{
  "expo": {
    "version": "1.1.0",
    "android": {
      "package": "com.faithautomation.sparetrack",
      "versionCode": 2
    }
  }
}
```

### Step 2: Trigger EAS APK Build
```bash
cd mobile
eas build --platform android --profile preview
```

### Step 3: Distribute & Sideload
Download the resulting `.apk` file and install it over the previous version on department devices.
Android will automatically update the app while preserving local app settings and login state.
