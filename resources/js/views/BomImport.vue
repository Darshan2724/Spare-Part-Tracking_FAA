<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h3 class="fw-bold mb-1"><i class="fas fa-file-excel me-2 text-primary"></i>BOM Import Desk (FA-279 Standard)</h3>
              <p class="text-muted mb-0">Import official FA-279 MFG BOM format (<span class="fw-semibold text-dark">Project Code, Jig No, Unit No, Part No, Side, Qty</span>).</p>
            </div>
            <span class="badge bg-primary px-3 py-2 fs-6">FA-279 Standard</span>
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
          <!-- DUPLICATE BOM WARNING BANNER -->
          <div v-if="duplicateInfo" class="alert alert-warning border-warning shadow-sm mb-4">
            <div class="d-flex align-items-start">
              <i class="fas fa-ban fs-3 text-danger me-3 mt-1"></i>
              <div class="flex-grow-1">
                <h5 class="fw-bold text-dark mb-1"><i class="fas fa-copy me-2 text-danger"></i>BOM Already Imported (Duplicate Blocked)</h5>
                <p class="mb-2 text-dark">{{ duplicateInfo.message || 'This exact BOM file has already been imported and cannot be imported again.' }}</p>
                <div class="row g-2 small text-muted bg-white p-2 rounded border">
                  <div class="col-md-4"><strong>Original File:</strong> {{ duplicateInfo.original_filename || 'N/A' }}</div>
                  <div class="col-md-4"><strong>Import Date:</strong> {{ duplicateInfo.imported_at || 'N/A' }}</div>
                  <div class="col-md-4"><strong>Imported By:</strong> {{ duplicateInfo.imported_by || 'N/A' }}</div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="error" class="alert alert-danger shadow-sm d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fs-4 me-3"></i>
            <div>
              <div class="fw-bold">Import / Validation Error</div>
              <div>{{ error }}</div>
            </div>
          </div>

          <div v-if="successMessage" class="alert alert-success shadow-sm d-flex align-items-center">
            <i class="fas fa-check-circle fs-4 me-3"></i>
            <div>
              <div class="fw-bold">Success</div>
              <div>{{ successMessage }}</div>
            </div>
          </div>

          <!-- TAB 1: IMPORT TOOL -->
          <div v-if="activeTab === 'import'">
            <form @submit.prevent="previewBom">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fw-semibold">Upload BOM Excel File (.xlsx, .xls)</label>
                  <input class="form-control" type="file" accept=".xls,.xlsx" @change="handleFileChange" />
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-semibold">Or Server File Path / Filename</label>
                  <input v-model="path" class="form-control" placeholder="BOM/FA-279 NEW MFG BOM.xlsx" />
                </div>
              </div>

              <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4 fw-bold" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="fas fa-search me-2"></i>Preview & Validate
                </button>
                <button type="button" class="btn btn-success px-4 fw-bold" :disabled="loading || !canImport" @click="importBom">
                  <i class="fas fa-file-import me-2"></i>IMPORT BOM
                </button>
              </div>
            </form>

            <!-- Preview Summary Metrics Cards -->
            <div v-if="previewSummary" class="row g-3 mt-3">
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-primary text-white shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.total_projects || 0 }}</div>
                  <div class="small opacity-75">Projects</div>
                </div>
              </div>
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-info text-white shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.total_jigs || 0 }}</div>
                  <div class="small opacity-75">Assembly JIGs</div>
                </div>
              </div>
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-secondary text-white shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.total_units || 0 }}</div>
                  <div class="small opacity-75">Units</div>
                </div>
              </div>
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-dark text-white shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.unique_parts || 0 }}</div>
                  <div class="small opacity-75">Unique Parts</div>
                </div>
              </div>
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-warning text-dark shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.total_rows || 0 }}</div>
                  <div class="small opacity-75">Requirements (Rows)</div>
                </div>
              </div>
              <div class="col-6 col-md-2">
                <div class="card border-0 bg-success text-white shadow-sm p-3 text-center">
                  <div class="fs-4 fw-bold">{{ previewSummary.total_required_quantity || 0 }}</div>
                  <div class="small opacity-75">Total Pieces (Qty)</div>
                </div>
              </div>
            </div>

            <!-- Side Distribution Pills -->
            <div v-if="previewSummary?.side_distribution" class="d-flex gap-3 align-items-center mt-3 p-3 bg-white border rounded shadow-sm">
              <span class="fw-bold small text-secondary">Side Distribution:</span>
              <span class="badge bg-primary px-3 py-2">
                RH: {{ previewSummary.side_distribution.RH?.count || 0 }} reqs ({{ previewSummary.side_distribution.RH?.qty || 0 }} pcs)
              </span>
              <span class="badge bg-info text-dark px-3 py-2">
                LH: {{ previewSummary.side_distribution.LH?.count || 0 }} reqs ({{ previewSummary.side_distribution.LH?.qty || 0 }} pcs)
              </span>
              <span v-if="previewSummary.side_distribution.COMMON?.count > 0" class="badge bg-secondary px-3 py-2">
                COMMON: {{ previewSummary.side_distribution.COMMON?.count || 0 }} reqs ({{ previewSummary.side_distribution.COMMON?.qty || 0 }} pcs)
              </span>
            </div>

            <!-- Validation Warnings & Issues -->
            <div v-if="validationErrors.length" class="mt-4">
              <div class="alert alert-danger shadow-sm">
                <h6 class="fw-bold text-danger mb-2"><i class="fas fa-ban me-2"></i>Validation Issues ({{ validationErrors.length }})</h6>
                <ul class="mb-0 small" style="max-height: 200px; overflow-y: auto;">
                  <li v-for="(issue, index) in validationErrors" :key="`${issue}-${index}`">{{ issue }}</li>
                </ul>
              </div>
            </div>

            <!-- Preview Rows Table -->
            <div v-if="previewRows.length" class="table-responsive mt-4 shadow-sm border rounded">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                  <tr>
                    <th style="width: 70px;">#</th>
                    <th>Project Code</th>
                    <th>Jig No</th>
                    <th>Unit No</th>
                    <th>Part No</th>
                    <th>Side</th>
                    <th>Required Qty</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, index) in previewRows" :key="`${row.project_code}-${row.part_no}-${index}`">
                    <td class="text-muted small">{{ row.row_number || (index + 1) }}</td>
                    <td class="fw-bold text-primary">{{ row.project_code }}</td>
                    <td><span class="badge bg-light text-dark border">{{ row.jig_no }}</span></td>
                    <td><span class="badge bg-secondary">Unit {{ row.unit_no }}</span></td>
                    <td class="fw-bold text-dark font-monospace">{{ row.part_no }}</td>
                    <td>
                      <span class="badge" :class="{
                        'bg-primary': row.side === 'RH',
                        'bg-info text-dark': row.side === 'LH',
                        'bg-secondary': row.side === 'COMMON'
                      }">
                        {{ row.side }}
                      </span>
                    </td>
                    <td class="fw-bold text-success fs-6">{{ row.qty }}</td>
                  </tr>
                </tbody>
              </table>
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
                    <th>Requirements Count</th>
                    <th>Status</th>
                    <th>Date & Timestamp</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="batch in importHistory" :key="batch.id">
                    <td class="fw-bold text-primary">{{ cleanFilename(batch.filename) }}</td>
                    <td>
                      <span class="badge bg-light text-dark border">
                        {{ batch.project?.name || '—' }} ({{ batch.project?.project_code || '—' }})
                      </span>
                    </td>
                    <td>{{ batch.importer?.name || 'System' }}</td>
                    <td class="fw-bold text-dark">{{ batch.total_rows || 0 }} reqs</td>
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

const duplicateInfo = ref(null);
const selectedFile = ref(null);
const path = ref('BOM/FA-279 NEW MFG BOM.xlsx');
const previewRows = ref([]);
const previewSummary = ref(null);
const validationErrors = ref([]);
const error = ref('');
const successMessage = ref('');
const loading = ref(false);

const canImport = computed(() => previewRows.value.length > 0 && validationErrors.value.length === 0 && !duplicateInfo.value);

const handleFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null;
  duplicateInfo.value = null;
  if (selectedFile.value) {
    path.value = '';
  }
};

const clearMessages = () => {
  error.value = '';
  successMessage.value = '';
  duplicateInfo.value = null;
};

const previewBom = async () => {
  clearMessages();
  loading.value = true;
  validationErrors.value = [];
  previewRows.value = [];
  previewSummary.value = null;
  duplicateInfo.value = null;

  try {
    const formData = new FormData();
    if (selectedFile.value) {
      formData.append('file', selectedFile.value);
      formData.append('filename', selectedFile.value.name);
    } else if (path.value) {
      formData.append('path', path.value);
      formData.append('filename', path.value.split(/[\\/]/).pop());
    }

    const response = await axios.post('/api/v1/bom/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.is_duplicate) {
      duplicateInfo.value = response.data.duplicate_details || {
        message: response.data.message,
        original_filename: response.data.filename,
      };
      error.value = response.data.message || 'This exact BOM file has already been imported.';
      previewRows.value = [];
      previewSummary.value = null;
    } else {
      previewRows.value = response.data.rows || [];
      previewSummary.value = response.data.summary || null;
      validationErrors.value = response.data.errors || [];
      if (!previewRows.value.length && !validationErrors.value.length) {
        successMessage.value = 'Preview completed. No data rows were found in the selected BOM.';
      }
    }
  } catch (err) {
    if (err.response?.data?.is_duplicate) {
      duplicateInfo.value = err.response.data.duplicate_details || {
        message: err.response.data.message,
        original_filename: err.response.data.filename,
      };
    }
    error.value = err.response?.data?.message || err.response?.data?.errors?.[0] || 'Unable to preview BOM.';
    if (err.response?.data?.errors && Array.isArray(err.response?.data?.errors)) {
      validationErrors.value = err.response.data.errors;
    }
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
      formData.append('filename', selectedFile.value.name);
    } else if (path.value) {
      formData.append('path', path.value);
      formData.append('filename', path.value.split(/[\\/]/).pop());
    }

    const response = await axios.post('/api/v1/bom/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.success) {
      successMessage.value = response.data.message || 'BOM imported successfully.';
      previewRows.value = [];
      previewSummary.value = null;
      validationErrors.value = [];
      duplicateInfo.value = null;
      fetchHistory();
    } else {
      if (response.data.is_duplicate) {
        duplicateInfo.value = response.data.duplicate_details || {
          message: response.data.message,
        };
      }
      error.value = response.data.message || 'Import failed.';
      validationErrors.value = response.data.errors || [];
    }
  } catch (err) {
    if (err.response?.data?.is_duplicate) {
      duplicateInfo.value = err.response.data.duplicate_details || {
        message: err.response.data.message,
      };
    }
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

const cleanFilename = (fn) => {
  if (!fn) return 'BOM File';
  return fn.replace(/^.*[\\\/]/, '');
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
