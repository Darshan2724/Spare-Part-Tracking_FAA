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

      <!-- KPI Interactive Drilldown Modal (Identical Structure to Main Dashboard) -->
      <div v-if="showKpiDrilldownModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;">
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
                    <span class="badge bg-primary px-2.5 py-1 fs-7">{{ kpiDrilldownResult.project_scope || (filters.project_id ? (selectedProjectObj?.project_code || 'Selected Project') : 'All Active Projects') }}</span>
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
                <table class="table table-hover align-middle mb-0 small">
                  <thead class="table-dark sticky-top" style="z-index: 1;">
                    <tr>
                      <th style="width: 11%;">PROJECT</th>
                      <th style="width: 10%;">JIG NO</th>
                      <th style="width: 8%; text-align: center;">UNIT NO</th>
                      <th style="width: 15%;">PART NO</th>
                      <th style="width: 7%; text-align: center;">SIDE</th>
                      <th style="width: 22%;">COMBINED IDENTIFIER</th>
                      <th style="width: 15%;">STATUS</th>
                      <th style="width: 12%; text-align: center;">QUANTITY</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in kpiDrilldownResult.data" :key="row.id">
                      <td>
                        <span class="badge bg-light text-dark border">{{ row.project_code }}</span>
                      </td>
                      <td class="fw-bold text-dark">{{ row.jig_no }}</td>
                      <td class="text-center"><span class="badge bg-secondary-subtle text-dark">{{ row.unit_no }}</span></td>
                      <td>
                        <span class="fw-bold text-primary">{{ row.part_no || row.part_number }}</span>
                        <small v-if="row.supplier && row.supplier !== 'Standard'" class="text-muted d-block extra-small">{{ row.supplier }}</small>
                      </td>
                      <td class="text-center">
                        <span :class="['badge', ['RH', 'RA', 'AR', 'R'].includes(row.side || row.original_side) ? 'badge-rh' : ['LH', 'LA', 'AL', 'L'].includes(row.side || row.original_side) ? 'badge-lh' : 'badge-common']">
                          {{ row.side || row.original_side || row.source_side }}
                        </span>
                      </td>
                      <td>
                        <code class="text-dark bg-light px-1.5 py-0.5 rounded border small">{{ row.combined_identifier }}</code>
                      </td>
                      <td>
                        <span 
                          class="badge"
                          :class="{
                            'bg-primary': row.status === 'ECN Required' || row.status === 'BOM Required',
                            'bg-success': row.status === 'Store Received' || row.status === 'Assembly Completed' || row.substate === 'completed',
                            'bg-dark': row.status === 'Pending Store Receipt' || row.status === 'ECN Required (PENDING)',
                            'bg-warning text-dark': row.status === 'In Store Bay' || row.status === 'In Rework Queue' || row.status === 'In Store' || row.status === 'ECN Required (STORE)',
                            'bg-info text-dark': row.status === 'QC Inspection Queue' || row.status === 'In QC' || row.status === 'SENT_TO_QC',
                            'bg-danger': row.status === 'QC Rejected' || row.substate === 'rejected',
                            'bg-purple text-white': row.status === 'In Paint Queue' || row.status === 'In Paint',
                            'bg-pink text-white': row.status === 'In Assembly Queue' || row.status === 'In Assembly',
                          }"
                        >
                          {{ row.status }}
                        </span>
                      </td>
                      <td class="text-center">
                        <span class="badge bg-dark px-2 py-1 fs-6 fw-bold">{{ row.quantity }}</span>
                      </td>
                    </tr>
                    <tr v-if="!kpiDrilldownResult.data || !kpiDrilldownResult.data.length">
                      <td colspan="8" class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-2 text-secondary opacity-50 d-block"></i>
                        No ECN parts found contributing to this KPI for the selected filters.
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

                <button type="button" class="btn btn-secondary btn-sm" @click="showKpiDrilldownModal = false">Close</button>
              </div>
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

// Drilldown Modal Reactive State (Identical to Main Dashboard)
const showKpiDrilldownModal = ref(false);
const selectedKpiKey = ref('total_parts');
const selectedKpiTitle = ref('Total ECN Parts');
const kpiDrilldownSubstate = ref('all');
const kpiDrilldownSearch = ref('');
const kpiDrilldownSide = ref('');
const kpiDrilldownPage = ref(1);
const kpiDrilldownPerPage = ref(50);
const kpiDrilldownLoading = ref(false);
const kpiExportLoading = ref(false);
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

const openKpiDrilldown = (kpiKey, title, defaultSubstate = 'all') => {
  selectedKpiKey.value = kpiKey;
  selectedKpiTitle.value = title;
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
    params.append('is_ecn', '1');
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
    if (kpiDrilldownSide.value) params.append('side', kpiDrilldownSide.value);
    if (kpiDrilldownSubstate.value && kpiDrilldownSubstate.value !== 'all') {
      params.append('substate', kpiDrilldownSubstate.value);
    }
    if (kpiDrilldownSearch.value) params.append('search', kpiDrilldownSearch.value);
    if (filters.value.date_from) params.append('date_from', filters.value.date_from);
    if (filters.value.date_to) params.append('date_to', filters.value.date_to);
    params.append('page', kpiDrilldownPage.value);
    params.append('per_page', kpiDrilldownPerPage.value);

    const res = await axios.get(`/api/v1/ecn/drilldown?${params.toString()}`);
    kpiDrilldownResult.value = res.data;
  } catch (err) {
    console.error('Failed to load ECN KPI drilldown:', err);
    kpiDrilldownResult.value = {
      kpi: selectedKpiKey.value,
      kpi_type: 'part',
      project_scope: filters.value.project_id ? (selectedProjectObj.value?.project_code || 'Selected Project') : 'All Active Projects',
      is_single_project: !!filters.value.project_id,
      selected_project: selectedProjectObj.value,
      substate: kpiDrilldownSubstate.value,
      total_records: 0,
      total_quantity: 0,
      page: 1,
      per_page: kpiDrilldownPerPage.value,
      total_pages: 1,
      columns: [],
      data: [],
    };
  } finally {
    kpiDrilldownLoading.value = false;
  }
};

const exportKpiExcel = async () => {
  kpiExportLoading.value = true;
  try {
    const params = new URLSearchParams();
    params.append('kpi', selectedKpiKey.value);
    params.append('is_ecn', '1');
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
    if (kpiDrilldownSide.value) params.append('side', kpiDrilldownSide.value);
    if (kpiDrilldownSubstate.value && kpiDrilldownSubstate.value !== 'all') {
      params.append('substate', kpiDrilldownSubstate.value);
    }
    if (kpiDrilldownSearch.value) params.append('search', kpiDrilldownSearch.value);
    if (filters.value.date_from) params.append('date_from', filters.value.date_from);
    if (filters.value.date_to) params.append('date_to', filters.value.date_to);

    const response = await axios.post(`/api/v1/export/drilldown`, Object.fromEntries(params.entries()), {
      responseType: 'blob',
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    const contentDisposition = response.headers['content-disposition'];
    let filename = `SpareTrack_ECN_${selectedKpiKey.value}_${new Date().toISOString().slice(0, 10)}.xlsx`;
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
    console.error('Failed to export ECN KPI Excel:', err);
    alert('Could not generate ECN Excel export.');
  } finally {
    kpiExportLoading.value = false;
  }
};

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

onMounted(async () => {
  await fetchProjects();
  await fetchEcnData();
});
</script>

<style scoped>
.kpi-card-interactive {
  cursor: pointer;
  transition: transform 0.18s ease-in-out, box-shadow 0.18s ease-in-out, filter 0.18s ease-in-out;
}
.kpi-card-interactive:hover {
  transform: translateY(-3px) scale(1.015);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18) !important;
  filter: brightness(1.06);
}
.extra-small {
  font-size: 0.72rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
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
</style>
