<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-shopping-cart me-2 text-danger"></i>Purchase Reorder Queue</h3>
                <p class="text-muted mb-0">Rejected spare parts requiring reorder. Export queue to CSV or PDF for purchase department processing.</p>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-success" :disabled="exportLoading" @click="exportData('csv')">
                  <span v-if="exportLoading && exportFormat === 'csv'" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-excel me-1"></i> Export CSV
                </button>
                <button class="btn btn-outline-danger" :disabled="exportLoading" @click="exportData('pdf')">
                  <span v-if="exportLoading && exportFormat === 'pdf'" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-pdf me-1"></i> Export PDF
                </button>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

            <!-- Filters -->
            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <input v-model="search" @input="loadItems" class="form-control" placeholder="Search Part Number or Reason..." />
              </div>
              <div class="col-md-3">
                <select v-model="projectId" @change="loadItems" class="form-select">
                  <option value="">All Projects</option>
                  <option v-for="proj in projects" :key="proj.id" :value="proj.id">{{ proj.name }}</option>
                </select>
              </div>
              <div class="col-md-3">
                <select v-model="status" @change="loadItems" class="form-select">
                  <option value="">All Statuses</option>
                  <option value="pending_purchase">Pending Purchase</option>
                  <option value="exported">Exported</option>
                  <option value="reordered">Reordered</option>
                  <option value="closed">Closed</option>
                </select>
              </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Side</th>
                    <th>Quantity</th>
                    <th>Reorder Reason</th>
                    <th>Created At</th>
                    <th style="width: 160px;">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in items" :key="item.id">
                    <td><strong class="text-dark">{{ item.standard_part_no || item.bom_item?.standard_part_no || 'N/A' }}</strong></td>
                    <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || item.project?.project_code || 'N/A' }}</span></td>
                    <td>{{ item.bom_item?.supplier?.name ?? item.bomItem?.supplier?.name ?? 'Standard' }}</td>
                    <td><span class="badge bg-primary">{{ item.side }}</span></td>
                    <td><span class="fw-bold fs-6 text-danger">{{ item.rejected_quantity ?? item.reorder_quantity ?? 0 }}</span></td>
                    <td><small class="text-muted">{{ item.rejection_reason || item.reorder_reason || 'Defect / Reject' }}</small></td>
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
                        <select v-model="item.status" @change="updateStatus(item)" class="form-select form-select-sm">
                          <option value="pending_purchase">🛒 Pending Purchase</option>
                          <option value="exported">Exported</option>
                          <option value="reordered">Reordered</option>
                          <option value="closed">Closed</option>
                        </select>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!items.length">
                    <td colspan="8" class="text-center py-5 text-muted">
                      <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                      <p class="mb-0">No rejected items pending in purchase queue.</p>
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

const getStatusBadge = (st) => {
  switch (st) {
    case 'pending_purchase': return 'bg-danger';
    case 'exported': return 'bg-warning text-dark';
    case 'reordered': return 'bg-info text-white';
    case 'closed': return 'bg-success';
    default: return 'bg-secondary';
  }
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString();
};

onMounted(() => {
  loadItems();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
