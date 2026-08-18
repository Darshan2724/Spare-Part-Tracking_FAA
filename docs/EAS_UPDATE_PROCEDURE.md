# Expo EAS Update Procedure (Over-The-Air JavaScript Updates)

---

## 1. Overview

**EAS Update** allows distributing bug fixes, styling adjustments, and React Native JavaScript updates directly to installed Android devices without rebuilding or re-installing the APK.

---

## 2. Publishing an Update

### Step 1: Ensure EAS CLI is Installed
```bash
npm install -g eas-cli
eas login
```

### Step 2: Publish to the Production Channel
Inside the `mobile/` directory:

```bash
cd mobile

# Publish update with a descriptive change message
eas update --branch production --message "Fixed mobile QC queue filter and compact styling"
```

### Step 3: Verify Update Status
```bash
eas update:list
```

---

## 3. How the Mobile Device Receives the Update

1. When the operator opens the **FAITH AUTOMATION** app on their phone, `expo-updates` checks the EAS server for updates matching the app's `runtimeVersion`.
2. The app downloads the new JavaScript and asset bundle in the background.
3. The next time the operator opens the app (or on reload), the updated UI runs automatically.
