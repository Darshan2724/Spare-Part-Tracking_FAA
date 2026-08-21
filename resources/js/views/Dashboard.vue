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

      <!-- Global Filters & Action Bar -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 bg-light rounded">
          <div class="row g-2 align-items-center justify-content-between">
            <div class="col-12 col-md-5">
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
            <div class="col-12 col-md-5 d-flex justify-content-md-end align-items-end gap-2 mt-2 mt-md-0">
              <button @click="exportDashboard('excel')" class="btn btn-outline-success btn-sm fw-semibold" :disabled="!!isExporting">
                <i v-if="isExporting === 'excel'" class="fas fa-spinner fa-spin me-1"></i>
                <i v-else class="fas fa-file-excel text-success me-1"></i> Export Excel
              </button>
              <button @click="exportDashboard('pdf')" class="btn btn-outline-danger btn-sm fw-semibold" :disabled="!!isExporting">
                <i v-if="isExporting === 'pdf'" class="fas fa-spinner fa-spin me-1"></i>
                <i v-else class="fas fa-file-pdf text-danger me-1"></i> Export PDF
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
          <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Active Projects</div>
                <h2 class="fw-bold mb-0 display-6">{{ metrics.active_projects ?? metrics.total_projects ?? 0 }}</h2>
              </div>
              <i class="fas fa-folder-open fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 2. Completed Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0d9488;">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Completed Projects</div>
                <h2 class="fw-bold mb-0 display-6">{{ metrics.completed_projects || 0 }}</h2>
              </div>
              <i class="fas fa-check-circle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <!-- 3. Delayed Projects -->
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm bg-danger text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Delayed Projects</div>
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

      <!-- ROW 2: Parts and Operational Workflow Status Overview (8 Compact Cards) -->
      <div class="row g-2 mb-4">
        <!-- 1. Total Parts -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #4f46e5;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Total Parts</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.total_parts ?? metrics.total_required ?? 0 }}</h3>
              </div>
              <i class="fas fa-cubes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 2. Total Parts Received -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-success text-white h-100">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Total Parts Received</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.total_parts_received ?? metrics.total_received ?? 0 }}</h3>
              </div>
              <i class="fas fa-boxes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 3. Parts Pending -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-dark text-white h-100">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Parts Pending</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_pending ?? metrics.total_pending ?? metrics.pending_store ?? 0 }}</h3>
              </div>
              <i class="fas fa-truck-loading text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 4. Store -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #d97706;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Store</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_store || 0 }}</h3>
              </div>
              <i class="fas fa-warehouse text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 5. QC (with separate Rejected secondary badge) -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0284c7;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>QC</span>
                  <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Rejected in QC">
                    Rejected: {{ metrics.qc_rejected || 0 }}
                  </span>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_qc ?? metrics.awaiting_qc ?? 0 }}</h3>
              </div>
              <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 6. Rework -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #ea580c;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Rework</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_rework || 0 }}</h3>
              </div>
              <i class="fas fa-tools text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 7. Paint -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #7c3aed;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Paint</div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_paint || 0 }}</h3>
              </div>
              <i class="fas fa-paint-roller text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 8. Assembly (with separate Completed secondary badge) -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #db2777;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Assembly</span>
                  <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Completed in Assembly">
                    Completed: {{ metrics.assembly_completed || 0 }}
                  </span>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_assembly || metrics.assembly_ready || 0 }}</h3>
              </div>
              <i class="fas fa-cogs text-white-50 fs-5"></i>
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
        <!-- Hierarchy Section Header -->
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                <i class="fas fa-sitemap text-primary me-2"></i>
                Project Hierarchy Drill-Down
                <span class="badge bg-light text-dark border ms-2 px-2 py-1 fs-7">
                  {{ hierarchyData.jigs?.length || 0 }} Jigs • {{ hierarchyData.completed_jigs || 0 }} Completed
                </span>
              </h5>
              <small class="text-muted">Level 3: Jig Breakdown &bull; Level 4: Unit Breakdown (LH/RH) &bull; Level 5: Part Inventory Table</small>
            </div>
            <div class="d-flex gap-2">
              <button @click="expandAllJigs" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-expand-arrows-alt me-1"></i> Expand All Jigs
              </button>
              <button @click="collapseAllJigs" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-compress-arrows-alt me-1"></i> Collapse All Jigs
              </button>
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

        <!-- Empty State -->
        <div v-else-if="!hierarchyData.jigs || !hierarchyData.jigs.length" class="card border-0 shadow-sm p-5 text-center bg-white">
          <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
          <h6 class="fw-bold text-dark">No BOM Hierarchy Configured</h6>
          <p class="text-muted small mb-0">This project does not have any BOM items or Jigs imported yet.</p>
        </div>

        <!-- LEVEL 3: JIGS LIST (Incomplete Jigs at top, Completed Jigs at bottom) -->
        <div v-else class="d-flex flex-column gap-3">
          <div 
            v-for="jig in hierarchyData.jigs" 
            :key="jig.jig_name"
            class="card border-0 shadow-sm overflow-hidden"
            :class="{ 'border border-2 border-success': jig.is_complete }"
          >
            <!-- JIG CARD HEADER (Level 3) -->
            <div 
              class="card-header py-3 d-flex flex-wrap justify-content-between align-items-center gap-2 cursor-pointer select-none"
              :style="{ backgroundColor: jig.is_complete ? '#ecfdf5' : '#ffffff' }"
              @click="toggleJigExpand(jig.jig_name)"
            >
              <div class="d-flex align-items-center gap-3">
                <button 
                  type="button"
                  class="btn btn-sm btn-light border rounded-circle p-0 d-flex align-items-center justify-content-center" 
                  style="width: 28px; height: 28px;"
                  @click.stop="toggleJigExpand(jig.jig_name)"
                >
                  <i class="fas" :class="expandedJigs[jig.jig_name] ? 'fa-chevron-down text-primary' : 'fa-chevron-right text-muted'"></i>
                </button>
                <div>
                  <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold mb-0 text-dark fs-6">{{ jig.jig_name }}</h6>
                    <span v-if="jig.is_complete" class="badge bg-success px-2 py-1 fs-7">
                      <i class="fas fa-check-circle me-1"></i> Completed
                    </span>
                    <span v-else class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-7">
                      <i class="fas fa-cogs me-1"></i> In Progress
                    </span>
                  </div>
                  <small class="text-muted">{{ jig.total_units || jig.units?.length || 0 }} Units &bull; {{ jig.total_parts || 0 }} Parts</small>
                </div>
              </div>

              <!-- Jig Metrics Pills & Completion Bar -->
              <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="d-none d-md-flex align-items-center gap-2 small">
                  <span class="badge bg-light text-dark border" title="Total Required">Req: <strong>{{ jig.total_required }}</strong></span>
                  <span class="badge bg-success-subtle text-success border border-success-subtle" title="Total Received">Rec: <strong>{{ jig.total_received }}</strong></span>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="Total Pending">Pend: <strong>{{ jig.total_pending }}</strong></span>
                  <span class="badge bg-purple text-white" title="Assembled Parts">Asm: <strong>{{ jig.metrics?.assembly_completed || 0 }}</strong></span>
                </div>

                <div class="d-flex align-items-center gap-2" style="min-width: 140px;">
                  <div class="progress flex-grow-1" style="height: 8px;">
                    <div 
                      class="progress-bar" 
                      :class="jig.is_complete ? 'bg-success' : 'bg-primary'"
                      :style="{ width: `${jig.completion_pct || 0}%` }"
                    ></div>
                  </div>
                  <span class="small fw-bold text-nowrap" :class="jig.is_complete ? 'text-success' : 'text-primary'">
                    {{ jig.completion_pct || 0 }}%
                  </span>
                </div>
              </div>
            </div>

            <!-- JIG BODY: LEVEL 4 UNITS -->
            <div v-if="expandedJigs[jig.jig_name]" class="card-body bg-light p-3">
              <div class="d-flex flex-column gap-3">
                <div 
                  v-for="unit in jig.units" 
                  :key="unit.unit_no"
                  class="card border-0 shadow-sm bg-white overflow-hidden"
                  :class="{ 'border border-2 border-success': unit.is_complete }"
                >
                  <!-- UNIT HEADER (Level 4) -->
                  <div 
                    class="card-header py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2"
                    :style="{ backgroundColor: unit.is_complete ? '#f0fdf4' : '#f8fafc' }"
                  >
                    <div class="d-flex align-items-center gap-2">
                      <i class="fas fa-cube" :class="unit.is_complete ? 'text-success' : 'text-primary'"></i>
                      <span class="fw-bold text-dark">{{ unit.unit_no }}</span>
                      <span v-if="unit.is_complete" class="badge bg-success px-2 py-1 fs-7">
                        <i class="fas fa-check-double me-1"></i> Unit Complete (LH &amp; RH)
                      </span>
                      <span v-else class="badge bg-secondary-subtle text-secondary border px-2 py-1 fs-7">
                        Incomplete
                      </span>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                      <div class="d-flex align-items-center gap-2" style="width: 120px;">
                        <div class="progress flex-grow-1" style="height: 6px;">
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
                        @click="toggleUnitExpand(`${jig.jig_name}_${unit.unit_no}`)" 
                        class="btn btn-xs"
                        :class="expandedUnits[`${jig.jig_name}_${unit.unit_no}`] ? 'btn-primary' : 'btn-outline-primary'"
                      >
                        <i class="fas" :class="expandedUnits[`${jig.jig_name}_${unit.unit_no}`] ? 'fa-table me-1' : 'fa-list me-1'"></i>
                        {{ expandedUnits[`${jig.jig_name}_${unit.unit_no}`] ? 'Hide Parts' : 'View Parts Inventory' }}
                      </button>
                    </div>
                  </div>

                  <!-- UNIT BODY: SIDE-BY-SIDE LH & RH (Level 4) -->
                  <div class="card-body p-3">
                    <div class="row g-3">
                      <!-- LEFT HAND (LH) SIDE CARD -->
                      <div class="col-12 col-md-6">
                        <div 
                          class="p-3 rounded border h-100 position-relative"
                          :class="unit.sides?.LH?.is_complete ? 'border-success bg-success-subtle bg-opacity-10' : 'border-light bg-light'"
                        >
                          <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-uppercase small d-flex align-items-center gap-1">
                              <i class="fas fa-arrow-left text-primary"></i> Left Hand (LH)
                            </span>
                            <span v-if="unit.sides?.LH?.is_complete" class="badge bg-success">
                              <i class="fas fa-check me-1"></i> LH Complete
                            </span>
                            <span v-else class="badge bg-warning text-dark">
                              LH In Progress ({{ unit.sides?.LH?.completion_pct || 0 }}%)
                            </span>
                          </div>

                          <div class="row g-2 text-center my-2">
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Required</small>
                                <span class="fw-bold fs-6 text-dark">{{ unit.sides?.LH?.total_required || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Received</small>
                                <span class="fw-bold fs-6 text-success">{{ unit.sides?.LH?.total_received || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Pending</small>
                                <span class="fw-bold fs-6 text-danger">{{ unit.sides?.LH?.pending_quantity || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Assembled</small>
                                <span class="fw-bold fs-6 text-purple">{{ unit.sides?.LH?.assembly_completed || 0 }}</span>
                              </div>
                            </div>
                          </div>

                          <div class="mt-2">
                            <div class="progress" style="height: 6px;">
                              <div 
                                class="progress-bar bg-success" 
                                :style="{ width: `${unit.sides?.LH?.completion_pct || 0}%` }"
                              ></div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- RIGHT HAND (RH) SIDE CARD -->
                      <div class="col-12 col-md-6">
                        <div 
                          class="p-3 rounded border h-100 position-relative"
                          :class="unit.sides?.RH?.is_complete ? 'border-success bg-success-subtle bg-opacity-10' : 'border-light bg-light'"
                        >
                          <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-uppercase small d-flex align-items-center gap-1">
                              <i class="fas fa-arrow-right text-primary"></i> Right Hand (RH)
                            </span>
                            <span v-if="unit.sides?.RH?.is_complete" class="badge bg-success">
                              <i class="fas fa-check me-1"></i> RH Complete
                            </span>
                            <span v-else class="badge bg-warning text-dark">
                              RH In Progress ({{ unit.sides?.RH?.completion_pct || 0 }}%)
                            </span>
                          </div>

                          <div class="row g-2 text-center my-2">
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Required</small>
                                <span class="fw-bold fs-6 text-dark">{{ unit.sides?.RH?.total_required || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Received</small>
                                <span class="fw-bold fs-6 text-success">{{ unit.sides?.RH?.total_received || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Pending</small>
                                <span class="fw-bold fs-6 text-danger">{{ unit.sides?.RH?.pending_quantity || 0 }}</span>
                              </div>
                            </div>
                            <div class="col-3">
                              <div class="bg-white p-2 rounded border">
                                <small class="text-muted extra-small d-block text-uppercase">Assembled</small>
                                <span class="fw-bold fs-6 text-purple">{{ unit.sides?.RH?.assembly_completed || 0 }}</span>
                              </div>
                            </div>
                          </div>

                          <div class="mt-2">
                            <div class="progress" style="height: 6px;">
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
                    <div v-if="expandedUnits[`${jig.jig_name}_${unit.unit_no}`]" class="mt-3 pt-3 border-top">
                      <!-- Table Filter Tabs & Search -->
                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div class="btn-group btn-group-sm" role="group">
                          <button 
                            type="button" 
                            class="btn" 
                            :class="(unitSideTab[`${jig.jig_name}_${unit.unit_no}`] || 'ALL') === 'ALL' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setUnitSideTab(`${jig.jig_name}_${unit.unit_no}`, 'ALL')"
                          >
                            All Parts ({{ (unit.sides?.LH?.parts?.length || 0) + (unit.sides?.RH?.parts?.length || 0) }})
                          </button>
                          <button 
                            type="button" 
                            class="btn" 
                            :class="unitSideTab[`${jig.jig_name}_${unit.unit_no}`] === 'LH' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setUnitSideTab(`${jig.jig_name}_${unit.unit_no}`, 'LH')"
                          >
                            LH Parts ({{ unit.sides?.LH?.parts?.length || 0 }})
                          </button>
                          <button 
                            type="button" 
                            class="btn" 
                            :class="unitSideTab[`${jig.jig_name}_${unit.unit_no}`] === 'RH' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setUnitSideTab(`${jig.jig_name}_${unit.unit_no}`, 'RH')"
                          >
                            RH Parts ({{ unit.sides?.RH?.parts?.length || 0 }})
                          </button>
                        </div>

                        <div class="input-group input-group-sm" style="max-width: 250px;">
                          <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                          <input 
                            type="text" 
                            class="form-control" 
                            placeholder="Search part #, item, supplier..."
                            v-model="unitPartSearch[`${jig.jig_name}_${unit.unit_no}`]"
                          />
                        </div>
                      </div>

                      <!-- Part Inventory Table -->
                      <div class="table-responsive rounded border">
                        <table class="table table-sm table-hover align-middle mb-0 text-center">
                          <thead style="background-color: #0f172a !important; color: #ffffff !important;">
                            <tr>
                              <th style="width: 45px; color: #fff; background-color: #0f172a;">#</th>
                              <th style="color: #fff; background-color: #0f172a; text-align: left;">PART NUMBER</th>
                              <th style="color: #fff; background-color: #0f172a; text-align: left;">ITEM NO</th>
                              <th style="color: #fff; background-color: #0f172a; text-align: left;">SUPPLIER</th>
                              <th style="color: #fff; background-color: #0f172a;">SIDE</th>
                              <th style="color: #fff; background-color: #0f172a;">REQ</th>
                              <th style="color: #fff; background-color: #0f172a;">REC</th>
                              <th style="color: #fff; background-color: #0f172a;">PEND</th>
                              <th style="color: #fff; background-color: #0f172a;">WORKSTATION STATUS</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(part, pIdx) in getPaginatedUnitParts(unit, `${jig.jig_name}_${unit.unit_no}`)" :key="part.id || pIdx">
                              <td class="text-muted extra-small">{{ ((unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1) - 1) * 10 + pIdx + 1 }}</td>
                              <td class="text-start fw-bold text-dark">{{ part.standard_part_no }}</td>
                              <td class="text-start text-muted extra-small">{{ part.item_no || '—' }}</td>
                              <td class="text-start text-muted extra-small">{{ part.supplier || '—' }}</td>
                              <td>
                                <span class="badge" :class="part.side === 'LH' ? 'bg-primary-subtle text-primary' : (part.side === 'RH' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-secondary-subtle text-secondary')">
                                  {{ part.side }}
                                </span>
                              </td>
                              <td class="fw-bold text-dark">{{ part.required_qty }}</td>
                              <td class="fw-bold text-success">{{ part.received_qty }}</td>
                              <td class="fw-bold" :class="part.pending_qty > 0 ? 'text-danger' : 'text-muted'">{{ part.pending_qty }}</td>
                              <td>
                                <span 
                                  class="badge px-2 py-1"
                                  :class="getStatusBadgeClass(part.status_badge)"
                                >
                                  {{ part.status_badge }}
                                </span>
                              </td>
                            </tr>
                            <tr v-if="!getFilteredUnitParts(unit, `${jig.jig_name}_${unit.unit_no}`).length">
                              <td colspan="9" class="text-center py-3 text-muted">No parts match the selected filter.</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>

                      <!-- Pagination -->
                      <div v-if="getUnitPartsTotalPages(unit, `${jig.jig_name}_${unit.unit_no}`) > 1" class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">
                          Showing page {{ unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1 }} of {{ getUnitPartsTotalPages(unit, `${jig.jig_name}_${unit.unit_no}`) }}
                        </small>
                        <div class="btn-group btn-group-sm">
                          <button 
                            class="btn btn-outline-secondary"
                            :disabled="(unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1) <= 1"
                            @click="setUnitPartPage(`${jig.jig_name}_${unit.unit_no}`, (unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1) - 1)"
                          >
                            Prev
                          </button>
                          <button 
                            class="btn btn-outline-secondary"
                            :disabled="(unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1) >= getUnitPartsTotalPages(unit, `${jig.jig_name}_${unit.unit_no}`)"
                            @click="setUnitPartPage(`${jig.jig_name}_${unit.unit_no}`, (unitPartPage[`${jig.jig_name}_${unit.unit_no}`] || 1) + 1)"
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
      <!-- END OF OPTION B (5-Level Hierarchy Drill-Down) -->
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const router = useRouter();
const authStore = useAuthStore();

const metrics = ref({});
const statusDistribution = ref({});
const projectsProgress = ref([]);

const loading = ref(false);
let autoRefreshTimer = null;

const filters = ref({
  project_id: '',
});

const isExporting = ref(null);

const exportDashboard = async (format) => {
  if (isExporting.value) return;
  isExporting.value = format;
  try {
    const payload = {
      format,
      project_id: filters.value.project_id || '',
    };
    const res = await axios.post('/api/v1/export/dashboard', payload, { responseType: 'blob' });
    const blob = new Blob([res.data], {
      type: format === 'excel'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/pdf'
    });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    let projectLabel = 'All_Active_Projects';
    if (filters.value.project_id) {
      const p = activeProjectsList.value.find(p => p.id === filters.value.project_id) || completedProjectsList.value.find(p => p.id === filters.value.project_id);
      projectLabel = (p?.project_code || p?.name || 'Project_' + filters.value.project_id).replace(/[^A-Za-z0-9_\-]/g, '_');
    }
    const timestamp = new Date().toISOString().slice(0, 10);
    a.download = `SpareTrack_Dashboard_${projectLabel}_${timestamp}.${format === 'excel' ? 'xlsx' : 'pdf'}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    console.error('Dashboard export error:', err);
    alert(`Unable to generate ${format === 'excel' ? 'Excel' : 'PDF'} report. Please try again.`);
  } finally {
    isExporting.value = null;
  }
};

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

// Chart canvas refs & instances
const topProjectsChartCanvas = ref(null);
const healthChartCanvas = ref(null);

const topProjectsData = ref({ labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 });
const healthDistribution = ref({ counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} });

let topProjectsChart = null;
let healthChart = null;

const fetchProjectHierarchy = async () => {
  if (!filters.value.project_id) {
    hierarchyData.value = { jigs: [], project: null, canonical_summary: null };
    return;
  }
  hierarchyLoading.value = true;
  try {
    const params = new URLSearchParams({
      project_id: filters.value.project_id,
    });
    const res = await axios.get(`/api/v1/dashboard/project-hierarchy?${params.toString()}`);
    hierarchyData.value = res.data || {};
    if (res.data.active_projects?.length) activeProjectsList.value = res.data.active_projects;
    if (res.data.completed_projects?.length) completedProjectsList.value = res.data.completed_projects;

    // Auto-expand the first incomplete Jig by default for immediate convenience
    if (res.data.jigs && res.data.jigs.length > 0) {
      const firstIncomplete = res.data.jigs.find(j => !j.is_complete) || res.data.jigs[0];
      if (firstIncomplete && !Object.keys(expandedJigs.value).length) {
        expandedJigs.value[firstIncomplete.jig_name] = true;
        if (firstIncomplete.units && firstIncomplete.units.length > 0) {
          const firstUnitKey = `${firstIncomplete.jig_name}_${firstIncomplete.units[0].unit_no}`;
          expandedUnits.value[firstUnitKey] = true;
        }
      }
    }
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
  expandedJigs.value = {};
  expandedUnits.value = {};
  unitPartSearch.value = {};
  unitSideTab.value = {};
  unitPartPage.value = {};
  fetchData();
  if (filters.value.project_id) {
    fetchProjectHierarchy();
  }
};

const toggleJigExpand = (jigName) => {
  expandedJigs.value[jigName] = !expandedJigs.value[jigName];
};

const expandAllJigs = () => {
  if (!hierarchyData.value.jigs) return;
  hierarchyData.value.jigs.forEach(j => {
    expandedJigs.value[j.jig_name] = true;
  });
};

const collapseAllJigs = () => {
  expandedJigs.value = {};
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
  if (tab === 'LH') {
    parts = unit.sides?.LH?.parts || [];
  } else if (tab === 'RH') {
    parts = unit.sides?.RH?.parts || [];
  } else {
    const lh = unit.sides?.LH?.parts || [];
    const rh = unit.sides?.RH?.parts || [];
    parts = [...lh, ...rh];
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

const fetchData = async () => {
  if (loading.value) return;
  loading.value = true;
  try {
    const params = new URLSearchParams(
      Object.entries(filters.value).filter(([_, v]) => v !== '')
    ).toString();

    const sumRes = await axios.get(`/api/v1/dashboard/summary?${params}`);

    metrics.value = sumRes.data.summary || {};
    statusDistribution.value = sumRes.data.status_distribution || {};
    projectsProgress.value = sumRes.data.projects_progress || [];
    topProjectsData.value = sumRes.data.top_projects || { labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 };
    healthDistribution.value = sumRes.data.health_distribution || { counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} };

    if (filters.value.project_id) {
      await fetchProjectHierarchy();
    } else {
      await nextTick();
      renderTopProjectsChart();
      renderHealthChart();
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
  await fetchData();

  autoRefreshTimer = setInterval(() => {
    if (document.visibilityState === 'visible' && !loading.value) {
      fetchData();
    }
  }, 3000);
});

onUnmounted(() => {
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
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
.border-teal {
  border-color: #0d9488 !important;
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
</style>
