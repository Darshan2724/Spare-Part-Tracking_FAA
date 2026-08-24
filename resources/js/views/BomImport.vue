<template>
  <div class="p-3 p-md-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm">
        
        <!-- Header -->
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h4 class="fw-bold mb-0 text-dark">
                <i class="fas fa-file-import me-2 text-primary"></i>Import BOM
              </h4>
              <p class="text-muted small mb-0 mt-1">Upload a BOM to create a new project or update an existing project.</p>
            </div>
          </div>

          <!-- Compact Tab Navigation -->
          <ul class="nav nav-tabs card-header-tabs mt-3 border-0">
            <li class="nav-item">
              <button
                class="nav-link px-3 py-2 fw-semibold"
                :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'import', 'text-secondary': activeTab !== 'import' }"
                @click="activeTab = 'import'"
              >
                <i class="fas fa-upload me-1"></i>Import BOM
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link px-3 py-2 fw-semibold"
                :class="{ 'active border-primary border-bottom border-2 text-primary': activeTab === 'history', 'text-secondary': activeTab !== 'history' }"
                @click="activeTab = 'history'; fetchHistory();"
              >
                <i class="fas fa-history me-1"></i>Import History
              </button>
            </li>
          </ul>
        </div>

        <div class="card-body p-3 p-md-4">

          <!-- DUPLICATE FILENAME / BOM WARNING ALERT -->
          <div v-if="duplicateInfo" class="alert alert-warning border-warning shadow-sm mb-3">
            <div class="d-flex align-items-start">
              <i class="fas fa-exclamation-triangle fs-4 text-warning me-3 mt-1"></i>
              <div class="flex-grow-1">
                <h6 class="fw-bold text-dark mb-1">
                  {{ duplicateInfo.error_title || 'Duplicate Filename' }}
                </h6>
                <p class="mb-1 text-dark small">
                  {{ duplicateInfo.message || 'This BOM filename has already been imported. Please rename the revised BOM and upload it again.' }}
                </p>
                <p class="mb-2 text-muted small fw-semibold">
                  {{ duplicateInfo.secondary_message || 'Every BOM revision must use a different filename.' }}
                </p>
                <div class="row g-2 small text-muted bg-white p-2 rounded border">
                  <div class="col-sm-6 col-md-3"><strong>Filename:</strong> {{ duplicateInfo.original_filename || 'N/A' }}</div>
                  <div class="col-sm-6 col-md-3"><strong>Project:</strong> {{ duplicateInfo.project_code || 'N/A' }}</div>
                  <div class="col-sm-6 col-md-3"><strong>Imported On:</strong> {{ duplicateInfo.imported_at || 'N/A' }}</div>
                  <div class="col-sm-6 col-md-3"><strong>Imported By:</strong> {{ duplicateInfo.imported_by || 'N/A' }}</div>
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
            
            <!-- Compact Upload Area -->
            <div class="bg-light p-3 rounded border mb-3">
              <form @submit.prevent="previewBom">
                <div class="row g-2 align-items-center">
                  <div class="col-md-7 col-lg-8">
                    <label class="form-label small fw-semibold text-dark mb-1">Choose BOM File (.xlsx, .xls)</label>
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
                      class="btn btn-primary btn-sm flex-grow-1 fw-bold"
                      :disabled="loading || !selectedFile"
                    >
                      <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                      <i v-else class="fas fa-search me-1"></i>Review Changes
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
                :class="proj.is_existing ? 'bg-primary-subtle border-primary text-primary-emphasis' : 'bg-success-subtle border-success text-success-emphasis'"
              >
                <div class="d-flex align-items-center gap-2">
                  <span class="badge" :class="proj.is_existing ? 'bg-primary' : 'bg-success'">
                    <i :class="proj.is_existing ? 'fas fa-code-branch me-1' : 'fas fa-plus me-1'"></i>
                    {{ proj.is_existing ? 'Existing Project Revision' : 'New Project' }}
                  </span>
                  <strong class="small">{{ proj.project_name }} ({{ proj.project_code }})</strong>
                </div>
                <div class="small text-muted" v-if="proj.is_existing">
                  Existing Jigs/Units reused. New requirements appended.
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
                    Incoming quantity is lower than stock already received in Store/QC. Automated reduction is blocked to protect inventory accuracy.
                  </p>
                  <div class="table-responsive bg-white rounded border">
                    <table class="table table-sm table-bordered mb-0 small">
                      <thead class="table-light">
                        <tr>
                          <th>Row</th>
                          <th>Jig / Unit</th>
                          <th>Part Number</th>
                          <th>Side</th>
                          <th>Existing Req</th>
                          <th>Incoming Req</th>
                          <th>Received (Store/QC)</th>
                          <th>Reason</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="c in conflicts" :key="`${c.row_number}-${c.part_no}`">
                          <td>{{ c.row_number }}</td>
                          <td>{{ c.jig_no }} / Unit {{ c.unit_no }}</td>
                          <td class="fw-bold font-monospace">{{ c.part_no }}</td>
                          <td><span class="badge bg-secondary">{{ c.side }}</span></td>
                          <td>{{ c.existing_qty }}</td>
                          <td class="fw-bold text-danger">{{ c.incoming_qty }}</td>
                          <td class="fw-bold text-primary">{{ c.received_qty }}</td>
                          <td class="text-danger small">{{ c.reason }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- Compact Preview Summary Pills & Action Bar -->
            <div v-if="reconciliationSummary && previewRows.length" class="card border mb-3 shadow-sm">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  
                  <!-- Metrics Pills -->
                  <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="badge bg-light text-dark border px-2 py-1">
                      <i class="fas fa-layer-group me-1 text-secondary"></i>
                      Jigs: <strong class="text-success">+{{ reconciliationSummary.new_jigs_count || 0 }} new</strong>
                    </span>
                    <span class="badge bg-light text-dark border px-2 py-1">
                      <i class="fas fa-cubes me-1 text-secondary"></i>
                      Units: <strong class="text-info">+{{ reconciliationSummary.new_units_count || 0 }} new</strong>
                    </span>
                    <span class="badge bg-light text-dark border px-2 py-1">
                      <i class="fas fa-puzzle-piece me-1 text-secondary"></i>
                      Parts: <strong class="text-success">+{{ reconciliationSummary.new_requirements_count || 0 }} new</strong>
                    </span>
                    <span class="badge bg-light text-dark border px-2 py-1">
                      <i class="fas fa-sync-alt me-1 text-secondary"></i>
                      Updated: <strong class="text-primary">{{ reconciliationSummary.updated_requirements_count || 0 }}</strong>
                    </span>
                    <span class="badge bg-light text-dark border px-2 py-1">
                      <i class="fas fa-check me-1 text-secondary"></i>
                      Unchanged: <strong>{{ reconciliationSummary.unchanged_requirements_count || 0 }}</strong>
                    </span>
                    <span v-if="reconciliationSummary.conflict_count > 0" class="badge bg-danger px-2 py-1">
                      <i class="fas fa-exclamation-triangle me-1"></i>
                      Conflicts: {{ reconciliationSummary.conflict_count }}
                    </span>
                  </div>

                  <!-- Primary Apply Button -->
                  <div>
                    <button
                      type="button"
                      class="btn btn-success btn-sm px-3 fw-bold"
                      :disabled="loading || !canImport || conflicts.length > 0"
                      @click="importBom"
                    >
                      <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
                      <i v-else class="fas fa-check me-1"></i>
                      {{ isRevisionMode ? 'Apply Changes' : 'IMPORT BOM' }}
                    </button>
                  </div>
                </div>

                <!-- Filter Pills -->
                <div class="d-flex align-items-center gap-2 mt-3 pt-2 border-top">
                  <span class="small text-muted fw-semibold">Filter:</span>
                  <div class="btn-group btn-group-sm" role="group">
                    <button
                      type="button"
                      class="btn btn-sm py-0 px-2"
                      :class="filterStatus === 'ALL' ? 'btn-dark' : 'btn-outline-secondary'"
                      @click="filterStatus = 'ALL'"
                    >
                      All ({{ previewRows.length }})
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm py-0 px-2"
                      :class="filterStatus === 'NEW' ? 'btn-success' : 'btn-outline-success'"
                      @click="filterStatus = 'NEW'"
                    >
                      New ({{ countByStatus('NEW') }})
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm py-0 px-2"
                      :class="filterStatus === 'UPDATED' ? 'btn-primary' : 'btn-outline-primary'"
                      @click="filterStatus = 'UPDATED'"
                    >
                      Updated ({{ countByStatus('UPDATED') }})
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm py-0 px-2"
                      :class="filterStatus === 'UNCHANGED' ? 'btn-secondary' : 'btn-outline-secondary'"
                      @click="filterStatus = 'UNCHANGED'"
                    >
                      Unchanged ({{ countByStatus('UNCHANGED') }})
                    </button>
                    <button
                      v-if="countByStatus('CONFLICT') > 0"
                      type="button"
                      class="btn btn-sm py-0 px-2"
                      :class="filterStatus === 'CONFLICT' ? 'btn-danger' : 'btn-outline-danger'"
                      @click="filterStatus = 'CONFLICT'"
                    >
                      Conflicts ({{ countByStatus('CONFLICT') }})
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Validation Warnings -->
            <div v-if="validationErrors.length" class="alert alert-danger shadow-sm mb-3 p-3">
              <h6 class="fw-bold text-danger mb-1 small"><i class="fas fa-ban me-1"></i>Validation Errors ({{ validationErrors.length }})</h6>
              <ul class="mb-0 small" style="max-height: 150px; overflow-y: auto;">
                <li v-for="(issue, index) in validationErrors" :key="`${issue}-${index}`">{{ issue }}</li>
              </ul>
            </div>

            <!-- Compact Diff Preview Table -->
            <div v-if="filteredPreviewRows.length" class="table-responsive border rounded shadow-sm">
              <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light border-bottom">
                  <tr>
                    <th style="width: 45px;">#</th>
                    <th style="width: 100px;">Status</th>
                    <th>Project</th>
                    <th>Jig</th>
                    <th>Unit</th>
                    <th>Part Number</th>
                    <th>Side</th>
                    <th>Existing</th>
                    <th>Incoming</th>
                    <th>Received</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(row, index) in filteredPreviewRows"
                    :key="`${row.project_code}-${row.part_no}-${row.side}-${index}`"
                    :class="{
                      'table-success-subtle': row.status === 'NEW',
                      'table-primary-subtle': row.status === 'UPDATED',
                      'table-danger-subtle': row.status === 'CONFLICT',
                      'text-muted': row.status === 'UNCHANGED'
                    }"
                  >
                    <td class="text-muted">{{ row.row_number || (index + 1) }}</td>
                    <td>
                      <span
                        class="badge py-1 px-2"
                        :class="{
                          'bg-success': row.status === 'NEW',
                          'bg-primary': row.status === 'UPDATED',
                          'bg-secondary': row.status === 'UNCHANGED',
                          'bg-danger': row.status === 'CONFLICT'
                        }"
                      >
                        {{ row.action }}
                      </span>
                    </td>
                    <td class="fw-semibold">{{ row.project_code }}</td>
                    <td>{{ row.jig_no }}</td>
                    <td>Unit {{ row.unit_no }}</td>
                    <td class="fw-bold font-monospace">{{ row.part_no }}</td>
                    <td>
                      <span
                        class="badge"
                        :class="{
                          'bg-primary': row.side === 'RH',
                          'bg-info text-dark': row.side === 'LH',
                          'bg-secondary': row.side === 'COMMON'
                        }"
                      >
                        {{ row.side }}
                      </span>
                    </td>
                    <td>{{ row.existing_qty !== null ? row.existing_qty : '—' }}</td>
                    <td
                      class="fw-bold"
                      :class="{
                        'text-success': row.status === 'NEW',
                        'text-primary': row.status === 'UPDATED',
                        'text-danger': row.status === 'CONFLICT',
                        'text-dark': row.status === 'UNCHANGED'
                      }"
                    >
                      {{ row.incoming_qty }}
                    </td>
                    <td class="text-secondary">{{ row.received_qty || 0 }}</td>
                    <td :class="row.status === 'CONFLICT' ? 'text-danger fw-bold' : 'text-muted'">
                      {{ row.reason }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else-if="previewRows.length" class="p-3 text-center text-muted bg-white rounded border small">
              No rows match the selected filter criteria.
            </div>

          </div>

          <!-- TAB 2: IMPORT HISTORY -->
          <div v-else-if="activeTab === 'history'">
            <div class="table-responsive border rounded shadow-sm">
              <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light border-bottom">
                  <tr>
                    <th>Filename</th>
                    <th>Project</th>
                    <th>Imported By</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="batch in importHistory" :key="batch.id">
                    <td>
                      <div class="fw-bold text-dark">{{ cleanFilename(batch.filename) }}</div>
                      <div class="small text-muted">
                        <span class="badge bg-light text-secondary border me-1">{{ (batch.import_type || 'initial').toUpperCase() }}</span>
                        <span class="text-success me-1">+{{ batch.added_rows_count ?? batch.total_rows }} new</span>
                        <span v-if="batch.updated_rows_count > 0" class="text-primary me-1">~{{ batch.updated_rows_count }} updated</span>
                        <span v-if="batch.skipped_rows_count > 0" class="text-muted">{{ batch.skipped_rows_count }} skipped</span>
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border">
                        {{ batch.project?.name || batch.project?.project_code || '—' }}
                      </span>
                    </td>
                    <td>{{ batch.importer?.name || 'System' }}</td>
                    <td class="text-muted">{{ formatTimestamp(batch.created_at) }}</td>
                    <td>
                      <span
                        class="badge"
                        :class="{
                          'bg-success': batch.status === 'completed',
                          'bg-warning text-dark': batch.status === 'processing',
                          'bg-danger': batch.status === 'failed'
                        }"
                      >
                        {{ (batch.status || 'completed').toUpperCase() }}
                      </span>
                    </td>
                    <td class="text-end">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger py-0 px-2"
                        :disabled="batch.status === 'processing'"
                        title="Delete BOM import"
                        @click="openDeleteModal(batch)"
                      >
                        <i class="fas fa-trash-alt me-1"></i>Delete
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!importHistory.length">
                    <td colspan="6" class="text-center py-4 text-muted">
                      <i class="fas fa-history fa-2x mb-2 text-secondary"></i>
                      <p class="mb-0 small">No BOM import history found.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- DELETE CONFIRMATION & IMPACT MODAL -->
    <div v-if="showDeleteModal" class="modal fade show d-block" tabindex="-1" style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;" role="dialog" aria-modal="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white border-0 py-2 px-3">
            <h6 class="modal-title fw-bold mb-0">
              <i class="fas fa-exclamation-triangle me-2"></i>Confirm BOM Deletion
            </h6>
            <button type="button" class="btn-close btn-close-white" :disabled="isDeleting" @click="closeDeleteModal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-3">
            <div v-if="impactLoading" class="text-center py-4">
              <div class="spinner-border text-danger mb-2" role="status" style="width: 2rem; height: 2rem;"></div>
              <p class="text-muted small mb-0">Calculating impact analysis...</p>
            </div>

            <div v-else-if="deleteError" class="alert alert-danger shadow-sm small py-2">
              <i class="fas fa-times-circle me-1"></i>{{ deleteError }}
            </div>

            <div v-else-if="deleteImpact">
              <div class="alert alert-warning border-warning shadow-sm mb-3 p-2 small">
                <div class="fw-bold mb-1">Target File: {{ deleteImpact.batch.filename }}</div>
                <div class="text-muted">
                  Project: <strong>{{ deleteImpact.project?.name || 'N/A' }} ({{ deleteImpact.project?.project_code || 'N/A' }})</strong> |
                  Imported on: {{ formatTimestamp(deleteImpact.batch.created_at) }}
                </div>
              </div>

              <div v-if="deleteImpact.has_operational_data" class="alert alert-danger shadow-sm mb-3 p-2 small">
                <div class="fw-bold text-danger mb-1"><i class="fas fa-radiation me-1"></i>Active Operational Workflow Data Detected!</div>
                <div>
                  This project contains active transactions. Deleting this BOM import will permanently remove all {{ deleteImpact.counts.total_operational_records }} operational records.
                </div>
              </div>

              <h6 class="fw-bold text-dark mb-2 small">Records Affected:</h6>
              <div class="row g-2 mb-2">
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.unique_parts_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">BOM Parts</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.receipts_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Store Receipts</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.qc_inspections_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">QC Inspections</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.paint_records_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Paint Records</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.assembly_records_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Assembly Records</div>
                  </div>
                </div>
                <div class="col-4">
                  <div class="p-2 border rounded bg-light text-center">
                    <div class="fw-bold text-danger">{{ deleteImpact.counts.rework_records_count }}</div>
                    <div class="text-muted" style="font-size: 0.75rem;">Rework Records</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer bg-light border-0 py-2 px-3">
            <button type="button" class="btn btn-secondary btn-sm px-3" :disabled="isDeleting" @click="closeDeleteModal">
              Cancel
            </button>
            <button type="button" class="btn btn-danger btn-sm px-3 fw-bold" :disabled="isDeleting || impactLoading" @click="executeDelete">
              <span v-if="isDeleting" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fas fa-trash-alt me-1"></i>Delete BOM
            </button>
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
const previewRows = ref([]);
const previewSummary = ref(null);
const reconciliationSummary = ref(null);
const matchedProjects = ref([]);
const conflicts = ref([]);
const isRevisionMode = ref(false);
const validationErrors = ref([]);
const filterStatus = ref('ALL'); // 'ALL' | 'NEW' | 'UPDATED' | 'UNCHANGED' | 'CONFLICT'

const error = ref('');
const successMessage = ref('');
const loading = ref(false);

// Deletion state
const showDeleteModal = ref(false);
const selectedDeleteBatch = ref(null);
const deleteImpact = ref(null);
const impactLoading = ref(false);
const isDeleting = ref(false);
const deleteError = ref('');

const canImport = computed(() => previewRows.value.length > 0 && validationErrors.value.length === 0 && !duplicateInfo.value);

const filteredPreviewRows = computed(() => {
  if (filterStatus.value === 'ALL') {
    return previewRows.value;
  }
  return previewRows.value.filter(r => r.status === filterStatus.value);
});

const countByStatus = (status) => {
  return previewRows.value.filter(r => r.status === status).length;
};

const handleFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null;
  duplicateInfo.value = null;
  resetPreview();
};

const resetPreview = () => {
  previewRows.value = [];
  previewSummary.value = null;
  reconciliationSummary.value = null;
  matchedProjects.value = [];
  conflicts.value = [];
  isRevisionMode.value = false;
  validationErrors.value = [];
  filterStatus.value = 'ALL';
  error.value = '';
};

const clearMessages = () => {
  error.value = '';
  successMessage.value = '';
  duplicateInfo.value = null;
};

const previewBom = async () => {
  if (!selectedFile.value) {
    error.value = 'Please select a BOM Excel file to preview.';
    return;
  }

  clearMessages();
  loading.value = true;
  resetPreview();

  try {
    const formData = new FormData();
    formData.append('file', selectedFile.value);
    formData.append('filename', selectedFile.value.name);

    const response = await axios.post('/api/v1/bom/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.is_duplicate) {
      duplicateInfo.value = response.data.duplicate_details || {
        message: response.data.message,
        secondary_message: response.data.secondary_message,
        original_filename: response.data.filename,
        error_title: response.data.error_title,
      };
      error.value = response.data.message || 'This BOM filename has already been imported.';
    } else {
      previewRows.value = response.data.rows || [];
      previewSummary.value = response.data.summary || null;
      reconciliationSummary.value = response.data.reconciliation || null;
      matchedProjects.value = response.data.matched_projects || [];
      conflicts.value = response.data.conflicts || [];
      isRevisionMode.value = response.data.is_revision || false;
      validationErrors.value = response.data.errors || [];

      if (!previewRows.value.length && !validationErrors.value.length) {
        successMessage.value = 'Preview completed. No data rows were found in the selected BOM.';
      }
    }
  } catch (err) {
    if (err.response?.data?.is_duplicate) {
      duplicateInfo.value = err.response.data.duplicate_details || {
        message: err.response.data.message,
        secondary_message: err.response.data.secondary_message,
        original_filename: err.response.data.filename,
        error_title: err.response.data.error_title,
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
  if (!selectedFile.value) {
    error.value = 'Please select a BOM Excel file to import.';
    return;
  }

  clearMessages();
  loading.value = true;

  try {
    const formData = new FormData();
    formData.append('file', selectedFile.value);
    formData.append('filename', selectedFile.value.name);

    const response = await axios.post('/api/v1/bom/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.success) {
      successMessage.value = response.data.message || 'BOM imported and reconciled successfully.';
      resetPreview();
      selectedFile.value = null;
      duplicateInfo.value = null;
      fetchHistory();
    } else {
      if (response.data.is_duplicate) {
        duplicateInfo.value = response.data.duplicate_details || {
          message: response.data.message,
          secondary_message: response.data.secondary_message,
          error_title: response.data.error_title,
        };
      }
      error.value = response.data.message || 'Import failed.';
      validationErrors.value = response.data.errors || [];
      conflicts.value = response.data.conflicts || [];
    }
  } catch (err) {
    if (err.response?.data?.is_duplicate) {
      duplicateInfo.value = err.response.data.duplicate_details || {
        message: err.response.data.message,
        secondary_message: err.response.data.secondary_message,
        error_title: err.response.data.error_title,
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

const openDeleteModal = async (batch) => {
  deleteError.value = '';
  selectedDeleteBatch.value = batch;
  deleteImpact.value = null;
  impactLoading.value = true;
  showDeleteModal.value = true;

  try {
    const res = await axios.get(`/api/v1/bom/history/${batch.id}/impact`);
    deleteImpact.value = res.data;
  } catch (err) {
    console.error('Failed to load deletion impact:', err);
    deleteError.value = err.response?.data?.message || 'Failed to calculate pre-deletion impact.';
  } finally {
    impactLoading.value = false;
  }
};

const closeDeleteModal = () => {
  if (isDeleting.value) return;
  showDeleteModal.value = false;
  selectedDeleteBatch.value = null;
  deleteImpact.value = null;
  deleteError.value = '';
};

const executeDelete = async () => {
  if (!selectedDeleteBatch.value) return;
  const batchId = selectedDeleteBatch.value.id;
  isDeleting.value = true;
  deleteError.value = '';

  try {
    const res = await axios.delete(`/api/v1/bom/history/${batchId}`);
    if (res.data.success) {
      importHistory.value = importHistory.value.filter(b => b.id !== batchId);
      successMessage.value = res.data.message || 'BOM import and associated project deleted successfully.';
      showDeleteModal.value = false;
      selectedDeleteBatch.value = null;
      deleteImpact.value = null;
    } else {
      deleteError.value = res.data.message || 'Failed to delete BOM import.';
    }
  } catch (err) {
    console.error('Failed to delete BOM import:', err);
    deleteError.value = err.response?.data?.message || 'An error occurred while deleting the BOM import.';
  } finally {
    isDeleting.value = false;
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
  background-color: #f1f5f9 !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 0.78rem;
  padding: 8px 12px;
}
</style>
