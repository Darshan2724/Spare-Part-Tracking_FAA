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

      <!-- ROW 2: 9 ECN KPI CARDS (Completely Isolated ECN Ledger) -->
      <div class="row g-2 mb-4">
        <!-- 1. Total Parts -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
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
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm bg-success text-white h-100 kpi-card-interactive" @click="openKpiDrilldown('total_received', 'Total ECN Parts Received')">
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

        <!-- 4. Store Bay -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #d97706;" @click="openKpiDrilldown('parts_in_store', 'ECN Parts in Store Bay')">
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
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0284c7;" @click="openKpiDrilldown('parts_in_qc', 'ECN Parts in Quality Control')">
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size: 0.72rem;">QC Bay</div>
                <h3 class="fw-bold mb-0 fs-4">{{ summary.parts_in_qc || 0 }}</h3>
              </div>
              <i class="fas fa-clipboard-check text-white-50 fs-5"></i>
            </div>
          </div>
        </div>

        <!-- 6. Rework -->
        <div class="col-6 col-sm-4 col-md-3 col-xl">
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #ea580c;" @click="openKpiDrilldown('parts_in_rework', 'ECN Parts in Rework')">
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
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #7c3aed;" @click="openKpiDrilldown('parts_in_paint', 'ECN Parts in Paint Shop')">
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
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #db2777;" @click="openKpiDrilldown('parts_in_assembly', 'ECN Parts in Assembly Queue')">
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
          <div class="card border-0 shadow-sm text-white h-100 kpi-card-interactive" style="background-color: #0d9488;" @click="openKpiDrilldown('assembly_completed', 'ECN Assembly Completed Parts')">
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
              <div :id="'collapse' + eIdx" class="accordion-collapse collapse" :class="{ 'show': eIdx === 0 }" :data-bs-parent="'#ecnAccordion'">
                <div class="accordion-body p-3 bg-light">
                  <div v-for="jig in ecnNode.jigs" :key="jig.jig_no" class="card border mb-3">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                      <span class="fw-bold text-primary"><i class="fas fa-layer-group me-1"></i>JIG: {{ jig.jig_no }}</span>
                      <span class="badge bg-light text-dark border">Units: {{ jig.units?.length || 0 }}</span>
                    </div>
                    <div class="card-body p-2">
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
                                    <td><span class="badge bg-light text-dark border">{{ p.side_display || p.side }}</span></td>
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
      </div>

      <!-- KPI Interactive Drilldown Modal -->
      <div v-if="showDrilldownModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-2.5 px-3" style="background-color: #1e293b;">
              <div class="d-flex align-items-center gap-2">
                <i class="fas fa-list-alt text-warning"></i>
                <h6 class="modal-title fw-bold mb-0">{{ drilldownTitle }}</h6>
                <span class="badge bg-secondary ms-2">{{ drilldownData.length }} Records</span>
              </div>
              <button type="button" class="btn-close btn-close-white" @click="closeDrilldownModal"></button>
            </div>
            <div class="modal-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <input
                  type="text"
                  v-model="drilldownSearch"
                  placeholder="Search ECN part, number, jig, unit..."
                  class="form-control form-control-sm"
                  style="max-width: 300px;"
                />
                <button class="btn btn-outline-secondary btn-sm" @click="fetchDrilldownData">
                  <i class="fas fa-search me-1"></i>Filter
                </button>
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
                      <th class="text-end">Required</th>
                      <th class="text-end">Received</th>
                      <th>Current Stage</th>
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
                      <td class="fw-semibold">{{ item.project?.project_code || item.project_code || '—' }}</td>
                      <td>{{ item.jig_no }} / Unit {{ item.unit_no }}</td>
                      <td class="font-monospace fw-bold">{{ item.part_no || item.standard_part_no }}</td>
                      <td><span class="badge bg-light text-dark border">{{ item.side_display || item.side }}</span></td>
                      <td class="text-end">{{ item.required_qty || item.required_quantity || 0 }}</td>
                      <td class="text-end fw-bold text-success">{{ item.received_qty || item.received_quantity || 0 }}</td>
                      <td>
                        <span class="badge" :class="item.current_state === 'ASSEMBLY_COMPLETED' ? 'bg-success' : 'bg-warning text-dark'">
                          {{ item.current_state || 'ACTIVE' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!filteredDrilldownData.length">
                      <td colspan="9" class="text-center py-4 text-muted">No records match drilldown filters.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer bg-light py-2 px-3">
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
  parts_in_rework: 0,
  parts_in_paint: 0,
  parts_in_assembly: 0,
  assembly_completed: 0,
});

// Drilldown Modal
const showDrilldownModal = ref(false);
const drilldownTitle = ref('');
const drilldownData = ref([]);
const drilldownSearch = ref('');

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
    return (item.part_no || '').toLowerCase().includes(q) ||
           (item.ecn_number || '').toLowerCase().includes(q) ||
           (item.jig_no || '').toLowerCase().includes(q) ||
           (item.unit_no || '').toLowerCase().includes(q) ||
           (item.current_state || '').toLowerCase().includes(q);
  });
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

    const summaryRes = await axios.get('/api/v1/ecn/dashboard/summary', { params });
    summary.value = summaryRes.data.summary || {
      total_parts: 0,
      total_received: 0,
      parts_pending: 0,
      parts_in_store: 0,
      parts_in_qc: 0,
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
  fetchEcnData();
};

const resetFilters = () => {
  filters.value = {
    project_id: '',
    side: '',
    date_from: '',
    date_to: '',
  };
  fetchEcnData();
};

const openKpiDrilldown = async (kpiKey, title) => {
  drilldownTitle.value = title;
  drilldownSearch.value = '';
  showDrilldownModal.value = true;
  await fetchDrilldownData();
};

const fetchDrilldownData = async () => {
  try {
    const params = {
      project_id: filters.value.project_id || undefined,
      side: filters.value.side || undefined,
      date_from: filters.value.date_from || undefined,
      date_to: filters.value.date_to || undefined,
      per_page: 200,
    };
    const res = await axios.get('/api/v1/ecn/dashboard/drilldown', { params });
    drilldownData.value = res.data.items || res.data.data || [];
  } catch (err) {
    console.error('Failed to fetch drilldown data:', err);
    drilldownData.value = [];
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
