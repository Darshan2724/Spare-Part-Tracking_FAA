<template>
  <div class="p-4 std-intake-container" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      
      <!-- HEADER CARD -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4 bg-white rounded">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="p-3 rounded-3 text-white shadow-sm" style="background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);">
                <i class="fas fa-wrench fa-2x"></i>
              </div>
              <div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-teal text-white fw-bold px-2 py-1">STD INTAKE</span>
                  <h3 class="fw-bold mb-0 text-dark">Standard Hardware (STD) Desk</h3>
                </div>
                <p class="text-muted mb-0 small mt-1">
                  Website-only aggregated intake &amp; department inventory tracking for fasteners, hardware, and standard components across all projects.
                </p>
              </div>
            </div>

            <div class="d-flex align-items-center gap-2">
              <button class="btn btn-outline-secondary btn-sm" @click="fetchStdData" :disabled="loading">
                <i class="fas fa-sync me-1" :class="{ 'fa-spin': loading }"></i> Refresh
              </button>
              <router-link to="/" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-chart-line me-1"></i> Dashboard
              </router-link>
            </div>
          </div>

          <!-- SUMMARY METRICS BANNER -->
          <div class="row g-2 mt-3 pt-3 border-top">
            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded bg-light border text-center h-100">
                <div class="text-muted extra-small text-uppercase fw-bold">Total Required</div>
                <div class="fs-4 fw-bold text-dark">{{ summaryStats.total_required }}</div>
                <div class="extra-small text-muted">{{ parts.length }} distinct parts</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded bg-success bg-opacity-10 border border-success-subtle text-center h-100">
                <div class="text-success extra-small text-uppercase fw-bold">Total Received</div>
                <div class="fs-4 fw-bold text-success">{{ summaryStats.total_received }}</div>
                <div class="extra-small text-success">{{ summaryStats.completion_pct }}% received</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded bg-dark text-white text-center h-100 cursor-pointer" @click="activeFilterStatus = 'pending'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Pending Intake</div>
                <div class="fs-4 fw-bold text-warning">{{ summaryStats.total_pending }}</div>
                <div class="extra-small text-white-50">Needs Intake</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #b45309;" @click="activeFilterStatus = 'store'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Store Bay</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_store }}</div>
                <div class="extra-small text-white-50">In Stock</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #0284c7;" @click="activeFilterStatus = 'qc'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">QC Bay</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_qc }}</div>
                <div class="extra-small text-white-50">Inspection</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #ea580c;" @click="activeFilterStatus = 'rework'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Rework Shop</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_rework }}</div>
                <div class="extra-small text-white-50">Defect Correction</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #7c3aed;" @click="activeFilterStatus = 'paint'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Paint Shop</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_paint }}</div>
                <div class="extra-small text-white-50">Surface Treatment</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #db2777;" @click="activeFilterStatus = 'assembly'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Assembly</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_assembly }}</div>
                <div class="extra-small text-white-50">Assembly Bay</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-3 col-xl">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #059669;" @click="activeFilterStatus = 'completed'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Completed</div>
                <div class="fs-4 fw-bold">{{ summaryStats.assembly_completed }}</div>
                <div class="extra-small text-white-50">Assembled &amp; Done</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ALERTS -->
      <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>{{ errorMessage }}
        <button type="button" class="btn-close" @click="errorMessage = ''"></button>
      </div>

      <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
        <button type="button" class="btn-close" @click="successMessage = ''"></button>
      </div>

      <!-- FILTERS & SEARCH CONTROL BAR -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3 bg-white rounded">
          <div class="row g-3 align-items-center">
            <div class="col-md-5 col-lg-4">
              <label class="form-label small fw-bold text-muted mb-1">
                <i class="fas fa-search me-1 text-teal"></i> Search Standard Part No / Size
              </label>
              <div class="input-group">
                <input 
                  type="text" 
                  v-model="searchQuery" 
                  class="form-control form-control-sm" 
                  placeholder="Filter by part no e.g. M6x20, FA-HW..." 
                />
                <button v-if="searchQuery" class="btn btn-outline-secondary btn-sm" @click="searchQuery = ''">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>

            <div class="col-md-4 col-lg-3">
              <label class="form-label small fw-bold text-muted mb-1">
                <i class="fas fa-project-diagram me-1 text-primary"></i> Project Filter
              </label>
              <select v-model="selectedProjectId" class="form-select form-select-sm" @change="fetchStdData">
                <option :value="null">All Active Projects (Aggregated)</option>
                <option v-for="proj in projectList" :key="proj.id" :value="proj.id">
                  {{ proj.name || proj.project_code }} ({{ proj.project_code }})
                </option>
              </select>
            </div>

            <div class="col-md-12 col-lg-5">
              <label class="form-label small fw-bold text-muted mb-1">
                <i class="fas fa-filter me-1 text-info"></i> Department Status Filter
              </label>
              <div class="btn-group btn-group-sm w-100 flex-wrap" role="group">
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'all' ? 'btn-dark' : 'btn-outline-secondary'"
                  @click="activeFilterStatus = 'all'"
                >
                  All ({{ parts.length }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'pending' ? 'btn-warning text-dark' : 'btn-outline-secondary'"
                  @click="activeFilterStatus = 'pending'"
                >
                  Pending ({{ countByStatus('pending') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'store' ? 'btn-amber text-white' : 'btn-outline-secondary'"
                  :style="activeFilterStatus === 'store' ? { backgroundColor: '#b45309' } : {}"
                  @click="activeFilterStatus = 'store'"
                >
                  Store ({{ countByStatus('store') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'qc' ? 'btn-info text-white' : 'btn-outline-secondary'"
                  :style="activeFilterStatus === 'qc' ? { backgroundColor: '#0284c7' } : {}"
                  @click="activeFilterStatus = 'qc'"
                >
                  QC ({{ countByStatus('qc') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'rework' ? 'btn-orange text-white' : 'btn-outline-secondary'"
                  :style="activeFilterStatus === 'rework' ? { backgroundColor: '#ea580c' } : {}"
                  @click="activeFilterStatus = 'rework'"
                >
                  Rework ({{ countByStatus('rework') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'paint' ? 'btn-purple text-white' : 'btn-outline-secondary'"
                  :style="activeFilterStatus === 'paint' ? { backgroundColor: '#7c3aed' } : {}"
                  @click="activeFilterStatus = 'paint'"
                >
                  Paint ({{ countByStatus('paint') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'assembly' ? 'btn-pink text-white' : 'btn-outline-secondary'"
                  :style="activeFilterStatus === 'assembly' ? { backgroundColor: '#db2777' } : {}"
                  @click="activeFilterStatus = 'assembly'"
                >
                  Assembly ({{ countByStatus('assembly') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'completed' ? 'btn-success' : 'btn-outline-secondary'"
                  @click="activeFilterStatus = 'completed'"
                >
                  Done ({{ countByStatus('completed') }})
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- MAIN AGGREGATED STD PARTS TABLE -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark">
              <i class="fas fa-layer-group text-teal me-2"></i>
              STD Aggregated Hardware Inventory ({{ filteredParts.length }} Parts)
            </h5>
            <span v-if="selectedProjectId" class="badge bg-primary">Project Filter Active</span>
          </div>
          <div class="small text-muted">
            Click <strong class="text-primary"><i class="fas fa-chevron-down"></i> Breakdown</strong> to view project/jig/unit allocations.
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 std-table">
            <thead class="table-dark">
              <tr class="text-nowrap" style="font-size: 0.82rem;">
                <th style="width: 40px;">#</th>
                <th>Standard Part No</th>
                <th>Size / Spec</th>
                <th class="text-center" style="width: 80px;">Req</th>
                <th class="text-center" style="width: 80px;">Rec</th>
                <th class="text-center" style="width: 85px;">Pending</th>
                <th class="text-center" style="width: 80px;">Store</th>
                <th class="text-center" style="width: 80px;">QC</th>
                <th class="text-center" style="width: 80px;">Rework</th>
                <th class="text-center" style="width: 80px;">Paint</th>
                <th class="text-center" style="width: 80px;">Assembly</th>
                <th class="text-center" style="width: 80px;">Completed</th>
                <th style="width: 120px;">Progress</th>
                <th class="text-center" style="width: 220px;">Quick Movement</th>
                <th class="text-center" style="width: 80px;">Breakdown</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(part, idx) in filteredParts" :key="part.standard_part_no">
                <tr :class="{ 'table-info-subtle': expandedPartNo === part.standard_part_no }">
                  <td class="text-muted small">{{ idx + 1 }}</td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge bg-teal text-white px-1.5 py-0.5 extra-small fw-bold">STD</span>
                      <strong class="text-dark font-monospace fs-6">{{ part.standard_part_no }}</strong>
                    </div>
                    <div class="extra-small text-muted mt-0.5">
                      <span class="badge bg-light text-secondary border me-1">{{ part.distinct_projects }} Proj</span>
                      <span class="badge bg-light text-secondary border me-1">{{ part.distinct_jigs }} Jigs</span>
                      <span class="badge bg-light text-secondary border">{{ part.distinct_units }} Units</span>
                    </div>
                  </td>
                  <td class="small text-muted text-truncate" style="max-width: 160px;" :title="part.size || 'N/A'">
                    {{ part.size || '—' }}
                  </td>
                  <td class="text-center fw-bold">{{ part.total_required }}</td>
                  <td class="text-center fw-bold text-success">{{ part.total_received }}</td>
                  
                  <!-- Pending Intake Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.total_pending > 0" 
                      class="badge bg-dark text-warning px-2 py-1 fs-7 fw-bold"
                    >
                      {{ part.total_pending }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Store Bay Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.parts_in_store > 0" 
                      class="badge text-white px-2 py-1 fs-7 fw-bold"
                      style="background-color: #b45309;"
                    >
                      {{ part.parts_in_store }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- QC Bay Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.parts_in_qc > 0" 
                      class="badge text-white px-2 py-1 fs-7 fw-bold"
                      style="background-color: #0284c7;"
                    >
                      {{ part.parts_in_qc }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Rework Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.parts_in_rework > 0" 
                      class="badge text-white px-2 py-1 fs-7 fw-bold"
                      style="background-color: #ea580c;"
                    >
                      {{ part.parts_in_rework }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Paint Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.parts_in_paint > 0" 
                      class="badge text-white px-2 py-1 fs-7 fw-bold"
                      style="background-color: #7c3aed;"
                    >
                      {{ part.parts_in_paint }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Assembly Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.parts_in_assembly > 0" 
                      class="badge text-white px-2 py-1 fs-7 fw-bold"
                      style="background-color: #db2777;"
                    >
                      {{ part.parts_in_assembly }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Completed Column -->
                  <td class="text-center">
                    <span 
                      v-if="part.assembly_completed > 0" 
                      class="badge bg-success text-white px-2 py-1 fs-7 fw-bold"
                    >
                      {{ part.assembly_completed }}
                    </span>
                    <span v-else class="text-muted extra-small">0</span>
                  </td>

                  <!-- Progress Bar Column -->
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div class="progress flex-grow-1" style="height: 6px;">
                        <div 
                          class="progress-bar bg-success" 
                          role="progressbar" 
                          :style="{ width: part.completion_pct + '%' }"
                        ></div>
                      </div>
                      <span class="extra-small fw-bold text-muted">{{ part.completion_pct }}%</span>
                    </div>
                  </td>

                  <!-- Action Column (Context-Sensitive STD Transitions) -->
                  <td class="text-center">
                    <div class="btn-group btn-group-sm flex-wrap gap-1 justify-content-center">
                      <!-- 1. Pending -> Store -->
                      <button 
                        v-if="part.total_pending > 0" 
                        class="btn btn-warning btn-sm text-dark fw-semibold" 
                        title="Intake arrived parts into Store"
                        @click="openTransitionModal(part, 'pending', 'store')"
                      >
                        <i class="fas fa-boxes me-1"></i> Receive
                      </button>

                      <!-- 2. Store -> QC -->
                      <button 
                        v-if="part.parts_in_store > 0" 
                        class="btn btn-sm text-white fw-semibold" 
                        style="background-color: #0284c7;"
                        title="Dispatch Store parts to QC Inspection"
                        @click="openTransitionModal(part, 'store', 'qc')"
                      >
                        <i class="fas fa-clipboard-check me-1"></i> To QC
                      </button>

                      <!-- 3. QC -> Assembly / Paint / Rework -->
                      <button 
                        v-if="part.parts_in_qc > 0" 
                        class="btn btn-sm text-white fw-semibold" 
                        style="background-color: #0d9488;"
                        title="Inspect & Route QC parts (Assembly/Paint/Rework)"
                        @click="openTransitionModal(part, 'qc', 'assembly')"
                      >
                        <i class="fas fa-route me-1"></i> QC Route
                      </button>

                      <!-- 4. Rework -> QC -->
                      <button 
                        v-if="part.parts_in_rework > 0" 
                        class="btn btn-sm text-white fw-semibold" 
                        style="background-color: #ea580c;"
                        title="Route reworked parts back to QC inspection"
                        @click="openTransitionModal(part, 'rework', 'qc')"
                      >
                        <i class="fas fa-tools me-1"></i> Rework
                      </button>

                      <!-- 5. Paint -> Assembly -->
                      <button 
                        v-if="part.parts_in_paint > 0" 
                        class="btn btn-sm text-white fw-semibold" 
                        style="background-color: #7c3aed;"
                        title="Move painted parts to Assembly Bay"
                        @click="openTransitionModal(part, 'paint', 'assembly')"
                      >
                        <i class="fas fa-cogs me-1"></i> To Asm
                      </button>

                      <!-- 6. Assembly -> Completed -->
                      <button 
                        v-if="part.parts_in_assembly > 0" 
                        class="btn btn-success btn-sm fw-semibold" 
                        title="Mark assembled parts as fully completed"
                        @click="openTransitionModal(part, 'assembly', 'completed')"
                      >
                        <i class="fas fa-check-double me-1"></i> Complete
                      </button>

                      <!-- Completed Tag if all done -->
                      <span v-if="part.total_pending === 0 && part.parts_in_store === 0 && part.parts_in_qc === 0 && part.parts_in_rework === 0 && part.parts_in_paint === 0 && part.parts_in_assembly === 0 && part.assembly_completed > 0" class="badge bg-success-subtle text-success border border-success px-2 py-1 extra-small">
                        <i class="fas fa-check-circle me-1"></i> All Assembled
                      </span>
                    </div>
                  </td>

                  <!-- Breakdown Toggle Column -->
                  <td class="text-center">
                    <button 
                      class="btn btn-sm btn-outline-secondary" 
                      @click="toggleBreakdown(part.standard_part_no)"
                      :title="expandedPartNo === part.standard_part_no ? 'Hide breakdown' : 'Show breakdown'"
                    >
                      <i class="fas" :class="expandedPartNo === part.standard_part_no ? 'fa-chevron-up text-teal' : 'fa-chevron-down'"></i>
                    </button>
                  </td>
                </tr>

                <!-- EXPANDABLE BREAKDOWN ROW -->
                <tr v-if="expandedPartNo === part.standard_part_no" class="bg-light">
                  <td colspan="15" class="p-3">
                    <div class="card border shadow-xs bg-white">
                      <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <span class="small fw-bold text-dark">
                          <i class="fas fa-sitemap me-1 text-teal"></i>
                          Unit-Wise Breakdown for <code>{{ part.standard_part_no }}</code>
                        </span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2 extra-small" @click="expandedPartNo = null">
                          Close &times;
                        </button>
                      </div>

                      <div v-if="loadingBreakdown" class="text-center py-4 text-muted">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2 text-teal"></i>
                        <p class="small mb-0">Loading project/unit traceability records...</p>
                      </div>

                      <div v-else-if="breakdownData.length === 0" class="text-center py-3 text-muted small">
                        No individual unit records found.
                      </div>

                      <div v-else class="table-responsive">
                        <table class="table table-sm table-bordered table-striped mb-0 extra-small">
                          <thead class="table-light">
                            <tr>
                              <th>Project</th>
                              <th>Jig No</th>
                              <th>Unit No</th>
                              <th>Side</th>
                              <th class="text-center">Req Qty</th>
                              <th class="text-center">Rec Qty</th>
                              <th class="text-center">Pending</th>
                              <th class="text-center">Store</th>
                              <th class="text-center">QC</th>
                              <th class="text-center">Rework</th>
                              <th class="text-center">Paint</th>
                              <th class="text-center">Assembly</th>
                              <th class="text-center">Completed</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="row in breakdownData" :key="row.unit_key">
                              <td class="fw-semibold">{{ row.project_name }} ({{ row.project_code }})</td>
                              <td><span class="badge bg-secondary">{{ row.jig_no }}</span></td>
                              <td>{{ row.unit_no }}</td>
                              <td><span class="badge bg-light text-dark border">{{ row.side }}</span></td>
                              <td class="text-center fw-bold">{{ row.required_quantity }}</td>
                              <td class="text-center fw-bold text-success">{{ row.received_quantity }}</td>
                              <td class="text-center">
                                <span v-if="row.pending_quantity > 0" class="badge bg-dark text-warning">{{ row.pending_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.store_quantity > 0" class="badge text-white" style="background-color: #b45309;">{{ row.store_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.qc_quantity > 0" class="badge text-white" style="background-color: #0284c7;">{{ row.qc_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.rework_quantity > 0" class="badge text-white" style="background-color: #ea580c;">{{ row.rework_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.paint_quantity > 0" class="badge text-white" style="background-color: #7c3aed;">{{ row.paint_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.assembly_quantity > 0" class="badge text-white" style="background-color: #db2777;">{{ row.assembly_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                              <td class="text-center">
                                <span v-if="row.completed_quantity > 0" class="badge bg-success">{{ row.completed_quantity }}</span>
                                <span v-else class="text-muted">0</span>
                              </td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>

              <tr v-if="!loading && filteredParts.length === 0">
                <td colspan="15" class="text-center py-5 text-muted">
                  <i class="fas fa-wrench fa-3x mb-3 text-secondary opacity-50"></i>
                  <h6 class="fw-bold">No STD parts match your filter</h6>
                  <p class="small mb-0">Try changing the search query or project selection.</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- MODAL: TRANSITION QUANTITY -->
    <div class="modal fade" id="stdTransitionModal" tabindex="-1" aria-hidden="true" ref="transitionModalRef">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header text-white" style="background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);">
            <h5 class="modal-title fw-bold">
              <i class="fas fa-exchange-alt me-2"></i> Move STD Hardware Parts
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-4">
            <div v-if="selectedPart">
              <div class="p-3 bg-light rounded mb-3 border">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="badge bg-teal text-white">STD HARDWARE</span>
                  <span class="small text-muted">{{ selectedPart.size || 'No Size' }}</span>
                </div>
                <h5 class="fw-bold font-monospace text-dark mt-1 mb-0">{{ selectedPart.standard_part_no }}</h5>
              </div>

              <!-- SOURCE SELECTION -->
              <div class="mb-3">
                <label class="form-label extra-small fw-bold text-muted text-uppercase mb-1">Source Department</label>
                <select v-model="transitionForm.from_state" class="form-select form-select-sm" @change="onSourceStateChange">
                  <option value="pending">Pending Intake ({{ selectedPart.total_pending || 0 }})</option>
                  <option value="store">Store Bay ({{ selectedPart.parts_in_store || 0 }})</option>
                  <option value="qc">QC Bay ({{ selectedPart.parts_in_qc || 0 }})</option>
                  <option value="rework">Rework Shop ({{ selectedPart.parts_in_rework || 0 }})</option>
                  <option value="paint">Paint Shop ({{ selectedPart.parts_in_paint || 0 }})</option>
                  <option value="assembly">Assembly Bay ({{ selectedPart.parts_in_assembly || 0 }})</option>
                </select>
              </div>

              <!-- IF QC BAY: 3-WAY DESTINATION ALLOCATION (REWORK, PAINT, ASSEMBLY) -->
              <div v-if="transitionForm.from_state === 'qc'" class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <label class="form-label extra-small fw-bold text-muted text-uppercase mb-0">
                    <i class="fas fa-sitemap me-1 text-teal"></i> Route to Destinations
                  </label>
                  <button 
                    type="button" 
                    class="btn btn-outline-secondary btn-sm py-0 px-2 extra-small"
                    @click="clearQcAllocations"
                    :disabled="totalQcAllocated === 0"
                  >
                    <i class="fas fa-eraser me-1"></i> Clear All
                  </button>
                </div>

                <!-- 1. REWORK DESTINATION -->
                <div class="card border mb-2 shadow-none" style="border-left: 4px solid #ea580c !important;">
                  <div class="card-body p-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="fw-bold fs-7 text-dark">
                        <i class="fas fa-tools me-1" style="color: #ea580c;"></i> Rework Shop
                      </span>
                      <span class="extra-small text-muted">Quality defect loop</span>
                    </div>
                    <div class="input-group input-group-sm">
                      <span class="input-group-text bg-white extra-small fw-semibold text-muted">Rework Qty</span>
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('rework', -1)"
                        :disabled="qcRouteForm.rework_quantity <= 0"
                      >
                        <i class="fas fa-minus"></i>
                      </button>
                      <input 
                        type="number" 
                        v-model.number="qcRouteForm.rework_quantity" 
                        min="0" 
                        :max="maxAvailableQuantity" 
                        class="form-control text-center fw-bold fs-6" 
                        placeholder="0"
                      />
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('rework', 1)"
                        :disabled="totalQcAllocated >= maxAvailableQuantity"
                      >
                        <i class="fas fa-plus"></i>
                      </button>
                      <button 
                        class="btn btn-sm fw-bold text-white" 
                        style="background-color: #ea580c;"
                        type="button" 
                        @click="setQcMax('rework')"
                      >
                        Max
                      </button>
                    </div>
                  </div>
                </div>

                <!-- 2. PAINT DESTINATION -->
                <div class="card border mb-2 shadow-none" style="border-left: 4px solid #7c3aed !important;">
                  <div class="card-body p-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="fw-bold fs-7 text-dark">
                        <i class="fas fa-paint-roller me-1" style="color: #7c3aed;"></i> Paint Shop
                      </span>
                      <span class="extra-small text-muted">Surface coating</span>
                    </div>
                    <div class="input-group input-group-sm">
                      <span class="input-group-text bg-white extra-small fw-semibold text-muted">Paint Qty</span>
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('paint', -1)"
                        :disabled="qcRouteForm.paint_quantity <= 0"
                      >
                        <i class="fas fa-minus"></i>
                      </button>
                      <input 
                        type="number" 
                        v-model.number="qcRouteForm.paint_quantity" 
                        min="0" 
                        :max="maxAvailableQuantity" 
                        class="form-control text-center fw-bold fs-6" 
                        placeholder="0"
                      />
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('paint', 1)"
                        :disabled="totalQcAllocated >= maxAvailableQuantity"
                      >
                        <i class="fas fa-plus"></i>
                      </button>
                      <button 
                        class="btn btn-sm fw-bold text-white" 
                        style="background-color: #7c3aed;"
                        type="button" 
                        @click="setQcMax('paint')"
                      >
                        Max
                      </button>
                    </div>
                  </div>
                </div>

                <!-- 3. ASSEMBLY DESTINATION -->
                <div class="card border mb-2 shadow-none" style="border-left: 4px solid #db2777 !important;">
                  <div class="card-body p-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="fw-bold fs-7 text-dark">
                        <i class="fas fa-cogs me-1" style="color: #db2777;"></i> Direct Assembly
                      </span>
                      <span class="extra-small text-muted">Bypass paint to assembly</span>
                    </div>
                    <div class="input-group input-group-sm">
                      <span class="input-group-text bg-white extra-small fw-semibold text-muted">Assembly Qty</span>
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('assembly', -1)"
                        :disabled="qcRouteForm.assembly_quantity <= 0"
                      >
                        <i class="fas fa-minus"></i>
                      </button>
                      <input 
                        type="number" 
                        v-model.number="qcRouteForm.assembly_quantity" 
                        min="0" 
                        :max="maxAvailableQuantity" 
                        class="form-control text-center fw-bold fs-6" 
                        placeholder="0"
                      />
                      <button 
                        class="btn btn-outline-secondary" 
                        type="button" 
                        @click="stepQcQty('assembly', 1)"
                        :disabled="totalQcAllocated >= maxAvailableQuantity"
                      >
                        <i class="fas fa-plus"></i>
                      </button>
                      <button 
                        class="btn btn-sm fw-bold text-white" 
                        style="background-color: #db2777;"
                        type="button" 
                        @click="setQcMax('assembly')"
                      >
                        Max
                      </button>
                    </div>
                  </div>
                </div>

                <!-- ALLOCATION SUMMARY BAR -->
                <div 
                  class="p-2.5 rounded border mt-2" 
                  :class="totalQcAllocated > maxAvailableQuantity ? 'bg-danger-subtle border-danger' : 'bg-light border-secondary-subtle'"
                >
                  <div class="d-flex justify-content-between align-items-center fs-7">
                    <div>
                      <span class="text-muted me-1">Available in QC:</span>
                      <strong class="text-dark">{{ maxAvailableQuantity }}</strong>
                    </div>
                    <div>
                      <span class="text-muted me-1">Allocated:</span>
                      <strong :class="totalQcAllocated > maxAvailableQuantity ? 'text-danger' : 'text-primary'">
                        {{ totalQcAllocated }}
                      </strong>
                    </div>
                    <div>
                      <span class="text-muted me-1">Remaining in QC:</span>
                      <strong :class="remainingQcAvailable < 0 ? 'text-danger' : 'text-success'">
                        {{ remainingQcAvailable }}
                      </strong>
                    </div>
                  </div>

                  <div v-if="totalQcAllocated > maxAvailableQuantity" class="extra-small text-danger fw-bold mt-1">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Over-allocation: Total entered ({{ totalQcAllocated }}) exceeds available QC quantity ({{ maxAvailableQuantity }}).
                  </div>
                  <div v-else-if="totalQcAllocated > 0 && remainingQcAvailable > 0" class="extra-small text-muted mt-1">
                    <i class="fas fa-info-circle me-1 text-info"></i>
                    Partial route: {{ remainingQcAvailable }} pcs will remain in QC Inspection.
                  </div>
                </div>
              </div>

              <!-- IF NON-QC: STANDARD SINGLE TARGET DEPARTMENT & QUANTITY INPUT -->
              <div v-else>
                <div class="mb-3">
                  <label class="form-label extra-small fw-bold text-muted text-uppercase mb-1">Target Department</label>
                  <select v-model="transitionForm.to_state" class="form-select form-select-sm">
                    <option v-if="transitionForm.from_state === 'pending'" value="store">Store Bay</option>
                    <option v-if="transitionForm.from_state === 'store'" value="qc">QC Bay</option>
                    <option v-if="transitionForm.from_state === 'rework'" value="qc">QC Inspection</option>
                    <option v-if="transitionForm.from_state === 'rework'" value="paint">Paint Shop</option>
                    <option v-if="transitionForm.from_state === 'rework'" value="assembly">Direct Assembly</option>
                    <option v-if="transitionForm.from_state === 'paint'" value="assembly">Assembly Bay</option>
                    <option v-if="transitionForm.from_state === 'assembly'" value="completed">Completed</option>
                  </select>
                </div>

                <!-- QUANTITY INPUT WITH STEPPERS -->
                <div class="mb-3">
                  <label class="form-label fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-cubes me-1 text-teal"></i> Quantity to Move</span>
                    <span class="badge bg-light text-dark border">Available: {{ maxAvailableQuantity }}</span>
                  </label>

                  <div class="input-group input-group-lg">
                    <button 
                      class="btn btn-outline-secondary" 
                      type="button" 
                      @click="stepQuantity(-1)"
                      :disabled="transitionForm.quantity <= 1"
                    >
                      <i class="fas fa-minus"></i>
                    </button>

                    <input 
                      type="number" 
                      v-model.number="transitionForm.quantity" 
                      min="1" 
                      :max="maxAvailableQuantity" 
                      class="form-control text-center fw-bold fs-4" 
                      placeholder="Enter quantity"
                    />

                    <button 
                      class="btn btn-outline-secondary" 
                      type="button" 
                      @click="stepQuantity(1)"
                      :disabled="transitionForm.quantity >= maxAvailableQuantity"
                    >
                      <i class="fas fa-plus"></i>
                    </button>

                    <button 
                      class="btn btn-teal text-white fw-bold" 
                      type="button" 
                      @click="transitionForm.quantity = maxAvailableQuantity"
                    >
                      Max
                    </button>
                  </div>
                  <div class="form-text extra-small text-muted mt-1">
                    Quantity will be automatically allocated across target projects/units in FIFO order.
                  </div>
                </div>
              </div>

              <!-- PROJECT RESTRICTION (OPTIONAL) -->
              <div v-if="selectedProjectId" class="alert alert-info py-2 px-3 extra-small mb-0 mt-2">
                <i class="fas fa-info-circle me-1"></i> Allocation will be constrained to the selected project filter.
              </div>
            </div>
          </div>

          <div class="modal-footer bg-light">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>

            <!-- When from_state === 'qc' -->
            <button 
              v-if="transitionForm.from_state === 'qc'"
              type="button" 
              class="btn btn-teal text-white fw-bold" 
              @click="submitQcRoute" 
              :disabled="submitting || isQcAllocationInvalid"
            >
              <i class="fas fa-check me-1" :class="{ 'fa-spin': submitting }"></i>
              {{ submitting ? 'Processing...' : 'Confirm QC Route' }}
            </button>

            <!-- When from_state !== 'qc' -->
            <button 
              v-else
              type="button" 
              class="btn btn-teal text-white fw-bold" 
              @click="submitTransition" 
              :disabled="submitting || transitionForm.quantity <= 0 || transitionForm.quantity > maxAvailableQuantity"
            >
              <i class="fas fa-check me-1" :class="{ 'fa-spin': submitting }"></i>
              {{ submitting ? 'Processing...' : 'Confirm Movement' }}
            </button>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import * as bootstrap from 'bootstrap';

const loading = ref(false);
const loadingBreakdown = ref(false);
const submitting = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const parts = ref([]);
const projectList = ref([]);
const selectedProjectId = ref(null);
const searchQuery = ref('');
const activeFilterStatus = ref('all');

const expandedPartNo = ref(null);
const breakdownData = ref([]);

const selectedPart = ref(null);
const transitionModalRef = ref(null);
let modalInstance = null;

const transitionForm = ref({
  standard_part_no: '',
  from_state: 'pending',
  to_state: 'store',
  quantity: 1,
});

const qcRouteForm = ref({
  rework_quantity: 0,
  paint_quantity: 0,
  assembly_quantity: 0,
});

const totalQcAllocated = computed(() => {
  const r = parseInt(qcRouteForm.value.rework_quantity) || 0;
  const p = parseInt(qcRouteForm.value.paint_quantity) || 0;
  const a = parseInt(qcRouteForm.value.assembly_quantity) || 0;
  return r + p + a;
});

const remainingQcAvailable = computed(() => {
  return maxAvailableQuantity.value - totalQcAllocated.value;
});

const isQcAllocationInvalid = computed(() => {
  const r = parseInt(qcRouteForm.value.rework_quantity) || 0;
  const p = parseInt(qcRouteForm.value.paint_quantity) || 0;
  const a = parseInt(qcRouteForm.value.assembly_quantity) || 0;
  if (r < 0 || p < 0 || a < 0) return true;
  if (totalQcAllocated.value <= 0) return true;
  if (totalQcAllocated.value > maxAvailableQuantity.value) return true;
  return false;
});

function stepQcQty(dest, delta) {
  const key = dest + '_quantity';
  const current = parseInt(qcRouteForm.value[key]) || 0;
  const next = current + delta;
  if (next < 0) return;
  if (delta > 0 && totalQcAllocated.value >= maxAvailableQuantity.value) return;
  qcRouteForm.value[key] = next;
}

function setQcMax(dest) {
  const key = dest + '_quantity';
  const otherSum = totalQcAllocated.value - (parseInt(qcRouteForm.value[key]) || 0);
  const remaining = Math.max(0, maxAvailableQuantity.value - otherSum);
  qcRouteForm.value[key] = remaining;
}

function clearQcAllocations() {
  qcRouteForm.value.rework_quantity = 0;
  qcRouteForm.value.paint_quantity = 0;
  qcRouteForm.value.assembly_quantity = 0;
}

// Fetch STD parts list from backend
async function fetchStdData() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params = {};
    if (selectedProjectId.value) {
      params.project_id = selectedProjectId.value;
    }
    const res = await axios.get('/api/v1/std/parts', { params });
    if (res.data?.success) {
      parts.value = res.data.data?.parts || [];
      projectList.value = res.data.data?.projects || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to fetch STD parts.';
  } finally {
    loading.value = false;
  }
}

// Summary stats computed
const summaryStats = computed(() => {
  let total_required = 0;
  let total_received = 0;
  let total_pending = 0;
  let parts_in_store = 0;
  let parts_in_qc = 0;
  let parts_in_rework = 0;
  let parts_in_paint = 0;
  let parts_in_assembly = 0;
  let assembly_completed = 0;

  for (const p of parts.value) {
    total_required += (p.total_required || 0);
    total_received += (p.total_received || 0);
    total_pending += (p.total_pending || 0);
    parts_in_store += (p.parts_in_store || 0);
    parts_in_qc += (p.parts_in_qc || 0);
    parts_in_rework += (p.parts_in_rework || 0);
    parts_in_paint += (p.parts_in_paint || 0);
    parts_in_assembly += (p.parts_in_assembly || 0);
    assembly_completed += (p.assembly_completed || 0);
  }

  const completion_pct = total_required > 0 ? Math.round((total_received / total_required) * 100) : 0;

  return {
    total_required,
    total_received,
    total_pending,
    parts_in_store,
    parts_in_qc,
    parts_in_rework,
    parts_in_paint,
    parts_in_assembly,
    assembly_completed,
    completion_pct,
  };
});

// Count parts by status for filter tabs
function countByStatus(status) {
  if (status === 'pending') {
    return parts.value.filter(p => p.total_pending > 0).length;
  }
  if (status === 'store') {
    return parts.value.filter(p => p.parts_in_store > 0).length;
  }
  if (status === 'qc') {
    return parts.value.filter(p => p.parts_in_qc > 0).length;
  }
  if (status === 'rework') {
    return parts.value.filter(p => p.parts_in_rework > 0).length;
  }
  if (status === 'paint') {
    return parts.value.filter(p => p.parts_in_paint > 0).length;
  }
  if (status === 'assembly') {
    return parts.value.filter(p => p.parts_in_assembly > 0).length;
  }
  if (status === 'completed') {
    return parts.value.filter(p => p.assembly_completed > 0 && p.total_pending === 0 && p.parts_in_store === 0 && p.parts_in_qc === 0 && p.parts_in_rework === 0 && p.parts_in_paint === 0 && p.parts_in_assembly === 0).length;
  }
  return parts.value.length;
}

// Filtered list
const filteredParts = computed(() => {
  let list = parts.value;

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.trim().toLowerCase();
    list = list.filter(p => 
      (p.standard_part_no && p.standard_part_no.toLowerCase().includes(q)) ||
      (p.size && p.size.toLowerCase().includes(q))
    );
  }

  if (activeFilterStatus.value === 'pending') {
    list = list.filter(p => p.total_pending > 0);
  } else if (activeFilterStatus.value === 'store') {
    list = list.filter(p => p.parts_in_store > 0);
  } else if (activeFilterStatus.value === 'qc') {
    list = list.filter(p => p.parts_in_qc > 0);
  } else if (activeFilterStatus.value === 'rework') {
    list = list.filter(p => p.parts_in_rework > 0);
  } else if (activeFilterStatus.value === 'paint') {
    list = list.filter(p => p.parts_in_paint > 0);
  } else if (activeFilterStatus.value === 'assembly') {
    list = list.filter(p => p.parts_in_assembly > 0);
  } else if (activeFilterStatus.value === 'completed') {
    list = list.filter(p => p.assembly_completed > 0 && p.total_pending === 0 && p.parts_in_store === 0 && p.parts_in_qc === 0 && p.parts_in_rework === 0 && p.parts_in_paint === 0 && p.parts_in_assembly === 0);
  }

  return list;
});

// Expand unit breakdown
async function toggleBreakdown(partNo) {
  if (expandedPartNo.value === partNo) {
    expandedPartNo.value = null;
    breakdownData.value = [];
    return;
  }

  expandedPartNo.value = partNo;
  loadingBreakdown.value = true;
  breakdownData.value = [];

  try {
    const params = {};
    if (selectedProjectId.value) {
      params.project_id = selectedProjectId.value;
    }
    const res = await axios.get(`/api/v1/std/parts/${encodeURIComponent(partNo)}/breakdown`, { params });
    if (res.data?.success) {
      breakdownData.value = res.data.data?.breakdown || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to fetch breakdown.';
  } finally {
    loadingBreakdown.value = false;
  }
}

// Open transition modal
function openTransitionModal(part, fromState, toState) {
  selectedPart.value = part;
  transitionForm.value = {
    standard_part_no: part.standard_part_no,
    from_state: fromState,
    to_state: toState,
    quantity: 1,
  };

  qcRouteForm.value = {
    rework_quantity: 0,
    paint_quantity: 0,
    assembly_quantity: 0,
  };

  const max = getMaxQty(part, fromState);
  transitionForm.value.quantity = Math.max(1, Math.min(1, max));

  if (!modalInstance && transitionModalRef.value) {
    modalInstance = new bootstrap.Modal(transitionModalRef.value);
  }
  modalInstance?.show();
}

function onSourceStateChange() {
  const from = transitionForm.value.from_state;
  if (from === 'pending') transitionForm.value.to_state = 'store';
  else if (from === 'store') transitionForm.value.to_state = 'qc';
  else if (from === 'qc') transitionForm.value.to_state = 'assembly';
  else if (from === 'rework') transitionForm.value.to_state = 'qc';
  else if (from === 'paint') transitionForm.value.to_state = 'assembly';
  else if (from === 'assembly') transitionForm.value.to_state = 'completed';

  qcRouteForm.value = {
    rework_quantity: 0,
    paint_quantity: 0,
    assembly_quantity: 0,
  };

  const max = getMaxQty(selectedPart.value, from);
  transitionForm.value.quantity = Math.max(1, Math.min(1, max));
}

function getMaxQty(part, fromState) {
  if (!part) return 0;
  if (fromState === 'pending') return part.total_pending || 0;
  if (fromState === 'store') return part.parts_in_store || 0;
  if (fromState === 'qc') return part.parts_in_qc || 0;
  if (fromState === 'rework') return part.parts_in_rework || 0;
  if (fromState === 'paint') return part.parts_in_paint || 0;
  if (fromState === 'assembly') return part.parts_in_assembly || 0;
  return 0;
}

const maxAvailableQuantity = computed(() => {
  return getMaxQty(selectedPart.value, transitionForm.value.from_state);
});

function stepQuantity(delta) {
  const current = transitionForm.value.quantity || 1;
  const target = current + delta;
  if (target >= 1 && target <= maxAvailableQuantity.value) {
    transitionForm.value.quantity = target;
  }
}

// Submit transition (Non-QC source)
async function submitTransition() {
  if (transitionForm.value.quantity <= 0 || transitionForm.value.quantity > maxAvailableQuantity.value) {
    errorMessage.value = 'Invalid quantity specified.';
    return;
  }

  submitting.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const payload = {
      standard_part_no: transitionForm.value.standard_part_no,
      from_state: transitionForm.value.from_state,
      to_state: transitionForm.value.to_state,
      quantity: transitionForm.value.quantity,
      project_id: selectedProjectId.value || null,
    };

    const res = await axios.post('/api/v1/std/transition', payload);
    if (res.data?.success) {
      successMessage.value = res.data.message || 'Transition successful.';
      modalInstance?.hide();

      await fetchStdData();
      if (expandedPartNo.value === transitionForm.value.standard_part_no) {
        toggleBreakdown(transitionForm.value.standard_part_no);
      }
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to transition parts.';
  } finally {
    submitting.value = false;
  }
}

// Submit QC Route (3-way distribution from QC source)
async function submitQcRoute() {
  if (isQcAllocationInvalid.value) {
    errorMessage.value = 'Invalid QC routing allocation.';
    return;
  }

  submitting.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const payload = {
      standard_part_no: transitionForm.value.standard_part_no,
      rework_quantity: parseInt(qcRouteForm.value.rework_quantity) || 0,
      paint_quantity: parseInt(qcRouteForm.value.paint_quantity) || 0,
      assembly_quantity: parseInt(qcRouteForm.value.assembly_quantity) || 0,
      project_id: selectedProjectId.value || null,
    };

    const res = await axios.post('/api/v1/std/qc-route', payload);
    if (res.data?.success) {
      successMessage.value = res.data.message || 'QC routing successful.';
      modalInstance?.hide();

      await fetchStdData();
      if (expandedPartNo.value === transitionForm.value.standard_part_no) {
        toggleBreakdown(transitionForm.value.standard_part_no);
      }
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to route QC parts.';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  fetchStdData();
});
</script>

<style scoped>
.std-intake-container {
  font-family: inherit;
}
.btn-teal {
  background-color: #0d9488;
  border-color: #0d9488;
}
.btn-teal:hover {
  background-color: #0f766e;
  border-color: #0f766e;
}
.text-teal {
  color: #0d9488 !important;
}
.bg-teal {
  background-color: #0d9488 !important;
}
.extra-small {
  font-size: 0.72rem;
}
.fs-7 {
  font-size: 0.78rem;
}
.cursor-pointer {
  cursor: pointer;
}
.std-table th {
  letter-spacing: 0.03em;
}
</style>
