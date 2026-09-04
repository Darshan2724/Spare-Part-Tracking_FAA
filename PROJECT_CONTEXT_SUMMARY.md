# FAITH AUTOMATION — SpareTrack Master Project Context & Operations Knowledge Base

```yaml
Project: SpareTrack (Industrial Spare Parts Tracking & Workflow Execution System)
Document: PROJECT_CONTEXT_SUMMARY.md
Status: Canonical Project Context & Universal AI Knowledge Base
Last Updated: September 04, 2026
Last Updated By: Antigravity
Version: 2.6.1
Change Confidence: VERIFIED (100% Codebase, Schema, Migration & Test Alignment)
```

> [!IMPORTANT]
> **UNIVERSAL AI AGENT BOOTSTRAP INSTRUCTION**:
> This document is the **single authoritative source of truth** for the SpareTrack architecture, database relationships, operational workflows, business logic, calculation rules, API contracts, deployment configuration, and safety guidelines.
> **When starting a new session or chat, read this file first.** You do **NOT** need to scan the entire database or rediscover the codebase from scratch for routine development, troubleshooting, or feature implementation. Use the documented canonical models, services, formulas, and rules. Inspect only specific database rows or source files required for your immediate task.

---

## Table of Contents
1. [Project Overview](#1-project-overview)
2. [Current Production Status](#2-current-production-status)
3. [System Architecture](#3-system-architecture)
4. [Repository Structure](#4-repository-structure)
5. [Frontend Architecture](#5-frontend-architecture)
6. [Backend Architecture](#6-backend-architecture)
7. [Database Architecture](#7-database-architecture)
8. [Canonical Data Models](#8-canonical-data-models)
9. [Project -> Jig -> Unit -> Part Hierarchy](#9-project---jig---unit---part-hierarchy)
10. [Regular BOM Workflow](#10-regular-bom-workflow)
11. [ECN Workflow](#11-ecn-workflow)
12. [Department Workflow](#12-department-workflow)
13. [Store Rules](#13-store-rules)
14. [QC Rules](#14-qc-rules)
15. [Rework Rules](#15-rework-rules)
16. [Paint Rules](#16-paint-rules)
17. [Assembly Rules](#17-assembly-rules)
18. [Revert Rules](#18-revert-rules)
19. [Purchase Department](#19-purchase-department)
20. [Supplier Management](#20-supplier-management)
21. [Supplier Allocation](#21-supplier-allocation)
22. [Supplier Overview and Analytics](#22-supplier-overview-and-analytics)
23. [Dashboard KPI Definitions](#23-dashboard-kpi-definitions)
24. [Mobile App Architecture](#24-mobile-app-architecture)
25. [Website Architecture](#25-website-architecture)
26. [API Contracts](#26-api-contracts)
27. [Authentication](#27-authentication)
28. [Network & Deployment Configuration](#28-network--deployment-configuration)
29. [Realtime and Cache Architecture](#29-realtime-and-cache-architecture)
30. [Import & Incremental BOM Rules](#30-import--incremental-bom-rules)
31. [Data Integrity Rules](#31-data-integrity-rules)
32. [Deletion Rules](#32-deletion-rules)
33. [Test Data Rules](#33-test-data-rules)
34. [Git & GitHub Workflow](#34-git--github-workflow)
35. [Known Bugs and Resolved Issues](#35-known-bugs-and-resolved-issues)
36. [Current Known Issues](#36-current-known-issues)
37. [Recent Changes / Changelog](#37-recent-changes--changelog)
38. [Production Data Protection Rules](#38-production-data-protection-rules)
39. [Safe Investigation Procedure](#39-safe-investigation-procedure)
40. [AI Agent Instructions](#40-ai-agent-instructions)

---

## 1. Project Overview `[VERIFIED]`

### 1.1 Purpose & Mission
**SpareTrack** is an enterprise manufacturing execution system (MES) and parts lifecycle tracking platform engineered exclusively for **FAITH AUTOMATION**. It provides **100% mathematical accountability** for automotive tooling fixtures, welding jigs, robotic sub-assemblies (units), structural fabrications, and spare components.

The platform eliminates paper tally sheets, fragmented spreadsheets, and uncoordinated department handoffs by providing a single, real-time relational ledger tracking components from engineering Bill of Materials (BOM) ingestion through vendor receiving, Quality Control (QC) inspection, defect rework loops, surface coating (Paint), mechanical assembly, and customer commissioning.

```
       ┌─────────────────────────────────────────────────────────────┐
       │                   ENGINEERING EXCEL BOM                     │
       │     (Project / Jig / Unit / Part / RH-LH Requirements)      │
       └──────────────────────────────┬──────────────────────────────┘
                                      │ Ingestion / Revision Diffing
                                      ▼
                        ┌───────────────────────────┐
                        │   STORE BAY RECEIVING     │
                        │ (Physical Delivery Check) │
                        └─────────────┬─────────────┘
                                      │ Sent to QC / Auto-Arrival
                                      ▼
                        ┌───────────────────────────┐
                        │    QC BAY INSPECTION      │
                        │ (Arrival & Quality Check) │
                        └──────┬──────┬──────┬──────┘
             QC Defect │      │      │ Direct Assembly
      ┌────────────────┘      │      └──────────────────────┐
      │                       │ Approved (Paint)            │
      ▼                       ▼                             ▼
┌─────────────┐       ┌───────────────┐             ┌───────────────┐
│ REWORK SHOP │       │  PAINT SHOP   │             │ ASSEMBLY BAY  │
│ (Correction)│       │  (Coating)    │             │ (Integration) │
└──────┬──────┘       └───────┬───────┘             └───────┬───────┘
       │ Complete Rework      │ Paint Completed             │
       └──────────────────────┼─────────────────────────────┘
                              ▼
                        ┌───────────────────────────┐
                        │    ASSEMBLY COMPLETED     │
                        │    (100% Green Status)    │
                        └───────────────────────────┘
```

### 1.2 User Roles & Operational Portals

| Portal / Layer | Primary Users | Department Scope | Key Functions |
|---|---|---|---|
| **Web Dashboard** | Plant Manager, System Admin | Executive Management, Engineering | High-density portfolio monitoring, 11 KPI drill-downs, BOM/ECN import, PDF/Excel export, audit logs. |
| **Purchase Desk** | Purchase Executives, Sourcing | Purchase, Supply Chain | Unit supplier allocation (BASE/WELDMENT/CHILD PART), cross-project overview table, supplier master CRUD, QC rejected reorder queue. |
| **Mobile Floor App** | Shop Floor Operators, Supervisors | Store, QC, Rework, Paint, Assembly | Physical barcode/item lookup, quantity steppers, bulk receiving, QC pass/defect split, one-click rework completion, assembly sign-off, lineage revert. |

### 1.3 Core Technology Stack

* **Backend API:** Laravel 11.x (PHP 8.3+, Eloquent ORM, REST API, Laravel Sanctum, Spatie Permission)
* **Relational Database:** PostgreSQL 16 (Native relational constraints, compound unique indexes, pg_trgm search)
* **Realtime Broadcasting:** Laravel Reverb (WebSocket server on `:8085`) backed by Redis 7
* **Web Single Page App (SPA):** Vue 3.4+ (Composition API, Vite, Pinia state management, Bootstrap 5.3 theme matching WebErpMesv2 design language, Chart.js)
* **Floor Mobile Application:** React Native 0.74.5 / Expo SDK 51 (Standalone Android APK with EAS Over-The-Air updates)
* **Reporting Engines:** PhpOffice/PhpSpreadsheet (`.xlsx` streaming) + DomPDF (Inspection reports)
* **Deployment Model:** On-Premise Docker Compose (7 container microservices) running on Windows 11 LAN Server (`192.168.9.200:8080`)

---

## 2. Current Production Status `[VERIFIED]`

### 2.1 Feature Stability Matrix

| Module / Feature | Status | Environment | Notes / Verification |
|---|---|---|---|
| **Regular BOM Ingestion & Diffing** | **STABLE** | Production | SHA-256 duplicate hashing, filename uniqueness, incremental revision updates. |
| **ECN Ingestion & Isolated Ledger** | **STABLE** | Production | Side normalization (`LA/RA/AL/AR/L/R`), dedicated `/ecn-reports`, auto-vanishing badges. |
| **Store Bay Receiving** | **STABLE** | Production | Partial receipts, automatic Store-to-QC Arrival visibility, vendor revert. |
| **QC Arrival & Inspection Routing** | **STABLE** | Production | Dual destination (`PAINT` vs `ASSEMBLY`), defect routing to Rework, rejection to Purchase. |
| **Rework Single-Action Workflow** | **STABLE** | Production | Atomic transfer back to QC Inspection queue (`status = 'qc_received'`). |
| **Paint Shop Batch Completion** | **STABLE** | Production | Batch coating records, direct flow into Assembly Bay queue. |
| **Assembly Bay & Green Propagation** | **STABLE** | Production | 100% mechanical completion, cascading green indicators (Part $\rightarrow$ Unit $\rightarrow$ Jig $\rightarrow$ Project). |
| **Lineage-Based Revert Engine** | **STABLE** | Production | Multi-department reverse state machine, atomic bulk revert, multi-format ID resolver. |
| **Supplier Allocation (3 Categories)** | **STABLE** | Production | Two-panel workspace, BASE / WELDMENT / CHILD PART, today $\pm 3$ days date validation. |
| **Supplier Master & Excel Import** | **STABLE** | Production | Multi-phone numbers (`supplier_phones`), safe deletion protection for active assignments. |
| **Management Dashboard & KPI Drilldown** | **STABLE** | Production | 11 canonical KPI drilldown datasets, Excel/PDF streaming exports. |
| **Mobile Floor App (Expo EAS OTA)** | **STABLE** | Production | Shorthand IP normalizer (`100.30`, `9.200`), auto-port `:8080`, quantity steppers. |
| **Automated Test Suite** | **STABLE** | Testing/CI | **158 passed feature tests (2,410 assertions)** with 0 failures. |

### 2.2 Production Environment vs Experimental
* **Production Environment:** On-Premise Windows 11 Desktop server running Docker Compose (`192.168.9.200:8080`). Active production projects: `FA-273` and `FA-279`.
* **Mobile Distribution:** Standalone Android APK (`com.faithautomation.sparetrack`) installed on plant floor terminals. Instant OTA JavaScript updates published via Expo Application Services (`npx eas-cli update --branch production`).
* **Rule:** No test databases, SQLite mockups, or in-memory arrays are used. All test suites run against an isolated test schema in native PostgreSQL 16.

---

## 3. System Architecture `[VERIFIED]`

### 3.1 Architectural Flow Diagram

```mermaid
graph TD
    subgraph Client Layer
        M[Mobile Android APK / OTA\nReact Native Expo SDK 51] -->|REST API :8080| N[Nginx Gateway :8080]
        W[Web Browser SPA\nVue 3 + Vite + Pinia] -->|REST API :8080| N
        M -.->|WebSocket :8085| R[Laravel Reverb :8085]
        W -.->|WebSocket :8085| R
    end

    subgraph Infrastructure Layer (Docker Compose on On-Premise Server)
        N -->|FastCGI / Proxy| A[Laravel 11 App Container :9000]
        A -->|ACID Transactions| DB[(PostgreSQL 16 Database :5432)]
        A -->|Cache & Queues| RD[(Redis 7 Cache :6379)]
        Q[Queue Worker Daemon] -->|Process Jobs| RD
        Q -->|Update Ledger| DB
        A -->|Broadcast Events| R
        ADM[Database Adminer :8088] -->|Inspect| DB
    end
```

### 3.2 Authoritative Layer & Data Flow
1. **Authoritative Ledger:** PostgreSQL 16 is the **sole authoritative source of truth**. All mathematical calculations, state machines, allocation constraints, and inventory balances are computed in the Laravel service layer (`QuantityCalculationService`, `HierarchyService`, `EcnQuantityCalculationService`, `SupplierLoadService`).
2. **Client Layer:** Vue 3 web SPA and React Native mobile clients are strictly **display and capture interfaces**. Mobile cache or Vue component state is never trusted for business calculations.
3. **Data Flow Pipeline:**
   $$\text{User Action (Mobile/Web)} \longrightarrow \text{API Endpoint} \longrightarrow \text{Controller Gate} \longrightarrow \text{Service Layer (DB Transaction)} \longrightarrow \text{PostgreSQL} \longrightarrow \text{Reverb Broadcast} \longrightarrow \text{Clients Update}$$

---

## 4. Repository Structure `[VERIFIED]`

```
SpareTrack/
├── app/
│   ├── Events/                              # WebSocket Broadcasting Events
│   │   ├── AssemblyUpdated.php              # Realtime event: Assembly bay progression
│   │   ├── EcnUpdated.php                   # Realtime event: ECN batch / requirement changes
│   │   ├── PaintUpdated.php                 # Realtime event: Paint shop completion
│   │   ├── PartReverted.php                 # Realtime event: Reverse lineage transition
│   │   ├── ProjectStatusChanged.php         # Realtime event: Project level progression
│   │   ├── QcInspected.php                  # Realtime event: QC inspection routing
│   │   ├── ReworkUpdated.php                # Realtime event: Rework completion -> QC
│   │   ├── StoreReceived.php                # Realtime event: Store bay receiving
│   │   ├── SupplierAssignmentUpdated.php    # Realtime event: Supplier allocation update
│   │   └── SupplierDeactivated.php          # Realtime event: Supplier inactivation
│   │
│   ├── Http/Controllers/                   # API Controllers
│   │   ├── Admin/SystemLogController.php    # Admin diagnostic log queries
│   │   ├── Auth/AuthController.php          # Sanctum authentication & user profile
│   │   ├── AssemblyController.php           # Mechanical assembly queue & completion
│   │   ├── BomImportController.php          # Excel BOM import, preview, history & deletion
│   │   ├── DashboardController.php          # Management KPIs, hierarchy tree & drilldowns
│   │   ├── EcnDashboardController.php       # ECN summary metrics & drilldowns
│   │   ├── EcnImportController.php          # ECN Excel import & history
│   │   ├── EcnWorkflowController.php        # ECN department handoffs & mixed revert
│   │   ├── ExportController.php             # PhpSpreadsheet (.xlsx) & PDF streaming
│   │   ├── HealthController.php             # Docker / DB / Redis health check API
│   │   ├── PaintController.php              # Paint shop batch recording
│   │   ├── PurchaseQueueController.php      # QC rejected reorder queue & export
│   │   ├── QcController.php                 # QC arrival verification & inspection split
│   │   ├── ReworkController.php             # Rework completion & return to QC
│   │   ├── StoreController.php              # Store bay stock intake & QC dispatch
│   │   ├── SupplierAllocationController.php # Unit supplier allocation (BASE/WELDMENT/CHILD)
│   │   ├── SupplierAnalyticsController.php  # Vendor performance & ranking analytics
│   │   ├── SupplierController.php           # Supplier master CRUD & Excel import
│   │   └── WorkflowRevertController.php     # Strict multi-department lineage revert engine
│   │
│   ├── Models/                              # Eloquent Relational Models (25 Canonical Entities)
│   │   ├── AssemblyRecord.php               # Mechanical assembly records
│   │   ├── BomImportBatch.php               # Regular BOM file import ledger
│   │   ├── BomItem.php                      # BOM part master (jig, unit, part_no)
│   │   ├── BomRequirement.php               # Side requirements (RH/LH/COMMON qty)
│   │   ├── Department.php                   # Floor department master
│   │   ├── EcnImportBatch.php               # ECN file import ledger
│   │   ├── EcnReceiptItem.php               # ECN physical inventory receipts
│   │   ├── EcnRequirement.php               # ECN side requirements & current state
│   │   ├── EcnWorkflowEvent.php             # ECN audit log event history
│   │   ├── EcnWorkflowRecord.php            # ECN department queue tracking
│   │   ├── PaintRecord.php                  # Paint shop coating records
│   │   ├── Project.php                      # Customer project master
│   │   ├── PurchaseQueueItem.php            # Rejected parts reorder queue
│   │   ├── QcInspection.php                 # QC quality inspection records
│   │   ├── Receipt.php                      # Store receipt batch header
│   │   ├── ReceiptItem.php                  # Physical inventory tracking ledger
│   │   ├── ReworkRecord.php                 # Defect correction tracking
│   │   ├── Supplier.php                     # Vendor profiles & active status
│   │   ├── SupplierAssignment.php           # Active unit-category supplier allocations
│   │   ├── SupplierAssignmentHistory.php    # Allocation change audit history
│   │   ├── SupplierImport.php               # Supplier Excel bulk import ledger
│   │   ├── SupplierPhone.php                # Supplier multi-phone register
│   │   ├── SystemLog.php                    # Diagnostic activity & error log
│   │   ├── User.php                         # System accounts & Sanctum tokens
│   │   └── WorkflowEvent.php                # Universal workflow audit event history
│   │
│   └── Services/                            # Core Business Logic & Canonical Math Engines
│       ├── BomImportService.php             # Excel BOM parser, duplicate checker & diff engine
│       ├── CanonicalCurrentStateService.php # Single-source current physical location resolver
│       ├── EcnBulkSplitService.php          # Bulk mixed regular/ECN selection processor
│       ├── EcnImportService.php             # ECN Excel parser & side normalizer
│       ├── EcnQuantityCalculationService.php# ECN KPI formulas & residency counters
│       ├── EcnWorkflowService.php           # ECN state machine & multi-department revert
│       ├── ExportService.php                # PhpSpreadsheet (.xlsx) & DomPDF generator
│       ├── HierarchyService.php             # 5-level mechanical tree & green status propagation
│       ├── KpiDrilldownService.php          # 11 KPI drill-down dataset queries
│       ├── ProjectIdentityResolver.php      # Project matching & code normalization
│       ├── QuantityCalculationService.php   # Authoritative mathematical ledger & invariants
│       ├── SupplierAnalyticsService.php     # Vendor ranking & fulfillment analytics
│       ├── SupplierImportService.php        # Supplier Excel import & safe deletion engine
│       ├── SupplierLoadService.php          # Supplier assignment load & capacity KPI
│       └── SystemLogService.php             # Centralized diagnostic logger
│
├── database/
│   ├── migrations/                          # 46 Schema Migrations (ACID constraints & indexes)
│   └── seeders/                             # Production user seeders & initial departments
│
├── resources/
│   ├── js/                                  # Vue 3 SPA Application
│   │   ├── components/                      # High-density UI Components
│   │   │   ├── SupplierAddTab.vue           # Supplier Master register & Excel import tab
│   │   │   ├── SupplierAllocationTab.vue    # Split 2-panel Unit supplier allocation workspace
│   │   │   └── SupplierOverviewTab.vue      # Cross-project supplier allocation table
│   │   ├── router/index.js                  # Vue Router route guards & path definitions
│   │   ├── stores/                          # Pinia State Management Stores (auth, etc.)
│   │   └── views/                           # High-Density Operational Views
│   │       ├── Assembly.vue                 # Web Assembly bay tracking
│   │       ├── BomImport.vue                # BOM upload, reconciliation diff & deletion
│   │       ├── Dashboard.vue                # Main 11-KPI Terminal & Drilldown modal
│   │       ├── EcnReports.vue               # Dedicated 9-KPI ECN Analytics page
│   │       ├── Login.vue                    # Sanctum authentication view
│   │       ├── Paint.vue                    # Web Paint shop tracking
│   │       ├── PurchaseQueue.vue            # Purchase Desk (4-tab master view)
│   │       ├── Qc.vue                       # Web QC inspection interface
│   │       ├── Reports.vue                  # Analytics & export reports
│   │       ├── Rework.vue                   # Web Rework queue tracking
│   │       ├── Store.vue                    # Web Store bay intake interface
│   │       ├── Suppliers.vue                # Standalone supplier management view
│   │       ├── SystemLogs.vue               # Admin diagnostic log browser
│   │       └── WorkflowHub.vue              # Universal department navigation
│
├── mobile/                                  # React Native / Expo Mobile Application
│   ├── App.js                               # Floor terminal shell, steppers & subtabs
│   ├── app.json                             # Expo EAS config, cleartext network & OTA URL
│   ├── eas.json                             # EAS build profiles (preview & production APK)
│   └── src/api/client.js                    # Axios API client, IP normalizer & ECN methods
│
├── routes/
│   ├── api.php                              # Versioned API routes (`/api/v1/...`)
│   └── web.php                              # Web SPA entry route
│
├── tests/Feature/                           # 30 Comprehensive PHPUnit Feature Test Suites
│   ├── AssemblyWorkflowStabilityTest.php
│   ├── BomImportDeletionTest.php
│   ├── BomIncrementalImportTest.php
│   ├── EcnComprehensiveFixTest.php
│   ├── EcnImportTest.php
│   ├── EcnQcInspectionAndCardVisibilityTest.php
│   ├── EcnRejectIdempotencyAndRevertContractTest.php
│   ├── KpiDrilldownTest.php
│   ├── MainDashboardEcnIndicatorTest.php
│   ├── MobileConnectivityAndStoreQcArrivalTest.php
│   ├── QuantityCalculationHierarchyTest.php
│   ├── SupplierLoadAndMultiUnitAllocationTest.php
│   ├── SupplierManagementAndAllocationTest.php
│   ├── WorkflowIntegrityTest.php
│   └── WorkflowStrictRevertSystemTest.php
│
├── docker/                                  # Nginx, PHP-FPM & Supervisor Docker configs
├── docker-compose.yml                       # 7 Docker microservice definitions
├── update_server.bat                        # Windows 1-Click Server Update Script
├── update_server.ps1                        # PowerShell 1-Click Server Update Script
└── update_server.sh                         # Linux 1-Click Server Update Script
```

---

## 5. Frontend Architecture `[VERIFIED]`

### 5.1 Technology Stack & Design System
* **Framework:** Vue 3.4+ utilizing the Composition API (`<script setup>`).
* **Styling & Theme:** Bootstrap 5.3 customized to match the visual language of **WebErpMesv2** (enterprise manufacturing ERP/MES styling, dense data grids, subtle borders, high-contrast badges, glassmorphism cards).
* **State Management:** Pinia stores (`stores/auth.js`) managing active user session, token persistence, and role capabilities.
* **Routing:** Vue Router (`router/index.js`) with client-side authentication guards (`requiresAuth`, `guestOnly`, `requiresAdmin`).

### 5.2 Key Views & Components
1. **`Dashboard.vue`**: Executive manufacturing terminal. Contains Global Filters (Project, Side, Date Range), 3 Portfolio KPI Cards (or Selected Project Banner), 9 Operational KPI Cards, Drilldown Modal with streaming Excel/PDF exports, and Deep Hierarchy Drilldown Trees.
2. **`PurchaseQueue.vue`**: 4-Tab Unified Purchase Desk:
   * **Tab 1: `SupplierAllocationTab.vue`** (Project $\rightarrow$ Jig $\rightarrow$ Two-Panel Unit Workspace).
   * **Tab 2: `SupplierOverviewTab.vue`** (Cross-project unified allocation table).
   * **Tab 3: `SupplierAddTab.vue`** (Supplier Master CRUD, multi-phone numbers, Excel bulk import).
   * **Tab 4: `Rejected Parts`** (QC rejected parts reorder queue with status workflow).
3. **`BomImport.vue`**: Drag-and-drop Excel BOM/ECN import with pre-commit reconciliation diffing, duplicate alerts, and safe batch deletion impact modals.
4. **`EcnReports.vue`**: Dedicated 9-KPI executive analytics terminal strictly for isolated ECN parts.
5. **Department Web Views (`Store.vue`, `Qc.vue`, `Rework.vue`, `Paint.vue`, `Assembly.vue`)**: Full desktop queue management with subtabs (`Active Queue` / `Lineage Revert`).

---

## 6. Backend Architecture `[VERIFIED]`

### 6.1 Framework & Pipeline
* **Framework:** Laravel 11.x running PHP 8.3+.
* **Architecture Pattern:** Controller-Service-Repository architecture. Controllers handle validation and HTTP responses, while heavy mathematical and state-machine business logic resides strictly inside dedicated domain services (`app/Services/`).
* **Database Transactions:** Every multi-row state transition, import batch, bulk revert, and supplier allocation is wrapped inside `DB::transaction(...)` with row-level locking (`lockForUpdate()`) to eliminate race conditions.
* **Audit Logging:** Every administrative action, batch deletion, and state revert generates structured diagnostic records via `SystemLogService` and `workflow_events` / `ecn_workflow_events`.

---

## 7. Database Architecture `[VERIFIED]`

### 7.1 Database Engine & Persistence
* **Engine:** PostgreSQL 16 Alpine native container.
* **Storage Safety:** Persisted in named Docker volume `postgres_data`. Code updates, cache clears, and container restarts **never touch or drop database volumes**.
* **Prohibited in Production:** `php artisan migrate:fresh` or any unverified drop command is strictly forbidden.

### 7.2 Indexing & Performance Strategy
* **Trigram Indexes (`pg_trgm`):** Accelerated multi-field fuzzy search across `bom_items(standard_part_no, item_no, jig_no, unit_no, part_description)` and `suppliers(name, code, contact_person)`.
* **Unique Composite BOM Index:** `bom_items(project_id, jig_no, unit_no, standard_part_no, part_type)` ensures parts across different BOM types (`MFG`, `BOP`, `STD`) remain strictly isolated without collisions.
* **Composite Indexes:** Optimized for high-frequency floor queue filtering:
  - `receipt_items(bom_item_id, side, status)`
  - `receipt_items(status, received_quantity)`
  - `qc_inspections(bom_item_id, side, approved_quantity, destination)`
  - `ecn_requirements(project_id, current_state, side)`
  - `supplier_assignments(project_id, jig_no, unit_no, category, status)`

---

## 8. Canonical Data Models `[VERIFIED]`

| Model Name | Purpose | Primary Key | Key Foreign Keys | Current State vs History | Production Critical |
|---|---|---|---|---|---|
| `Project` | Customer tooling assembly contract | `id` (int) | None | Current State | **YES** |
| `BomItem` | Master BOM part entry with `part_type` (`MFG` \| `BOP` \| `STD`) | `id` (int) | `project_id`, `supplier_id`, `import_batch_id` | Current State | **YES** |
| `BomRequirement` | Side-isolated required quantity | `id` (int) | `bom_item_id` | Current State | **YES** |
| `BomImportBatch` | BOM file upload ledger with `bom_type` (`MFG` \| `BOP` \| `STD`) | `id` (int) | `project_id`, `imported_by` | History / Audit | **YES** |
| `Receipt` | Store delivery batch header | `id` (int) | `project_id`, `received_by` | Current State | **YES** |
| `ReceiptItem` | Physical inventory piece ledger | `id` (int) | `receipt_id`, `bom_item_id` | Current State | **YES** |
| `QcInspection` | QC quality inspection records | `id` (int) | `bom_item_id`, `receipt_item_id`, `inspector_id` | Current State | **YES** |
| `ReworkRecord` | Defect rework jobs | `id` (int) | `bom_item_id`, `qc_inspection_id` | Current State | **YES** |
| `PaintRecord` | Surface coating batch records | `id` (int) | `bom_item_id`, `qc_inspection_id` | Current State | **YES** |
| `AssemblyRecord` | Final mechanical assembly records | `id` (int) | `bom_item_id`, `paint_record_id`, `qc_inspection_id` | Current State | **YES** |
| `PurchaseQueueItem`| QC rejected parts reorder queue | `id` (int) | `project_id`, `bom_item_id`, `qc_inspection_id`, `supplier_id` | Current State | **YES** |
| `WorkflowEvent` | Universal workflow history log | `id` (int) | `project_id`, `bom_item_id`, `user_id` | History / Audit | No |
| `EcnImportBatch` | ECN file upload ledger | `id` (int) | `project_id`, `imported_by` | History / Audit | **YES** |
| `EcnRequirement` | Isolated ECN requirement entry | `id` (int) | `project_id`, `ecn_import_batch_id` | Current State | **YES** |
| `EcnReceiptItem` | Physical ECN intake ledger | `id` (int) | `ecn_requirement_id`, `project_id` | Current State | **YES** |
| `EcnWorkflowRecord`| Active ECN department tracking | `id` (int) | `ecn_requirement_id`, `project_id` | Current State | **YES** |
| `EcnWorkflowEvent` | ECN transition audit log | `id` (int) | `ecn_requirement_id`, `user_id` | History / Audit | No |
| `Supplier` | Master vendor register | `id` (int) | `supplier_import_id` | Current State | **YES** |
| `SupplierPhone` | Normalized supplier phone numbers | `id` (int) | `supplier_id` | Current State | **YES** |
| `SupplierAssignment`| Active Unit-category allocations | `id` (int) | `project_id`, `supplier_id`, `created_by` | Current State | **YES** |
| `SupplierAssignmentHistory` | Allocation audit trail | `id` (int) | `supplier_assignment_id`, `project_id`, `changed_by` | History / Audit | **YES** |
| `SupplierImport` | Supplier Excel import ledger | `id` (int) | `imported_by` | History / Audit | **YES** |
| `SystemLog` | Admin diagnostic activity log | `id` (int) | `user_id` | History / Audit | No |
| `User` | System user accounts & roles | `id` (int) | None | Current State | **YES** |
| `Department` | Plant floor department master | `id` (int) | None | Reference | **YES** |

---

## 9. Project -> Jig -> Unit -> Part Hierarchy `[VERIFIED]`

### 9.1 5-Level Mechanical Tree
Every query, web table, API payload, and mobile view traverses the strict 5-level mechanical tree:
$$\text{Project} \longrightarrow \text{Jig} \longrightarrow \text{Unit} \longrightarrow \text{Standard Part Number} \longrightarrow \text{Side} \ (\text{RH} \mid \text{LH} \mid \text{COMMON})$$

* **Level 1: Project**: Customer assembly contract (e.g. `FA-273`, `FA-279 - Main Floor Framing`).
* **Level 2: Jig**: Structural tooling fixture frame code (e.g. `169961@`).
  * **Jig Type Classification:**
    * **`SIDE_SPECIFIC`**: Jigs containing parts with LH (Left Hand) and/or RH (Right Hand) variants. Rendered with dual LH/RH side panels.
    * **`COMMON`**: Symmetrical or single tooling fixtures where parts have no LH/RH distinction (BOM Side is blank, empty, `NULL`, `C`, `COM`, or `COMMON`). Rendered with a single Common Tooling section.
  * **Jig Exclusivity Rule:** A Jig must be exclusively `SIDE_SPECIFIC` or `COMMON`, never both. Mixing blank and LH/RH rows in the same Jig is rejected during BOM import.
* **Level 3: Unit**: Mechanical sub-assembly station (e.g. `Unit 00` to `Unit 13`).
  * **Common Units (`has_common: true`):** Build a single `sides['COMMON']` branch with zero LH/RH duplication, preserving mathematical conservation without double-counting.
* **Level 4: Standard Part Number**: Engineering part number with standard trailing zero/revision padding (e.g. `020#R00`, `040#R00`).
* **Level 5: Side**: Geometric symmetry—**RH** (Right Hand), **LH** (Left Hand), or **COMMON** (Symmetrical / Single Tooling).

### 9.2 Cascading Green Status Invariants
* **Part Done:** A part requirement is complete if and only if $\text{assembled\_quantity} \ge \text{required\_quantity}$.
* **Side Done:** An RH/LH or COMMON side group is complete if and only if every part requirement for that side is complete.
* **Unit Done (100% Green):**
  * For **Side-Specific Units**: Complete if and only if **both required RH and LH sides** are 100% completed.
  * For **Common Units**: Complete if and only if the **Common side** is 100% completed.
* **Jig Done (100% Green):** A Jig turns green if and only if **every Unit inside that Jig** is 100% completed.
* **Project Done (100% Green):** A Project turns green if and only if **every Jig inside that Project** is 100% completed.

---

## 10. Regular BOM Workflow `[VERIFIED]`

### 10.1 Ingestion & Standards
* **Format:** Excel (`.xlsx`, `.xls`) with 6 mandatory columns: `Project Code`, `Jig No`, `Unit No`, `Part No`, `Side`, `Qty` (optional: `Supplier`, `Description`, `Size`).
* **Duplicate Protection:**
  1. **Exact Filename Check:** Rejects upload if a file with the identical filename has already been imported.
  2. **SHA-256 Content Hash:** Computes `hash_file('sha256', $path)` to block identical files even if renamed.
* **Part Number Normalization:** Standardizes part numbers with trailing zero-padding or suffixes (e.g. `020` $\rightarrow$ `020#R00`).
* **Incremental Revision Diffing:**
  - `NEW`: Newly added Jig, Unit, or Part requirement $\rightarrow$ Inserted into database.
  - `MATCH`: Unchanged requirement $\rightarrow$ Preserved with current operational quantities intact.
  - `CONFLICT`: Requirement quantity reduced below already received count $\rightarrow$ Flagged for administrative review.

---

## 11. ECN Workflow `[VERIFIED]`

### 11.1 Strict Isolation Architecture
Engineering Change Notices (ECN) represent distinct engineering modifications and are **strictly isolated** from baseline BOM requirements:
* Stored in dedicated tables (`ecn_import_batches`, `ecn_requirements`, `ecn_receipt_items`, `ecn_workflow_records`, `ecn_workflow_events`).
* **Side Normalization:**
  - `LA`, `AL`, `L` $\longrightarrow$ Normalized to **`LH`** (Family: `LEFT`).
  - `RA`, `AR`, `R` $\longrightarrow$ Normalized to **`RH`** (Family: `RIGHT`).
* **Pure Regular Lists on Main Dashboard:** Regular part tables on the Main Dashboard (`allUnitParts`, `lhParts`, `rhParts`) display strictly 100% regular BOM parts. ECN parts are **never mixed** into regular tables.
* **Scoped `[ECN]` Badge:** High-contrast amber badge (`#f59e0b` background with bold white text) displayed on Jig, Unit, LH, and RH section headers if and only if active ECN requirements exist within that exact scope.
* **Auto-Vanish Rule:** When an ECN requirement reaches `ASSEMBLY_COMPLETED`, the `[ECN]` indicator badge automatically disappears from that LH/RH, Unit, and Jig card on the Main Dashboard.
* **Dedicated ECN Reports Portal:** Comprehensive 9-KPI drilldown terminal accessible at `/ecn-reports`.

---

## 12. Department Workflow `[VERIFIED]`

```
[Pending Supplier Intake] ──> [Store Bay] ──> [QC Arrival] ──> [QC Inspection]
                                                                      │
                         ┌────────────────────────────────────────────┼────────────────────────────────────────────┐
                         ▼                                            ▼                                            ▼
                  [QC Rejected]                                [Rework Shop]                                [QC Approved]
                  (Purchase Queue)                                    │                                            │
                                                                      ▼ (Complete Rework)            ┌─────────────┴─────────────┐
                                                              [QC Re-Inspection]                     ▼                           ▼
                                                                                                [Paint Shop]            [Direct Assembly]
                                                                                                     │                           │
                                                                                                     └─────────────┬─────────────┘
                                                                                                                   ▼
                                                                                                            [Assembly Bay]
                                                                                                                   │
                                                                                                                   ▼
                                                                                                        [Assembly Completed]
```

---

## 13. Store Rules `[VERIFIED]`

* **Pending Intake Calculation:** $\text{Pending Qty} = \max(0, \text{Required Qty} - \text{Total Received Qty})$.
* **Partial Quantities:** Supports split receipts. Unreceived balances remain active in pending intake.
* **Valid Receipt Statuses:**
  $$\text{Valid Statuses} = \{\text{'received'}, \text{'sent\_to\_qc'}, \text{'qc\_received'}, \text{'qc\_approved'}, \text{'qc\_rejected'}, \text{'qc\_rework'}, \text{'paint\_completed'}, \text{'assembly\_completed'}, \text{'returned\_to\_store'}\}$$
* **Immediate QC Arrival Visibility:** In `HierarchyService.php`, `$qcPendingArrival` queries `whereIn('status', ['received', 'sent_to_qc'])`. Parts received by Store appear instantly in Mobile QC Arrival without requiring manual store dispatch.
* **Revert Action:** Reverts store receipts back to Pending Supplier Arrival.

---

## 14. QC Rules `[VERIFIED]`

* **Step 1: Physical Arrival Verification:** Inspector confirms physical delivery (`/qc/receive`), transitioning status from `'received'` / `'sent_to_qc'` to `'qc_received'`, transferring custody into QC Inspection.
* **Step 2: Dual Destination Quality Inspection:**
  * **Approved for Paint (`destination = 'PAINT'`)** $\rightarrow$ Routes into `paint_records` queue.
  * **Approved for Direct Assembly (`destination = 'ASSEMBLY'`)** $\rightarrow$ Bypasses Paint directly into `assembly_records` queue.
  * **Rework Needed** $\rightarrow$ Routes into `rework_records` with defect notes.
  * **QC Rejected / Scrap** $\rightarrow$ Marked in `qc_inspections.rejected_quantity` and automatically populates `purchase_queue_items`.
* **Idempotent Rejections:** Prevents duplicate QC reject button clicks from creating redundant purchase records.

---

## 15. Rework Rules `[VERIFIED]`

* **Single-Action Architecture:** Rework has strictly **ONLY ONE** user action: **Complete Rework**.
* **Zero Intermediate Debt:** `Start Rework` and `In-Progress` intermediate manual statuses are completely prohibited.
* **Mandatory QC Re-Inspection:** Completing rework atomically decrements the active rework record and updates the status to `'qc_received'`, returning the exact quantity to the QC Inspection queue for mandatory re-inspection.
* **Revert Action:** Returns un-reworked parts from Rework back to QC Inspection.

---

## 16. Paint Rules `[VERIFIED]`

* **Queue Eligibility:** Accepts only parts approved by QC with `destination = 'PAINT'`.
* **Batch Completion:** Operators record completed surface coating batches.
* **Advance to Assembly:** Completed paint quantities automatically advance into the Assembly Bay queue.
* **Revert Action:** Returns unpainted parts from Paint Shop back to QC Approved state.

---

## 17. Assembly Rules `[VERIFIED]`

* **Queue Eligibility:** Integrates parts received from completed `paint_records` and direct `qc_inspections` (`destination = 'ASSEMBLY'`).
* **Final Sign-off:** Operators record completed mechanical assemblies, transitioning parts to **Assembly Completed**.
* **Revert Action:**
  - If part arrived via Paint $\rightarrow$ Reverts back to Paint Shop completed state.
  - If part arrived via Direct QC $\rightarrow$ Reverts back to QC Approved state.

---

## 18. Revert Rules `[VERIFIED]`

SpareTrack enforces a strict mathematical, lineage-based reverse state machine (`WorkflowRevertController.php` & `EcnWorkflowService.php`):

| Department Initiating Revert | Source Entity | Quantity Restored To | Target Department | Lineage Proof |
|---|---|---|---|---|
| **Store** | `receipt_item` | `bom_item.pending` | Supplier Pending Intake | Receipt uninspected balance |
| **QC Arrival** | `receipt_item` (`qc_received`) | `received` / `sent_to_qc` | Store Bay | Arrived uninspected receipt |
| **Rework** | `rework_record` | `qc_inspection` pending | QC Inspection Bay | Uncorrected defect quantity |
| **Paint** | `paint_record` | `qc_inspection` approved | QC Inspection Bay | Unpainted approved quantity |
| **Assembly (Direct QC)** | `assembly_record` | `qc_inspection` approved | QC Inspection Bay | Direct QC approved balance |
| **Assembly (Painted)** | `assembly_record` | `paint_record` completed | Paint Shop | Painted completed balance |

* **Atomic Bulk Revert API:** `POST /api/v1/workflow/bulk-revert` and `POST /api/v1/ecn/mixed-bulk-revert`. Wrapped inside `DB::transaction()`. If any item exceeds available lineage quantity, the entire batch rolls back with zero partial corruption.
* **Multi-Format ID Resolution:** ECN Revert accepts raw requirement IDs, receipt IDs, or prefixed strings (`ecn_123`) without ever assigning fake regular BOM record IDs.

---

## 19. Purchase Department `[VERIFIED]`

The Purchase Desk (`resources/js/views/PurchaseQueue.vue`) is organized into 4 distinct tabs:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ [ 📦 Supplier Allocation ]  [ 📋 Overview Table ]  [ 🏭 Supplier Add ]  [ ❌ Rejected Parts ] │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

1. **Tab 1: Supplier Allocation (`SupplierAllocationTab.vue`):** 3-level interactive hierarchy for assigning vendors to sub-assembly units.
2. **Tab 2: Overview Table (`SupplierOverviewTab.vue`):** Standalone cross-project / cross-jig / cross-unit supplier allocation overview with quick search and filters.
3. **Tab 3: Supplier Add (`SupplierAddTab.vue`):** Supplier Master register, multi-phone numbers, and Excel bulk import engine.
4. **Tab 4: Rejected Parts Queue (`PurchaseQueue.vue`):** Reorder queue for QC defect scrap with status workflow (`pending_purchase` $\rightarrow$ `reordered` $\rightarrow$ `closed`) and Excel/PDF export.

---

## 20. Supplier Management `[VERIFIED]`

* **Master Vendor Register:** Stored in `suppliers` table with soft delete support (`deleted_at`).
* **Multi-Phone Numbers:** Stored in `supplier_phones` table (`supplier_id`, `phone_number`, `label`, `is_primary`).
* **Supplier Excel Import:** Handled by `SupplierImportService.php` (`BOM/supplier list 1.xlsx`). Creates `supplier_imports` batch record and associates new suppliers via `supplier_import_id`.
* **Safe Deletion Protection Rules:**
  1. If supplier has **active assignments** on valid projects $\rightarrow$ **Deactivate only** (`is_active = false`). Hard delete blocked.
  2. If supplier has **historical assignment audit records** $\rightarrow$ **Deactivate only** (`is_active = false`). Hard delete blocked.
  3. If supplier is **truly unused** $\rightarrow$ Soft deleted safely.
  4. **Critical Rule:** Never delete a Supplier merely because its name appeared in a deleted BOM import file.

---

## 21. Supplier Allocation `[VERIFIED]`

### 21.1 Three-Level Allocation Hierarchy
* **Level 1: Project Cards:** Select customer project.
* **Level 2: Jig Cards:** Select tooling fixture frame.
* **Level 3: Two-Panel Split Unit Workspace:**
  * **Left Panel:** Vertical list of all Units with completion badges and checkboxes for multi-unit selection. No unit is selected by default.
  * **Right Panel:** Interactive cards for 3 mandatory sub-assembly categories:
    1. **`BASE`**
    2. **`WELDMENT`**
    3. **`CHILD PART`**

### 21.2 Operational Allocation Rules
* **7-Day Restricted Date Window:** Assignment dates must be within **today $\pm 3$ calendar days** (`$minDate = today - 3d`, `$maxDate = today + 3d`). Enforced on backend and frontend.
* **Multi-Unit Bulk Assignment:** Select multiple units via checkboxes, choose vendor and date for any category, and click **"Apply Changes"** to update all units in a single atomic transaction (`multiUnitAssign`).
* **Mixed Supplier State:** When multiple units with different assigned vendors are selected, category cards display a "Mixed" badge.
* **Realtime Broadcast:** Broadcasts `SupplierAssignmentUpdated` event across WebSocket channel `:8085`.

---

## 22. Supplier Overview and Analytics `[VERIFIED]`

* **Standalone Overview Table:** Displays unified table of all Project / Jig / Unit allocations with columns: `Project`, `Jig No`, `Unit No`, `BASE Supplier & Date`, `WELDMENT Supplier & Date`, `CHILD PART Supplier & Date`, and `Allocation Status`.
* **Supplier Load KPI Calculation (`SupplierLoadService.php`):**
  - **Low Load (1–5 active unit assignments):** Optimal vendor capacity.
  - **Medium Load (6–15 active unit assignments):** Moderate vendor capacity.
  - **High Load (16+ active unit assignments):** Approaching maximum floor capacity.
  - **Overload (25+ active unit assignments):** Bottleneck risk.
* **Vendor Fill & Defect Analytics (`SupplierAnalyticsService.php`):** Evaluates vendor on-time delivery rates, QC rejection percentages, and defect frequency.

---

## 23. Dashboard KPI Definitions `[VERIFIED]`

### 23.1 Mathematical KPI Formulas & Queries

| KPI Name | Plain Language Definition | Authoritative Mathematical Formula / Query Reference |
|---|---|---|
| **Active Projects** | Projects currently under active floor manufacturing | `Project::where('status', 'active')->count()` |
| **Completed Projects** | Projects where 100% of required parts are assembled | `Project::where('status', 'completed')->count()` |
| **Delayed Projects** | Active projects with zero receipt activity for $> 14$ days | `Project::where('status', 'active')->where('created_at', '<', now()->subDays(14))->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))` |
| **Total Parts** | Grand sum of side requirements across all BOM items | $\sum \text{bom\_requirements.required\_quantity}$ |
| **Total Received** | Valid physical parts received by Store Bay | $\sum \min(\sum \text{receipt\_items.received\_quantity}, \text{required\_quantity}) \quad (\text{valid statuses})$ |
| **Parts Pending** | Parts awaiting initial delivery from vendors | $\max(0, \text{Total Parts} - \text{Total Received})$ |
| **Store** | Valid parts physically in Store awaiting QC inspection | $\max(0, \text{Total Received} - (\text{QC Resident} + \text{QC Rejected} + \text{Rework Active} + \text{Paint Active} + \text{Assembly Ready} + \text{Assembled}))$ |
| **QC** | Parts physically inside QC Bay (Arrival + Pending Inspection) | $\text{QC Pending Arrival} + \text{QC Pending Inspection}$ |
| **QC Rejected** | Defective scrapped parts sent to Purchase Queue | $\sum \text{qc\_inspections.rejected\_quantity}$ |
| **Rework** | Defective parts currently undergoing repair in Rework Shop | $\max(0, \sum \text{qc\_inspections.rework\_quantity} - \sum \text{rework\_records.completed\_quantity})$ |
| **Paint** | Approved parts currently undergoing surface coating | $\max(0, \sum \text{qc\_inspections.approved\_paint} - \sum \text{paint\_records.completed\_quantity})$ |
| **Assembly** | Parts ready for integration onto tooling units | $\max(0, (\text{Paint Completed} + \text{QC Direct Assembly}) - \text{Assembly Completed})$ |
| **Assembly Completed** | Parts 100% mechanically assembled into final fixtures | $\sum \text{assembly\_records.quantity} \quad (\text{status} = \text{'completed'})$ |
| **ECN Total** | Grand sum of isolated Engineering Change Notice parts | `EcnRequirement::sum('required_qty')` |
| **Project Completion %** | Overall manufacturing assembly completion percentage | $\min\left(100, \text{round}\left(\frac{\text{Assembly Completed}}{\text{Total Parts}} \times 100, 1\right)\right)$ |

### 23.2 Separated 3x9 KPI Architecture (MFG, BOP, STD)
To eliminate congestion and prevent conflating custom fabricated parts with off-the-shelf components, the main dashboard provides separated 9-KPI metric groups:
1. **Manufacturing BOM (MFG)** — Custom fabricated tooling components (Blue accent `#2563eb`).
2. **Bought Out Parts (BOP)** — Commercial purchased parts like pneumatic cylinders, sensors, switches (Amber accent `#d97706`).
3. **Standard Hardware (STD)** — Fasteners, dowel pins, bolts, washers, screws (Teal accent `#0d9488`).

* **Strict Order:** 1. MFG $\rightarrow$ 2. BOP $\rightarrow$ 3. STD. Clean section headers without verbose subtitles (`Manufacturing`, `BOP`, `Standard`).
* **9 Core KPIs Standardized:** Every BOM group (including BOP and STD) displays the standardized 9-card workflow grid: `Total Parts`, `Total Parts Received`, `Parts Pending`, `Store`, `QC`, `Rework`, `Paint`, `Assembly`, `Assembly Completed`. Irrelevant catalog items and hardware metrics are completely removed to eliminate empty grid gaps.
* **BOM Type Filter & Aggregated Portfolio Health:**
  - **All Types Mode (`ALL`):** When "Active Projects" and "All Types" are active, "Top Projects Near Completion" and "Project Health Distribution" compute a true weighted aggregate across all three part types (`MFG + BOP + STD`):
    $$\text{Weighted Completion \%} = \min\left(100, \text{round}\left(\frac{\sum_{\text{MFG+BOP+STD}} \text{assembly\_completed}}{\sum_{\text{MFG+BOP+STD}} \text{required\_quantity}} \times 100, 1\right)\right)$$
    Numerator: `sum(assembly_completed)`. Denominator: `sum(required_quantity)`. Zero-required projects are handled safely with 0.0% completion without division-by-zero.
  - **Single Type Filter (`MFG`, `BOP`, `STD`):** When a specific BOM type button is selected, the entire dashboard (KPIs, Charts, and Drill-Down Hierarchy) filters strictly to that part type.
* **Option B Hierarchy Drill-Down:**
  - When a project is selected under `All Types`: Displays three compact, individually collapsible sections (`Manufacturing`, `BOP`, `Standard`), each with its own independent Jig $\rightarrow$ Unit $\rightarrow$ Part inventory tree.
  - When a single type (`MFG`, `BOP`, or `STD`) is selected: Displays only that selected type's hierarchy tree.
  - Jigs and units are scoped per section (`${sectionKey}_${jigName}`) preventing expand/collapse collision across types.
* **KPI Drilldown Modal:** Passes `part_type` filter parameter to backend, displays color-coded BOM Type badge, and includes a `TYPE` column.

---

## 24. Mobile App Architecture `[VERIFIED]`

### 24.1 Architecture & Navigation
* **Engine:** React Native 0.74.5 / Expo SDK 51 (`mobile/App.js`).
* **Department Subtabs:** Dedicated top navigation subtab bars across all operational departments:
  - **Store:** `[ 📦 Pending Intake | ↩ Revert ]`
  - **QC:** `[ 📦 1. Arrival | 🔬 2. Inspection | ↩ Revert ]`
  - **Rework:** `[ 🛠️ Rework Queue | ↩ Revert ]`
  - **Paint:** `[ 🎨 Paint Queue | ↩ Revert ]`
  - **Assembly:** `[ ⚙️ Queue | 🏁 Done | ↩ Revert ]`
* **Interactive Quantity Steppers:** Interactive steppers (`-`, text input, `+`, `Max`) on all department action cards supporting precise partial quantity processing.
* **Network Host Normalizer (`mobile/src/api/client.js`):**
  - Shorthand `100.30` $\longrightarrow$ Expands to `http://192.168.100.30:8080/api/v1`.
  - Shorthand `9.200` $\longrightarrow$ Expands to `http://192.168.9.200:8080/api/v1`.
  - Auto-appends port `:8080` to any raw IPv4 address.
* **EAS OTA Update Mechanism:**
  - Floor devices check for updates on app launch (`checkAutomatically: "ON_LOAD"` in `mobile/app.json`).
  - Manual update button (`🔄 Update`) in app header triggers `Updates.checkForUpdateAsync()`.

---

## 25. Website Architecture `[VERIFIED]`

* **Visual Alignment:** Built with Vue 3 and Bootstrap 5.3 following the visual design language of **WebErpMesv2** (clean topbar, collapsible dark sidebar, high-density data tables, status pill badges, modal drilldowns).
* **Universal Export Engine (`app/Services/ExportService.php`):**
  - **Part Number Format:** Formats unique part identifier as a continuous string:
    $$\text{Part Number} = \text{Jig No} + \text{Unit No} + \text{Part No} + (\text{R} \mid \text{L}) \quad (\text{e.g. } 169961@00020\#R00R)$$
  - **Exports:** Scoped Excel (`.xlsx`) and PDF (`.pdf`) streaming generation directly from the Parts Movement Detail modal.

---

## 26. API Contracts `[VERIFIED]`

All endpoints are versioned under `/api/v1/...` and secured via Laravel Sanctum bearer tokens:

```
Public Endpoints:
  GET  /api/v1/health                          -> Health check (DB, Redis, App status)
  POST /api/v1/auth/login                      -> Authenticate user and issue Sanctum token

Authenticated Endpoints (Bearer Token Required):
  POST /api/v1/auth/logout                     -> Revoke current Sanctum token
  GET  /api/v1/auth/me                         -> Current authenticated user profile & roles

  # Dashboard & Analytics
  GET  /api/v1/dashboard/summary               -> Authoritative 11-KPI summary metrics
  GET  /api/v1/dashboard/project-hierarchy     -> 5-level mechanical tree with green states
  GET  /api/v1/dashboard/kpi-drilldown         -> Detailed drilldown row items for any KPI card
  GET  /api/v1/dashboard/jig-suppliers         -> Jig vendor indicators (ADMIN/MANAGER/PURCHASE)

  # BOM Management
  POST /api/v1/bom/preview                     -> Preview & diff uploaded Excel BOM
  POST /api/v1/bom/import                      -> Commit BOM import batch into database
  GET  /api/v1/bom/history                     -> List regular and ECN import batches
  GET  /api/v1/bom/history/{id}/impact         -> Pre-deletion impact analysis
  DELETE /api/v1/bom/history/{id}              -> Safely delete import batch & cascade records

  # ECN Management
  POST /api/v1/ecn/preview                     -> Preview & validate ECN Excel workbook
  POST /api/v1/ecn/import                      -> Commit ECN import batch into database
  GET  /api/v1/ecn/history                     -> List ECN import history batches
  GET  /api/v1/ecn/dashboard/summary           -> 9-KPI summary metrics for ECN reports
  GET  /api/v1/ecn/dashboard/drilldown         -> Drilldown dataset for ECN KPI cards
  POST /api/v1/ecn/store/receive               -> ECN Store bay receiving
  POST /api/v1/ecn/qc/receive                  -> ECN QC physical arrival confirmation
  POST /api/v1/ecn/qc/inspect                  -> ECN QC inspection routing
  POST /api/v1/ecn/rework/complete             -> ECN Rework completion back to QC
  POST /api/v1/ecn/paint/complete              -> ECN Paint shop batch completion
  POST /api/v1/ecn/assembly/complete           -> ECN Assembly completion
  POST /api/v1/ecn/revert                      -> Strict lineage-based ECN revert
  POST /api/v1/ecn/mixed-bulk-revert           -> Atomic bulk revert for mixed Regular & ECN items

  # Store Operations
  GET  /api/v1/store/hierarchy                 -> Store department hierarchy tree
  GET  /api/v1/store/pending                   -> Items awaiting receiving
  POST /api/v1/store/bulk-receive              -> Bulk stock intake transaction
  POST /api/v1/store/items/{id}/revert         -> Revert store receipt to pending

  # QC Operations
  GET  /api/v1/qc/hierarchy                    -> QC department hierarchy tree
  POST /api/v1/qc/bulk-receive                 -> Confirm physical arrival from Store
  POST /api/v1/qc/bulk-inspect                 -> Inspect and split route (Paint/Assembly/Rework/Reject)

  # Rework Operations
  GET  /api/v1/rework/hierarchy                -> Rework department hierarchy tree
  POST /api/v1/rework/complete                 -> Complete rework & atomically transfer back to QC

  # Paint & Assembly Operations
  GET  /api/v1/paint/hierarchy                 -> Paint shop hierarchy tree
  POST /api/v1/paint/bulk-complete             -> Batch paint completion
  GET  /api/v1/assembly/hierarchy              -> Assembly bay hierarchy tree
  POST /api/v1/assembly/bulk-complete          -> Batch assembly completion (100% Green)

  # Strict Lineage Revert Engine
  GET  /api/v1/workflow/revert-options         -> Available revertible lineage sources for a part
  GET  /api/v1/workflow/revert-items           -> Department-wide list of revertible items
  POST /api/v1/workflow/revert                 -> Single item transactional revert
  POST /api/v1/workflow/bulk-revert            -> Atomic bulk multi-department revert

  # Supplier Management & Allocation (Purchase Desk)
  GET  /api/v1/suppliers                       -> Paginated master supplier list
  GET  /api/v1/suppliers/active-list           -> Compact active suppliers for dropdowns
  POST /api/v1/suppliers                       -> Create supplier with phones
  PUT  /api/v1/suppliers/{id}                  -> Update supplier & sync phones
  DELETE /api/v1/suppliers/{id}               -> Safe delete or deactivate supplier
  POST /api/v1/suppliers/import/commit         -> Commit supplier Excel bulk import
  GET  /api/v1/supplier-allocation/hierarchy   -> Project -> Jig -> Unit allocation tree
  POST /api/v1/supplier-allocation/assign      -> Single unit category assignment (today ± 3d)
  POST /api/v1/supplier-allocation/multi-unit-assign -> Atomic multi-unit bulk assignment
  GET  /api/v1/supplier-allocation/overview    -> Cross-project unified allocation table
  GET  /api/v1/purchase/queue                  -> QC rejected reorder queue
  PATCH /api/v1/purchase/queue/{id}/status     -> Update reorder item status

  # Diagnostic Logs (Admin Only)
  GET  /api/v1/admin/logs                      -> Query system diagnostic logs
```

---

## 27. Authentication `[VERIFIED]`

### 27.1 Default Roles & Credentials
Default password for development and staging across all roles: **`password123`**

| Role | Default Email | Floor Permissions & System Capabilities |
|---|---|---|
| **ADMIN** | `admin@sparetrack.internal` | Full System Access, User Mgmt, BOM Import/Delete, System Logs |
| **MANAGER** | `manager@sparetrack.internal` | Full Executive Monitoring, Analytics, Excel/PDF Exports, Read-Only Queues |
| **STORE** | `store@sparetrack.internal` | Store Bay Stock Intake, QC Dispatch, Supplier Revert |
| **QC** | `qc@sparetrack.internal` | QC Arrival Verification, Quality Inspection Split, Revert |
| **REWORK** | `rework@sparetrack.internal` | Rework Processing, Complete Rework Return to QC, Revert |
| **PAINT** | `paint@sparetrack.internal` | Surface Treatment Completion, Revert |
| **ASSEMBLY** | `assembly@sparetrack.internal` | Unit Mechanical Assembly Sign-off, Revert |
| **PURCHASE** | `purchase@sparetrack.internal` | Unit Supplier Allocation, Supplier Master, Excel Import, Rejected Queue |

---

## 28. Network & Deployment Configuration `[VERIFIED]`

### 28.1 Port Allocations & Microservices

| Container Name | Service / Image | Internal Port | Exposed Host Port | Host URL / Address |
|---|---|---|---|---|
| `sparetrack-nginx` | Nginx Alpine Gateway | `80` | `8080` | `http://192.168.9.200:8080` |
| `sparetrack-app` | PHP 8.3-FPM (Laravel 11) | `9000` | Internal | Connected via FastCGI |
| `sparetrack-reverb`| Laravel Reverb WebSockets | `8080` | `8085` | `ws://192.168.9.200:8085` |
| `sparetrack-postgres`| PostgreSQL 16 Alpine | `5432` | `5432` | `192.168.9.200:5432` |
| `sparetrack-redis` | Redis 7 Alpine | `6379` | `6379` | Internal Docker Network |
| `sparetrack-worker`| Laravel Queue Worker | Daemon | Internal | Background Job Consumer |
| `sparetrack-adminer`| Adminer Database Browser | `8080` | `8088` | `http://192.168.9.200:8088` |

### 28.2 1-Click Server Update SOP
To deploy verified updates to the server without downtime or data risk:

#### On Windows Server (Command Prompt / PowerShell):
```cmd
update_server.bat
```
*Or in PowerShell:*
```powershell
.\update_server.ps1
```

#### On Linux / macOS Server:
```bash
bash update_server.sh
```

#### Manual PowerShell Deployment Command:
```powershell
git stash; git pull origin main; npm run build; docker exec -t sparetrack-app php artisan migrate --force; docker exec -t sparetrack-app php artisan optimize:clear; docker exec -t sparetrack-app php artisan config:cache; docker exec -t sparetrack-app php artisan route:cache; docker exec -t sparetrack-app php artisan view:cache; docker exec -t sparetrack-app php artisan queue:restart; docker restart sparetrack-app sparetrack-worker sparetrack-reverb sparetrack-nginx; Start-Sleep -Seconds 3; curl.exe -s http://127.0.0.1:8080/api/v1/health
```

### 28.3 Database Backup & Disaster Recovery
* **Backup Script:** `powershell -ExecutionPolicy Bypass -File ".\scripts\backup.ps1"` (generates timestamped SQL dump in `backups/sparetrack_db_backup_YYYYMMDD_HHMMSS.sql`).
* **Restore Script:** `powershell -ExecutionPolicy Bypass -File ".\scripts\restore.ps1"`.

---

## 29. Realtime and Cache Architecture `[VERIFIED]`

* **Broadcasting Engine:** Laravel Reverb running on port `:8085` using Redis 7 as the message broker.
* **WebSocket Channels:**
  - `department-updates`: Broadcasts live quantity state transitions across Store, QC, Rework, Paint, and Assembly.
  - `supplier-assignments`: Broadcasts live unit supplier allocations to Purchase and Manager views.
  - `project-status`: Broadcasts project and unit 100% green completion events.
* **Cache Management:** Application caching uses Redis. Running `php artisan optimize:clear` flushes cached routes, config, and views without touching database data.

---

## 30. Import & Incremental BOM Rules `[VERIFIED]`

1. **BOM Type Detection:** Dynamically detects BOM type from column headers:
   - `BOP`: Presence of `BOP Part No` / `BOP Part Number` header.
   - `STD`: Presence of `STD Part No` / `Standard Hardware Part No` header.
   - `MFG`: Default; presence of `MFG Part No` / `Standard Part No` / `Part Number`.
2. **Project Matching:** `ProjectIdentityResolver.php` matches incoming project codes against existing projects in PostgreSQL (e.g. `FA-273` matches `FA-273`).
3. **Repeat Parts Skipping (Never Summed):** When multiple rows with identical composite keys `(project, jig, unit, part_no, side)` exist in an uploaded file (common in BOP and STD sheets), the importer retains the **first occurrence only**, **never sums quantities**, and emits an upload warning banner noting that repeat parts are skipped by default.
4. **Isolated Reconciliation Diffing:** Incremental reconciliation diffing against existing project BOM records strictly filters by `where('part_type', $bomType)`. Importing a BOP or STD BOM never marks existing MFG parts as missing, deleted, or conflicting.
5. **Revision Classification:**
   - `ADD`: New component of that `part_type` not previously present in project BOM.
   - `UPDATE`: Existing component with altered required quantity.
   - `UNCHANGED`: Existing component with identical required quantity.
6. **Quantity Downward Revision Protection:** If an incoming revision decreases required quantity below what has already been received on the floor, the row is marked as a `CONFLICT` and requires administrative confirmation.

---

## 31. Data Integrity Rules `[VERIFIED]`

### 31.1 Non-Negotiable Ledger Invariants
1. **Total Parts Invariant:**
   $$\text{Total Required} = \sum \text{bom\_requirements.required\_quantity}$$
2. **Total Received Invariant:**
   $$\text{Total Received} = \sum \min\left(\sum \text{Valid Receipts}, \text{Required Quantity}\right)$$
3. **Pending Store Invariant:**
   $$\text{Parts Pending} = \max\left(0, \text{Total Required} - \text{Total Received}\right)$$
4. **Zero-Sum Physical Quantity Conservation Invariant:**
   $$\text{Total Received} = \text{Store Bay} + \text{QC Bay} + \text{Rework Queue} + \text{Paint Shop} + \text{Assembly Bay}$$
5. **Assembly Completion Invariant:**
   $$\text{Assembly Completed} \le \text{Total Received}$$

### 31.2 Strict RH vs LH vs COMMON Side Isolation
* Right-Hand (`RH`), Left-Hand (`LH`), and Symmetrical (`COMMON`) components are physically non-interchangeable.
* Requirements, receipts, inspections, rework jobs, paint records, assembly records, and lineage reverts must **NEVER** combine, merge, or cross-transfer quantities between sides using `bom_item_id` alone. Compound keys `(bom_item_id, side)` are mandatory across all queries.

---

## 32. Deletion Rules `[VERIFIED]`

### 32.1 BOM Import Batch Deletion
* **Impact Preview:** `GET /api/v1/bom/history/{id}/impact` returns pre-deletion impact analysis (affected jigs, units, parts, receipts, inspections, records).
* **Exclusive Project Deletion:** If a project was created exclusively by this BOM import batch and has no other batches, deleting the batch cleans up all child operational records in strict foreign key order and deletes the project.
* **Shared Project Deletion:** If a project contains other active import batches, deleting the batch removes **only the items introduced by that specific batch**, preserving the rest of the project and other batches intact.

### 32.2 Supplier Deletion Rules
1. Active assignments on valid projects $\rightarrow$ **Deactivate only** (`is_active = false`). Hard delete blocked.
2. Historical assignment audit records $\rightarrow$ **Deactivate only** (`is_active = false`). Hard delete blocked.
3. Truly unused supplier $\rightarrow$ Soft deleted safely.

---

## 33. Test Data Rules `[VERIFIED]`

* **Test Data Flag:** Test suites and seeders tag disposable test records with `is_test_data = true` or use unique test prefixes (`TEST_FA_...`).
* **Test Isolation:** Automated test suites execute inside database transactions or clean up strictly their own created test records during teardown.
* **Production Preservation:** Production projects `FA-273` and `FA-279` along with all actual imported BOM parts, ECNs, suppliers, and receipts must **NEVER** be deleted, overwritten, or modified by test suites.

---

## 34. Git & GitHub Workflow `[VERIFIED]`

To guarantee production stability, all repository contributions strictly adhere to this branching model:

```
                  ┌──────────────┐
                  │ main branch  │ ◄── Production-ready verified code ONLY
                  └──────┬───────┘
                         │ Branch checkout
                         ▼
                  ┌──────────────┐
                  │   branch-a   │ ◄── Active development & bug fixing
                  └──────┬───────┘
                         │
                         ├─► 1. Run PHPUnit Test Suite (158 passed tests required)
                         ├─► 2. Run Vite Web Build (npm run build)
                         ├─► 3. Push to origin branch-a
                         │
                         ▼ Pull Request
                  ┌──────────────┐
                  │ GitHub PR    │ ◄── branch-a -> main (0 conflicts required)
                  └──────────────┘
```

* **Branch Rules:** All development occurs on `branch-a`. Direct commits or force-pushes to `main` are prohibited.
* **Verification Standards:** Before opening a PR to `main`:
  1. Backend test suite must pass 100%: `docker exec -t sparetrack-app php artisan test`.
  2. Web frontend production assets must build cleanly: `npm run build`.
* **Author Identity:** Verified author identity across all commits: `Darshan2724 <darshilvant@gmail.com>`.

---

## 35. Known Bugs and Resolved Issues `[VERIFIED]`

### Forensic Audit of Historical Incidents (Milestones 1 to 15)

1. **Incident: Trapped Rework Parts & Obsolete "Start Rework" State**
   * *Root Cause:* Rework completion updated status to `completed` but failed to re-queue item in `qc_inspections` with `qc_received`. Mobile UI had duplicate `Start Rework` action creating state debt.
   * *Resolution:* Replaced with single-action `completeRework()` that atomically transitions items back to QC Inspection queue (`status = 'qc_received'`). Removed `Start Rework` entirely.
2. **Incident: Quantity Doubling & Partial Quantity Vanishing**
   * *Root Cause:* Direct joins between `receipt_items` and `qc_inspections` without `(bom_item_id, side)` aggregation caused Cartesian products. Split receipts marked parent records inactive without retaining balance.
   * *Resolution:* Implemented ledger aggregation across `QuantityCalculationService` and `HierarchyService`.
3. **Incident: Removal of Quantity Stepper Inputs on Mobile**
   * *Root Cause:* Rapid refactoring in `mobile/App.js` condensed cards and omitted `quantityToProcess` steppers.
   * *Resolution:* Restored interactive quantity steppers (`-`, text input, `+`, `Max`) across all department modals.
4. **Incident: RH vs LH Side Quantity Collisions**
   * *Root Cause:* Queries filtered only on `bom_item_id`, treating symmetric pairs as interchangeable.
   * *Resolution:* Enforced compound key isolation `(bom_item_id, side)` across all services and transactions.
5. **Incident: Excel Export Single-Column Part Number Format**
   * *Root Cause:* Default export templates separated `Jig No`, `Unit No`, and `Side` into multiple columns.
   * *Resolution:* Implemented standard shop floor format: $\text{Part Number} = \text{Jig No} + \text{Unit No} + \text{Part No} + (\text{R} \mid \text{L})$ under column header `Part Number`.
6. **Incident: Android APK Cleartext Network Failure**
   * *Root Cause:* Android 9+ blocks cleartext HTTP traffic to local LAN IP addresses by default.
   * *Resolution:* Added `usesCleartextTraffic: true` in `mobile/app.json` and `expo-build-properties` plugin.
7. **Incident: Multi-Department Lineage Revert Engine**
   * *Root Cause:* Store "Recent Receipts" lacked multi-department reverse lineage safety.
   * *Resolution:* Built reverse state machine (`WorkflowRevertController.php`) with atomic bulk revert endpoints.
8. **Incident: Mobile Department Subtabs & Revert UI**
   * *Root Cause:* Operators needed clean separation between active processing queues and revert history.
   * *Resolution:* Built top-level subtabs (`Queue / Revert`) across all 5 operational mobile departments.
9. **Incident: Deep Unit Hierarchy & Multi-Field Search**
   * *Root Cause:* Search only checked part numbers, missing Jig codes, descriptions, and suppliers.
   * *Resolution:* Extended search queries across JIGs, Units, Standard Part Numbers, Descriptions, and Suppliers.
10. **Incident: Mobile Terminal Network Normalizer & EAS OTA**
    * *Root Cause:* Operators entering shorthand IPs (`100.30`, `9.200`) failed to connect without port `:8080`.
    * *Resolution:* Built smart normalizer `normalizeServerHost()` in `mobile/src/api/client.js` and configured EAS OTA update channels.
11. **Incident: ECN Mobile QC Reject Idempotency & Revert Identity**
    * *Root Cause:* Rejecting an ECN part from mobile QC left `current_state = 'QC'` with `received_qty = 1`, trapping parts in inspection. Duplicate reject clicks created redundant purchase records. ECN revert failed when passed fake regular IDs.
    * *Resolution:* Hardened `HierarchyService` to verify uninspected receipt balances, enforced reject idempotency, and added multi-format ECN ID resolution.
12. **Incident: Scoped ECN Indicators on Main Dashboard**
    * *Root Cause:* ECN parts were polluting regular part listings on the Main Dashboard.
    * *Resolution:* Filtered regular part tables to strictly 100% regular BOM parts. Added scoped amber `[ECN]` badges on section headers that auto-vanish upon `ASSEMBLY_COMPLETED`.
13. **Incident: Supplier Management & Unit Allocation Workspace**
    * *Root Cause:* Lack of structured unit-level vendor assignment across BASE, WELDMENT, and CHILD PART.
    * *Resolution:* Built two-panel Unit allocation workspace with mandatory today $\pm 3$ days date validation and Standalone Overview Table.
14. **Incident: Purchase Desk Rejected Parts Queue Restoration**
    * *Root Cause:* Missing route aliases and paginated data unpacking errors in `PurchaseQueue.vue`.
    * *Resolution:* Added route aliases `/api/v1/purchase/queue` and `/api/v1/purchase/items` and fixed tab switcher.
15. **Incident: Mobile 100.30 Network Error & Regular Store-to-QC Arrival Visibility**
    * *Root Cause:* Shorthand `100.30` was missing port `:8080`. In `HierarchyService.php`, `$qcPendingArrival` omitted status `'received'`.
    * *Resolution:* Enhanced `normalizeServerHost()` to auto-append `:8080` and updated `$qcPendingArrival` to query `whereIn('status', ['received', 'sent_to_qc'])`.

---

## 36. Current Known Issues `[VERIFIED]`

| Issue | Severity | Affected Area | Known Root Cause | Status | Last Updated |
|---|---|---|---|---|---|
| None | N/A | None | All 15 historical anomalies resolved and backed by 158 automated feature tests. | **ALL FIXED (0 Open Issues)** | September 04, 2026 |

---

## 37. Recent Changes / Changelog `[VERIFIED]`

| Date | Change Summary | Files / Modules Affected | Database Schema Changes | Behavioral Impact | Testing Status |
|---|---|---|---|---|---|
| **2026-09-04** | Project Hierarchy Drill-Down Permanent Fix (MFG/BOP/STD Single-Type Views & Level 5 Parts Table) | `DashboardController.php`, `Dashboard.vue`, `DashboardTypeFilteringAndHierarchyTest.php` | None (Canonical API contract refinement) | Guarantees mfg_section, bop_section, std_section keys across all views; eliminates circular JSON; synchronizes toolbar state; enables Level 5 parts table in single-type panels | Passing (182 tests, 2300 assertions) |
| **2026-09-04** | Dashboard MFG/BOP/STD Filter + Aggregated Project Health + Option B Three-Section Hierarchy Refinement | `DashboardController.php`, `QuantityCalculationService.php`, `Dashboard.vue`, `DashboardTypeFilteringAndHierarchyTest.php` | None (Backward-compatible API & state calculation) | Top projects & health distribution weighted aggregate, single-type isolation, Option B three-type compact hierarchy with scoped expansion, 9-KPI standardized BOP/STD | Passing (181 tests, 2249 assertions) |
| **2026-09-04** | Isolated 3-BOM Type Support (MFG, BOP, STD) with 3x9 KPI Architecture | `BomImportService.php`, `QuantityCalculationService.php`, `HierarchyService.php`, `KpiDrilldownService.php`, `DashboardController.php`, `Dashboard.vue`, `BomImport.vue`, Department Views | Added `part_type` on `bom_items` & `bom_type` on `bom_import_batches`, composite unique constraint | Isolated 3x9 KPI groups, BOM type switcher, duplicate skipping with upload warnings, isolated reconciliation diffing | Passing (178 tests, 2206 assertions) |
| **2026-09-04** | Updated universal project context & self-contained knowledge base | `PROJECT_CONTEXT_SUMMARY.md` | None (Documentation) | Provides canonical onboarding context for future AI sessions | Verified against 158 tests |
| **2026-09-02** | Supplier Load KPI & Excel bulk import engine | `SupplierController.php`, `SupplierImportService.php`, `SupplierLoadService.php` | Added `supplier_imports` table & `supplier_import_id` FK | Real-time vendor capacity tracking & safe import batch deletion | Passing |
| **2026-09-01** | Unit Supplier Allocation (BASE/WELDMENT/CHILD) & Multi-Phone | `SupplierAllocationController.php`, `SupplierAllocationTab.vue`, `SupplierPhone.php` | Added `supplier_assignments`, `supplier_assignment_history`, `supplier_phones` | 2-panel allocation workspace with today $\pm 3$ days datepicker | Passing |
| **2026-08-28** | ECN isolated workflow & dedicated analytics portal | `EcnImportService.php`, `EcnWorkflowService.php`, `EcnReports.vue`, `HierarchyService.php` | Added `ecn_import_batches`, `ecn_requirements`, `ecn_receipt_items`, `ecn_workflow_records` | Isolated ECN state machine, side normalizer, auto-vanishing badges | Passing |
| **2026-08-26** | Multi-department lineage revert engine & bulk API | `WorkflowRevertController.php`, `HierarchyService.php`, `App.js` | Added lineage tracking queries & composite indexes | Zero-corruption reverse state machine across Store, QC, Rework, Paint, Assembly | Passing |
| **2026-08-24** | Incremental BOM diffing & duplicate prevention | `BomImportService.php`, `BomImportController.php` | Added unique filename & content hash indexes on `bom_import_batches` | Reconciles revisions against existing projects with conflict detection | Passing |

---

## 38. Production Data Protection Rules `[VERIFIED]`

> [!CAUTION]
> **STRICT PRODUCTION DATA SAFEGUARDS**:
> 1. **Never delete, truncate, or overwrite production projects `FA-273` and `FA-279`.**
> 2. **Never delete real supplier records** with active or historical assignments.
> 3. **`php artisan migrate:fresh` is strictly FORBIDDEN** in production and development environments. All schema updates must execute through forward migrations (`php artisan migrate --force`).
> 4. **Cascade Deletes:** Must be strictly analyzed before execution. BOM deletion must preserve multi-batch shared projects.
> 5. **Test Data Cleanup:** Automated cleanup routines must filter strictly on `is_test_data = true` or isolated test project IDs.

---

## 39. Safe Investigation Procedure `[VERIFIED]`

When troubleshooting any reported anomaly or bug, follow this safe protocol:

1. **Step 1: Check System Logs First:**
   Query `SystemLog::where('severity', 'ERROR')->orderByDesc('id')->take(20)->get()` or view `/admin/logs` to identify runtime exceptions and stack traces.
2. **Step 2: Inspect Specific Target Records:**
   Query only the specific `project_id`, `bom_item_id`, or `ecn_requirement_id` involved in the issue. **Do not run unfiltered full database table scans.**
3. **Step 3: Verify Mathematical Ledger Invariants:**
   Execute `QuantityCalculationService::calculateProjectMetrics($projectId)` and compare with raw table counts to detect ledger discrepancies.
4. **Step 4: Execute Targeted Feature Test:**
   Run the specific PHPUnit test suite covering the affected module:
   ```bash
   docker exec -t sparetrack-app php artisan test --filter SpecificFeatureTest
   ```
5. **Step 5: Implement Fix in Service Layer:**
   Apply modifications inside domain services using `DB::transaction(...)` to ensure zero state fragmentation.

---

## 40. AI Agent Instructions `[VERIFIED]`

> [!IMPORTANT]
> **AI AGENT BOOTSTRAP INSTRUCTIONS**:
>
> 1. **Read `PROJECT_CONTEXT_SUMMARY.md` First:** Treat this file as the canonical, authoritative repository knowledge base.
> 2. **Do NOT Scan the Entire Database by Default:** The schema, relationships, models, controllers, services, and calculations are fully documented in this file.
> 3. **Do NOT Rediscover Architecture Unnecessarily:** Use the documented canonical models (`app/Models/`), domain services (`app/Services/`), and API endpoints (`routes/api.php`).
> 4. **Inspect Only Required Code / Rows:** Only inspect specific source files or database rows when the requested task specifically requires code modification or data verification.
> 5. **Preserve Production Data:** Never modify or delete production data (`FA-273`, `FA-279`, real suppliers).
> 6. **Follow Canonical Math Invariants:** Never alter quantity formulas without verifying compliance with [Section 31 (Data Integrity Rules)](#31-data-integrity-rules).
> 7. **Maintain Reverse Lineage Integrity:** Follow [Section 18 (Revert Rules)](#18-revert-rules) for any workflow transition changes.
> 8. **Respect Git Branching Policy:** All development work occurs on `branch-a`. PR required to merge into `main`.
> 9. **Verify with Automated Tests:** Run `docker exec -t sparetrack-app php artisan test` to confirm all 181 tests pass before concluding.
> 10. **Update This Document on Every Meaningful Change:** Whenever a new feature, bug fix, migration, API endpoint, or workflow rule is modified, update `PROJECT_CONTEXT_SUMMARY.md` in the same development cycle (recommended every 2–4 hours of active work).
