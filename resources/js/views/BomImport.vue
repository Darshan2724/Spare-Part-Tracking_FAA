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
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

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
              <table class="table table-sm align-middle">
                <thead>
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
                    <td>{{ row.standard_part_no }}</td>
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
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
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
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
