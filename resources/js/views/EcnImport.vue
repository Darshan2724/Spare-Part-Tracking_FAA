<template>
  <div class="p-3 p-md-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm">
        
        <!-- Header -->
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h4 class="fw-bold mb-0 text-dark">
                <i class="fas fa-file-import me-2" style="color: #b45309;"></i>Import ECN (Engineering Change Notice)
              </h4>
              <p class="text-muted small mb-0 mt-1">Upload an ECN Master Sheet to attach isolated engineering change requirements to an existing project.</p>
            </div>
            <span class="badge px-3 py-2 text-white" style="background-color: #b45309; font-size: 0.8rem;">
              <i class="fas fa-shield-alt me-1"></i> Isolated Classification
            </span>
          </div>

          <!-- Tab Navigation -->
          <ul class="nav nav-tabs card-header-tabs mt-3 border-0">
            <li class="nav-item">
              <button
                class="nav-link px-3 py-2 fw-semibold"
                :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'import', 'text-secondary': activeTab !== 'import' }"
                @click="activeTab = 'import'"
              >
                <i class="fas fa-upload me-1"></i>Import ECN
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link px-3 py-2 fw-semibold"
                :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'history', 'text-secondary': activeTab !== 'history' }"
                @click="activeTab = 'history'; fetchHistory();"
              >
                <i class="fas fa-history me-1"></i>ECN Import History
              </button>
            </li>
          </ul>
        </div>

        <div class="card-body p-3 p-md-4">

          <!-- DUPLICATE WARNING ALERT -->
          <div v-if="duplicateInfo" class="alert alert-warning border-warning shadow-sm mb-3">
            <div class="d-flex align-items-start">
              <i class="fas fa-exclamation-triangle fs-4 text-warning me-3 mt-1"></i>
              <div class="flex-grow-1">
                <h6 class="fw-bold text-dark mb-1">
                  {{ duplicateInfo.error_title || 'Duplicate ECN File' }}
                </h6>
                <p class="mb-1 text-dark small">
                  {{ duplicateInfo.message }}
                </p>
                <div class="row g-2 small text-muted bg-white p-2 rounded border mt-2">
                  <div class="col-sm-6 col-md-4"><strong>Filename:</strong> {{ duplicateInfo.original_filename || 'N/A' }}</div>
                  <div class="col-sm-6 col-md-4"><strong>Imported On:</strong> {{ duplicateInfo.imported_at || 'N/A' }}</div>
                </div>
              </div>
            </div>
          </div>

          <!-- General Error Alert -->
          <div v-if="error && !duplicateInfo" class="alert alert-danger shadow-sm d-flex align-items-center mb-3 py-2">
            <i class="fas fa-exclamation-circle fs-5 me-2"></i>
            <div class="small">{{ error }}</div>
          </div>

          <!-- Success Alert -->
          <div v-if="successMessage" class="alert alert-success shadow-sm d-flex align-items-center mb-3 py-2">
            <i class="fas fa-check-circle fs-5 me-2"></i>
            <div class="small">{{ successMessage }}</div>
          </div>

          <!-- TAB 1: IMPORT TOOL -->
          <div v-if="activeTab === 'import'">
            
            <!-- Upload Area -->
            <div class="bg-light p-3 rounded border mb-3">
              <form @submit.prevent="previewEcn">
                <div class="row g-2 align-items-center">
                  <div class="col-md-7 col-lg-8">
                    <label class="form-label small fw-semibold text-dark mb-1">Choose ECN Excel File (.xlsx, .xls)</label>
                    <input
                      class="form-control form-control-sm bg-white"
                      type="file"
                      accept=".xls,.xlsx"
                      @change="handleFileChange"
                    />
                  </div>
                  <div class="col-md-5 col-lg-4 d-flex gap-2 align-self-end mt-2 mt-md-0">
                    <button
                      type="submit"
                      class="btn btn-sm flex-grow-1 fw-bold text-white"
                      style="background-color: #b45309;"
                      :disabled="loading || !selectedFile"
                    >
                      <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                      <i v-else class="fas fa-search me-1"></i>Review ECN Data
                    </button>
                    <button
                      v-if="previewRows.length || selectedFile"
                      type="button"
                      class="btn btn-outline-secondary btn-sm"
                      @click="resetPreview"
                      title="Reset"
                    >
                      <i class="fas fa-times me-1"></i>Cancel
                    </button>
                  </div>
                </div>
              </form>
            </div>

            <!-- Project Match Status Banner -->
            <div v-if="matchedProjects.length" class="mb-3">
              <div
                v-for="proj in matchedProjects"
                :key="proj.project_code"
                class="p-2 px-3 rounded border d-flex justify-content-between align-items-center flex-wrap gap-2"
                :class="proj.is_existing ? 'bg-primary-subtle border-primary text-primary-emphasis' : 'bg-danger-subtle border-danger text-danger-emphasis'"
              >
                <div class="d-flex align-items-center gap-2">
                  <span class="badge" :class="proj.is_existing ? 'bg-primary' : 'bg-danger'">
                    <i :class="proj.is_existing ? 'fas fa-check-circle me-1' : 'fas fa-times-circle me-1'"></i>
                    {{ proj.is_existing ? 'Matched Project' : 'Project Not Found' }}
                  </span>
                  <strong class="small">{{ proj.project_name || proj.project_code }} ({{ proj.project_code }})</strong>
                </div>
                <div class="small text-muted" v-if="proj.is_existing">
                  ECN records will be attached to this existing project without affecting regular BOM totals.
                </div>
                <div class="small text-danger fw-semibold" v-else>
                  ECN import requires this project to be created first via BOM import.
                </div>
              </div>
            </div>

            <!-- Conflict Alert Table -->
            <div v-if="conflicts.length" class="alert alert-danger shadow-sm mb-3 p-3">
              <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-triangle fs-4 text-danger me-2 mt-1"></i>
                <div class="flex-grow-1">
                  <h6 class="fw-bold text-danger mb-1">{{ conflicts.length }} Quantity Conflict(s) Detected</h6>
                  <p class="mb-2 small text-dark">
                    Incoming ECN quantity is lower than stock already received in Store. Automated reduction is blocked to protect inventory accuracy.
                  </p>
                  <div class="table-responsive bg-white rounded border">
                    <table class="table table-sm table-bordered mb-0 small">
                      <thead class="table-light">
                        <tr>
                          <th>ECN No</th>
                          <th>Jig</th>
                          <th>Unit</th>
                          <th>Part No</th>
                          <th>Side</th>
                          <th>Incoming Qty</th>
                          <th>Received Qty</th>
                          <th>Reason</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(conf, idx) in conflicts" :key="idx">
                          <td>{{ conf.ecn_number }}</td>
                          <td>{{ conf.jig_no }}</td>
                          <td>{{ conf.unit_no }}</td>
                          <td>{{ conf.part_no }}</td>
                          <td>{{ conf.side }}</td>
                          <td class="text-danger fw-bold">{{ conf.incoming_qty }}</td>
                          <td class="text-success fw-bold">{{ conf.received_qty }}</td>
                          <td>{{ conf.reason }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- Diff / Reconciliation Summary Card -->
            <div v-if="reconciliationSummary" class="card border mb-3 shadow-sm">
              <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-dark">
                  <i class="fas fa-balance-scale me-1 text-primary"></i>ECN Reconciliation Summary
                </span>
                <span class="badge bg-secondary text-white">
                  {{ previewRows.length }} Total Rows
                </span>
              </div>
              <div class="card-body p-3 bg-light">
                <div class="row g-2 text-center">
                  <div class="col-6 col-sm-3">
                    <div class="p-2 bg-white rounded border">
                      <div class="text-muted extra-small text-uppercase fw-bold">New Requirements</div>
                      <div class="fs-5 fw-bold text-success">+{{ reconciliationSummary.added_count || 0 }}</div>
                    </div>
                  </div>
                  <div class="col-6 col-sm-3">
                    <div class="p-2 bg-white rounded border">
                      <div class="text-muted extra-small text-uppercase fw-bold">Updated Qty</div>
                      <div class="fs-5 fw-bold text-warning">{{ reconciliationSummary.updated_count || 0 }}</div>
                    </div>
                  </div>
                  <div class="col-6 col-sm-3">
                    <div class="p-2 bg-white rounded border">
                      <div class="text-muted extra-small text-uppercase fw-bold">Unchanged</div>
                      <div class="fs-5 fw-bold text-secondary">{{ reconciliationSummary.unchanged_count || 0 }}</div>
                    </div>
                  </div>
                  <div class="col-6 col-sm-3">
                    <div class="p-2 bg-white rounded border">
                      <div class="text-muted extra-small text-uppercase fw-bold">Conflicts</div>
                      <div class="fs-5 fw-bold" :class="reconciliationSummary.conflict_count ? 'text-danger' : 'text-success'">
                        {{ reconciliationSummary.conflict_count || 0 }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Confirmation Action Button -->
                <div class="mt-3 d-flex justify-content-end gap-2">
                  <button
                    class="btn btn-sm text-white fw-bold px-4"
                    style="background-color: #b45309;"
                    :disabled="importing || conflicts.length > 0 || !canImport"
                    @click="confirmImport"
                  >
                    <span v-if="importing" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-check me-1"></i>
                    Confirm &amp; Import ECN Records ({{ (reconciliationSummary.added_count || 0) + (reconciliationSummary.updated_count || 0) }} Changes)
                  </button>
                </div>
              </div>
            </div>

            <!-- Preview Rows Table -->
            <div v-if="previewRows.length" class="card border shadow-sm">
              <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small fw-bold text-dark">
                  <i class="fas fa-table me-1 text-primary"></i>Parsed ECN Data Rows ({{ filteredPreviewRows.length }})
                </span>
                <div class="d-flex gap-2 align-items-center">
                  <input
                    type="text"
                    v-model="previewSearch"
                    placeholder="Filter preview..."
                    class="form-control form-control-sm"
                    style="width: 200px;"
                  />
                  <select v-model="previewStatusFilter" class="form-select form-select-sm" style="width: 140px;">
                    <option value="">All Statuses</option>
                    <option value="NEW">New (ADD)</option>
                    <option value="UPDATED">Updated</option>
                    <option value="UNCHANGED">Unchanged</option>
                    <option value="CONFLICT">Conflict</option>
                  </select>
                </div>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                  <table class="table table-sm table-hover table-striped mb-0 small">
                    <thead class="table-light sticky-top">
                      <tr>
                        <th style="width: 50px;">#</th>
                        <th>Project</th>
                        <th>ECN NO.</th>
                        <th>Jig</th>
                        <th>Unit</th>
                        <th>Part No.</th>
                        <th>Side (Orig)</th>
                        <th>Side (Display)</th>
                        <th class="text-center">Incoming Qty</th>
                        <th class="text-center">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(row, idx) in paginatedPreviewRows" :key="idx">
                        <td>{{ row.row_number || (idx + 1) }}</td>
                        <td><strong>{{ row.project_code }}</strong></td>
                        <td>
                          <span class="badge text-white" style="background-color: #b45309;">{{ row.ecn_number }}</span>
                        </td>
                        <td>{{ row.jig_no }}</td>
                        <td>{{ row.unit_no }}</td>
                        <td><code>{{ row.part_no }}</code></td>
                        <td><span class="badge bg-secondary">{{ row.side }}</span></td>
                        <td><span class="badge bg-dark">{{ row.side_display }}</span></td>
                        <td class="text-center fw-bold">{{ row.qty || row.incoming_qty }}</td>
                        <td class="text-center">
                          <span
                            class="badge"
                            :class="{
                              'bg-success': row.status === 'NEW',
                              'bg-warning text-dark': row.status === 'UPDATED',
                              'bg-secondary': row.status === 'UNCHANGED',
                              'bg-danger': row.status === 'CONFLICT',
                            }"
                          >
                            {{ row.status || 'NEW' }}
                          </span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Preview Pagination -->
                <div class="p-2 border-top bg-light d-flex justify-content-between align-items-center small">
                  <span class="text-muted">
                    Showing {{ (previewPage - 1) * previewPerPage + 1 }} to {{ Math.min(previewPage * previewPerPage, filteredPreviewRows.length) }} of {{ filteredPreviewRows.length }}
                  </span>
                  <div class="d-flex gap-1">
                    <button class="btn btn-outline-secondary btn-sm py-0" :disabled="previewPage <= 1" @click="previewPage--">Prev</button>
                    <button class="btn btn-outline-secondary btn-sm py-0" :disabled="previewPage >= maxPreviewPage" @click="previewPage++">Next</button>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- TAB 2: IMPORT HISTORY -->
          <div v-if="activeTab === 'history'">
            <div v-if="historyLoading" class="text-center py-4">
              <div class="spinner-border text-primary spinner-border-sm me-2"></div>
              <span class="text-muted small">Loading ECN import history...</span>
            </div>

            <div v-else-if="!historyItems.length" class="text-center py-4 text-muted small">
              <i class="fas fa-inbox fa-2x mb-2 d-block text-secondary opacity-50"></i>
              No ECN imports recorded yet.
            </div>

            <div v-else class="table-responsive">
              <table class="table table-sm table-hover table-bordered small">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Filename</th>
                    <th>Project</th>
                    <th>ECN Numbers</th>
                    <th>Total Rows</th>
                    <th>Added</th>
                    <th>Updated</th>
                    <th>Imported By</th>
                    <th>Date</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="b in historyItems" :key="b.id">
                    <td>#{{ b.id }}</td>
                    <td><strong>{{ b.original_filename || b.filename }}</strong></td>
                    <td>{{ b.project?.project_code || 'N/A' }}</td>
                    <td>
                      <span v-for="ecn in (b.ecn_numbers || [])" :key="ecn" class="badge me-1 text-white" style="background-color: #b45309;">
                        {{ ecn }}
                      </span>
                    </td>
                    <td class="text-center">{{ b.total_rows }}</td>
                    <td class="text-center text-success fw-bold">+{{ b.added_rows_count }}</td>
                    <td class="text-center text-warning fw-bold">{{ b.updated_rows_count }}</td>
                    <td>{{ b.importer?.name || 'Admin' }}</td>
                    <td>{{ formatDate(b.created_at) }}</td>
                    <td class="text-center">
                      <span class="badge bg-success">{{ b.status }}</span>
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
import { ref, computed } from 'vue';
import axios from 'axios';

const activeTab = ref('import');
const selectedFile = ref(null);
const loading = ref(false);
const importing = ref(false);
const error = ref(null);
const successMessage = ref(null);
const duplicateInfo = ref(null);

const previewRows = ref([]);
const matchedProjects = ref([]);
const conflicts = ref([]);
const reconciliationSummary = ref(null);
const ecnNumbers = ref([]);

const previewSearch = ref('');
const previewStatusFilter = ref('');
const previewPage = ref(1);
const previewPerPage = 50;

const historyItems = ref([]);
const historyLoading = ref(false);

const canImport = computed(() => {
  return matchedProjects.value.length > 0 && matchedProjects.value.every(p => p.is_existing);
});

const filteredPreviewRows = computed(() => {
  return previewRows.value.filter(r => {
    if (previewStatusFilter.value && r.status !== previewStatusFilter.value) {
      return false;
    }
    if (previewSearch.value) {
      const q = previewSearch.value.toLowerCase();
      return (
        (r.part_no && String(r.part_no).toLowerCase().includes(q)) ||
        (r.jig_no && String(r.jig_no).toLowerCase().includes(q)) ||
        (r.unit_no && String(r.unit_no).toLowerCase().includes(q)) ||
        (r.ecn_number && String(r.ecn_number).toLowerCase().includes(q)) ||
        (r.project_code && String(r.project_code).toLowerCase().includes(q))
      );
    }
    return true;
  });
});

const maxPreviewPage = computed(() => Math.ceil(filteredPreviewRows.value.length / previewPerPage) || 1);

const paginatedPreviewRows = computed(() => {
  const start = (previewPage.value - 1) * previewPerPage;
  return filteredPreviewRows.value.slice(start, start + previewPerPage);
});

function handleFileChange(e) {
  selectedFile.value = e.target.files[0] || null;
  error.value = null;
  duplicateInfo.value = null;
  successMessage.value = null;
}

function resetPreview() {
  selectedFile.value = null;
  previewRows.value = [];
  matchedProjects.value = [];
  conflicts.value = [];
  reconciliationSummary.value = null;
  error.value = null;
  duplicateInfo.value = null;
  previewSearch.value = '';
  previewPage.value = 1;
}

async function previewEcn() {
  if (!selectedFile.value) return;

  loading.value = true;
  error.value = null;
  duplicateInfo.value = null;
  successMessage.value = null;

  const formData = new FormData();
  formData.append('file', selectedFile.value);

  try {
    const res = await axios.post('/api/v1/ecn/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (res.data.is_duplicate) {
      duplicateInfo.value = res.data.duplicate_details || res.data;
      loading.value = false;
      return;
    }

    if (!res.data.success && res.data.errors?.length) {
      error.value = res.data.errors.join('; ');
      loading.value = false;
      return;
    }

    previewRows.value = res.data.rows || [];
    matchedProjects.value = res.data.matched_projects || [];
    conflicts.value = res.data.conflicts || [];
    reconciliationSummary.value = res.data.reconciliation || null;
    ecnNumbers.value = res.data.ecn_numbers || [];
    previewPage.value = 1;
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Failed to parse ECN file.';
  } finally {
    loading.value = false;
  }
}

async function confirmImport() {
  if (!selectedFile.value) return;

  importing.value = true;
  error.value = null;

  const formData = new FormData();
  formData.append('file', selectedFile.value);
  formData.append('filename', selectedFile.value.name);

  try {
    const res = await axios.post('/api/v1/ecn/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    successMessage.value = res.data.message || 'ECN records imported successfully!';
    resetPreview();
    fetchHistory();
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Failed to complete ECN import.';
  } finally {
    importing.value = false;
  }
}

async function fetchHistory() {
  historyLoading.value = true;
  try {
    const res = await axios.get('/api/v1/ecn/history');
    historyItems.value = res.data.data || res.data || [];
  } catch (err) {
    console.error('Failed to fetch ECN history', err);
  } finally {
    historyLoading.value = false;
  }
}

function formatDate(dt) {
  if (!dt) return '—';
  return new Date(dt).toLocaleString();
}
</script>
