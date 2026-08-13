<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-boxes me-2 text-success"></i>Store Receiving Desk</h3>
                <p class="text-muted mb-0">Search spare parts by StandardPartNo, view RH/LH pending quantities, and record receipts.</p>
              </div>
              <span class="badge bg-success px-3 py-2 fs-6">Store Module</span>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger alert-dismissible fade show">
              <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
              <button type="button" class="btn-close" @click="error = ''"></button>
            </div>
            <div v-if="successMessage" class="alert alert-success alert-dismissible fade show">
              <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
              <button type="button" class="btn-close" @click="successMessage = ''"></button>
            </div>

            <!-- Fast Search & Filters Bar -->
            <div class="row g-3 mb-4">
              <div class="col-md-5">
                <label class="form-label fw-semibold"><i class="fas fa-search me-1 text-primary"></i>Fast Search (Part No / Size / Supplier)</label>
                <input v-model="searchQuery" @input="onSearchInput" class="form-control form-control-lg shadow-xs" placeholder="Type StandardPartNo e.g. 62800-ST7..." />
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold"><i class="fas fa-project-diagram me-1 text-info"></i>Project Selection</label>
                <select v-model="projectId" class="form-select form-select-lg shadow-xs" @change="onProjectChange">
                  <option value="">All Projects</option>
                  <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }} ({{ project.project_code }})</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold"><i class="fas fa-filter me-1 text-secondary"></i>Side Filter</label>
                <select v-model="side" class="form-select form-select-lg shadow-xs" @change="loadItems">
                  <option value="">All Sides (RH & LH)</option>
                  <option value="RH">RH (Right Hand)</option>
                  <option value="LH">LH (Left Hand)</option>
                  <option value="COMMON">COMMON</option>
                </select>
              </div>
            </div>

            <!-- HIERARCHICAL DRILLDOWN VIEW (FOR 62800 PROJECT) -->
            <div v-if="isHierarchical">
              <!-- Breadcrumbs Navigation -->
              <div class="d-flex align-items-center justify-content-between p-3 mb-4 bg-white border rounded shadow-xs">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb mb-0 fs-6 fw-bold">
                    <li class="breadcrumb-item">
                      <a href="#" @click.prevent="resetHierarchyBreadcrumb" class="text-primary text-decoration-none">
                        <i class="fas fa-folder me-1"></i>Project: {{ activeProjectName }}
                      </a>
                    </li>
                    <li v-if="selectedJig" class="breadcrumb-item">
                      <a href="#" @click.prevent="selectedUnit = null" class="text-primary text-decoration-none">
                        JIG: {{ selectedJig.jig_name }}
                      </a>
                    </li>
                    <li v-if="selectedUnit" class="breadcrumb-item active text-success">
                      {{ selectedUnit.unit_no }}
                    </li>
                  </ol>
                </nav>
                <div v-if="selectedJig || selectedUnit">
                  <button class="btn btn-outline-secondary btn-sm" @click="goBackHierarchy">
                    <i class="fas fa-arrow-left me-1"></i> Back
                  </button>
                </div>
              </div>

              <!-- LEVEL 1: JIG Cards Grid (when no JIG selected) -->
              <div v-if="!selectedJig">
                <h5 class="fw-bold mb-3"><i class="fas fa-cubes me-2 text-primary"></i>Assembly JIGs in {{ activeProjectName }}</h5>
                <div class="row g-3">
                  <div v-for="jig in hierarchyJigs" :key="jig.jig_name" class="col-md-6 col-lg-4">
                    <div class="card h-100 border-2 shadow-xs transition-card"
                      :class="jig.is_complete ? 'border-success bg-success-subtle' : 'border-light bg-white'"
                      style="cursor: pointer;"
                      @click="selectedJig = jig">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                          <h4 class="fw-bold mb-0" :class="jig.is_complete ? 'text-success' : 'text-dark'">
                            <i class="fas" :class="jig.is_complete ? 'fa-check-circle text-success' : 'fa-cog text-primary'"></i>
                            JIG: {{ jig.jig_name }}
                          </h4>
                          <span class="badge" :class="jig.is_complete ? 'bg-success' : 'bg-primary'">
                            {{ jig.is_complete ? '100% COMPLETE' : jig.completion_pct + '%' }}
                          </span>
                        </div>
                        <p class="text-muted small mb-3">
                          {{ jig.complete_units }} / {{ jig.total_units }} Units Complete | {{ jig.total_parts }} Parts
                        </p>
                        <div class="progress mb-3" style="height: 10px;">
                          <div class="progress-bar"
                            :class="jig.is_complete ? 'bg-success' : 'bg-primary'"
                            :style="{ width: jig.completion_pct + '%' }">
                          </div>
                        </div>
                        <button class="btn btn-sm w-100 fw-bold" :class="jig.is_complete ? 'btn-success' : 'btn-outline-primary'">
                          Explore Units inside {{ jig.jig_name }} <i class="fas fa-arrow-right ms-1"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <div v-if="!hierarchyJigs.length" class="col-12 text-center py-5 text-muted">
                    <i class="fas fa-folder-open fa-3x mb-3 text-secondary"></i>
                    <p>No JIG assemblies found for 62800 project.</p>
                  </div>
                </div>
              </div>

              <!-- LEVEL 2: Units Accordion List (when JIG selected, no Unit selected) -->
              <div v-else-if="selectedJig && !selectedUnit">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-layer-group me-2 text-primary"></i>
                    Units in JIG {{ selectedJig.jig_name }}
                  </h5>
                  <button class="btn btn-sm btn-outline-secondary" @click="selectedJig = null">
                    <i class="fas fa-arrow-left me-1"></i> Back to JIGs
                  </button>
                </div>

                <div class="row g-3">
                  <div v-for="unit in selectedJig.units" :key="unit.unit_no" class="col-md-6 col-lg-4">
                    <div class="card h-100 border-2 shadow-xs transition-card"
                      :class="unit.is_complete ? 'border-success bg-success-subtle' : 'border-secondary-subtle bg-white'"
                      style="cursor: pointer;"
                      @click="selectedUnit = unit">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                          <h5 class="fw-bold mb-0" :class="unit.is_complete ? 'text-success' : 'text-dark'">
                            <i class="fas me-1" :class="unit.is_complete ? 'fa-check-circle text-success' : 'fa-cube text-info'"></i>
                            {{ unit.unit_no }}
                          </h5>
                          <span class="badge" :class="unit.is_complete ? 'bg-success' : 'bg-warning text-dark'">
                            {{ unit.is_complete ? 'COMPLETED' : unit.completion_pct + '%' }}
                          </span>
                        </div>
                        <p class="text-muted small mb-2">
                          Required: {{ unit.total_required }} | Received: <span class="text-success fw-bold">{{ unit.total_received }}</span> | Pending: <span class="text-danger fw-bold">{{ unit.pending_quantity }}</span>
                        </p>
                        <div class="progress mb-3" style="height: 8px;">
                          <div class="progress-bar"
                            :class="unit.is_complete ? 'bg-success' : 'bg-warning'"
                            :style="{ width: unit.completion_pct + '%' }">
                          </div>
                        </div>
                        <button class="btn btn-sm w-100 fw-bold" :class="unit.is_complete ? 'btn-success' : 'btn-outline-secondary'">
                          View {{ unit.parts.length }} Parts <i class="fas fa-list ms-1"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- LEVEL 3: Parts Table for selected Unit -->
              <div v-else-if="selectedUnit">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-list me-2 text-success"></i>
                    Parts in JIG {{ selectedJig.jig_name }} - {{ selectedUnit.unit_no }}
                  </h5>
                  <button class="btn btn-sm btn-outline-secondary" @click="selectedUnit = null">
                    <i class="fas fa-arrow-left me-1"></i> Back to Units
                  </button>
                </div>

                <div class="table-responsive bg-white rounded border">
                  <table class="table table-hover align-middle border-top mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>Standard Part Number</th>
                        <th>Supplier</th>
                        <th>RH Status (Req / Rec / Pend)</th>
                        <th>LH Status (Req / Rec / Pend)</th>
                        <th style="width: 220px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Receive Stock' }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in selectedUnit.parts" :key="item.id">
                        <td>
                          <div class="fw-bold text-primary fs-6">{{ item.standard_part_no }}</div>
                          <small class="text-muted" v-if="item.item_no">Item #: {{ item.item_no }}</small>
                        </td>
                        <td>
                          <div class="small fw-semibold">{{ item.supplier?.name || item.supplier_name_raw || 'Standard' }}</div>
                        </td>

                        <!-- RH Breakdown -->
                        <td>
                          <div v-if="item.side_stats?.RH" class="small">
                            <span class="badge bg-primary me-1">Req: {{ item.side_stats.RH.required }}</span>
                            <span class="badge bg-success me-1">Rec: {{ item.side_stats.RH.received }}</span>
                            <span class="badge bg-danger" v-if="item.side_stats.RH.pending > 0">Pend: {{ item.side_stats.RH.pending }}</span>
                            <span class="badge bg-secondary" v-else>Fulfilled</span>
                          </div>
                          <span v-else class="text-muted small">N/A</span>
                        </td>

                        <!-- LH Breakdown -->
                        <td>
                          <div v-if="item.side_stats?.LH" class="small">
                            <span class="badge bg-primary me-1">Req: {{ item.side_stats.LH.required }}</span>
                            <span class="badge bg-success me-1">Rec: {{ item.side_stats.LH.received }}</span>
                            <span class="badge bg-danger" v-if="item.side_stats.LH.pending > 0">Pend: {{ item.side_stats.LH.pending }}</span>
                            <span class="badge bg-secondary" v-else>Fulfilled</span>
                          </div>
                          <span v-else class="text-muted small">N/A</span>
                        </td>

                        <!-- Action Column -->
                        <td>
                          <template v-if="authStore.userRole === 'MANAGER'">
                            <span v-if="item.side_stats && Object.values(item.side_stats).some(s => s.pending > 0)" class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                              <i class="fas fa-clock me-1"></i>AWAITING RECEIPT
                            </span>
                            <span v-else class="badge bg-success border px-3 py-2 w-100 d-block">
                              <i class="fas fa-check-circle me-1"></i>STORE RECEIVED
                            </span>
                          </template>
                          <template v-else>
                            <button class="btn btn-sm btn-success fw-bold text-nowrap" @click="openReceiveModal(item)" :disabled="item.side_stats && Object.values(item.side_stats).every(s => s.pending === 0)">
                              <i class="fas fa-plus me-1"></i> Receive Stock
                            </button>
                          </template>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- FLAT ITEMS TABLE (FOR OTHER PROJECTS) -->
            <div v-else class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Size / Specs</th>
                    <th>RH Status (Req / Rec / Pend)</th>
                    <th>LH Status (Req / Rec / Pend)</th>
                    <th style="width: 220px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Receive Stock' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in items" :key="item.id">
                    <td>
                      <div class="fw-bold text-dark fs-6">{{ item.standard_part_no }}</div>
                      <small class="text-muted" v-if="item.item_no">Item #: {{ item.item_no }}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ item.project?.project_code || 'N/A' }}</span></td>
                    <td>
                      <div class="small fw-semibold">{{ item.supplier?.name || item.supplier_name_raw || 'Standard' }}</div>
                    </td>
                    <td><small class="text-muted">{{ item.size || '—' }}</small></td>
                    
                    <!-- RH Breakdown -->
                    <td>
                      <div v-if="item.side_stats?.RH" class="small">
                        <span class="badge bg-primary me-1">Req: {{ item.side_stats.RH.required }}</span>
                        <span class="badge bg-success me-1">Rec: {{ item.side_stats.RH.received }}</span>
                        <span class="badge bg-danger" v-if="item.side_stats.RH.pending > 0">Pend: {{ item.side_stats.RH.pending }}</span>
                        <span class="badge bg-secondary" v-else>Fulfilled</span>
                      </div>
                      <span v-else class="text-muted small">N/A</span>
                    </td>

                    <!-- LH Breakdown -->
                    <td>
                      <div v-if="item.side_stats?.LH" class="small">
                        <span class="badge bg-primary me-1">Req: {{ item.side_stats.LH.required }}</span>
                        <span class="badge bg-success me-1">Rec: {{ item.side_stats.LH.received }}</span>
                        <span class="badge bg-danger" v-if="item.side_stats.LH.pending > 0">Pend: {{ item.side_stats.LH.pending }}</span>
                        <span class="badge bg-secondary" v-else>Fulfilled</span>
                      </div>
                      <span v-else class="text-muted small">N/A</span>
                    </td>

                    <!-- Action Column (Read-Only for Manager) -->
                    <td>
                      <template v-if="authStore.userRole === 'MANAGER'">
                        <span v-if="item.side_stats && Object.values(item.side_stats).some(s => s.pending > 0)" class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                          <i class="fas fa-clock me-1"></i>AWAITING RECEIPT
                        </span>
                        <span v-else class="badge bg-success border px-3 py-2 w-100 d-block">
                          <i class="fas fa-check-circle me-1"></i>STORE RECEIVED
                        </span>
                      </template>
                      <template v-else>
                        <button class="btn btn-sm btn-success fw-bold text-nowrap" @click="openReceiveModal(item)" :disabled="item.side_stats && Object.values(item.side_stats).every(s => s.pending === 0)">
                          <i class="fas fa-plus me-1"></i> Receive Stock
                        </button>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!items.length">
                    <td colspan="7" class="text-center py-5 text-muted">
                      <i class="fas fa-inbox fa-3x mb-3 text-secondary"></i>
                      <p class="mb-0">No spare parts found. Adjust your search or import a BOM file.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center mt-3" v-if="pagination.total > pagination.per_page">
              <span class="text-muted small">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} parts</span>
              <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page === 1" @click="changePage(pagination.current_page - 1)">Previous</button>
                <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page === pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Receive Stock Modal -->
    <div class="modal fade" id="receiveModal" tabindex="-1" ref="receiveModalRef">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title fw-bold"><i class="fas fa-box-open me-2"></i>Record Part Receipt</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeItem">
            <div class="alert alert-light border mb-3">
              <strong>Part:</strong> {{ activeItem.standard_part_no }}<br>
              <strong>Project:</strong> {{ activeItem.project?.name }} ({{ activeItem.project?.project_code }})
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Select Side</label>
              <select v-model="receiveForm.side" class="form-select">
                <option v-if="activeItem.side_stats?.RH" value="RH">RH (Pending: {{ activeItem.side_stats.RH.pending }})</option>
                <option v-if="activeItem.side_stats?.LH" value="LH">LH (Pending: {{ activeItem.side_stats.LH.pending }})</option>
                <option v-if="activeItem.side_stats?.COMMON" value="COMMON">COMMON (Pending: {{ activeItem.side_stats.COMMON.pending }})</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Received Quantity</label>
              <input type="number" v-model.number="receiveForm.received_quantity" min="1" class="form-control form-control-lg" placeholder="Enter arrived qty..." />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Delivery Note / Invoice #</label>
              <input type="text" v-model="receiveForm.delivery_note_number" class="form-control" placeholder="e.g. DN-98432" />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Remarks (Optional)</label>
              <textarea v-model="receiveForm.remarks" class="form-control" rows="2" placeholder="Condition or notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success fw-bold" @click="submitReceipt" :disabled="submitting">
              <i class="fas fa-check me-1"></i> Confirm & Send to QC Queue
            </button>
          </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
const items = ref([]);
const projects = ref([]);
const searchQuery = ref('');
const projectId = ref('');
const side = ref('');
const error = ref('');
const successMessage = ref('');
const isHierarchical = ref(false);
const hierarchyJigs = ref([]);
const hierarchyProject = ref(null);
const selectedJig = ref(null);
const selectedUnit = ref(null);

const activeProjectName = computed(() => {
  if (hierarchyProject.value) {
    return hierarchyProject.value.name + (hierarchyProject.value.project_code ? ` (${hierarchyProject.value.project_code})` : '');
  }
  const found = projects.value.find(p => String(p.id) === String(projectId.value));
  return found ? (found.name + (found.project_code ? ` (${found.project_code})` : '')) : 'Selected Project';
});

const onProjectChange = async () => {
  selectedJig.value = null;
  selectedUnit.value = null;
  await checkAndLoadStoreData();
};

const checkAndLoadStoreData = async () => {
  try {
    const res = await axios.get(`/api/v1/store/hierarchy?project_id=${projectId.value}`);
    if (res.data.projects) {
      projects.value = res.data.projects;
    }
    if (res.data.is_hierarchical) {
      isHierarchical.value = true;
      hierarchyJigs.value = res.data.jigs || [];
      hierarchyProject.value = res.data.project || null;
      // Keep selected project in dropdown
      if (res.data.project && !projectId.value) {
        projectId.value = res.data.project.id;
      }
    } else {
      isHierarchical.value = false;
      hierarchyProject.value = null;
      await loadItems();
    }
  } catch (err) {
    isHierarchical.value = false;
    hierarchyProject.value = null;
    await loadItems();
  }
};

const resetHierarchyBreadcrumb = () => {
  selectedJig.value = null;
  selectedUnit.value = null;
};

const goBackHierarchy = () => {
  if (selectedUnit.value) {
    selectedUnit.value = null;
  } else if (selectedJig.value) {
    selectedJig.value = null;
  }
};

const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
  from: 0,
  to: 0,
});

const activeItem = ref(null);
const receiveForm = ref({
  side: 'RH',
  received_quantity: 1,
  delivery_note_number: '',
  remarks: '',
});

let debounceTimer = null;
const onSearchInput = () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    pagination.value.current_page = 1;
    loadItems();
  }, 300); // 300ms Debounce
};

const loadItems = async (page = 1) => {
  try {
    const params = new URLSearchParams({ page, per_page: 100 });
    if (searchQuery.value) params.append('search', searchQuery.value);
    if (projectId.value) params.append('project_id', projectId.value);
    if (side.value) params.append('side', side.value);

    const response = await axios.get(`/api/v1/store/items?${params.toString()}`);
    items.value = response.data.items?.data || [];
    projects.value = response.data.projects || [];
    
    pagination.value = {
      current_page: response.data.items.current_page,
      last_page: response.data.items.last_page,
      per_page: response.data.items.per_page,
      total: response.data.items.total,
      from: response.data.items.from,
      to: response.data.items.to,
    };
  } catch (err) {
    error.value = 'Unable to load store items.';
  }
};

const changePage = (page) => {
  pagination.value.current_page = page;
  loadItems(page);
};

const openReceiveModal = (item) => {
  activeItem.value = item;
  const defaultSide = item.side_stats?.RH ? 'RH' : (item.side_stats?.LH ? 'LH' : 'COMMON');
  const pendingQty = item.side_stats?.[defaultSide]?.pending || 1;

  receiveForm.value = {
    side: defaultSide,
    received_quantity: pendingQty > 0 ? pendingQty : 1,
    delivery_note_number: '',
    remarks: '',
  };

  const modalEl = document.getElementById('receiveModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
};

const submitReceipt = async () => {
  if (!receiveForm.value.received_quantity || receiveForm.value.received_quantity < 1) {
    alert('Please enter a valid quantity.');
    return;
  }

  submitting.value = true;
  try {
    const payload = {
      project_id: activeItem.value.project_id,
      supplier_id: activeItem.value.supplier_id,
      delivery_note_number: receiveForm.value.delivery_note_number || 'DN-AUTO',
      remarks: receiveForm.value.remarks,
      items: [{
        bom_item_id: activeItem.value.id,
        side: receiveForm.value.side,
        received_quantity: receiveForm.value.received_quantity,
        remarks: receiveForm.value.remarks,
      }],
    };

    const res = await axios.post('/api/v1/store/receipts', payload);
    successMessage.value = res.data.message || 'Receipt recorded and sent to QC.';
    
    // Close modal
    const modalEl = document.getElementById('receiveModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    if (isHierarchical.value) {
      await checkAndLoadStoreData();
      // Keep selected unit & jig active so user stays in context
      if (selectedJig.value) {
        const updatedJig = hierarchyJigs.value.find(j => j.jig_name === selectedJig.value.jig_name);
        if (updatedJig) {
          selectedJig.value = updatedJig;
          if (selectedUnit.value) {
            const updatedUnit = updatedJig.units.find(u => u.unit_no === selectedUnit.value.unit_no);
            if (updatedUnit) selectedUnit.value = updatedUnit;
          }
        }
      }
    } else {
      loadItems(pagination.value.current_page);
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to record receipt.';
  } finally {
    submitting.value = false;
  }
};

onMounted(() => {
  checkAndLoadStoreData();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
.shadow-xs {
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
</style>
