<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-truck me-2 text-dark"></i>Supplier Management</h3>
                <p class="text-muted mb-0">Manage component suppliers, contact details, and supplier codes.</p>
              </div>
              <button v-if="authStore.userRole !== 'MANAGER'" class="btn btn-primary" @click="openCreateModal">
                <i class="fas fa-plus me-1"></i> Add New Supplier
              </button>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

            <!-- Table -->
            <div class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Code</th>
                    <th>Supplier Name</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th style="width: 150px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in suppliers" :key="s.id">
                    <td><span class="badge bg-light text-dark border">{{ s.code }}</span></td>
                    <td><strong class="text-dark">{{ s.name }}</strong></td>
                    <td>{{ s.contact_person || '—' }}</td>
                    <td>{{ s.phone || '—' }}</td>
                    <td>{{ s.email || '—' }}</td>
                    <td>
                      <span class="badge" :class="s.is_active ? 'bg-success' : 'bg-secondary'">
                        {{ s.is_active ? 'Active' : 'Inactive' }}
                      </span>
                    </td>
                    <td>
                      <button class="btn btn-sm btn-outline-primary me-1" @click="openEditModal(s)">Edit</button>
                      <button class="btn btn-sm btn-outline-danger" @click="deleteSupplier(s)">Delete</button>
                    </td>
                  </tr>
                  <tr v-if="!suppliers.length">
                    <td colspan="7" class="text-center py-5 text-muted">No suppliers found.</td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="supplierModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-dark text-white">
            <h5 class="modal-title fw-bold">{{ editing ? 'Edit Supplier' : 'Add Supplier' }}</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label fw-semibold">Supplier Name</label>
              <input type="text" v-model="form.name" class="form-control" placeholder="Company Name" />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Supplier Code</label>
              <input type="text" v-model="form.code" class="form-control" placeholder="SUP-001" />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Contact Person</label>
              <input type="text" v-model="form.contact_person" class="form-control" />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" v-model="form.phone" class="form-control" />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" v-model="form.email" class="form-control" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary fw-bold" @click="saveSupplier">Save Supplier</button>
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
const error = ref('');
const successMessage = ref('');

const form = ref({
  name: '',
  code: '',
  contact_person: '',
  phone: '',
  email: '',
});

const loadSuppliers = async () => {
  try {
    const res = await axios.get('/api/v1/suppliers');
    suppliers.value = res.data.data || [];
  } catch (err) {
    error.value = 'Failed to load suppliers.';
  }
};

const openCreateModal = () => {
  editing.value = false;
  editingId.value = null;
  form.value = { name: '', code: '', contact_person: '', phone: '', email: '' };
  const modal = new bootstrap.Modal(document.getElementById('supplierModal'));
  modal.show();
};

const openEditModal = (s) => {
  editing.value = true;
  editingId.value = s.id;
  form.value = { ...s };
  const modal = new bootstrap.Modal(document.getElementById('supplierModal'));
  modal.show();
};

const saveSupplier = async () => {
  try {
    if (editing.value) {
      await axios.put(`/api/v1/suppliers/${editingId.value}`, form.value);
      successMessage.value = 'Supplier updated.';
    } else {
      await axios.post('/api/v1/suppliers', form.value);
      successMessage.value = 'Supplier created.';
    }
    const modal = bootstrap.Modal.getInstance(document.getElementById('supplierModal'));
    if (modal) modal.hide();
    loadSuppliers();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save supplier.';
  }
};

const deleteSupplier = async (s) => {
  if (!confirm(`Delete supplier ${s.name}?`)) return;
  try {
    await axios.delete(`/api/v1/suppliers/${s.id}`);
    successMessage.value = 'Supplier deleted.';
    loadSuppliers();
  } catch (err) {
    error.value = 'Failed to delete supplier.';
  }
};

onMounted(() => {
  loadSuppliers();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
