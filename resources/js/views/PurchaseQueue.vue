<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h3 class="fw-bold mb-1"><i class="fas fa-shopping-cart me-2 text-danger"></i>Purchase Reorder Queue</h3>
              <p class="text-muted mb-0">QC-rejected parts requiring vendor reorder. Export queue to CSV or PDF for procurement dispatch.</p>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-success fw-semibold shadow-xs" :disabled="exportLoading" @click="exportData('csv')">
                <span v-if="exportLoading && exportFormat === 'csv'" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-file-excel me-1"></i> Export CSV
              </button>
              <button class="btn btn-outline-danger fw-semibold shadow-xs" :disabled="exportLoading" @click="exportData('pdf')">
                <span v-if="exportLoading && exportFormat === 'pdf'" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-file-pdf me-1"></i> Export PDF
              </button>
            </div>
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
                  <th style="width: 190px;">Reorder Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in items" :key="item.id">
                  <td><strong class="text-primary fs-6">{{ item.standard_part_no || item.bom_item?.standard_part_no || 'N/A' }}</strong></td>
                  <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || item.project?.project_code || 'N/A' }}</span></td>
                  <td>{{ item.bom_item?.supplier?.name ?? item.bomItem?.supplier?.name ?? 'Standard' }}</td>
                  <td>
                    <span class="badge" :class="item.side === 'RH' ? 'badge-rh' : (item.side === 'LH' ? 'badge-lh' : 'badge-common')">
                      {{ item.side }}
                    </span>
                  </td>
                  <td><span class="fw-bold fs-6 text-danger">{{ item.rejected_quantity ?? item.reorder_quantity ?? 0 }} pcs</span></td>
                  <td><small class="text-danger fw-semibold">{{ item.rejection_reason || item.reorder_reason || 'QC Defect / Out of Tolerance' }}</small></td>
                  <td><small class="text-muted">{{ new Date(item.created_at).toLocaleDateString() }}</small></td>
                  <td>
                    <template v-if="authStore.userRole === 'MANAGER'">
                      <span class="badge px-3 py-2 w-100 d-block"
                        :class="{
                          'bg-danger': item.status === 'pending_purchase',
                          'bg-info text-white': item.status === 'exported',
                          'bg-primary': item.status === 'reordered',
                          'bg-success': item.status === 'closed'
                        }">
                        <i class="fas fa-shopping-cart me-1" v-if="item.status === 'pending_purchase'"></i>
                        <i class="fas fa-file-export me-1" v-else-if="item.status === 'exported'"></i>
                        <i class="fas fa-redo me-1" v-else-if="item.status === 'reordered'"></i>
                        <i class="fas fa-check-circle me-1" v-else></i>
                        {{ item.status === 'pending_purchase' ? '🛒 REORDER REQUIRED' : item.status?.toUpperCase() }}
                      </span>
                    </template>
                    <template v-else>
                      <select v-model="item.status" @change="updateStatus(item, item.status)" class="form-select form-select-sm shadow-xs">
                        <option value="pending_purchase">🛒 Pending Purchase</option>
                        <option value="exported">📄 Exported</option>
                        <option value="reordered">📦 Reordered</option>
                        <option value="closed">✅ Closed</option>
                      </select>
                    </template>
                  </td>
                </tr>
                <tr v-if="!items.length">
                  <td colspan="8" class="text-center py-5 text-muted">
                    <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                    <p class="mb-0 fs-6">No rejected items currently pending in the purchase queue.</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();

const items = ref([]);
const projects = ref([]);
const search = ref('');
const projectId = ref('');
const status = ref('');
const error = ref('');
const successMessage = ref('');

const loadItems = async () => {
  try {
    const params = new URLSearchParams();
    if (search.value) params.append('search', search.value);
    if (projectId.value) params.append('project_id', projectId.value);
    if (status.value) params.append('status', status.value);

    const res = await axios.get(`/api/v1/purchase/items?${params.toString()}`);
    items.value = res.data.items?.data || [];
    projects.value = res.data.projects || [];
  } catch (err) {
    error.value = 'Failed to load purchase queue.';
  }
};

const updateStatus = async (item, newStatus) => {
  try {
    const res = await axios.patch(`/api/v1/purchase/items/${item.id}/status`, {
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
  loadItems();
});
</script>
