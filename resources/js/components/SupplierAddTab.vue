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

                <!-- Remarks / Notes -->
                <div class="col-12">
                  <label class="form-label extra-small fw-bold text-dark mb-1">Remarks</label>
                  <input 
                    v-model="manualForm.remarks" 
                    type="text" 
                    class="form-control form-control-sm shadow-xs" 
                    placeholder="Optional remarks" 
                  />
                </div>
              </div>

              <!-- Submit Button -->
              <div class="mt-3 pt-2 border-top d-flex justify-content-end">
                <button 
                  type="submit" 
                  class="btn btn-sm btn-primary fw-semibold px-3 py-1.5 shadow-xs"
                  :disabled="submittingManual || !manualForm.name"
                >
                  <span v-if="submittingManual" class="spinner-border spinner-border-sm me-1.5"></span>
                  <i v-else class="fas fa-save me-1.5"></i> Save Supplier
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- 2. EXCEL IMPORT CARD -->
      <div class="col-12 col-xl-5">
        <div class="card border shadow-xs bg-white h-100 app-card">
          <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
              <span class="category-icon-box bg-success-subtle text-success">
                <i class="fas fa-file-excel"></i>
              </span>
              <div>
                <h6 class="fw-bold text-dark mb-0">Import Supplier Excel</h6>
              </div>
            </div>
          </div>

          <div class="card-body p-3 d-flex flex-column justify-content-between">
            <div>
              <!-- Quick Import Sample File Button -->
              <div class="p-2.5 mb-3 bg-light rounded border d-flex justify-content-between align-items-center">
                <div>
                  <strong class="text-dark small d-block">BOM/supplier list 1.xlsx</strong>
                </div>
                <button 
                  type="button" 
                  class="btn btn-xs btn-outline-success fw-semibold shadow-xs" 
                  :disabled="previewLoading" 
                  @click="loadSampleExcelPreview"
                >
                  <span v-if="previewLoading && useSample" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="fas fa-file-import me-1"></i> Preview BOM File
                </button>
              </div>

              <!-- Upload Custom File -->
              <div class="mb-2">
                <label class="form-label extra-small fw-bold text-dark mb-1">Select Supplier Excel File</label>
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
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- EXCEL IMPORT PREVIEW MODAL / CARD (When previewData is ready)             -->
    <!-- ========================================================================= -->
    <div v-if="previewData" class="card border shadow-xs bg-white mb-3 app-card border-primary">
      <div class="card-header bg-primary text-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="fas fa-eye fs-6"></i>
          <h6 class="fw-bold mb-0">Supplier Excel Import Preview</h6>
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
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="previewData = null">
            <i class="fas fa-times me-1"></i> Cancel Preview
          </button>

          <button 
            type="button" 
            class="btn btn-sm btn-success fw-bold px-3 shadow-xs" 
            :disabled="committingImport" 
            @click="commitImport"
          >
            <span v-if="committingImport" class="spinner-border spinner-border-sm me-1.5"></span>
            <i v-else class="fas fa-check me-1.5"></i> Confirm &amp; Import {{ previewData.total_rows }} Suppliers
          </button>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- SECTION 3: SUPPLIER LIST (COLLAPSED BY DEFAULT)                           -->
    <!-- ========================================================================= -->
    <div class="card border shadow-xs bg-white app-card">
      <div 
        class="card-header bg-white py-2.5 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2 cursor-pointer select-none"
        @click="isListExpanded = !isListExpanded"
      >
        <div class="d-flex align-items-center gap-2">
          <i 
            class="fas text-primary fs-7 transition-all"
            :class="isListExpanded ? 'fa-chevron-down' : 'fa-chevron-right'"
          ></i>
          <strong class="text-dark small">
            Supplier List
          </strong>
          <span class="badge bg-light text-dark border extra-small">
            {{ pagination.total }} Registered
          </span>
        </div>

        <!-- Search input & refresh (visible when expanded or inline) -->
        <div v-if="isListExpanded" class="d-flex align-items-center gap-2" @click.stop>
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
                <th style="width: 120px;">Code / Glcd</th>
                <th>Supplier Name</th>
                <th>Contact Person</th>
                <th style="width: 110px;">City</th>
                <th style="width: 90px;">PinCode</th>
                <th>Phone Numbers</th>
                <th style="width: 90px;" class="text-center">Status</th>
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
                    Active
                  </span>
                  <span v-else class="badge bg-secondary">
                    Inactive
                  </span>
                </td>
              </tr>
              <tr v-if="!suppliersList.length">
                <td colspan="7" class="text-center py-4 text-muted">
                  No suppliers found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="card-footer bg-white border-top py-2 px-3 d-flex justify-content-between align-items-center">
          <span class="extra-small text-muted">
            Page {{ pagination.current_page }} of {{ pagination.last_page }}
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
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
const committingImport = ref(false);

// Collapsible Supplier List State (Collapsed by default)
const isListExpanded = ref(false);
const loadingList = ref(false);
const suppliersList = ref([]);
const searchQuery = ref('');
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

let searchDebounce = null;
const debounceSearch = () => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    fetchSuppliersList(1);
  }, 300);
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
    });
    successMessage.value = res.data.message || 'Suppliers successfully imported to database.';
    previewData.value = null;
    selectedFile.value = null;
    fetchSuppliersList(1);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to commit supplier import.';
  } finally {
    committingImport.value = false;
  }
};

// Fetch Suppliers List
const fetchSuppliersList = async (page = 1) => {
  loadingList.value = true;
  try {
    const params = new URLSearchParams();
    params.append('page', page);
    params.append('per_page', 15);
    if (searchQuery.value) params.append('search', searchQuery.value);

    const res = await axios.get(`/api/v1/suppliers?${params.toString()}`);
    suppliersList.value = res.data.data || [];
    pagination.value = {
      current_page: res.data.current_page || 1,
      last_page: res.data.last_page || 1,
      total: res.data.total || 0,
    };
  } catch (err) {
    console.error('Failed to load supplier directory:', err);
  } finally {
    loadingList.value = false;
  }
};

onMounted(() => {
  fetchSuppliersList(1);
});
</script>

<style scoped>
.supplier-add-wrapper {
  font-family: inherit;
}
.app-card {
  border: 1px solid #cbd5e1 !important;
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
