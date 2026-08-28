<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <!-- Header Topbar -->
      <div class="py-3 px-4 bg-white border-bottom shadow-sm rounded mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
          <div class="p-2 rounded-3 text-white" style="background-color: #b45309;">
            <i class="fas fa-exchange-alt fa-lg"></i>
          </div>
          <div>
            <h4 class="mb-0 fw-bold text-dark">ECN Engineering Change Dashboard &amp; Analytics</h4>
            <small class="text-muted">Strict ECN Isolation &bull; Canonical Calculations &bull; Department Queues &bull; Project Level Drilldown</small>
          </div>
        </div>
        <div class="d-flex gap-2">
          <router-link v-if="['ADMIN', 'MANAGER'].includes(authStore.userRole)" :to="{ name: 'bom-import' }" class="btn btn-primary btn-sm text-nowrap">
            <i class="fas fa-file-upload me-1"></i> Import BOM / ECN
          </router-link>
          <button @click="fetchEcnData" class="btn btn-outline-primary btn-sm text-nowrap" :disabled="loading">
            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh Live Data
          </button>
        </div>
      </div>

      <!-- Global Filters Bar -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 bg-light rounded">
          <div class="row g-2 align-items-center">
            <!-- Project Filter -->
            <div class="col-12 col-md-3">
              <label class="form-label small fw-bold mb-1"><i class="fas fa-filter me-1 text-primary"></i> Project</label>
              <select v-model="filters.project_id" @change="onProjectChange" class="form-select form-select-sm">
                <option value="">All Active Projects</option>
                <optgroup v-if="activeProjects.length" label="Active Projects">
                  <option v-for="proj in activeProjects" :key="proj.id" :value="proj.id">
                    {{ proj.project_code || proj.name }} - {{ proj.name }}
                  </option>
                </optgroup>
                <optgroup v-if="completedProjects.length" label="Completed Projects">
                  <option v-for="proj in completedProjects" :key="proj.id" :value="proj.id">
                    ✓ {{ proj.project_code || proj.name }} - {{ proj.name }} (Completed)
                  </option>
                </optgroup>
              </select>
            </div>

            <!-- Side Filter -->
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Side</label>
              <select v-model="filters.side" @change="fetchEcnData" class="form-select form-select-sm">
                <option value="">All Sides (LH / RH)</option>
                <option value="LH">LH (Left Hand Family)</option>
                <option value="RH">RH (Right Hand Family)</option>
              </select>
            </div>

            <!-- Date Range From -->
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">From Date</label>
              <input type="date" v-model="filters.date_from" @change="fetchEcnData" class="form-control form-control-sm" />
            </div>

            <!-- Date Range To -->
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">To Date</label>
              <input type="date" v-model="filters.date_to" @change="fetchEcnData" class="form-control form-control-sm" />
            </div>

            <!-- Reset Button -->
            <div class="col-12 col-md-3 d-flex align-items-end gap-2">
              <button @click="resetFilters" class="btn btn-outline-secondary btn-sm w-100 mt-2 mt-md-0" title="Reset Filters">
                <i class="fas fa-undo me-1"></i> Reset Filters
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 1: Case A - All Active Projects Portfolio View (3 Prominent Cards) -->
      <div v-if="!filters.project_id" class="row g-3 mb-3">
        <!-- 1. Active ECN Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Active ECN Projects</div>
                <h2 class="fw-bold mb-0 display-6">{{ activeProjects.length || 0 }}</h2>
              </div>
              <i class="fas fa-folder-open fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 2. Total ECN Requirements -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0d9488;">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Total ECN Parts</div>
                <h2 class="fw-bold mb-0 display-6">{{ summary.total_parts || 0 }}</h2>
              </div>
              <i class="fas fa-cubes fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 3. ECN Completion Status -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #b45309;">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">ECN Assembly Completion</div>
                <h2 class="fw-bold mb-0 display-6">{{ completionPct }}%</h2>
              </div>
              <i class="fas fa-check-circle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 1: Case B - Selected Project Level 2 Banner (When single project is selected) -->
      <div v-else class="card border-0 shadow-sm mb-3" style="background: linear-gradient(135deg, #451a03 0%, #78350f 100%); color: #ffffff;">
        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 bg-white bg-opacity-10 rounded-3">
              <i class="fas fa-bolt fa-2x" style="color: #fbbf24;"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-warning text-dark px-2 py-1 fs-7">
                  {{ selectedProjectObj?.project_code || 'PROJECT' }}
                </span>
                <h4 class="fw-bold mb-0 text-white">{{ selectedProjectObj?.name || 'Selected Project' }}</h4>
                <span 
                  class="badge px-2 py-1 fs-7"
                  :class="completionPct === 100 ? 'bg-success' : 'bg-warning text-dark'"
                >
                  {{ completionPct === 100 ? '✓ ECN COMPLETED' : 'ECN ACTIVE' }}
                </span>
              </div>
              <small class="text-white-50">
                {{ hierarchyNodes.length || 0 }} ECN Batches &bull; {{ summary.total_parts || 0 }} Total ECN Parts &bull; Assembled: {{ summary.assembly_completed || 0 }} pcs
              </small>
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <div class="text-end me-2">
              <div class="text-white-50 extra-small text-uppercase">ECN Completion Progress</div>
              <div class="fw-bold fs-5 text-white">{{ completionPct }}%</div>
            </div>
            <button @click="resetFilters" class="btn btn-outline-light btn-sm">
              <i class="fas fa-times me-1"></i> Clear / Portfolio View
            </button>
          </div>
        </div>
      </div>

      <!-- ROW 2: 8 ECN KPI CARDS (Completely Isolated ECN Ledger) -->
      <div class="row g-2 mb-4">
        <!-- 1. Total Parts -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #4f46e5;" @click="openKpiDrilldown('total_parts', 'Total ECN Parts')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Total ECN Parts</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.total_parts || 0 }}</h3>
              </div>
              <i class="fas fa-cubes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 2. Total Received -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_parts_received', 'Total ECN Parts Received')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Total Received</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.total_received || 0 }}</h3>
              </div>
              <i class="fas fa-boxes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 3. Parts Pending -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-dark text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('parts_pending', 'ECN Parts Pending Intake')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Parts Pending</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_pending || 0 }}</h3>
              </div>
              <i class="fas fa-truck-loading text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 4. Store (renamed from Store Bay) -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #d97706;" @click="openKpiDrilldown('store', 'ECN Parts in Store')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Store</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_store || 0 }}</h3>
              </div>
              <i class="fas fa-warehouse text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 5. QC (renamed from QC Bay; Main: Active Inspection, Sub: Rejected) -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('qc', 'ECN Parts in Quality Control', 'all')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">QC</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_qc || 0 }}</h3>
                <span 
                  class="badge px-1.5 py-0.5 rounded extra-small mt-1 text-nowrap"
                  style="background-color: rgba(254, 226, 226, 0.95); color: #991b1b; cursor: pointer;"
                  @click.stop="openKpiDrilldown('qc', 'ECN Parts - QC Rejected', 'rejected')"
                >
                  Rejected: <strong>{{ summary.qc_rejected || 0 }}</strong>
                </span>
              </div>
              <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 6. Rework -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('rework', 'ECN Parts in Rework')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Rework</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_rework || 0 }}</h3>
              </div>
              <i class="fas fa-tools text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 7. Paint -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('paint', 'ECN Parts in Paint Shop')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Paint</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_paint || 0 }}</h3>
              </div>
              <i class="fas fa-paint-roller text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 8. Assembly (includes Assembly Queue & Assembly Completed; standalone Completed card removed) -->
        <div class="col-6 col-sm-6 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #db2777;" @click="openKpiDrilldown('assembly', 'ECN Parts in Assembly Bay', 'all')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Assembly</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_assembly || 0 }}</h3>
                <span 
                  class="badge px-1.5 py-0.5 rounded extra-small mt-1 text-nowrap"
                  style="background-color: rgba(220, 252, 231, 0.95); color: #166534; cursor: pointer;"
                  @click.stop="openKpiDrilldown('assembly', 'ECN Parts - Assembly Completed', 'completed')"
                >
                  Completed: <strong>{{ summary.assembly_completed || 0 }}</strong>
                </span>
              </div>
              <i class="fas fa-cogs text-white-50 fs-5"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Hierarchy Section (When Project is Selected) -->
      <div v-if="filters.project_id" class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="fw-bold mb-0 text-dark">
              <i class="fas fa-sitemap me-2" style="color: #b45309;"></i>
              ECN Hierarchy Breakdown: {{ selectedProjectObj?.project_code }} - {{ selectedProjectObj?.name }}
            </h5>
            <small class="text-muted">Breakdown by ECN Number &rarr; Jig &rarr; Unit &rarr; Parts</small>
          </div>
        </div>

        <div class="card-body p-3">
          <div v-if="!hierarchyNodes.length" class="text-center py-4 text-muted small">
            <i class="fas fa-info-circle fa-2x mb-2 d-block text-secondary opacity-50"></i>
            No ECN requirements registered for this project yet.
          </div>

          <!-- ECN Nodes Hierarchy -->
          <div v-else class="d-flex flex-column gap-3" id="ecnAccordion">
            <div v-for="(ecnNode, eIdx) in hierarchyNodes" :key="ecnNode.ecn_number" class="card border-0 shadow-sm overflow-hidden">
              <!-- ECN Node Header -->
              <div 
                class="card-header bg-white py-2.5 px-3 d-flex justify-content-between align-items-center cursor-pointer border-bottom"
                style="cursor: pointer;"
                @click="toggleEcnExpand(ecnNode.ecn_number)"
              >
                <div class="d-flex align-items-center gap-2">
                  <i class="fas" :class="expandedEcns[ecnNode.ecn_number] ? 'fa-chevron-down text-primary' : 'fa-chevron-right text-muted'"></i>
                  <span class="badge px-2 py-1" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                    ⚡ {{ ecnNode.ecn_number }}
                  </span>
                  <span class="text-muted small">({{ ecnNode.jigs?.length || 0 }} Jigs)</span>
                </div>
                <div class="d-flex align-items-center gap-2 small">
                  <span class="badge bg-light text-dark border">Total Req: <strong>{{ ecnNode.total_required }}</strong></span>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">Received: <strong>{{ ecnNode.total_received }}</strong></span>
                </div>
              </div>

              <!-- Jigs Under ECN -->
              <div v-if="expandedEcns[ecnNode.ecn_number]" class="card-body p-3 bg-light d-flex flex-column gap-2">
                <div v-for="jig in ecnNode.jigs" :key="jig.jig_no" class="card border shadow-xs overflow-hidden">
                  <!-- JIG HEADER -->
                  <div 
                    class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center cursor-pointer"
                    style="cursor: pointer;"
                    @click="toggleJigExpand(`${ecnNode.ecn_number}_${jig.jig_no}`)"
                  >
                    <div class="d-flex align-items-center gap-3">
                      <button 
                        type="button"
                        class="btn btn-sm btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center"
                        style="width: 26px; height: 26px;"
                        @click.stop="toggleJigExpand(`${ecnNode.ecn_number}_${jig.jig_no}`)"
                      >
                        <i class="fas" :class="expandedJigs[`${ecnNode.ecn_number}_${jig.jig_no}`] ? 'fa-chevron-down text-primary' : 'fa-chevron-right text-muted'"></i>
                      </button>
                      <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold text-dark"><i class="fas fa-layer-group text-primary me-1.5"></i>JIG: {{ jig.jig_no }}</span>
                        <span class="badge bg-light text-dark border">{{ jig.units?.length || 0 }} Units</span>
                      </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 small">
                      <span class="badge bg-light text-dark border">Req: <strong>{{ jig.total_required }}</strong></span>
                      <span class="badge bg-success-subtle text-success border border-success-subtle">Rec: <strong>{{ jig.total_received }}</strong></span>
                    </div>
                  </div>

                  <!-- JIG BODY: UNITS & PARTS (Hidden until Jig arrow is clicked) -->
                  <div v-if="expandedJigs[`${ecnNode.ecn_number}_${jig.jig_no}`]" class="card-body p-3 bg-light">
                    <div class="row g-2">
                      <div v-for="unit in jig.units" :key="unit.unit_no" class="col-12 col-md-6 col-xl-4">
                        <div class="p-2 border rounded bg-white shadow-xs">
                          <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong class="text-dark small">Unit {{ unit.unit_no }}</strong>
                            <span class="badge bg-secondary" style="font-size: 0.7rem;">{{ unit.parts?.length || 0 }} Parts</span>
                          </div>
                          <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0 extra-small">
                              <thead>
                                <tr class="border-bottom text-muted">
                                  <th>Part No</th>
                                  <th>Side</th>
                                  <th class="text-end">Req</th>
                                  <th class="text-end">Rec</th>
                                  <th>Status</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr v-for="p in unit.parts" :key="p.id">
                                  <td class="font-monospace fw-bold">{{ p.part_no }}</td>
                                  <td>
                                    <span :class="['badge', ['RH', 'RA', 'AR', 'R'].includes(p.side || p.original_side) ? 'bg-primary' : ['LH', 'LA', 'AL', 'L'].includes(p.side || p.original_side) ? 'bg-info text-dark' : 'bg-secondary']">
                                      {{ p.side || p.original_side || p.side_display }}
                                    </span>
                                  </td>
                                  <td class="text-end">{{ p.required_qty }}</td>
                                  <td class="text-end fw-bold text-success">{{ p.received_qty }}</td>
                                  <td>
                                    <span class="badge" :class="p.current_state === 'ASSEMBLY_COMPLETED' ? 'bg-success' : 'bg-warning text-dark'" style="font-size: 0.65rem;">
                                      {{ p.current_state }}
                                    </span>
                                  </td>
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
        </div>
      </div>

      <!-- KPI Interactive Drilldown Modal -->
      <div v-if="showDrilldownModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-2.5 px-3" style="background-color: #1e293b;">
              <div class="d-flex align-items-center gap-2">
                <i class="fas fa-list-alt text-warning"></i>
                <h6 class="modal-title fw-bold mb-0">{{ drilldownTitle }}</h6>
                <span class="badge bg-secondary ms-2">{{ drilldownData.length }} Records (Qty: {{ drilldownTotalQty }})</span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <button @click="exportEcnExcel" class="btn btn-success btn-sm text-nowrap" :disabled="exportingExcel">
                  <i class="fas fa-file-excel me-1"></i> {{ exportingExcel ? 'Exporting...' : 'Export Excel' }}
                </button>
                <button type="button" class="btn-close btn-close-white" @click="closeDrilldownModal"></button>
              </div>
            </div>
            <div class="modal-body p-3">
              <!-- Substate Filters Bar (for QC and Assembly) -->
              <div v-if="['qc', 'assembly'].includes(activeKpiKey)" class="d-flex gap-2 mb-3 pb-2 border-bottom flex-wrap">
                <template v-if="activeKpiKey === 'qc'">
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'all' ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="setSubstate('all')"
                  >
                    All QC Items
                  </button>
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'inspection' ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="setSubstate('inspection')"
                  >
                    🔬 Active Inspection Queue ({{ summary.parts_in_qc || 0 }})
                  </button>
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'rejected' ? 'btn-danger text-white' : 'btn-outline-danger'"
                    @click="setSubstate('rejected')"
                  >
                    ❌ QC Rejected ({{ summary.qc_rejected || 0 }})
                  </button>
                </template>
                <template v-if="activeKpiKey === 'assembly'">
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'all' ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="setSubstate('all')"
                  >
                    All Assembly Items
                  </button>
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'queue' ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="setSubstate('queue')"
                  >
                    ⚙️ In Assembly Queue ({{ summary.parts_in_assembly || 0 }})
                  </button>
                  <button 
                    class="btn btn-sm"
                    :class="activeSubstate === 'completed' ? 'btn-success text-white' : 'btn-outline-success'"
                    @click="setSubstate('completed')"
                  >
                    🏁 Assembly Completed ({{ summary.assembly_completed || 0 }})
                  </button>
                </template>
              </div>

              <!-- Search Bar -->
              <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <input
                  type="text"
                  v-model="drilldownSearch"
                  placeholder="Search ECN part, number, jig, unit..."
                  class="form-control form-control-sm"
                  style="max-width: 300px;"
                />
                <span class="text-muted small">
                  Showing <strong>{{ filteredDrilldownData.length }}</strong> matching parts
                </span>
              </div>

              <div class="table-responsive border rounded">
                <table class="table table-sm table-hover align-middle mb-0 small">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>ECN NO.</th>
                      <th>Project</th>
                      <th>Jig / Unit</th>
                      <th>Part Number</th>
                      <th>Side</th>
                      <th>Combined Identifier</th>
                      <th class="text-end">Quantity</th>
                      <th>Status / Stage</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, idx) in filteredDrilldownData" :key="item.id || idx">
                      <td class="text-muted">{{ idx + 1 }}</td>
                      <td>
                        <span class="badge" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                          ⚡ {{ item.ecn_number || item.ecn_no || 'ECN' }}
                        </span>
                      </td>
                      <td class="fw-semibold">{{ item.project_code || '—' }}</td>
                      <td>{{ item.jig_no }} / Unit {{ item.unit_no }}</td>
                      <td class="font-monospace fw-bold text-dark">{{ item.part_no || item.part_number }}</td>
                      <td>
                        <span :class="['badge', ['RH', 'RA', 'AR', 'R'].includes(item.side || item.original_side) ? 'bg-primary' : ['LH', 'LA', 'AL', 'L'].includes(item.side || item.original_side) ? 'bg-info text-dark' : 'bg-secondary']">
                          {{ item.side || item.original_side || item.source_side }}
                        </span>
                      </td>
                      <td>
                        <code class="text-dark bg-light px-1.5 py-0.5 rounded border small">
                          {{ item.combined_identifier }}
                        </code>
                      </td>
                      <td class="text-end fw-bold fs-6 text-primary">{{ item.quantity || 0 }}</td>
                      <td>
                        <span 
                          class="badge" 
                          :class="item.substate === 'rejected' ? 'bg-danger text-white' : item.substate === 'completed' ? 'bg-success text-white' : 'bg-warning text-dark'"
                        >
                          {{ item.status || 'ACTIVE' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!filteredDrilldownData.length">
                      <td colspan="9" class="text-center py-4 text-muted">No records match drilldown filters for this KPI.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3 justify-content-between">
              <span class="text-muted small">
                Scope: <strong>{{ filters.project_id ? (selectedProjectObj?.project_code || 'Single Project') : 'All Active Projects' }}</strong>
              </span>
              <button type="button" class="btn btn-secondary btn-sm" @click="closeDrilldownModal">Close</button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();

const loading = ref(false);
const exportingExcel = ref(false);
const allProjects = ref([]);
const activeProjects = ref([]);
const completedProjects = ref([]);
const hierarchyNodes = ref([]);

const filters = ref({
  project_id: '',
  side: '',
  date_from: '',
  date_to: '',
});

const summary = ref({
  total_parts: 0,
  total_received: 0,
  parts_pending: 0,
  parts_in_store: 0,
  parts_in_qc: 0,
  qc_rejected: 0,
  parts_in_rework: 0,
  parts_in_paint: 0,
  parts_in_assembly: 0,
  assembly_completed: 0,
});

// Drilldown Modal
const showDrilldownModal = ref(false);
const drilldownTitle = ref('');
const activeKpiKey = ref('total_parts');
const activeSubstate = ref('all');
const drilldownData = ref([]);
const drilldownSearch = ref('');

const expandedEcns = ref({});
const expandedJigs = ref({});

const toggleEcnExpand = (ecnNumber) => {
  expandedEcns.value[ecnNumber] = !expandedEcns.value[ecnNumber];
};

const toggleJigExpand = (jigKey) => {
  expandedJigs.value[jigKey] = !expandedJigs.value[jigKey];
};

const selectedProjectObj = computed(() => {
  if (!filters.value.project_id) return null;
  return allProjects.value.find(p => p.id === Number(filters.value.project_id));
});

const completionPct = computed(() => {
  const req = summary.value.total_parts || 0;
  const comp = summary.value.assembly_completed || 0;
  if (req === 0) return 0;
  return Math.min(100, Math.round((comp / req) * 100));
});

const filteredDrilldownData = computed(() => {
  if (!drilldownSearch.value) return drilldownData.value;
  const q = drilldownSearch.value.toLowerCase().trim();
  return drilldownData.value.filter(item => {
    return (item.part_no || item.part_number || '').toLowerCase().includes(q) ||
           (item.ecn_number || '').toLowerCase().includes(q) ||
           (item.jig_no || '').toLowerCase().includes(q) ||
           (item.unit_no || '').toLowerCase().includes(q) ||
           (item.combined_identifier || '').toLowerCase().includes(q) ||
           (item.status || '').toLowerCase().includes(q);
  });
});

const drilldownTotalQty = computed(() => {
  return filteredDrilldownData.value.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
});

const fetchProjects = async () => {
  try {
    const res = await axios.get('/api/v1/dashboard/summary');
    const projectsList = res.data.projects_progress || [];
    allProjects.value = projectsList;
    activeProjects.value = projectsList.filter(p => p.status === 'active' || !p.status);
    completedProjects.value = projectsList.filter(p => p.status === 'completed');
  } catch (err) {
    console.error('Failed to load projects list:', err);
  }
};

const fetchEcnData = async () => {
  loading.value = true;
  try {
    const params = {
      project_id: filters.value.project_id || undefined,
      side: filters.value.side || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
    };

    const summaryRes = await axios.get('/api/v1/ecn/summary', { params });
    summary.value = summaryRes.data.summary || {
      total_parts: 0,
      total_received: 0,
      parts_pending: 0,
      parts_in_store: 0,
      parts_in_qc: 0,
      qc_rejected: 0,
      parts_in_rework: 0,
      parts_in_paint: 0,
      parts_in_assembly: 0,
      assembly_completed: 0,
    };

    if (filters.value.project_id) {
      const hierRes = await axios.get('/api/v1/ecn/hierarchy', { params: { project_id: filters.value.project_id, side: filters.value.side } });
      hierarchyNodes.value = hierRes.data.ecn_nodes || [];
    } else {
      hierarchyNodes.value = [];
    }
  } catch (err) {
    console.error('Failed to fetch ECN dashboard data:', err);
  } finally {
    loading.value = false;
  }
};

const onProjectChange = () => {
  expandedEcns.value = {};
  expandedJigs.value = {};
  fetchEcnData();
};

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  expandedEcns.value = {};
  expandedJigs.value = {};
  fetchEcnData();
};

const openKpiDrilldown = async (kpiKey, title, substate = 'all') => {
  activeKpiKey.value = kpiKey;
  activeSubstate.value = substate;
  drilldownTitle.value = title;
  drilldownSearch.value = '';
  showDrilldownModal.value = true;
  await fetchDrilldownData();
};

const setSubstate = async (substate) => {
  activeSubstate.value = substate;
  await fetchDrilldownData();
};

const fetchDrilldownData = async () => {
  try {
    const params = {
      kpi: activeKpiKey.value,
      substate: activeSubstate.value,
      project_id: filters.value.project_id || undefined,
      side: filters.value.side || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
      per_page: 500,
    };
    const res = await axios.get('/api/v1/ecn/drilldown', { params });
    drilldownData.value = res.data.data || res.data.items || [];
  } catch (err) {
    console.error('Failed to fetch drilldown data:', err);
    drilldownData.value = [];
  }
};

const exportEcnExcel = async () => {
  exportingExcel.value = true;
  try {
    const payload = {
      kpi: activeKpiKey.value,
      substate: activeSubstate.value,
      project_id: filters.value.project_id || undefined,
      side: filters.value.side || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
    };
    const response = await axios.post('/api/v1/export/drilldown', payload, {
      responseType: 'blob',
    });
    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `SpareTrack_ECN_${activeKpiKey.value}_${Date.now()}.xlsx`);
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch (err) {
    console.error('Failed to export ECN Excel:', err);
  } finally {
    exportingExcel.value = false;
  }
};

const closeDrilldownModal = () => {
  showDrilldownModal.value = false;
};

onMounted(async () => {
  await fetchProjects();
  await fetchEcnData();
});
</script>

<style scoped>
.kpi-card-interactive {
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-card-interactive:hover {
  transform: translateY(-2px);
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
.extra-small {
  font-size: 0.72rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
</style>
