<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-file-upload me-2 text-primary"></i>BOM Import Desk</h3>
                <p class="text-muted mb-0">Import legacy .xls or .xlsx BOM files and preview rows before saving.</p>
              </div>
              <span class="badge bg-primary px-3 py-2 fs-6">BOM Import</span>
            </div>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs card-header-tabs mt-3 border-0">
              <li class="nav-item">
                <button class="nav-link px-4 fw-bold" :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'import', 'text-secondary': activeTab !== 'import' }" @click="activeTab = 'import'">
                  <i class="fas fa-upload me-2"></i>Import Tool
                </button>
              </li>
              <li class="nav-item">
                <button class="nav-link px-4 fw-bold" :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'history', 'text-secondary': activeTab !== 'history' }" @click="activeTab = 'history'; fetchHistory();">
                  <i class="fas fa-history me-2"></i>Import History
                </button>
              </li>
            </ul>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

            <!-- TAB 1: IMPORT TOOL -->
            <div v-if="activeTab === 'import'">
              <form @submit.prevent="previewBom">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">Select BOM file</label>
                    <input class="form-control" type="file" accept=".xls,.xlsx" @change="handleFileChange" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label fw-semibold">Project Code</label>
                    <input v-model="projectCode" class="form-control" placeholder="e.g. FAA" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label fw-semibold">Project Name</label>
                    <input v-model="projectName" class="form-control" placeholder="e.g. FAA Project" />
                  </div>
                  <div class="col-md-12">
                    <label class="form-label fw-semibold">BOM path or filename</label>
                    <input v-model="path" class="form-control" placeholder="BOM/ERP BOM-62800-ST07-00-00-R.xls" />
                  </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                  <button class="btn btn-primary" :disabled="loading">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                    Preview BOM
                  </button>
                  <button type="button" class="btn btn-success" :disabled="loading || !canImport" @click="importBom">
                    Import BOM
                  </button>
                </div>
              </form>

              <div v-if="previewRows.length" class="table-responsive mt-4">
                <table class="table table-hover align-middle border-top mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Item</th>
                      <th>Standard Part No</th>
                      <th>RH</th>
                      <th>LH</th>
                      <th>Total</th>
                      <th>Parent</th>
                      <th>Supplier</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, index) in previewRows" :key="`${row.standard_part_no}-${index}`">
                      <td>{{ row.item_no }}</td>
                      <td class="fw-bold text-primary">{{ row.standard_part_no }}</td>
                      <td>{{ row.qty_rh }}</td>
                      <td>{{ row.qty_lh }}</td>
                      <td>{{ (Number(row.qty_rh || 0) + Number(row.qty_lh || 0)) }}</td>
                      <td>{{ row.parent }}</td>
                      <td>{{ row.supplier || '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div v-if="validationErrors.length" class="mt-4">
                <h6 class="fw-bold text-danger">Validation issues</h6>
                <ul class="mb-0">
                  <li v-for="(issue, index) in validationErrors" :key="`${issue}-${index}`">{{ issue }}</li>
                </ul>
              </div>
            </div>

            <!-- TAB 2: IMPORT HISTORY -->
            <div v-else-if="activeTab === 'history'">
              <div class="table-responsive">
                <table class="table table-hover align-middle border-top mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Filename</th>
                      <th>Project</th>
                      <th>Imported By</th>
                      <th>Parts Count</th>
                      <th>Status</th>
                      <th>Date & Timestamp</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="batch in importHistory" :key="batch.id">
                      <td class="fw-bold text-primary">{{ batch.filename }}</td>
                      <td>
                        <span class="badge bg-light text-dark border">
                          {{ batch.project?.name || '—' }} ({{ batch.project?.project_code || '—' }})
                        </span>
                      </td>
                      <td>{{ batch.importer?.name || 'System' }}</td>
                      <td class="fw-bold text-dark">{{ batch.total_rows || 0 }} parts</td>
                      <td>
                        <span class="badge" :class="{
                          'bg-success': batch.status === 'completed',
                          'bg-warning text-dark': batch.status === 'processing',
                          'bg-danger': batch.status === 'failed'
                        }">
                          {{ (batch.status || 'completed').toUpperCase() }}
                        </span>
                      </td>
                      <td class="text-muted">{{ formatTimestamp(batch.created_at) }}</td>
                    </tr>
                    <tr v-if="!importHistory.length">
                      <td colspan="6" class="text-center py-5 text-muted">
                        <i class="fas fa-history fa-3x mb-3 text-secondary"></i>
                        <p class="mb-0">No BOM import history found.</p>
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
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
const activeTab = ref('import'); // 'import' | 'history'
const importHistory = ref([]);

const selectedFile = ref(null);
const projectCode = ref('');
const projectName = ref('');
const path = ref('');
const previewRows = ref([]);
const validationErrors = ref([]);
const error = ref('');
const successMessage = ref('');
const loading = ref(false);

const canImport = computed(() => previewRows.value.length > 0 && validationErrors.value.length === 0);

const handleFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null;
  if (selectedFile.value) {
    path.value = '';
  }
};

const clearMessages = () => {
  error.value = '';
  successMessage.value = '';
};

const previewBom = async () => {
  clearMessages();
  loading.value = true;
  validationErrors.value = [];
  previewRows.value = [];

  try {
    const formData = new FormData();
    if (selectedFile.value) {
      formData.append('file', selectedFile.value);
    } else if (path.value) {
      formData.append('path', path.value);
    }

    const response = await axios.post('/api/v1/bom/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    previewRows.value = response.data.rows || [];
    validationErrors.value = response.data.errors || [];
    if (!previewRows.value.length) {
      successMessage.value = 'Preview completed. No rows were found in the selected BOM.';
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to preview BOM.';
  } finally {
    loading.value = false;
  }
};

const importBom = async () => {
  clearMessages();
  loading.value = true;

  try {
    const formData = new FormData();
    if (selectedFile.value) {
      formData.append('file', selectedFile.value);
    } else if (path.value) {
      formData.append('path', path.value);
    }
    if (projectCode.value) {
      formData.append('project_code', projectCode.value);
    }
    if (projectName.value) {
      formData.append('project_name', projectName.value);
    }

    const response = await axios.post('/api/v1/bom/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.success) {
      successMessage.value = response.data.message || 'BOM imported successfully.';
      previewRows.value = [];
      validationErrors.value = [];
      fetchHistory();
    } else {
      error.value = response.data.message || 'Import failed.';
      validationErrors.value = response.data.errors || [];
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to import BOM.';
    validationErrors.value = err.response?.data?.errors || [];
  } finally {
    loading.value = false;
  }
};

const fetchHistory = async () => {
  try {
    const res = await axios.get('/api/v1/bom/history');
    importHistory.value = res.data.history || [];
  } catch (err) {
    console.error('Failed to fetch BOM import history:', err);
  }
};

const formatTimestamp = (isoString) => {
  if (!isoString) return '';
  try {
    const d = new Date(isoString);
    if (isNaN(d.getTime())) return isoString;
    return d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  } catch (e) {
    return isoString;
  }
};

onMounted(() => {
  fetchHistory();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}

.table thead th {
  background-color: #1e293b !important;
  color: #ffffff !important;
  font-weight: 700;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  padding: 12px 16px;
}
</style>
