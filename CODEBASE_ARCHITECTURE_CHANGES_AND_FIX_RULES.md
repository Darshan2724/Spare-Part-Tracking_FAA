# SpareTrack — Industrial Spare Parts Tracking System
## Comprehensive Codebase Architecture, Incident Forensic Audit, Fix Rules & Engineering Standards

---

## 1. Codebase Overview & System Mission

### 1.1 Project Mission
**SpareTrack** is an enterprise industrial manufacturing and execution tracking platform built for **Faith Automation**. The platform manages the end-to-end physical lifecycle of automotive tooling spare parts, jigs, units, and structural weld fixtures across high-volume factory floor operations.

The core mission of the platform is to provide **zero-tolerance mathematical accountability** for every physical component entering the plant—tracking parts from initial engineering BOM (Bill of Materials) ingestion, through supplier receipt, Quality Control inspection, defect rework loops, surface coating/paint shops, and final mechanical assembly.

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

---

### 1.2 Technology Stack

| Layer | Technologies & Libraries | Responsibilities |
|---|---|---|
| **Backend Framework** | **Laravel 11.x (PHP 8.3+)** | REST API endpoints, business logic pipelines, database transactions, authorization gates. |
| **Relational Database** | **PostgreSQL 16** | ACID state ledger, indexed relational constraints, JSONB metadata, foreign keys. |
| **Realtime Engine** | **Laravel Reverb + Redis** | WebSocket broadcasting of department state changes to web and mobile apps. |
| **Web Dashboard** | **Vue.js 3 (Composition API) + Vite** | High-density desktop dashboard, KPI drill-downs, hierarchy trees, analytics charts. |
| **Styling & UI** | **Bootstrap 5 + Custom Modern Theme** | Responsive data tables, glassmorphism cards, interactive KPI badges, micro-animations. |
| **Reporting & Export** | **PhpOffice/PhpSpreadsheet + DomPDF** | Streaming Excel (`.xlsx`) generation and formatted PDF inspection reports. |
| **Mobile Application** | **React Native (Expo SDK 54 / EAS)** | Department floor scanning, quantity steppers, bulk handoffs, offline-tolerant actions. |
| **Infrastructure** | **Docker Compose, Nginx, PHP-FPM, Supervisor** | Containerized microservices, queue workers, persistent Docker storage volumes. |

---

### 1.3 5-Level Manufacturing Hierarchy
SpareTrack enforces a strict 5-level mechanical hierarchy across all database queries, API payloads, web tables, and mobile queues:

$$\text{Project} \longrightarrow \text{Jig} \longrightarrow \text{Unit} \longrightarrow \text{Part Number} \longrightarrow \text{Side} \ (\text{RH} \mid \text{LH} \mid \text{COMMON})$$

* **Project**: Top-level customer assembly project (e.g. `FA-279 - Main Floor Framing`).
* **Jig**: Tooling fixture frame code (e.g. `169961@`).
* **Unit**: Sub-assembly station within a jig (e.g. `00`, `01`, `04`).
* **Part Number**: Standardized engineering part number (e.g. `020#R00`, `040#R00`).
* **Side**: Geometric symmetry orientation—**RH** (Right Hand), **LH** (Left Hand), or **COMMON**.

---

## 2. End-to-End Operational Workflow

### 2.1 BOM Import & Requirements Ledger
* When an engineering Excel BOM is uploaded via the `/bom-import` screen, it creates records in `bom_items` and side-specific requirements in `bom_requirements`.
* Each BOM item defines the baseline requirement:
  $$\text{Required Quantity} = \sum_{\text{side}} \text{bom\_requirements.required\_quantity}$$
* The system validates against duplicates, standardizes part numbers (e.g. converting `020` to `020#R00` if suffixed), and creates side-isolated requirements.

### 2.2 Store Bay Receiving
* Physical shipments arrive from internal fabrication or external vendors.
* Store managers accept parts into `receipt_items`.
* Store quantity is officially counted in the valid received pool:
  $$\text{Valid Receipt Statuses} \in \{\text{'received'}, \text{'returned\_to\_store'}, \text{'sent\_to\_qc'}, \text{'qc\_received'}\}$$
* From Store, parts are transferred to QC via `sent_to_qc`.

### 2.3 QC Bay (Arrival & Quality Inspection)
* **Step 1: Physical Arrival Verification**: When parts arrive from Store, QC officers confirm physical arrival (`qc_received`), moving parts from the Store bay into the active QC inspection queue.
* **Step 2: Quality Inspection**: The inspector verifies dimensional tolerances, surface quality, and side marking. The inspector selects the destination routing:
  * **Approved for Paint** $\rightarrow$ Parts flow into `paint_records` queue.
  * **Approved for Direct Assembly** $\rightarrow$ Parts bypass Paint and flow directly into `assembly_records` queue.
  * **Rework Needed** $\rightarrow$ Defective parts are routed into `rework_records` with defect notes.
  * **Scrapped / Rejected** $\rightarrow$ Fatal defects are marked as `QC Rejected` (recorded in `qc_inspections.rejected_quantity`).

### 2.4 Rework Shop Loop (Complete Rework Only)
* Rework operators receive defective parts in the Rework queue (`status = 'pending'` or `'in_progress'`).
* **Complete Rework Action**: When the correction is finished, the operator selects the completed quantity and clicks **Complete Rework**.
* The backend atomically deducts the rework record and returns the exact quantity to the **QC Quality Inspection queue** for mandatory reinspection.

### 2.5 Paint Shop Coating
* Parts approved for Paint wait in the Paint queue.
* When surface treatment/coating is complete, the painter enters the completed quantity and records `paint_records`.
* Completed paint quantities automatically advance into the **Assembly Bay queue**.

### 2.6 Assembly Bay & Final Completion
* Mechanical fitters assemble painted and direct-routed parts onto their designated Jig and Unit fixtures.
* Entering completed quantities in `assembly_records` moves the parts to **Assembly Completed**.
* When all required BOM parts for a Unit reach 100% Assembly Completed, the Unit turns green. When all Units in a Jig complete, the Jig turns green. When all Jigs complete, the Project reaches **100% Completed**.

---

## 3. Incident Forensic Audit & Errors Faced

During production operations and stress testing, several critical workflow anomalies and edge cases were identified and systematically resolved:

### Incident 1: Rework Parts Getting Trapped & Obsolete "Start Rework" State
* **Symptom**: When a user clicked "Complete Rework" on the mobile app, the parts remained inside Rework instead of returning to QC inspection. Additionally, an obsolete `Start Rework` button created intermediate state debt.
* **Root Cause**:
  1. The backend rework completion endpoint was updating the rework record status to `completed` but was not transitioning the parent `receipt_items` or re-queuing the item in `qc_inspections` with `qc_received` status.
  2. The mobile UI had a duplicate `Start Rework` action which caused state fragmentation (`pending` $\rightarrow$ `in_progress` $\rightarrow$ `completed`).
* **Resolution Applied**:
  1. Updated `ReworkController.php` so that `completeRework()` atomically decrements active rework records and updates the receipt item / QC inspection state to `qc_received`, returning the exact quantity to the QC inspection queue.
  2. Completely removed `Start Rework` from all mobile screens and bulk toolbars. Rework is strictly a **Complete Rework** single-action department.

---

### Incident 2: Quantity Doubling & Partial Quantity Vanishing
* **Symptom**: When a user partially inspected 1 part out of 2, the remaining 1 part vanished from the mobile card, or in certain calculation paths, quantities showed doubled totals.
* **Root Cause**:
  1. **Vanishing**: When processing partial quantities (e.g. 1 of 2), `ReceiptItem` splitting or QC record updates were marking the source record as inactive without retaining the remaining balance in an active queue state.
  2. **Doubling**: In calculation queries, joining `receipt_items` directly to `qc_inspections` without aggregating by `(bom_item_id, side)` caused a Cartesian product when multiple partial inspection rows existed for a single BOM item.
* **Resolution Applied**:
  1. Implemented ledger aggregation across all quantity services (`QuantityCalculationService`, `HierarchyService`, `KpiDrilldownService`).
  2. Split-receipt handling preserves remaining quantities in their exact prior state.
  3. Strict mathematical capping:
     $$\text{Canonical Received} = \min(\sum \text{Valid Receipts}, \text{BOM Required})$$

---

### Incident 3: Removal of Quantity Stepper Inputs in Department Actions
* **Symptom**: Mobile operators were forced to process entire batch quantities at once because quantity steppers were accidentally replaced by single-click full-batch buttons.
* **Root Cause**: Rapid refactoring in `mobile/App.js` condensed the card UI, omitting the `quantityToProcess` state and steppers across QC, Rework, Paint, and Assembly.
* **Resolution Applied**:
  1. Restored interactive quantity steppers (`-`, number input, `+`, `Max`) across all department action modals in `mobile/App.js`.
  2. Added split-routing validation in QC so users can assign 1 part to Paint and 1 part to Direct Assembly simultaneously without exceeding available balance.
  3. Published live Over-The-Air (OTA) updates via Expo EAS to both `preview` and `production` release channels.

---

### Incident 4: RH vs. LH Side Quantity Collisions
* **Symptom**: Processing an `RH` part inadvertently decremented or credited the `LH` requirements for the same part number.
* **Root Cause**: SQL queries were filtering only on `bom_item_id`, treating symmetric pairs as interchangeable.
* **Resolution Applied**:
  1. Enforced compound key isolation: every query, event, and database transaction MUST filter by both `bom_item_id` AND `side`.
  2. Strict side separation is enforced in `QuantityCalculationService`, `HierarchyService`, and `KpiDrilldownService`.

---

### Incident 5: Excel Export Formatting Requirements
* **Symptom**: Standard Excel exports contained separated columns (`Jig No`, `Unit No`, `Side`) and used "Combined Identifier" headers, which did not match the engineering shop floor standard format.
* **Root Cause**: Default export templates used raw database column mappings.
* **Resolution Applied**:
  1. Created a dedicated single-column format in `ExportService.php`:
     $$\text{Part Number} = \text{Jig No} + \text{Unit No} + \text{Part No} + (\text{R} \mid \text{L})$$
     *(e.g. `169961@` + `00` + `020#R00` + `RH` $\rightarrow$ `169961@00020#R00R`)*
  2. Renamed column header strictly to **`Part Number`**.
  3. Streamlined columns to: `Project`, `Part Number`, `Status`, `Quantity`.

---

## 4. Architectural Fix Rules & Engineering Standards

The following invariant rules are permanently enforced across the SpareTrack codebase:

### Rule 1: Canonical Mathematical Invariants
Every quantity calculation in the system must strictly satisfy the following five invariants:

1. **Total Parts Invariant**:
   $$\text{Total Parts} = \sum_{\text{BOM}} \text{Required Quantity}$$
2. **Total Parts Received Invariant**:
   $$\text{Total Received} = \sum_{\text{BOM}} \min\left(\sum \text{Valid Receipts}, \text{Required Quantity}\right)$$
3. **Pending Store Invariant**:
   $$\text{Parts Pending} = \max\left(0, \text{Total Parts} - \text{Total Received}\right)$$
4. **Physical Location Conservation Invariant**:
   $$\text{Total Received} = \text{Store Bay} + \text{QC Bay} + \text{Rework Queue} + \text{Paint Shop} + \text{Assembly Bay}$$
5. **Assembly Completion Invariant**:
   $$\text{Assembly Completed} \le \text{Total Received}$$

---

### Rule 2: Strict Side Isolation (RH / LH / COMMON)
* An `RH` part requirement can **ONLY** be satisfied by an `RH` receipt.
* An `LH` part requirement can **ONLY** be satisfied by an `LH` receipt.
* A `COMMON` part requirement is side-neutral but isolated from symmetric pairs.
* **Prohibited**: Never merge, sum, or subtract across sides using `bom_item_id` alone.

---

### Rule 3: Complete-Only Rework Architecture
* The Rework department has **ONLY ONE** user action: **Complete Rework**.
* No `Start Rework`, `In-Progress`, or intermediate manual statuses are exposed to the user.
* Completing rework automatically, atomically, and without exception transfers the part back to the **QC Quality Inspection** queue (`status = 'qc_received'`).

---

### Rule 4: Atomic Transactions & Partial Quantities
* All multi-step department state mutations must be wrapped inside `DB::transaction(...)`.
* Partial quantities ($1 \le q \le \text{available}$) must decrement available balance and spawn/update child records without deleting or hiding unprocessed quantities.

---

### Rule 5: Read-Only KPI Drill-Down with Zero Recalculation
* Clicking any dashboard KPI card opens a detailed drill-down modal powered by `KpiDrilldownService.php`.
* The sum of drill-down rows must **exactly reconcile** with the KPI card number without frontend JavaScript recalculation.
* Scoping must strictly reflect the dashboard filter (Single Project vs. All Active Projects).

---

### Rule 6: Single-Column Excel Formatting
* Part-level Excel exports must format the unique part identifier as a continuous string:
  $$\text{Part Number} = \text{Jig No} + \text{Unit No} + \text{Part No} + (\text{R} \mid \text{L})$$
* Header is strictly labeled **`Part Number`**.
* Columns exported: `Project`, `Part Number`, `Status`, `Quantity`.

---

### Rule 7: Database Persistence & Docker Volume Safety
* **Zero Data Loss Guarantee**: Deployments using `git pull`, `npm run build`, `php artisan optimize:clear`, and container restarts (`docker compose restart app worker reverb`) **NEVER** touch or reset PostgreSQL database tables.
* Database records are persisted in the named Docker volume `pgdata`.
* **Prohibited in Production**: Never run `php artisan migrate:fresh` or destructive drop commands on production servers.

---

## 5. Complete Codebase Directory & File Map

```
SpareTrack/
├── app/
│   ├── Events/                              # Realtime WebSocket Event Broadcasts
│   │   ├── AssemblyUpdated.php              # Realtime event: Assembly bay progression
│   │   ├── PaintUpdated.php                 # Realtime event: Paint shop completion
│   │   ├── QcInspected.php                  # Realtime event: QC inspection routing
│   │   ├── ReworkUpdated.php                # Realtime event: Rework completion -> QC
│   │   ├── StoreReceived.php                # Realtime event: Store bay receiving
│   │   └── ProjectStatusChanged.php         # Realtime event: Project level progression
│   │
│   ├── Http/Controllers/                   # API Endpoint Controllers
│   │   ├── AuthController.php               # Sanctum authentication & role checks
│   │   ├── BomController.php                # Engineering Excel BOM import & parsing
│   │   ├── DashboardController.php          # Management KPIs & KPI drill-down API
│   │   ├── ExportController.php             # Excel (.xlsx) & PDF streaming endpoints
│   │   ├── StoreController.php              # Store bay receipt & transfer to QC
│   │   ├── QcController.php                 # QC arrival & quality inspection split
│   │   ├── ReworkController.php             # Rework completion & handoff to QC
│   │   ├── PaintController.php              # Paint shop batch recording
│   │   ├── AssemblyController.php           # Assembly bay completion tracking
│   │   └── SupplierController.php           # Supplier master data CRUD
│   │
│   ├── Models/                              # Eloquent Relational Ledger Models
│   │   ├── Project.php                      # Project header (code, name, status)
│   │   ├── BomItem.php                      # BOM item master (jig, unit, part_no)
│   │   ├── BomRequirement.php               # Side requirements (RH/LH/COMMON qty)
│   │   ├── Receipt.php                      # Receipt batch header
│   │   ├── ReceiptItem.php                  # Physical inventory tracking ledger
│   │   ├── QcInspection.php                 # QC inspection results (approved/rejected)
│   │   ├── ReworkRecord.php                 # Defect rework tracking records
│   │   ├── PaintRecord.php                  # Paint shop surface treatment records
│   │   ├── AssemblyRecord.php               # Mechanical assembly records
│   │   └── Supplier.php                     # Vendor profiles & fill accuracy
│   │
│   └── Services/                            # Domain Business Logic & Canonical Math
│       ├── QuantityCalculationService.php   # Central canonical quantity math engine
│       ├── HierarchyService.php             # 5-level tree generator & green propagation
│       ├── KpiDrilldownService.php          # 11 KPI drill-down dataset queries
│       ├── ExportService.php                # PhpSpreadsheet (.xlsx) & DomPDF formatting
│       └── SystemLogService.php             # Diagnostic activity & error logger
│
├── database/
│   ├── migrations/                          # PostgreSQL Schema Definitions
│   │   ├── 2026_01_01_000001_create_projects_table.php
│   │   ├── 2026_01_01_000002_create_bom_items_table.php
│   │   ├── 2026_01_01_000003_create_bom_requirements_table.php
│   │   ├── 2026_01_01_000004_create_receipts_table.php
│   │   ├── 2026_01_01_000005_create_receipt_items_table.php
│   │   ├── 2026_01_01_000006_create_qc_inspections_table.php
│   │   ├── 2026_01_01_000007_create_rework_records_table.php
│   │   ├── 2026_01_01_000008_create_paint_records_table.php
│   │   └── 2026_01_01_000009_create_assembly_records_table.php
│   └── seeders/                             # Test data & production user seeders
│
├── resources/
│   ├── js/                                  # Vue 3 Single Page Application
│   │   ├── views/
│   │   │   ├── Dashboard.vue                # Main Dashboard, 11 KPI cards, Drill-down Modal
│   │   │   ├── BomImport.vue                # Excel BOM upload & validation interface
│   │   │   ├── Store.vue                    # Web Store bay receiving interface
│   │   │   ├── Qc.vue                       # Web QC inspection interface
│   │   │   ├── Rework.vue                   # Web Rework queue interface
│   │   │   ├── Paint.vue                    # Web Paint shop interface
│   │   │   ├── Assembly.vue                 # Web Assembly bay interface
│   │   │   └── Suppliers.vue                # Supplier analytics & management
│   │   ├── stores/                          # Pinia State Management Stores
│   │   └── router/                          # Vue Router Route Definitions
│   └── views/                               # Blade Templates (Root shell, PDF templates)
│
├── mobile/                                  # React Native (Expo) Mobile App
│   ├── App.js                               # Floor application shell, cards & steppers
│   ├── src/
│   │   ├── api/client.js                    # Mobile Axios API client & token storage
│   │   └── screens/                         # Department screens (Store, QC, Rework, etc.)
│   └── app.json                             # Expo EAS configuration & OTA update channels
│
├── routes/
│   ├── api.php                              # Versioned API routes (`/api/v1/...`)
│   └── web.php                              # Web SPA entry routes
│
├── tests/Feature/                           # Comprehensive PHPUnit Test Suites
│   ├── KpiDrilldownTest.php                 # 11 KPI drilldowns & Excel export tests
│   ├── WorkflowIntegrityTest.php            # Rework-to-QC & department integrity tests
│   ├── QuantityCalculationHierarchyTest.php # Invariant mathematical proofs
│   ├── PriorityMapSideIsolationTest.php     # RH/LH side isolation verification
│   └── BomImportDeletionTest.php            # Safe BOM import & cascade deletion tests
│
└── docker-compose.yml                       # Docker services (app, db, redis, reverb, worker)
```

---

## 6. Server Deployment & Update Standard Operating Procedure

When deploying code updates to the production server, execute the following commands in order:

```bash
# 1. Navigate to project root
cd /path/to/your/SpareTrack

# 2. Pull latest code from main
git checkout main
git pull origin main

# 3. Rebuild frontend production assets
npm install
npm run build

# 4. Refresh Laravel application caches inside Docker
docker exec -it sparetrack-app php artisan optimize:clear
docker exec -it sparetrack-app php artisan config:cache
docker exec -it sparetrack-app php artisan route:cache
docker exec -it sparetrack-app php artisan view:cache

# 5. Restart background queue workers and application containers
docker compose restart app worker reverb
```

> **Verification**: Run `docker exec -it sparetrack-app php artisan test` to confirm that all 45+ feature tests pass with 100% assertion success on the live environment.
