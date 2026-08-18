# Android APK Build & Installation Runbook

This runbook explains how to generate a production-ready standalone Android APK for **FAITH AUTOMATION** and sideload it onto factory mobile devices.

---

## 1. Building the Android APK with Expo EAS

### Step 1: Install EAS CLI (One-Time Setup)
On your development PC, install the Expo Application Services (EAS) CLI globally:

```bash
npm install -g eas-cli
```

### Step 2: Log In to Your Expo Account
```bash
eas login
```

### Step 3: Build Standalone APK (Internal Sideload Profile)
Inside the `mobile/` directory:

```bash
cd mobile

# Cloud Build via EAS (Recommended - No Android Studio Required)
eas build --platform android --profile preview

# OR Local Offline Build (Requires Android SDK & Java on PC)
eas build --platform android --profile preview --local
```

When the build completes, EAS outputs a download link for the `.apk` file (e.g. `FAITH_AUTOMATION_v1.0.0.apk`).

---

## 2. Installing the APK on Department Android Phones

1. **Option A: Direct Download via Phone Browser**
   - Connect the Android phone to the company Wi-Fi network.
   - Open Chrome on the phone and download the `.apk` file from the EAS build URL or company local server.
2. **Option B: USB Transfer / Local Share**
   - Copy the `.apk` file to the phone via USB cable or local file share.
3. **Installation Steps on Phone**:
   - Tap the downloaded `.apk` file.
   - If prompted: **"Install from unknown sources"** > Allow for Chrome / Files.
   - Tap **Install** > **Open**.

---

## 3. Initial App Launch & Connection

1. On the login screen, verify the **Server Host / IP** field:
   - Example: `192.168.100.60:8080` (your Windows Server LAN IP and port).
2. Enter department credentials (e.g. `store@faithautomation.internal` or `admin@sparetrack.internal`).
3. Tap **Sign In to Mobile Terminal**.
