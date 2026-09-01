<template>
  <div class="p-3 p-md-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm mb-4">
        <!-- Compact Clean Header with 4 Clearly Separated Tabs -->
        <div class="card-header bg-white border-bottom py-2.5 px-3 px-md-4">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="fas fa-shopping-bag text-primary" v-if="activeTab === 'rejected'"></i>
                <i class="fas fa-truck text-primary" v-else-if="activeTab === 'allocation'"></i>
                <i class="fas fa-table-list text-primary" v-else-if="activeTab === 'overview'"></i>
                <i class="fas fa-user-plus text-primary" v-else></i>
                <span>Purchase Desk</span>
              </h4>

              <!-- 4 Separated Tabs Switcher with clear spacing (gap-2) in exact required order -->
              <div class="d-flex flex-wrap align-items-center gap-2" role="tablist">
                <!-- TAB 1: Supplier Allocation -->
                <button 
                  type="button" 
                  class="btn btn-sm fw-semibold px-3 py-1.5 shadow-xs transition-all" 
                  :class="activeTab === 'allocation' ? 'btn-primary text-white' : 'btn-outline-secondary'"
                  @click="activeTab = 'allocation'"
                >
                  <i class="fas fa-truck me-1.5"></i>Supplier Allocation
                </button>

                <!-- TAB 2: Overview Table -->
                <button 
                  type="button" 
                  class="btn btn-sm fw-semibold px-3 py-1.5 shadow-xs transition-all" 
                  :class="activeTab === 'overview' ? 'btn-info text-white' : 'btn-outline-secondary'"
                  @click="activeTab = 'overview'"
                >
                  <i class="fas fa-table-list me-1.5"></i>Overview Table
                </button>

                <!-- TAB 3: Supplier Add -->
                <button 
                  type="button" 
                  class="btn btn-sm fw-semibold px-3 py-1.5 shadow-xs transition-all" 
                  :class="activeTab === 'supplier_add' ? 'btn-success text-white' : 'btn-outline-secondary'"
                  @click="activeTab = 'supplier_add'"
                >
                  <i class="fas fa-user-plus me-1.5"></i>Supplier Add
                </button>

                <!-- TAB 4: Rejected Parts -->
                <button 
                  type="button" 
                  class="btn btn-sm fw-semibold px-3 py-1.5 shadow-xs transition-all" 
                  :class="activeTab === 'rejected' ? 'btn-danger text-white' : 'btn-outline-secondary'"
                  @click="activeTab = 'rejected'"
                >
                  <i class="fas fa-shopping-cart me-1.5"></i>Rejected Parts
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card-body p-3 p-md-4">
          <!-- ========================================================================= -->
          <!-- TAB 1: REJECTED PARTS QUEUE (Preserved 100%)                              -->
          <!-- ========================================================================= -->
          <div v-if="activeTab === 'rejected'">
            <!-- Export Actions Bar -->
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2 text-danger"></i>Reorder Queue</h5>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-success btn-sm fw-semibold shadow-xs" :disabled="exportLoading" @click="exportData('csv')">
                  <span v-if="exportLoading && exportFormat === 'csv'" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-excel me-1"></i> Export CSV
                </button>
                <button class="btn btn-outline-danger btn-sm fw-semibold shadow-xs" :disabled="exportLoading" @click="exportData('pdf')">
                  <span v-if="exportLoading && exportFormat === 'pdf'" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-pdf me-1"></i> Export PDF
                </button>
              </div>
            </div>

            <div v-if="error" class="alert alert-danger alert-dismissible fade show shadow-xs">
              <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
              <button type="button" class="btn-close" @click="error = ''"></button>
            </div>
            <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs">
              <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
              <button type="button" class="btn-close" @click="successMessage = ''"></button>
            </div>

            <!-- Filters -->
            <div class="row g-3 mb-4">
              <div class="col-md-5">
                <label class="form-label fw-semibold"><i class="fas fa-search me-1 text-primary"></i>Search Part No or Reason</label>
                <input v-model="search" @input="loadItems" class="form-control form-control-lg shadow-xs" placeholder="Search Part Number or Reason..." />
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold"><i class="fas fa-project-diagram me-1 text-info"></i>Project Selection</label>
                <select v-model="projectId" @change="loadItems" class="form-select form-select-lg shadow-xs">
                  <option value="">All Projects</option>
                  <option v-for="proj in projects" :key="proj.id" :value="proj.id">{{ proj.name || proj.project_code }}</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold"><i class="fas fa-filter me-1 text-secondary"></i>Status Filter</label>
                <select v-model="status" @change="loadItems" class="form-select form-select-lg shadow-xs">
                  <option value="">All Statuses</option>
                  <option value="pending_purchase">🛒 Pending Purchase</option>
                  <option value="exported">Exported</option>
                  <option value="reordered">Reordered</option>
                  <option value="closed">Closed</option>
                </select>
              </div>
            </div>

            <!-- High-Contrast Table -->
            <div class="table-responsive bg-white rounded border shadow-xs">
              <table class="table table-hover align-middle border-top mb-0">
                <thead class="table-dark">
                  <tr>
                    <th>Standard Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Side</th>
                    <th>Rejected / Reorder Qty</th>
                    <th>Rejection Reason</th>
                    <th>Created Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in items" :key="item.id" :class="getRowClass(item.status)">
                    <td>
                      <span class="fw-bold font-monospace text-dark">{{ item.bom_item?.item_name || 'N/A' }}</span>
                      <div v-if="item.bom_item?.part_number" class="text-muted extra-small">
                        {{ item.bom_item.part_number }}
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border fw-semibold">
                        {{ item.project?.name || item.project?.project_code || 'Unknown' }}
                      </span>
                    </td>
                    <td>
                      <span class="text-secondary fw-semibold">{{ item.supplier?.name || 'N/A' }}</span>
                    </td>
                    <td>
                      <span :class="['badge', getSideBadgeClass(item.side)]">{{ item.side }}</span>
                    </td>
                    <td>
                      <span class="badge bg-danger fs-6 px-2.5 py-1.5 fw-bold shadow-xs">
                        {{ item.quantity }}
                      </span>
                    </td>
                    <td>
                      <span class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i>{{ item.rejection_reason || 'QC Defect' }}</span>
                    </td>
                    <td>
                      <small class="text-muted"><i class="far fa-calendar-alt me-1"></i>{{ formatDate(item.created_at) }}</small>
                    </td>
                    <td>
                      <span :class="['badge px-2 py-1', getStatusBadgeClass(item.status)]">
                        {{ formatStatus(item.status) }}
                      </span>
                    </td>
                    <td>
                      <div class="btn-group btn-group-sm">
                        <button 
                          v-if="item.status === 'pending_purchase'"
                          class="btn btn-primary fw-semibold shadow-xs"
                          @click="updateStatus(item.id, 'reordered')"
                        >
                          <i class="fas fa-shopping-cart me-1"></i> Mark Reordered
                        </button>
                        <button 
                          v-if="item.status === 'reordered'"
                          class="btn btn-success fw-semibold shadow-xs"
                          @click="updateStatus(item.id, 'closed')"
                        >
                          <i class="fas fa-check-circle me-1"></i> Close
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="items.length === 0">
                    <td colspan="9" class="text-center py-5 text-muted">
                      <i class="fas fa-check-circle text-success fs-1 mb-3 d-block"></i>
                      <strong>Reorder queue is clear!</strong> No rejected parts currently require reordering.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="d-flex justify-content-between align-items-center mt-3">
              <span class="small text-muted">Page {{ currentPage }} of {{ totalPages }}</span>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" :disabled="currentPage <= 1" @click="loadItems(currentPage - 1)">Previous</button>
                <button class="btn btn-outline-secondary" :disabled="currentPage >= totalPages" @click="loadItems(currentPage + 1)">Next</button>
              </div>
            </div>
          </div>

          <!-- ========================================================================= -->
          <!-- TAB 2: SUPPLIER ALLOCATION COMPONENT                                      -->
          <!-- ========================================================================= -->
          <SupplierAllocationTab v-else-if="activeTab === 'allocation'" />

          <!-- ========================================================================= -->
          <!-- TAB 3: OVERVIEW TABLE COMPONENT                                           -->
          <!-- ========================================================================= -->
          <SupplierOverviewTab v-else-if="activeTab === 'overview'" />

          <!-- ========================================================================= -->
          <!-- TAB 4: SUPPLIER ADD / MASTER / IMPORT COMPONENT                           -->
          <!-- ========================================================================= -->
          <SupplierAddTab v-else-if="activeTab === 'supplier_add'" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import SupplierAllocationTab from '@/components/SupplierAllocationTab.vue';
import SupplierOverviewTab from '@/components/SupplierOverviewTab.vue';
import SupplierAddTab from '@/components/SupplierAddTab.vue';

// 4 Top-Level Tabs: 'allocation', 'overview', 'supplier_add', 'rejected'
const activeTab = ref('allocation');

// Rejected Parts State
const items = ref([]);
const projects = ref([]);
const search = ref('');
const projectId = ref('');
const status = ref('pending_purchase');
const currentPage = ref(1);
const totalPages = ref(1);
const error = ref('');
const successMessage = ref('');

const loadItems = async (page = 1) => {
  error.value = '';
  try {
    const params = new URLSearchParams();
    params.append('page', page);
    if (search.value) params.append('search', search.value);
    if (projectId.value) params.append('project_id', projectId.value);
    if (status.value) params.append('status', status.value);

    const res = await axios.get(`/api/v1/purchase/queue?${params.toString()}`);
    items.value = res.data.data;
    currentPage.value = res.data.current_page;
    totalPages.value = res.data.last_page;
  } catch (err) {
    error.value = 'Failed to load purchase queue.';
  }
};

const loadProjects = async () => {
  try {
    const res = await axios.get('/api/v1/projects');
    projects.value = res.data.data || res.data;
  } catch (err) {
    console.error('Failed to load projects:', err);
  }
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });
};

const formatStatus = (s) => {
  switch (s) {
    case 'pending_purchase': return '🛒 Pending Purchase';
    case 'exported': return 'Exported';
    case 'reordered': return 'Reordered';
    case 'closed': return 'Closed';
    default: return s;
  }
};

const getStatusBadgeClass = (s) => {
  switch (s) {
    case 'pending_purchase': return 'bg-danger text-white';
    case 'exported': return 'bg-info text-dark';
    case 'reordered': return 'bg-warning text-dark';
    case 'closed': return 'bg-success text-white';
    default: return 'bg-secondary';
  }
};

const getSideBadgeClass = (side) => {
  switch (side) {
    case 'RH': return 'badge-rh';
    case 'LH': return 'badge-lh';
    default: return 'badge-common';
  }
};

const getRowClass = (s) => {
  return s === 'pending_purchase' ? 'table-danger bg-opacity-25' : '';
};

const updateStatus = async (id, newStatus) => {
  error.value = '';
  try {
    const res = await axios.patch(`/api/v1/purchase/queue/${id}`, {
      status: newStatus,
    });
    successMessage.value = res.data.message;
    loadItems();
  } catch (err) {
    error.value = 'Failed to update status.';
  }
};

const exportLoading = ref(false);
const exportFormat = ref('');

const exportData = async (format) => {
  exportLoading.value = true;
  exportFormat.value = format;
  error.value = '';
  try {
    const res = await axios.get(`/api/v1/purchase/export?format=${format}`, {
      responseType: 'blob',
    });
    const mimeType = format === 'pdf' ? 'application/pdf' : 'text/csv';
    const blob = new Blob([res.data], { type: mimeType });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `Purchase_Reorder_Queue_${new Date().toISOString().slice(0, 10)}.${format}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
    successMessage.value = `Purchase queue successfully exported as ${format.toUpperCase()}.`;
  } catch (err) {
    error.value = `Failed to export purchase queue as ${format.toUpperCase()}.`;
  } finally {
    exportLoading.value = false;
    exportFormat.value = '';
  }
};

onMounted(() => {
  loadProjects();
  loadItems();
});
</script>

<style scoped>
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.extra-small {
  font-size: 0.73rem;
}
.badge-rh {
  background-color: #6366f1;
  color: #fff;
}
.badge-lh {
  background-color: #0ea5e9;
  color: #fff;
}
.badge-common {
  background-color: #64748b;
  color: #fff;
}
.transition-all {
  transition: all 0.15s ease-in-out;
}
</style>
