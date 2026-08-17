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
              <select v-model="filters.project_id" @change="fetchData" class="form-select form-select-sm">
                <option value="">All Active Projects</option>
                <option v-for="proj in priorityProjectsList" :key="proj.id" :value="proj.id">
                  {{ proj.project_code || proj.name }} - {{ proj.name }}
                </option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Side</label>
              <select v-model="filters.side" @change="fetchData" class="form-select form-select-sm">
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

      <!-- KPI Summary Row 1: Project Overview -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Active Projects</div>
                <h2 class="fw-bold mb-0">{{ metrics.total_projects || 0 }}</h2>
                <small class="text-white-50">{{ metrics.completed_projects || 0 }} Completed</small>
              </div>
              <i class="fas fa-folder-open fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-info text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Completed Projects</div>
                <h2 class="fw-bold mb-0">{{ metrics.completed_projects || 0 }}</h2>
                <small class="text-white-50">Fully Assembled</small>
              </div>
              <i class="fas fa-check-circle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-danger text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Delayed Projects</div>
                <h2 class="fw-bold mb-0">{{ metrics.delayed_projects || 0 }}</h2>
                <small class="text-white-50">No progress >14 days</small>
              </div>
              <i class="fas fa-exclamation-triangle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-secondary text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Total Parts Required</div>
                <h2 class="fw-bold mb-0">{{ metrics.total_required || 0 }}</h2>
                <small class="text-white-50">Across All Projects</small>
              </div>
              <i class="fas fa-cubes fa-2x text-white-50"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- KPI Summary Row 2: Part Flow & QC Breakdown -->
      <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-success text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Store Received</div>
                <h2 class="fw-bold mb-0">{{ metrics.total_received || 0 }}</h2>
                <small class="text-white-50">{{ metrics.pending_store || 0 }} Pending Arrival</small>
              </div>
              <i class="fas fa-boxes fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-dark text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Parts Pending Store</div>
                <h2 class="fw-bold mb-0">{{ metrics.pending_store || 0 }}</h2>
                <small class="text-white-50">Awaiting Delivery</small>
              </div>
              <i class="fas fa-truck-loading fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-warning text-dark h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-dark-50 text-uppercase fw-bold small">Awaiting QC</div>
                <h2 class="fw-bold mb-0">{{ metrics.awaiting_qc || 0 }}</h2>
                <small class="text-dark-50">Pending Inspection</small>
              </div>
              <i class="fas fa-clipboard-check fa-2x text-dark-50"></i>
            </div>
          </div>
        </div>

        <!-- ENHANCED QC RESULTS BREAKDOWN KPI CARD -->
        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0d9488;">
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <div class="text-white-50 text-uppercase fw-bold small">QC Inspection Breakdown</div>
                  <h2 class="fw-bold mb-0">{{ metrics.qc_approved || 0 }} <span class="fs-6 text-white-50">Approved</span></h2>
                </div>
                <i class="fas fa-shield-alt fa-2x text-white-50"></i>
              </div>
              <div class="d-flex gap-1 mt-2 pt-2 border-top border-white-50 small flex-wrap">
                <span class="badge bg-success" title="Approved"><i class="fas fa-check me-1"></i>{{ metrics.qc_approved || 0 }} Approved</span>
                <span class="badge bg-warning text-dark" title="Rework"><i class="fas fa-tools me-1"></i>{{ metrics.qc_rework || 0 }} Rework</span>
                <span class="badge bg-danger" title="Rejected"><i class="fas fa-times me-1"></i>{{ metrics.qc_rejected || 0 }} Rejected</span>
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
              <option value="LOW">Low (&lt;20%)</option>
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
            <!-- Left 7 Cols: Priority Heatmap Table -->
            <div class="col-12 col-xl-7">
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
                                  <tr v-for="part in (unit.pending_parts || [])" :key="part.id + (part.side || 'COMMON')">
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

            <!-- Right 5 Cols: Top Near-Complete Units Bar Chart -->
            <div class="col-12 col-xl-5 border-start">
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

      <!-- ROW 2: OPERATIONAL EFFICIENCY & CAPACITY (Cards 4, 5, 6) -->
      <div class="row g-3 mb-4">
        <!-- 4. Process Flow Efficiency & Stage Dwell Time -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-hourglass-half text-purple me-2"></i>Process Flow Efficiency
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Measures average workstation dwell and turnaround duration in hours for parts progressing through Store, QC Inspection, Rework, Paint, and Assembly against standard targets."></i>
                </h5>
              </div>
              <span class="badge bg-purple text-white">Stage Dwell</span>
            </div>
            <div class="card-body p-3">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                  <thead class="table-light">
                    <tr>
                      <th>Workstation Stage</th>
                      <th>Avg Dwell</th>
                      <th>Benchmark</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="stg in stageDwellTimes" :key="stg.stage">
                      <td class="fw-semibold text-dark">{{ stg.stage }}</td>
                      <td class="fw-bold">{{ stg.avg_hours }} hrs</td>
                      <td class="text-muted">{{ stg.benchmark_hours }} hrs</td>
                      <td>
                        <span class="badge" :class="stg.status === 'Optimal' ? 'bg-success' : (stg.status === 'Attention' ? 'bg-warning text-dark' : 'bg-secondary')">
                          {{ stg.status }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- 5. Quality Stability Index (QSI Volatility Control Chart) -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-shield-alt text-warning me-2"></i>Quality Stability Index (QSI)
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Statistical Process Control (SPC) metric monitoring day-to-day QC yield consistency within Upper Control Limit (UCL = Mean + 2σ) and Lower Control Limit (LCL = Mean - 2σ) thresholds."></i>
                </h5>
              </div>
              <span class="badge bg-warning text-dark">Mean: {{ qualityStability.mean_yield || 0 }}%</span>
            </div>
            <div class="card-body p-3">
              <div style="height: 220px; position: relative;">
                <canvas ref="qsiChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- 6. Capacity Load by Department (Active WIP) -->
        <div class="col-12 col-lg-4">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-layer-group text-primary me-2"></i>Capacity Load by Department
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Displays live Work-In-Progress (WIP) volume (pieces) currently queued or being processed across each individual department."></i>
                </h5>
              </div>
              <span class="badge bg-primary">Active WIP</span>
            </div>
            <div class="card-body p-3">
              <div class="d-flex flex-column gap-3">
                <div v-for="cap in capacityLoad" :key="cap.department">
                  <div class="d-flex justify-content-between align-items-center mb-1 small">
                    <span class="fw-bold text-dark">{{ cap.department }}</span>
                    <span class="badge px-2 py-1" :style="{ backgroundColor: cap.color, color: '#fff' }">
                      {{ cap.wip_count }} pcs in WIP
                    </span>
                  </div>
                  <div class="progress" style="height: 8px;">
                    <div class="progress-bar" :style="{ width: Math.min(100, (cap.wip_count / (totalWipSum || 1)) * 100) + '%', backgroundColor: cap.color }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 3: VENDOR QUALITY, VARIANCE & QUALITY COST (Cards 7, 8, 9, 10) -->
      <div class="row g-3 mb-4">
        <!-- 7. Supplier Fill Accuracy (RH vs LH Separate) -->
        <div class="col-12 col-lg-6">
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

        <!-- 8. Project Completion Variance -->
        <div class="col-12 col-lg-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-dark d-flex align-items-center">
                  <i class="fas fa-balance-scale text-info me-2"></i>Project Completion Variance
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Compares required BOM specifications against actual assembled units per active project to identify schedule variance and part shortages."></i>
                </h5>
              </div>
              <span class="badge bg-info text-white">Project Delta</span>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                  <thead class="table-dark">
                    <tr>
                      <th>Project</th>
                      <th>Planned BOM</th>
                      <th>Actual Assembled</th>
                      <th>Variance</th>
                      <th style="width: 140px;">Readiness</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="proj in projectVariance" :key="proj.id">
                      <td>
                        <strong>{{ proj.project_name || proj.project_code }}</strong>
                        <div class="extra-small text-muted">{{ proj.project_code }}</div>
                      </td>
                      <td>{{ proj.planned_qty }} pcs</td>
                      <td class="text-teal fw-bold">{{ proj.actual_qty }} pcs</td>
                      <td>
                        <span class="badge" :class="proj.variance_qty >= 0 ? 'bg-success' : 'bg-danger'">
                          {{ proj.variance_qty }} pcs
                        </span>
                      </td>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <div class="progress flex-grow-1" style="height: 6px;">
                            <div class="progress-bar bg-teal" :style="{ width: proj.completion_pct + '%' }"></div>
                          </div>
                          <span class="extra-small fw-bold">{{ proj.completion_pct }}%</span>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="!projectVariance.length">
                      <td colspan="5" class="text-center py-4 text-muted">No active projects found.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 4: BOTTLENECKS & QUALITY COST (Cards 9, 10) -->
      <div class="row g-3 mb-4">
        <!-- 9. Critical Dependency Monitor (Top Bottleneck Parts) -->
        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="card-title fw-bold mb-0 text-danger d-flex align-items-center">
                  <i class="fas fa-exclamation-triangle me-2"></i>Critical Dependency Monitor (Bottleneck Ranking)
                  <i class="fas fa-info-circle text-muted ms-2 fs-7" style="cursor: pointer;" title="Ranks the most critical missing or bottleneck spare parts currently blocking project assembly and shipment."></i>
                </h5>
              </div>
              <span class="badge bg-danger">{{ criticalBottlenecks.length }} Critical Parts</span>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                  <thead class="table-dark">
                    <tr>
                      <th>Standard Part Number</th>
                      <th>Project</th>
                      <th>Supplier</th>
                      <th>Required</th>
                      <th>Assembled</th>
                      <th>Shortage</th>
                      <th>Criticality</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="bn in criticalBottlenecks" :key="bn.id">
                      <td class="fw-bold text-primary">{{ bn.standard_part_no }}</td>
                      <td><span class="badge bg-light text-dark border">{{ bn.project_code }}</span></td>
                      <td class="text-muted">{{ bn.supplier }}</td>
                      <td>{{ bn.required }} pcs</td>
                      <td class="text-teal">{{ bn.assembled }} pcs</td>
                      <td class="text-danger fw-bold">{{ bn.shortage }} pcs</td>
                      <td>
                        <span class="badge" :class="bn.criticality === 'CRITICAL' ? 'bg-danger' : 'bg-warning text-dark'">
                          {{ bn.criticality }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!criticalBottlenecks.length">
                      <td colspan="7" class="text-center py-4 text-success">
                        <i class="fas fa-check-circle me-1"></i> No critical bottlenecks detected!
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- 10. Quality Cost Pressure Score -->
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
                <small class="text-muted"><i class="fas fa-table me-1"></i>Use column filters below for Excel-style filtering</small>
                <button class="btn btn-outline-secondary btn-sm" @click="clearModalFilters"><i class="fas fa-times me-1"></i>Clear Filters</button>
              </div>
              <span class="badge bg-primary fs-6">{{ filteredModalParts.length }} Movement{{ filteredModalParts.length !== 1 ? 's' : '' }}</span>
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
                    <td class="text-muted">{{ formatLocalTime(part.created_at_iso || part.time) }}</td>
                  </tr>
                  <tr v-if="!filteredModalParts.length">
                    <td colspan="7" class="text-center py-4 text-muted">No parts match the selected filters.</td>
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
const modalColFilters = ref({ partNo: '', project: '', side: '', qty: '', dept: '', user: '', time: '' });
const expandedPriorityKey = ref(null);
const projectsProgress = ref([]);

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

// 10 Industry-Grade Management Analytics State
const readinessScore = ref(0);
const readinessBreakdown = ref([]);
const conversionData = ref({});
const velocitySeries = ref([]);
const stageDwellTimes = ref([]);
const qualityStability = ref({});
const capacityLoad = ref([]);
const supplierFillAccuracy = ref([]);
const projectVariance = ref([]);
const criticalBottlenecks = ref([]);
const qualityCostPressure = ref({});

const totalWipSum = computed(() => {
  return (capacityLoad.value || []).reduce((acc, c) => acc + (c.wip_count || 0), 0);
});

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
const qsiChartCanvas = ref(null);

let priorityChart = null;
let velocityChart = null;
let qsiChart = null;

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  matrixWindowOffset.value = 0;
  fetchData();
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
  modalColFilters.value = { partNo: '', project: '', side: '', qty: '', dept: '', user: '', time: '' };
};

const clearModalFilters = () => {
  modalDeptFilter.value = '';
  modalColFilters.value = { partNo: '', project: '', side: '', qty: '', dept: '', user: '', time: '' };
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

    await fetchDailyMovement();

    // 10 Industry-Grade Analytics Data
    const ana = anaRes.data || {};
    readinessScore.value = ana.project_readiness_index?.readiness_score || 0;
    readinessBreakdown.value = ana.project_readiness_index?.breakdown || [];
    conversionData.value = ana.conversion_rate || {};
    velocitySeries.value = ana.velocity_series || [];
    stageDwellTimes.value = ana.stage_dwell_times || [];
    qualityStability.value = ana.quality_stability_index || {};
    capacityLoad.value = ana.capacity_load || [];
    supplierFillAccuracy.value = ana.supplier_fill_accuracy || [];
    projectVariance.value = ana.project_variance || [];
    criticalBottlenecks.value = ana.critical_bottlenecks || [];
    qualityCostPressure.value = ana.quality_cost_pressure || {};

    await fetchPriorityMap();

    await nextTick();
    renderAnalyticsCharts();
  } catch (err) {
    console.error('Failed to load dashboard data:', err);
  } finally {
    loading.value = false;
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

    // 2. QSI Control Chart
    if (qsiChart) {
      qsiChart.destroy();
      qsiChart = null;
    }
    if (qsiChartCanvas.value && qualityStability.value.history?.length) {
      const hist = qualityStability.value.history;
      qsiChart = new Chart(qsiChartCanvas.value, {
        type: 'line',
        data: {
          labels: hist.map(h => h.label),
          datasets: [
            {
              label: 'QC Yield %',
              data: hist.map(h => h.yield_pct),
              borderColor: '#10b981',
              backgroundColor: 'rgba(16, 185, 129, 0.1)',
              tension: 0.2,
              pointRadius: 4,
            },
            {
              label: 'UCL (Upper Limit)',
              data: hist.map(() => qualityStability.value.ucl),
              borderColor: '#ef4444',
              borderDash: [4, 4],
              pointRadius: 0,
              fill: false,
            },
            {
              label: 'Mean Yield',
              data: hist.map(() => qualityStability.value.mean_yield),
              borderColor: '#6b7280',
              borderDash: [2, 2],
              pointRadius: 0,
              fill: false,
            },
            {
              label: 'LCL (Lower Limit)',
              data: hist.map(() => qualityStability.value.lcl),
              borderColor: '#ef4444',
              borderDash: [4, 4],
              pointRadius: 0,
              fill: false,
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 10 } }
          },
          scales: {
            y: { min: 0, max: 105, title: { display: true, text: 'Yield %' } }
          }
        }
      });
    }
  } catch (e) {
    console.warn('Error rendering analytics charts:', e);
  }
};

onMounted(() => {
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
</style>
