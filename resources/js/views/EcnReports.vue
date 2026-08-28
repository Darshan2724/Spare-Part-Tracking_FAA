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
            <h4 class="mb-0 fw-bold text-dark">ECN Engineering Change Reports &amp; Analytics</h4>
            <small class="text-muted">Isolated Classification &bull; ECN KPI Ledger &bull; Project Breakdown &bull; Unit Hierarchy</small>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button @click="fetchEcnData" class="btn btn-outline-primary btn-sm text-nowrap" :disabled="loading">
            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh Live Data
          </button>
        </div>
      </div>

      <!-- Filters Bar -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 bg-light rounded">
          <div class="row g-2 align-items-center">
            <!-- Project Filter -->
            <div class="col-12 col-md-3">
              <label class="form-label small fw-bold mb-1"><i class="fas fa-filter me-1 text-primary"></i> Project</label>
              <select v-model="filters.project_id" @change="fetchEcnData" class="form-select form-select-sm">
                <option value="">All Projects</option>
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

            <!-- ECN Number Filter -->
            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">ECN Number</label>
              <input
                type="text"
                v-model="filters.ecn_number"
                @input="debounceFetch"
                placeholder="e.g. ECN-1"
                class="form-control form-control-sm"
              />
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
            <div class="col-12 col-md-1 d-flex align-items-end">
              <button @click="resetFilters" class="btn btn-outline-secondary btn-sm w-100 mt-2 mt-md-0" title="Reset Filters">
                <i class="fas fa-undo"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- 9 ECN KPI CARDS (Completely Isolated ECN Ledger) -->
      <div class="row g-2 mb-4">
        <!-- 1. Total Parts -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #4f46e5;">
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
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-success text-white h-100">
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
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-dark text-white h-100">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Parts Pending</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_pending || 0 }}</h3>
              </div>
              <i class="fas fa-truck-loading text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 4. Store Bay -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #d97706;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Store Bay</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_store || 0 }}</h3>
              </div>
              <i class="fas fa-warehouse text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 5. QC Bay -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0284c7;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">
                  QC Bay
                  <span v-if="summary.qc_rejected" class="badge rounded-pill bg-light text-dark ms-1 px-1 py-0" style="font-size: 0.65rem;">
                    Rej: {{ summary.qc_rejected }}
                  </span>
                </div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_qc || 0 }}</h3>
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
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_rework || 0 }}</h3>
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
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Paint Shop</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_paint || 0 }}</h3>
              </div>
              <i class="fas fa-paint-roller text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 8. Assembly Queue -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #db2777;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Assembly Queue</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_assembly || 0 }}</h3>
              </div>
              <i class="fas fa-cogs text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 9. Assembly Completed -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100" style="background-color: #0d9488;">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">Completed</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.assembly_completed || 0 }}</h3>
              </div>
              <i class="fas fa-check-circle text-white-50 fs-5"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- MAIN CONTENT: ECN Breakdown by Project / ECN Hierarchy -->
      <div v-if="!filters.project_id" class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
          <h5 class="fw-bold mb-0 text-dark">
            <i class="fas fa-project-diagram me-2 text-primary"></i>ECN Distribution by Project
          </h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-striped mb-0 small">
              <thead class="table-light">
                <tr>
                  <th>Project Code</th>
                  <th>Project Name</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="proj in allProjects" :key="proj.id">
                  <td><strong>{{ proj.project_code || proj.name }}</strong></td>
                  <td>{{ proj.name }}</td>
                  <td class="text-center">
                    <span class="badge" :class="proj.status === 'completed' ? 'bg-success' : 'bg-primary'">
                      {{ proj.status || 'active' }}
                    </span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary py-0" @click="filters.project_id = proj.id; fetchEcnData();">
                      <i class="fas fa-search me-1"></i>View Hierarchy
                    </button>
                  </td>
                </tr>
                <tr v-if="!allProjects.length">
                  <td colspan="4" class="text-center py-4 text-muted">No projects found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Selected Project ECN Hierarchy Tree -->
      <div v-else class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="fw-bold mb-0 text-dark">
              <i class="fas fa-sitemap me-2" style="color: #b45309;"></i>
              ECN Hierarchy: {{ selectedProjectObj?.project_code }} - {{ selectedProjectObj?.name }}
            </h5>
            <small class="text-muted">Breakdown by ECN Number &rarr; Jig &rarr; Unit &rarr; Parts</small>
          </div>
          <button class="btn btn-outline-secondary btn-sm" @click="filters.project_id = ''; fetchEcnData();">
            <i class="fas fa-arrow-left me-1"></i>All Projects View
          </button>
        </div>

        <div class="card-body p-3">
          <div v-if="!hierarchyNodes.length" class="text-center py-4 text-muted small">
            <i class="fas fa-info-circle fa-2x mb-2 d-block text-secondary opacity-50"></i>
            No ECN requirements registered for this project yet.
          </div>

          <!-- ECN Nodes Accordion -->
          <div v-else class="accordion" id="ecnAccordion">
            <div v-for="(ecnNode, eIdx) in hierarchyNodes" :key="ecnNode.ecn_number" class="accordion-item mb-2 border rounded shadow-sm">
              <h2 class="accordion-header" :id="'heading' + eIdx">
                <button
                  class="accordion-button py-2.5 px-3"
                  type="button"
                  data-bs-toggle="collapse"
                  :data-bs-target="'#collapse' + eIdx"
                  :aria-expanded="eIdx === 0 ? 'true' : 'false'"
                >
                  <div class="d-flex justify-content-between align-items-center w-100 me-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge text-white px-2.5 py-1" style="background-color: #b45309; font-size: 0.85rem;">
                        {{ ecnNode.ecn_number }}
                      </span>
                      <strong>{{ ecnNode.jigs?.length || 0 }} Jigs</strong>
                    </div>
                    <div class="small">
                      <span class="badge bg-secondary me-2">Required: {{ ecnNode.total_required }} pcs</span>
                      <span class="badge bg-success">Received: {{ ecnNode.total_received }} pcs</span>
                    </div>
                  </div>
                </button>
              </h2>
              <div
                :id="'collapse' + eIdx"
                class="accordion-collapse collapse"
                :class="{ 'show': eIdx === 0 }"
                :data-bs-parent="'#ecnAccordion'"
              >
                <div class="accordion-body p-3 bg-light">
                  <div v-for="jig in ecnNode.jigs" :key="jig.jig_no" class="card mb-3 border">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                      <span class="fw-bold text-dark"><i class="fas fa-tools me-1 text-primary"></i>Jig {{ jig.jig_no }}</span>
                      <span class="badge bg-primary">{{ jig.units?.length || 0 }} Units &bull; {{ jig.total_required }} pcs</span>
                    </div>
                    <div class="card-body p-2">
                      <div class="row g-2">
                        <div v-for="unit in jig.units" :key="unit.unit_no" class="col-12 col-md-6 col-xl-4">
                          <div class="p-2.5 bg-white border rounded shadow-sm">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                              <span class="fw-bold small text-dark">Unit {{ unit.unit_no }}</span>
                              <span class="badge bg-secondary small">{{ unit.parts?.length || 0 }} parts &bull; {{ unit.total_required }} pcs</span>
                            </div>
                            <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                              <table class="table table-sm table-striped mb-0 extra-small" style="font-size: 0.75rem;">
                                <thead>
                                  <tr>
                                    <th>Part No</th>
                                    <th>Side</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-center">State</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  <tr v-for="part in unit.parts" :key="part.id">
                                    <td><code>{{ part.part_no }}</code></td>
                                    <td><span class="badge bg-dark">{{ part.side_display }}</span></td>
                                    <td class="text-center fw-bold">{{ part.required_qty }}</td>
                                    <td class="text-center">
                                      <span class="badge bg-warning text-dark">{{ part.current_state }}</span>
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
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const loading = ref(false);
const allProjects = ref([]);
const activeProjects = ref([]);
const completedProjects = ref([]);
const selectedProjectObj = ref(null);
const hierarchyNodes = ref([]);

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
  completion_pct: 0,
});

const filters = ref({
  project_id: '',
  ecn_number: '',
  side: '',
  date_from: '',
  date_to: '',
});

let debounceTimer = null;
function debounceFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    fetchEcnData();
  }, 300);
}

function resetFilters() {
  filters.value = {
    project_id: '',
    ecn_number: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  fetchEcnData();
}

async function fetchEcnData() {
  loading.value = true;
  try {
    const params = { ...filters.value };
    const [summaryRes, hierarchyRes] = await Promise.all([
      axios.get('/api/v1/ecn/summary', { params }),
      axios.get('/api/v1/ecn/hierarchy', { params }),
    ]);

    summary.value = summaryRes.data.summary || summary.value;
    allProjects.value = hierarchyRes.data.projects || [];
    activeProjects.value = hierarchyRes.data.active_projects || [];
    completedProjects.value = hierarchyRes.data.completed_projects || [];
    selectedProjectObj.value = hierarchyRes.data.project || null;
    hierarchyNodes.value = hierarchyRes.data.ecn_nodes || [];
  } catch (err) {
    console.error('Failed to load ECN reports data', err);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  fetchEcnData();
});
</script>

<style scoped>
.extra-small {
  font-size: 0.72rem;
}
</style>
