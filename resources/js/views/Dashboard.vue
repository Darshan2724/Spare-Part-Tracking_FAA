<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <!-- Header Topbar -->
      <div class="py-3 px-4 bg-white border-bottom shadow-sm rounded mb-4 d-flex justify-content-between align-items-center">
        <div>
          <h4 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-line text-primary me-2"></i>Manufacturing Manager Terminal</h4>
          <p class="text-muted mb-0 small">Real-time status, bottleneck analytics, quality trends, and daily department parts movement</p>
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
                <option v-for="proj in projectsProgress" :key="proj.id" :value="proj.id">
                  {{ proj.project_code }} - {{ proj.name }}
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

      <!-- PARTS PRIORITY INTELLIGENCE MAP (Critical Path Acceleration) -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <h5 class="card-title fw-bold mb-0">
              <i class="fas fa-fire me-2 text-danger"></i>Parts Priority Intelligence Map
              <span class="badge bg-danger ms-2" v-if="prioritySummary.CRITICAL > 0">{{ prioritySummary.CRITICAL }} Urgent Action Units</span>
            </h5>
            <small class="text-muted">Units closest to completion are prioritized so managers can order remaining parts to finish assemblies faster.</small>
          </div>
          <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small fw-bold text-secondary">Filter Project:</label>
            <select v-model="priorityProjectFilter" class="form-select form-select-sm" style="width: 190px;" @change="fetchPriorityMap">
              <option value="">All Projects</option>
              <option v-for="proj in priorityProjectsList" :key="proj.id" :value="proj.id">{{ proj.name }} ({{ proj.project_code }})</option>
            </select>
          </div>
        </div>

        <!-- Priority Tier Summary Badges -->
        <div class="card-body py-2 px-3 border-bottom bg-light">
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-danger px-3 py-2 fs-6">🔴 Critical (≥70%): {{ prioritySummary.CRITICAL || 0 }}</span>
            <span class="badge bg-warning text-dark px-3 py-2 fs-6">🟠 High (≥40%): {{ prioritySummary.HIGH || 0 }}</span>
            <span class="badge bg-info text-white px-3 py-2 fs-6">🟡 Medium (≥20%): {{ prioritySummary.MEDIUM || 0 }}</span>
            <span class="badge bg-secondary px-3 py-2 fs-6">⚪ Low (<20%): {{ prioritySummary.LOW || 0 }}</span>
            <span class="badge bg-success px-3 py-2 fs-6">🟢 Completed: {{ prioritySummary.COMPLETE || 0 }}</span>
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
                      <th style="background:#0f172a; color:#fff;">Req / Rec</th>
                      <th style="background:#0f172a; color:#fff;">Pending</th>
                      <th style="background:#0f172a; color:#fff;">Completion %</th>
                      <th style="background:#0f172a; color:#fff;">Priority Tier</th>
                      <th style="background:#0f172a; color:#fff;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="unit in priorityUnits" :key="unit.key">
                      <tr :class="{ 'table-danger fw-semibold': unit.priority_tier === 'CRITICAL' }">
                        <td><span class="badge bg-light text-dark border">{{ unit.project_code }}</span></td>
                        <td class="fw-bold text-primary">{{ unit.jig_name }}</td>
                        <td class="fw-bold">{{ unit.unit_no }}</td>
                        <td>{{ unit.total_required }} / <span class="text-success fw-bold">{{ unit.total_received }}</span></td>
                        <td><span class="fw-bold text-danger">{{ unit.pending_quantity }}</span></td>
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
                          <span class="badge" :class="'bg-' + unit.badge_color" style="font-size: 0.72rem;">
                            {{ unit.priority_label }}
                          </span>
                        </td>
                        <td>
                          <button v-if="unit.pending_parts.length"
                            @click="expandedPriorityKey = expandedPriorityKey === unit.key ? null : unit.key"
                            class="btn btn-xs"
                            :class="expandedPriorityKey === unit.key ? 'btn-dark' : 'btn-outline-danger'">
                            <i class="fas" :class="expandedPriorityKey === unit.key ? 'fa-chevron-up' : 'fa-list-ul'"></i>
                            {{ expandedPriorityKey === unit.key ? 'Hide' : 'Parts (' + unit.pending_parts.length + ')' }}
                          </button>
                          <span v-else class="badge bg-success">Done</span>
                        </td>
                      </tr>

                      <!-- Expandable Pending Parts Details -->
                      <tr v-if="expandedPriorityKey === unit.key && unit.pending_parts.length" class="table-light">
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
                                  <tr v-for="part in unit.pending_parts" :key="part.bom_item_id + part.side">
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
                    <tr v-if="!priorityUnits.length">
                      <td colspan="8" class="text-center py-4 text-muted">No unit priority data available for the selected project.</td>
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
              <small class="text-muted d-block mb-3">Order the few missing parts for these top units to unlock assembly completion immediately.</small>
              <div style="height: 330px; position: relative;">
                <canvas ref="priorityChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- DAILY DEPARTMENT PARTS MOVEMENT MATRIX (Excel Spreadsheet Style) -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <h5 class="card-title fw-bold mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Daily Department Parts Movement Matrix</h5>
          <small class="text-muted">Tracking on which date which part was in which department</small>
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
                  <th style="width: 15%; color: #ffffff !important; background-color: #0f172a !important; font-weight: 700; font-size: 0.85rem;" title="Sum of unique parts moved across all departments on this date (Store + QC + Rework + Paint + Assembly)">DAILY TOTAL PARTS <i class="fas fa-info-circle text-warning ms-1" style="cursor:help;"></i></th>
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
                  <td colspan="8" class="text-center py-4 text-muted">No department movements recorded for the selected filter date range.</td>
                </tr>
              </tbody>
              <!-- Master Totals Row (Highlighted Yellow as per Excel template) -->
              <tfoot class="table-warning border-top border-dark border-2">
                <tr class="fw-bold fs-6">
                  <td class="text-uppercase">Total (Master Linked)</td>
                  <td class="text-success">{{ dailyTotals.store_received || 0 }}</td>
                  <td class="text-warning">{{ dailyTotals.qc_inspected || 0 }}</td>
                  <td class="text-danger">{{ dailyTotals.rework || 0 }}</td>
                  <td class="text-purple">{{ dailyTotals.paint || 0 }}</td>
                  <td class="text-primary">{{ dailyTotals.assembly || 0 }}</td>
                  <td class="bg-warning text-dark border-dark border-2 fs-5">{{ dailyTotals.grand_total || 0 }}</td>
                  <td><small class="text-dark">Master Total</small></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- PIPELINE TRANSPARENCY: WHERE IS EVERY PART RIGHT NOW -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <h5 class="card-title fw-bold mb-0"><i class="fas fa-route me-2 text-success"></i>Parts Pipeline Transparency — Where Is Every Part Right Now?</h5>
            <small class="text-muted">Live snapshot of all {{ pipelineParts.length }} parts and their current manufacturing stage</small>
          </div>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <select v-model="pipelineDeptFilter" class="form-select form-select-sm" style="width:170px;">
              <option value="">All Stages</option>
              <option value="STORE">📦 Store</option>
              <option value="QC">🔬 QC</option>
              <option value="REWORK">⚙️ Rework</option>
              <option value="PAINT">🎨 Paint</option>
              <option value="ASSEMBLY">🔩 Assembly</option>
              <option value="PURCHASE">🛒 Purchase Queue</option>
              <option value="DONE">✅ Done</option>
            </select>
            <input v-model="pipelineSearch" type="text" class="form-control form-control-sm" style="width:160px;" placeholder="🔍 Search Part / Project" />
            <span class="badge bg-success fs-6">{{ filteredPipeline.length }} Parts</span>
          </div>
        </div>
        <!-- Dept summary badges -->
        <div class="card-body py-2 px-3 border-bottom bg-light">
          <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-secondary px-3 py-2">📦 Store: {{ pipelineByDept.STORE || 0 }}</span>
            <span class="badge bg-info px-3 py-2">🔬 QC: {{ pipelineByDept.QC || 0 }}</span>
            <span class="badge bg-warning text-dark px-3 py-2">⚙️ Rework: {{ pipelineByDept.REWORK || 0 }}</span>
            <span class="badge px-3 py-2" style="background:#7c3aed; color:white;">🎨 Paint: {{ pipelineByDept.PAINT || 0 }}</span>
            <span class="badge bg-primary px-3 py-2">🔩 Assembly: {{ pipelineByDept.ASSEMBLY || 0 }}</span>
            <span class="badge bg-danger px-3 py-2">🛒 Purchase: {{ pipelineByDept.PURCHASE || 0 }}</span>
            <span class="badge bg-success px-3 py-2">✅ Done: {{ pipelineByDept.DONE || 0 }}</span>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0 small">
              <thead class="sticky-top" style="background: #0f172a; color: #fff; z-index: 1;">
                <tr>
                  <th style="background:#0f172a; color:#fff;">Part Number</th>
                  <th style="background:#0f172a; color:#fff;">Project</th>
                  <th style="background:#0f172a; color:#fff;">Side</th>
                  <th style="background:#0f172a; color:#fff;">Qty</th>
                  <th style="background:#0f172a; color:#fff;">Current Stage</th>
                  <th style="background:#0f172a; color:#fff;">Department</th>
                  <th style="background:#0f172a; color:#fff;">Last Updated</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="part in filteredPipeline" :key="part.id">
                  <td class="fw-bold text-primary">{{ part.standard_part_no }}</td>
                  <td><span class="badge bg-light text-dark border">{{ part.project_code || part.project }}</span></td>
                  <td><span class="badge bg-secondary">{{ part.side }}</span></td>
                  <td class="fw-bold">{{ part.quantity }}</td>
                  <td>
                    <span class="badge" :class="'bg-' + (part.stage_color || 'secondary')" style="font-size: 0.75rem; white-space: nowrap;">
                      {{ part.stage_label }}
                    </span>
                  </td>
                  <td>
                    <span class="badge" :class="{
                      'bg-secondary': part.department === 'STORE',
                      'bg-info': part.department === 'QC',
                      'bg-warning text-dark': part.department === 'REWORK',
                      'bg-primary': part.department === 'ASSEMBLY',
                      'bg-danger': part.department === 'PURCHASE',
                      'bg-success': part.department === 'DONE',
                    }" style="min-width:80px;">
                      {{ part.department }}
                    </span>
                  </td>
                  <td class="text-muted">{{ part.updated_at }}</td>
                </tr>
                <tr v-if="!filteredPipeline.length">
                  <td colspan="7" class="text-center py-4 text-muted">No parts found matching the filter.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Charts Row 1: Interactive Project Comparison & Status Distribution -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <h5 class="card-title fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i>Project Progress Breakdown & Comparison</h5>
              <small class="text-muted">Required vs Received vs Approved Parts per Project</small>
            </div>
            <div class="card-body p-3">
              <canvas ref="projectChartCanvas" style="max-height: 260px;"></canvas>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
              <h5 class="card-title fw-bold mb-0"><i class="fas fa-chart-pie me-2 text-info"></i>Part Status Distribution</h5>
            </div>
            <div class="card-body p-3 d-flex justify-content-center align-items-center">
              <canvas ref="statusChartCanvas" style="max-height: 260px;"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts Row 2: Bottleneck Analysis & Quality Trend -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <h5 class="card-title fw-bold mb-0"><i class="fas fa-hourglass-half me-2 text-danger"></i>Department Bottleneck Analysis</h5>
              <span class="badge bg-light text-muted border">Avg Days / Stage</span>
            </div>
            <div class="card-body p-3 position-relative">
              <div v-if="!bottleneckData?.sufficient_data" class="alert alert-warning text-center my-4 py-4">
                <i class="fas fa-exclamation-circle fa-2x mb-2 text-warning"></i>
                <h6>Insufficient historical data</h6>
                <p class="small text-muted mb-0">Workflow stage duration calculations require at least 2 completed events per stage.</p>
              </div>
              <canvas v-else ref="bottleneckChartCanvas" style="max-height: 260px;"></canvas>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
              <h5 class="card-title fw-bold mb-0"><i class="fas fa-chart-line me-2 text-warning"></i>30-Day Quality Inspection Trend</h5>
            </div>
            <div class="card-body p-3">
              <canvas ref="qualityChartCanvas" style="max-height: 260px;"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Two Panel Row: Filterable Recent Activity & Delayed Parts -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-7">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
              <h5 class="card-title fw-bold mb-0"><i class="fas fa-stream me-2 text-info"></i>Recent Workflow Activity Log</h5>
              <!-- Activity Filters Bar -->
              <div class="d-flex gap-2 align-items-center">
                <select v-model="activityFilterType" class="form-select form-select-sm" style="width: 140px;">
                  <option value="">All Events</option>
                  <option value="store_received">Store Received</option>
                  <option value="qc_inspected">QC Inspected</option>
                  <option value="rework">Rework</option>
                  <option value="paint">Paint</option>
                  <option value="assembly">Assembly</option>
                </select>
                <input type="text" v-model="activitySearch" class="form-control form-control-sm" placeholder="Filter Part / User..." style="width: 140px;" />
              </div>
            </div>
            <div class="card-body p-3 overflow-y-auto" style="max-height: 380px;">
              <ul class="list-group list-group-flush small">
                <li v-for="evt in filteredRecentEvents" :key="evt.id" class="list-group-item px-0 py-2 border-bottom">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="badge" :class="getEventTypeBadge(evt.event_type)">{{ evt.event_type }}</span>
                    <small class="text-muted">{{ formatDate(evt.created_at) }}</small>
                  </div>
                  <div class="fw-semibold mt-1">
                    {{ evt.bom_item?.standard_part_no || 'Part' }} ({{ evt.side }})
                    <span v-if="evt.user" class="text-muted fw-normal"> • {{ evt.user.name }}</span>
                  </div>
                  <div class="text-muted text-truncate">{{ evt.remarks || evt.notes }}</div>
                </li>
                <li v-if="!filteredRecentEvents.length" class="text-center text-muted py-4">No matching workflow activity log found.</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
              <h5 class="card-title fw-bold mb-0 text-danger"><i class="fas fa-clock me-2"></i>Delayed Parts (>3 Days Stuck)</h5>
              <span class="badge bg-danger">{{ delayedParts.length }} Alert{{ delayedParts.length !== 1 ? 's' : '' }}</span>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                  <thead class="table-light">
                    <tr>
                      <th>Part No</th>
                      <th>Project</th>
                      <th>Status</th>
                      <th>Stuck</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in delayedParts" :key="item.id">
                      <td class="fw-bold">{{ item.standard_part_no }} ({{ item.side }})</td>
                      <td>{{ item.project }}</td>
                      <td><span class="badge bg-warning text-dark">{{ item.status }}</span></td>
                      <td class="text-danger fw-bold">{{ item.duration_days }}d</td>
                    </tr>
                    <tr v-if="!delayedParts.length">
                      <td colspan="4" class="text-center py-4 text-success">
                        <i class="fas fa-check-circle me-1"></i> No delayed parts currently stuck!
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
                  <option value="">All Departments ({{ selectedDateRow.parts.length }})</option>
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
                      <input v-model="modalColFilters.partNo" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 90px;" placeholder="🔍 Part No" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.project" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 80px;" placeholder="🔍 Project" />
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
                      <input v-model="modalColFilters.dept" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 100px;" placeholder="🔍 Event" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.user" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 80px;" placeholder="🔍 User" />
                    </th>
                    <th style="background: #1e293b; padding: 4px 6px;">
                      <input v-model="modalColFilters.time" type="text" class="form-control form-control-sm" style="font-size:0.72rem; min-width: 70px;" placeholder="🔍 Time" />
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
                    <td colspan="7" class="text-center py-4 text-muted">No part movements match the selected filters.</td>
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
const delayedParts = ref([]);
const qualityTrend = ref([]);
const recentEvents = ref([]);
const projectsProgress = ref([]);
const bottleneckData = ref(null);

const dailyMatrix = ref([]);
const dailyTotals = ref({});
const selectedDateRow = ref(null);
const modalDeptFilter = ref('');
const modalColFilters = ref({ partNo: '', project: '', side: '', qty: '', dept: '', user: '', time: '' });

// Pipeline Transparency
const pipelineParts = ref([]);
const pipelineByDept = ref({});
const pipelineDeptFilter = ref('');
const pipelineSearch = ref('');

const activityFilterType = ref('');
const activitySearch = ref('');

const loading = ref(false);

const filters = ref({
  project_id: '',
  side: '',
  date_from: '',
  date_to: '',
});

// Computed Filtered Activity Logs
const filteredRecentEvents = computed(() => {
  return recentEvents.value.filter(evt => {
    if (activityFilterType.value) {
      if (activityFilterType.value === 'rework' && !evt.event_type.includes('rework')) return false;
      if (activityFilterType.value === 'paint' && !evt.event_type.includes('paint')) return false;
      if (activityFilterType.value === 'assembly' && !evt.event_type.includes('assembly')) return false;
      if (['store_received', 'qc_inspected'].includes(activityFilterType.value) && evt.event_type !== activityFilterType.value) return false;
    }
    if (activitySearch.value) {
      const query = activitySearch.value.toLowerCase();
      const partNo = (evt.bom_item?.standard_part_no || '').toLowerCase();
      const user = (evt.user?.name || '').toLowerCase();
      const remarks = (evt.remarks || evt.notes || '').toLowerCase();
      if (!partNo.includes(query) && !user.includes(query) && !remarks.includes(query)) return false;
    }
    return true;
  });
});

// Computed Filtered Modal Parts (department filter + Excel column filters)
const filteredModalParts = computed(() => {
  if (!selectedDateRow.value?.parts) return [];
  const cf = modalColFilters.value;
  return selectedDateRow.value.parts.filter(p => {
    // Dept top filter
    if (modalDeptFilter.value) {
      const evt = (p.department_event || '').toUpperCase();
      if (modalDeptFilter.value === 'STORE' && !evt.includes('STORE')) return false;
      if (modalDeptFilter.value === 'QC' && !evt.includes('QC')) return false;
      if (modalDeptFilter.value === 'REWORK' && !evt.includes('REWORK')) return false;
      if (modalDeptFilter.value === 'PAINT' && !evt.includes('PAINT')) return false;
      if (modalDeptFilter.value === 'ASSEMBLY' && !evt.includes('ASSEMBLY')) return false;
    }
    // Column filters
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

// Computed Filtered Pipeline Parts
const filteredPipeline = computed(() => {
  return pipelineParts.value.filter(p => {
    if (pipelineDeptFilter.value && p.department !== pipelineDeptFilter.value) return false;
    if (pipelineSearch.value) {
      const q = pipelineSearch.value.toLowerCase();
      if (!(p.standard_part_no || '').toLowerCase().includes(q) &&
          !(p.project || '').toLowerCase().includes(q) &&
          !(p.project_code || '').toLowerCase().includes(q)) return false;
    }
    return true;
  });
});

// Priority Map Transparency
const priorityUnits = ref([]);
const prioritySummary = ref({});
const priorityChartData = ref({});
const priorityProjectFilter = ref('');
const priorityProjectsList = ref([]);
const expandedPriorityKey = ref(null);

// Chart canvas refs & instances
const priorityChartCanvas = ref(null);
const projectChartCanvas = ref(null);
const statusChartCanvas = ref(null);
const bottleneckChartCanvas = ref(null);
const qualityChartCanvas = ref(null);

let priorityChart = null;
let projectChart = null;
let statusChart = null;
let bottleneckChart = null;
let qualityChart = null;

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  fetchData();
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

const fetchData = async () => {
  loading.value = true;
  try {
    const params = new URLSearchParams(
      Object.entries(filters.value).filter(([_, v]) => v !== '')
    ).toString();

    const [sumRes, botRes, movRes, pipeRes] = await Promise.all([
      axios.get(`/api/v1/dashboard/summary?${params}`),
      axios.get(`/api/v1/dashboard/bottleneck?${params}`),
      axios.get(`/api/v1/dashboard/daily-movement?${params}`),
      axios.get(`/api/v1/dashboard/pipeline-status?${params}`),
    ]);

    metrics.value = sumRes.data.summary || {};
    statusDistribution.value = sumRes.data.status_distribution || {};
    delayedParts.value = sumRes.data.delayed_parts || [];
    qualityTrend.value = sumRes.data.quality_trend || [];
    recentEvents.value = sumRes.data.recent_events || [];
    projectsProgress.value = sumRes.data.projects_progress || [];

    bottleneckData.value = botRes.data || null;

    dailyMatrix.value = movRes.data.matrix || [];
    dailyTotals.value = movRes.data.totals || {};

    pipelineParts.value = pipeRes.data.parts || [];
    pipelineByDept.value = pipeRes.data.by_dept || {};

    await fetchPriorityMap();

    await nextTick();
    renderCharts();
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
    prioritySummary.value = res.data.summary_counts || {};
    priorityProjectsList.value = res.data.projects || [];
    priorityChartData.value = res.data.chart || {};
    await nextTick();
    renderPriorityChart();
  } catch (err) {
    console.error('Failed to load priority map:', err);
  }
};

const renderPriorityChart = () => {
  if (priorityChart) priorityChart.destroy();
  if (priorityChartCanvas.value && priorityChartData.value.labels?.length) {
    const colors = (priorityChartData.value.tiers || []).map(t => {
      if (t === 'CRITICAL') return '#ef4444';
      if (t === 'HIGH') return '#f59e0b';
      if (t === 'MEDIUM') return '#06b6d4';
      return '#64748b';
    });

    priorityChart = new Chart(priorityChartCanvas.value, {
      type: 'bar',
      data: {
        labels: priorityChartData.value.labels.map((u, i) => `${priorityChartData.value.jigs[i]} ${u}`),
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
};

const renderCharts = () => {
  // 1. Interactive Project Progress Comparison Grouped Bar Chart
  if (projectChart) projectChart.destroy();
  if (projectChartCanvas.value && projectsProgress.value.length) {
    projectChart = new Chart(projectChartCanvas.value, {
      type: 'bar',
      data: {
        labels: projectsProgress.value.map(p => `${p.project_code} (${p.progress_percent}%)`),
        datasets: [
          {
            label: 'Required Parts',
            data: projectsProgress.value.map(p => p.required_qty),
            backgroundColor: '#94a3b8',
            borderRadius: 4,
          },
          {
            label: 'Store Received',
            data: projectsProgress.value.map(p => p.received_qty),
            backgroundColor: '#10b981',
            borderRadius: 4,
          },
          {
            label: 'QC Approved',
            data: projectsProgress.value.map(p => p.approved_qty),
            backgroundColor: '#0d9488',
            borderRadius: 4,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              footer: (items) => {
                const index = items[0].dataIndex;
                const proj = projectsProgress.value[index];
                return `Overall Completion Rate: ${proj.progress_percent}%`;
              }
            }
          }
        },
        scales: {
          y: { beginAtZero: true, title: { display: true, text: 'Quantity (Pcs)' } }
        }
      }
    });
  }

  // 2. Part Status Donut Chart
  if (statusChart) statusChart.destroy();
  if (statusChartCanvas.value) {
    const keys = Object.keys(statusDistribution.value);
    const values = Object.values(statusDistribution.value);
    statusChart = new Chart(statusChartCanvas.value, {
      type: 'doughnut',
      data: {
        labels: keys.map(k => k.toUpperCase().replace('_', ' ')),
        datasets: [{
          data: values.length ? values : [1],
          backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'],
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  // 3. Bottleneck Analysis Chart
  if (bottleneckChart) bottleneckChart.destroy();
  if (bottleneckChartCanvas.value && bottleneckData.value?.sufficient_data) {
    const stages = Object.values(bottleneckData.value.stages);
    bottleneckChart = new Chart(bottleneckChartCanvas.value, {
      type: 'bar',
      data: {
        labels: stages.map(s => s.stage),
        datasets: [{
          label: 'Avg Days',
          data: stages.map(s => s.avg_days || 0),
          backgroundColor: '#ef4444',
          borderRadius: 6,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
      }
    });
  }

  // 4. Quality Trend Line Chart
  if (qualityChart) qualityChart.destroy();
  if (qualityChartCanvas.value && qualityTrend.value.length) {
    qualityChart = new Chart(qualityChartCanvas.value, {
      type: 'line',
      data: {
        labels: qualityTrend.value.map(q => q.date),
        datasets: [
          { label: 'Approved', data: qualityTrend.value.map(q => q.approved), borderColor: '#10b981', fill: false, tension: 0.3 },
          { label: 'Rework', data: qualityTrend.value.map(q => q.rework), borderColor: '#f59e0b', fill: false, tension: 0.3 },
          { label: 'Rejected', data: qualityTrend.value.map(q => q.rejected), borderColor: '#ef4444', fill: false, tension: 0.3 },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }
};

const getEventTypeBadge = (type) => {
  switch (type) {
    case 'store_received': return 'bg-success';
    case 'sent_to_qc': return 'bg-info';
    case 'qc_inspected': return 'bg-warning text-dark';
    case 'rework_started': return 'bg-orange text-white';
    case 'rework_completed': return 'bg-purple text-white';
    case 'paint_completed': return 'bg-primary';
    case 'assembly_completed': return 'bg-dark';
    default: return 'bg-secondary';
  }
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

onMounted(() => {
  fetchData();
});
</script>

<style scoped>
.fs-7 {
  font-size: 0.75rem;
}
</style>
