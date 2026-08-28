# FAITH AUTOMATION — SpareTrack Master System Context & Operations Guide

**Project Name:** SpareTrack (Industrial Spare Parts Tracking & Workflow Execution System)  
**Organization:** FAITH AUTOMATION  
**Primary Database Engine:** PostgreSQL 16 ONLY  
**Backend Framework:** Laravel 11.x (REST API + Sanctum + Spatie Permission + Reverb WebSockets)  
**Web Frontend Framework:** Vue 3.4+ (Vite, Bootstrap 5.3, Chart.js, Pinia)  
**Mobile Application:** React Native 0.74.5 / Expo SDK 51 (Standalone Android APK + EAS OTA Updates)  
**Infrastructure:** Docker Compose (7 Microservices), Windows 11 Desktop On-Premise LAN Server  
**Document Revision:** August 28, 2026  

---

## Table of Contents
1. [Executive Overview & Core System Mission](#1-executive-overview--core-system-mission)
2. [Strict Architectural Laws & Mathematical Invariants](#2-strict-architectural-laws--mathematical-invariants)
3. [Manufacturing Workflow & State Machine Rules](#3-manufacturing-workflow--state-machine-rules)
4. [Strict Multi-Department Lineage Revert Engine](#4-strict-multi-department-lineage-revert-engine)
5. [Chronological Development Milestones (1 to 10)](#5-chronological-development-milestones-1-to-10)
6. [Default Roles, Accounts & Credentials](#6-default-roles-accounts--credentials)
7. [Network Architecture & Port Allocations](#7-network-architecture--port-allocations)
8. [Complete Server Upload & Deployment SOP](#8-complete-server-upload--deployment-sop)
9. [Mobile EAS OTA Update & APK Build Guide](#9-mobile-eas-ota-update--apk-build-guide)
10. [Database Backup & Disaster Recovery Procedures](#10-database-backup--disaster-recovery-procedures)
11. [Git Workflow & Verification Standards](#11-git-workflow--verification-standards)

---

## 1. Executive Overview & Core System Mission

**SpareTrack** is an enterprise manufacturing execution and parts lifecycle tracking platform engineered exclusively for **FAITH AUTOMATION**. The system provides 100% mathematical accountability for automotive tooling spare parts, welding fixtures, jigs, units, and structural fabrications from initial engineering BOM (Bill of Materials) ingestion to final assembly commissioning.

```
       ┌─────────────────────────────────────────────────────────────┐
       │                   ENGINEERING EXCEL BOM                     │
       │     (Project / Jig / Unit / Part / RH-LH Requirements)      │
       └──────────────────────────────┬──────────────────────────────┘
                                      │ Ingestion
                                      ▼
                        ┌───────────────────────────┐
                        │   STORE BAY RECEIVING     │
                        │ (Physical Delivery Check) │
                        └─────────────┬─────────────┘
                                      │ Sent to QC
                                      ▼
                        ┌───────────────────────────┐
                        │    QC BAY INSPECTION      │
                        │ (Arrival & Quality Check) │
                        └──────┬──────┬──────┬──────┘
             Rejected (Defect) │      │      │ Direct Assembly
      ┌────────────────────────┘      │      └──────────────────────┐
      │                               │ Approved (Paint)            │
      ▼                               ▼                             ▼
┌─────────────┐               ┌───────────────┐             ┌───────────────┐
│ REWORK SHOP │               │  PAINT SHOP   │             │ ASSEMBLY BAY  │
│ (Correction)│               │  (Coating)    │             │ (Integration) │
└──────┬──────┘               └───────┬───────┘             └───────┬───────┘
       │ Complete Rework              │ Paint Completed             │
       └──────────────────────────────┼─────────────────────────────┘
                                      ▼
                        ┌───────────────────────────┐
                        │    ASSEMBLY COMPLETED     │
                        │    (100% Green Status)    │
                        └───────────────────────────┘
```

### 5-Level Manufacturing Hierarchy
Every relational query, API payload, web table, and mobile view strictly traverses the 5-level mechanical tree:
$$\text{Project} \longrightarrow \text{Jig} \longrightarrow \text{Unit} \longrightarrow \text{Part Number} \longrightarrow \text{Side} \ (\text{RH} \mid \text{LH} \mid \text{COMMON})$$

* **Level 1: Project**: Top-level customer assembly contract (e.g. `FA-279 - Main Floor Framing`).
* **Level 2: Jig**: Structural tooling fixture frame (e.g. `169961@`).
* **Level 3: Unit**: Sub-assembly station within a jig (e.g. `Unit 00` through `Unit 13`).
* **Level 4: Part Number**: Engineering standard part reference (e.g. `020#R00`, `040#R00`).
* **Level 5: Side**: Geometric orientation—**RH** (Right Hand), **LH** (Left Hand), or **COMMON**.

---

## 2. Strict Architectural Laws & Mathematical Invariants

The following laws are non-negotiable across all code contributions:

### Law 1: PostgreSQL 16 Database Engine ONLY
* All development, testing, and production environments execute on native PostgreSQL 16.
* SQLite, client-side data mockups, or in-memory arrays bypassing PostgreSQL queries are strictly prohibited.

### Law 2: Mathematical Ledger Invariants
1. **Total Required Parts**:
   $$\text{Required Qty} = \sum \text{bom\_requirements.required\_quantity}$$
2. **Total Received Parts**:
   $$\text{Received Qty} = \sum \text{receipt\_items.received\_quantity} \quad \text{where status} \in \{\text{'received'}, \text{'returned\_to\_store'}, \text{'sent\_to\_qc'}, \text{'qc\_received'}\}$$
3. **Pending Store Intake**:
   $$\text{Pending Qty} = \max(0, \text{Required Qty} - \text{Received Qty})$$
4. **Project / Unit Completion**:
   $$\text{Completed (100\% Green)} \iff \text{All required parts have verified assembly\_records.status} = \text{'assembled'}$$

### Law 3: Strict RH vs LH vs COMMON Side Isolation
* Right-Hand (`RH`), Left-Hand (`LH`), and Symmetrical (`COMMON`) components are physically non-interchangeable.
* Requirements, receipts, inspections, rework jobs, paint records, assembly records, and lineage reverts must **NEVER** combine or cross-transfer quantities between sides.

---

## 3. Manufacturing Workflow & State Machine Rules

### 3.1 BOM Import & Engineering Ledger
* Standard FA-279 Excel import schema (`Project Code`, `Jig No`, `Unit No`, `Part No`, `Side`, `Qty`, `Supplier`).
* SHA-256 duplicate file hashing (`checkDuplicateFile`) rejects identical file uploads even if renamed.
* Standardizes part numbers with trailing zero-padding or suffixes (e.g. `020` $\rightarrow$ `020#R00`).

### 3.2 Store Bay Receiving
* Physical vendor shipments are checked against active BOM requirements.
* Partial quantity receipts are supported: receipt items remain active, preserving the remaining pending count without state corruption.
* Once intake is confirmed, parts transition to `sent_to_qc`.

### 3.3 QC Bay (Arrival Verification & Destination Routing)
* **Step 1: Physical Arrival Acceptance**: QC officers confirm receipt (`qc_received`), transferring parts from Store custody to QC custody.
* **Step 2: Quality Inspection**:
  * **Approved for Paint** $\rightarrow$ Routes into `paint_records` queue.
  * **Approved for Direct Assembly** $\rightarrow$ Bypasses Paint and routes into `assembly_records` queue.
  * **Rework Needed** $\rightarrow$ Routes into `rework_records` with defect notes.
  * **QC Rejected / Scrap** $\rightarrow$ Marked as `QC Rejected` (routes to Purchase queue).

### 3.4 Rework Shop (Single-Action Completion)
* Rework operators correct physical defects and execute **COMPLETE REWORK**.
* Completed rework returns quantities back to the **QC Inspection** bay for re-inspection.

### 3.5 Paint Shop & Assembly Bay
* **Paint Shop**: Accepts only parts approved for `PAINT`. Upon completion, parts transfer to the Assembly queue.
* **Assembly Bay**: Integrates parts received from completed `paint_records` and direct `qc_inspections` (`destination = 'ASSEMBLY'`).

---

## 4. Strict Multi-Department Lineage Revert Engine

SpareTrack implements a reverse-lineage state machine (`WorkflowRevertController.php` & `HierarchyService.php`) that preserves historical integrity:

| Department Initiating Revert | Source Entity | Quantity Restored To | Target Department |
| :--- | :--- | :--- | :--- |
| **Store** | `receipt_item` | `bom_item.pending` | Supplier Pending Intake |
| **QC Arrival** | `receipt_item` (`qc_received`) | `sent_to_qc` | Store Bay |
| **Rework** | `rework_record` | `qc_inspection` pending | QC Inspection |
| **Paint** | `paint_record` | `qc_inspection` approved | QC Inspection |
| **Assembly (Direct QC)** | `assembly_record` | `qc_inspection` approved | QC Inspection |
| **Assembly (Painted)** | `assembly_record` | `paint_record` completed | Paint Shop |

### Atomic Bulk Revert API
* **Endpoint:** `POST /api/v1/workflow/bulk-revert`
* **Transactional Safety:** Wrapped inside `DB::transaction()`. If any item exceeds available lineage quantity, the entire batch rolls back with zero partial corruption.

---

## 5. Chronological Development Milestones (1 to 10)

### Milestone 1: Branding & Logo Harmonization
* Configured official **FAITH AUTOMATION** banner logo and square app launcher icon (`logo_app.png`).

### Milestone 2: Manager & Admin Dashboard KPI Simplification
* Replaced arbitrary non-physical metrics with 7 verified real-data mathematical indicators.

### Milestone 3: Manufacturing Workflow & State Machine Hardening
* Enforced strict RH/LH side isolation, dual QC destination routing (`PAINT` vs `ASSEMBLY`), and duplicate BOM hashing.

### Milestone 4: Scoped Excel/PDF Export & Date Columns
* Scoped universal Excel (`.xlsx`) and PDF (`.pdf`) export engines strictly inside the Parts Movement Detail modal.

### Milestone 5: 20-Phase Production Readiness Audit & Verification
* Fixed EAS build configuration, registered Reverb broadcast routes, and expanded PHPUnit feature tests.

### Milestone 6: Android APK Generation & Cleartext Network Setup
* Configured `android:usesCleartextTraffic=true` in `mobile/app.json` and resolved Windows LAN discovery.

### Milestone 7: Strict Multi-Department Lineage Revert Engine & API
* Replaced unstructured Store "Recent Receipts" with a mathematical lineage revert engine and atomic bulk revert endpoints.

### Milestone 8: Mobile UI Modernization & Department Subtabs
* Added dedicated top-level subtab bars across all operational departments:
  - **Store**: `[ 📦 Pending Intake | ↩ Revert ]`
  - **QC**: `[ 📦 1. Arrival | 🔬 2. Inspection | ↩ Revert ]`
  - **Rework**: `[ 🛠️ Rework Queue | ↩ Revert ]`
  - **Paint**: `[ 🎨 Paint Queue | ↩ Revert ]`
  - **Assembly**: `[ ⚙️ Queue | 🏁 Done | ↩ Revert ]`
* Implemented compact Revert Cards and dynamic bottom sticky bulk selection action bars.

### Milestone 9: Deep Unit Hierarchy & Multi-Field Search
* Verified all 14 Units (`Unit 00` to `Unit 13`) in JIG `169961@` for Project `FA-279` (252 parts).
* Extended search queries across JIGs, Units, Standard Part Numbers, Item Numbers, Descriptions, Sizes, and Suppliers.

### Milestone 10: Mobile Terminal Network Config, Live Connection Tester & EAS OTA
* Set primary default host to `192.168.9.200:8080` (Plant Floor LAN).
* Added quick switcher chips (`Wi-Fi (192.168.100.60)` and `Plant LAN (192.168.9.200)`).
* Added `⚡ Test Connection` real-time ping tool.
* Configured launch auto-check for OTA updates and in-app header manual update button (`🔄 Update`).
* Expanded test suite to **81 passing PHPUnit feature tests (801 assertions)**.

---

## 6. Default Roles, Accounts & Credentials

Default development and staging password for all roles: **`password123`**

| Role / Department | User Name | Email Address | Floor Permissions |
| :--- | :--- | :--- | :--- |
| **ADMIN** | System Admin | `admin@sparetrack.internal` | Full System Access, Logs, User Mgmt, BOM Import |
| **MANAGER** | Plant Manager | `manager@sparetrack.internal` | Full Monitoring, Analytics, Exports, Read-Only Queues |
| **STORE** | Store Officer | `store@sparetrack.internal` | Stock Intake, QC Dispatch, Supplier Revert |
| **QC** | QC Inspector | `qc@sparetrack.internal` | Physical Arrival Check, Inspection (Approve/Reject/Rework), Revert |
| **REWORK** | Rework Specialist | `rework@sparetrack.internal` | Rework Processing, Return to QC, Revert |
| **PAINT** | Paint Operator | `paint@sparetrack.internal` | Surface Treatment Completion, Revert |
| **ASSEMBLY** | Assembly Lead | `assembly@sparetrack.internal` | Unit Mechanical Assembly, Revert |
| **PURCHASE** | Purchase Executive | `purchase@sparetrack.internal` | Supplier Return Queue Management |

---

## 7. Network Architecture & Port Allocations

```mermaid
graph TD
    subgraph Client Layer
        A[Mobile Android APK / OTA\nReact Native Expo] -->|Port 8080 / 8085| C[Nginx Gateway :8080]
        B[Web Browser App\nVue 3 + Vite] -->|Port 8080 / 8085| C
    end

    subgraph Infrastructure Layer (Docker on Windows 11 Desktop)
        C --> D[Laravel 11 App Container :9000]
        C --> E[Laravel Reverb Container :8085]
        D --> F[(PostgreSQL 16 Database :5432)]
        D --> G[(Redis Cache & Queue :6379)]
        H[Queue Worker] --> G
        I[Database Adminer :8088] --> F
    end
```

| Service | Port | Host URL / Address | Description |
| :--- | :--- | :--- | :--- |
| **Nginx Web Gateway** | `8080` | `http://192.168.9.200:8080` | Web SPA & Mobile REST API Gateway |
| **Reverb WebSockets** | `8085` | `ws://192.168.9.200:8085` | Real-time event broadcasting |
| **Database Adminer** | `8088` | `http://192.168.9.200:8088` | Web PostgreSQL Database Browser |
| **PostgreSQL Database** | `5432` | `192.168.9.200:5432` | Internal Docker database port |

---

## 8. Complete Server Upload & Deployment SOP

### 8.1 Method A: 1-Click Server Update (Recommended)
On the target production Windows server machine:
1. Open PowerShell or Command Prompt inside `C:\SpareTrack`.
2. Execute:
   ```bat
   .\update_server.bat
   ```

*(On Linux / macOS servers, run `./update_server.sh`)*

---

### 8.2 Method B: All-in-One Command (PowerShell)
Copy and paste this single command into PowerShell on the server:

```powershell
git stash; git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx
```

---

### 8.3 Method C: Step-by-Step Manual Deployment Guide

```powershell
# Step 1: Navigate to repository root
cd C:\SpareTrack

# Step 2: Stash any local temporary files
git stash

# Step 3: Pull latest verified code from main branch
git pull origin main

# Step 4: Recompile Web Frontend assets (Vue 3 / Vite)
npm install
npm run build

# Step 5: Execute database migrations inside Docker (Zero data loss)
docker exec -t sparetrack-app php artisan migrate --force

# Step 6: Clear and re-warm Laravel application caches
docker exec -t sparetrack-app php artisan optimize:clear
docker exec -t sparetrack-app php artisan config:cache
docker exec -t sparetrack-app php artisan route:cache
docker exec -t sparetrack-app php artisan view:cache

# Step 7: Restart background queue worker daemon
docker exec -t sparetrack-app php artisan queue:restart

# Step 8: Restart application containers (PostgreSQL database remains untouched)
docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx

# Step 9: Verify feature test suite integrity
docker exec -t sparetrack-app php artisan test
```

---

## 9. Mobile EAS OTA Update & APK Build Guide

### 9.1 Publish Instant OTA Updates (No APK Re-installation Required)
To push live JavaScript / UI / state machine updates directly to department floor phones:

```powershell
cd mobile

# Publish to Preview channel (Staging test devices)
npx eas-cli update --channel preview --message "Store top subtabs, Unit navigation stability, Bulk revert"

# Publish to Production channel (Factory floor devices)
npx eas-cli update --channel production --message "Store top subtabs, Unit navigation stability, Bulk revert"
```

*When the mobile app is opened, it automatically downloads and prompts to restart with the new update.*

### 9.2 Generate New Standalone Android APK
When native dependencies or Android manifest settings change:

```powershell
cd mobile
npx eas-cli build --profile preview --platform android
```

---

## 10. Database Backup & Disaster Recovery Procedures

### 10.1 Generate On-Demand Database Backup
```powershell
cd C:\SpareTrack
powershell -ExecutionPolicy Bypass -File ".\scripts\backup.ps1"
```
*Creates a timestamped SQL dump in `backups/sparetrack_db_backup_YYYYMMDD_HHMMSS.sql`.*

### 10.2 Restore Database from Backup
```powershell
cd C:\SpareTrack
powershell -ExecutionPolicy Bypass -File ".\scripts\restore.ps1"
```

---

## 11. Git Workflow & Verification Standards

To guarantee production stability, all development follows this strict workflow:

1. **Create Isolated Feature Branch**:
   ```bash
   git checkout -b feat/your-feature-name
   ```
2. **Execute Tests & Metro Compilation**:
   ```bash
   # 1. Run PHPUnit backend test suite (Must pass 100%)
   php artisan test

   # 2. Verify Metro React Native bundler
   cd mobile && npx expo export --output-dir dist --dump-assetmap --platform android
   ```
3. **Commit & Push Feature Branch**:
   ```bash
   git add -A
   git commit -m "feat(module): description of changes"
   git push -u origin feat/your-feature-name
   ```
4. **Merge to Main & Push to Origin**:
   ```bash
   git checkout main
   git merge feat/your-feature-name
   git push origin main
   ```
5. **Publish EAS OTA Update to Floor Phones**:
   ```bash
   cd mobile
   npx eas-cli update --channel preview --message "Release notes"
   npx eas-cli update --channel production --message "Release notes"
   ```
