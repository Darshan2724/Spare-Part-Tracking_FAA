# Mobile API & Network Configuration Guide

---

## 1. Network Communication Rules

> [!IMPORTANT]
> **CRITICAL RULE**: The Android APK must **NEVER** use `localhost` or `127.0.0.1`.
> On a physical phone, `localhost` refers to the phone's internal OS loopback.
> All API calls must target the Windows Server's LAN IP address (e.g. `http://192.168.9.200:8080/api/v1`) or company internal DNS name.

---

## 2. Dynamic 3-Tier Base URL Architecture

The mobile app (`mobile/src/api/client.js`) uses a multi-tier fallback mechanism:

```
┌────────────────────────────────────────────────────────┐
│ 1. Runtime In-App Input (Login Screen: Server Host/IP) │
├────────────────────────────────────────────────────────┤
│ 2. EAS Build Environment Variable (EXPO_PUBLIC_API_URL)│
├────────────────────────────────────────────────────────┤
│ 3. Default Company LAN Fallback IP (192.168.9.200:8080)│
└────────────────────────────────────────────────────────┘
```

Operators can seamlessly switch between staging and production servers directly from the login screen without rebuilding the APK.

---

## 3. Network Troubleshooting Checklist

If an Android device cannot connect to the server:
1. **Wi-Fi Connection Check**: Ensure the phone is connected to the company Wi-Fi AP (`192.168.14.238`).
2. **Turn Mobile Data OFF**: Mobile Data must be switched OFF on Android so all HTTP packets route over Wi-Fi.
3. **Browser Health Test from Phone**: Open Chrome on the phone and navigate to `http://192.168.9.200:8080/api/v1/health`.
   - If it displays `{"status":"healthy",...}`, communication is 100% functional.
   - If it times out, verify Windows Firewall on the server allows port `8080` from `remoteip=any` and ensure AP Isolation is disabled on the router.
