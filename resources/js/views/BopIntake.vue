<template>
  <div class="p-4 bop-intake-container" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      
      <!-- HEADER CARD -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4 bg-white rounded">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="p-3 rounded-3 text-white shadow-sm" style="background: linear-gradient(135deg, #d97706 0%, #b45309 100%);">
                <i class="fas fa-shopping-cart fa-2x"></i>
              </div>
              <div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-warning text-dark fw-bold px-2 py-1">BOP INTAKE</span>
                  <h3 class="fw-bold mb-0 text-dark">Bought Out Parts (BOP) Desk</h3>
                </div>
                <p class="text-muted mb-0 small mt-1">
                  Website-only aggregated intake &amp; inventory tracking. Parts are grouped across all projects and follow 
                  <strong class="text-dark">Pending &rarr; Store &rarr; Assembly &rarr; Completed</strong>.
                </p>
              </div>
            </div>

            <div class="d-flex align-items-center gap-2">
              <button class="btn btn-outline-secondary btn-sm" @click="fetchBopData" :disabled="loading">
                <i class="fas fa-sync me-1" :class="{ 'fa-spin': loading }"></i> Refresh
              </button>
              <router-link to="/" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-chart-line me-1"></i> Dashboard
              </router-link>
            </div>
          </div>

          <!-- 6 KPI SUMMARY METRICS BANNER -->
          <div class="row g-2 mt-3 pt-3 border-top">
            <div class="col-6 col-sm-4 col-md-2">
              <div class="p-2 rounded bg-light border text-center h-100">
                <div class="text-muted extra-small text-uppercase fw-bold">Total Required</div>
                <div class="fs-4 fw-bold text-dark">{{ summaryStats.total_required }}</div>
                <div class="extra-small text-muted">{{ parts.length }} distinct parts</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
              <div class="p-2 rounded bg-success bg-opacity-10 border border-success-subtle text-center h-100">
                <div class="text-success extra-small text-uppercase fw-bold">Total Received</div>
                <div class="fs-4 fw-bold text-success">{{ summaryStats.total_received }}</div>
                <div class="extra-small text-success">{{ summaryStats.completion_pct }}% received</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
              <div class="p-2 rounded bg-dark text-white text-center h-100 cursor-pointer" @click="activeFilterStatus = 'pending'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Pending Intake</div>
                <div class="fs-4 fw-bold text-warning">{{ summaryStats.total_pending }}</div>
                <div class="extra-small text-white-50">Needs Store Intake</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #b45309;" @click="activeFilterStatus = 'store'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Store Bay</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_store }}</div>
                <div class="extra-small text-white-50">Ready for Assembly</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
              <div class="p-2 rounded text-white text-center h-100 cursor-pointer" style="background-color: #db2777;" @click="activeFilterStatus = 'assembly'">
                <div class="text-white-50 extra-small text-uppercase fw-bold">Assembly Bay</div>
                <div class="fs-4 fw-bold">{{ summaryStats.parts_in_assembly }}</div>
                <div class="extra-small text-white-50">In Production</div>
              </div>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
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
                <i class="fas fa-search me-1 text-warning"></i> Search Standard Part No / Size
              </label>
              <div class="input-group">
                <input 
                  type="text" 
                  v-model="searchQuery" 
                  class="form-control form-control-sm" 
                  placeholder="Filter by part no e.g. PU BLOCK BUSH, FA-HW..." 
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
              <select v-model="selectedProjectId" class="form-select form-select-sm" @change="fetchBopData">
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
              <div class="btn-group btn-group-sm w-100" role="group">
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
                  style="background-color: activeFilterStatus === 'store' ? '#b45309' : ''"
                  @click="activeFilterStatus = 'store'"
                >
                  Store ({{ countByStatus('store') }})
                </button>
                <button 
                  type="button" 
                  class="btn" 
                  :class="activeFilterStatus === 'assembly' ? 'btn-pink text-white' : 'btn-outline-secondary'"
                  style="background-color: activeFilterStatus === 'assembly' ? '#db2777' : ''"
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

      <!-- MAIN AGGREGATED PARTS TABLE -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark">
              <i class="fas fa-layer-group text-warning me-2"></i>
              BOP Aggregated Inventory ({{ filteredParts.length }} Parts)
            </h5>
            <span v-if="selectedProjectId" class="badge bg-primary">Project Filter Active</span>
          </div>
          <div class="small text-muted">
            Click <strong class="text-primary"><i class="fas fa-chevron-down"></i> Breakdown</strong> to view project/jig/unit allocations.
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 bop-table">
            <thead class="table-dark">
              <tr class="text-nowrap" style="font-size: 0.82rem;">
                <th style="width: 40px;">#</th>
                <th>Standard Part No</th>
                <th>Size / Spec</th>
                <th class="text-center" style="width: 90px;">Req (Qty)</th>
                <th class="text-center" style="width: 90px;">Rec (Qty)</th>
                <th class="text-center" style="width: 100px;">Pending Intake</th>
                <th class="text-center" style="width: 90px;">Store Bay</th>
                <th class="text-center" style="width: 90px;">Assembly</th>
                <th class="text-center" style="width: 90px;">Completed</th>
                <th style="width: 130px;">Progress</th>
                <th class="text-center" style="width: 180px;">Quick Workflow Action</th>
                <th class="text-center" style="width: 100px;">Breakdown</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(part, idx) in filteredParts" :key="part.standard_part_no">
                <tr :class="{ 'table-warning-subtle': expandedPartNo === part.standard_part_no }">
                  <td class="text-muted small">{{ idx + 1 }}</td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge bg-warning text-dark px-1.5 py-0.5 extra-small fw-bold">BOP</span>
                      <strong class="text-dark font-monospace fs-6">{{ part.standard_part_no }}</strong>
                    </div>
                    <div class="extra-small text-muted mt-0.5">
                      <span class="badge bg-light text-secondary border me-1">{{ part.distinct_projects }} Proj</span>
                      <span class="badge bg-light text-secondary border me-1">{{ part.distinct_jigs }} Jigs</span>
                      <span class="badge bg-light text-secondary border">{{ part.distinct_units }} Units</span>
                    </div>
                  </td>
                  <td class="small text-muted text-truncate" style="max-width: 180px;" :title="part.size || 'N/A'">
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

                  <!-- Assembly Bay Column -->
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

                  <!-- Action Column -->
                  <td class="text-center">
                    <div class="btn-group btn-group-sm">
                      <!-- 1. Receive to Store -->
                      <button 
                        v-if="part.total_pending > 0" 
                        class="btn btn-warning btn-sm text-dark fw-semibold" 
                        title="Intake arrived parts into Store"
                        @click="openTransitionModal(part, 'pending', 'store')"
                      >
                        <i class="fas fa-boxes me-1"></i> Receive
                      </button>

                      <!-- 2. Move to Assembly -->
                      <button 
                        v-if="part.parts_in_store > 0" 
                        class="btn btn-sm text-white fw-semibold" 
                        style="background-color: #db2777;"
                        title="Issue from Store to Assembly Bay"
                        @click="openTransitionModal(part, 'store', 'assembly')"
                      >
                        <i class="fas fa-cogs me-1"></i> Assembly
                      </button>

                      <!-- 3. Mark Assembled -->
                      <button 
                        v-if="part.parts_in_assembly > 0" 
                        class="btn btn-success btn-sm fw-semibold" 
                        title="Mark parts as fully assembled"
                        @click="openTransitionModal(part, 'assembly', 'completed')"
                      >
                        <i class="fas fa-check-double me-1"></i> Complete
                      </button>

                      <!-- Completed Tag if all done -->
                      <span v-if="part.total_pending === 0 && part.parts_in_store === 0 && part.parts_in_assembly === 0 && part.assembly_completed > 0" class="badge bg-success-subtle text-success border border-success px-2 py-1 extra-small">
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
                      <i class="fas" :class="expandedPartNo === part.standard_part_no ? 'fa-chevron-up text-primary' : 'fa-chevron-down'"></i>
                    </button>
                  </td>
                </tr>

                <!-- EXPANDABLE BREAKDOWN ROW -->
                <tr v-if="expandedPartNo === part.standard_part_no" class="bg-light">
                  <td colspan="12" class="p-3">
                    <div class="card border shadow-xs bg-white">
                      <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <span class="small fw-bold text-dark">
                          <i class="fas fa-sitemap me-1 text-warning"></i>
                          Unit-Wise Breakdown for <code>{{ part.standard_part_no }}</code>
                        </span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2 extra-small" @click="expandedPartNo = null">
                          Close &times;
                        </button>
                      </div>

                      <div v-if="loadingBreakdown" class="text-center py-4 text-muted">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2 text-warning"></i>
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
                              <th class="text-center">Store Bay</th>
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
                <td colspan="12" class="text-center py-5 text-muted">
                  <i class="fas fa-shopping-cart fa-3x mb-3 text-secondary opacity-50"></i>
                  <h6 class="fw-bold">No BOP parts match your filter</h6>
                  <p class="small mb-0">Try changing the search query or project selection.</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- MODAL: TRANSITION QUANTITY -->
    <div class="modal fade" id="bopTransitionModal" tabindex="-1" aria-hidden="true" ref="transitionModalRef">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header text-white" :style="modalHeaderStyle">
            <h5 class="modal-title fw-bold">
              <i class="fas fa-exchange-alt me-2"></i> {{ modalTitle }}
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-4">
            <div v-if="selectedPart">
              <div class="p-3 bg-light rounded mb-3 border">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="badge bg-warning text-dark">BOP PART</span>
                  <span class="small text-muted">{{ selectedPart.size || 'No Size' }}</span>
                </div>
                <h5 class="fw-bold font-monospace text-dark mt-1 mb-0">{{ selectedPart.standard_part_no }}</h5>
              </div>

              <!-- FROM -> TO PATH -->
              <div class="d-flex align-items-center justify-content-between p-2 rounded mb-3 bg-white border">
                <div class="text-center flex-grow-1">
                  <div class="extra-small text-muted text-uppercase fw-bold">Source Department</div>
                  <span class="badge px-3 py-1.5 text-uppercase fw-bold" :class="getStateBadgeClass(transitionForm.from_state)">
                    {{ transitionForm.from_state }}
                  </span>
                  <div class="small fw-bold text-dark mt-1">Available: {{ maxAvailableQuantity }} pcs</div>
                </div>

                <div class="px-2 text-muted fs-5">
                  <i class="fas fa-arrow-right text-warning"></i>
                </div>

                <div class="text-center flex-grow-1">
                  <div class="extra-small text-muted text-uppercase fw-bold">Target Department</div>
                  <span class="badge px-3 py-1.5 text-uppercase fw-bold" :class="getStateBadgeClass(transitionForm.to_state)">
                    {{ transitionForm.to_state }}
                  </span>
                </div>
              </div>

              <!-- QUANTITY INPUT WITH STEPPERS -->
              <div class="mb-3">
                <label class="form-label fw-bold d-flex justify-content-between align-items-center">
                  <span><i class="fas fa-cubes me-1 text-warning"></i> Quantity to Move</span>
                  <span class="badge bg-light text-dark border">Max: {{ maxAvailableQuantity }}</span>
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
                    class="btn btn-warning text-dark fw-bold" 
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

              <!-- PROJECT RESTRICTION (OPTIONAL) -->
              <div v-if="selectedProjectId" class="alert alert-info py-2 px-3 extra-small mb-0">
                <i class="fas fa-info-circle me-1"></i> Allocation will be constrained to the selected project filter.
              </div>
            </div>
          </div>

          <div class="modal-footer bg-light">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button 
              type="button" 
              class="btn btn-warning text-dark fw-bold" 
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

// Fetch BOP parts list from backend
async function fetchBopData() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params = {};
    if (selectedProjectId.value) {
      params.project_id = selectedProjectId.value;
    }
    const res = await axios.get('/api/v1/bop/parts', { params });
    if (res.data?.success) {
      parts.value = res.data.data?.parts || [];
      projectList.value = res.data.data?.projects || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to fetch BOP parts.';
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
  let parts_in_assembly = 0;
  let assembly_completed = 0;

  for (const p of parts.value) {
    total_required += (p.total_required || 0);
    total_received += (p.total_received || 0);
    total_pending += (p.total_pending || 0);
    parts_in_store += (p.parts_in_store || 0);
    parts_in_assembly += (p.parts_in_assembly || 0);
    assembly_completed += (p.assembly_completed || 0);
  }

  const completion_pct = total_required > 0 ? Math.round((total_received / total_required) * 100) : 0;

  return {
    total_required,
    total_received,
    total_pending,
    parts_in_store,
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
  if (status === 'assembly') {
    return parts.value.filter(p => p.parts_in_assembly > 0).length;
  }
  if (status === 'completed') {
    return parts.value.filter(p => p.assembly_completed > 0 && p.total_pending === 0 && p.parts_in_store === 0 && p.parts_in_assembly === 0).length;
  }
  return parts.value.length;
}

// Filtered list
const filteredParts = computed(() => {
  let list = parts.value;

  // Search filter
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.trim().toLowerCase();
    list = list.filter(p => 
      (p.standard_part_no && p.standard_part_no.toLowerCase().includes(q)) ||
      (p.size && p.size.toLowerCase().includes(q))
    );
  }

  // Department status tab filter
  if (activeFilterStatus.value === 'pending') {
    list = list.filter(p => p.total_pending > 0);
  } else if (activeFilterStatus.value === 'store') {
    list = list.filter(p => p.parts_in_store > 0);
  } else if (activeFilterStatus.value === 'assembly') {
    list = list.filter(p => p.parts_in_assembly > 0);
  } else if (activeFilterStatus.value === 'completed') {
    list = list.filter(p => p.assembly_completed > 0 && p.total_pending === 0 && p.parts_in_store === 0 && p.parts_in_assembly === 0);
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
    const res = await axios.get(`/api/v1/bop/parts/${encodeURIComponent(partNo)}/breakdown`, { params });
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

  // Default quantity to max available if 1 or more
  const max = getMaxQty(part, fromState);
  transitionForm.value.quantity = Math.max(1, Math.min(1, max));

  if (!modalInstance && transitionModalRef.value) {
    modalInstance = new bootstrap.Modal(transitionModalRef.value);
  }
  modalInstance?.show();
}

function getMaxQty(part, fromState) {
  if (!part) return 0;
  if (fromState === 'pending') return part.total_pending || 0;
  if (fromState === 'store') return part.parts_in_store || 0;
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

// Submit transition
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

    const res = await axios.post('/api/v1/bop/transition', payload);
    if (res.data?.success) {
      successMessage.value = res.data.message || 'Transition successful.';
      modalInstance?.hide();

      // Refresh list & breakdown if open
      await fetchBopData();
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

const modalTitle = computed(() => {
  if (transitionForm.value.from_state === 'pending') return 'Intake BOP Parts into Store';
  if (transitionForm.value.from_state === 'store') return 'Issue BOP Parts to Assembly';
  if (transitionForm.value.from_state === 'assembly') return 'Complete BOP Assembly';
  return 'Move BOP Parts';
});

const modalHeaderStyle = computed(() => {
  if (transitionForm.value.from_state === 'pending') return 'background: linear-gradient(135deg, #d97706 0%, #b45309 100%);';
  if (transitionForm.value.from_state === 'store') return 'background: linear-gradient(135deg, #db2777 0%, #be185d 100%);';
  return 'background: linear-gradient(135deg, #059669 0%, #047857 100%);';
});

function getStateBadgeClass(state) {
  if (state === 'pending') return 'bg-dark text-warning';
  if (state === 'store') return 'bg-warning text-dark';
  if (state === 'assembly') return 'bg-danger text-white';
  if (state === 'completed') return 'bg-success text-white';
  return 'bg-secondary text-white';
}

onMounted(() => {
  fetchBopData();
});
</script>

<style scoped>
.bop-intake-container {
  font-family: inherit;
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
.bop-table th {
  letter-spacing: 0.03em;
}
</style>
