<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h3 class="fw-bold mb-1"><i class="fas fa-clipboard-check me-2 text-warning"></i>Quality Control Desk</h3>
              <p class="text-muted mb-0">Confirm physical arrivals, inspect parts by Project hierarchy, record decisions, and dispatch to Paint or Rework.</p>
            </div>
            <span class="badge bg-warning text-dark px-3 py-2 fs-6">Quality Control</span>
          </div>
        </div>

        <div class="card-body">
          <!-- Alerts -->
          <div v-if="error" class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
            <button type="button" class="btn-close" @click="error = ''"></button>
          </div>
          <div v-if="successMessage" class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
            <button type="button" class="btn-close" @click="successMessage = ''"></button>
          </div>

          <!-- Fast Search & Filters Bar -->
          <div class="row g-3 mb-4">
            <div class="col-md-5">
              <label class="form-label fw-semibold"><i class="fas fa-search me-1 text-primary"></i>Fast Search (Part No / Size / Supplier)</label>
              <div class="input-group">
                <input v-model="searchQuery" @input="onSearchInput" class="form-control form-control-lg shadow-xs" placeholder="Type StandardPartNo e.g. 62800-ST7..." />
                <button v-if="searchQuery" class="btn btn-outline-secondary" @click="searchQuery = ''; onSearchInput();">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold"><i class="fas fa-project-diagram me-1 text-info"></i>Project Selection</label>
              <select v-model="projectId" class="form-select form-select-lg shadow-xs" @change="onProjectChange">
                <option value="">All Projects Overview</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">
                  {{ project.name || project.project_code }} ({{ project.completion_pct }}% QC Cleared)
                </option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold"><i class="fas fa-filter me-1 text-secondary"></i>Side Filter</label>
              <select v-model="selectedSide" class="form-select form-select-lg shadow-xs" @change="loadHierarchy">
                <option value="">All Sides (RH / LH / COMMON)</option>
                <option value="RH">RH Only</option>
                <option value="LH">LH Only</option>
                <option value="COMMON">COMMON Only</option>
              </select>
            </div>
          </div>

          <!-- SEARCH RESULTS VIEW -->
          <div v-if="searchQuery">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="fw-bold mb-0 text-dark">
                <i class="fas fa-search me-2 text-primary"></i>Search Results for "{{ searchQuery }}" ({{ searchResults.length }} matching parts)
              </h5>
              <button class="btn btn-sm btn-outline-secondary" @click="searchQuery = ''; onSearchInput();">
                <i class="fas fa-arrow-left me-1"></i> Back to Hierarchy View
              </button>
            </div>

            <div class="table-responsive bg-white rounded border shadow-xs">
              <table class="table table-hover align-middle border-top mb-0">
                <thead class="table-dark">
                  <tr>
                    <th>Standard Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Side</th>
                    <th>Arrival Status</th>
                    <th>Received / Approved / Rework / Rejected</th>
                    <th style="width: 200px;">QC Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="part in searchResults" :key="part.id">
                    <td>
                      <div class="fw-bold text-primary fs-6">{{ part.standard_part_no }}</div>
                      <small class="text-muted" v-if="part.size">Size: {{ part.size }}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ part.project?.name || part.project?.project_code || 'N/A' }}</span></td>
                    <td>{{ part.supplier?.name || part.supplier_name_raw || 'Standard' }}</td>
                    <td>
                      <span v-for="(stats, sKey) in part.side_stats" :key="sKey" class="badge me-1" :class="sKey === 'RH' ? 'badge-rh' : (sKey === 'LH' ? 'badge-lh' : 'badge-common')">
                        {{ sKey }}
                      </span>
                    </td>
                    <td>
                      <span v-if="part.metrics.qc_pending_arrival > 0" class="badge bg-warning text-dark">
                        <i class="fas fa-truck me-1"></i>{{ part.metrics.qc_pending_arrival }} pcs Awaiting Physical Check
                      </span>
                      <span v-else-if="part.metrics.qc_pending_inspection > 0" class="badge bg-info text-dark">
                        <i class="fas fa-microscope me-1"></i>{{ part.metrics.qc_pending_inspection }} pcs In QC Bay
                      </span>
                      <span v-else-if="part.metrics.qc_approved > 0" class="badge bg-success">
                        <i class="fas fa-check-circle me-1"></i>QC Approved
                      </span>
                      <span v-else class="text-muted small">No QC Load</span>
                    </td>
                    <td>
                      <div class="small">
                        <span class="badge bg-primary me-1">Rec: {{ part.metrics.total_received }}</span>
                        <span class="badge bg-success me-1">App: {{ part.metrics.qc_approved }}</span>
                        <span class="badge bg-warning text-dark me-1" v-if="part.metrics.qc_rework > 0">Rew: {{ part.metrics.qc_rework }}</span>
                        <span class="badge bg-danger" v-if="part.metrics.qc_rejected > 0">Rej: {{ part.metrics.qc_rejected }}</span>
                      </div>
                    </td>
                    <td>
                      <div v-if="authStore.userRole === 'ADMIN'" class="d-flex flex-column gap-1">
                        <!-- If awaiting physical arrival -->
                        <button v-if="part.metrics.qc_pending_arrival > 0" class="btn btn-sm btn-primary fw-bold text-nowrap" @click="openArrivalModal(part)">
                          <i class="fas fa-check-double me-1"></i> Store Receive Item
                        </button>
                        <!-- If in QC bay awaiting inspection -->
                        <button v-if="part.metrics.qc_pending_inspection > 0" class="btn btn-sm btn-warning text-dark fw-bold text-nowrap" @click="openInspectModalFromPart(part)">
                          <i class="fas fa-microscope me-1"></i> Inspect Part
                        </button>
                      </div>
                      <div v-else class="text-muted extra-small text-center">
                        <span class="badge bg-light text-muted border">Monitoring Only</span>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!searchResults.length">
                    <td colspan="7" class="text-center py-5 text-muted">
                      <i class="fas fa-search fa-3x mb-3 text-secondary"></i>
                      <p class="mb-0 fs-6">No matching QC parts found for "{{ searchQuery }}".</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- LEVEL 1: Projects Overview Cards (when no project is selected) -->
          <div v-else-if="!projectId">
            <h6 class="fw-bold mb-3 text-secondary text-uppercase ls-1"><i class="fas fa-project-diagram me-2 text-primary"></i>Active Projects QC Status ({{ projects.length }})</h6>
            <div class="row g-2">
              <div v-for="proj in projects" :key="proj.id" class="col-md-6 col-lg-4">
                <div class="card h-100 border shadow-xs transition-card border-light bg-white"
                  style="cursor: pointer;"
                  @click="projectId = proj.id; onProjectChange();">
                  <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                      <h6 class="fw-bold mb-0 text-dark text-truncate me-2" style="max-width: 70%;" :title="proj.name">
                        <i class="fas fa-folder text-warning me-1.5"></i>{{ proj.name || proj.project_code || 'Project' }}
                      </h6>
                      <span class="badge" :class="proj.is_complete ? 'bg-success' : 'bg-warning text-dark'" style="font-size: 0.72rem;">
                        {{ proj.completion_pct }}% QC Pass
                      </span>
                    </div>
                    <p class="text-muted extra-small mb-2">Code: <strong>{{ proj.project_code }}</strong></p>

                    <!-- Compact Progress Bar -->
                    <div class="progress mb-2" style="height: 6px;">
                      <div class="progress-bar bg-warning" :style="{ width: proj.completion_pct + '%' }"></div>
                    </div>

                    <div class="d-flex justify-content-between text-muted extra-small border-top pt-1.5">
                      <span>Rec: <strong>{{ proj.total_received }}</strong> pcs</span>
                      <span>Approved: <strong class="text-success">{{ proj.qc_approved }}</strong> pcs</span>
                    </div>
                  </div>
                  <div class="card-footer bg-light border-0 text-end py-1.5 px-3">
                    <span class="extra-small text-primary fw-bold">Open QC Breakdown <i class="fas fa-arrow-right ms-1"></i></span>
                  </div>
                </div>
              </div>
              <div v-if="!projects.length" class="col-12 text-center py-4 text-muted">
                No active projects found.
              </div>
            </div>
          </div>

          <!-- LEVEL 2 & 3 & 4: Hierarchical JIG -> Unit -> Parts View -->
          <div v-else>
            <!-- Breadcrumbs Navigation -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 p-2.5 bg-white rounded border shadow-xs">
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                  <li class="breadcrumb-item">
                    <a href="javascript:void(0)" @click="projectId = ''; selectedJig = null; selectedUnit = null; onProjectChange();" class="text-decoration-none fw-bold text-primary">
                      <i class="fas fa-project-diagram me-1"></i> Projects
                    </a>
                  </li>
                  <li class="breadcrumb-item" :class="{ 'active fw-bold text-dark': !selectedJig }">
                    <a v-if="selectedJig" href="javascript:void(0)" @click="selectedJig = null; selectedUnit = null;" class="text-decoration-none text-primary">
                      {{ activeProject?.name || activeProject?.project_code }}
                    </a>
                    <span v-else>{{ activeProject?.name || activeProject?.project_code }}</span>
                  </li>
                  <li v-if="selectedJig" class="breadcrumb-item" :class="{ 'active fw-bold text-dark': !selectedUnit }">
                    <a v-if="selectedUnit" href="javascript:void(0)" @click="selectedUnit = null;" class="text-decoration-none text-primary">
                      JIG {{ selectedJig.jig_name }}
                    </a>
                    <span v-else>JIG {{ selectedJig.jig_name }}</span>
                  </li>
                  <li v-if="selectedUnit" class="breadcrumb-item active fw-bold text-dark" aria-current="page">
                    {{ selectedUnit.unit_no }}
                  </li>
                </ol>
              </nav>

              <button class="btn btn-xs btn-outline-secondary" @click="goBackOneLevel">
                <i class="fas fa-arrow-left me-1"></i> Back
              </button>
            </div>

            <!-- LEVEL 2: JIG Cards Grid (if no JIG selected) -->
            <div v-if="!selectedJig">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0 text-dark">
                  <i class="fas fa-cubes me-1.5 text-warning"></i>JIG Assemblies for {{ activeProject?.name }} ({{ jigs.length }} JIGs)
                </h6>
              </div>

              <div class="row g-2">
                <div v-for="jig in jigs" :key="jig.jig_name" class="col-md-6 col-lg-4">
                  <div class="card h-100 border shadow-xs transition-card border-light bg-white"
                    style="cursor: pointer;"
                    @click="selectedJig = jig; selectedUnit = null;">
                    <div class="card-body p-3">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="fw-bold mb-0 text-dark text-truncate me-2">
                          <i class="fas fa-layer-group text-warning me-1.5"></i>{{ jig.jig_name }}
                        </h6>
                        <span class="badge" :class="jig.is_complete ? 'bg-success' : 'bg-warning text-dark'" style="font-size: 0.72rem;">
                          {{ jig.completion_pct }}% QC Pass
                        </span>
                      </div>

                      <p class="text-muted extra-small mb-2">
                        <strong>{{ jig.units?.length || 0 }} Units</strong> | <strong>{{ jig.total_parts }} Parts</strong>
                      </p>

                      <div class="progress mb-2" style="height: 6px;">
                        <div class="progress-bar bg-warning" :style="{ width: jig.completion_pct + '%' }"></div>
                      </div>

                      <div class="d-flex justify-content-between text-muted extra-small border-top pt-1.5">
                        <span>Rec: <strong>{{ jig.metrics?.total_received || 0 }}</strong></span>
                        <span>Approved: <strong class="text-success">{{ jig.metrics?.qc_approved || 0 }}</strong></span>
                        <span>Pending: <strong class="text-danger">{{ (jig.metrics?.qc_pending_arrival || 0) + (jig.metrics?.qc_pending_inspection || 0) }}</strong></span>
                      </div>
                    </div>
                    <div class="card-footer bg-light border-0 text-end py-1.5 px-3">
                      <span class="extra-small text-primary fw-bold">Select Units <i class="fas fa-arrow-right ms-1"></i></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- LEVEL 3: Units List (if JIG selected but no Unit selected) -->
            <div v-else-if="selectedJig && !selectedUnit">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0 text-dark">
                  <i class="fas fa-th-large me-1.5 text-warning"></i>Units in JIG {{ selectedJig.jig_name }} ({{ selectedJig.units?.length || 0 }} Units)
                </h6>
                <button class="btn btn-xs btn-outline-secondary" @click="selectedJig = null">
                  <i class="fas fa-arrow-left me-1"></i> Back to JIGs
                </button>
              </div>

              <div class="row g-2">
                <div v-for="unit in selectedJig.units" :key="unit.unit_no" class="col-6 col-md-4 col-xl-3">
                  <div class="card h-100 compact-unit-card">
                    <!-- Row 1: Unit Header Row (Neutral) -->
                    <div class="compact-unit-header py-1.5 px-2.5 d-flex justify-content-between align-items-center">
                      <div class="d-flex align-items-center gap-1.5 text-truncate me-1">
                        <span class="unit-icon-box"><i class="fas fa-cube"></i></span>
                        <span class="unit-title-text text-truncate">{{ unit.unit_no }}</span>
                      </div>
                      <span class="badge unit-overall-badge" :class="unit.is_complete ? 'badge-unit-complete' : 'badge-unit-progress'">
                        {{ unit.is_complete ? '100%' : (unit.completion_pct || 0) + '%' }}
                      </span>
                    </div>

                    <!-- Row 2: Side Row (LH and RH side-by-side) -->
                    <div class="card-body p-2 bg-white">
                      <div class="side-panel-grid">
                        <!-- LH Clickable Panel (Soft Blue) -->
                        <div
                          class="side-panel p-2 transition-card"
                          :class="unit.sides?.LH?.total_parts > 0 ? 'side-panel-lh' : 'side-panel-disabled'"
                          @click="unit.sides?.LH?.total_parts > 0 && openUnitSide(unit, 'LH')"
                          :title="unit.sides?.LH?.total_parts > 0 ? `Open ${unit.unit_no} LH Parts` : 'No LH Parts'">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="side-badge" :class="unit.sides?.LH?.total_parts > 0 ? 'side-badge-lh' : 'side-badge-disabled'">LH</span>
                            <span class="side-pct-pill" :class="unit.sides?.LH?.total_parts > 0 ? 'side-pct-lh' : 'side-pct-disabled'">
                              {{ unit.sides?.LH?.total_parts > 0 ? (unit.sides?.LH?.completion_pct || 0) + '%' : '—' }}
                            </span>
                          </div>
                          <div class="d-flex align-items-center justify-content-between">
                            <span class="extra-small side-part-count" :class="unit.sides?.LH?.total_parts > 0 ? 'text-dark-slate' : 'text-muted-gray'">
                              {{ unit.sides?.LH?.total_parts || 0 }} Parts
                            </span>
                            <i class="fas fa-chevron-right extra-small side-chevron-lh" v-if="unit.sides?.LH?.total_parts > 0"></i>
                          </div>
                        </div>

                        <!-- RH Clickable Panel (Soft Violet) -->
                        <div
                          class="side-panel p-2 transition-card"
                          :class="unit.sides?.RH?.total_parts > 0 ? 'side-panel-rh' : 'side-panel-disabled'"
                          @click="unit.sides?.RH?.total_parts > 0 && openUnitSide(unit, 'RH')"
                          :title="unit.sides?.RH?.total_parts > 0 ? `Open ${unit.unit_no} RH Parts` : 'No RH Parts'">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="side-badge" :class="unit.sides?.RH?.total_parts > 0 ? 'side-badge-rh' : 'side-badge-disabled'">RH</span>
                            <span class="side-pct-pill" :class="unit.sides?.RH?.total_parts > 0 ? 'side-pct-rh' : 'side-pct-disabled'">
                              {{ unit.sides?.RH?.total_parts > 0 ? (unit.sides?.RH?.completion_pct || 0) + '%' : '—' }}
                            </span>
                          </div>
                          <div class="d-flex align-items-center justify-content-between">
                            <span class="extra-small side-part-count" :class="unit.sides?.RH?.total_parts > 0 ? 'text-dark-slate' : 'text-muted-gray'">
                              {{ unit.sides?.RH?.total_parts || 0 }} Parts
                            </span>
                            <i class="fas fa-chevron-right extra-small side-chevron-rh" v-if="unit.sides?.RH?.total_parts > 0"></i>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- LEVEL 4: Parts Table inside Selected Unit (Dedicated Side View) -->
            <div v-else-if="selectedUnit">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                  <div>
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center flex-wrap gap-2">
                      <span><i class="fas fa-list me-1.5 text-warning"></i>JIG {{ selectedJig.jig_name }} — {{ selectedUnit.unit_no }}</span>
                      <span class="badge px-2 py-1" :class="selectedUnitSide === 'LH' ? 'bg-info text-dark' : 'bg-primary text-white'">
                        Viewing {{ selectedUnitSide }} Side
                      </span>
                    </h6>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge side-badge px-2.5 py-1.5 extra-small" :class="selectedUnitSide === 'LH' ? 'side-badge-lh' : 'side-badge-rh'">
                      {{ selectedUnitSide === 'LH' ? '🔵 LH Parts' : '🔷 RH Parts' }} ({{ selectedUnitSide === 'LH' ? selectedUnitLhParts.length : selectedUnitRhParts.length }})
                    </span>
                    <!-- Stage Filter Tabs inside Unit -->
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-xs" :class="qcStageFilter === 'all' ? 'btn-dark' : 'btn-outline-secondary'" @click="qcStageFilter = 'all'">
                        All
                      </button>
                      <button class="btn btn-xs" :class="qcStageFilter === 'arrival' ? 'btn-primary' : 'btn-outline-primary'" @click="qcStageFilter = 'arrival'">
                        📦 Arrival
                      </button>
                      <button class="btn btn-xs" :class="qcStageFilter === 'inspection' ? 'btn-warning text-dark' : 'btn-outline-warning text-dark'" @click="qcStageFilter = 'inspection'">
                        🔬 Inspection
                      </button>
                    </div>
                    <button class="btn btn-xs btn-outline-secondary" @click="selectedUnit = null">
                      <i class="fas fa-arrow-left me-1"></i> Back to Units
                    </button>
                  </div>
                </div>

                <!-- Side Parts Table -->
                <div class="card border shadow-xs">
                  <div class="card-header py-2 px-3 border-bottom d-flex justify-content-between align-items-center"
                    :class="selectedUnitSide === 'LH' ? 'bg-info-subtle' : 'bg-primary-subtle'">
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge px-2 py-1 fw-bold" :class="selectedUnitSide === 'LH' ? 'bg-info text-dark' : 'bg-primary text-white'">
                        {{ selectedUnitSide }}
                      </span>
                      <h6 class="fw-bold mb-0 text-dark">{{ selectedUnit.unit_no }} — {{ selectedUnitSide === 'LH' ? 'Left Hand' : 'Right Hand' }} QC Desk</h6>
                    </div>
                    <span class="badge bg-white text-dark border">
                      {{ (selectedUnitSide === 'LH' ? selectedUnitLhParts : selectedUnitRhParts).length }} Parts
                    </span>
                  </div>
                  <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                      <table class="table table-hover align-middle border-top mb-0 small">
                        <thead class="table-dark sticky-top" style="z-index: 1;">
                          <tr>
                            <th>Part Number</th>
                            <th>Supplier</th>
                            <th>Status / Stage</th>
                            <th style="width: 140px;">{{ authStore.userRole === 'ADMIN' ? 'QC Action' : 'Monitoring Status' }}</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-for="part in (selectedUnitSide === 'LH' ? selectedUnitLhParts : selectedUnitRhParts)" :key="selectedUnitSide + '_' + part.id">
                            <td>
                              <div class="fw-bold text-primary">{{ part.standard_part_no }}</div>
                              <small class="text-muted" v-if="part.item_no">Item #: {{ part.item_no }}</small>
                            </td>
                            <td>
                              <div class="extra-small fw-semibold text-truncate" style="max-width: 110px;" :title="part.supplier?.name || part.supplier_name_raw || 'Standard'">
                                {{ part.supplier?.name || part.supplier_name_raw || 'Standard' }}
                              </div>
                            </td>
                            <td>
                              <div v-if="part.metrics.qc_pending_arrival > 0" class="badge bg-warning text-dark d-block mb-1">
                                <i class="fas fa-truck me-1"></i>{{ part.metrics.qc_pending_arrival }} Arrival
                              </div>
                              <div v-if="part.metrics.qc_pending_inspection > 0" class="badge bg-info text-dark d-block mb-1">
                                <i class="fas fa-microscope me-1"></i>{{ part.metrics.qc_pending_inspection }} In QC
                              </div>
                              <div v-if="part.metrics.qc_approved > 0" class="badge bg-success d-block">
                                <i class="fas fa-check-circle me-1"></i>{{ part.metrics.qc_approved }} Approved
                              </div>
                            </td>
                            <td>
                              <div v-if="authStore.userRole === 'ADMIN'" class="d-flex flex-column gap-1">
                                <button v-if="part.metrics.qc_pending_arrival > 0" class="btn btn-xs btn-primary fw-bold text-nowrap w-100" @click="openArrivalModal(part, selectedUnitSide)">
                                  <i class="fas fa-check-double me-1"></i> Store Receive Item
                                </button>
                                <button v-if="part.metrics.qc_pending_inspection > 0" class="btn btn-xs btn-warning text-dark fw-bold text-nowrap w-100" @click="openInspectModalFromPart(part, selectedUnitSide)">
                                  <i class="fas fa-microscope me-1"></i> Inspect
                                </button>
                                <span v-if="part.metrics.qc_pending_arrival === 0 && part.metrics.qc_pending_inspection === 0" class="text-muted extra-small text-center">
                                  <i class="fas fa-check text-success me-1"></i>Done
                                </span>
                              </div>
                              <div v-else class="text-center">
                                <span v-if="part.metrics.qc_pending_arrival > 0" class="badge bg-warning-subtle text-dark border">
                                  <i class="fas fa-truck me-1"></i>Pending
                                </span>
                                <span v-else-if="part.metrics.qc_pending_inspection > 0" class="badge bg-info-subtle text-dark border">
                                  <i class="fas fa-microscope me-1"></i>In QC
                                </span>
                                <span v-else-if="part.metrics.qc_approved > 0" class="badge bg-success-subtle text-success border">
                                  <i class="fas fa-check-circle me-1"></i>Cleared
                                </span>
                                <span v-else class="text-muted extra-small">Done</span>
                              </div>
                            </td>
                          </tr>
                          <tr v-if="!(selectedUnitSide === 'LH' ? selectedUnitLhParts : selectedUnitRhParts).length">
                            <td colspan="4" class="text-center py-4 text-muted">No {{ selectedUnitSide }} QC parts in this unit.</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>

            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- PHYSICAL ARRIVAL CHECK MODAL -->
    <div class="modal fade" id="arrivalModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title fw-bold"><i class="fas fa-truck-loading me-2"></i>Confirm Physical QC Arrival</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeArrivalItem">
            <p class="mb-3">Confirm that the following spare parts have physically arrived at the Quality Control inspection desk:</p>
            <div class="alert alert-light border">
              <div><strong>Part Number:</strong> {{ activeArrivalItem.bom_item?.standard_part_no || activeArrivalItem.standard_part_no }}</div>
              <div><strong>Side:</strong> <span class="badge" :class="activeArrivalItem.side === 'RH' ? 'badge-rh' : 'badge-lh'">{{ activeArrivalItem.side }}</span></div>
              <div><strong>Quantity:</strong> <span class="fw-bold fs-6 text-primary">{{ activeArrivalItem.received_quantity }} pcs</span></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success fw-bold" @click="confirmArrival" :disabled="submitting">
              <i class="fas fa-check-circle me-1"></i> Store Receive Item
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- QC INSPECTION MODAL WITH PARTIAL SPLIT -->
    <div class="modal fade" id="qcModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-warning text-dark">
            <h5 class="modal-title fw-bold"><i class="fas fa-microscope me-2"></i>Record Quality Inspection</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeInspectItem">
            <div class="alert alert-light border mb-3">
              <div class="row">
                <div class="col-6"><strong>Part Number:</strong> {{ activeInspectItem.bom_item?.standard_part_no || activeInspectItem.standard_part_no }}</div>
                <div class="col-6"><strong>Side:</strong> {{ activeInspectItem.side }}</div>
                <div class="col-6"><strong>Project:</strong> {{ activeInspectItem.bom_item?.project?.name || activeProject?.name }}</div>
                <div class="col-6"><strong>Quantity in QC Bay:</strong> {{ activeInspectItem.received_quantity }} pcs</div>
              </div>
            </div>

            <!-- Decision Radio Buttons -->
            <div class="mb-3">
              <label class="form-label fw-bold">Inspection Result</label>
              <div class="d-flex gap-2">
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resApproved" value="approved" v-model="inspectForm.result">
                  <label class="form-check-label text-success fw-bold me-2" for="resApproved">
                    <i class="fas fa-check-circle me-1"></i> Approve
                  </label>
                </div>
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resRework" value="rework" v-model="inspectForm.result">
                  <label class="form-check-label text-warning text-dark fw-bold me-2" for="resRework">
                    <i class="fas fa-tools me-1"></i> Rework
                  </label>
                </div>
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resRejected" value="rejected" v-model="inspectForm.result">
                  <label class="form-check-label text-danger fw-bold me-2" for="resRejected">
                    <i class="fas fa-times-circle me-1"></i> Reject
                  </label>
                </div>
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resPartial" value="partial" v-model="inspectForm.result">
                  <label class="form-check-label text-primary fw-bold me-2" for="resPartial">
                    <i class="fas fa-balance-scale me-1"></i> Partial Split
                  </label>
                </div>
              </div>
            </div>

            <!-- Partial Quantities Breakdown -->
            <div v-if="inspectForm.result === 'partial'" class="card border-primary p-3 mb-3 bg-light">
              <h6 class="fw-bold text-primary mb-2"><i class="fas fa-sliders-h me-1"></i>Partial Quantity Split</h6>
              <div class="row g-2">
                <div class="col-4">
                  <label class="form-label small fw-bold text-success">Approved Qty</label>
                  <input type="number" v-model.number="inspectForm.approved_qty" min="0" :max="activeInspectItem.received_quantity" class="form-control form-control-sm" />
                </div>
                <div class="col-4">
                  <label class="form-label small fw-bold text-warning text-dark">Rework Qty</label>
                  <input type="number" v-model.number="inspectForm.rework_qty" min="0" :max="activeInspectItem.received_quantity" class="form-control form-control-sm" />
                </div>
                <div class="col-4">
                  <label class="form-label small fw-bold text-danger">Rejected Qty</label>
                  <input type="number" v-model.number="inspectForm.rejected_qty" min="0" :max="activeInspectItem.received_quantity" class="form-control form-control-sm" />
                </div>
              </div>
              <small class="text-muted mt-2">
                Total Split: <strong>{{ (inspectForm.approved_qty || 0) + (inspectForm.rework_qty || 0) + (inspectForm.rejected_qty || 0) }}</strong> / {{ activeInspectItem.received_quantity }} pcs
              </small>
            </div>

            <!-- Reason Fields -->
            <div class="mb-3" v-if="inspectForm.result === 'rejected' || (inspectForm.result === 'partial' && inspectForm.rejected_qty > 0)">
              <label class="form-label fw-semibold text-danger">Rejection Reason</label>
              <input type="text" v-model="inspectForm.rejection_reason" class="form-control border-danger" placeholder="e.g. Out of tolerance, crack detected, wrong material..." />
              <small class="text-danger">Note: Rejected items will be placed into the Purchase Queue for reordering.</small>
            </div>

            <div class="mb-3" v-if="inspectForm.result === 'rework' || (inspectForm.result === 'partial' && inspectForm.rework_qty > 0)">
              <label class="form-label fw-semibold text-warning text-dark">Rework Instructions</label>
              <input type="text" v-model="inspectForm.rework_reason" class="form-control border-warning" placeholder="e.g. Minor burr removal, re-thread hole, deburring..." />
              <small class="text-muted">Note: Reworked items will automatically return to QC queue upon completion.</small>
            </div>

            <!-- Attach Photo -->
            <div class="mb-3">
              <label class="form-label fw-semibold"><i class="fas fa-camera me-1 text-primary"></i>Attach Photo / Defect Document (Optional)</label>
              <input type="file" @change="handleFileChange" accept="image/*,.pdf" class="form-control" />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">QC Remarks</label>
              <textarea v-model="inspectForm.remarks" class="form-control" rows="2" placeholder="Inspection notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-warning text-dark fw-bold" @click="submitInspection" :disabled="submitting">
              <i class="fas fa-save me-1"></i> Save Inspection & Dispatch
            </button>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useAppCacheStore } from '@/stores/cache';
import axios from 'axios';

const authStore = useAuthStore();
const cacheStore = useAppCacheStore();
const projects = ref([]);
const jigs = ref([]);
const activeProject = ref(null);
const projectId = ref('');
const selectedJig = ref(null);
const selectedUnit = ref(null);
const selectedSide = ref('');
const selectedUnitSide = ref('LH');
const searchQuery = ref('');
const searchResults = ref([]);

const qcStageFilter = ref('all'); // 'all' | 'arrival' | 'inspection'

const openUnitSide = (unit, side) => {
  selectedUnit.value = unit;
  selectedUnitSide.value = side;
};

const selectedUnitLhParts = computed(() => {
  if (!selectedUnit.value?.parts) return [];
  return selectedUnit.value.parts.filter(item => {
    const sideStat = item.side_stats?.LH || item.side_stats?.COMMON;
    if (!sideStat) return false;
    if (qcStageFilter.value === 'arrival' && !(sideStat.qc_pending_arrival > 0)) return false;
    if (qcStageFilter.value === 'inspection' && !(sideStat.qc_pending_inspection > 0)) return false;
    return true;
  });
});

const selectedUnitRhParts = computed(() => {
  if (!selectedUnit.value?.parts) return [];
  return selectedUnit.value.parts.filter(item => {
    const sideStat = item.side_stats?.RH || item.side_stats?.COMMON;
    if (!sideStat) return false;
    if (qcStageFilter.value === 'arrival' && !(sideStat.qc_pending_arrival > 0)) return false;
    if (qcStageFilter.value === 'inspection' && !(sideStat.qc_pending_inspection > 0)) return false;
    return true;
  });
});

const activeArrivalItem = ref(null);
const activeInspectItem = ref(null);
const error = ref('');
const successMessage = ref('');
const submitting = ref(false);
const selectedFile = ref(null);

const inspectForm = ref({
  result: 'approved',
  approved_qty: 0,
  rework_qty: 0,
  rejected_qty: 0,
  rejection_reason: '',
  rework_reason: '',
  remarks: '',
});

let searchDebounce = null;

const loadHierarchy = async (forceFresh = false) => {
  const params = new URLSearchParams();
  if (projectId.value) params.append('project_id', projectId.value);
  if (selectedSide.value) params.append('side', selectedSide.value);

  const cacheKey = `qc_hierarchy_${params.toString()}`;

  const applyData = (data) => {
    if (data.is_hierarchical) {
      activeProject.value = data.project;
      jigs.value = data.jigs || [];
      projects.value = data.projects || [];

      // Preserve selected JIG & Unit pointers
      if (selectedJig.value) {
        selectedJig.value = jigs.value.find(j => j.jig_name === selectedJig.value.jig_name) || null;
        if (selectedJig.value && selectedUnit.value) {
          selectedUnit.value = selectedJig.value.units?.find(u => u.unit_no === selectedUnit.value.unit_no) || null;
        } else if (!selectedJig.value) {
          selectedUnit.value = null;
        }
      }
    } else {
      projects.value = data.projects || [];
      jigs.value = [];
      activeProject.value = null;
      selectedJig.value = null;
      selectedUnit.value = null;
    }
  };

  const cached = cacheStore.get(cacheKey);
  if (cached && !forceFresh) {
    applyData(cached.data);
  }

  try {
    const response = await axios.get(`/api/v1/qc/hierarchy?${params.toString()}`);
    cacheStore.set(cacheKey, response.data, 60000);
    applyData(response.data);
  } catch (err) {
    error.value = 'Unable to load QC hierarchy.';
  }
};

const onProjectChange = () => {
  selectedJig.value = null;
  selectedUnit.value = null;
  loadHierarchy(true);
};

const goBackOneLevel = () => {
  if (selectedUnit.value) {
    selectedUnit.value = null;
  } else if (selectedJig.value) {
    selectedJig.value = null;
  } else if (projectId.value) {
    projectId.value = '';
    onProjectChange();
  }
};

const onSearchInput = () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(async () => {
    if (!searchQuery.value.trim()) {
      searchResults.value = [];
      return;
    }
    try {
      const response = await axios.get(`/api/v1/qc/hierarchy?search=${encodeURIComponent(searchQuery.value)}&project_id=${projectId.value || ''}&side=${selectedSide.value || ''}`);
      if (response.data.is_hierarchical) {
        const flatParts = [];
        (response.data.jigs || []).forEach(j => {
          (j.units || []).forEach(u => {
            (u.parts || []).forEach(p => flatParts.push(p));
          });
        });
        searchResults.value = flatParts;
      }
    } catch (e) {
      console.error(e);
    }
  }, 300);
};

const openArrivalModal = (part, explicitSide = null) => {
  const targetSide = explicitSide || selectedSide.value || Object.keys(part.side_stats || {})[0] || 'RH';
  const sideStat = part.side_stats?.[targetSide] || {};
  const sideReceipts = sideStat.receipt_items || (part.receipt_items || []).filter(r => r.side === targetSide || r.side === 'COMMON');
  const recItem = sideReceipts.find(r => ['received', 'sent_to_qc'].includes(r.status));

  activeArrivalItem.value = recItem || {
    id: part.id,
    standard_part_no: part.standard_part_no,
    side: targetSide,
    received_quantity: sideStat.qc_pending_arrival || part.metrics?.qc_pending_arrival || 1,
  };

  const modalEl = document.getElementById('arrivalModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();
};

const confirmArrival = async () => {
  submitting.value = true;
  try {
    const response = await axios.post('/api/v1/qc/receive', {
      receipt_item_id: activeArrivalItem.value.id,
    });
    successMessage.value = response.data.message || 'QC physical arrival confirmed.';
    
    cacheStore.invalidate('qc');
    cacheStore.invalidate('dashboard');
    cacheStore.invalidate('store');

    const modalEl = document.getElementById('arrivalModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    await loadHierarchy(true);
    if (searchQuery.value) onSearchInput();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to confirm QC arrival.';
  } finally {
    submitting.value = false;
  }
};

const openInspectModalFromPart = (part, explicitSide = null) => {
  const targetSide = explicitSide || selectedSide.value || Object.keys(part.side_stats || {})[0] || 'RH';
  const sideStat = part.side_stats?.[targetSide] || {};
  const sideReceipts = sideStat.receipt_items || (part.receipt_items || []).filter(r => r.side === targetSide || r.side === 'COMMON');
  const recItem = sideReceipts.find(r => r.status === 'qc_received') || {
    id: null,
    bom_item_id: part.id,
    standard_part_no: part.standard_part_no,
    side: targetSide,
    received_quantity: sideStat.qc_pending_inspection || part.metrics?.qc_pending_inspection || 1,
  };

  const finalQty = recItem.received_quantity || sideStat.qc_pending_inspection || 1;
  activeInspectItem.value = {
    ...recItem,
    bom_item_id: part.id,
    side: targetSide,
    received_quantity: finalQty
  };

  inspectForm.value = {
    result: 'approved',
    approved_qty: finalQty,
    rework_qty: 0,
    rejected_qty: 0,
    rejection_reason: '',
    rework_reason: '',
    remarks: '',
  };
  selectedFile.value = null;

  const modalEl = document.getElementById('qcModal');
  if (modalEl) new bootstrap.Modal(modalEl).show();
};

const handleFileChange = (event) => {
  const file = event.target.files[0];
  if (file) selectedFile.value = file;
};

const submitInspection = async () => {
  submitting.value = true;
  try {
    const totalArrived = activeInspectItem.value.received_quantity;
    let appQty = totalArrived;
    let rewQty = 0;
    let rejQty = 0;

    if (inspectForm.value.result === 'approved') {
      appQty = totalArrived;
    } else if (inspectForm.value.result === 'rework') {
      appQty = 0; rewQty = totalArrived;
    } else if (inspectForm.value.result === 'rejected') {
      appQty = 0; rejQty = totalArrived;
    } else if (inspectForm.value.result === 'partial') {
      appQty = inspectForm.value.approved_qty || 0;
      rewQty = inspectForm.value.rework_qty || 0;
      rejQty = inspectForm.value.rejected_qty || 0;
      if (appQty + rewQty + rejQty !== totalArrived) {
        error.value = `Sum of partial split quantities (${appQty + rewQty + rejQty}) must equal total inspected (${totalArrived}).`;
        submitting.value = false;
        return;
      }
    }

    const formData = new FormData();
    formData.append('receipt_item_id', activeInspectItem.value.id || '');
    formData.append('bom_item_id', activeInspectItem.value.bom_item_id || activeInspectItem.value.bom_item?.id || '');
    formData.append('side', activeInspectItem.value.side);
    formData.append('inspected_quantity', totalArrived);
    formData.append('result', inspectForm.value.result);
    formData.append('approved_quantity', appQty);
    formData.append('rework_quantity', rewQty);
    formData.append('rejected_quantity', rejQty);
    formData.append('rejection_reason', inspectForm.value.rejection_reason || '');
    formData.append('rework_reason', inspectForm.value.rework_reason || '');
    formData.append('remarks', inspectForm.value.remarks || '');

    if (selectedFile.value) {
      formData.append('photo', selectedFile.value);
    }

    const response = await axios.post('/api/v1/qc/inspect', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    successMessage.value = response.data.message || 'QC Inspection recorded successfully.';
    
    cacheStore.invalidate('qc');
    cacheStore.invalidate('dashboard');
    cacheStore.invalidate('paint');
    cacheStore.invalidate('rework');
    cacheStore.invalidate('assembly');

    const modalEl = document.getElementById('qcModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    await loadHierarchy(true);
    if (searchQuery.value) onSearchInput();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to record QC inspection.';
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  loadHierarchy();
});
</script>

<style scoped>
.transition-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.transition-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1) !important;
}
.extra-small {
  font-size: 0.72rem;
}
.btn-xs {
  padding: 0.2rem 0.5rem;
  font-size: 0.75rem;
  border-radius: 0.2rem;
}
.compact-unit-card {
  border: 1px solid #CBD5E1 !important;
  border-radius: 8px !important;
  background: #FFFFFF;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
  overflow: hidden;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.compact-unit-card:hover {
  border-color: #94A3B8 !important;
  box-shadow: 0 3px 8px rgba(15, 23, 42, 0.09);
}
.compact-unit-header {
  background-color: #E8EEF5;
  border-bottom: 1px solid #CBD5E1;
  padding: 0.35rem 0.65rem;
}
.unit-icon-box {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  background: #FFFFFF;
  border: 1px solid #94A3B8;
  border-radius: 4px;
  color: #1F2937;
  font-size: 0.75rem;
}
.unit-title-text {
  font-size: 1rem;
  font-weight: 700;
  color: #1F2937;
  letter-spacing: -0.01em;
}
.unit-overall-badge {
  font-size: 0.75rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
}
.badge-unit-progress {
  background-color: #2F80C9;
  border: 1px solid #2675B8;
  color: #FFFFFF;
}
.badge-unit-complete {
  background-color: #2E8B68;
  border: 1px solid #8CCBAA;
  color: #FFFFFF;
}

.side-panel-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 7px;
}
.side-panel {
  cursor: pointer;
  user-select: none;
  border-radius: 6px;
  transition: all 0.15s ease-in-out;
}

/* LH Side: Medium Blue Palette */
.side-panel-lh {
  background-color: #DCEBFA;
  border: 1.5px solid #6FA8DC !important;
}
.side-panel-lh:hover {
  background-color: #CDE2F7;
  border-color: #4F91CC !important;
  box-shadow: 0 2px 6px rgba(23, 90, 145, 0.15);
  transform: translateY(-1px);
}
.side-badge-lh {
  background-color: #BFDDF5;
  color: #175A91;
  font-weight: 700;
}
.side-pct-lh {
  background-color: #FFFFFF;
  border: 1px solid #6FA8DC;
  color: #175A91;
  font-weight: 600;
}
.side-chevron-lh {
  color: #175A91;
}

/* RH Side: Medium Purple Palette */
.side-panel-rh {
  background-color: #E9E0F8;
  border: 1.5px solid #9275C7 !important;
}
.side-panel-rh:hover {
  background-color: #DFD2F2;
  border-color: #7659B1 !important;
  box-shadow: 0 2px 6px rgba(86, 53, 143, 0.15);
  transform: translateY(-1px);
}
.side-badge-rh {
  background-color: #D7C7EE;
  color: #56358F;
  font-weight: 700;
}
.side-pct-rh {
  background-color: #FFFFFF;
  border: 1px solid #9275C7;
  color: #56358F;
  font-weight: 600;
}
.side-chevron-rh {
  color: #56358F;
}

/* Disabled Side: Neutral Gray Palette */
.side-panel-disabled {
  background-color: #EEF1F4;
  border: 1.5px dashed #D6DCE3 !important;
  opacity: 0.75;
  cursor: not-allowed;
}
.side-badge-disabled {
  background-color: #D6DCE3;
  color: #8993A1;
  font-weight: 600;
}
.side-pct-disabled {
  background-color: #F4F6F8;
  border: 1px solid #D6DCE3;
  color: #8993A1;
  font-weight: 500;
}

.side-badge {
  font-size: 0.68rem;
  padding: 2px 6px;
  border-radius: 4px;
  display: inline-block;
}
.side-pct-pill {
  font-size: 0.68rem;
  padding: 1px 5px;
  border-radius: 4px;
}
.side-part-count {
  font-size: 0.73rem;
  font-weight: 600;
}
.text-dark-slate {
  color: #334155;
}
.text-muted-gray {
  color: #8993A1;
}
</style>
