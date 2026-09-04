<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <!-- Header Topbar -->
      <div class="py-3 px-4 bg-white border-bottom shadow-sm rounded mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h4 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-line text-primary me-2"></i>Manufacturing Manager Terminal</h4>
        </div>
        <div class="d-flex gap-2">
          <router-link v-if="['ADMIN', 'MANAGER'].includes(authStore.userRole)" :to="{ name: 'bom-import' }" class="btn btn-primary btn-sm text-nowrap">
            <i class="fas fa-file-upload me-1"></i> Import BOM
          </router-link>
          <button @click="fetchData" class="btn btn-outline-primary btn-sm text-nowrap" :disabled="loading">
            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh Live Data
          </button>
        </div>
      </div>

      <!-- Global Filters Bar -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 bg-light rounded">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
              <label class="form-label small fw-bold mb-1"><i class="fas fa-filter me-1 text-primary"></i> Project</label>
              <select v-model="filters.project_id" @change="onProjectFilterChange" class="form-select form-select-sm">
                <option value="">All Active Projects</option>
                <optgroup v-if="activeProjectsList.length" label="Active Projects">
                  <option v-for="proj in activeProjectsList" :key="proj.id" :value="proj.id">
                    {{ proj.project_code || proj.name }} - {{ proj.name }}
                  </option>
                </optgroup>
                <optgroup v-if="completedProjectsList.length" label="Completed Projects">
                  <option v-for="proj in completedProjectsList" :key="proj.id" :value="proj.id">
                    ✓ {{ proj.project_code || proj.name }} - {{ proj.name }} (Completed)
                  </option>
                </optgroup>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Side</label>
              <select v-model="filters.side" @change="onProjectFilterChange" class="form-select form-select-sm">
                <option value="">All Sides (RH/LH/COMMON)</option>
                <option value="RH">RH (Right Hand)</option>
                <option value="LH">LH (Left Hand)</option>
                <option value="COMMON">COMMON</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">From Date</label>
              <input type="date" v-model="filters.date_from" @change="fetchData" class="form-control form-control-sm" />
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">To Date</label>
              <input type="date" v-model="filters.date_to" @change="fetchData" class="form-control form-control-sm" />
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end gap-2">
              <button @click="resetFilters" class="btn btn-outline-secondary btn-sm w-100 mt-4">
                <i class="fas fa-undo me-1"></i> Reset Filters
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- PRIMARY MANAGEMENT KPI CARDS (Exactly Two Structured Rows) -->
      <!-- ROW 1: Case A - All Active Projects Portfolio View (3 Prominent Cards) -->
      <div v-if="!filters.project_id" class="row g-3 mb-3">
        <!-- 1. Active Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm bg-primary text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('active_projects', 'Active Projects Portfolio')">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small d-flex align-items-center gap-1.5">
                  <span>Active Projects</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h2 class="fw-bold mb-0 display-6">{{ metrics.active_projects ?? metrics.total_projects ?? 0 }}</h2>
              </div>
              <i class="fas fa-folder-open fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 2. Completed Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0d9488;" @click="openKpiDrilldown('completed_projects', 'Completed Projects')">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small d-flex align-items-center gap-1.5">
                  <span>Completed Projects</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h2 class="fw-bold mb-0 display-6">{{ metrics.completed_projects || 0 }}</h2>
              </div>
              <i class="fas fa-check-circle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 3. Delayed Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm bg-danger text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('delayed_projects', 'Delayed Projects (Inactivity > 14 Days)')">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small d-flex align-items-center gap-1.5">
                  <span>Delayed Projects</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h2 class="fw-bold mb-0 display-6">{{ metrics.delayed_projects || 0 }}</h2>
              </div>
              <i class="fas fa-exclamation-triangle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 1: Case B - Selected Project Level 2 Banner (When single project is selected) -->
      <div v-else class="card border-0 shadow-sm mb-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff;">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 bg-white bg-opacity-10 rounded-3">
              <i class="fas fa-industry fa-2x text-primary"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-primary text-white px-2 py-1 fs-7">
                  {{ hierarchyData.project?.project_code || 'PROJECT' }}
                </span>
                <h4 class="fw-bold mb-0 text-white">{{ hierarchyData.project?.name || 'Selected Project' }}</h4>
                <span 
                  class="badge px-2 py-1 fs-7"
                  :class="hierarchyData.canonical_summary?.is_complete ? 'bg-success' : 'bg-warning text-dark'"
                >
                  {{ hierarchyData.canonical_summary?.is_complete ? '✓ COMPLETED' : 'ACTIVE' }}
                </span>
              </div>
              <small class="text-white-50">
                {{ hierarchyData.jigs?.length || 0 }} Jigs &bull; {{ hierarchyData.canonical_summary?.total_required || metrics.total_parts || 0 }} Total Parts &bull; Assembled: {{ hierarchyData.canonical_summary?.assembly_completed || metrics.parts_in_assembly || 0 }} pcs
              </small>
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <div class="text-end me-2">
              <div class="text-white-50 extra-small text-uppercase">Project Assembly Progress</div>
              <div class="fw-bold fs-5 text-white">{{ hierarchyData.canonical_summary?.completion_pct || metrics.completion_pct || 0 }}%</div>
            </div>
            <button @click="resetFilters" class="btn btn-outline-light btn-sm">
              <i class="fas fa-times me-1"></i> Clear / Portfolio View
            </button>
          </div>
        </div>
      </div>

      <!-- BOM TYPE TAB SWITCHER (ALL 3 TYPES / MFG / BOP / STD) -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-2 bg-white rounded d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="small fw-bold text-muted text-uppercase me-1"><i class="fas fa-layer-group me-1 text-primary"></i> BOM View:</span>
            <div class="btn-group btn-group-sm shadow-xs" role="group">
              <button 
                type="button" 
                class="btn fw-semibold"
                :class="activeBomTypeTab === 'ALL' ? 'btn-dark' : 'btn-outline-secondary bg-white'"
                @click="setBomViewType('ALL')">
                All 3 BOM Types
              </button>
              <button 
                type="button" 
                class="btn fw-semibold"
                :class="activeBomTypeTab === 'MFG' ? 'btn-primary' : 'btn-outline-secondary bg-white'"
                @click="setBomViewType('MFG')">
                <i class="fas fa-industry me-1"></i> Manufacturing (MFG)
                <span v-if="metrics.mfg?.total_parts || metrics.total_parts" class="badge bg-white text-primary ms-1">
                  {{ metrics.mfg?.total_parts ?? metrics.total_parts ?? 0 }}
                </span>
              </button>
              <button 
                type="button" 
                class="btn fw-semibold"
                :class="activeBomTypeTab === 'BOP' ? 'btn-warning text-dark' : 'btn-outline-secondary bg-white'"
                @click="setBomViewType('BOP')">
                <i class="fas fa-shopping-cart me-1"></i> Bought Out (BOP)
                <span v-if="metrics.bop?.total_parts !== undefined" class="badge bg-dark text-warning ms-1">
                  {{ metrics.bop?.total_parts ?? 0 }}
                </span>
              </button>
              <button 
                type="button" 
                class="btn fw-semibold text-nowrap"
                :class="activeBomTypeTab === 'STD' ? 'btn-teal text-white' : 'btn-outline-secondary bg-white'"
                @click="setBomViewType('STD')">
                <i class="fas fa-wrench me-1"></i> Standard Hardware (STD)
                <span v-if="metrics.std?.total_parts !== undefined" class="badge bg-white text-teal ms-1">
                  {{ metrics.std?.total_parts ?? 0 }}
                </span>
              </button>
            </div>
          </div>

          <!-- Quick summary badges pill -->
          <div class="d-none d-lg-flex align-items-center gap-2 small">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
              MFG: {{ metrics.mfg?.total_parts ?? metrics.total_parts ?? 0 }}
            </span>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
              BOP: {{ metrics.bop?.total_parts ?? 0 }}
            </span>
            <span class="badge bg-teal-subtle text-teal border border-teal-subtle px-2 py-1">
              STD: {{ metrics.std?.total_parts ?? 0 }}
            </span>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- GROUP 1: MANUFACTURING BOM (MFG) - 9 KPI CARDS (Blue Accent)             -->
      <!-- ========================================================================= -->
      <div v-if="activeBomTypeTab === 'ALL' || activeBomTypeTab === 'MFG'" class="mb-4">
        <!-- MFG Group Header -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary px-2 py-1 fs-7"><i class="fas fa-industry me-1"></i>MFG</span>
            <h6 class="fw-bold mb-0 text-dark">Manufacturing</h6>
          </div>
          <small class="text-muted">Total Required: <strong>{{ metrics.mfg?.total_required ?? metrics.total_required ?? 0 }} pcs</strong> &bull; Received: <strong class="text-success">{{ metrics.mfg?.total_received ?? metrics.total_received ?? 0 }} pcs</strong></small>
        </div>

        <div class="row g-2">
          <!-- 1. Total Parts -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #2563eb;" @click="openKpiDrilldown('total_parts', 'MFG - Total Parts (BOM Requirements)', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>MFG Parts</span>
                    <span v-if="metrics.ecn_total_parts" class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Isolated ECN Parts Count">
                      ECN: {{ metrics.ecn_total_parts }}
                    </span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.total_parts ?? metrics.total_parts ?? metrics.total_required ?? 0 }}</h3>
                </div>
                <i class="fas fa-industry text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 2. Total Parts Received -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_parts_received', 'MFG - Total Parts Received', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Total Received</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.total_parts_received ?? metrics.mfg?.total_received ?? metrics.total_parts_received ?? 0 }}</h3>
                </div>
                <i class="fas fa-boxes text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 3. Parts Pending -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-dark text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('parts_pending', 'MFG - Parts Pending Receipt', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Parts Pending</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_pending ?? metrics.mfg?.total_pending ?? metrics.parts_pending ?? 0 }}</h3>
                </div>
                <i class="fas fa-truck-loading text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 4. Store -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #d97706;" @click="openKpiDrilldown('store', 'MFG - Store Bay Inventory', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Store</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_in_store ?? metrics.parts_in_store ?? 0 }}</h3>
                </div>
                <i class="fas fa-warehouse text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 5. QC (with separate Rejected secondary badge) -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('qc', 'MFG - QC Bay Parts', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>QC</span>
                    <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Rejected parts in QC" @click.stop="openKpiDrilldown('qc', 'MFG - QC Rejected Parts', 'rejected', 'MFG')">
                      Rejected: {{ metrics.mfg?.qc_rejected ?? metrics.qc_rejected ?? 0 }}
                    </span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_in_qc ?? metrics.parts_in_qc ?? 0 }}</h3>
                </div>
                <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 6. Rework -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('rework', 'MFG - Active Rework Queue', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Rework</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_in_rework ?? metrics.parts_in_rework ?? 0 }}</h3>
                </div>
                <i class="fas fa-tools text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 7. Paint -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('paint', 'MFG - Paint Shop Parts', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Paint</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_in_paint ?? metrics.parts_in_paint ?? 0 }}</h3>
                </div>
                <i class="fas fa-paint-roller text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 8. Assembly (with separate Completed secondary badge) -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #db2777;" @click="openKpiDrilldown('assembly', 'MFG - Assembly Bay Parts', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Assembly</span>
                    <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Completed Assembly parts" @click.stop="openKpiDrilldown('assembly', 'MFG - Assembly Completed Parts', 'completed', 'MFG')">
                      Completed: {{ metrics.mfg?.assembly_completed ?? metrics.assembly_completed ?? 0 }}
                    </span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.mfg?.parts_in_assembly ?? metrics.parts_in_assembly ?? 0 }}</h3>
                </div>
                <i class="fas fa-cogs text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 9. ECN (Isolated Engineering Change Notices) -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #b45309;" @click="openKpiDrilldown('ecn', 'MFG - ECN Parts (Isolated Engineering Change Notices)', 'all', 'MFG')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>ECN</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.ecn_total_parts || 0 }}</h3>
                </div>
                <i class="fas fa-exchange-alt text-white-50 fs-5"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- GROUP 2: BOUGHT OUT PARTS (BOP) - 9 KPI CARDS (Warm Amber / Gold Accent)  -->
      <!-- ========================================================================= -->
      <div v-if="activeBomTypeTab === 'ALL' || activeBomTypeTab === 'BOP'" class="mb-4">
        <!-- BOP Group Header -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark px-2 py-1 fs-7"><i class="fas fa-shopping-cart me-1"></i>BOP</span>
            <h6 class="fw-bold mb-0 text-dark">BOP</h6>
          </div>
          <small class="text-muted">Total Required: <strong>{{ metrics.bop?.total_required ?? 0 }} pcs</strong> &bull; Received: <strong class="text-success">{{ metrics.bop?.total_received ?? 0 }} pcs</strong></small>
        </div>

        <div class="row g-2">
          <!-- 1. Total Parts -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #d97706;" @click="openKpiDrilldown('total_parts', 'BOP - Total Parts (Bought Out Requirements)', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Total Parts</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.total_parts ?? metrics.bop?.total_required ?? 0 }}</h3>
                </div>
                <i class="fas fa-shopping-cart text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 2. Total Parts Received -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_parts_received', 'BOP - Total Parts Received', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Total Received</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.total_parts_received ?? metrics.bop?.total_received ?? 0 }}</h3>
                </div>
                <i class="fas fa-boxes text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 3. Parts Pending -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-dark text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('parts_pending', 'BOP - Parts Pending Receipt', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Parts Pending</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_pending ?? metrics.bop?.total_pending ?? 0 }}</h3>
                </div>
                <i class="fas fa-truck-loading text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 4. Store -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #b45309;" @click="openKpiDrilldown('store', 'BOP - Store Bay Inventory', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Store</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_in_store || 0 }}</h3>
                </div>
                <i class="fas fa-warehouse text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 5. QC (with separate Rejected secondary badge) -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('qc', 'BOP - QC Bay Parts', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>QC</span>
                    <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Rejected parts in QC" @click.stop="openKpiDrilldown('qc', 'BOP - QC Rejected Parts', 'rejected', 'BOP')">
                      Rejected: {{ metrics.bop?.qc_rejected || 0 }}
                    </span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_in_qc || 0 }}</h3>
                </div>
                <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 6. Rework -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('rework', 'BOP - Active Rework Queue', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Rework</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_in_rework || 0 }}</h3>
                </div>
                <i class="fas fa-tools text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 7. Paint -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('paint', 'BOP - Paint Shop Parts', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Paint</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_in_paint || 0 }}</h3>
                </div>
                <i class="fas fa-paint-roller text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 8. Assembly -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #db2777;" @click="openKpiDrilldown('assembly', 'BOP - Assembly Bay Parts', 'all', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Assembly</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.parts_in_assembly || 0 }}</h3>
                </div>
                <i class="fas fa-cogs text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 9. Assembly Completed -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #059669;" @click="openKpiDrilldown('assembly', 'BOP - Assembly Completed Parts', 'completed', 'BOP')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Assembly Completed</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.bop?.assembly_completed || 0 }}</h3>
                </div>
                <i class="fas fa-check-double text-white-50 fs-5"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- GROUP 3: STANDARD HARDWARE (STD) - 9 KPI CARDS (Teal / Emerald Accent)   -->
      <!-- ========================================================================= -->
      <div v-if="activeBomTypeTab === 'ALL' || activeBomTypeTab === 'STD'" class="mb-4">
        <!-- STD Group Header -->
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-teal text-white px-2 py-1 fs-7"><i class="fas fa-wrench me-1"></i>STD</span>
            <h6 class="fw-bold mb-0 text-dark">Standard</h6>
          </div>
          <small class="text-muted">Total Required: <strong>{{ metrics.std?.total_required ?? 0 }} pcs</strong> &bull; Received: <strong class="text-success">{{ metrics.std?.total_received ?? 0 }} pcs</strong></small>
        </div>

        <div class="row g-2">
          <!-- 1. Total Parts -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0d9488;" @click="openKpiDrilldown('total_parts', 'STD - Total Parts (Standard Hardware Requirements)', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Total Parts</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.total_parts ?? metrics.std?.total_required ?? 0 }}</h3>
                </div>
                <i class="fas fa-wrench text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 2. Total Parts Received -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_parts_received', 'STD - Total Parts Received', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Total Received</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.total_parts_received ?? metrics.std?.total_received ?? 0 }}</h3>
                </div>
                <i class="fas fa-boxes text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 3. Parts Pending -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm bg-dark text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('parts_pending', 'STD - Parts Pending Receipt', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Parts Pending</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_pending ?? metrics.std?.total_pending ?? 0 }}</h3>
                </div>
                <i class="fas fa-truck-loading text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 4. Store -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0f766e;" @click="openKpiDrilldown('store', 'STD - Store Bay Inventory', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Store</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_in_store || 0 }}</h3>
                </div>
                <i class="fas fa-warehouse text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 5. QC (with separate Rejected secondary badge) -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('qc', 'STD - QC Bay Parts', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>QC</span>
                    <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Rejected parts in QC" @click.stop="openKpiDrilldown('qc', 'STD - QC Rejected Parts', 'rejected', 'STD')">
                      Rejected: {{ metrics.std?.qc_rejected || 0 }}
                    </span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_in_qc || 0 }}</h3>
                </div>
                <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 6. Rework -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('rework', 'STD - Active Rework Queue', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Rework</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_in_rework || 0 }}</h3>
                </div>
                <i class="fas fa-tools text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 7. Paint -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('paint', 'STD - Paint Shop Parts', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Paint</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_in_paint || 0 }}</h3>
                </div>
                <i class="fas fa-paint-roller text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 8. Assembly -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #059669;" @click="openKpiDrilldown('assembly', 'STD - Assembly Bay Parts', 'all', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Assembly</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.parts_in_assembly || 0 }}</h3>
                </div>
                <i class="fas fa-cogs text-white-50 fs-5"></i>
              </div>
            </div>
          </div>

          <!-- 9. Assembly Completed -->
          <div class="col-6 col-sm-4 col-md-3 col-xl">
            <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0d9488;" @click="openKpiDrilldown('assembly', 'STD - Assembly Completed Parts', 'completed', 'STD')">
              <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                    <span>Assembly Completed</span>
                    <i class="fas fa-search-plus extra-small opacity-75"></i>
                  </div>
                  <h3 class="fw-bold mb-0 fs-4">{{ metrics.std?.assembly_completed || 0 }}</h3>
                </div>
                <i class="fas fa-check-double text-white-50 fs-5"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- OPTION A: ALL ACTIVE PROJECTS PORTFOLIO VIEW (When no project is selected) -->
      <!-- ========================================================================= -->
      <div v-if="!filters.project_id">
        <!-- ALL ACTIVE PROJECTS MANAGEMENT VISUALS (Top Projects Near Completion & Overall Health Distribution) -->
        <div class="row g-3 mb-4">
          <!-- Top Projects Near Completion Bar Chart -->
          <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                  <h5 class="card-title fw-bold mb-0 text-dark">
                    <i class="fas fa-trophy text-warning me-2"></i>Top Projects Near Completion
                  </h5>
                  <small class="text-muted">Ranked by completion percentage (Active Projects)</small>
                </div>
                <span class="badge bg-primary px-2 py-1">{{ topProjectsData.total_active_incomplete || topProjectsData.labels?.length || 0 }} Active Incomplete</span>
              </div>
            <div class="card-body">
              <div v-if="topProjectsData.labels?.length" style="height: 280px; position: relative;">
                <canvas ref="topProjectsChartCanvas"></canvas>
              </div>
              <div v-else class="text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                <div>All active projects have reached 100% completion or no project data available.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Overall Project Health Distribution Visual -->
        <div class="col-12 col-xl-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-heartbeat text-danger me-2"></i>Project Health Distribution
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;"
                     title="Executive Portfolio Health Classification:&#10;• Near Completion: ≥85% complete&#10;• On Track: Active progress in last 7 days&#10;• At Risk: No activity for 7-14 days&#10;• Delayed: No activity over 14 days and under 80% complete"></i>
                </h5>
                <small class="text-muted">Executive Portfolio Risk &amp; Velocity Status</small>
              </div>
              <span class="badge bg-dark px-2 py-1">{{ healthDistribution.total_active || 0 }} Total Active</span>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
              <div class="row g-2 mb-3">
                <div class="col-6">
                  <div class="p-2 rounded border bg-success-subtle text-success-emphasis d-flex justify-content-between align-items-center">
                    <div>
                      <div class="small fw-bold">Near Completion</div>
                      <div class="fs-5 fw-bold">{{ healthDistribution.counts?.near_completion || 0 }}</div>
                    </div>
                    <span class="badge bg-success">{{ healthDistribution.percentages?.near_completion || 0 }}%</span>
                  </div>
                </div>
                <div class="col-6">
                  <div class="p-2 rounded border bg-primary-subtle text-primary-emphasis d-flex justify-content-between align-items-center">
                    <div>
                      <div class="small fw-bold">On Track</div>
                      <div class="fs-5 fw-bold">{{ healthDistribution.counts?.on_track || 0 }}</div>
                    </div>
                    <span class="badge bg-primary">{{ healthDistribution.percentages?.on_track || 0 }}%</span>
                  </div>
                </div>
                <div class="col-6">
                  <div class="p-2 rounded border bg-warning-subtle text-warning-emphasis d-flex justify-content-between align-items-center">
                    <div>
                      <div class="small fw-bold">At Risk</div>
                      <div class="fs-5 fw-bold">{{ healthDistribution.counts?.at_risk || 0 }}</div>
                    </div>
                    <span class="badge bg-warning text-dark">{{ healthDistribution.percentages?.at_risk || 0 }}%</span>
                  </div>
                </div>
                <div class="col-6">
                  <div class="p-2 rounded border bg-danger-subtle text-danger-emphasis d-flex justify-content-between align-items-center">
                    <div>
                      <div class="small fw-bold">Delayed</div>
                      <div class="fs-5 fw-bold">{{ healthDistribution.counts?.delayed || 0 }}</div>
                    </div>
                    <span class="badge bg-danger">{{ healthDistribution.percentages?.delayed || 0 }}%</span>
                  </div>
                </div>
              </div>
              <div style="height: 170px; position: relative;">
                <canvas ref="healthChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
      </div>
      <!-- END OF OPTION A (All Active Projects Portfolio View) -->

      <!-- ========================================================================= -->
      <!-- OPTION B: 5-LEVEL PROJECT DRILL-DOWN HIERARCHY (When project selected)   -->
      <!-- ========================================================================= -->
      <div v-else class="mb-4">
        <!-- Hierarchy Section Header (Compact Toolbar) -->
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <div class="d-flex align-items-center gap-1.5">
                <i class="fas fa-sitemap text-primary"></i>
                <h6 class="card-title fw-bold mb-0 text-dark">Project Hierarchy Drill-Down</h6>
              </div>
              <span class="badge bg-light text-dark border px-2 py-1 fs-7">
                {{ displayedHierarchySections.reduce((acc, s) => acc + (s.jigs?.length || 0), 0) }} Jigs &bull; {{ displayedHierarchySections.reduce((acc, s) => acc + (s.completed || 0), 0) }} Complete
              </span>
              <span v-if="activeHierarchyBomType === 'ALL'" class="badge bg-secondary-subtle text-dark border px-2 py-1 fs-7">
                <i class="fas fa-columns text-primary me-1"></i> Side-by-Side (MFG | BOP | STD)
              </span>
              <span v-else class="badge px-2 py-1 fs-7" :class="activeHierarchyBomType === 'MFG' ? 'bg-primary text-white' : (activeHierarchyBomType === 'BOP' ? 'bg-warning text-dark' : 'bg-teal text-white')">
                {{ activeHierarchyBomType === 'MFG' ? 'MFG Only' : (activeHierarchyBomType === 'BOP' ? 'BOP Only' : 'STD Only') }}
              </span>
            </div>
            
            <!-- BOM Type Filter & Expand/Collapse Controls -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <div class="btn-group btn-group-sm shadow-xs" role="group">
                <button 
                  type="button" 
                  class="btn btn-xs fw-semibold px-2"
                  :class="activeHierarchyBomType === 'ALL' ? 'btn-dark text-white' : 'btn-outline-secondary bg-white'"
                  @click="setHierarchyBomType('ALL')"
                  title="View MFG, BOP, STD side-by-side"
                >
                  <i class="fas fa-columns me-1"></i> All Types
                </button>
                <button 
                  type="button" 
                  class="btn btn-xs fw-semibold px-2"
                  :class="activeHierarchyBomType === 'MFG' ? 'btn-primary' : 'btn-outline-secondary bg-white'"
                  @click="setHierarchyBomType('MFG')"
                  title="Filter to Manufacturing"
                >
                  <i class="fas fa-industry me-1"></i> MFG
                </button>
                <button 
                  type="button" 
                  class="btn btn-xs fw-semibold px-2"
                  :class="activeHierarchyBomType === 'BOP' ? 'btn-warning text-dark' : 'btn-outline-secondary bg-white'"
                  @click="setHierarchyBomType('BOP')"
                  title="Filter to Bought Out Parts"
                >
                  <i class="fas fa-shopping-cart me-1"></i> BOP
                </button>
                <button 
                  type="button" 
                  class="btn btn-xs fw-semibold text-nowrap px-2"
                  :class="activeHierarchyBomType === 'STD' ? 'btn-teal text-white' : 'btn-outline-secondary bg-white'"
                  @click="setHierarchyBomType('STD')"
                  title="Filter to Standard Hardware"
                >
                  <i class="fas fa-wrench me-1"></i> STD
                </button>
              </div>

              <div class="btn-group btn-group-sm shadow-xs">
                <button @click="expandAllJigs" class="btn btn-outline-secondary btn-xs px-2" title="Expand all jigs in all panels">
                  <i class="fas fa-expand-arrows-alt me-1"></i> Expand All
                </button>
                <button @click="collapseAllJigs" class="btn btn-outline-secondary btn-xs px-2" title="Collapse all jigs">
                  <i class="fas fa-compress-arrows-alt me-1"></i> Collapse All
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Shimmer / Loading State -->
        <div v-if="hierarchyLoading" class="card border-0 shadow-sm p-5 text-center bg-white">
          <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Loading Hierarchy...</span>
          </div>
          <h6 class="fw-bold text-dark">Building 5-Level Project Hierarchy...</h6>
          <small class="text-muted">Loading Jigs, Units (LH/RH), and Part-level Workstation Statuses</small>
        </div>

        <!-- THREE-TYPE HIERARCHY PANELS (Side-by-Side in ALL mode, Full-width in single type mode) -->
        <div 
          v-else 
          class="hierarchy-panels-container"
          :class="{ 'three-column-mode': activeHierarchyBomType === 'ALL' }"
        >
          <div 
            v-for="section in displayedHierarchySections" 
            :key="section.key"
            class="hierarchy-panel-column border rounded-3 overflow-hidden shadow-sm bg-white"
            :class="{ 'single-type-panel': activeHierarchyBomType !== 'ALL' }"
          >
            <!-- STICKY SECTION HEADER -->
            <div 
              class="hierarchy-panel-header-sticky d-flex justify-content-between align-items-center px-3 py-2 border-bottom cursor-pointer select-none"
              :style="{ backgroundColor: section.headerBg, borderLeft: `4px solid ${section.accentColor}` }"
              @click="toggleSectionCollapse(section.key)"
            >
              <div class="d-flex align-items-center gap-2">
                <button 
                  type="button" 
                  class="btn btn-xs btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center" 
                  style="width: 22px; height: 22px;"
                  @click.stop="toggleSectionCollapse(section.key)"
                >
                  <i class="fas" :class="collapsedSections[section.key] ? 'fa-chevron-right text-muted' : 'fa-chevron-down text-dark'" style="font-size: 0.65rem;"></i>
                </button>
                <span class="badge" :class="section.badgeClass" style="font-size: 0.72rem; padding: 2px 6px;">{{ section.badge }}</span>
                <span class="fw-bold text-dark fs-6">{{ section.title }}</span>
                <span class="badge bg-white border text-muted small" style="font-size: 0.7rem; padding: 2px 6px;">
                  {{ section.jigs ? section.jigs.length : 0 }} Jigs ({{ section.completed || 0 }} Complete)
                </span>
              </div>

              <div class="d-flex align-items-center gap-1">
                <span 
                  class="badge bg-light text-secondary border px-1.5 py-0.5" 
                  style="font-size: 0.65rem;"
                  :title="collapsedSections[section.key] ? 'Click to expand this panel' : 'Click to collapse this panel'"
                >
                  <i class="fas" :class="collapsedSections[section.key] ? 'fa-plus' : 'fa-minus'"></i>
                </span>
              </div>
            </div>

            <!-- SECTION SCROLLABLE BODY (Independent scrolling per panel) -->
            <div 
              v-show="!collapsedSections[section.key]" 
              class="hierarchy-panel-scrollable-body p-2.5 bg-light bg-opacity-50"
            >
              <!-- Empty state inside section -->
              <div v-if="!section.jigs || !section.jigs.length" class="text-center py-4 bg-white rounded border border-dashed">
                <i class="fas fa-box-open text-muted mb-2 fs-5"></i>
                <div class="small fw-semibold text-muted">No {{ section.title }} items or jigs found for this project.</div>
              </div>

              <!-- JIGS LIST FOR THIS SECTION -->
              <div v-else class="d-flex flex-column gap-2.5">
                <div 
                  v-for="jig in section.jigs" 
                  :key="jig.jig_name"
                  class="card border-0 shadow-sm overflow-hidden"
                  :class="{ 'border border-2 border-success': jig.is_complete }"
                >
                  <!-- JIG CARD HEADER (Level 3) -->
                  <div 
                    class="card-header py-2.5 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2 cursor-pointer select-none"
                    :style="{ backgroundColor: jig.is_complete ? '#ecfdf5' : '#ffffff' }"
                    @click="toggleJigExpand(section.key, jig.jig_name)"
                  >
                    <div class="d-flex align-items-center gap-2">
                      <button 
                        type="button"
                        class="btn btn-xs btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center" 
                        style="width: 24px; height: 24px;"
                        @click.stop="toggleJigExpand(section.key, jig.jig_name)"
                      >
                        <i class="fas" :class="expandedJigs[`${section.key}_${jig.jig_name}`] ? 'fa-chevron-down text-primary' : 'fa-chevron-right text-muted'" style="font-size: 0.65rem;"></i>
                      </button>
                      <div>
                        <div class="d-flex align-items-center gap-1.5 flex-wrap">
                          <span class="fw-bold text-dark fs-7">{{ jig.jig_name }}</span>
                          <span v-if="jig.ecn_present || jig.is_ecn_present || (jig.ecn_count > 0)" class="badge shadow-xs" style="font-size: 0.62rem; font-weight: 700; background-color: #f59e0b; color: #ffffff; padding: 1px 5px; border-radius: 4px;" title="Contains ECN parts">
                            ECN
                          </span>
                          <span v-if="jig.is_complete" class="badge bg-success px-1.5 py-0.5" style="font-size: 0.68rem;">
                            <i class="fas fa-check-circle me-0.5"></i> Done
                          </span>
                          <span v-else class="badge bg-primary-subtle text-primary border border-primary-subtle px-1.5 py-0.5" style="font-size: 0.68rem;">
                            In Progress
                          </span>
                          <button 
                            v-if="['ADMIN', 'MANAGER', 'PURCHASE'].includes(authStore.userRole)"
                            type="button" 
                            class="btn btn-xs btn-outline-dark shadow-xs d-inline-flex align-items-center gap-1 py-0 px-1"
                            style="font-size: 0.68rem; border-radius: 3px;"
                            title="View Jig Assigned Suppliers"
                            @click.stop="openJigSupplierModal(jig)"
                          >
                            <i class="fas fa-truck text-primary"></i>
                            <span>Supplier</span>
                          </button>
                        </div>
                        <small class="text-muted extra-small">{{ jig.total_units || jig.units?.length || 0 }} Units &bull; {{ jig.total_parts || 0 }} Parts</small>
                      </div>
                    </div>

                    <!-- Jig Metrics Pills & Completion Bar -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <div class="d-flex align-items-center gap-1 extra-small">
                        <span class="badge bg-light text-dark border px-1.5 py-0.5" title="Total Required">Req: <strong>{{ jig.total_required }}</strong></span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-1.5 py-0.5" title="Total Received">Rec: <strong>{{ jig.total_received }}</strong></span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-1.5 py-0.5" title="Total Pending">Pend: <strong>{{ jig.total_pending }}</strong></span>
                        <span class="badge bg-purple text-white px-1.5 py-0.5" title="Assembled Parts">Asm: <strong>{{ jig.metrics?.assembly_completed || 0 }}</strong></span>
                      </div>

                      <div class="d-flex align-items-center gap-1.5" style="min-width: 100px;">
                        <div class="progress flex-grow-1" style="height: 6px;">
                          <div 
                            class="progress-bar" 
                            :class="jig.is_complete ? 'bg-success' : 'bg-primary'"
                            :style="{ width: `${jig.completion_pct || 0}%` }"
                          ></div>
                        </div>
                        <span class="extra-small fw-bold text-nowrap" :class="jig.is_complete ? 'text-success' : 'text-primary'">
                          {{ jig.completion_pct || 0 }}%
                        </span>
                      </div>
                    </div>
                  </div>

                  <!-- JIG BODY: LEVEL 4 UNITS -->
                  <div v-if="expandedJigs[`${section.key}_${jig.jig_name}`]" class="card-body bg-light p-2.5">
                    <div class="d-flex flex-column gap-2.5">
                      <div 
                        v-for="unit in jig.units" 
                        :key="unit.unit_no"
                        class="card border-0 shadow-sm bg-white overflow-hidden"
                        :class="{ 'border border-2 border-success': unit.is_complete }"
                      >
                        <!-- UNIT HEADER (Level 4) -->
                        <div 
                          class="card-header py-2 px-2.5 d-flex flex-wrap justify-content-between align-items-center gap-2"
                          :style="{ backgroundColor: unit.is_complete ? '#f0fdf4' : '#f8fafc' }"
                        >
                          <div class="d-flex align-items-center gap-1.5 flex-wrap">
                            <i class="fas fa-cube" :class="unit.is_complete ? 'text-success' : 'text-primary'" style="font-size: 0.8rem;"></i>
                            <span class="fw-bold text-dark fs-7">{{ unit.unit_no }}</span>
                            <span v-if="unit.ecn_present || unit.is_ecn_present || (unit.ecn_count > 0)" class="badge shadow-xs" style="font-size: 0.62rem; font-weight: 700; background-color: #f59e0b; color: #ffffff; padding: 1px 4px; border-radius: 3px;" title="Contains ECN parts">
                              ECN
                            </span>
                            <span v-if="unit.is_complete" class="badge bg-success px-1.5 py-0.5" style="font-size: 0.68rem;">
                              <i class="fas fa-check-double me-0.5"></i> Complete
                            </span>
                            <span v-else class="badge bg-secondary-subtle text-secondary border px-1.5 py-0.5" style="font-size: 0.68rem;">
                              Incomplete
                            </span>
                          </div>

                          <div class="d-flex align-items-center gap-2">
                            <div class="d-flex align-items-center gap-1" style="width: 80px;">
                              <div class="progress flex-grow-1" style="height: 5px;">
                                <div 
                                  class="progress-bar" 
                                  :class="unit.is_complete ? 'bg-success' : 'bg-primary'"
                                  :style="{ width: `${unit.completion_pct || 0}%` }"
                                ></div>
                              </div>
                              <span class="extra-small fw-bold text-nowrap" :class="unit.is_complete ? 'text-success' : 'text-muted'">
                                {{ unit.completion_pct || 0 }}%
                              </span>
                            </div>

                            <button 
                              @click="toggleUnitExpand(`${section.key}_${jig.jig_name}_${unit.unit_no}`)" 
                              class="btn btn-xs py-0.5 px-1.5"
                              :class="expandedUnits[`${section.key}_${jig.jig_name}_${unit.unit_no}`] ? 'btn-primary' : 'btn-outline-primary'"
                            >
                              <i class="fas" :class="expandedUnits[`${section.key}_${jig.jig_name}_${unit.unit_no}`] ? 'fa-table me-1' : 'fa-list me-1'"></i>
                              {{ expandedUnits[`${section.key}_${jig.jig_name}_${unit.unit_no}`] ? 'Hide' : 'Parts' }}
                            </button>
                          </div>
                        </div>

                        <!-- UNIT BODY: LEVEL 4 -->
                        <div class="card-body p-2.5">
                          <!-- COMMON JIG / UNIT SINGLE CARD -->
                          <div v-if="unit.has_common || unit.sides?.COMMON" class="row g-2">
                            <div class="col-12">
                              <div 
                                class="p-2.5 rounded border h-100 position-relative"
                                :class="unit.sides?.COMMON?.is_complete ? 'border-success bg-success-subtle bg-opacity-10' : 'border-light bg-light'"
                              >
                                <div class="d-flex justify-content-between align-items-center mb-1.5">
                                  <span class="fw-bold text-uppercase extra-small d-flex align-items-center gap-1">
                                    <i class="fas fa-circle-notch text-primary"></i> Common Fixture
                                    <span v-if="unit.sides?.COMMON?.ecn_present || unit.sides?.COMMON?.is_ecn_present || (unit.sides?.COMMON?.ecn_count > 0)" class="badge shadow-xs" style="font-size: 0.6rem; font-weight: 700; background-color: #f59e0b; color: #ffffff; padding: 1px 4px; border-radius: 3px;">
                                      ECN
                                    </span>
                                  </span>
                                  <span v-if="unit.sides?.COMMON?.is_complete" class="badge bg-success" style="font-size: 0.68rem;">
                                    <i class="fas fa-check me-0.5"></i> Complete
                                  </span>
                                  <span v-else class="badge bg-warning text-dark" style="font-size: 0.68rem;">
                                    {{ unit.sides?.COMMON?.completion_pct || 0 }}%
                                  </span>
                                </div>

                                <div class="row g-1 text-center my-1">
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.62rem;">Req</small>
                                      <span class="fw-bold fs-7 text-dark">{{ unit.sides?.COMMON?.total_required || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.62rem;">Rec</small>
                                      <span class="fw-bold fs-7 text-success">{{ unit.sides?.COMMON?.total_received || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.62rem;">Pend</small>
                                      <span class="fw-bold fs-7 text-danger">{{ unit.sides?.COMMON?.pending_quantity || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.62rem;">Asm</small>
                                      <span class="fw-bold fs-7 text-purple">{{ unit.sides?.COMMON?.assembly_completed || 0 }}</span>
                                    </div>
                                  </div>
                                </div>

                                <div class="mt-1.5">
                                  <div class="progress" style="height: 4px;">
                                    <div 
                                      class="progress-bar bg-success" 
                                      :style="{ width: `${unit.sides?.COMMON?.completion_pct || 0}%` }"
                                    ></div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- SIDE-BY-SIDE LH & RH (Level 4) -->
                          <div v-else class="row g-2">
                            <!-- LEFT HAND (LH) SIDE CARD -->
                            <div class="col-12 col-xl-6">
                              <div 
                                class="p-2 rounded border h-100 position-relative"
                                :class="unit.sides?.LH?.is_complete ? 'border-success bg-success-subtle bg-opacity-10' : 'border-light bg-light'"
                              >
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                  <span class="fw-bold text-uppercase extra-small d-flex align-items-center gap-1">
                                    <i class="fas fa-arrow-left text-primary"></i> LH
                                    <span v-if="unit.sides?.LH?.ecn_present || unit.sides?.LH?.is_ecn_present || (unit.sides?.LH?.ecn_count > 0)" class="badge shadow-xs" style="font-size: 0.6rem; font-weight: 700; background-color: #f59e0b; color: #ffffff; padding: 1px 4px; border-radius: 3px;">
                                      ECN
                                    </span>
                                  </span>
                                  <span v-if="unit.sides?.LH?.is_complete" class="badge bg-success" style="font-size: 0.65rem;">
                                    <i class="fas fa-check me-0.5"></i> Complete
                                  </span>
                                  <span v-else class="badge bg-warning text-dark" style="font-size: 0.65rem;">
                                    {{ unit.sides?.LH?.completion_pct || 0 }}%
                                  </span>
                                </div>

                                <div class="row g-1 text-center my-1">
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Req</small>
                                      <span class="fw-bold fs-7 text-dark">{{ unit.sides?.LH?.total_required || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Rec</small>
                                      <span class="fw-bold fs-7 text-success">{{ unit.sides?.LH?.total_received || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Pend</small>
                                      <span class="fw-bold fs-7 text-danger">{{ unit.sides?.LH?.pending_quantity || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Asm</small>
                                      <span class="fw-bold fs-7 text-purple">{{ unit.sides?.LH?.assembly_completed || 0 }}</span>
                                    </div>
                                  </div>
                                </div>

                                <div class="mt-1">
                                  <div class="progress" style="height: 4px;">
                                    <div 
                                      class="progress-bar bg-success" 
                                      :style="{ width: `${unit.sides?.LH?.completion_pct || 0}%` }"
                                    ></div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <!-- RIGHT HAND (RH) SIDE CARD -->
                            <div class="col-12 col-xl-6">
                              <div 
                                class="p-2 rounded border h-100 position-relative"
                                :class="unit.sides?.RH?.is_complete ? 'border-success bg-success-subtle bg-opacity-10' : 'border-light bg-light'"
                              >
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                  <span class="fw-bold text-uppercase extra-small d-flex align-items-center gap-1">
                                    <i class="fas fa-arrow-right text-primary"></i> RH
                                    <span v-if="unit.sides?.RH?.ecn_present || unit.sides?.RH?.is_ecn_present || (unit.sides?.RH?.ecn_count > 0)" class="badge shadow-xs" style="font-size: 0.6rem; font-weight: 700; background-color: #f59e0b; color: #ffffff; padding: 1px 4px; border-radius: 3px;">
                                      ECN
                                    </span>
                                  </span>
                                  <span v-if="unit.sides?.RH?.is_complete" class="badge bg-success" style="font-size: 0.65rem;">
                                    <i class="fas fa-check me-0.5"></i> Complete
                                  </span>
                                  <span v-else class="badge bg-warning text-dark" style="font-size: 0.65rem;">
                                    {{ unit.sides?.RH?.completion_pct || 0 }}%
                                  </span>
                                </div>

                                <div class="row g-1 text-center my-1">
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Req</small>
                                      <span class="fw-bold fs-7 text-dark">{{ unit.sides?.RH?.total_required || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Rec</small>
                                      <span class="fw-bold fs-7 text-success">{{ unit.sides?.RH?.total_received || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Pend</small>
                                      <span class="fw-bold fs-7 text-danger">{{ unit.sides?.RH?.pending_quantity || 0 }}</span>
                                    </div>
                                  </div>
                                  <div class="col-3">
                                    <div class="bg-white p-1 rounded border">
                                      <small class="text-muted extra-small d-block text-uppercase" style="font-size: 0.6rem;">Asm</small>
                                      <span class="fw-bold fs-7 text-purple">{{ unit.sides?.RH?.assembly_completed || 0 }}</span>
                                    </div>
                                  </div>
                                </div>

                                <div class="mt-1">
                                  <div class="progress" style="height: 4px;">
                                    <div 
                                      class="progress-bar bg-success" 
                                      :style="{ width: `${unit.sides?.RH?.completion_pct || 0}%` }"
                                    ></div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>

                          <!-- LEVEL 5: PART INVENTORY TABLE (Inside Unit) -->
                          <div v-if="expandedUnits[`${section.key}_${jig.jig_name}_${unit.unit_no}`]" class="mt-2.5 pt-2.5 border-top">
                            <!-- Table Filter Tabs & Search -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                              <!-- Common Only Unit Tab -->
                              <div v-if="(unit.has_common || unit.sides?.COMMON) && !unit.sides?.LH && !unit.sides?.RH" class="btn-group btn-group-sm" role="group">
                                <button 
                                  type="button" 
                                  class="btn btn-primary btn-xs"
                                >
                                  Common Parts ({{ unit.sides?.COMMON?.parts?.length || 0 }})
                                </button>
                              </div>
                              <!-- Side Specific / Mixed Tabs -->
                              <div v-else class="btn-group btn-group-sm" role="group">
                                <button 
                                  type="button" 
                                  class="btn btn-xs" 
                                  :class="(unitSideTab[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 'ALL') === 'ALL' ? 'btn-primary' : 'btn-outline-secondary'"
                                  @click="setUnitSideTab(`${section.key}_${jig.jig_name}_${unit.unit_no}`, 'ALL')"
                                >
                                  All ({{ (unit.sides?.LH?.parts?.length || 0) + (unit.sides?.RH?.parts?.length || 0) + (unit.sides?.COMMON?.parts?.length || 0) }})
                                </button>
                                <button 
                                  v-if="unit.sides?.LH"
                                  type="button" 
                                  class="btn btn-xs" 
                                  :class="unitSideTab[`${section.key}_${jig.jig_name}_${unit.unit_no}`] === 'LH' ? 'btn-primary' : 'btn-outline-secondary'"
                                  @click="setUnitSideTab(`${section.key}_${jig.jig_name}_${unit.unit_no}`, 'LH')"
                                >
                                  LH ({{ unit.sides?.LH?.parts?.length || 0 }})
                                </button>
                                <button 
                                  v-if="unit.sides?.RH"
                                  type="button" 
                                  class="btn btn-xs" 
                                  :class="unitSideTab[`${section.key}_${jig.jig_name}_${unit.unit_no}`] === 'RH' ? 'btn-primary' : 'btn-outline-secondary'"
                                  @click="setUnitSideTab(`${section.key}_${jig.jig_name}_${unit.unit_no}`, 'RH')"
                                >
                                  RH ({{ unit.sides?.RH?.parts?.length || 0 }})
                                </button>
                                <button 
                                  v-if="unit.sides?.COMMON"
                                  type="button" 
                                  class="btn btn-xs" 
                                  :class="unitSideTab[`${section.key}_${jig.jig_name}_${unit.unit_no}`] === 'COMMON' ? 'btn-primary' : 'btn-outline-secondary'"
                                  @click="setUnitSideTab(`${section.key}_${jig.jig_name}_${unit.unit_no}`, 'COMMON')"
                                >
                                  Common ({{ unit.sides?.COMMON?.parts?.length || 0 }})
                                </button>
                              </div>

                              <div class="input-group input-group-sm" style="max-width: 200px;">
                                <span class="input-group-text bg-white py-0 px-2"><i class="fas fa-search text-muted" style="font-size: 0.7rem;"></i></span>
                                <input 
                                  type="text" 
                                  class="form-control form-control-sm py-0.5 px-2" 
                                  style="font-size: 0.75rem;"
                                  placeholder="Search part #, item..."
                                  v-model="unitPartSearch[`${section.key}_${jig.jig_name}_${unit.unit_no}`]"
                                />
                              </div>
                            </div>

                            <!-- Part Inventory Table -->
                            <div class="table-responsive rounded border" style="max-height: 380px; overflow-y: auto;">
                              <table class="table table-sm table-hover align-middle mb-0 text-center" style="font-size: 0.78rem;">
                                <thead style="background-color: #0f172a !important; color: #ffffff !important; position: sticky; top: 0; z-index: 2;">
                                  <tr>
                                    <th style="width: 35px; color: #fff; background-color: #0f172a; padding: 4px 6px;">#</th>
                                    <th style="color: #fff; background-color: #0f172a; text-align: left; padding: 4px 6px;">PART NUMBER</th>
                                    <th style="color: #fff; background-color: #0f172a; text-align: left; padding: 4px 6px;">ITEM NO</th>
                                    <th style="color: #fff; background-color: #0f172a; text-align: left; padding: 4px 6px;">SUPPLIER</th>
                                    <th style="color: #fff; background-color: #0f172a; padding: 4px 6px;">SIDE</th>
                                    <th style="color: #fff; background-color: #0f172a; padding: 4px 6px;">REQ</th>
                                    <th style="color: #fff; background-color: #0f172a; padding: 4px 6px;">REC</th>
                                    <th style="color: #fff; background-color: #0f172a; padding: 4px 6px;">PEND</th>
                                    <th style="color: #fff; background-color: #0f172a; padding: 4px 6px;">STATUS</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <tr v-for="(part, pIdx) in getPaginatedUnitParts(unit, `${section.key}_${jig.jig_name}_${unit.unit_no}`)" :key="part.id || pIdx">
                                    <td class="text-muted extra-small py-1 px-1.5">{{ ((unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1) - 1) * 10 + pIdx + 1 }}</td>
                                    <td class="text-start py-1 px-1.5">
                                      <div class="d-flex align-items-center gap-1 flex-wrap">
                                        <span 
                                          v-if="part.part_type" 
                                          class="badge shadow-xs" 
                                          :class="{
                                            'bg-primary text-white': part.part_type === 'MFG',
                                            'bg-warning text-dark': part.part_type === 'BOP',
                                            'bg-teal text-white': part.part_type === 'STD'
                                          }"
                                          style="font-size: 0.62rem; padding: 1px 4px;"
                                        >
                                          {{ part.part_type }}
                                        </span>
                                        <span class="fw-bold text-dark" style="font-size: 0.78rem;">{{ part.standard_part_no }}</span>
                                      </div>
                                    </td>
                                    <td class="text-start text-muted extra-small py-1 px-1.5">{{ part.item_no || '—' }}</td>
                                    <td class="text-start text-muted extra-small py-1 px-1.5">{{ part.supplier || '—' }}</td>
                                    <td class="py-1 px-1.5">
                                      <span class="badge" :class="part.side === 'LH' ? 'bg-primary-subtle text-primary' : (part.side === 'RH' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-secondary-subtle text-secondary')" style="font-size: 0.65rem; padding: 1px 4px;">
                                        {{ part.side }}
                                      </span>
                                    </td>
                                    <td class="fw-bold text-dark py-1 px-1.5">{{ part.required_qty }}</td>
                                    <td class="fw-bold text-success py-1 px-1.5">{{ part.received_qty }}</td>
                                    <td class="fw-bold py-1 px-1.5" :class="part.pending_qty > 0 ? 'text-danger' : 'text-muted'">{{ part.pending_qty }}</td>
                                    <td class="py-1 px-1.5">
                                      <span 
                                        class="badge px-1.5 py-0.5"
                                        :class="getStatusBadgeClass(part.status_badge)"
                                        style="font-size: 0.68rem;"
                                      >
                                        {{ part.status_badge }}
                                      </span>
                                    </td>
                                  </tr>
                                  <tr v-if="!getFilteredUnitParts(unit, `${section.key}_${jig.jig_name}_${unit.unit_no}`).length">
                                    <td colspan="9" class="text-center py-3 text-muted">No parts match the selected filter.</td>
                                  </tr>
                                </tbody>
                              </table>
                            </div>

                            <!-- Pagination -->
                            <div v-if="getUnitPartsTotalPages(unit, `${section.key}_${jig.jig_name}_${unit.unit_no}`) > 1" class="d-flex justify-content-between align-items-center mt-2">
                              <small class="text-muted extra-small">
                                Page {{ unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1 }} / {{ getUnitPartsTotalPages(unit, `${section.key}_${jig.jig_name}_${unit.unit_no}`) }}
                              </small>
                              <div class="btn-group btn-group-sm">
                                <button 
                                  class="btn btn-outline-secondary btn-xs py-0.5 px-2"
                                  :disabled="(unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1) <= 1"
                                  @click="setUnitPartPage(`${section.key}_${jig.jig_name}_${unit.unit_no}`, (unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1) - 1)"
                                >
                                  Prev
                                </button>
                                <button 
                                  class="btn btn-outline-secondary btn-xs py-0.5 px-2"
                                  :disabled="(unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1) >= getUnitPartsTotalPages(unit, `${section.key}_${jig.jig_name}_${unit.unit_no}`)"
                                  @click="setUnitPartPage(`${section.key}_${jig.jig_name}_${unit.unit_no}`, (unitPartPage[`${section.key}_${jig.jig_name}_${unit.unit_no}`] || 1) + 1)"
                                >
                                  Next
                                </button>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- END OF OPTION B (5-Level Hierarchy Drill-Down) -->


    <!-- ========================================================================= -->
    <!-- KPI DRILL-DOWN DETAILED PARTS MODAL (Excel Export + Canonical PostgreSQL) -->
    <!-- ========================================================================= -->
    <div v-if="showKpiDrilldownModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(15, 23, 42, 0.65); z-index: 1060; backdrop-filter: blur(2px);">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width: 95vw; width: 1250px;">
        <div class="modal-content shadow-lg border-0">
          <!-- Modal Header -->
          <div class="modal-header bg-dark text-white py-3 px-4 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <div class="p-2 bg-white bg-opacity-10 rounded-3">
                <i class="fas fa-layer-group text-primary fs-5"></i>
              </div>
              <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <h5 class="modal-title fw-bold mb-0 text-white">{{ selectedKpiTitle }}</h5>
                  <span v-if="selectedKpiPartType" class="badge px-2.5 py-1 fs-7 shadow-xs" :class="selectedKpiPartType === 'MFG' ? 'bg-primary' : (selectedKpiPartType === 'BOP' ? 'bg-warning text-dark' : 'bg-teal text-white')">
                    {{ selectedKpiPartType === 'MFG' ? 'Manufacturing (MFG)' : (selectedKpiPartType === 'BOP' ? 'Bought Out Parts (BOP)' : 'Standard (STD)') }}
                  </span>
                  <span class="badge bg-primary px-2.5 py-1 fs-7">{{ kpiDrilldownResult.project_scope || 'All Active Projects' }}</span>
                  <span v-if="kpiDrilldownResult.total_quantity !== undefined" class="badge bg-success px-2.5 py-1 fs-7">
                    Total Quantity: {{ kpiDrilldownResult.total_quantity }} pcs
                  </span>
                </div>
                <small class="text-white-50">
                  Detailed parts list and exact contributing quantities &bull; Canonical PostgreSQL breakdown
                </small>
              </div>
            </div>
            <button type="button" class="btn-close btn-close-white" @click="showKpiDrilldownModal = false" aria-label="Close"></button>
          </div>

          <!-- Modal Sub-Toolbar (Substates, Search, Filters, Excel Export) -->
          <div class="modal-body p-3 bg-light border-bottom">
            <div class="row g-2 align-items-center justify-content-between">
              <!-- Left: Substate Selector Tabs (For QC and Assembly) -->
              <div class="col-12 col-md-auto d-flex align-items-center gap-1.5 flex-wrap">
                <!-- QC Substates -->
                <div v-if="selectedKpiKey === 'qc'" class="btn-group btn-group-sm shadow-xs" role="group">
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'all' ? 'btn-primary' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('all')">
                    All QC
                  </button>
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'inspection' ? 'btn-info text-dark' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('inspection')">
                    <i class="fas fa-clipboard-check me-1"></i> Inspection
                  </button>
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'rejected' ? 'btn-danger' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('rejected')">
                    <i class="fas fa-ban me-1"></i> Rejected
                  </button>
                </div>

                <!-- Assembly Substates -->
                <div v-if="selectedKpiKey === 'assembly'" class="btn-group btn-group-sm shadow-xs" role="group">
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'all' ? 'btn-primary' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('all')">
                    All Assembly
                  </button>
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'queue' ? 'btn-primary' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('queue')">
                    <i class="fas fa-cogs me-1"></i> Assembly Queue
                  </button>
                  <button 
                    type="button" 
                    class="btn fw-semibold"
                    :class="kpiDrilldownSubstate === 'completed' ? 'btn-success' : 'btn-outline-secondary bg-white'"
                    @click="setKpiSubstate('completed')">
                    <i class="fas fa-check-double me-1"></i> Completed
                  </button>
                </div>

                <!-- Side Filter for Drill-Down -->
                <div class="d-flex align-items-center gap-1">
                  <select v-model="kpiDrilldownSide" @change="fetchKpiDrilldown" class="form-select form-select-sm shadow-xs" style="width: 140px;">
                    <option value="">All Sides</option>
                    <option value="RH">RH Only</option>
                    <option value="LH">LH Only</option>
                    <option value="COMMON">COMMON Only</option>
                  </select>
                </div>
              </div>

              <!-- Right: Fast Search & Excel Export Button -->
              <div class="col-12 col-md d-flex align-items-center justify-content-md-end gap-2 flex-wrap">
                <div class="input-group input-group-sm shadow-xs" style="max-width: 320px;">
                  <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                  <input 
                    type="text" 
                    v-model="kpiDrilldownSearch" 
                    @input="onSearchInput" 
                    class="form-control border-start-0 ps-0" 
                    placeholder="Search Jig, Unit, Part No, Project..." 
                  />
                  <button v-if="kpiDrilldownSearch" class="btn btn-outline-secondary bg-white border-start-0" @click="kpiDrilldownSearch = ''; fetchKpiDrilldown();">
                    <i class="fas fa-times"></i>
                  </button>
                </div>

                <!-- Excel Export Button -->
                <button 
                  @click="exportKpiExcel" 
                  class="btn btn-success btn-sm fw-bold shadow-xs text-nowrap" 
                  :disabled="kpiExportLoading || kpiDrilldownLoading">
                  <i class="fas fa-file-excel me-1.5" :class="{ 'fa-spin': kpiExportLoading }"></i>
                  {{ kpiExportLoading ? 'Exporting...' : 'Export Excel (.xlsx)' }}
                </button>
              </div>
            </div>
          </div>

          <!-- Modal Body Table (Sticky header, high density, server-side pagination) -->
          <div class="modal-body p-0" style="min-height: 360px; max-height: 60vh; overflow-y: auto;">
            <div v-if="kpiDrilldownLoading" class="text-center py-5">
              <div class="spinner-border text-primary mb-2" role="status"></div>
              <p class="text-muted small mb-0">Loading canonical drill-down records from database...</p>
            </div>

            <div v-else class="table-responsive">
              <!-- Part-level Table -->
              <table v-if="kpiDrilldownResult.kpi_type === 'part'" class="table table-hover align-middle mb-0 small">
                <thead class="table-dark sticky-top" style="z-index: 1;">
                  <tr>
                    <th style="width: 10%;">PROJECT</th>
                    <th style="width: 7%; text-align: center;">TYPE</th>
                    <th style="width: 10%;">JIG NO</th>
                    <th style="width: 7%; text-align: center;">UNIT NO</th>
                    <th style="width: 14%;">PART NO</th>
                    <th style="width: 6%; text-align: center;">SIDE</th>
                    <th style="width: 21%;">COMBINED IDENTIFIER</th>
                    <th style="width: 14%;">STATUS</th>
                    <th style="width: 11%; text-align: center;">QUANTITY</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in kpiDrilldownResult.data" :key="row.id">
                    <td>
                      <span class="badge bg-light text-dark border">{{ row.project_code }}</span>
                    </td>
                    <td class="text-center">
                      <span 
                        class="badge shadow-xs"
                        :class="{
                          'bg-primary text-white': (row.part_type || 'MFG') === 'MFG',
                          'bg-warning text-dark': row.part_type === 'BOP',
                          'bg-teal text-white': row.part_type === 'STD'
                        }"
                        style="font-size: 0.68rem;"
                      >
                        {{ row.part_type || 'MFG' }}
                      </span>
                    </td>
                    <td class="fw-bold text-dark">{{ row.jig_no }}</td>
                    <td class="text-center"><span class="badge bg-secondary-subtle text-dark">{{ row.unit_no }}</span></td>
                    <td>
                      <span class="fw-bold text-primary">{{ row.part_no }}</span>
                      <small v-if="row.supplier && row.supplier !== 'Standard'" class="text-muted d-block extra-small">{{ row.supplier }}</small>
                    </td>
                    <td class="text-center">
                      <span :class="['badge', ['RH', 'RA', 'AR', 'R'].includes(row.side) ? 'badge-rh' : ['LH', 'LA', 'AL', 'L'].includes(row.side) ? 'badge-lh' : 'badge-common']">
                        {{ row.side }}
                      </span>
                    </td>
                    <td>
                      <code class="text-dark bg-light px-1.5 py-0.5 rounded border small">{{ row.combined_identifier }}</code>
                    </td>
                    <td>
                      <span 
                        class="badge fw-semibold"
                        :class="getDrilldownStatusBadgeClass(row.status, row.substate)"
                      >
                        {{ row.status }}
                      </span>
                    </td>
                    <td class="text-center">
                      <span class="badge bg-dark px-2 py-1 fs-6 fw-bold">{{ row.quantity }}</span>
                    </td>
                  </tr>
                  <tr v-if="!kpiDrilldownResult.data || !kpiDrilldownResult.data.length">
                    <td colspan="9" class="text-center py-5 text-muted">
                      <i class="fas fa-inbox fa-3x mb-2 text-secondary opacity-50 d-block"></i>
                      No parts found contributing to this KPI for the selected filters.
                    </td>
                  </tr>
                </tbody>
              </table>

              <!-- Project-level Table (for Active, Completed, Delayed Projects) -->
              <table v-else class="table table-hover align-middle mb-0 small">
                <thead class="table-dark sticky-top" style="z-index: 1;">
                  <tr>
                    <th style="width: 15%;">PROJECT CODE</th>
                    <th style="width: 25%;">PROJECT NAME</th>
                    <th style="width: 12%; text-align: center;">TOTAL PARTS</th>
                    <th style="width: 12%; text-align: center;">TOTAL RECEIVED</th>
                    <th style="width: 12%; text-align: center;">PARTS PENDING</th>
                    <th style="width: 12%; text-align: center;">COMPLETION %</th>
                    <th style="width: 12%; text-align: center;">STATUS</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="proj in kpiDrilldownResult.data" :key="proj.id">
                    <td><span class="badge bg-primary text-white">{{ proj.project_code }}</span></td>
                    <td class="fw-bold text-dark">{{ proj.project_name }}</td>
                    <td class="text-center fw-bold">{{ proj.total_parts }}</td>
                    <td class="text-center text-success fw-bold">{{ proj.total_parts_received }}</td>
                    <td class="text-center text-danger fw-bold">{{ proj.parts_pending }}</td>
                    <td class="text-center">
                      <div class="d-flex align-items-center justify-content-center gap-2">
                        <div class="progress flex-grow-1" style="height: 6px; max-width: 60px;">
                          <div class="progress-bar bg-success" :style="{ width: `${proj.completion_pct}%` }"></div>
                        </div>
                        <span class="fw-bold extra-small">{{ proj.completion_pct }}%</span>
                      </div>
                    </td>
                    <td class="text-center">
                      <span class="badge" :class="proj.status.includes('Delayed') ? 'bg-danger' : proj.status === 'Completed' ? 'bg-success' : 'bg-info text-dark'">
                        {{ proj.status }}
                      </span>
                    </td>
                  </tr>
                  <tr v-if="!kpiDrilldownResult.data || !kpiDrilldownResult.data.length">
                    <td colspan="7" class="text-center py-5 text-muted">
                      No project records found contributing to this KPI.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Modal Footer with Server-Side Pagination & Summary -->
          <div class="modal-footer bg-light py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted d-flex align-items-center gap-2">
              <span>Showing {{ kpiDrilldownResult.total_records > 0 ? (kpiDrilldownResult.page - 1) * kpiDrilldownResult.per_page + 1 : 0 }} to {{ Math.min(kpiDrilldownResult.page * kpiDrilldownResult.per_page, kpiDrilldownResult.total_records) }} of <strong>{{ kpiDrilldownResult.total_records }}</strong> total records</span>
              <span class="badge bg-secondary-subtle text-dark border">Sum: <strong>{{ kpiDrilldownResult.total_quantity }}</strong> pcs</span>
            </div>

            <!-- Pagination Buttons -->
            <div class="d-flex align-items-center gap-2">
              <select v-model="kpiDrilldownPerPage" @change="kpiDrilldownPage = 1; fetchKpiDrilldown();" class="form-select form-select-sm" style="width: 100px;">
                <option :value="25">25 / page</option>
                <option :value="50">50 / page</option>
                <option :value="100">100 / page</option>
              </select>

              <div class="btn-group btn-group-sm">
                <button 
                  class="btn btn-outline-secondary" 
                  :disabled="kpiDrilldownPage <= 1 || kpiDrilldownLoading"
                  @click="kpiDrilldownPage--; fetchKpiDrilldown();">
                  <i class="fas fa-chevron-left me-1"></i> Prev
                </button>
                <span class="btn btn-outline-secondary disabled bg-white text-dark fw-bold">
                  {{ kpiDrilldownPage }} / {{ kpiDrilldownResult.total_pages || 1 }}
                </span>
                <button 
                  class="btn btn-outline-secondary" 
                  :disabled="kpiDrilldownPage >= kpiDrilldownResult.total_pages || kpiDrilldownLoading"
                  @click="kpiDrilldownPage++; fetchKpiDrilldown();">
                  Next <i class="fas fa-chevron-right ms-1"></i>
                </button>
              </div>

              <button type="button" class="btn btn-secondary btn-sm" @click="showKpiDrilldownModal = false">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- JIG SUPPLIER VISIBILITY POPUP MODAL (Admin / Manager / Purchase) -->
    <div v-if="showJigSupplierModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(15, 23, 42, 0.6); z-index: 1060;" @click.self="showJigSupplierModal = false">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-dark text-white py-3">
            <div class="d-flex align-items-center gap-2">
              <i class="fas fa-truck text-warning fs-5"></i>
              <div>
                <h5 class="modal-title fw-bold mb-0">JIG {{ selectedJigSupplierData?.jig_no }} &mdash; Supplier Visibility</h5>
                <small class="text-white-50">Active supplier assignments allocated across units and categories</small>
              </div>
            </div>
            <button type="button" class="btn-close btn-close-white" @click="showJigSupplierModal = false"></button>
          </div>

          <div class="modal-body p-3">
            <div v-if="jigSupplierLoading" class="text-center py-5">
              <div class="spinner-border text-primary mb-2" role="status"></div>
              <p class="text-muted small mb-0">Loading Jig Supplier Assignments...</p>
            </div>

            <div v-else>
              <!-- Unique Suppliers Summary Chips -->
              <div class="mb-3 p-2.5 bg-light rounded border">
                <span class="extra-small text-uppercase fw-bold text-muted d-block mb-1.5">Associated Suppliers for this Jig:</span>
                <div class="d-flex flex-wrap gap-2">
                  <span 
                    v-for="supp in selectedJigSupplierData?.unique_suppliers" 
                    :key="supp.supplier_id"
                    class="badge bg-white text-dark border px-2.5 py-1.5 d-inline-flex align-items-center gap-1 shadow-xs"
                  >
                    <i class="fas fa-industry text-primary"></i>
                    <strong class="text-dark">{{ supp.supplier_name }}</strong>
                    <span class="badge bg-light text-muted border ms-1">{{ supp.supplier_code || 'SUP' }}</span>
                  </span>
                  <span v-if="!selectedJigSupplierData?.unique_suppliers?.length" class="text-muted small">
                    No suppliers allocated to this Jig yet.
                  </span>
                </div>
              </div>

              <!-- Detailed Assignments Table -->
              <div class="table-responsive bg-white rounded border">
                <table class="table table-hover table-sm align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Unit</th>
                      <th>Category</th>
                      <th>Assigned Supplier</th>
                      <th>Target Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="assign in selectedJigSupplierData?.assignments" :key="assign.id">
                      <td><strong class="text-dark">{{ assign.unit_no }}</strong></td>
                      <td>
                        <span class="badge" :class="assign.category === 'BASE' ? 'bg-success' : (assign.category === 'WELDMENT' ? 'bg-info text-dark' : 'bg-warning text-dark')">
                          {{ assign.category }}
                        </span>
                      </td>
                      <td><strong class="text-primary">{{ assign.supplier_name }}</strong></td>
                      <td>
                        <span class="badge bg-light text-dark border">
                          <i class="fas fa-calendar-day me-1 text-muted"></i>{{ assign.assignment_date || '—' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!selectedJigSupplierData?.assignments?.length">
                      <td colspan="4" class="text-center py-4 text-muted">
                        <i class="fas fa-info-circle me-1 text-info"></i>
                        No supplier allocations have been made for JIG {{ selectedJigSupplierData?.jig_no }} yet.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="modal-footer bg-light py-2">
            <button type="button" class="btn btn-secondary btn-sm" @click="showJigSupplierModal = false">Close</button>
          </div>
        </div>
      </div>
    </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useAppCacheStore } from '@/stores/cache';
import axios from 'axios';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const router = useRouter();
const authStore = useAuthStore();
const cacheStore = useAppCacheStore();

const metrics = ref({});
const statusDistribution = ref({});
const projectsProgress = ref([]);

// --- BOM TYPE VIEW & HIERARCHY STATE ---
const activeBomTypeTab = ref('ALL'); // 'ALL' | 'MFG' | 'BOP' | 'STD'
const activeHierarchyBomType = ref('ALL'); // 'ALL' | 'MFG' | 'BOP' | 'STD'
const selectedKpiPartType = ref(''); // '' | 'MFG' | 'BOP' | 'STD'

// --- JIG SUPPLIER MODAL STATE & HANDLERS ---
const showJigSupplierModal = ref(false);
const jigSupplierLoading = ref(false);
const selectedJigSupplierData = ref(null);

const openJigSupplierModal = async (jig) => {
  if (!filters.value.project_id) return;
  showJigSupplierModal.value = true;
  jigSupplierLoading.value = true;
  selectedJigSupplierData.value = {
    jig_no: jig.jig_name,
    unique_suppliers: [],
    assignments: [],
  };

  try {
    const res = await axios.get('/api/v1/dashboard/jig-suppliers', {
      params: {
        project_id: filters.value.project_id,
        jig_no: jig.jig_name,
      },
    });
    selectedJigSupplierData.value = res.data.data;
  } catch (err) {
    console.error('Failed to load jig suppliers:', err);
  } finally {
    jigSupplierLoading.value = false;
  }
};

// --- KPI DRILL-DOWN MODAL STATE & HANDLERS ---
const showKpiDrilldownModal = ref(false);
const kpiDrilldownLoading = ref(false);
const kpiExportLoading = ref(false);
const selectedKpiKey = ref('total_parts');
const selectedKpiTitle = ref('Total Parts');
const kpiDrilldownSubstate = ref('all');
const kpiDrilldownSearch = ref('');
const kpiDrilldownSide = ref('');
const kpiDrilldownPage = ref(1);
const kpiDrilldownPerPage = ref(50);
const kpiDrilldownResult = ref({
  kpi: '',
  kpi_type: 'part',
  project_scope: '',
  is_single_project: false,
  selected_project: null,
  substate: 'all',
  total_records: 0,
  total_quantity: 0,
  page: 1,
  per_page: 50,
  total_pages: 1,
  columns: [],
  data: [],
});

let searchDebounceTimer = null;
const onSearchInput = () => {
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => {
    kpiDrilldownPage.value = 1;
    fetchKpiDrilldown();
  }, 300);
};

const openKpiDrilldown = (kpiKey, title, defaultSubstate = 'all', partType = '') => {
  selectedKpiKey.value = kpiKey;
  selectedKpiTitle.value = title;
  selectedKpiPartType.value = partType;
  kpiDrilldownSubstate.value = defaultSubstate;
  kpiDrilldownSearch.value = '';
  kpiDrilldownSide.value = filters.value.side || '';
  kpiDrilldownPage.value = 1;
  kpiDrilldownPerPage.value = 50;
  showKpiDrilldownModal.value = true;
  fetchKpiDrilldown();
};

const setKpiSubstate = (sub) => {
  kpiDrilldownSubstate.value = sub;
  kpiDrilldownPage.value = 1;
  fetchKpiDrilldown();
};

const fetchKpiDrilldown = async () => {
  kpiDrilldownLoading.value = true;
  try {
    const params = new URLSearchParams();
    params.append('kpi', selectedKpiKey.value);
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
    if (selectedKpiPartType.value) params.append('part_type', selectedKpiPartType.value);
    if (kpiDrilldownSide.value) params.append('side', kpiDrilldownSide.value);
    if (kpiDrilldownSubstate.value && kpiDrilldownSubstate.value !== 'all') {
      params.append('substate', kpiDrilldownSubstate.value);
    }
    if (kpiDrilldownSearch.value) params.append('search', kpiDrilldownSearch.value);
    if (filters.value.date_from) params.append('date_from', filters.value.date_from);
    if (filters.value.date_to) params.append('date_to', filters.value.date_to);
    params.append('page', kpiDrilldownPage.value);
    params.append('per_page', kpiDrilldownPerPage.value);

    const res = await axios.get(`/api/v1/dashboard/kpi-drilldown?${params.toString()}`);
    kpiDrilldownResult.value = res.data;
  } catch (err) {
    console.error('Failed to load KPI drilldown:', err);
  } finally {
    kpiDrilldownLoading.value = false;
  }
};

const getDrilldownStatusBadgeClass = (status, substate) => {
  const s = String(status || '').toUpperCase();
  const sub = String(substate || '').toUpperCase();

  // Completed / Assembled
  if (s.includes('COMPLET') || sub.includes('COMPLET') || s.includes('ASSEMBLED')) {
    return 'bg-success text-white';
  }
  // Rejected
  if (s.includes('REJECT') || sub.includes('REJECT')) {
    return 'bg-danger text-white';
  }
  // Rework
  if (s.includes('REWORK') || sub.includes('REWORK')) {
    return 'bg-warning text-dark';
  }
  // Paint
  if (s.includes('PAINT') || sub.includes('PAINT')) {
    return 'bg-purple text-white';
  }
  // Assembly
  if (s.includes('ASSEMBLY') || sub.includes('ASSEMBLY')) {
    return 'bg-pink text-white';
  }
  // QC / Inspection
  if (s.includes('QC') || s.includes('INSPECT') || sub.includes('QC')) {
    return 'bg-info text-dark';
  }
  // Store / Received
  if (s.includes('STORE') || s.includes('RECEIVED') || sub.includes('STORE')) {
    return 'bg-primary text-white';
  }
  // Pending
  if (s.includes('PENDING')) {
    return 'bg-secondary text-white';
  }
  if (s.includes('BOM REQUIRED') || s === 'REQUIRED') {
    return 'bg-primary text-white';
  }
  
  return 'bg-dark text-white';
};

const exportKpiExcel = async () => {
  kpiExportLoading.value = true;
  try {
    const params = new URLSearchParams();
    params.append('kpi', selectedKpiKey.value);
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
    if (selectedKpiPartType.value) params.append('part_type', selectedKpiPartType.value);
    if (kpiDrilldownSide.value) params.append('side', kpiDrilldownSide.value);
    if (kpiDrilldownSubstate.value && kpiDrilldownSubstate.value !== 'all') {
      params.append('substate', kpiDrilldownSubstate.value);
    }
    if (kpiDrilldownSearch.value) params.append('search', kpiDrilldownSearch.value);
    if (filters.value.date_from) params.append('date_from', filters.value.date_from);
    if (filters.value.date_to) params.append('date_to', filters.value.date_to);

    const response = await axios.get(`/api/v1/dashboard/kpi-drilldown/export?${params.toString()}`, {
      responseType: 'blob',
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    const contentDisposition = response.headers['content-disposition'];
    let filename = `SpareTrack_${selectedKpiKey.value}_${new Date().toISOString().slice(0, 10)}.xlsx`;
    if (contentDisposition) {
      const match = contentDisposition.match(/filename="?([^"]+)"?/);
      if (match && match[1]) filename = match[1];
    }
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    console.error('Failed to export KPI Excel:', err);
    alert('Could not generate Excel export.');
  } finally {
    kpiExportLoading.value = false;
  }
};

const loading = ref(false);

const filters = ref({
  project_id: '',
  side: '',
  date_from: '',
  date_to: '',
  part_type: '',
});

// Phase 2 Hierarchy State
const hierarchyData = ref({ jigs: [], project: null, canonical_summary: null, active_projects: [], completed_projects: [] });
const activeProjectsList = ref([]);
const completedProjectsList = ref([]);
const expandedJigs = ref({});
const expandedUnits = ref({});
const unitPartSearch = ref({});
const unitSideTab = ref({});
const unitPartPage = ref({});
const hierarchyLoading = ref(false);

const collapsedSections = ref({
  MFG: false,
  BOP: false,
  STD: false,
});

const toggleSectionCollapse = (key) => {
  collapsedSections.value[key] = !collapsedSections.value[key];
};

const displayedHierarchySections = computed(() => {
  const mfgJigs = hierarchyData.value.mfg_section?.jigs ?? (activeHierarchyBomType.value === 'MFG' ? (hierarchyData.value.jigs || []) : []);
  const mfgComp = hierarchyData.value.mfg_section?.completed_jigs ?? (activeHierarchyBomType.value === 'MFG' ? (hierarchyData.value.completed_jigs || 0) : 0);

  const bopJigs = hierarchyData.value.bop_section?.jigs ?? (activeHierarchyBomType.value === 'BOP' ? (hierarchyData.value.jigs || []) : []);
  const bopComp = hierarchyData.value.bop_section?.completed_jigs ?? (activeHierarchyBomType.value === 'BOP' ? (hierarchyData.value.completed_jigs || 0) : 0);

  const stdJigs = hierarchyData.value.std_section?.jigs ?? (activeHierarchyBomType.value === 'STD' ? (hierarchyData.value.jigs || []) : []);
  const stdComp = hierarchyData.value.std_section?.completed_jigs ?? (activeHierarchyBomType.value === 'STD' ? (hierarchyData.value.completed_jigs || 0) : 0);

  if (activeHierarchyBomType.value === 'ALL') {
    return [
      {
        key: 'MFG',
        title: 'Manufacturing',
        badge: 'MFG',
        badgeClass: 'bg-primary text-white',
        accentColor: '#2563eb',
        headerBg: '#eff6ff',
        jigs: mfgJigs,
        completed: mfgComp,
      },
      {
        key: 'BOP',
        title: 'BOP',
        badge: 'BOP',
        badgeClass: 'bg-warning text-dark',
        accentColor: '#d97706',
        headerBg: '#fffbeb',
        jigs: bopJigs,
        completed: bopComp,
      },
      {
        key: 'STD',
        title: 'Standard',
        badge: 'STD',
        badgeClass: 'bg-teal text-white',
        accentColor: '#0d9488',
        headerBg: '#f0fdfa',
        jigs: stdJigs,
        completed: stdComp,
      },
    ];
  } else if (activeHierarchyBomType.value === 'MFG') {
    return [
      {
        key: 'MFG',
        title: 'Manufacturing',
        badge: 'MFG',
        badgeClass: 'bg-primary text-white',
        accentColor: '#2563eb',
        headerBg: '#eff6ff',
        jigs: mfgJigs,
        completed: mfgComp,
      }
    ];
  } else if (activeHierarchyBomType.value === 'BOP') {
    return [
      {
        key: 'BOP',
        title: 'BOP',
        badge: 'BOP',
        badgeClass: 'bg-warning text-dark',
        accentColor: '#d97706',
        headerBg: '#fffbeb',
        jigs: bopJigs,
        completed: bopComp,
      }
    ];
  } else if (activeHierarchyBomType.value === 'STD') {
    return [
      {
        key: 'STD',
        title: 'Standard',
        badge: 'STD',
        badgeClass: 'bg-teal text-white',
        accentColor: '#0d9488',
        headerBg: '#f0fdfa',
        jigs: stdJigs,
        completed: stdComp,
      }
    ];
  }
  return [];
});

const hasAnyHierarchyJigs = computed(() => {
  return displayedHierarchySections.value.some(s => s.jigs && s.jigs.length > 0);
});

// Chart canvas refs & instances
const topProjectsChartCanvas = ref(null);
const healthChartCanvas = ref(null);

const topProjectsData = ref({ labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 });
const healthDistribution = ref({ counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} });

let topProjectsChart = null;
let healthChart = null;

const setBomViewType = (type) => {
  activeBomTypeTab.value = type;
  activeHierarchyBomType.value = type;
  filters.value.part_type = (type === 'ALL' ? '' : type);
  if (type !== 'ALL') {
    collapsedSections.value[type] = false;
  }
  fetchData(true);
};

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
    part_type: '',
  };
  activeBomTypeTab.value = 'ALL';
  activeHierarchyBomType.value = 'ALL';
  expandedJigs.value = {};
  expandedUnits.value = {};
  unitPartSearch.value = {};
  unitSideTab.value = {};
  unitPartPage.value = {};
  fetchData(true);
};

const setHierarchyBomType = (type) => {
  activeHierarchyBomType.value = type;
  unitSideTab.value = {};
  unitPartSearch.value = {};
  unitPartPage.value = {};
  if (type !== 'ALL') {
    collapsedSections.value[type] = false;
  }
  fetchProjectHierarchy(true);
};

const fetchProjectHierarchy = async (forceFresh = false) => {
  if (!filters.value.project_id) {
    hierarchyData.value = { jigs: [], project: null, canonical_summary: null };
    return;
  }
  const params = new URLSearchParams({
    project_id: filters.value.project_id,
    side: filters.value.side || '',
  });
  if (activeHierarchyBomType.value && activeHierarchyBomType.value !== 'ALL') {
    params.append('part_type', activeHierarchyBomType.value);
  }
  const cacheKey = `project_hierarchy_${params.toString()}`;

  const applyHierarchy = (data) => {
    hierarchyData.value = data || {};
    if (data.active_projects?.length) activeProjectsList.value = data.active_projects;
    if (data.completed_projects?.length) completedProjectsList.value = data.completed_projects;

    // In single-type mode (MFG, BOP, STD), auto-expand jigs and units so parts are immediately visible
    if (activeHierarchyBomType.value !== 'ALL') {
      nextTick(() => {
        displayedHierarchySections.value.forEach(sec => {
          (sec.jigs || []).forEach(j => {
            expandedJigs.value[`${sec.key}_${j.jig_name}`] = true;
            (j.units || []).forEach(u => {
              expandedUnits.value[`${sec.key}_${j.jig_name}_${u.unit_no}`] = true;
            });
          });
        });
      });
    }
  };

  const cached = cacheStore.get(cacheKey);
  if (cached && !forceFresh) {
    applyHierarchy(cached.data);
    hierarchyLoading.value = false;
  } else {
    hierarchyLoading.value = true;
  }

  try {
    const res = await axios.get(`/api/v1/dashboard/project-hierarchy?${params.toString()}`);
    cacheStore.set(cacheKey, res.data, 60000);
    applyHierarchy(res.data);
  } catch (err) {
    console.error('Failed to load project hierarchy:', err);
  } finally {
    hierarchyLoading.value = false;
  }
};

const fetchInitialProjectsList = async () => {
  try {
    const res = await axios.get('/api/v1/dashboard/project-hierarchy');
    if (res.data.active_projects?.length) activeProjectsList.value = res.data.active_projects;
    if (res.data.completed_projects?.length) completedProjectsList.value = res.data.completed_projects;
  } catch (e) {
    console.warn('Could not fetch initial projects list:', e);
  }
};

const onProjectFilterChange = () => {
  activeHierarchyBomType.value = activeBomTypeTab.value;
  expandedJigs.value = {};
  expandedUnits.value = {};
  unitPartSearch.value = {};
  unitSideTab.value = {};
  unitPartPage.value = {};
  fetchData(true);
};

const toggleJigExpand = (sectionKey, jigName) => {
  const k = `${sectionKey}_${jigName}`;
  expandedJigs.value[k] = !expandedJigs.value[k];
};

const expandAllJigs = () => {
  displayedHierarchySections.value.forEach(sec => {
    collapsedSections.value[sec.key] = false;
    (sec.jigs || []).forEach(j => {
      expandedJigs.value[`${sec.key}_${j.jig_name}`] = true;
      (j.units || []).forEach(u => {
        expandedUnits.value[`${sec.key}_${j.jig_name}_${u.unit_no}`] = true;
      });
    });
  });
};

const collapseAllJigs = () => {
  expandedJigs.value = {};
  expandedUnits.value = {};
};

const toggleUnitExpand = (unitKey) => {
  expandedUnits.value[unitKey] = !expandedUnits.value[unitKey];
};

const setUnitSideTab = (unitKey, tab) => {
  unitSideTab.value[unitKey] = tab;
  unitPartPage.value[unitKey] = 1;
};

const setUnitPartPage = (unitKey, page) => {
  unitPartPage.value[unitKey] = page;
};

const getFilteredUnitParts = (unit, unitKey) => {
  const tab = unitSideTab.value[unitKey] || 'ALL';
  const q = (unitPartSearch.value[unitKey] || '').toLowerCase().trim();

  let parts = [];
  const comParts = unit.sides?.COMMON?.parts || [];
  const lhParts = unit.sides?.LH?.parts || [];
  const rhParts = unit.sides?.RH?.parts || [];

  if (tab === 'LH') {
    parts = lhParts;
  } else if (tab === 'RH') {
    parts = rhParts;
  } else if (tab === 'COMMON') {
    parts = comParts;
  } else {
    // 'ALL' tab
    if (comParts.length && !lhParts.length && !rhParts.length) {
      parts = comParts;
    } else if (!comParts.length) {
      parts = [...lhParts, ...rhParts];
    } else {
      parts = [...lhParts, ...rhParts, ...comParts];
    }
  }

  // Fallback if unit.parts is provided at unit root
  if (!parts.length && Array.isArray(unit.parts)) {
    parts = unit.parts;
  }

  if (!q) return parts;

  return parts.filter(p => {
    const partNo = (p.standard_part_no || '').toLowerCase();
    const itemNo = (p.item_no || '').toLowerCase();
    const supp = (p.supplier || '').toLowerCase();
    const status = (p.status_badge || '').toLowerCase();
    return partNo.includes(q) || itemNo.includes(q) || supp.includes(q) || status.includes(q);
  });
};

const getPaginatedUnitParts = (unit, unitKey) => {
  const parts = getFilteredUnitParts(unit, unitKey);
  const page = unitPartPage.value[unitKey] || 1;
  const pageSize = 10;
  const start = (page - 1) * pageSize;
  return parts.slice(start, start + pageSize);
};

const getUnitPartsTotalPages = (unit, unitKey) => {
  const parts = getFilteredUnitParts(unit, unitKey);
  return Math.ceil(parts.length / 10) || 1;
};

const getStatusBadgeClass = (status) => {
  switch (status) {
    case 'Done':
      return 'bg-success text-white';
    case 'Assembly':
      return 'bg-pink text-white';
    case 'Paint':
      return 'bg-purple text-white';
    case 'Rework':
      return 'bg-danger text-white';
    case 'QC':
      return 'bg-info text-dark';
    case 'QC (Rejected)':
      return 'bg-danger text-white';
    case 'Store':
      return 'bg-warning text-dark';
    default:
      return 'bg-secondary text-white';
  }
};

const fetchData = async (forceFresh = false) => {
  const params = new URLSearchParams(
    Object.entries(filters.value).filter(([_, v]) => v !== '')
  ).toString();
  const cacheKey = `dashboard_summary_${params}`;

  const applyData = (data) => {
    metrics.value = data.summary || {};
    statusDistribution.value = data.status_distribution || {};
    projectsProgress.value = data.projects_progress || [];
    topProjectsData.value = data.top_projects || { labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 };
    healthDistribution.value = data.health_distribution || { counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} };

    if (!filters.value.project_id) {
      nextTick(() => {
        renderTopProjectsChart();
        renderHealthChart();
      });
    }
  };

  const cached = cacheStore.get(cacheKey);
  if (cached && !forceFresh) {
    applyData(cached.data);
    loading.value = false;
  } else {
    loading.value = true;
  }

  try {
    const sumRes = await axios.get(`/api/v1/dashboard/summary?${params}`);
    cacheStore.set(cacheKey, sumRes.data, 60000);
    applyData(sumRes.data);

    if (filters.value.project_id) {
      await fetchProjectHierarchy(forceFresh);
    }
  } catch (err) {
    console.error('Failed to load dashboard data:', err);
  } finally {
    loading.value = false;
  }
};

const renderTopProjectsChart = () => {
  try {
    if (topProjectsChart) {
      topProjectsChart.destroy();
      topProjectsChart = null;
    }
    if (topProjectsChartCanvas.value && topProjectsData.value.labels?.length) {
      const colors = (topProjectsData.value.percentages || []).map(pct => {
        if (pct >= 85) return '#10b981'; // Green
        if (pct >= 60) return '#3b82f4'; // Blue
        if (pct >= 30) return '#f59e0b'; // Amber
        return '#ef4444'; // Red
      });

      topProjectsChart = new Chart(topProjectsChartCanvas.value, {
        type: 'bar',
        data: {
          labels: topProjectsData.value.labels,
          datasets: [{
            label: 'Completion %',
            data: topProjectsData.value.percentages,
            backgroundColor: colors,
            borderRadius: 4,
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title: (items) => {
                  const idx = items[0]?.dataIndex;
                  return topProjectsData.value.names?.[idx] || items[0]?.label || '';
                },
                label: (ctx) => {
                  const idx = ctx.dataIndex;
                  const req = topProjectsData.value.required?.[idx] ?? 0;
                  const rec = topProjectsData.value.received?.[idx] ?? 0;
                  const pend = topProjectsData.value.pending?.[idx] ?? 0;
                  return [
                    ` Completion: ${ctx.raw}%`,
                    ` Required: ${req} pcs`,
                    ` Received: ${rec} pcs`,
                    ` Pending: ${pend} pcs`
                  ];
                }
              }
            }
          },
          scales: {
            x: { min: 0, max: 100, title: { display: true, text: 'Completion Percentage (%)' } }
          }
        }
      });
    }
  } catch (e) {
    console.warn('Error rendering top projects chart:', e);
  }
};

const renderHealthChart = () => {
  try {
    if (healthChart) {
      healthChart.destroy();
      healthChart = null;
    }
    if (healthChartCanvas.value) {
      const counts = healthDistribution.value.counts || {};
      const dataValues = [
        counts.near_completion || 0,
        counts.on_track || 0,
        counts.at_risk || 0,
        counts.delayed || 0,
      ];
      const hasData = dataValues.some(v => v > 0);

      healthChart = new Chart(healthChartCanvas.value, {
        type: 'doughnut',
        data: {
          labels: ['Near Completion (≥85%)', 'On Track (Active)', 'At Risk (7-14d)', 'Delayed (>14d)'],
          datasets: [{
            data: hasData ? dataValues : [1],
            backgroundColor: hasData 
              ? ['#10b981', '#3b82f4', '#f59e0b', '#ef4444'] 
              : ['#e2e8f0'],
            borderWidth: 2,
            borderColor: '#ffffff',
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: {
              position: 'right',
              labels: { boxWidth: 12, font: { size: 11 } }
            },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  if (!hasData) return ' No active projects';
                  const total = healthDistribution.value.total_active || 1;
                  const val = ctx.raw;
                  const pct = Math.round((val / total) * 100);
                  return ` ${ctx.label}: ${val} projects (${pct}%)`;
                }
              }
            }
          }
        }
      });
    }
  } catch (e) {
    console.warn('Error rendering health distribution chart:', e);
  }
};

onMounted(async () => {
  await fetchInitialProjectsList();
  fetchData();
  if (window.Echo) {
    window.Echo.channel('workflow')
      .listen('.assembly.completed', () => {
        fetchData();
      })
      .listen('.paint.completed', () => {
        fetchData();
      })
      .listen('.qc.inspected', () => {
        fetchData();
      })
      .listen('.store.received', () => {
        fetchData();
      })
      .listen('.part.reverted', () => {
        fetchData();
      });
  }
});

onUnmounted(() => {
  if (window.Echo) {
    try {
      window.Echo.leaveChannel('workflow');
    } catch (e) {}
  }
});
</script>

<style scoped>
.fs-7 {
  font-size: 0.75rem;
}
.text-purple {
  color: #7c3aed !important;
}
.bg-purple {
  background-color: #7c3aed !important;
}
.border-purple {
  border-color: #7c3aed !important;
}
.text-pink {
  color: #db2777 !important;
}
.bg-pink {
  background-color: #db2777 !important;
}
.border-pink {
  border-color: #db2777 !important;
}
.text-teal {
  color: #0d9488 !important;
}
.bg-teal {
  background-color: #0d9488 !important;
}
.bg-teal-subtle {
  background-color: #ccfbf1 !important;
}
.border-teal {
  border-color: #0d9488 !important;
}
.btn-teal {
  background-color: #0d9488 !important;
  color: #ffffff !important;
  border-color: #0d9488 !important;
}
.btn-teal:hover {
  background-color: #0f766e !important;
  color: #ffffff !important;
  border-color: #0f766e !important;
}
.border-start-4 {
  border-left-width: 4px !important;
}
.extra-small {
  font-size: 0.72rem;
}
.cursor-pointer {
  cursor: pointer;
}
.btn-xs {
  padding: 0.18rem 0.45rem;
  font-size: 0.75rem;
  line-height: 1.2;
}
.transition-all {
  transition: all 0.2s ease-in-out;
}
.kpi-card-interactive {
  cursor: pointer;
  transition: transform 0.18s ease-in-out, box-shadow 0.18s ease-in-out, filter 0.18s ease-in-out;
}
.kpi-card-interactive:hover {
  transform: translateY(-3px) scale(1.015);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18) !important;
  filter: brightness(1.06);
}
.shadow-xs {
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.badge-rh {
  background-color: #2563eb;
  color: #ffffff;
}
.badge-lh {
  background-color: #7c3aed;
  color: #ffffff;
}
.badge-common {
  background-color: #475569;
  color: #ffffff;
}

/* Option B: Three-Type Side-by-Side Comparison Layout */
.hierarchy-panels-container {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  width: 100%;
}

.hierarchy-panels-container.three-column-mode {
  flex-direction: row;
  overflow-x: auto;
  align-items: stretch;
  padding-bottom: 0.75rem;
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 #f8fafc;
}

.hierarchy-panels-container.three-column-mode::-webkit-scrollbar {
  height: 8px;
}

.hierarchy-panels-container.three-column-mode::-webkit-scrollbar-track {
  background: #f8fafc;
  border-radius: 4px;
}

.hierarchy-panels-container.three-column-mode::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

.hierarchy-panels-container.three-column-mode::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

.hierarchy-panel-column {
  width: 100%;
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.hierarchy-panels-container.three-column-mode .hierarchy-panel-column {
  flex: 1 1 0;
  min-width: 380px;
  max-width: 100%;
}

.hierarchy-panel-column.single-type-panel {
  width: 100%;
  min-width: 100%;
  flex: 0 0 auto;
}

.hierarchy-panel-column.single-type-panel .hierarchy-panel-scrollable-body {
  max-height: none;
  overflow-y: visible;
}

.hierarchy-panel-header-sticky {
  position: sticky;
  top: 0;
  z-index: 5;
  background: #ffffff;
}

.hierarchy-panel-scrollable-body {
  overflow-y: auto;
  max-height: 750px;
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 #f1f5f9;
}

.hierarchy-panel-scrollable-body::-webkit-scrollbar {
  width: 6px;
}

.hierarchy-panel-scrollable-body::-webkit-scrollbar-track {
  background: #f1f5f9;
}

.hierarchy-panel-scrollable-body::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}

.hierarchy-panel-scrollable-body::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

.fs-7 {
  font-size: 0.82rem;
}
</style>
