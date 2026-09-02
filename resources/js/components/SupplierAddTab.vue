<template>
  <div class="supplier-add-wrapper">
    <!-- Alert Feedback -->
    <div v-if="error" class="alert alert-danger alert-dismissible fade show shadow-xs py-2.5 px-3 mb-3">
      <i class="fas fa-exclamation-triangle me-1.5"></i>{{ error }}
      <button type="button" class="btn-close py-2" @click="error = ''"></button>
    </div>
    <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs py-2.5 px-3 mb-3">
      <i class="fas fa-check-circle me-1.5"></i>{{ successMessage }}
      <button type="button" class="btn-close py-2" @click="successMessage = ''"></button>
    </div>

    <!-- TOP ROW: (1) MANUAL FORM CARD & (2) EXCEL IMPORT CARD -->
    <div class="row g-3 mb-3">
      <!-- 1. MANUAL SUPPLIER CREATION FORM -->
      <div class="col-12 col-xl-7">
        <div class="card border shadow-xs bg-white h-100 app-card">
          <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
              <span class="category-icon-box bg-primary-subtle text-primary">
                <i class="fas fa-user-plus"></i>
              </span>
              <div>
                <h6 class="fw-bold text-dark mb-0">Add Supplier Manually</h6>
              </div>
            </div>
            <button 
              type="button" 
              class="btn btn-xs btn-outline-secondary" 
              @click="resetManualForm"
            >
              <i class="fas fa-undo me-1"></i> Reset
            </button>
          </div>

          <div class="card-body p-3">
            <form @submit.prevent="submitManualSupplier">
              <div class="row g-2.5">
                <!-- Name -->
                <div class="col-12 col-md-7">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    Name <span class="text-danger">*</span>
                  </label>
                  <input 
                    v-model="manualForm.name" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="Supplier Name" 
                    required 
                  />
                </div>

                <!-- Glcd / Code -->
                <div class="col-12 col-md-5">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    Supplier Code / Glcd
                  </label>
                  <input 
                    v-model="manualForm.code" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="e.g. 153A115" 
                  />
                </div>

                <!-- Contact Person -->
                <div class="col-12 col-md-6">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    Contact Person
                  </label>
                  <input 
                    v-model="manualForm.contact_person" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="Contact person name" 
                  />
                </div>

                <!-- City -->
                <div class="col-12 col-md-3">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    City
                  </label>
                  <input 
                    v-model="manualForm.city" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="City" 
                  />
                </div>

                <!-- PinCode -->
                <div class="col-12 col-md-3">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    PinCode
                  </label>
                  <input 
                    v-model="manualForm.pincode" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="PinCode" 
                  />
                </div>

                <!-- MULTIPLE PHONE NUMBERS REPEATER -->
                <div class="col-12">
                  <div class="p-2.5 bg-light rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                      <label class="form-label extra-small fw-bold text-dark mb-0 d-flex align-items-center gap-1">
                        <i class="fas fa-phone text-primary"></i>
                        <span>Phone Numbers</span>
                      </label>
                      <button 
                        type="button" 
                        class="btn btn-xs btn-outline-primary py-0.5 px-2 fw-semibold" 
                        @click="addPhoneField"
                      >
                        <i class="fas fa-plus me-1"></i> Add Phone
                      </button>
                    </div>

                    <div class="d-flex flex-column gap-1.5">
                      <div 
                        v-for="(ph, idx) in manualForm.phones" 
                        :key="idx" 
                        class="input-group input-group-sm"
                      >
                        <span class="input-group-text extra-small text-muted py-0">
                          #{{ idx + 1 }}
                        </span>
                        <input 
                          v-model="manualForm.phones[idx]" 
                          type="text" 
                          class="form-control form-control-sm" 
                          placeholder="Phone number" 
                        />
                        <button 
                          v-if="manualForm.phones.length > 1" 
                          type="button" 
                          class="btn btn-outline-danger py-0 px-2" 
                          @click="removePhoneField(idx)"
                          title="Remove this phone"
                        >
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Remarks -->
                <div class="col-12">
                  <label class="form-label extra-small fw-bold text-dark mb-1">
                    Remarks
                  </label>
                  <textarea 
                    v-model="manualForm.remarks" 
                    class="form-control form-control-sm shadow-xs" 
                    rows="2" 
                    placeholder="Optional remarks"
                  ></textarea>
                </div>

                <!-- Submit Button -->
                <div class="col-12 text-end mt-2">
                  <button 
                    type="submit" 
                    class="btn btn-sm btn-primary fw-semibold px-4 shadow-xs" 
                    :disabled="submittingManual"
                  >
                    <span v-if="submittingManual" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-save me-1"></i> Save Supplier
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- 2. EXCEL IMPORT CARD & IMPORT HISTORY -->
      <div class="col-12 col-xl-5">
        <div class="d-flex flex-column gap-3 h-100">
          <!-- Excel Import Box -->
          <div class="card border shadow-xs bg-white app-card">
            <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex align-items-center gap-2">
              <span class="category-icon-box bg-success-subtle text-success">
                <i class="fas fa-file-excel"></i>
              </span>
              <div>
                <h6 class="fw-bold text-dark mb-0">Import Supplier Excel</h6>
              </div>
            </div>

            <div class="card-body p-3">
              <!-- Preset Sample Format Option -->
              <div class="p-2.5 rounded border bg-light mb-3 d-flex justify-content-between align-items-center">
                <div>
                  <strong class="text-dark small d-block">BOM/Supplier list 1.xlsx</strong>
                  <span class="extra-small text-muted">Standard master format</span>
                </div>
                <button 
                  type="button" 
                  class="btn btn-xs btn-outline-success fw-semibold shadow-xs" 
                  :disabled="previewLoading" 
                  @click="loadSampleExcelPreview"
                >
                  <span v-if="previewLoading && useSample" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-import me-1"></i> Preview Sample
                </button>
              </div>

              <!-- Upload Custom File -->
              <div class="mb-1">
                <label class="form-label extra-small fw-bold text-dark mb-1">Upload Supplier Excel File (.xlsx, .csv)</label>
                <div class="input-group input-group-sm">
                  <input 
                    type="file" 
                    class="form-control form-control-sm" 
                    accept=".xlsx,.xls,.csv" 
                    @change="handleFileUpload" 
                  />
                  <button 
                    class="btn btn-primary btn-sm fw-semibold" 
                    :disabled="!selectedFile || previewLoading" 
                    @click="uploadAndPreview"
                  >
                    <span v-if="previewLoading && !useSample" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="fas fa-search me-1"></i> Preview
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Import History / Delete Supplier List Card -->
          <div class="card border shadow-xs bg-white app-card flex-grow-1">
            <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
              <span class="small fw-bold text-dark d-flex align-items-center gap-1.5">
                <i class="fas fa-history text-secondary"></i> Imported Excel Lists
              </span>
              <button class="btn btn-xs btn-outline-secondary" @click="fetchImportsList" title="Refresh imports">
                <i class="fas fa-sync-alt" :class="{ 'fa-spin': loadingImports }"></i>
              </button>
            </div>

            <div class="card-body p-2" style="max-height: 200px; overflow-y: auto;">
              <div v-if="loadingImports && !importsList.length" class="text-center py-3 text-muted extra-small">
                Loading import records...
              </div>

              <div v-else-if="!importsList.length" class="text-center py-3 text-muted extra-small">
                No past supplier imports recorded.
              </div>

              <div v-else class="d-flex flex-column gap-1.5">
                <div 
                  v-for="imp in importsList" 
                  :key="imp.id" 
                  class="p-2 rounded border bg-light d-flex justify-content-between align-items-center extra-small"
                >
                  <div class="text-truncate me-2" style="max-width: 220px;">
                    <strong class="text-dark d-block text-truncate">{{ imp.filename }}</strong>
                    <span class="text-muted">
                      {{ formatDateTime(imp.created_at) }} &bull; {{ imp.suppliers_count || imp.created_count || 0 }} suppliers
                    </span>
                  </div>

                  <button 
                    class="btn btn-xs btn-outline-danger py-0.5 px-2"
                    title="Delete all suppliers imported via this file"
                    @click="confirmDeleteImport(imp)"
                  >
                    <i class="fas fa-trash-alt me-1"></i> Delete List
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- EXCEL IMPORT PREVIEW CARD (When previewData is ready)                     -->
    <!-- ========================================================================= -->
    <div v-if="previewData" class="card border shadow-xs bg-white mb-3 app-card border-primary">
      <div class="card-header bg-primary text-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="fas fa-eye fs-6"></i>
          <h6 class="fw-bold mb-0">Supplier Excel Import Preview ({{ currentPreviewFilename }})</h6>
        </div>

        <!-- Metric Badges -->
        <div class="d-flex align-items-center gap-2 extra-small">
          <span class="badge bg-light text-dark">Total: {{ previewData.total_rows }}</span>
          <span class="badge bg-success">New: {{ previewData.new_count }}</span>
          <span class="badge bg-warning text-dark">Duplicate/Update: {{ previewData.duplicate_count }}</span>
          <span v-if="previewData.invalid_count" class="badge bg-danger">Invalid: {{ previewData.invalid_count }}</span>
        </div>
      </div>

      <div class="card-body p-0">
        <!-- Preview Table -->
        <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
          <table class="table table-hover table-sm align-middle mb-0 extra-small">
            <thead class="table-dark sticky-top">
              <tr>
                <th style="width: 50px;">SR</th>
                <th>Supplier Name</th>
                <th style="width: 110px;">Code / Glcd</th>
                <th style="width: 100px;">City</th>
                <th style="width: 80px;">PinCode</th>
                <th>Contact Person</th>
                <th>Phone Numbers</th>
                <th style="width: 100px;" class="text-center">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in previewData.rows" :key="r.row_index">
                <td>{{ r.sr_no }}</td>
                <td><strong class="text-dark">{{ r.name }}</strong></td>
                <td><span class="badge bg-light text-dark border">{{ r.glcd || r.code }}</span></td>
                <td>{{ r.city }}</td>
                <td>{{ r.pincode }}</td>
                <td>{{ r.contact_person }}</td>
                <td>
                  <div class="d-flex flex-wrap gap-1">
                    <span 
                      v-for="(p, pIdx) in r.phones" 
                      :key="pIdx" 
                      class="badge bg-primary-subtle text-primary border extra-small"
                    >
                      {{ p }}
                    </span>
                    <span v-if="!r.phones.length" class="text-muted">-</span>
                  </div>
                </td>
                <td class="text-center">
                  <span v-if="r.status === 'new'" class="badge bg-success">New</span>
                  <span v-else-if="r.status === 'duplicate'" class="badge bg-warning text-dark">Existing</span>
                  <span v-else class="badge bg-danger">Invalid</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Preview Actions Bar -->
        <div class="p-2.5 bg-light border-top d-flex justify-content-between align-items-center">
          <button 
            type="button" 
            class="btn btn-sm btn-outline-secondary" 
            @click="previewData = null" 
            :disabled="committingImport"
          >
            Cancel Preview
          </button>
          <button 
            type="button" 
            class="btn btn-sm btn-success fw-bold px-4 shadow-xs" 
            :disabled="committingImport || !previewData.new_count && !previewData.duplicate_count" 
            @click="commitImport"
          >
            <span v-if="committingImport" class="spinner-border spinner-border-sm me-1.5"></span>
            <i v-else class="fas fa-check me-1.5"></i> Confirm &amp; Import {{ previewData.total_rows }} Suppliers
          </button>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- SECTION 3: SUPPLIER DIRECTORY LIST (WITH STATUS PARTITIONING TABS)         -->
    <!-- ========================================================================= -->
    <div class="card border shadow-xs bg-white app-card">
      <div 
        class="card-header bg-white py-2.5 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2 select-none"
      >
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <i 
            class="fas text-primary fs-7 cursor-pointer transition-all"
            :class="isListExpanded ? 'fa-chevron-down' : 'fa-chevron-right'"
            @click="isListExpanded = !isListExpanded"
          ></i>
          <strong class="text-dark small cursor-pointer" @click="isListExpanded = !isListExpanded">
            Supplier Directory
          </strong>

          <!-- Status Partitioning Tabs (Active vs Inactive / Historical) -->
          <div class="btn-group btn-group-sm ms-2">
            <button 
              type="button" 
              class="btn btn-xs fw-semibold px-2.5 py-1"
              :class="statusFilter === 'active' ? 'btn-primary' : 'btn-outline-secondary'"
              @click="setStatusFilter('active')"
            >
              Active Suppliers <span class="badge ms-1" :class="statusFilter === 'active' ? 'bg-white text-primary' : 'bg-secondary'">{{ statusCounts.active }}</span>
            </button>
            <button 
              type="button" 
              class="btn btn-xs fw-semibold px-2.5 py-1"
              :class="statusFilter === 'inactive' ? 'btn-secondary' : 'btn-outline-secondary'"
              @click="setStatusFilter('inactive')"
            >
              Inactive / Historical <span class="badge ms-1" :class="statusFilter === 'inactive' ? 'bg-white text-dark' : 'bg-secondary'">{{ statusCounts.inactive }}</span>
            </button>
            <button 
              type="button" 
              class="btn btn-xs fw-semibold px-2.5 py-1"
              :class="statusFilter === 'all' ? 'btn-dark' : 'btn-outline-secondary'"
              @click="setStatusFilter('all')"
            >
              All
            </button>
          </div>
        </div>

        <!-- Search input & refresh -->
        <div class="d-flex align-items-center gap-2">
          <input 
            v-model="searchQuery" 
            @input="debounceSearch" 
            class="form-control form-control-sm shadow-xs" 
            style="width: 200px;" 
            placeholder="Search suppliers..." 
          />
          <button class="btn btn-xs btn-outline-secondary" @click="fetchSuppliersList(pagination.current_page)" :disabled="loadingList">
            <i class="fas fa-sync-alt" :class="{ 'fa-spin': loadingList }"></i>
          </button>
        </div>
      </div>

      <div v-if="isListExpanded" class="card-body p-0">
        <div v-if="loadingList && !suppliersList.length" class="text-center py-4">
          <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
          <div class="small text-muted">Loading Supplier Directory...</div>
        </div>

        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0 extra-small">
            <thead class="table-dark">
              <tr>
                <th style="width: 110px;">Code / Glcd</th>
                <th>Supplier Name</th>
                <th>Contact Person</th>
                <th style="width: 110px;">City</th>
                <th style="width: 90px;">PinCode</th>
                <th>Phone Numbers</th>
                <th style="width: 130px;" class="text-center">Status</th>
                <th style="width: 70px;" class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in suppliersList" :key="s.id">
                <td>
                  <span class="badge bg-light text-dark border fw-bold">
                    {{ s.code || 'SUP-' + s.id }}
                  </span>
                </td>
                <td>
                  <strong class="text-dark">{{ s.name }}</strong>
                </td>
                <td>{{ s.contact_person || '-' }}</td>
                <td>{{ s.city || '-' }}</td>
                <td>{{ s.pincode || '-' }}</td>
                <td>
                  <div class="d-flex flex-wrap gap-1">
                    <span 
                      v-for="ph in (s.phones || [])" 
                      :key="ph.id" 
                      class="badge bg-primary-subtle text-primary border extra-small"
                    >
                      <i class="fas fa-phone-alt me-0.5 text-secondary" style="font-size: 0.6rem;"></i>
                      {{ ph.phone_number }}
                    </span>
                    <span v-if="(!s.phones || !s.phones.length) && s.phone" class="badge bg-light text-secondary border extra-small">
                      {{ s.phone }}
                    </span>
                    <span v-if="(!s.phones || !s.phones.length) && !s.phone" class="text-muted">-</span>
                  </div>
                </td>
                <td class="text-center">
                  <span v-if="s.is_active" class="badge bg-success-subtle text-success border border-success-subtle">
                    <i class="fas fa-check-circle me-1"></i> Active
                  </span>
                  <span v-else class="badge bg-secondary" title="Retained for audit history / allocation references">
                    <i class="fas fa-archive me-1"></i> Inactive / Historical
                  </span>
                </td>
                <td class="text-center">
                  <button 
                    class="btn btn-xs btn-outline-danger py-0.5 px-1.5"
                    title="Delete or Deactivate Supplier"
                    @click="confirmDeleteSupplier(s)"
                  >
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </td>
              </tr>
              <tr v-if="!suppliersList.length">
                <td colspan="8" class="text-center py-4 text-muted">
                  No {{ statusFilter === 'active' ? 'active' : (statusFilter === 'inactive' ? 'inactive/historical' : '') }} suppliers found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="card-footer bg-white border-top py-2 px-3 d-flex justify-content-between align-items-center">
          <span class="extra-small text-muted">
            Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} {{ statusFilter === 'active' ? 'active' : '' }} suppliers)
          </span>
          <div class="btn-group btn-group-sm">
            <button 
              class="btn btn-outline-secondary btn-xs" 
              :disabled="pagination.current_page <= 1"
              @click="fetchSuppliersList(pagination.current_page - 1)"
            >
              &larr; Prev
            </button>
            <button 
              class="btn btn-outline-secondary btn-xs" 
              :disabled="pagination.current_page >= pagination.last_page"
              @click="fetchSuppliersList(pagination.current_page + 1)"
            >
              Next &rarr;
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: DELETE EXCEL IMPORT CONFIRMATION                                   -->
    <!-- ========================================================================= -->
    <div v-if="importToDelete" class="modal-backdrop fade show"></div>
    <div v-if="importToDelete" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white py-2.5 px-3">
            <h6 class="modal-title fw-bold">
              <i class="fas fa-exclamation-triangle me-1.5"></i> Delete Supplier Excel List
            </h6>
            <button type="button" class="btn-close btn-close-white" @click="importToDelete = null"></button>
          </div>

          <div class="modal-body p-3">
            <p class="small mb-2">
              Are you sure you want to remove all suppliers created from the import file:
            </p>
            <div class="p-2 rounded border bg-light small mb-3">
              <strong class="text-dark d-block">{{ importToDelete.filename }}</strong>
              <span class="extra-small text-muted">
                Imported on {{ formatDateTime(importToDelete.created_at) }} &bull; {{ importToDelete.suppliers_count || importToDelete.created_count || 0 }} created suppliers
              </span>
            </div>

            <div class="alert alert-warning py-2 px-2.5 extra-small mb-0">
              <i class="fas fa-shield-alt me-1 text-warning"></i>
              <strong>Dependency Protection:</strong>
              Suppliers with active assignments or historical audit references will be safely marked <em>Inactive</em> to protect production data integrity. Only unused suppliers will be soft-deleted. Pre-existing suppliers are untouched.
            </div>
          </div>

          <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="importToDelete = null" :disabled="deletingImport">
              Cancel
            </button>
            <button type="button" class="btn btn-sm btn-danger fw-bold px-3" @click="executeDeleteImport" :disabled="deletingImport">
              <span v-if="deletingImport" class="spinner-border spinner-border-sm me-1.5"></span>
              <i v-else class="fas fa-trash-alt me-1"></i> Confirm Delete List
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: DELETE INDIVIDUAL SUPPLIER CONFIRMATION                            -->
    <!-- ========================================================================= -->
    <div v-if="supplierToDelete" class="modal-backdrop fade show"></div>
    <div v-if="supplierToDelete" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white py-2.5 px-3">
            <h6 class="modal-title fw-bold">
              <i class="fas fa-user-minus me-1.5"></i> Delete Supplier
            </h6>
            <button type="button" class="btn-close btn-close-white" @click="supplierToDelete = null"></button>
          </div>

          <div class="modal-body p-3">
            <p class="small mb-2">
              Are you sure you want to delete supplier:
            </p>
            <div class="p-2 rounded border bg-light small mb-3">
              <strong class="text-dark d-block">{{ supplierToDelete.name }}</strong>
              <span class="extra-small text-muted">
                Code: {{ supplierToDelete.code || 'N/A' }} &bull; City: {{ supplierToDelete.city || 'N/A' }}
              </span>
            </div>

            <div class="alert alert-info py-2 px-2.5 extra-small mb-0">
              <i class="fas fa-info-circle me-1"></i>
              If this supplier has active or historical allocations, it will be deactivated to protect referential integrity and audit records.
            </div>
          </div>

          <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="supplierToDelete = null" :disabled="deletingSupplier">
              Cancel
            </button>
            <button type="button" class="btn btn-sm btn-danger fw-bold px-3" @click="executeDeleteSupplier" :disabled="deletingSupplier">
              <span v-if="deletingSupplier" class="spinner-border spinner-border-sm me-1.5"></span>
              <i v-else class="fas fa-trash-alt me-1"></i> Confirm Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const error = ref('');
const successMessage = ref('');

// Manual Form State
const submittingManual = ref(false);
const manualForm = ref({
  name: '',
  code: '',
  contact_person: '',
  city: '',
  pincode: '',
  phones: [''],
  remarks: '',
});

// Excel Import State
const selectedFile = ref(null);
const useSample = ref(false);
const previewLoading = ref(false);
const previewData = ref(null);
const currentPreviewFilename = ref('');
const committingImport = ref(false);

// Import History State
const importsList = ref([]);
const loadingImports = ref(false);
const importToDelete = ref(null);
const deletingImport = ref(false);

// Status Partitioning Filter State
const statusFilter = ref('active'); // 'active' | 'inactive' | 'all'
const statusCounts = ref({ active: 0, inactive: 0 });

// Collapsible Supplier List State
const isListExpanded = ref(true);
const loadingList = ref(false);
const suppliersList = ref([]);
const searchQuery = ref('');
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

// Individual Supplier Deletion State
const supplierToDelete = ref(null);
const deletingSupplier = ref(false);

let searchDebounce = null;
const debounceSearch = () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    fetchSuppliersList(1);
  }, 300);
};

const setStatusFilter = (filter) => {
  statusFilter.value = filter;
  fetchSuppliersList(1);
};

// Phone Repeater
const addPhoneField = () => {
  manualForm.value.phones.push('');
};

const removePhoneField = (index) => {
  if (manualForm.value.phones.length > 1) {
    manualForm.value.phones.splice(index, 1);
  }
};

const resetManualForm = () => {
  manualForm.value = {
    name: '',
    code: '',
    contact_person: '',
    city: '',
    pincode: '',
    phones: [''],
    remarks: '',
  };
};

// Format Date / Time helper
const formatDateTime = (dateStr) => {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

// Manual Submit Handler
const submitManualSupplier = async () => {
  if (!manualForm.value.name) return;

  submittingManual.value = true;
  error.value = '';
  successMessage.value = '';

  try {
    const payload = {
      name: manualForm.value.name,
      code: manualForm.value.code || null,
      contact_person: manualForm.value.contact_person || null,
      city: manualForm.value.city || null,
      pincode: manualForm.value.pincode || null,
      phones: manualForm.value.phones.filter(p => p.trim() !== ''),
      remarks: manualForm.value.remarks || null,
      is_active: true,
    };

    const res = await axios.post('/api/v1/suppliers', payload);
    successMessage.value = `Supplier "${res.data.supplier?.name}" created successfully!`;
    resetManualForm();
    fetchSuppliersList(1);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to create supplier.';
  } finally {
    submittingManual.value = false;
  }
};

// Excel File Upload & Preview
const handleFileUpload = (event) => {
  selectedFile.value = event.target.files[0] || null;
};

const loadSampleExcelPreview = async () => {
  previewLoading.value = true;
  useSample.value = true;
  error.value = '';
  try {
    const res = await axios.post('/api/v1/suppliers/import/preview', { use_sample: true });
    previewData.value = res.data;
    currentPreviewFilename.value = res.data.filename || 'supplier list 1.xlsx';
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to parse sample supplier list 1.xlsx.';
  } finally {
    previewLoading.value = false;
  }
};

const uploadAndPreview = async () => {
  if (!selectedFile.value) return;

  previewLoading.value = true;
  useSample.value = false;
  error.value = '';

  const formData = new FormData();
  formData.append('file', selectedFile.value);

  try {
    const res = await axios.post('/api/v1/suppliers/import/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    previewData.value = res.data;
    currentPreviewFilename.value = res.data.filename || selectedFile.value.name;
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to parse supplier Excel file.';
  } finally {
    previewLoading.value = false;
  }
};

const commitImport = async () => {
  if (!previewData.value || !previewData.value.rows) return;

  committingImport.value = true;
  error.value = '';
  successMessage.value = '';

  try {
    const res = await axios.post('/api/v1/suppliers/import/commit', {
      rows: previewData.value.rows,
      filename: currentPreviewFilename.value || 'supplier_list.xlsx',
    });
    successMessage.value = res.data.message || 'Suppliers successfully imported to database.';
    previewData.value = null;
    selectedFile.value = null;
    fetchSuppliersList(1);
    fetchImportsList();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to commit supplier import.';
  } finally {
    committingImport.value = false;
  }
};

// Fetch Imports List
const fetchImportsList = async () => {
  loadingImports.value = true;
  try {
    const res = await axios.get('/api/v1/suppliers/imports');
    importsList.value = res.data.imports || [];
  } catch (err) {
    console.error('Failed to load supplier imports history:', err);
  } finally {
    loadingImports.value = false;
  }
};

// Delete Import Handlers
const confirmDeleteImport = (imp) => {
  importToDelete.value = imp;
};

const executeDeleteImport = async () => {
  if (!importToDelete.value) return;

  deletingImport.value = true;
  error.value = '';
  successMessage.value = '';

  try {
    const res = await axios.delete(`/api/v1/suppliers/import/${importToDelete.value.id}`);
    successMessage.value = res.data.message || 'Supplier import list removed.';
    importToDelete.value = null;
    fetchImportsList();
    fetchSuppliersList(1);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to delete supplier import.';
  } finally {
    deletingImport.value = false;
  }
};

// Fetch Suppliers List
const fetchSuppliersList = async (page = 1) => {
  loadingList.value = true;
  try {
    const params = new URLSearchParams();
    params.append('page', page);
    params.append('per_page', 15);
    if (statusFilter.value && statusFilter.value !== 'all') {
      params.append('status', statusFilter.value);
    }
    if (searchQuery.value) {
      params.append('search', searchQuery.value);
    }

    const res = await axios.get(`/api/v1/suppliers?${params.toString()}`);
    suppliersList.value = res.data.data || [];
    pagination.value = {
      current_page: res.data.current_page || 1,
      last_page: res.data.last_page || 1,
      total: res.data.total || 0,
    };
    if (res.data.active_count !== undefined) {
      statusCounts.value.active = res.data.active_count;
    }
    if (res.data.inactive_count !== undefined) {
      statusCounts.value.inactive = res.data.inactive_count;
    }
  } catch (err) {
    console.error('Failed to load supplier directory:', err);
  } finally {
    loadingList.value = false;
  }
};

// Individual Supplier Deletion Handlers
const confirmDeleteSupplier = (supplier) => {
  supplierToDelete.value = supplier;
};

const executeDeleteSupplier = async () => {
  if (!supplierToDelete.value) return;

  deletingSupplier.value = true;
  error.value = '';
  successMessage.value = '';

  try {
    const res = await axios.delete(`/api/v1/suppliers/${supplierToDelete.value.id}`);
    successMessage.value = res.data.message || 'Supplier removed.';
    supplierToDelete.value = null;
    fetchSuppliersList(pagination.value.current_page);
    fetchImportsList();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to delete supplier.';
  } finally {
    deletingSupplier.value = false;
  }
};

// Echo Realtime Listeners
const setupEchoListener = () => {
  if (window.Echo) {
    window.Echo.channel('workflow')
      .listen('.supplier.deactivated', () => {
        fetchSuppliersList(pagination.value.current_page);
        fetchImportsList();
      })
      .listen('.supplier.assignment.updated', () => {
        fetchSuppliersList(pagination.value.current_page);
      });
  }
};

onMounted(() => {
  fetchSuppliersList(1);
  fetchImportsList();
  setupEchoListener();
});

onUnmounted(() => {
  if (window.Echo) {
    window.Echo.leaveChannel('workflow');
  }
});
</script>

<style scoped>
.supplier-add-wrapper {
  font-family: inherit;
}
.app-card {
  border: 1px solid #334155 !important;
  border-radius: 6px;
}
.category-icon-box {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.95rem;
}
.extra-small {
  font-size: 0.73rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.cursor-pointer {
  cursor: pointer;
}
.select-none {
  user-select: none;
}
.transition-all {
  transition: transform 0.2s ease, all 0.2s ease;
}
</style>
