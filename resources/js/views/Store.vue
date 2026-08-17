<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-boxes me-2 text-success"></i>Store Receiving Desk</h3>
                <p class="text-muted mb-0">Manage incoming supplier deliveries, QC-returned parts, and stock receipt records.</p>
              </div>
              <span class="badge bg-success px-3 py-2 fs-6">Store Module</span>
            </div>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs card-header-tabs mt-3 border-0">
              <li class="nav-item">
                <button class="nav-link px-4 fw-bold" :class="{ 'active border-success border-bottom border-2 text-success': activeStoreTab === 'pending', 'text-secondary': activeStoreTab !== 'pending' }" @click="activeStoreTab = 'pending'">
                  <i class="fas fa-box-open me-2"></i>Pending Intake
                </button>
              </li>
              <li class="nav-item">
                <button class="nav-link px-4 fw-bold" :class="{ 'active border-primary border-bottom border-2 text-primary': activeStoreTab === 'history', 'text-secondary': activeStoreTab !== 'history' }" @click="activeStoreTab = 'history'; fetchReceiptHistory();">
                  <i class="fas fa-history me-2"></i>Receipts History
                </button>
              </li>
            </ul>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger alert-dismissible fade show">
              <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
              <button type="button" class="btn-close" @click="error = ''"></button>
            </div>
            <div v-if="successMessage" class="alert alert-success alert-dismissible fade show">
              <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
              <button type="button" class="btn-close" @click="successMessage = ''"></button>
            </div>

            <!-- TAB 1: PENDING INTAKE -->
            <div v-if="activeStoreTab === 'pending'">
              <!-- Fast Search & Filters Bar -->
              <div class="row g-3 mb-4">
                <div class="col-md-7">
                  <label class="form-label fw-semibold"><i class="fas fa-search me-1 text-primary"></i>Fast Search (Part No / Size / Supplier)</label>
                  <div class="input-group">
                    <input v-model="searchQuery" @input="onSearchInput" class="form-control form-control-lg shadow-xs" placeholder="Type StandardPartNo e.g. 62800-ST7..." />
                    <button v-if="searchQuery" class="btn btn-outline-secondary" @click="searchQuery = ''; onSearchInput();">
                      <i class="fas fa-times"></i>
                    </button>
                  </div>
                </div>
                <div class="col-md-5">
                  <label class="form-label fw-semibold"><i class="fas fa-project-diagram me-1 text-info"></i>Project Selection</label>
                  <select v-model="projectId" class="form-select form-select-lg shadow-xs" @change="onProjectChange">
                    <option value="">All Projects</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">
                      {{ project.name || project.project_code }} ({{ project.completion_pct !== undefined ? project.completion_pct + '% Complete' : project.project_code }})
                    </option>
                  </select>
                </div>
              </div>

            <!-- SEARCH RESULTS VIEW (when search query is typed) -->
            <div v-if="searchQuery">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                  <i class="fas fa-search me-2 text-primary"></i>Search Results for "{{ searchQuery }}" ({{ items.length }} parts found)
                </h5>
                <button class="btn btn-sm btn-outline-secondary" @click="searchQuery = ''; onSearchInput();">
                  <i class="fas fa-arrow-left me-1"></i> Back to Project View
                </button>
              </div>

              <div class="table-responsive bg-white rounded border shadow-xs">
                <table class="table table-hover align-middle border-top mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Standard Part Number</th>
                      <th>Project</th>
                      <th>Supplier</th>
                      <th>Size</th>
                      <th>RH Status (Req / Rec / Pend)</th>
                      <th>LH Status (Req / Rec / Pend)</th>
                      <th style="width: 200px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in items" :key="item.id">
                      <td>
                        <div class="fw-bold text-primary fs-6">{{ item.standard_part_no }}</div>
                        <small class="text-muted" v-if="item.item_no">Item #: {{ item.item_no }}</small>
                      </td>
                      <td><span class="badge bg-light text-dark border">{{ item.project?.name || item.project?.project_code || 'N/A' }}</span></td>
                      <td>
                        <div class="small fw-semibold">{{ item.supplier?.name || item.supplier_name_raw || 'Standard' }}</div>
                      </td>
                      <td><small class="text-muted">{{ item.size || '—' }}</small></td>

                      <!-- RH Breakdown -->
                      <td>
                        <div v-if="item.side_stats?.RH" class="small">
                          <span class="badge bg-primary me-1">Req: {{ item.side_stats.RH.required }}</span>
                          <span class="badge bg-success me-1">Rec: {{ item.side_stats.RH.received }}</span>
                          <span class="badge bg-danger" v-if="item.side_stats.RH.pending > 0">Pend: {{ item.side_stats.RH.pending }}</span>
                          <span class="badge bg-secondary" v-else>Fulfilled</span>
                        </div>
                        <span v-else class="text-muted small">N/A</span>
                      </td>

                      <!-- LH Breakdown -->
                      <td>
                        <div v-if="item.side_stats?.LH" class="small">
                          <span class="badge bg-primary me-1">Req: {{ item.side_stats.LH.required }}</span>
                          <span class="badge bg-success me-1">Rec: {{ item.side_stats.LH.received }}</span>
                          <span class="badge bg-danger" v-if="item.side_stats.LH.pending > 0">Pend: {{ item.side_stats.LH.pending }}</span>
                          <span class="badge bg-secondary" v-else>Fulfilled</span>
                        </div>
                        <span v-else class="text-muted small">N/A</span>
                      </td>

                      <!-- Action Column -->
                      <td>
                        <template v-if="authStore.userRole === 'MANAGER'">
                          <span v-if="item.side_stats && Object.values(item.side_stats).some(s => s.pending > 0)" class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                            <i class="fas fa-clock me-1"></i>AWAITING RECEIPT
                          </span>
                          <span v-else class="badge bg-success border px-3 py-2 w-100 d-block">
                            <i class="fas fa-check-circle me-1"></i>STORE RECEIVED
                          </span>
                        </template>
                        <template v-else>
                          <button class="btn btn-sm btn-success fw-bold text-nowrap w-100" @click="openReceiveModal(item)" :disabled="item.side_stats && Object.values(item.side_stats).every(s => s.pending === 0)">
                            <i class="fas fa-plus me-1"></i> Receive Stock
                          </button>
                        </template>
                      </td>
                    </tr>
                    <tr v-if="!items.length">
                      <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-search fa-3x mb-3 text-secondary"></i>
                        <p class="mb-0 fs-6">No matching spare parts found for "{{ searchQuery }}".</p>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- LEVEL 1: Projects Grid (when no project is selected and no search query) -->
            <div v-else-if="!projectId">
              <h6 class="fw-bold mb-3 text-secondary text-uppercase ls-1"><i class="fas fa-project-diagram me-2 text-primary"></i>Active Projects ({{ projects.length }})</h6>
              <div class="row g-2">
                <div v-for="proj in projects" :key="proj.id" class="col-md-6 col-lg-4">
                  <div class="card h-100 border shadow-xs transition-card border-light bg-white"
                    style="cursor: pointer;"
                    @click="projectId = proj.id; onProjectChange();">
                    <div class="card-body p-3">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="fw-bold mb-0 text-dark text-truncate me-2" style="max-width: 70%;" :title="proj.name">
                          <i class="fas fa-folder text-primary me-1.5"></i>{{ proj.name || proj.project_code || 'Project' }}
                        </h6>
                        <span class="badge" :class="proj.is_complete ? 'bg-success' : 'bg-primary'" style="font-size: 0.72rem;">
                          {{ proj.is_complete ? '100%' : ((proj.completion_pct !== undefined && proj.completion_pct !== null) ? proj.completion_pct : 0) + '%' }}
                        </span>
                      </div>
                      <p class="text-muted extra-small mb-2">
                        Code: <strong>{{ proj.project_code || 'N/A' }}</strong>
                      </p>
                      <div class="progress mb-2" style="height: 6px;">
                        <div class="progress-bar"
                          :class="proj.is_complete ? 'bg-success' : 'bg-primary'"
                          :style="{ width: ((proj.completion_pct !== undefined && proj.completion_pct !== null) ? proj.completion_pct : 0) + '%' }">
                        </div>
                      </div>
                      <div class="d-flex justify-content-between text-muted extra-small border-top pt-1.5">
                        <span>Req: <strong>{{ proj.total_required || 0 }}</strong></span>
                        <span>Rec: <strong class="text-success">{{ proj.total_received || 0 }}</strong></span>
                      </div>
                    </div>
                    <div class="card-footer bg-light border-0 text-end py-1.5 px-3">
                      <span class="extra-small text-primary fw-bold">Open Project Store <i class="fas fa-chevron-right ms-1"></i></span>
                    </div>
                  </div>
                </div>
                <div v-if="!projects.length" class="col-12 text-center py-4 text-muted bg-white rounded border shadow-xs">
                  <i class="fas fa-folder-open fa-2x mb-2 text-muted"></i>
                  <p class="mb-0">No active projects found. Please import a BOM file to get started.</p>
                </div>
              </div>
            </div>

            <!-- JIG & UNIT DRILLDOWN (when project is selected and no search query) -->
            <div v-else>
              <!-- Breadcrumbs Navigation -->
              <div class="d-flex align-items-center justify-content-between p-2.5 mb-3 bg-white border rounded shadow-xs">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb mb-0 small fw-bold">
                    <li class="breadcrumb-item">
                      <a href="#" @click.prevent="projectId = ''; onProjectChange();" class="text-secondary text-decoration-none">
                        <i class="fas fa-home me-1"></i>Projects
                      </a>
                    </li>
                    <li class="breadcrumb-item">
                      <a href="#" @click.prevent="resetHierarchyBreadcrumb" class="text-primary text-decoration-none">
                        {{ activeProjectName }}
                      </a>
                    </li>
                    <li v-if="selectedJig" class="breadcrumb-item">
                      <a href="#" @click.prevent="selectedUnit = null" class="text-primary text-decoration-none">
                        JIG: {{ selectedJig.jig_name }}
                      </a>
                    </li>
                    <li v-if="selectedUnit" class="breadcrumb-item active text-success">
                      {{ selectedUnit.unit_no }}
                    </li>
                  </ol>
                </nav>
                <div>
                  <button class="btn btn-outline-secondary btn-xs" @click="goBackHierarchy">
                    <i class="fas fa-arrow-left me-1"></i> Back
                  </button>
                </div>
              </div>

              <!-- HIERARCHICAL DRILLDOWN VIEW -->
              <div v-if="isHierarchical">

              <!-- LEVEL 1: JIG Cards Grid (when no JIG selected) -->
              <div v-if="!selectedJig">
                <h6 class="fw-bold mb-2 text-dark"><i class="fas fa-cubes me-1.5 text-primary"></i>Assembly JIGs in {{ activeProjectName }} ({{ hierarchyJigs.length }} JIGs)</h6>
                <div class="row g-2">
                  <div v-for="jig in hierarchyJigs" :key="jig.jig_name" class="col-md-6 col-lg-4">
                    <div class="card h-100 border shadow-xs transition-card"
                      :class="jig.is_complete ? 'border-success bg-white' : 'border-light bg-white'"
                      style="cursor: pointer;"
                      @click="selectedJig = jig">
                      <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                          <h6 class="fw-bold mb-0 text-dark text-truncate me-2">
                            <i class="fas me-1.5" :class="jig.is_complete ? 'fa-check-circle text-success' : 'fa-cog text-primary'"></i>
                            JIG: {{ jig.jig_name }}
                          </h6>
                          <span class="badge" :class="jig.is_complete ? 'bg-success' : 'bg-primary'" style="font-size: 0.72rem;">
                            {{ jig.is_complete ? '100%' : jig.completion_pct + '%' }}
                          </span>
                        </div>
                        <p class="text-muted extra-small mb-2">
                          {{ jig.complete_units }} / {{ jig.total_units }} Units Complete | {{ jig.total_parts }} Parts
                        </p>
                        <div class="progress mb-2" style="height: 6px;">
                          <div class="progress-bar"
                            :class="jig.is_complete ? 'bg-success' : 'bg-primary'"
                            :style="{ width: jig.completion_pct + '%' }">
                          </div>
                        </div>
                        <div class="d-flex justify-content-between text-muted extra-small border-top pt-1.5">
                          <span>Req: <strong>{{ jig.total_required || 0 }}</strong></span>
                          <span>Rec: <strong class="text-success">{{ jig.total_received || 0 }}</strong></span>
                          <span>Pending: <strong class="text-danger">{{ (jig.total_required || 0) - (jig.total_received || 0) }}</strong></span>
                        </div>
                      </div>
                      <div class="card-footer bg-light border-0 text-end py-1.5 px-3">
                        <span class="extra-small text-primary fw-bold">Explore Units <i class="fas fa-arrow-right ms-1"></i></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- LEVEL 2: Units List (when JIG selected, no Unit selected) -->
              <div v-else-if="selectedJig && !selectedUnit">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-layer-group me-1.5 text-primary"></i>
                    Units in JIG {{ selectedJig.jig_name }} ({{ selectedJig.units?.length || 0 }} Units)
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

              <!-- LEVEL 3: Parts Table for selected Unit (Dedicated Side View) -->
              <div v-else-if="selectedUnit">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                  <div>
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center flex-wrap gap-2">
                      <span><i class="fas fa-layer-group me-1.5 text-primary"></i>JIG {{ selectedJig.jig_name }} — {{ selectedUnit.unit_no }}</span>
                      <span class="badge px-2 py-1" :class="selectedUnitSide === 'LH' ? 'bg-info text-dark' : 'bg-primary text-white'">
                        Viewing {{ selectedUnitSide }} Side
                      </span>
                    </h6>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge side-badge px-2.5 py-1.5 extra-small" :class="selectedUnitSide === 'LH' ? 'side-badge-lh' : 'side-badge-rh'">
                      {{ selectedUnitSide === 'LH' ? '🔵 LH Parts' : '🔷 RH Parts' }} ({{ selectedUnitSide === 'LH' ? selectedUnitLhParts.length : selectedUnitRhParts.length }})
                    </span>
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
                      <h6 class="fw-bold mb-0 text-dark">{{ selectedUnit.unit_no }} — {{ selectedUnitSide === 'LH' ? 'Left Hand' : 'Right Hand' }} Parts List</h6>
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
                            <th>{{ selectedUnitSide }} (Req / Rec / Pend)</th>
                            <th style="width: 140px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-for="item in (selectedUnitSide === 'LH' ? selectedUnitLhParts : selectedUnitRhParts)" :key="selectedUnitSide + '_' + item.id">
                            <td>
                              <div class="fw-bold text-primary">{{ item.standard_part_no }}</div>
                              <small class="text-muted" v-if="item.item_no">Item #: {{ item.item_no }}</small>
                            </td>
                            <td>
                              <div class="extra-small fw-semibold text-truncate" style="max-width: 110px;" :title="item.supplier?.name || item.supplier_name_raw || 'Standard'">
                                {{ item.supplier?.name || item.supplier_name_raw || 'Standard' }}
                              </div>
                            </td>
                            <td>
                              <div v-if="item.side_stats?.[selectedUnitSide]" class="d-flex flex-wrap gap-1">
                                <span class="badge bg-primary">Req: {{ item.side_stats[selectedUnitSide].required }}</span>
                                <span class="badge bg-success">Rec: {{ item.side_stats[selectedUnitSide].received }}</span>
                                <span class="badge bg-danger" v-if="item.side_stats[selectedUnitSide].pending > 0">Pend: {{ item.side_stats[selectedUnitSide].pending }}</span>
                                <span class="badge bg-secondary" v-else>Fulfilled</span>
                              </div>
                              <div v-else-if="item.side_stats?.COMMON" class="d-flex flex-wrap gap-1">
                                <span class="badge bg-secondary">COMMON</span>
                                <span class="badge bg-primary">Req: {{ item.side_stats.COMMON.required }}</span>
                                <span class="badge bg-success">Rec: {{ item.side_stats.COMMON.received }}</span>
                                <span class="badge bg-danger" v-if="item.side_stats.COMMON.pending > 0">Pend: {{ item.side_stats.COMMON.pending }}</span>
                                <span class="badge bg-secondary" v-else>Fulfilled</span>
                              </div>
                            </td>
                            <td>
                              <template v-if="(item.side_stats?.[selectedUnitSide]?.pending > 0 || item.side_stats?.COMMON?.pending > 0)">
                                <button v-if="authStore.userRole !== 'MANAGER'"
                                  class="btn btn-xs btn-primary fw-bold text-nowrap w-100"
                                  @click="openReceiveModal(item, item.side_stats?.[selectedUnitSide] ? selectedUnitSide : 'COMMON')">
                                  <i class="fas fa-inbox me-1"></i> Receive {{ item.side_stats?.[selectedUnitSide] ? selectedUnitSide : 'COMMON' }}
                                </button>
                                <span v-else class="badge bg-warning text-dark extra-small fw-bold px-2 py-1">
                                  <i class="fas fa-clock me-1"></i>Pending
                                </span>
                              </template>
                              <template v-else>
                                <span class="text-success extra-small fw-bold">
                                  <i class="fas fa-check-circle me-1"></i>Fulfilled
                                </span>
                              </template>
                            </td>
                          </tr>
                          <tr v-if="!(selectedUnitSide === 'LH' ? selectedUnitLhParts : selectedUnitRhParts).length">
                            <td colspan="4" class="text-center py-4 text-muted">No {{ selectedUnitSide }} parts in this unit.</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- FLAT ITEMS TABLE (FOR OTHER PROJECTS) -->
            <div v-else class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Size / Specs</th>
                    <th>RH Status (Req / Rec / Pend)</th>
                    <th>LH Status (Req / Rec / Pend)</th>
                    <th style="width: 220px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Receive Stock' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in items" :key="item.id">
                    <td>
                      <div class="fw-bold text-dark fs-6">{{ item.standard_part_no }}</div>
                      <small class="text-muted" v-if="item.item_no">Item #: {{ item.item_no }}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ item.project?.project_code || 'N/A' }}</span></td>
                    <td>
                      <div class="small fw-semibold">{{ item.supplier?.name || item.supplier_name_raw || 'Standard' }}</div>
                    </td>
                    <td><small class="text-muted">{{ item.size || '—' }}</small></td>
                    
                    <!-- RH Breakdown -->
                    <td>
                      <div v-if="item.side_stats?.RH" class="small">
                        <span class="badge bg-primary me-1">Req: {{ item.side_stats.RH.required }}</span>
                        <span class="badge bg-success me-1">Rec: {{ item.side_stats.RH.received }}</span>
                        <span class="badge bg-danger" v-if="item.side_stats.RH.pending > 0">Pend: {{ item.side_stats.RH.pending }}</span>
                        <span class="badge bg-secondary" v-else>Fulfilled</span>
                      </div>
                      <span v-else class="text-muted small">N/A</span>
                    </td>

                    <!-- LH Breakdown -->
                    <td>
                      <div v-if="item.side_stats?.LH" class="small">
                        <span class="badge bg-primary me-1">Req: {{ item.side_stats.LH.required }}</span>
                        <span class="badge bg-success me-1">Rec: {{ item.side_stats.LH.received }}</span>
                        <span class="badge bg-danger" v-if="item.side_stats.LH.pending > 0">Pend: {{ item.side_stats.LH.pending }}</span>
                        <span class="badge bg-secondary" v-else>Fulfilled</span>
                      </div>
                      <span v-else class="text-muted small">N/A</span>
                    </td>

                    <!-- Action Column (Read-Only for Manager) -->
                    <td>
                      <template v-if="authStore.userRole === 'MANAGER'">
                        <span v-if="item.side_stats && Object.values(item.side_stats).some(s => s.pending > 0)" class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                          <i class="fas fa-clock me-1"></i>AWAITING RECEIPT
                        </span>
                        <span v-else class="badge bg-success border px-3 py-2 w-100 d-block">
                          <i class="fas fa-check-circle me-1"></i>STORE RECEIVED
                        </span>
                      </template>
                      <template v-else>
                        <button class="btn btn-sm btn-success fw-bold text-nowrap" @click="openReceiveModal(item)" :disabled="item.side_stats && Object.values(item.side_stats).every(s => s.pending === 0)">
                          <i class="fas fa-plus me-1"></i> Receive Stock
                        </button>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!items.length">
                    <td colspan="7" class="text-center py-5 text-muted">
                      <i class="fas fa-inbox fa-3x mb-3 text-secondary"></i>
                      <p class="mb-0">No spare parts found. Adjust your search or import a BOM file.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-3" v-if="pagination.total > pagination.per_page">
              <span class="text-muted small">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} parts</span>
              <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page === 1" @click="changePage(pagination.current_page - 1)">Previous</button>
                <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page === pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: RECEIPTS HISTORY -->
        <div v-else-if="activeStoreTab === 'history'" class="p-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h5 class="fw-bold mb-1 text-primary">
                <i class="fas fa-history me-2"></i>Store Receipts History ({{ receiptHistoryList.length }} records)
              </h5>
              <p class="text-muted small mb-0">Complete audit log of received deliveries with rollback / undo capability.</p>
            </div>
            <button class="btn btn-sm btn-outline-secondary" @click="fetchReceiptHistory">
              <i class="fas fa-sync me-1"></i> Refresh History
            </button>
          </div>

          <div class="table-responsive bg-white rounded border shadow-xs">
            <table class="table table-hover align-middle border-top mb-0 small">
              <thead class="table-dark">
                <tr>
                  <th>Receipt #</th>
                  <th>Part Number</th>
                  <th>Project</th>
                  <th>Side</th>
                  <th>Qty Received</th>
                  <th>Delivery Note</th>
                  <th>Status</th>
                  <th>Received Date</th>
                  <th style="width: 140px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in receiptHistoryList" :key="'hist_' + item.id">
                  <td><span class="font-monospace fw-bold">#{{ item.id }}</span></td>
                  <td>
                    <div class="fw-bold text-primary">{{ item.bom_item?.standard_part_no || 'N/A' }}</div>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark border">{{ item.bom_item?.project?.name || item.bom_item?.project?.project_code || 'N/A' }}</span>
                  </td>
                  <td>
                    <span class="badge" :class="item.side === 'LH' ? 'bg-info text-dark' : 'bg-primary text-white'">{{ item.side || 'COMMON' }}</span>
                  </td>
                  <td>
                    <span class="badge bg-success fs-6">{{ item.received_quantity }} pcs</span>
                  </td>
                  <td>
                    <span class="font-monospace small">{{ item.receipt?.delivery_note_number || 'N/A' }}</span>
                  </td>
                  <td>
                    <span class="badge bg-secondary text-uppercase">{{ item.status }}</span>
                  </td>
                  <td>
                    <small class="text-muted">{{ new Date(item.created_at).toLocaleString() }}</small>
                  </td>
                  <td>
                    <button v-if="['received', 'sent_to_qc'].includes(item.status) && authStore.userRole !== 'MANAGER'"
                      class="btn btn-xs btn-outline-danger fw-bold text-nowrap"
                      @click="handleRevertReceipt(item)"
                      :disabled="submitting">
                      <i class="fas fa-undo me-1"></i> Revert
                    </button>
                    <span v-else class="text-muted extra-small">Locked</span>
                  </td>
                </tr>
                <tr v-if="!receiptHistoryList.length">
                  <td colspan="9" class="text-center py-5 text-muted">
                    <i class="fas fa-receipt fa-3x mb-3 text-secondary"></i>
                    <p class="mb-0">No receipts history found.</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

    <!-- Receive Stock Modal -->
    <div class="modal fade" id="receiveModal" tabindex="-1" ref="receiveModalRef">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title fw-bold"><i class="fas fa-box-open me-2"></i>Record Part Receipt</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeItem">
            <div class="alert alert-light border mb-3">
              <strong>Part:</strong> {{ activeItem.standard_part_no }}<br>
              <strong>Project:</strong> {{ activeItem.project?.name }} ({{ activeItem.project?.project_code }})
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Select Side</label>
              <select v-model="receiveForm.side" class="form-select">
                <option v-if="activeItem.side_stats?.RH" value="RH">RH (Pending: {{ activeItem.side_stats.RH.pending }})</option>
                <option v-if="activeItem.side_stats?.LH" value="LH">LH (Pending: {{ activeItem.side_stats.LH.pending }})</option>
                <option v-if="activeItem.side_stats?.COMMON" value="COMMON">COMMON (Pending: {{ activeItem.side_stats.COMMON.pending }})</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Received Quantity</label>
              <input type="number" v-model.number="receiveForm.received_quantity" min="1" class="form-control form-control-lg" placeholder="Enter arrived qty..." />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Delivery Note / Invoice #</label>
              <input type="text" v-model="receiveForm.delivery_note_number" class="form-control" placeholder="e.g. DN-98432" />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Remarks (Optional)</label>
              <textarea v-model="receiveForm.remarks" class="form-control" rows="2" placeholder="Condition or notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success fw-bold" @click="submitReceipt" :disabled="submitting">
              <i class="fas fa-check me-1"></i> Confirm & Send to QC Queue
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
const activeStoreTab = ref('pending');
const returnedItemsList = ref([]);
const receiptHistoryList = ref([]);
const items = ref([]);
const projects = ref([]);
const searchQuery = ref('');
const projectId = ref('');
const side = ref('');
const error = ref('');
const successMessage = ref('');
const submitting = ref(false);
const isHierarchical = ref(false);
const hierarchyJigs = ref([]);
const hierarchyProject = ref(null);
const selectedJig = ref(null);
const selectedUnit = ref(null);
const selectedUnitSide = ref('LH');

const openUnitSide = (unit, side) => {
  selectedUnit.value = unit;
  selectedUnitSide.value = side;
};

const activeProjectCompletionPct = computed(() => {
  const found = projects.value.find(p => String(p.id) === String(projectId.value));
  if (found && found.completion_pct !== undefined) {
    return found.completion_pct;
  }
  if (hierarchyJigs.value.length > 0) {
    const totalReq = hierarchyJigs.value.reduce((acc, j) => acc + (j.total_required || 0), 0);
    const totalRec = hierarchyJigs.value.reduce((acc, j) => acc + (j.total_received || 0), 0);
    return totalReq > 0 ? Math.min(100, Math.round((totalRec / totalReq) * 100)) : 0;
  }
  return 0;
});

const activeProjectName = computed(() => {
  let name = '';
  if (hierarchyProject.value) {
    name = hierarchyProject.value.name || hierarchyProject.value.project_code || 'Project';
  } else {
    const found = projects.value.find(p => String(p.id) === String(projectId.value));
    name = found ? (found.name || found.project_code || 'Project') : 'Project';
  }
  return `${name} (${activeProjectCompletionPct.value}% Complete)`;
});

const onProjectChange = async () => {
  selectedJig.value = null;
  selectedUnit.value = null;
  await checkAndLoadStoreData();
};

const checkAndLoadStoreData = async () => {
  try {
    const res = await axios.get(`/api/v1/store/hierarchy?project_id=${projectId.value}`);
    if (res.data.projects) {
      projects.value = res.data.projects;
    }
    if (res.data.is_hierarchical) {
      isHierarchical.value = true;
      hierarchyJigs.value = res.data.jigs || [];
      hierarchyProject.value = res.data.project || null;
      if (res.data.project && !projectId.value) {
        projectId.value = res.data.project.id;
      }
    } else {
      isHierarchical.value = false;
      hierarchyProject.value = null;
      await loadItems();
    }
  } catch (err) {
    isHierarchical.value = false;
    hierarchyProject.value = null;
    await loadItems();
  }
};

const resetHierarchyBreadcrumb = () => {
  selectedJig.value = null;
  selectedUnit.value = null;
};

const goBackHierarchy = () => {
  if (selectedUnit.value) {
    selectedUnit.value = null;
  } else if (selectedJig.value) {
    selectedJig.value = null;
  } else {
    projectId.value = '';
    onProjectChange();
  }
};

const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
  from: 0,
  to: 0,
});

const activeItem = ref(null);
const receiveForm = ref({
  side: 'RH',
  received_quantity: 1,
  delivery_note_number: '',
  remarks: '',
});

const fetchReturnedItems = async () => {
  try {
    const res = await axios.get('/api/v1/store/returned', {
      params: {
        project_id: projectId.value || '',
        side: side.value || '',
        search: searchQuery.value || ''
      }
    });
    returnedItemsList.value = res.data.data || res.data || [];
  } catch (err) {
    error.value = 'Failed to load QC-returned items queue.';
  }
};

const fetchReceiptHistory = async () => {
  try {
    const res = await axios.get('/api/v1/store/history', {
      params: {
        project_id: projectId.value || '',
        side: side.value || '',
        search: searchQuery.value || ''
      }
    });
    receiptHistoryList.value = res.data.data || res.data || [];
  } catch (err) {
    error.value = 'Failed to load store receipts history.';
  }
};

const handleProcessReturned = async (item, action) => {
  if (!confirm(`Are you sure you want to process this returned part as "${action}"?`)) return;
  submitting.value = true;
  try {
    const res = await axios.post(`/api/v1/store/items/${item.id}/process-returned`, {
      action,
      remarks: `Processed via Store Web Desk as ${action}.`
    });
    successMessage.value = res.data.message || `Returned item processed as ${action}.`;
    await fetchReturnedItems();
    if (isHierarchical.value) await checkAndLoadStoreData();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to process returned item.';
  } finally {
    submitting.value = false;
  }
};

const handleRevertReceipt = async (item) => {
  if (!confirm(`Are you sure you want to revert receipt #${item.id} for ${item.bom_item?.standard_part_no}? The quantity will be restored to pending arrival.`)) return;
  submitting.value = true;
  try {
    const res = await axios.post(`/api/v1/store/items/${item.id}/revert`);
    successMessage.value = res.data.message || 'Receipt successfully reverted.';
    await fetchReceiptHistory();
    if (isHierarchical.value) await checkAndLoadStoreData();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to revert receipt.';
  } finally {
    submitting.value = false;
  }
};

const selectedUnitLhParts = computed(() => {
  if (!selectedUnit.value?.parts) return [];
  return selectedUnit.value.parts.filter(item => {
    const st = item.side_stats?.LH || item.side_stats?.COMMON;
    return st && st.pending > 0;
  });
});

const selectedUnitRhParts = computed(() => {
  if (!selectedUnit.value?.parts) return [];
  return selectedUnit.value.parts.filter(item => {
    const st = item.side_stats?.RH || item.side_stats?.COMMON;
    return st && st.pending > 0;
  });
});

let debounceTimer = null;
const onSearchInput = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    pagination.value.current_page = 1;
    loadItems();
  }, 300); // 300ms Debounce
};

const loadItems = async (page = 1) => {
  try {
    const params = new URLSearchParams({ page, per_page: 100 });
    if (searchQuery.value) params.append('search', searchQuery.value);
    if (projectId.value) params.append('project_id', projectId.value);
    if (side.value) params.append('side', side.value);

    const response = await axios.get(`/api/v1/store/items?${params.toString()}`);
    items.value = response.data.items?.data || [];
    projects.value = response.data.projects || [];
    
    pagination.value = {
      current_page: response.data.items.current_page,
      last_page: response.data.items.last_page,
      per_page: response.data.items.per_page,
      total: response.data.items.total,
      from: response.data.items.from,
      to: response.data.items.to,
    };
  } catch (err) {
    error.value = 'Unable to load store items.';
  }
};

const changePage = (page) => {
  pagination.value.current_page = page;
  loadItems(page);
};

const openReceiveModal = (item, preferredSide = null) => {
  activeItem.value = item;
  let defaultSide = preferredSide;
  if (!defaultSide || !item.side_stats?.[defaultSide]) {
    defaultSide = item.side_stats?.LH ? 'LH' : (item.side_stats?.RH ? 'RH' : 'COMMON');
  }
  const pendingQty = item.side_stats?.[defaultSide]?.pending || 1;

  receiveForm.value = {
    side: defaultSide,
    received_quantity: pendingQty > 0 ? pendingQty : 1,
    delivery_note_number: '',
    remarks: '',
  };

  const modalEl = document.getElementById('receiveModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
};

const submitReceipt = async () => {
  if (!receiveForm.value.received_quantity || receiveForm.value.received_quantity < 1) {
    alert('Please enter a valid quantity.');
    return;
  }

  submitting.value = true;
  try {
    const payload = {
      project_id: activeItem.value.project_id,
      supplier_id: activeItem.value.supplier_id,
      delivery_note_number: receiveForm.value.delivery_note_number || 'DN-AUTO',
      remarks: receiveForm.value.remarks,
      items: [{
        bom_item_id: activeItem.value.id,
        side: receiveForm.value.side,
        received_quantity: receiveForm.value.received_quantity,
        remarks: receiveForm.value.remarks,
      }],
    };

    const res = await axios.post('/api/v1/store/receipts', payload);
    successMessage.value = res.data.message || 'Receipt recorded and sent to QC.';
    
    // Close modal
    const modalEl = document.getElementById('receiveModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    if (isHierarchical.value) {
      await checkAndLoadStoreData();
      // Keep selected unit & jig active so user stays in context
      if (selectedJig.value) {
        const updatedJig = hierarchyJigs.value.find(j => j.jig_name === selectedJig.value.jig_name);
        if (updatedJig) {
          selectedJig.value = updatedJig;
          if (selectedUnit.value) {
            const updatedUnit = updatedJig.units.find(u => u.unit_no === selectedUnit.value.unit_no);
            if (updatedUnit) selectedUnit.value = updatedUnit;
          }
        }
      }
    } else {
      loadItems(pagination.value.current_page);
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to record receipt.';
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  checkAndLoadStoreData();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
.shadow-xs {
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.table thead th {
  background-color: #1e293b !important;
  color: #ffffff !important;
  font-weight: 700 !important;
  font-size: 0.82rem !important;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 10px 12px !important;
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
