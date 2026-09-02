<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm mb-4">
        <!-- Header Topbar with Subtabs -->
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h3 class="fw-bold mb-1">
                <i class="fas fa-truck-moving me-2 text-primary"></i>Supplier Management &amp; Performance Analytics
              </h3>
              <p class="text-muted mb-0">
                Enterprise supplier master catalog, project allocation workload, rework defect tracking, and mathematical performance rankings.
              </p>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-outline-primary btn-sm fw-semibold shadow-xs" :disabled="loading" @click="refreshAllData">
                <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh Analytics
              </button>
              <button v-if="canManageSuppliers" class="btn btn-primary btn-sm fw-semibold shadow-xs" @click="openCreateModal">
                <i class="fas fa-plus me-1"></i> Add New Supplier
              </button>
            </div>
          </div>

          <!-- Top Tabs Navigation -->
          <ul class="nav nav-tabs card-header-tabs mt-3 border-0">
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'overview', 'text-secondary': activeTab !== 'overview' }"
                @click="activeTab = 'overview'; fetchOverviewKpis();"
              >
                <i class="fas fa-chart-pie me-1.5"></i>Overview &amp; KPIs
              </button>
            </li>
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-success border-bottom border-2 text-success': activeTab === 'rankings', 'text-secondary': activeTab !== 'rankings' }"
                @click="activeTab = 'rankings'; fetchRankings();"
              >
                <i class="fas fa-trophy me-1.5"></i>Supplier Rankings
              </button>
            </li>
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-danger border-bottom border-2 text-danger': activeTab === 'rework', 'text-secondary': activeTab !== 'rework' }"
                @click="activeTab = 'rework'; fetchReworkAnalysis();"
              >
                <i class="fas fa-tools me-1.5"></i>Rework Quality Analysis
              </button>
            </li>
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-info border-bottom border-2 text-info': activeTab === 'history', 'text-secondary': activeTab !== 'history' }"
                @click="activeTab = 'history'; fetchHistory();"
              >
                <i class="fas fa-history me-1.5"></i>Allocation Audit History
              </button>
            </li>
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-warning border-bottom border-2 text-dark': activeTab === 'load', 'text-secondary': activeTab !== 'load' }"
                @click="activeTab = 'load'; fetchSupplierLoad();"
              >
                <i class="fas fa-balance-scale me-1.5 text-warning"></i>Supplier Load KPI
              </button>
            </li>
            <li class="nav-item">
              <button 
                class="nav-link px-3.5 py-2 fw-bold" 
                :class="{ 'active border-dark border-bottom border-2 text-dark': activeTab === 'master', 'text-secondary': activeTab !== 'master' }"
                @click="activeTab = 'master'; fetchMasterSuppliers();"
              >
                <i class="fas fa-building me-1.5"></i>Supplier Master Directory
              </button>
            </li>
          </ul>
        </div>

        <div class="card-body p-4">
          <!-- Alert Feedback Messages -->
          <div v-if="error" class="alert alert-danger alert-dismissible fade show shadow-xs mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
            <button type="button" class="btn-close" @click="error = ''"></button>
          </div>
          <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs mb-3">
            <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
            <button type="button" class="btn-close" @click="successMessage = ''"></button>
          </div>

          <!-- Global Analytics Filter Bar -->
          <div class="card border-0 shadow-xs bg-light rounded mb-4">
            <div class="card-body p-3">
              <div class="row g-2 align-items-center">
                <div class="col-12 col-md-3">
                  <label class="form-label extra-small fw-bold text-muted mb-1 text-uppercase">Project Filter</label>
                  <select v-model="filters.project_id" @change="onFilterChange" class="form-select form-select-sm shadow-xs">
                    <option value="">All Projects</option>
                    <option v-for="p in projectList" :key="p.id" :value="p.id">{{ p.name || p.project_code }}</option>
                  </select>
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label extra-small fw-bold text-muted mb-1 text-uppercase">Supplier Filter</label>
                  <select v-model="filters.supplier_id" @change="onFilterChange" class="form-select form-select-sm shadow-xs">
                    <option value="">All Suppliers</option>
                    <option v-for="s in activeSuppliersList" :key="s.id" :value="s.id">{{ s.name }}</option>
                  </select>
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label extra-small fw-bold text-muted mb-1 text-uppercase">From Date</label>
                  <input type="date" v-model="filters.date_from" @change="onFilterChange" class="form-control form-control-sm shadow-xs" />
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label extra-small fw-bold text-muted mb-1 text-uppercase">To Date</label>
                  <input type="date" v-model="filters.date_to" @change="onFilterChange" class="form-control form-control-sm shadow-xs" />
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end">
                  <button @click="resetFilters" class="btn btn-outline-secondary btn-sm w-100 shadow-xs">
                    <i class="fas fa-undo me-1"></i> Reset
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 1: OVERVIEW & 8 PRIMARY KPIS                                          -->
          <!-- ========================================================================= -->
          <div v-if="activeTab === 'overview'">
            <!-- 8 KPI Cards Grid -->
            <div class="row g-3 mb-4">
              <!-- 1. Total Suppliers -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-primary">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Total Suppliers</span>
                      <h3 class="fw-bold mb-0 text-dark">{{ kpis.total_suppliers || 0 }}</h3>
                    </div>
                    <i class="fas fa-building fa-2x text-primary opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 2. Active Suppliers -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-success">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Active Suppliers</span>
                      <h3 class="fw-bold mb-0 text-success">{{ kpis.active_suppliers || 0 }}</h3>
                    </div>
                    <i class="fas fa-check-circle fa-2x text-success opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 3. Suppliers In Use -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-info">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Suppliers in Use</span>
                      <h3 class="fw-bold mb-0 text-info">{{ kpis.suppliers_in_use || 0 }}</h3>
                    </div>
                    <i class="fas fa-truck-loading fa-2x text-info opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 4. Projects With Allocation -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-purple">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Allocated Projects</span>
                      <h3 class="fw-bold mb-0 text-purple">{{ kpis.projects_with_allocation || 0 }}</h3>
                    </div>
                    <i class="fas fa-project-diagram fa-2x text-purple opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 5. Jigs With Allocation -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-warning">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Allocated Jigs</span>
                      <h3 class="fw-bold mb-0 text-dark">{{ kpis.jigs_with_allocation || 0 }}</h3>
                    </div>
                    <i class="fas fa-cubes fa-2x text-warning opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 6. Units With Allocation -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-teal">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Allocated Units</span>
                      <h3 class="fw-bold mb-0 text-teal">{{ kpis.units_with_allocation || 0 }}</h3>
                    </div>
                    <i class="fas fa-cube fa-2x text-teal opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 7. Total Active Assignments -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-primary">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Active Assignments</span>
                      <h3 class="fw-bold mb-0 text-primary">{{ kpis.total_active_assignments || 0 }}</h3>
                    </div>
                    <i class="fas fa-tasks fa-2x text-primary opacity-25"></i>
                  </div>
                </div>
              </div>

              <!-- 8. Suppliers with Rework -->
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-danger">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted">Suppliers with Defect Rework</span>
                      <h3 class="fw-bold mb-0 text-danger">{{ kpis.suppliers_with_rework || 0 }}</h3>
                    </div>
                    <i class="fas fa-exclamation-triangle fa-2x text-danger opacity-25"></i>
                  </div>
                </div>
              </div>
            </div>

            <!-- Allocation Summary by Supplier Table -->
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-sitemap me-2 text-primary"></i>Supplier Workload &amp; Category Allocation Breakdown</h6>
                <span class="badge bg-light text-dark border">{{ allocationData.length }} Suppliers Assigned</span>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th>Projects Assigned</th>
                        <th>Jigs Assigned</th>
                        <th>Units Assigned</th>
                        <th>BASE Slots</th>
                        <th>WELDMENT Slots</th>
                        <th>CHILD PART Slots</th>
                        <th>Total Active Slots</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in allocationData" :key="row.supplier_id">
                        <td>
                          <strong class="text-primary fs-6">{{ row.supplier_name }}</strong>
                          <span class="badge bg-light text-dark border ms-1.5">{{ row.supplier_code || 'SUP' }}</span>
                        </td>
                        <td>
                          <span class="badge" :class="row.is_active ? 'bg-success' : 'bg-secondary'">
                            {{ row.is_active ? 'Active' : 'Inactive' }}
                          </span>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ row.total_projects }}</span></td>
                        <td><span class="badge bg-light text-dark border">{{ row.total_jigs }}</span></td>
                        <td><span class="badge bg-light text-dark border">{{ row.total_units }}</span></td>
                        <td><span class="badge bg-success-subtle text-success fw-bold">{{ row.base_assignments }}</span></td>
                        <td><span class="badge bg-info-subtle text-dark fw-bold">{{ row.weldment_assignments }}</span></td>
                        <td><span class="badge bg-warning-subtle text-dark fw-bold">{{ row.child_part_assignments }}</span></td>
                        <td>
                          <span class="badge bg-primary px-3 py-1.5 fs-7">{{ row.total_active_assignments }}</span>
                        </td>
                      </tr>
                      <tr v-if="!allocationData.length">
                        <td colspan="9" class="text-center py-5 text-muted">
                          <i class="fas fa-inbox fa-3x mb-2 text-muted opacity-50"></i>
                          <p class="mb-0">No active supplier allocation records found.</p>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 2: SUPPLIER RANKING BOARD                                             -->
          <!-- ========================================================================= -->
          <div v-else-if="activeTab === 'rankings'">
            <!-- Ranking View Switcher & Description -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
              <div>
                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Supplier Performance Rankings</h5>
                <small class="text-muted">Rankings calculated from PostgreSQL ledger: assignments count, verified receipts, defect occurrences, and rework ratios.</small>
              </div>

              <!-- Sorting Switcher -->
              <div class="btn-group btn-group-sm shadow-xs" role="group">
                <button 
                  type="button" 
                  class="btn" 
                  :class="rankingSortBy === 'usage' ? 'btn-primary' : 'btn-outline-secondary'"
                  @click="rankingSortBy = 'usage'; fetchRankings();"
                >
                  <i class="fas fa-truck me-1"></i> Most Used
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="rankingSortBy === 'lowest_rework' ? 'btn-success' : 'btn-outline-secondary'"
                  @click="rankingSortBy = 'lowest_rework'; fetchRankings();"
                >
                  <i class="fas fa-shield-alt me-1"></i> Lowest Rework (Quality)
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="rankingSortBy === 'highest_rework' ? 'btn-danger' : 'btn-outline-secondary'"
                  @click="rankingSortBy = 'highest_rework'; fetchRankings();"
                >
                  <i class="fas fa-exclamation-circle me-1"></i> Highest Rework
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="rankingSortBy === 'best_overall' ? 'btn-purple text-white' : 'btn-outline-secondary'"
                  @click="rankingSortBy = 'best_overall'; fetchRankings();"
                >
                  <i class="fas fa-star me-1"></i> Best Overall Performance
                </button>
              </div>
            </div>

            <!-- Rankings Table -->
            <div class="card border-0 shadow-sm">
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th style="width: 60px;">Rank</th>
                        <th>Supplier</th>
                        <th>Location</th>
                        <th>Active Workload (Slots)</th>
                        <th>Projects Assigned</th>
                        <th>Units Assigned</th>
                        <th>BOM Receipts</th>
                        <th>Defect Reworks</th>
                        <th>Rework Rate (%)</th>
                        <th>Quality Standing</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="r in rankingsData" :key="r.supplier_id">
                        <td>
                          <span 
                            class="badge rounded-circle p-2 d-inline-flex align-items-center justify-content-center"
                            :class="r.rank === 1 ? 'bg-warning text-dark' : (r.rank === 2 ? 'bg-secondary text-white' : (r.rank === 3 ? 'bg-amber text-white' : 'bg-light text-dark border'))"
                            style="width: 28px; height: 28px; font-weight: 700;"
                          >
                            {{ r.rank }}
                          </span>
                        </td>
                        <td>
                          <strong class="text-dark fs-6">{{ r.supplier_name }}</strong>
                          <span v-if="r.is_test_data" class="badge bg-warning text-dark ms-1 extra-small">Test</span>
                          <div class="extra-small text-muted">{{ r.supplier_code || 'CODE' }}</div>
                        </td>
                        <td><small class="text-muted">{{ r.city ? `${r.city}, ${r.state || ''}` : '—' }}</small></td>
                        <td>
                          <span class="badge bg-primary px-2.5 py-1.5 fw-bold">{{ r.total_assignments }} slots</span>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ r.projects_count }}</span></td>
                        <td><span class="badge bg-light text-dark border">{{ r.units_count }}</span></td>
                        <td><span class="badge bg-success-subtle text-success fw-bold">{{ r.total_receipts }}</span></td>
                        <td>
                          <span class="badge" :class="r.total_reworks > 0 ? 'bg-danger text-white' : 'bg-light text-muted border'">
                            {{ r.total_reworks }}
                          </span>
                        </td>
                        <td>
                          <strong :class="r.rework_rate > 10 ? 'text-danger' : (r.rework_rate > 0 ? 'text-warning' : 'text-success')">
                            {{ r.rework_rate }}%
                          </strong>
                        </td>
                        <td>
                          <span 
                            class="badge px-2.5 py-1.5"
                            :class="r.rework_rate === 0 && r.total_receipts > 0 ? 'bg-success' : (r.rework_rate <= 5 ? 'bg-info text-dark' : (r.rework_rate <= 15 ? 'bg-warning text-dark' : 'bg-danger'))"
                          >
                            {{ r.total_receipts === 0 ? 'Pending Intake' : (r.rework_rate === 0 ? 'Zero Defect' : (r.rework_rate <= 5 ? 'Optimal' : (r.rework_rate <= 15 ? 'Moderate Defect' : 'High Defect Risk'))) }}
                          </span>
                        </td>
                      </tr>
                      <tr v-if="!rankingsData.length">
                        <td colspan="10" class="text-center py-5 text-muted">No supplier rankings data available.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 3: REWORK QUALITY ANALYSIS                                            -->
          <!-- ========================================================================= -->
          <div v-else-if="activeTab === 'rework'">
            <div class="row g-3 mb-4">
              <!-- Summary Table -->
              <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Suppliers Associated with Defect Rework Events</h6>
                  </div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                          <tr>
                            <th>Supplier Name</th>
                            <th>Rework Incidents</th>
                            <th>Total Rework Qty</th>
                            <th>Affected Projects</th>
                            <th>Affected Parts</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-for="s in reworkSummary" :key="s.supplier_id">
                            <td>
                              <strong class="text-danger">{{ s.supplier_name }}</strong>
                              <div class="extra-small text-muted">{{ s.supplier_code || 'CODE' }}</div>
                            </td>
                            <td><span class="badge bg-danger px-2.5 py-1">{{ s.rework_count }}</span></td>
                            <td><span class="fw-bold text-dark">{{ s.total_rework_qty }} pcs</span></td>
                            <td><span class="badge bg-light text-dark border">{{ s.affected_projects_count }}</span></td>
                            <td><span class="badge bg-light text-dark border">{{ s.affected_parts_count }}</span></td>
                          </tr>
                          <tr v-if="!reworkSummary.length">
                            <td colspan="5" class="text-center py-5 text-success">
                              <i class="fas fa-check-circle fa-3x mb-2 text-success"></i>
                              <p class="mb-0 fw-bold">Zero Rework Defects Recorded across active projects!</p>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Rework Log List -->
              <div class="col-12 col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-clipboard-list me-2 text-primary"></i>Recent Defect Logs</h6>
                  </div>
                  <div class="card-body p-2" style="max-height: 450px; overflow-y: auto;">
                    <div v-for="ev in recentReworkEvents" :key="ev.id" class="p-2.5 mb-2 bg-light rounded border">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong class="text-primary small">{{ ev.standard_part_no }}</strong>
                        <span class="badge bg-danger">{{ ev.rework_quantity }} pcs</span>
                      </div>
                      <div class="extra-small text-muted">
                        <span><i class="fas fa-project-diagram me-1"></i>{{ ev.project_code }} &bull; JIG {{ ev.jig_no }}</span>
                        <span class="d-block text-danger fw-semibold mt-0.5">Supplier: {{ ev.supplier_name }}</span>
                      </div>
                      <p v-if="ev.notes" class="extra-small text-muted mb-0 mt-1 fst-italic">"{{ ev.notes }}"</p>
                    </div>
                    <div v-if="!recentReworkEvents.length" class="text-center py-4 text-muted small">
                      No defect logs recorded.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 4: ALLOCATION AUDIT HISTORY                                           -->
          <!-- ========================================================================= -->
          <div v-else-if="activeTab === 'history'">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-history me-2 text-info"></i>Assignment Audit Trail (Who Changed What &amp; When)</h6>
                <span class="badge bg-light text-dark border">Immutable PostgreSQL Audit</span>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>Date &amp; Time</th>
                        <th>Project</th>
                        <th>Jig</th>
                        <th>Unit</th>
                        <th>Category</th>
                        <th>Action</th>
                        <th>Previous Supplier</th>
                        <th>New Assigned Supplier</th>
                        <th>Target Date</th>
                        <th>User</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="h in historyItems" :key="h.id">
                        <td><small class="text-muted">{{ formatDate(h.created_at) }}</small></td>
                        <td><span class="badge bg-light text-dark border">{{ h.project?.name || h.project?.project_code }}</span></td>
                        <td><strong class="text-dark">{{ h.jig_no }}</strong></td>
                        <td><span class="badge bg-secondary-subtle text-dark">{{ h.unit_no }}</span></td>
                        <td>
                          <span class="badge" :class="getCategoryBadgeClass(h.category)">{{ h.category }}</span>
                        </td>
                        <td>
                          <span 
                            class="badge" 
                            :class="h.action === 'created' ? 'bg-success' : (h.action === 'updated' ? 'bg-primary' : 'bg-danger')"
                          >
                            {{ h.action.toUpperCase() }}
                          </span>
                        </td>
                        <td>
                          <span class="text-muted small">{{ h.previous_supplier?.name || '—' }}</span>
                        </td>
                        <td>
                          <strong class="text-primary small">{{ h.new_supplier?.name || '—' }}</strong>
                        </td>
                        <td>
                          <span class="badge bg-light text-dark border">{{ h.new_date || '—' }}</span>
                        </td>
                        <td>
                          <small class="text-muted"><i class="fas fa-user me-1"></i>{{ h.user?.name || 'System' }}</small>
                        </td>
                      </tr>
                      <tr v-if="!historyItems.length">
                        <td colspan="10" class="text-center py-5 text-muted">No historical assignment records found.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <!-- Pagination -->
              <div v-if="historyPagination.last_page > 1" class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2">
                <small class="text-muted">Showing page {{ historyPagination.current_page }} of {{ historyPagination.last_page }} ({{ historyPagination.total }} records)</small>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary" :disabled="historyPagination.current_page <= 1" @click="fetchHistory(historyPagination.current_page - 1)">Prev</button>
                  <button class="btn btn-outline-secondary" :disabled="historyPagination.current_page >= historyPagination.last_page" @click="fetchHistory(historyPagination.current_page + 1)">Next</button>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB: SUPPLIER LOAD KPI & WORKLOAD BALANCING ENGINE                         -->
          <!-- ========================================================================= -->
          <div v-else-if="activeTab === 'load'">
            <!-- Top Load KPI Cards Grid -->
            <div class="row g-3 mb-4">
              <!-- 1. Highest Load Supplier -->
              <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-danger">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="text-truncate me-2">
                      <span class="extra-small text-uppercase fw-bold text-muted d-block">Highest Workload</span>
                      <h5 class="fw-bold mb-0 text-danger text-truncate" :title="loadData.highest_load?.supplier_name || 'None'">
                        {{ loadData.highest_load ? loadData.highest_load.supplier_name : 'None' }}
                      </h5>
                      <small class="text-muted extra-small">
                        {{ loadData.highest_load ? `${loadData.highest_load.total_assignments} slots (${loadData.highest_load.load_pct}%)` : 'No assignments' }}
                      </small>
                    </div>
                    <span class="badge bg-danger-subtle text-danger p-2 rounded-circle">
                      <i class="fas fa-fire fa-lg"></i>
                    </span>
                  </div>
                </div>
              </div>

              <!-- 2. Lowest Load Supplier -->
              <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-success">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="text-truncate me-2">
                      <span class="extra-small text-uppercase fw-bold text-muted d-block">Lowest Workload</span>
                      <h5 class="fw-bold mb-0 text-success text-truncate" :title="loadData.lowest_load?.supplier_name || 'None'">
                        {{ loadData.lowest_load ? loadData.lowest_load.supplier_name : 'None' }}
                      </h5>
                      <small class="text-muted extra-small">
                        {{ loadData.lowest_load ? `${loadData.lowest_load.total_assignments} slots (${loadData.lowest_load.load_pct}%)` : 'No assignments' }}
                      </small>
                    </div>
                    <span class="badge bg-success-subtle text-success p-2 rounded-circle">
                      <i class="fas fa-leaf fa-lg"></i>
                    </span>
                  </div>
                </div>
              </div>

              <!-- 3. Total Assigned Workload Slots -->
              <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-primary">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted d-block">Total Active Slots</span>
                      <h3 class="fw-bold mb-0 text-primary">{{ loadData.total_assignments || 0 }}</h3>
                      <small class="text-muted extra-small">Across {{ loadData.supplier_count || 0 }} suppliers</small>
                    </div>
                    <span class="badge bg-primary-subtle text-primary p-2 rounded-circle">
                      <i class="fas fa-tasks fa-lg"></i>
                    </span>
                  </div>
                </div>
              </div>

              <!-- 4. Average Load per Supplier -->
              <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-xs bg-white p-3 h-100 border-start border-4 border-info">
                  <div class="d-flex justify-content-between align-items-center">
                    <div>
                      <span class="extra-small text-uppercase fw-bold text-muted d-block">Average Workload</span>
                      <h3 class="fw-bold mb-0 text-info">{{ loadData.average_load || 0 }}</h3>
                      <small class="text-muted extra-small">Slots per assigned supplier</small>
                    </div>
                    <span class="badge bg-info-subtle text-info p-2 rounded-circle">
                      <i class="fas fa-chart-line fa-lg"></i>
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Supplier Workload Ranking Table Card -->
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                  <h6 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-balance-scale me-2 text-warning"></i>Supplier Workload Distribution &amp; Balance Ranking
                  </h6>
                  <span class="badge bg-light text-dark border">{{ rankedLoadSuppliers.length }} Active Suppliers</span>
                </div>

                <!-- Sort & Filter Toggles -->
                <div class="d-flex gap-2 align-items-center">
                  <div class="btn-group btn-group-sm">
                    <button 
                      class="btn btn-xs fw-semibold"
                      :class="loadSortBy === 'highest' ? 'btn-primary' : 'btn-outline-secondary'"
                      @click="loadSortBy = 'highest'"
                    >
                      <i class="fas fa-sort-amount-down me-1"></i> Highest Load
                    </button>
                    <button 
                      class="btn btn-xs fw-semibold"
                      :class="loadSortBy === 'lowest' ? 'btn-primary' : 'btn-outline-secondary'"
                      @click="loadSortBy = 'lowest'"
                    >
                      <i class="fas fa-sort-amount-up me-1"></i> Lowest Load
                    </button>
                    <button 
                      class="btn btn-xs fw-semibold"
                      :class="loadSortBy === 'high_only' ? 'btn-danger' : 'btn-outline-secondary'"
                      @click="loadSortBy = 'high_only'"
                    >
                      <i class="fas fa-exclamation-triangle me-1"></i> High Load Alerts
                    </button>
                  </div>
                </div>
              </div>

              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th style="width: 60px;" class="text-center">Rank</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th>Units Assigned</th>
                        <th>BASE Slots</th>
                        <th>WELDMENT Slots</th>
                        <th>CHILD PART Slots</th>
                        <th>Total Slots</th>
                        <th style="width: 200px;">Workload Share</th>
                        <th style="width: 130px;" class="text-center">Load Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="s in rankedLoadSuppliers" :key="s.supplier_id">
                        <td class="text-center fw-bold">#{{ s.rank }}</td>
                        <td>
                          <strong class="text-dark fs-6">{{ s.supplier_name }}</strong>
                          <span v-if="s.supplier_code" class="badge bg-light text-secondary border ms-1 extra-small">{{ s.supplier_code }}</span>
                        </td>
                        <td>
                          <span class="badge" :class="s.supplier_is_active ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary'">
                            {{ s.supplier_is_active ? 'Active' : 'Inactive' }}
                          </span>
                        </td>
                        <td>
                          <span class="badge bg-primary-subtle text-primary border fw-bold">{{ s.units_assigned }} Units</span>
                        </td>
                        <td><span class="badge bg-success-subtle text-success">{{ s.base_count }}</span></td>
                        <td><span class="badge bg-info-subtle text-info-emphasis">{{ s.weldment_count }}</span></td>
                        <td><span class="badge bg-warning-subtle text-warning-emphasis">{{ s.child_part_count }}</span></td>
                        <td>
                          <strong class="text-dark fs-6">{{ s.total_assignments }}</strong>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px;">
                              <div 
                                class="progress-bar"
                                :class="{
                                  'bg-danger': s.relative_status === 'High Load',
                                  'bg-warning': s.relative_status === 'Medium Load',
                                  'bg-success': s.relative_status === 'Low Load'
                                }"
                                :style="{ width: `${Math.min(100, s.load_pct * 2)}%` }"
                              ></div>
                            </div>
                            <span class="extra-small fw-bold text-muted" style="min-width: 45px;">{{ s.load_pct }}%</span>
                          </div>
                        </td>
                        <td class="text-center">
                          <span 
                            class="badge px-2.5 py-1"
                            :class="{
                              'bg-danger text-white': s.relative_status === 'High Load',
                              'bg-warning text-dark': s.relative_status === 'Medium Load',
                              'bg-success text-white': s.relative_status === 'Low Load'
                            }"
                          >
                            <i 
                              class="fas me-1"
                              :class="{
                                'fa-exclamation-circle': s.relative_status === 'High Load',
                                'fa-dot-circle': s.relative_status === 'Medium Load',
                                'fa-check-circle': s.relative_status === 'Low Load'
                              }"
                            ></i>
                            {{ s.relative_status }}
                          </span>
                        </td>
                      </tr>
                      <tr v-if="!rankedLoadSuppliers.length">
                        <td colspan="10" class="text-center py-5 text-muted">
                          No active supplier load data found matching filters.
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 5: SUPPLIER MASTER CRUD DIRECTORY                                     -->
          <!-- ========================================================================= -->
          <div v-else-if="activeTab === 'master'">
            <!-- Master Filters & Search -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
              <div class="input-group input-group-sm" style="max-width: 320px;">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input 
                  type="text" 
                  class="form-control" 
                  placeholder="Search supplier name, code, contact..." 
                  v-model="masterSearch"
                  @input="fetchMasterSuppliers"
                />
              </div>

              <div class="d-flex gap-2 align-items-center">
                <select v-model="masterStatusFilter" @change="fetchMasterSuppliers" class="form-select form-select-sm" style="min-width: 140px;">
                  <option value="">All Statuses</option>
                  <option value="active">Active Only</option>
                  <option value="inactive">Inactive Only</option>
                </select>
                <select v-model="masterTestFilter" @change="fetchMasterSuppliers" class="form-select form-select-sm" style="min-width: 150px;">
                  <option value="">All Types</option>
                  <option value="false">Production Only</option>
                  <option value="true">Test Data Only</option>
                </select>
              </div>
            </div>

            <!-- Master Table -->
            <div class="card border-0 shadow-sm">
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>Code</th>
                        <th>Supplier Name</th>
                        <th>Contact Person</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Location</th>
                        <th>Active Slots</th>
                        <th>Status</th>
                        <th style="width: 140px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="s in masterSuppliers" :key="s.id">
                        <td><span class="badge bg-light text-dark border">{{ s.code }}</span></td>
                        <td>
                          <strong class="text-dark fs-6">{{ s.name }}</strong>
                          <span v-if="s.is_test_data" class="badge bg-warning text-dark ms-1 extra-small">Test Data</span>
                        </td>
                        <td>{{ s.contact_person || '—' }}</td>
                        <td>{{ s.phone || '—' }}</td>
                        <td>{{ s.email || '—' }}</td>
                        <td><small class="text-muted">{{ s.city ? `${s.city}, ${s.state || ''}` : '—' }}</small></td>
                        <td>
                          <span class="badge bg-primary-subtle text-primary border">{{ s.supplier_assignments_count || 0 }}</span>
                        </td>
                        <td>
                          <span class="badge" :class="s.is_active ? 'bg-success' : 'bg-secondary'">
                            {{ s.is_active ? 'Active' : 'Inactive' }}
                          </span>
                        </td>
                        <td>
                          <button v-if="canManageSuppliers" class="btn btn-xs btn-outline-primary me-1" @click="openEditModal(s)">Edit</button>
                          <button v-if="authStore.userRole === 'ADMIN'" class="btn btn-xs btn-outline-danger" @click="deleteSupplier(s)">Delete</button>
                        </td>
                      </tr>
                      <tr v-if="!masterSuppliers.length">
                        <td colspan="9" class="text-center py-5 text-muted">No suppliers found matching the filters.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Pagination -->
              <div v-if="masterPagination.last_page > 1" class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2">
                <small class="text-muted">Showing page {{ masterPagination.current_page }} of {{ masterPagination.last_page }} ({{ masterPagination.total }} suppliers)</small>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-secondary" :disabled="masterPagination.current_page <= 1" @click="fetchMasterSuppliers(masterPagination.current_page - 1)">Prev</button>
                  <button class="btn btn-outline-secondary" :disabled="masterPagination.current_page >= masterPagination.last_page" @click="fetchMasterSuppliers(masterPagination.current_page + 1)">Next</button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- ADD / EDIT SUPPLIER MODAL -->
    <div class="modal fade" id="supplierModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-dark text-white py-3">
            <h5 class="modal-title fw-bold">{{ editing ? 'Edit Supplier' : 'Add New Supplier' }}</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label fw-semibold small">Supplier Name <span class="text-danger">*</span></label>
                <input type="text" v-model="form.name" class="form-control" placeholder="Company Name (e.g. Acme Tooling Ltd)" />
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold small">Supplier Code</label>
                <input type="text" v-model="form.code" class="form-control" placeholder="SUP-001" />
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold small">Contact Person</label>
                <input type="text" v-model="form.contact_person" class="form-control" placeholder="Contact Person Name" />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold small">Phone</label>
                <input type="text" v-model="form.phone" class="form-control" placeholder="+91 98765 43210" />
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold small">Email</label>
                <input type="email" v-model="form.email" class="form-control" placeholder="supplier@example.com" />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold small">City</label>
                <input type="text" v-model="form.city" class="form-control" placeholder="Pune" />
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold small">State</label>
                <input type="text" v-model="form.state" class="form-control" placeholder="Maharashtra" />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold small">Country</label>
                <input type="text" v-model="form.country" class="form-control" placeholder="India" />
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold small">Address</label>
                <textarea v-model="form.address" class="form-control" rows="2" placeholder="Full plant address..."></textarea>
              </div>

              <div class="col-md-6">
                <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" id="isActiveSwitch" v-model="form.is_active">
                  <label class="form-check-label fw-semibold small" for="isActiveSwitch">Active Supplier</label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" id="isTestSwitch" v-model="form.is_test_data">
                  <label class="form-check-label fw-semibold small text-warning" for="isTestSwitch">Mark as Test Data</label>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold small">Remarks / Notes</label>
                <input type="text" v-model="form.remarks" class="form-control" placeholder="Internal remarks..." />
              </div>
            </div>
          </div>
          <div class="modal-footer bg-light py-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm fw-bold" @click="saveSupplier">Save Supplier</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
const canManageSuppliers = computed(() => ['ADMIN', 'MANAGER', 'PURCHASE'].includes(authStore.userRole));

// Active subtab
const activeTab = ref('overview'); // 'overview' | 'rankings' | 'rework' | 'history' | 'load' | 'master'
const loading = ref(false);
const error = ref('');
const successMessage = ref('');

// Global filters
const filters = ref({
  project_id: '',
  supplier_id: '',
  date_from: '',
  date_to: '',
});

const projectList = ref([]);
const activeSuppliersList = ref([]);

// Tab 1: Overview KPIs & Allocation breakdown
const kpis = ref({});
const allocationData = ref([]);

// Tab 2: Rankings
const rankingsData = ref([]);
const rankingSortBy = ref('usage');

// Tab 3: Rework
const reworkSummary = ref([]);
const recentReworkEvents = ref([]);

// Tab 4: History
const historyItems = ref([]);
const historyPagination = ref({ current_page: 1, last_page: 1, total: 0 });

// Tab: Supplier Load KPI
const loadData = ref({
  suppliers: [],
  total_assignments: 0,
  supplier_count: 0,
  highest_load: null,
  lowest_load: null,
  average_load: 0,
  average_load_pct: 0,
});
const loadSortBy = ref('highest'); // 'highest' | 'lowest' | 'high_only'

// Computed: Filtered / Sorted Supplier Load list
const rankedLoadSuppliers = computed(() => {
  const list = [...(loadData.value.suppliers || [])];

  if (loadSortBy.value === 'lowest') {
    list.sort((a, b) => a.total_assignments - b.total_assignments);
  } else if (loadSortBy.value === 'high_only') {
    return list.filter(s => s.relative_status === 'High Load');
  } else {
    // default 'highest'
    list.sort((a, b) => b.total_assignments - a.total_assignments);
  }

  // Update ranks
  return list.map((item, idx) => ({ ...item, rank: idx + 1 }));
});

// Tab 5: Supplier Master
const masterSuppliers = ref([]);
const masterSearch = ref('');
const masterStatusFilter = ref('');
const masterTestFilter = ref('');
const masterPagination = ref({ current_page: 1, last_page: 1, total: 0 });

// Modal form
const editing = ref(false);
const editingId = ref(null);
const form = ref({
  name: '',
  code: '',
  contact_person: '',
  phone: '',
  email: '',
  address: '',
  city: '',
  state: '',
  country: 'India',
  is_active: true,
  is_test_data: false,
  remarks: '',
});

const onFilterChange = () => {
  if (activeTab.value === 'overview') fetchOverviewKpis();
  else if (activeTab.value === 'rankings') fetchRankings();
  else if (activeTab.value === 'rework') fetchReworkAnalysis();
  else if (activeTab.value === 'history') fetchHistory();
  else if (activeTab.value === 'load') fetchSupplierLoad();
};

const resetFilters = () => {
  filters.value = {
    project_id: '',
    supplier_id: '',
    date_from: '',
    date_to: '',
  };
  onFilterChange();
};

const fetchFilterOptions = async () => {
  try {
    const [pRes, sRes] = await Promise.all([
      axios.get('/api/v1/supplier-allocation/hierarchy'),
      axios.get('/api/v1/suppliers/active-list'),
    ]);
    projectList.value = pRes.data.projects || [];
    activeSuppliersList.value = sRes.data.suppliers || [];
  } catch (err) {
    console.error('Failed to load filter options:', err);
  }
};

const fetchOverviewKpis = async () => {
  loading.value = true;
  try {
    const params = buildFilterParams();
    const [kpiRes, allocRes] = await Promise.all([
      axios.get(`/api/v1/supplier-analytics/kpis?${params}`),
      axios.get(`/api/v1/supplier-analytics/allocation?${params}`),
    ]);
    kpis.value = kpiRes.data.kpis || {};
    allocationData.value = allocRes.data.allocation || [];
  } catch (err) {
    error.value = 'Failed to load supplier overview KPIs.';
  } finally {
    loading.value = false;
  }
};

const fetchRankings = async () => {
  loading.value = true;
  try {
    const params = buildFilterParams();
    params.append('sort_by', rankingSortBy.value);
    const res = await axios.get(`/api/v1/supplier-analytics/ranking?${params.toString()}`);
    rankingsData.value = res.data.rankings || [];
  } catch (err) {
    error.value = 'Failed to load supplier rankings.';
  } finally {
    loading.value = false;
  }
};

const fetchReworkAnalysis = async () => {
  loading.value = true;
  try {
    const params = buildFilterParams();
    const res = await axios.get(`/api/v1/supplier-analytics/rework?${params.toString()}`);
    reworkSummary.value = res.data.rework?.summary || [];
    recentReworkEvents.value = res.data.rework?.recent_events || [];
  } catch (err) {
    error.value = 'Failed to load rework analysis.';
  } finally {
    loading.value = false;
  }
};

const fetchHistory = async (page = 1) => {
  loading.value = true;
  try {
    const params = buildFilterParams();
    params.append('page', page);
    const res = await axios.get(`/api/v1/supplier-analytics/history?${params.toString()}`);
    historyItems.value = res.data.data || [];
    historyPagination.value = {
      current_page: res.data.current_page,
      last_page: res.data.last_page,
      total: res.data.total,
    };
  } catch (err) {
    error.value = 'Failed to load audit history.';
  } finally {
    loading.value = false;
  }
};

const fetchSupplierLoad = async () => {
  loading.value = true;
  try {
    const params = buildFilterParams();
    const res = await axios.get(`/api/v1/supplier-analytics/load?${params.toString()}`);
    loadData.value = res.data.load || {
      suppliers: [],
      total_assignments: 0,
      supplier_count: 0,
      highest_load: null,
      lowest_load: null,
      average_load: 0,
    };
  } catch (err) {
    error.value = 'Failed to load Supplier Load KPI data.';
  } finally {
    loading.value = false;
  }
};

const fetchMasterSuppliers = async (page = 1) => {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    params.append('page', page);
    if (masterSearch.value) params.append('search', masterSearch.value);
    if (masterStatusFilter.value) params.append('status', masterStatusFilter.value);
    if (masterTestFilter.value) params.append('is_test_data', masterTestFilter.value);

    const res = await axios.get(`/api/v1/suppliers?${params.toString()}`);
    masterSuppliers.value = res.data.data || [];
    masterPagination.value = {
      current_page: res.data.current_page,
      last_page: res.data.last_page,
      total: res.data.total,
    };
  } catch (err) {
    error.value = 'Failed to load master suppliers.';
  } finally {
    loading.value = false;
  }
};

const buildFilterParams = () => {
  const params = new URLSearchParams();
  if (filters.value.project_id) params.append('project_id', filters.value.project_id);
  if (filters.value.supplier_id) params.append('supplier_id', filters.value.supplier_id);
  if (filters.value.date_from) params.append('date_from', filters.value.date_from);
  if (filters.value.date_to) params.append('date_to', filters.value.date_to);
  return params;
};

const refreshAllData = () => {
  fetchFilterOptions();
  onFilterChange();
  if (activeTab.value === 'master') fetchMasterSuppliers();
};

const openCreateModal = () => {
  editing.value = false;
  editingId.value = null;
  form.value = {
    name: '',
    code: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    city: '',
    state: '',
    country: 'India',
    is_active: true,
    is_test_data: false,
    remarks: '',
  };
  const modal = new bootstrap.Modal(document.getElementById('supplierModal'));
  modal.show();
};

const openEditModal = (s) => {
  editing.value = true;
  editingId.value = s.id;
  form.value = {
    name: s.name,
    code: s.code,
    contact_person: s.contact_person,
    phone: s.phone,
    email: s.email,
    address: s.address,
    city: s.city,
    state: s.state,
    country: s.country || 'India',
    is_active: s.is_active,
    is_test_data: s.is_test_data || false,
    remarks: s.remarks,
  };
  const modal = new bootstrap.Modal(document.getElementById('supplierModal'));
  modal.show();
};

const saveSupplier = async () => {
  error.value = '';
  successMessage.value = '';
  if (!form.value.name) {
    error.value = 'Supplier name is required.';
    return;
  }

  try {
    if (editing.value) {
      await axios.put(`/api/v1/suppliers/${editingId.value}`, form.value);
      successMessage.value = 'Supplier updated successfully.';
    } else {
      await axios.post('/api/v1/suppliers', form.value);
      successMessage.value = 'Supplier created successfully.';
    }
    const modal = bootstrap.Modal.getInstance(document.getElementById('supplierModal'));
    if (modal) modal.hide();

    refreshAllData();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save supplier.';
  }
};

const deleteSupplier = async (s) => {
  if (!confirm(`Delete or deactivate supplier ${s.name}?`)) return;
  try {
    const res = await axios.delete(`/api/v1/suppliers/${s.id}`);
    successMessage.value = res.data.message || 'Supplier deleted successfully.';
    refreshAllData();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to delete supplier.';
  }
};

const getCategoryBadgeClass = (category) => {
  switch (category) {
    case 'BASE':
      return 'bg-success text-white';
    case 'WELDMENT':
      return 'bg-info text-dark';
    case 'CHILD_PART':
      return 'bg-warning text-dark';
    default:
      return 'bg-secondary text-white';
  }
};

const formatDate = (val) => {
  if (!val) return '—';
  return new Date(val).toLocaleDateString() + ' ' + new Date(val).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

// Echo Realtime Listeners
const setupEchoListener = () => {
  if (window.Echo) {
    window.Echo.channel('workflow')
      .listen('.supplier.assignment.updated', () => {
        if (activeTab.value === 'overview') fetchOverviewKpis();
        else if (activeTab.value === 'load') fetchSupplierLoad();
        else if (activeTab.value === 'history') fetchHistory();
      })
      .listen('.supplier.deactivated', () => {
        fetchFilterOptions();
        if (activeTab.value === 'master') fetchMasterSuppliers();
        if (activeTab.value === 'load') fetchSupplierLoad();
      });
  }
};

onMounted(() => {
  fetchFilterOptions();
  fetchOverviewKpis();
  setupEchoListener();
});

onUnmounted(() => {
  if (window.Echo) {
    window.Echo.leaveChannel('workflow');
  }
});
</script>

<style scoped>
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.fs-7 {
  font-size: 0.8rem;
}
.extra-small {
  font-size: 0.72rem;
}
.text-purple {
  color: #8b5cf6 !important;
}
.border-purple {
  border-color: #8b5cf6 !important;
}
.bg-purple {
  background-color: #8b5cf6 !important;
}
.text-teal {
  color: #0d9488 !important;
}
.border-teal {
  border-color: #0d9488 !important;
}
</style>
