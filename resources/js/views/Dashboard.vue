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

      <!-- ROW 2: Parts and Operational Workflow Status Overview (8 Compact Cards) -->
      <div class="row g-2 mb-4">
        <!-- 1. Total Parts -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #4f46e5;" @click="openKpiDrilldown('total_parts', 'Total Parts (BOM Requirements)')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Total Parts</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.total_parts ?? metrics.total_required ?? 0 }}</h3>
              </div>
              <i class="fas fa-cubes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 2. Total Parts Received -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_parts_received', 'Total Parts Received (Store Valid Receipts)')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Total Received</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.total_parts_received ?? metrics.total_received ?? 0 }}</h3>
              </div>
              <i class="fas fa-boxes text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 3. Parts Pending -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-dark text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('parts_pending', 'Parts Pending Store Receipt')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Parts Pending</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_pending ?? metrics.total_pending ?? metrics.pending_store ?? 0 }}</h3>
              </div>
              <i class="fas fa-truck-loading text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 4. Store -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #d97706;" @click="openKpiDrilldown('store', 'Store Bay Inventory (Pending QC Transfer)')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Store</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_store || 0 }}</h3>
              </div>
              <i class="fas fa-warehouse text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 5. QC (with separate Rejected secondary badge) -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('qc', 'QC Bay Parts (Inspection & Rejected)', 'all')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>QC</span>
                  <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Rejected parts in QC" @click.stop="openKpiDrilldown('qc', 'QC Rejected Parts', 'rejected')">
                    Rejected: {{ metrics.qc_rejected || 0 }}
                  </span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_qc ?? metrics.awaiting_qc ?? 0 }}</h3>
              </div>
              <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 6. Rework -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('rework', 'Active Rework Queue')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Rework</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_rework || 0 }}</h3>
              </div>
              <i class="fas fa-tools text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 7. Paint -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('paint', 'Paint Shop Parts')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Paint</span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ metrics.parts_in_paint || 0 }}</h3>
              </div>
              <i class="fas fa-paint-roller text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 8. Assembly (with separate Completed secondary badge) -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #db2777;" @click="openKpiDrilldown('assembly', 'Assembly Bay Parts (Queue & Completed)', 'all')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold d-flex align-items-center gap-1" style="font-size: 0.72rem;">
                  <span>Assembly</span>
                  <span class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem; font-weight: 600;" title="Click to view Completed Assembly parts" @click.stop="openKpiDrilldown('assembly', 'Assembly Completed Parts', 'completed')">
                    Completed: {{ metrics.assembly_completed || 0 }}
                  </span>
                  <i class="fas fa-search-plus extra-small opacity-75"></i>
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

      <!-- PARTS PRIORITY MAP -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <h5 class="card-title fw-bold mb-0">
              <i class="fas fa-layer-group text-primary me-2"></i>Parts Priority Map
              <span class="badge bg-danger ms-2" v-if="(prioritySummary.critical || prioritySummary.CRITICAL || 0) > 0">
                {{ prioritySummary.critical || prioritySummary.CRITICAL }} Urgent Units
              </span>
            </h5>
            <button class="btn btn-xs btn-outline-secondary ms-2" @click="showPriorityTiers = !showPriorityTiers">
              <i class="fas fa-info-circle me-1"></i>{{ showPriorityTiers ? 'Hide Tiers' : 'Show Priority Tiers' }}
            </button>
          </div>
          
          <!-- Priority Filters -->
          <div class="d-flex flex-wrap align-items-center gap-2">
            <input v-model="prioritySearchQuery" type="text" class="form-control form-control-sm" placeholder="Search JIG / Unit / Project..." style="width: 200px;" />
            <select v-model="priorityTierFilter" class="form-select form-select-sm" style="width: 140px;">
              <option value="">All Tiers</option>
              <option value="CRITICAL">Critical (≥70%)</option>
              <option value="HIGH">High (≥40%)</option>
              <option value="MEDIUM">Medium (≥20%)</option>
              <option value="LOW">Low (under 20%)</option>
              <option value="COMPLETE">Completed</option>
            </select>
            <select v-model="priorityProjectFilter" class="form-select form-select-sm" style="width: 170px;" @change="fetchPriorityMap">
              <option value="">All Projects</option>
              <option v-for="proj in priorityProjectsList" :key="proj.id" :value="proj.id">{{ proj.name }} ({{ proj.project_code }})</option>
            </select>
          </div>
        </div>

        <!-- Priority Tier Summary Badges (Collapsible) -->
        <div v-if="showPriorityTiers" class="card-body py-2 px-3 border-bottom bg-light">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-danger px-3 py-2 fs-6">Critical (≥70%): {{ prioritySummary.critical || prioritySummary.CRITICAL || 0 }}</span>
            <span class="badge bg-warning text-dark px-3 py-2 fs-6">High (≥40%): {{ prioritySummary.high || prioritySummary.HIGH || 0 }}</span>
            <span class="badge bg-info text-white px-3 py-2 fs-6">Medium (≥20%): {{ prioritySummary.medium || prioritySummary.MEDIUM || 0 }}</span>
            <span class="badge bg-secondary px-3 py-2 fs-6">Low (&lt;20%): {{ prioritySummary.low || prioritySummary.LOW || 0 }}</span>
            <span class="badge bg-success px-3 py-2 fs-6">Completed: {{ prioritySummary.complete || prioritySummary.COMPLETE || 0 }}</span>
          </div>
        </div>

        <div class="card-body p-3">
          <div class="row g-3">
            <!-- Priority Heatmap Table (12 Cols or 7 Cols when single project filtered) -->
            <div :class="(priorityProjectFilter || filters.project_id) ? 'col-12 col-xl-7' : 'col-12'">
              <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0 small border">
                  <thead class="sticky-top" style="background: #0f172a; color: #fff; z-index: 1;">
                    <tr>
                      <th style="background:#0f172a; color:#fff;">Project</th>
                      <th style="background:#0f172a; color:#fff;">JIG</th>
                      <th style="background:#0f172a; color:#fff;">Unit</th>
                      <th style="background:#0f172a; color:#fff;">REQ / REC</th>
                      <th style="background:#0f172a; color:#fff;">Pending</th>
                      <th style="background:#0f172a; color:#fff;">Completion %</th>
                      <th style="background:#0f172a; color:#fff;">Priority Tier</th>
                      <th style="background:#0f172a; color:#fff;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="unit in filteredPriorityUnits" :key="unit.key">
                      <tr :class="{ 'table-danger fw-semibold': unit.priority_tier === 'CRITICAL' }">
                        <td><span class="badge bg-light text-dark border">{{ unit.project_code }}</span></td>
                        <td class="fw-bold text-primary">{{ unit.jig_name }}</td>
                        <td class="fw-bold">{{ unit.unit_no }}</td>
                        <td>{{ unit.total_required }} / <span class="text-success fw-bold">{{ unit.total_received }}</span></td>
                        <td><span class="fw-bold text-danger">{{ unit.pending_quantity || 0 }}</span></td>
                        <td style="width: 120px;">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 8px;">
                              <div class="progress-bar"
                                :class="{
                                   'bg-danger': unit.priority_tier === 'CRITICAL',
                                   'bg-warning': unit.priority_tier === 'HIGH',
                                   'bg-info': unit.priority_tier === 'MEDIUM',
                                   'bg-secondary': unit.priority_tier === 'LOW',
                                   'bg-success': unit.priority_tier === 'COMPLETE'
                                }"
                                :style="{ width: unit.completion_pct + '%' }">
                              </div>
                            </div>
                            <span class="fw-bold" style="font-size: 0.75rem;">{{ unit.completion_pct }}%</span>
                          </div>
                        </td>
                        <td>
                          <span class="badge" :class="'bg-' + (unit.tier_class || 'secondary')" style="font-size: 0.72rem;">
                            {{ unit.priority_tier }}
                          </span>
                        </td>
                        <td>
                          <button v-if="(unit.pending_parts || []).length"
                            @click="expandedPriorityKey = expandedPriorityKey === unit.key ? null : unit.key"
                            class="btn btn-xs"
                            :class="expandedPriorityKey === unit.key ? 'btn-dark' : 'btn-outline-danger'">
                            <i class="fas" :class="expandedPriorityKey === unit.key ? 'fa-chevron-up' : 'fa-list-ul'"></i>
                            {{ expandedPriorityKey === unit.key ? 'Hide' : 'Parts (' + (unit.pending_parts || []).length + ')' }}
                          </button>
                          <span v-else class="badge bg-success">Done</span>
                        </td>
                      </tr>

                      <!-- Expandable Pending Parts Details -->
                      <tr v-if="expandedPriorityKey === unit.key && (unit.pending_parts || []).length" class="table-light">
                        <td colspan="8" class="p-3">
                          <div class="p-2 bg-white rounded border">
                            <h6 class="fw-bold text-danger mb-2">
                              <i class="fas fa-exclamation-circle me-1"></i>
                              Pending Parts Required to Complete {{ unit.jig_name }} - {{ unit.unit_no }} (Order Urgent):
                            </h6>
                            <div class="table-responsive">
                              <table class="table table-sm table-bordered mb-0 extra-small">
                                <thead class="table-dark">
                                  <tr>
                                    <th>Standard Part No</th>
                                    <th>Side</th>
                                    <th>Required</th>
                                    <th>Received</th>
                                    <th>Pending</th>
                                    <th>Supplier</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <tr v-for="part in (unit.pending_parts || [])" :key="part.id || (part.bom_item_id + '_' + (part.side || 'COMMON'))">
                                    <td class="fw-bold text-primary">{{ part.standard_part_no }}</td>
                                    <td><span class="badge bg-secondary">{{ part.side }}</span></td>
                                    <td>{{ part.required }}</td>
                                    <td class="text-success">{{ part.received }}</td>
                                    <td class="fw-bold text-danger">{{ part.pending }}</td>
                                    <td>{{ part.supplier }}</td>
                                  </tr>
                                </tbody>
                              </table>
                            </div>
                          </div>
                        </td>
                      </tr>
                    </template>
                    <tr v-if="!filteredPriorityUnits.length">
                      <td colspan="8" class="text-center py-4 text-muted">No unit priority data matching the filters.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Right 5 Cols: Top Near-Complete Units Bar Chart (When specific project selected) -->
            <div v-if="priorityProjectFilter || filters.project_id" class="col-12 col-xl-5 border-start">
              <h6 class="fw-bold text-dark mb-2">
                <i class="fas fa-chart-line me-1 text-primary"></i>
                Top Units Closest to Completion (% Ready)
              </h6>
              <div style="height: 330px; position: relative;">
                <canvas ref="priorityChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- DAILY DEPARTMENT PARTS MOVEMENT MATRIX (5-Active-Day Rolling Window) -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <h5 class="card-title fw-bold mb-0">
              <i class="fas fa-calendar-alt me-2 text-primary"></i>Daily Department Parts Movement Matrix
            </h5>
          </div>
          <!-- 5-Active-Day Rolling Window & History Navigation Toolbar -->
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-light text-dark border px-2 py-2 small">
              <i class="fas fa-history text-primary me-1"></i>{{ matrixPagination.displayed_period_label || 'Latest 5 Active Days' }}
            </span>
            <div class="btn-group btn-group-sm">
              <button 
                class="btn btn-outline-secondary" 
                :disabled="!matrixPagination.has_previous_window" 
                @click="navigateMatrixWindow(5)"
                title="View previous 5 active days">
                <i class="fas fa-chevron-left me-1"></i>Previous 5 Days
              </button>
              <button 
                class="btn btn-outline-secondary" 
                :disabled="!matrixPagination.has_next_window" 
                @click="navigateMatrixWindow(-5)"
                title="View next 5 active days">
                Next 5 Days<i class="fas fa-chevron-right ms-1"></i>
              </button>
            </div>
            <select v-model="matrixQuickRange" @change="handleMatrixQuickRangeChange" class="form-select form-select-sm" style="width: 170px;">
              <option value="last_5_active">Last 5 Active Days</option>
              <option value="last_10_days">Last 10 Days</option>
              <option value="this_week">This Week</option>
              <option value="last_week">Last Week</option>
              <option value="this_month">This Month</option>
              <option value="custom">Custom Range</option>
            </select>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0 text-center">
              <thead style="background-color: #0f172a !important; color: #ffffff !important;">
                <tr style="background-color: #0f172a !important;">
                  <th style="width: 15%; color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">DATE</th>
                  <th style="color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">STORE RECEIVED</th>
                  <th style="color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">QC INSPECTED</th>
                  <th style="color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">REWORK QUEUE</th>
                  <th style="color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">PAINT SHOP</th>
                  <th style="color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">ASSEMBLY SHOP</th>
                  <th style="width: 15%; color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;" title="Sum of parts moved across departments on this date (Store + QC + Rework + Paint + Assembly)">DAILY TOTAL PARTS <i class="fas fa-info-circle text-warning ms-1" style="cursor:help;"></i></th>
                  <th style="width: 15%; color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;">INSPECT PARTS</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in dailyMatrix" :key="row.date">
                  <td class="fw-bold bg-light">{{ row.formatted_date }}</td>
                  <td :class="{ 'fw-bold text-success': row.store_received > 0 }">{{ row.store_received || '-' }}</td>
                  <td :class="{ 'fw-bold text-warning': row.qc_inspected > 0 }">{{ row.qc_inspected || '-' }}</td>
                  <td :class="{ 'fw-bold text-danger': row.rework > 0 }">{{ row.rework || '-' }}</td>
                  <td :class="{ 'fw-bold text-purple': row.paint > 0 }">{{ row.paint || '-' }}</td>
                  <td :class="{ 'fw-bold text-primary': row.assembly > 0 }">{{ row.assembly || '-' }}</td>
                  <td class="fw-bold text-dark bg-light">{{ row.total_day }}</td>
                  <td>
                    <button @click="openDatePartsModal(row)" class="btn btn-xs btn-outline-primary">
                      <i class="fas fa-search me-1"></i> View Parts
                    </button>
                  </td>
                </tr>
                <tr v-if="!dailyMatrix.length">
                  <td colspan="8" class="text-center py-4 text-muted">No department movements recorded for the selected active date window.</td>
                </tr>
              </tbody>
              <!-- Master Totals Row (Highlighted Yellow for displayed period) -->
              <tfoot class="table-warning border-top border-dark border-2">
                <tr class="fw-bold fs-6">
                  <td class="text-uppercase">TOTAL FOR DISPLAYED PERIOD</td>
                  <td class="text-success">{{ dailyTotals.store_received || 0 }}</td>
                  <td class="text-warning">{{ dailyTotals.qc_inspected || 0 }}</td>
                  <td class="text-danger">{{ dailyTotals.rework || 0 }}</td>
                  <td class="text-purple">{{ dailyTotals.paint || 0 }}</td>
                  <td class="text-primary">{{ dailyTotals.assembly || 0 }}</td>
                  <td class="bg-warning text-dark border-dark border-2 fs-5">{{ dailyTotals.grand_total || 0 }}</td>
                  <td><small class="text-dark">Displayed Window</small></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- EXECUTIVE MANAGEMENT ANALYTICS: 10 INDUSTRY-GRADE KPIS & CHARTS           -->
      <!-- Strictly located BELOW the Daily Department Parts Movement Matrix          -->
      <!-- ========================================================================= -->

      <!-- ROW 1: PRODUCTION FLOW & VELOCITY (Cards 1, 2, 3) -->
      <div class="row g-3 mb-4">
        <!-- 1. Project Readiness Index (PRI) -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-tachometer-alt text-primary me-2"></i>Project Readiness Index (PRI)
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Measures overall assembly fulfillment progress. Computed as (Final Assembled Parts / Total Required BOM Parts) × 100, alongside stage fulfillment percentages for Store, QC, Paint, and Assembly."></i>
                </h5>
              </div>
              <span class="badge" :class="readinessScore >= 80 ? 'bg-success' : (readinessScore >= 40 ? 'bg-primary' : 'bg-warning text-dark')">
                {{ readinessScore }}% Readiness
              </span>
            </div>
            <div class="card-body p-3 text-center">
              <!-- Circular Progress Dial -->
              <div class="position-relative d-inline-block my-2" style="width: 140px; height: 140px;">
                <svg viewBox="0 0 36 36" class="w-100 h-100" style="transform: rotate(-90deg);">
                  <path class="text-light" stroke-width="3.5" stroke="#f1f5f9" fill="none"
                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                  <path stroke-width="3.5" stroke-dasharray="100, 100"
                    :stroke-dashoffset="100 - readinessScore"
                    :stroke="readinessScore >= 80 ? '#10b981' : '#2563eb'"
                    stroke-linecap="round" fill="none"
                    d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                </svg>
                <div class="position-absolute top-50 start-50 translate-middle text-center">
                  <h2 class="fw-bold mb-0 text-dark">{{ readinessScore }}%</h2>
                  <small class="text-muted extra-small">READINESS</small>
                </div>
              </div>

              <!-- 4-Stage Completion Breakdown -->
              <div class="mt-3 text-start small">
                <div v-for="stg in readinessBreakdown" :key="stg.stage" class="mb-2">
                  <div class="d-flex justify-content-between text-muted extra-small mb-1">
                    <span>{{ stg.stage }}</span>
                    <strong :style="{ color: stg.color }">{{ stg.count }} pcs ({{ stg.percent }}%)</strong>
                  </div>
                  <div class="progress" style="height: 6px;">
                    <div class="progress-bar" :style="{ width: stg.percent + '%', backgroundColor: stg.color }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. Production Conversion Rate (PCR Funnel) -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-filter text-success me-2"></i>Production Conversion Rate (PCR)
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Shows the conversion rate of incoming raw store receipts into finished assembled components through the 4 core factory workstations: (Assembly Completed / Store Received) × 100."></i>
                </h5>
              </div>
              <span class="badge bg-success">{{ conversionData.overall_yield_pct || 0 }}% Yield</span>
            </div>
            <div class="card-body p-3">
              <div class="funnel-container d-flex flex-column gap-2">
                <!-- Step 1: Store Inward -->
                <div class="funnel-step bg-light p-2 rounded border border-primary border-start-4">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-primary small"><i class="fas fa-warehouse me-1"></i>1. Store Intake</span>
                    <strong class="text-dark">{{ conversionData.store_intake || 0 }} pcs</strong>
                  </div>
                </div>
                <div class="text-center extra-small text-muted py-0">
                  <i class="fas fa-arrow-down text-primary"></i> QC Clearance: <strong>{{ conversionData.qc_conversion_pct || 0 }}%</strong>
                </div>

                <!-- Step 2: QC Approved -->
                <div class="funnel-step bg-light p-2 rounded border border-success border-start-4">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-success small"><i class="fas fa-check-circle me-1"></i>2. QC Approved</span>
                    <strong class="text-dark">{{ conversionData.qc_approved || 0 }} pcs</strong>
                  </div>
                </div>
                <div class="text-center extra-small text-muted py-0">
                  <i class="fas fa-arrow-down text-success"></i> Paint Throughput: <strong>{{ conversionData.paint_conversion_pct || 0 }}%</strong>
                </div>

                <!-- Step 3: Surface Painted -->
                <div class="funnel-step bg-light p-2 rounded border border-purple border-start-4">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-purple small"><i class="fas fa-paint-roller me-1"></i>3. Paint Shop</span>
                    <strong class="text-dark">{{ conversionData.paint_completed || 0 }} pcs</strong>
                  </div>
                </div>
                <div class="text-center extra-small text-muted py-0">
                  <i class="fas fa-arrow-down text-purple"></i> Assembly Fulfillment: <strong>{{ conversionData.assembly_conversion_pct || 0 }}%</strong>
                </div>

                <!-- Step 4: Final Assembled -->
                <div class="funnel-step bg-light p-2 rounded border border-teal border-start-4">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-teal small"><i class="fas fa-cogs me-1"></i>4. Final Assembly</span>
                    <strong class="text-teal">{{ conversionData.final_assembled || 0 }} pcs</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. Project Completion Velocity -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-chart-line text-info me-2"></i>Project Completion Velocity
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Tracks daily completed assembly units over time with a 7-day moving average to measure line output velocity and manufacturing acceleration."></i>
                </h5>
              </div>
              <span class="badge bg-info text-white">14-Day Trend</span>
            </div>
            <div class="card-body p-3">
              <div style="height: 220px; position: relative;">
                <canvas ref="velocityChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 2: SUPPLIER ACCURACY & QUALITY COST PRESSURE -->
      <div class="row g-3 mb-4">
        <!-- 4. Supplier Fill Accuracy (RH vs LH Separate) -->
        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-truck-loading text-secondary me-2"></i>Supplier Fill Accuracy (RH vs LH Separate)
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Measures vendor fulfillment precision against BOM specifications, keeping Left-Hand (LH) and Right-Hand (RH) quantities independently evaluated."></i>
                </h5>
              </div>
              <span class="badge bg-light text-dark border">{{ supplierFillAccuracy.length }} Suppliers</span>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                  <thead class="table-dark">
                    <tr>
                      <th>Supplier Name</th>
                      <th>RH Required</th>
                      <th>RH Received</th>
                      <th>RH Fill %</th>
                      <th>LH Required</th>
                      <th>LH Received</th>
                      <th>LH Fill %</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="sup in supplierFillAccuracy" :key="sup.supplier_id">
                      <td class="fw-bold text-primary">{{ sup.supplier_name }}</td>
                      <td>{{ sup.rh_required }}</td>
                      <td class="text-success">{{ sup.rh_received }}</td>
                      <td>
                        <span class="badge" :class="sup.rh_accuracy_pct >= 90 ? 'bg-success' : 'bg-warning text-dark'">
                          {{ sup.rh_accuracy_pct }}%
                        </span>
                      </td>
                      <td>{{ sup.lh_required }}</td>
                      <td class="text-success">{{ sup.lh_received }}</td>
                      <td>
                        <span class="badge" :class="sup.lh_accuracy_pct >= 90 ? 'bg-success' : 'bg-warning text-dark'">
                          {{ sup.lh_accuracy_pct }}%
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!supplierFillAccuracy.length">
                      <td colspan="7" class="text-center py-4 text-muted">No supplier delivery data recorded.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- 5. Quality Cost Pressure Score -->
        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-coins text-warning me-2"></i>Quality Cost Pressure Score
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Measures operational quality pressure on shop-floor throughput caused by scrap rejections (parts in reorder queue) and active rework work-orders."></i>
                </h5>
              </div>
              <span class="badge" :class="qualityCostPressure.severity === 'HIGH' ? 'bg-danger' : (qualityCostPressure.severity === 'MODERATE' ? 'bg-warning text-dark' : 'bg-success')">
                {{ qualityCostPressure.severity || 'LOW' }} RISK
              </span>
            </div>
            <div class="card-body p-3">
              <div class="row g-3 text-center mb-3">
                <div class="col-6">
                  <div class="p-3 bg-light rounded border">
                    <small class="text-muted text-uppercase extra-small">Pressure Score</small>
                    <h3 class="fw-bold text-warning mb-0">{{ qualityCostPressure.pressure_score || 0 }} / 100</h3>
                    <small class="text-muted extra-small">{{ qualityCostPressure.trend || 'Controlled' }}</small>
                  </div>
                </div>
                <div class="col-6">
                  <div class="p-3 bg-light rounded border">
                    <small class="text-muted text-uppercase extra-small">Scrap Rejections</small>
                    <h3 class="fw-bold text-danger mb-0">{{ qualityCostPressure.scrap_rejections || 0 }}</h3>
                    <small class="text-muted extra-small">Pieces in Reorder Queue</small>
                  </div>
                </div>
              </div>

              <div class="p-3 bg-white rounded border">
                <div class="d-flex justify-content-between align-items-center small mb-1">
                  <span class="text-muted">Rework Cycle Impact</span>
                  <strong class="text-dark">{{ qualityCostPressure.rework_events || 0 }} Work Orders</strong>
                </div>
                <div class="progress" style="height: 6px;">
                  <div class="progress-bar bg-warning" :style="{ width: Math.min(100, (qualityCostPressure.pressure_score || 0)) + '%' }"></div>
                </div>
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

    <!-- DAILY PARTS MOVEMENT DRILLDOWN MODAL WITH DEPARTMENT FILTER -->
    <div v-if="selectedDateRow" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5);">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white py-3">
            <h5 class="modal-title fw-bold mb-0">
              <i class="fas fa-calendar-day me-2"></i>Parts Movement Detail — {{ selectedDateRow.formatted_date }}
            </h5>
            <button type="button" class="btn-close btn-close-white" @click="selectedDateRow = null"></button>
          </div>
          <div class="modal-body p-0">
            <!-- Filter Bar inside Modal -->
            <div class="p-3 bg-light border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div class="d-flex align-items-center gap-2">
                <label class="fw-semibold small text-muted mb-0"><i class="fas fa-filter text-primary me-1"></i> Department:</label>
                <select v-model="modalDeptFilter" class="form-select form-select-sm" style="width: 180px;">
                  <option value="">All Departments ({{ (selectedDateRow.parts || []).length }})</option>
                  <option value="STORE">Store Received</option>
                  <option value="QC">QC Inspected</option>
                  <option value="REWORK">Rework Queue</option>
                  <option value="PAINT">Paint Shop</option>
                  <option value="ASSEMBLY">Assembly Shop</option>
                </select>
              </div>
              <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-success btn-sm fw-semibold" @click="handleModalExport('excel')" :disabled="isExportingModal">
                  <i v-if="isExportingModal" class="fas fa-spinner fa-spin me-1"></i>
                  <i v-else class="fas fa-file-excel text-success me-1"></i> Export Excel
                </button>
                <button class="btn btn-outline-danger btn-sm fw-semibold" @click="handleModalExport('pdf')" :disabled="isExportingModal">
                  <i v-if="isExportingModal" class="fas fa-spinner fa-spin me-1"></i>
                  <i v-else class="fas fa-file-pdf text-danger me-1"></i> Export PDF
                </button>
                <button class="btn btn-outline-secondary btn-sm" @click="clearModalFilters"><i class="fas fa-times me-1"></i>Clear Filters</button>
              </div>
              <span class="badge bg-primary fs-6">{{ filteredModalParts.length }} Movements</span>
            </div>

            <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="sticky-top" style="background: #0f172a; color: #fff; top: 0; z-index: 2;">
                  <tr>
                    <th style="background: #0f172a; color:#fff;">Part Number</th>
                    <th style="background: #0f172a; color:#fff;">Project</th>
                    <th style="background: #0f172a; color:#fff;">Side</th>
                    <th style="background: #0f172a; color:#fff;">Qty</th>
                    <th style="background: #0f172a; color:#fff;">Department Movement</th>
                    <th style="background: #0f172a; color:#fff;">Processed By</th>
                    <th style="background: #0f172a; color:#fff;">Date</th>
                    <th style="background: #0f172a; color:#fff;">Time</th>
                  </tr>
                  <!-- Excel-style column filter inputs row -->
                  <tr style="background: #1e293b;">
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.partNo" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 90px;" placeholder="Part No" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.project" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 80px;" placeholder="Project" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <select v-model="modalColFilters.side" class="form-select form-select-sm" style="font-size:0.72rem; min-width: 70px;">
                        <option value="">All</option>
                        <option value="RH">RH</option>
                        <option value="LH">LH</option>
                        <option value="COMMON">COMMON</option>
                      </select>
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.qty" type="number" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 55px;" placeholder="Qty" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.dept" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 100px;" placeholder="Event" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.user" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 80px;" placeholder="User" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.date" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 80px;" placeholder="Date" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.time" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 70px;" placeholder="Time" />
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="part in filteredModalParts" :key="part.id">
                    <td class="fw-bold text-primary">{{ part.standard_part_no }}</td>
                    <td>{{ part.project }}</td>
                    <td><span class="badge bg-secondary">{{ part.side }}</span></td>
                    <td class="fw-bold">{{ part.quantity }}</td>
                    <td><span class="badge bg-info text-dark">{{ part.department_event }}</span></td>
                    <td>{{ part.user }}</td>
                    <td class="text-muted">{{ formatLocalDate(part.created_at_iso || part.date, part.date) }}</td>
                    <td class="text-muted">{{ formatLocalTime(part.created_at_iso || part.time) }}</td>
                  </tr>
                  <tr v-if="!filteredModalParts.length">
                    <td colspan="8" class="text-center py-4 text-muted">No parts match the selected filters.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer bg-light py-2">
            <button type="button" class="btn btn-secondary btn-sm" @click="selectedDateRow = null">Close</button>
          </div>
        </div>
      </div>
    </div>

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
                      <span class="fw-bold text-primary">{{ row.part_no }}</span>
                      <small v-if="row.supplier && row.supplier !== 'Standard'" class="text-muted d-block extra-small">{{ row.supplier }}</small>
                    </td>
                    <td class="text-center">
                      <span :class="['badge', row.side === 'RH' ? 'badge-rh' : row.side === 'LH' ? 'badge-lh' : 'badge-common']">
                        {{ row.side }}
                      </span>
                    </td>
                    <td>
                      <code class="text-dark bg-light px-1.5 py-0.5 rounded border small">{{ row.combined_identifier }}</code>
                    </td>
                    <td>
                      <span 
                        class="badge"
                        :class="{
                          'bg-primary': row.status === 'BOM Required',
                          'bg-success': row.status === 'Store Received' || row.status === 'Assembly Completed',
                          'bg-dark': row.status === 'Pending Store Receipt',
                          'bg-warning text-dark': row.status === 'In Store Bay' || row.status === 'In Rework Queue',
                          'bg-info text-dark': row.status === 'QC Inspection Queue',
                          'bg-danger': row.status === 'QC Rejected',
                          'bg-purple text-white': row.status === 'In Paint Queue',
                          'bg-pink text-white': row.status === 'In Assembly Queue',
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
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const router = useRouter();
const authStore = useAuthStore();

const metrics = ref({});
const statusDistribution = ref({});
const dailyMatrix = ref([]);
const dailyTotals = ref({});
const selectedDateRow = ref(null);
const modalDeptFilter = ref('');
const modalColFilters = ref({ partNo: '', project: '', side: '', qty: '', dept: '', user: '', date: '', time: '' });
const expandedPriorityKey = ref(null);
const projectsProgress = ref([]);

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

    const res = await axios.get(`/api/v1/dashboard/kpi-drilldown?${params.toString()}`);
    kpiDrilldownResult.value = res.data;
  } catch (err) {
    console.error('Failed to load KPI drilldown:', err);
  } finally {
    kpiDrilldownLoading.value = false;
  }
};

const exportKpiExcel = async () => {
  kpiExportLoading.value = true;
  try {
    const params = new URLSearchParams();
    params.append('kpi', selectedKpiKey.value);
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
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

// Rolling Matrix 5-Active-Day Window & History State
const matrixQuickRange = ref('last_5_active');
const matrixWindowOffset = ref(0);
const matrixPagination = ref({
  window_offset: 0,
  window_size: 5,
  total_active_days: 0,
  has_previous_window: false,
  has_next_window: false,
  displayed_period_label: 'Latest 5 Active Days',
});

const loading = ref(false);

const filters = ref({
  project_id: '',
  side: '',
  date_from: '',
  date_to: '',
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

// Retained Core Analytics State
const readinessScore = ref(0);
const readinessBreakdown = ref([]);
const conversionData = ref({});
const velocitySeries = ref([]);
const supplierFillAccuracy = ref([]);
const qualityCostPressure = ref({});

// Computed Filtered Modal Parts (department filter + Excel column filters)
const filteredModalParts = computed(() => {
  if (!selectedDateRow.value?.parts) return [];
  const cf = modalColFilters.value;
  return (selectedDateRow.value.parts || []).filter(p => {
    if (modalDeptFilter.value) {
      const evt = (p.department_event || '').toUpperCase();
      if (modalDeptFilter.value === 'STORE' && !evt.includes('STORE')) return false;
      if (modalDeptFilter.value === 'QC' && !evt.includes('QC')) return false;
      if (modalDeptFilter.value === 'REWORK' && !evt.includes('REWORK')) return false;
      if (modalDeptFilter.value === 'PAINT' && !evt.includes('PAINT')) return false;
      if (modalDeptFilter.value === 'ASSEMBLY' && !evt.includes('ASSEMBLY')) return false;
    }
    if (cf.partNo && !(p.standard_part_no || '').toLowerCase().includes(cf.partNo.toLowerCase())) return false;
    if (cf.project && !(p.project || '').toLowerCase().includes(cf.project.toLowerCase())) return false;
    if (cf.side && (p.side || '').toUpperCase() !== cf.side.toUpperCase()) return false;
    if (cf.qty && String(p.quantity) !== String(cf.qty)) return false;
    if (cf.dept && !(p.department_event || '').toLowerCase().includes(cf.dept.toLowerCase())) return false;
    if (cf.user && !(p.user || '').toLowerCase().includes(cf.user.toLowerCase())) return false;
    if (cf.date) {
      const dateStr = formatLocalDate(p.created_at_iso || p.date, p.date).toLowerCase();
      if (!dateStr.includes(cf.date.toLowerCase())) return false;
    }
    if (cf.time) {
      const timeStr = formatLocalTime(p.created_at_iso || p.time);
      if (!timeStr.includes(cf.time)) return false;
    }
    return true;
  });
});

// Priority Map State & Filters
const priorityUnits = ref([]);
const prioritySummary = ref({ critical: 0, high: 0, medium: 0, low: 0, complete: 0, CRITICAL: 0, HIGH: 0, MEDIUM: 0, LOW: 0, COMPLETE: 0, total_units: 0 });
const priorityChartData = ref({});
const priorityProjectFilter = ref('');
const priorityProjectsList = ref([]);
const showPriorityTiers = ref(false);
const prioritySearchQuery = ref('');
const priorityTierFilter = ref('');

// Computed Filtered Priority Units
const filteredPriorityUnits = computed(() => {
  return (priorityUnits.value || []).filter(unit => {
    if (priorityTierFilter.value && unit.priority_tier !== priorityTierFilter.value) {
      return false;
    }
    if (prioritySearchQuery.value) {
      const q = prioritySearchQuery.value.toLowerCase();
      const jig = (unit.jig_name || '').toLowerCase();
      const unitNo = (unit.unit_no || '').toLowerCase();
      const projCode = (unit.project_code || '').toLowerCase();
      const projName = (unit.project_name || '').toLowerCase();
      if (!jig.includes(q) && !unitNo.includes(q) && !projCode.includes(q) && !projName.includes(q)) {
        return false;
      }
    }
    return true;
  });
});

// Chart canvas refs & instances
const priorityChartCanvas = ref(null);
const velocityChartCanvas = ref(null);
const topProjectsChartCanvas = ref(null);
const healthChartCanvas = ref(null);

const topProjectsData = ref({ labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 });
const healthDistribution = ref({ counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} });

let priorityChart = null;
let velocityChart = null;
let topProjectsChart = null;
let healthChart = null;

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  expandedJigs.value = {};
  expandedUnits.value = {};
  unitPartSearch.value = {};
  unitSideTab.value = {};
  unitPartPage.value = {};
  matrixWindowOffset.value = 0;
  fetchData();
};

const fetchProjectHierarchy = async () => {
  if (!filters.value.project_id) {
    hierarchyData.value = { jigs: [], project: null, canonical_summary: null };
    return;
  }
  hierarchyLoading.value = true;
  try {
    const params = new URLSearchParams({
      project_id: filters.value.project_id,
      side: filters.value.side || '',
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

const navigateMatrixWindow = (step) => {
  matrixWindowOffset.value = Math.max(0, matrixWindowOffset.value + step);
  fetchDailyMovement();
};

const handleMatrixQuickRangeChange = () => {
  matrixWindowOffset.value = 0;
  fetchDailyMovement();
};

const openDatePartsModal = (row) => {
  selectedDateRow.value = row;
  modalDeptFilter.value = '';
  modalColFilters.value = { partNo: '', project: '', side: '', qty: '', dept: '', user: '', date: '', time: '' };
};

const clearModalFilters = () => {
  modalDeptFilter.value = '';
  modalColFilters.value = { partNo: '', project: '', side: '', qty: '', dept: '', user: '', date: '', time: '' };
};

const formatLocalDate = (isoString, fallbackDate = '') => {
  if (isoString) {
    try {
      const d = new Date(isoString);
      if (!isNaN(d.getTime())) {
        const day = String(d.getDate()).padStart(2, '0');
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = months[d.getMonth()];
        const year = String(d.getFullYear()).slice(-2);
        return `${day}-${month}-${year}`;
      }
    } catch (e) {}
  }
  return fallbackDate || selectedDateRow.value?.formatted_date || '';
};

const formatLocalTime = (isoString) => {
  if (!isoString) return '';
  try {
    const d = new Date(isoString);
    if (isNaN(d.getTime())) return isoString;
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  } catch (e) {
    return isoString;
  }
};

const isExportingModal = ref(false);

const handleModalExport = async (format) => {
  if (isExportingModal.value) return;
  isExportingModal.value = true;
  try {
    const dateLabel = selectedDateRow.value?.formatted_date || selectedDateRow.value?.date || 'Movement_Log';
    const payload = {
      format,
      date_label: dateLabel,
      department: modalDeptFilter.value || 'All Departments',
      column_filters: modalColFilters.value,
      items: filteredModalParts.value.map(p => ({
        standard_part_no: p.standard_part_no,
        project: p.project,
        side: p.side,
        quantity: p.quantity,
        department_event: p.department_event,
        user: p.user,
        date: formatLocalDate(p.created_at_iso || p.date, p.date),
        time: formatLocalTime(p.created_at_iso || p.time),
      })),
    };

    const res = await axios.post('/api/v1/export/movement', payload, { responseType: 'blob' });
    const blob = new Blob([res.data], {
      type: format === 'excel' 
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' 
        : 'application/pdf'
    });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `SpareTrack_PartsMovement_${dateLabel}.${format === 'excel' ? 'xlsx' : 'pdf'}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (e) {
    console.error('Modal export failed:', e);
    alert('Failed to export Parts Movement Detail.');
  } finally {
    isExportingModal.value = false;
  }
};

const fetchDailyMovement = async () => {
  try {
    const params = new URLSearchParams(
      Object.entries(filters.value).filter(([_, v]) => v !== '')
    );
    params.append('quick_range', matrixQuickRange.value);
    params.append('window_offset', matrixWindowOffset.value);
    params.append('window_size', 5);

    const movRes = await axios.get(`/api/v1/dashboard/daily-movement?${params.toString()}`);
    dailyMatrix.value = movRes.data.matrix || [];
    dailyTotals.value = movRes.data.totals || {};
    matrixPagination.value = movRes.data.pagination || {};
  } catch (err) {
    console.error('Failed to load daily movement matrix:', err);
  }
};

const fetchData = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams(
      Object.entries(filters.value).filter(([_, v]) => v !== '')
    ).toString();

    const [sumRes, anaRes] = await Promise.all([
      axios.get(`/api/v1/dashboard/summary?${params}`),
      axios.get(`/api/v1/dashboard/analytics?${params}`),
    ]);

    metrics.value = sumRes.data.summary || {};
    statusDistribution.value = sumRes.data.status_distribution || {};
    projectsProgress.value = sumRes.data.projects_progress || [];
    topProjectsData.value = sumRes.data.top_projects || { labels: [], names: [], percentages: [], required: [], received: [], pending: [], projects: [], total_active_incomplete: 0 };
    healthDistribution.value = sumRes.data.health_distribution || { counts: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, percentages: { near_completion: 0, on_track: 0, at_risk: 0, delayed: 0 }, total_active: 0, details: {} };

    if (filters.value.project_id) {
      await fetchProjectHierarchy();
    } else {
      await fetchDailyMovement();

      // Retained Core Analytics Data
      const ana = anaRes.data || {};
      readinessScore.value = ana.project_readiness_index?.readiness_score || 0;
      readinessBreakdown.value = ana.project_readiness_index?.breakdown || [];
      conversionData.value = ana.conversion_rate || {};
      velocitySeries.value = ana.velocity_series || [];
      supplierFillAccuracy.value = ana.supplier_fill_accuracy || [];
      qualityCostPressure.value = ana.quality_cost_pressure || {};

      await fetchPriorityMap();

      await nextTick();
      renderAnalyticsCharts();
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

const fetchPriorityMap = async () => {
  try {
    const res = await axios.get(`/api/v1/dashboard/priority-map?project_id=${priorityProjectFilter.value}`);
    priorityUnits.value = res.data.units || [];
    prioritySummary.value = res.data.summary_counts || { critical: 0, high: 0, medium: 0, low: 0, complete: 0 };
    if (res.data.projects?.length) {
      priorityProjectsList.value = res.data.projects;
    }
    priorityChartData.value = res.data.chart || {};
    await nextTick();
    renderPriorityChart();
  } catch (err) {
    console.error('Failed to load priority map:', err);
  }
};

const renderPriorityChart = () => {
  try {
    if (priorityChart) {
      priorityChart.destroy();
      priorityChart = null;
    }
    if (priorityChartCanvas.value && priorityChartData.value.labels?.length) {
      const colors = (priorityChartData.value.tiers || []).map(t => {
        if (t === 'CRITICAL') return '#ef4444';
        if (t === 'HIGH') return '#f59e0b';
        if (t === 'MEDIUM') return '#06b6d4';
        if (t === 'LOW') return '#64748b';
        return '#10b981';
      });

      priorityChart = new Chart(priorityChartCanvas.value, {
        type: 'bar',
        data: {
          labels: priorityChartData.value.labels.map((u, i) => `${priorityChartData.value.jigs?.[i] || ''} ${u}`),
          datasets: [{
            label: 'Completion %',
            data: priorityChartData.value.percentages,
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
                label: (ctx) => ` Completion: ${ctx.raw}%`
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
    console.warn('Error rendering priority chart:', e);
  }
};

const renderAnalyticsCharts = () => {
  try {
    // 1. Velocity Line Chart
    if (velocityChart) {
      velocityChart.destroy();
      velocityChart = null;
    }
    if (velocityChartCanvas.value && velocitySeries.value.length) {
      velocityChart = new Chart(velocityChartCanvas.value, {
        type: 'line',
        data: {
          labels: velocitySeries.value.map(v => v.label),
          datasets: [
            {
              label: 'Daily Completed (pcs)',
              data: velocitySeries.value.map(v => v.completed),
              borderColor: '#2563eb',
              backgroundColor: 'rgba(37, 99, 235, 0.1)',
              fill: true,
              tension: 0.3,
              pointRadius: 3,
            },
            {
              label: '7-Day Moving Avg',
              data: velocitySeries.value.map(v => v.moving_avg),
              borderColor: '#f59e0b',
              borderDash: [5, 5],
              fill: false,
              tension: 0.3,
              pointRadius: 0,
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12 } }
          },
          scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Pieces' } }
          }
        }
      });
    }
  } catch (e) {
    console.warn('Error rendering analytics charts:', e);
  }
};

onMounted(async () => {
  await fetchInitialProjectsList();
  fetchData();
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
</style>
