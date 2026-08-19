# FAITH AUTOMATION — SpareTrack System Context & Development Summary

**Project Name:** SpareTrack (Industrial Spare Parts Tracking & Workflow Management System)  
**Organization:** FAITH AUTOMATION  
**Primary Database:** PostgreSQL 16 ONLY  
**Backend Framework:** Laravel 11.x (REST API + Sanctum + Spatie Permission)  
**Frontend Framework:** Vue 3.4+ (Vite, Bootstrap 5.3, Chart.js)  
**Mobile Terminal:** React Native 0.74.5 / Expo SDK 51 (Standalone Android APK)  
**Infrastructure:** Docker Compose (7 Services), Windows 11 On-Premise LAN Server  
**Document Generated:** August 19, 2026  

---

## 1. Executive Summary & Purpose

This document provides a comprehensive, chronological, and technical record of all project developments, architectural decisions, manufacturing workflow implementations, dashboard updates, export engines, production audits, APK builds, and on-premise server deployment procedures for the **SpareTrack** system.

---

## 2. Chronological Milestones & Work Completed

### Milestone 1: Branding & Logo Harmonization
* **Company Brand:** Replaced generic placeholder logos with official **FAITH AUTOMATION** branding across Web and Mobile.
* **App Icon (`LOGO_APP.png`):** Configured dedicated square app launcher icon (`logo_app.png`) in `mobile/assets/` and `mobile/app.json` for Android home screen recognition.
* **Web & Splash Logo (`LOGO.png`):** Retained high-resolution horizontal company banner on web headers and mobile login splash screen.

### Milestone 2: Manager & Admin Dashboard KPI Simplification
* **Removed Obsolete KPIs:** Completely eliminated the 5 unwanted metrics from Manager/Admin dashboards:
  1. *Process Flow Efficiency*
  2. *Quality Stability Index (QSI)*
  3. *Capacity Load by Department*
  4. *Project Completion Variance*
  5. *Critical Dependency Monitor (Bottleneck Ranking)*
* **Retained Real Data KPIs:** Focused dashboard on real mathematical PostgreSQL queries:
  - *Project Readiness Index*
  - *Production Conversion Rate*
  - *Quality Cost Pressure Score*
  - *Supplier Fill Accuracy*
  - *Project Completion Velocity*
  - *Today's Departmental Throughput*
  - *Daily Movement Matrix*

### Milestone 3: Manufacturing Workflow & State Machine Hardening
* **RH vs LH vs COMMON Strict Isolation:** 
  - RH and LH components are modeled as independent `bom_requirements` with isolated tracking across `receipt_items`, `qc_inspections`, `rework_records`, `paint_records`, and `assembly_records`.
  - Added strict server-side side-mismatch guards preventing cross-side contamination during inspection.
* **Dual QC Approval Routing:**
  - QC approved items require an explicit destination: `PAINT` or `ASSEMBLY`.
  - Paint Shop queue accepts only parts routed to `PAINT`.
  - Assembly queue accepts parts from both completed `paint_records` and direct `qc_inspections` (destination = `ASSEMBLY`).
* **FA-279 Standard BOM Import Desk:**
  - Strict FA-279 column validation (`Project Code`, `Jig No`, `Unit No`, `Part No`, `Side`, `Qty`, `Supplier`).
  - SHA-256 duplicate file hashing (`checkDuplicateFile`) blocking re-importation of identical files even if renamed.

### Milestone 4: Scoped Excel/PDF Export & Date Columns
* **Strict Scoping:** Restricted the universal Excel and PDF export engine **strictly inside the Parts Movement Detail modal view** (opened from the Daily Movement Matrix).
* **Preserved Columns:** Kept both **Date** and **Time** columns intact in the Parts Movement Detail table.
* **Universal Export Engine:**
  - Excel (`.xlsx`): Generated using `PhpOffice\PhpSpreadsheet` with branded banners, active filter metadata, and auto-adjusted columns.
  - PDF (`.pdf`): Generated using `Barryvdh\DomPDF` in landscape A4 multi-page format with repeating headers.

### Milestone 5: 20-Phase Production Readiness Audit & Verification
* Completed deep audit across all 20 architectural domains.
* Resolved all 3 identified blockers:
  1. Fixed EAS Android build configuration by installing `expo-updates` and `expo-build-properties`.
  2. Registered WebSocket broadcast channel routing in `bootstrap/app.php` and created `routes/channels.php`.
  3. Implemented automated PHPUnit test suite in `tests/Feature/` (100% passing tests).

### Milestone 6: Android APK Generation & LAN Connectivity
* Fixed Android cleartext HTTP restrictions (`android:usesCleartextTraffic="true"` via `expo-build-properties`).
* Solved Windows network discovery issues (switching Wi-Fi profile from **Public** to **Private**).
* Built standalone production preview APK on EAS Cloud.

---

## 3. System Architecture & Tech Stack

```mermaid
graph TD
    subgraph Client Layer
        A[Mobile Android APK\nReact Native / Expo] -->|REST API & WebSockets| C[Nginx Gateway :8080]
        B[Web Browser App\nVue 3 + Vite] -->|REST API & WebSockets| C
    end

    subgraph Infrastructure Layer (Docker on Windows 11)
        C --> D[Laravel 11 App Container :9000]
        C --> E[Laravel Reverb Container :8085]
        D --> F[(PostgreSQL 16 Database :5432)]
        D --> G[(Redis Cache & Queue :6379)]
        H[Queue Worker] --> G
        I[Database Adminer :8088] --> F
    end
```

### Docker Services in `docker-compose.yml`:
1. **`app`**: PHP 8.2-FPM running Laravel 11 application logic.
2. **`nginx`**: Web server gateway exposing port `8080` to LAN.
3. **`postgres`**: PostgreSQL 16 database storing all relational and workflow data (Named volume: `postgres_data`).
4. **`redis`**: Cache and high-throughput background queue engine (Named volume: `redis_data`).
5. **`reverb`**: WebSocket real-time server exposing port `8085`.
6. **`worker`**: Background queue daemon processing asynchronous jobs.
7. **`adminer`**: Visual database web administration interface exposing port `8088`.

---

## 4. Default Roles, Users & Credentials

All default user passwords in development/local deployment are set to: **`password123`**

| Role / Department | User Name | Email Address | Access Level |
| :--- | :--- | :--- | :--- |
| **ADMIN** | System Admin | `admin@sparetrack.internal` | Full System Access, Logs, User Mgmt, BOM Import |
| **MANAGER** | Plant Manager | `manager@sparetrack.internal` | Full Monitoring, Analytics, Exports, Read-Only Queues |
| **STORE** | Store Officer | `store@sparetrack.internal` | Stock Arrival, Physical Intake, Dispatch to QC, Revert |
| **QC** | QC Inspector | `qc@sparetrack.internal` | Physical Arrival Check, Inspection (Approve/Reject/Rework) |
| **REWORK** | Rework Specialist | `rework@sparetrack.internal` | Rework Processing, Re-dispatch to QC |
| **PAINT** | Paint Operator | `paint@sparetrack.internal` | Powder Coat / Surface Treatment Queue Completion |
| **ASSEMBLY** | Assembly Lead | `assembly@sparetrack.internal` | Final Unit Assembly Completion |
| **PURCHASE** | Purchase Executive | `purchase@sparetrack.internal` | Supplier Rejections, Return Queue Management |

---

## 5. Network Configuration & Ports

| Service | Port | Host Address (Example) | Purpose |
| :--- | :--- | :--- | :--- |
| **Web & REST API** | `8080` | `http://192.168.100.60:8080` | Main Web Application and Mobile API Gateway |
| **Reverb WebSockets** | `8085` | `ws://192.168.100.60:8085` | Real-time cross-device event broadcasting |
| **Database Adminer** | `8088` | `http://192.168.100.60:8088` | PostgreSQL Database Browser |
| **PostgreSQL Engine** | `5432` | `192.168.100.60:5432` | Native PostgreSQL connection port |

> **Crucial Network Rule:**  
> Windows 11 Wi-Fi network profile MUST be set to **Private network** (in `Settings > Network & internet > Wi-Fi > Network profile type`) so incoming LAN traffic from mobile devices is permitted by Windows Firewall.

---

## 6. Windows 11 Desktop On-Premise Server Migration Package

To transfer and deploy the complete system to the company's dedicated Windows 11 Desktop:

### 📦 Pen Drive File Structure:
```text
📁 PENDRIVE/
│
├── 📁 SpareTrack/                        <-- Main Project Folder
│   ├── 📁 docker/
│   ├── 📁 scripts/                      <-- Automated Deployment & Backup Scripts
│   ├── 📁 backups/                      <-- Latest DB Dump: sparetrack_db_backup_*.sql
│   ├── 📁 resources/
│   ├── 📁 app/
│   ├── 📁 database/
│   ├── 📄 docker-compose.yml
│   └── 📄 .env.production.example
│
├── 📁 Installers/                       <-- Offline Installers for Target Desktop
│   ├── 📄 Docker Desktop Installer.exe
│   ├── 📄 Git-64-bit.exe
│   └── 📄 node-v20.x.x-x64.msi (Optional)
│
└── 📁 APK/
    └── 📄 FAITH_AUTOMATION.apk          <-- Latest Android APK for Department Phones
```

### 🛠️ Step-by-Step Deployment on Target Desktop:
1. **Install Prerequisites**: Run `Git-64-bit.exe` and `Docker Desktop Installer.exe`. Reboot the PC when prompted.
2. **Copy Codebase**: Copy `SpareTrack` folder from the Pen Drive to `C:\SpareTrack`.
3. **Run PowerShell Scripts (as Administrator)**:
   ```powershell
   cd C:\SpareTrack
   
   # Step 1: Open firewall ports
   powershell -ExecutionPolicy Bypass -File ".\scripts\setup_server.ps1"
   
   # Step 2: Build & start all 7 Docker containers
   powershell -ExecutionPolicy Bypass -File ".\scripts\deploy.ps1"
   
   # Step 3: Restore all database records, BOM parts, and history
   powershell -ExecutionPolicy Bypass -File ".\scripts\restore.ps1"
   ```
4. **Verification**:
   - Web App: Open `http://localhost:8080` in browser.
   - Mobile APK: Install on Android phones connected to office Wi-Fi, enter Desktop IP (`<DESKTOP_IP>:8080`).

---

## 7. Android Mobile APK Builds (EAS Cloud)

* **Build Configuration**:
  - EAS Project ID: `8f72f3f8-4359-4def-bef1-da6ba4314c2d`
  - Package Name: `com.faithautomation.sparetrack`
  - Manifest Plugin: `expo-build-properties` with `android:usesCleartextTraffic=true`
* **Latest Verified APK Build:**
  - Build ID: `e9734b58-c20c-4625-8c01-bf37caf656a9`
  - Direct Download URL: [Download FAITH AUTOMATION APK](https://expo.dev/artifacts/eas/2QR9sBHUs7qG9NUbzsZFj4TeHJqoEdb6ZJGolxJmRts.apk)

---

## 8. Git Commit Log (Recent Core Updates)

| Commit Hash | Description |
| :--- | :--- |
| `194411a` | Configure `expo-build-properties` plugin to enforce `android:usesCleartextTraffic` in standalone APK builds |
| `191ce4e` | Enhance mobile API client URL normalization and diagnostics |
| `a74fb69` | Configure dedicated square app launcher icon from `LOGO_APP.png` while keeping banner logo on login page |
| `406cd9b` | Resolve production readiness audit: Fix EAS build config, enable broadcast routing, add PHPUnit feature tests |
| `5bada6a` | Implement Export Excel and PDF exclusively inside Parts Movement Detail modal while preserving Date and Time columns |
| `c84927f` | Add Date column to Parts Movement modal, Windows 11 server deployment suite, Android APK config, and Faith Automation branding |
| `ab93546` | Complete spare parts tracking system hardening, high-volume performance, bulk department actions, destination routing |

---

## 9. Automated Testing & Verification Commands

```powershell
# Run backend PHPUnit feature test suite
php artisan test

# Compile web frontend assets
npm run build

# Generate on-demand database backup
powershell -ExecutionPolicy Bypass -File ".\scripts\backup.ps1"

# Check Docker container health
powershell -ExecutionPolicy Bypass -File ".\scripts\health.ps1"
```
