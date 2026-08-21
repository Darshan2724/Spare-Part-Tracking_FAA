<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h3 class="fw-bold mb-1"><i class="fas fa-truck me-2 text-dark"></i>Supplier Management</h3>
              <p class="text-muted mb-0">Manage component suppliers, contact details, and supplier codes.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-primary btn-sm fw-bold text-nowrap" @click="openCreateModal">
                <i class="fas fa-plus me-1"></i> Add Supplier
              </button>
              <button class="btn btn-outline-success btn-sm fw-semibold text-nowrap" @click="exportSuppliers('excel')" :disabled="!!isExporting">
                <i v-if="isExporting === 'excel'" class="fas fa-spinner fa-spin me-1"></i>
                <i v-else class="fas fa-file-excel text-success me-1"></i> Export Excel
              </button>
              <button class="btn btn-outline-danger btn-sm fw-semibold text-nowrap" @click="exportSuppliers('pdf')" :disabled="!!isExporting">
                <i v-if="isExporting === 'pdf'" class="fas fa-spinner fa-spin me-1"></i>
                <i v-else class="fas fa-file-pdf text-danger me-1"></i> Export PDF
              </button>
            </div>
          </div>
        </div>

        <div class="card-body">
          <div v-if="error" class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>{{ error }}
            <button type="button" class="btn-close" @click="error = ''"></button>
          </div>
          <div v-if="successMessage" class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
            <button type="button" class="btn-close" @click="successMessage = ''"></button>
          </div>

          <!-- Table -->
          <div class="table-responsive">
            <table class="table table-hover align-middle border-top mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 120px;">CODE</th>
                  <th>SUPPLIER NAME</th>
                  <th>CONTACT PERSON</th>
                  <th>PHONE</th>
                  <th>EMAIL</th>
                  <th style="width: 100px;">STATUS</th>
                  <th style="width: 150px; text-align: center;">ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in suppliers" :key="s.id">
                  <td><span class="badge bg-light text-dark border px-2 py-1 fs-7 fw-semibold">{{ s.code }}</span></td>
                  <td><strong class="text-dark">{{ s.name }}</strong></td>
                  <td>{{ s.contact_person || '—' }}</td>
                  <td>{{ s.phone || '—' }}</td>
                  <td>{{ s.email || '—' }}</td>
                  <td>
                    <span class="badge" :class="s.is_active ? 'bg-success' : 'bg-secondary'">
                      {{ s.is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary me-1 px-2 py-1 fs-7" @click="openEditModal(s)">Edit</button>
                    <button class="btn btn-sm btn-outline-danger px-2 py-1 fs-7" @click="deleteSupplier(s)">Delete</button>
                  </td>
                </tr>
                <tr v-if="!suppliers.length">
                  <td colspan="7" class="text-center py-5 text-muted">
                    <i class="fas fa-truck fa-2x mb-2 text-secondary"></i>
                    <div>No suppliers found. Click <strong>+ Add Supplier</strong> to register a new vendor.</div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-dark text-white py-3">
            <h5 class="modal-title fw-bold">
              <i :class="editing ? 'fas fa-edit' : 'fas fa-plus-circle'" class="me-2 text-primary"></i>
              {{ editing ? 'Edit Supplier' : 'Add Supplier' }}
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <div v-if="modalError" class="alert alert-danger py-2 small mb-3">
              <i class="fas fa-exclamation-triangle me-1"></i>{{ modalError }}
            </div>

            <div class="mb-3">
              <label class="form-label small fw-bold mb-1">Supplier Code</label>
              <input type="text" v-model="form.code" class="form-control form-control-sm" placeholder="e.g. SUP-001 (Auto-generated if left blank)" />
              <small class="text-muted extra-small">Leave blank to auto-generate next sequential code.</small>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold mb-1">Supplier Name <span class="text-danger">*</span></label>
              <input type="text" v-model="form.name" class="form-control form-control-sm" placeholder="Company / Vendor Name" required />
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold mb-1">Contact Person</label>
              <input type="text" v-model="form.contact_person" class="form-control form-control-sm" placeholder="Full Name" />
            </div>
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label small fw-bold mb-1">Phone</label>
                <input type="text" v-model="form.phone" class="form-control form-control-sm" placeholder="Contact Number" />
              </div>
              <div class="col-6">
                <label class="form-label small fw-bold mb-1">Email</label>
                <input type="email" v-model="form.email" class="form-control form-control-sm" placeholder="orders@supplier.com" />
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold mb-1">Status</label>
              <select v-model="form.is_active" class="form-select form-select-sm">
                <option :value="true">Active</option>
                <option :value="false">Inactive</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-bold mb-1">Address / Plant Location</label>
              <textarea v-model="form.address" class="form-control form-control-sm" rows="2" placeholder="Factory Address, City, State"></textarea>
            </div>
          </div>
          <div class="modal-footer bg-light py-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm fw-bold px-3" @click="saveSupplier" :disabled="saving">
              <i v-if="saving" class="fas fa-spinner fa-spin me-1"></i>
              <i v-else class="fas fa-save me-1"></i>
              {{ editing ? 'Update Supplier' : 'Save Supplier' }}
            </button>
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
const suppliers = ref([]);
const editing = ref(false);
const editingId = ref(null);
const saving = ref(false);
const isExporting = ref(null);
const error = ref('');
const modalError = ref('');
const successMessage = ref('');

const form = ref({
  name: '',
  code: '',
  contact_person: '',
  phone: '',
  email: '',
  address: '',
  is_active: true,
});

const loadSuppliers = async () => {
  try {
    const res = await axios.get('/api/v1/suppliers');
    suppliers.value = res.data.data || res.data || [];
  } catch (err) {
    error.value = 'Failed to load suppliers.';
  }
};

const openCreateModal = () => {
  editing.value = false;
  editingId.value = null;
  modalError.value = '';
  form.value = {
    name: '',
    code: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    is_active: true,
  };
  const modalEl = document.getElementById('supplierModal');
  if (modalEl) {
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }
};

const openEditModal = (s) => {
  editing.value = true;
  editingId.value = s.id;
  modalError.value = '';
  form.value = {
    name: s.name || '',
    code: s.code || '',
    contact_person: s.contact_person || '',
    phone: s.phone || '',
    email: s.email || '',
    address: s.address || '',
    is_active: s.is_active !== undefined ? Boolean(s.is_active) : true,
  };
  const modalEl = document.getElementById('supplierModal');
  if (modalEl) {
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }
};

const saveSupplier = async () => {
  modalError.value = '';
  if (!form.value.name || !form.value.name.trim()) {
    modalError.value = 'Supplier Name is required.';
    return;
  }

  saving.value = true;
  try {
    if (editing.value) {
      const res = await axios.put(`/api/v1/suppliers/${editingId.value}`, form.value);
      successMessage.value = res.data.message || 'Supplier updated successfully.';
    } else {
      const res = await axios.post('/api/v1/suppliers', form.value);
      successMessage.value = res.data.message || 'Supplier added successfully.';
    }
    const modalEl = document.getElementById('supplierModal');
    if (modalEl) {
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();
    }
    await loadSuppliers();
  } catch (err) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) {
      const firstKey = Object.keys(serverErrors)[0];
      modalError.value = serverErrors[firstKey][0];
    } else {
      modalError.value = err.response?.data?.message || 'Failed to save supplier.';
    }
  } finally {
    saving.value = false;
  }
};

const deleteSupplier = async (s) => {
  if (!confirm(`Are you sure you want to delete supplier "${s.name}"?`)) return;
  try {
    await axios.delete(`/api/v1/suppliers/${s.id}`);
    successMessage.value = 'Supplier deleted successfully.';
    await loadSuppliers();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to delete supplier.';
  }
};

const exportSuppliers = async (format) => {
  if (isExporting.value) return;
  isExporting.value = format;
  try {
    const res = await axios.post('/api/v1/export/suppliers', { format }, { responseType: 'blob' });
    const blob = new Blob([res.data], {
      type: format === 'excel'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/pdf'
    });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const timestamp = new Date().toISOString().slice(0, 10);
    a.download = `SpareTrack_Suppliers_${timestamp}.${format === 'excel' ? 'xlsx' : 'pdf'}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  } catch (err) {
    console.error('Supplier export error:', err);
    alert(`Unable to generate ${format === 'excel' ? 'Excel' : 'PDF'} file. Please try again.`);
  } finally {
    isExporting.value = null;
  }
};

onMounted(() => {
  loadSuppliers();
});
</script>

<style scoped>
.fs-7 {
  font-size: 0.75rem;
}
.extra-small {
  font-size: 0.72rem;
}
</style>
