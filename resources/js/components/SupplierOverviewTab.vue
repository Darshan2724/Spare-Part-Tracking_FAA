<template>
  <div class="supplier-overview-wrapper">
    <!-- Alert Feedback -->
    <div v-if="error" class="alert alert-danger alert-dismissible fade show shadow-xs py-2 px-3 mb-3">
      <i class="fas fa-exclamation-triangle me-1.5"></i>{{ error }}
      <button type="button" class="btn-close py-2" @click="error = ''"></button>
    </div>
    <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs py-2 px-3 mb-3">
      <i class="fas fa-check-circle me-1.5"></i>{{ successMessage }}
      <button type="button" class="btn-close py-2" @click="successMessage = ''"></button>
    </div>

    <!-- Filter Card -->
    <div class="card border shadow-xs bg-white mb-3 app-card">
      <div class="card-body p-3">
        <div class="row g-2.5 align-items-center">
          <div class="col-12 col-md-3">
            <label class="form-label extra-small fw-bold text-dark mb-1">
              <i class="fas fa-project-diagram text-primary me-1"></i>Project Filter
            </label>
            <select v-model="filters.project_id" @change="fetchOverview(1)" class="form-select form-select-sm shadow-xs">
              <option value="">All Projects</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.project_code || p.name }}</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label extra-small fw-bold text-dark mb-1">
              <i class="fas fa-cubes text-info me-1"></i>Category Filter
            </label>
            <select v-model="filters.category" @change="fetchOverview(1)" class="form-select form-select-sm shadow-xs">
              <option value="">All Categories</option>
              <option value="BASE">BASE</option>
              <option value="WELDMENT">WELDMENT</option>
              <option value="CHILD_PART">CHILD PART</option>
            </select>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label extra-small fw-bold text-dark mb-1">
              <i class="fas fa-truck text-success me-1"></i>Supplier Filter
            </label>
            <select v-model="filters.supplier_id" @change="fetchOverview(1)" class="form-select form-select-sm shadow-xs">
              <option value="">All Suppliers</option>
              <option v-for="s in activeSuppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.code || 'SUP' }})</option>
            </select>
          </div>

          <div class="col-12 col-md-3 d-flex align-items-end justify-content-between gap-2 mt-auto">
            <div class="flex-grow-1">
              <label class="form-label extra-small fw-bold text-dark mb-1">
                <i class="fas fa-search text-secondary me-1"></i>Quick Search
              </label>
              <input 
                v-model="filters.search" 
                @input="debounceSearch" 
                class="form-control form-control-sm shadow-xs" 
                placeholder="Search Jig, Unit..." 
              />
            </div>
            <button class="btn btn-outline-secondary btn-sm" @click="resetFilters" title="Reset Filters">
              <i class="fas fa-redo"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Allocations Table Card -->
    <div class="card border shadow-xs bg-white app-card">
      <div class="card-header bg-white py-2.5 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
          <strong class="text-dark small">
            <i class="fas fa-table-list text-primary me-1.5"></i>Supplier Allocations Overview
          </strong>
          <span class="badge bg-primary-subtle text-primary border border-primary-subtle extra-small">
            {{ pagination.total }} Total Records
          </span>
        </div>

        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-xs btn-outline-secondary" @click="fetchOverview(pagination.current_page)" :disabled="loading">
            <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i> Refresh
          </button>
        </div>
      </div>

      <div class="card-body p-0">
        <!-- Loading -->
        <div v-if="loading && !items.length" class="text-center py-5">
          <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
          <div class="small text-muted">Loading active allocations...</div>
        </div>

        <!-- Table -->
        <div v-else class="table-responsive">
          <table class="table table-hover align-middle mb-0 extra-small">
            <thead class="table-dark">
              <tr>
                <th style="width: 140px;">Project</th>
                <th style="width: 120px;">Jig</th>
                <th style="width: 100px;">Unit</th>
                <th style="width: 120px;">Category</th>
                <th>Assigned Supplier</th>
                <th style="width: 120px;">Target Date</th>
                <th style="width: 100px;">Status</th>
                <th v-if="canEdit" style="width: 80px;" class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>
                  <span class="badge bg-light text-dark border fw-bold">
                    {{ item.project?.project_code || item.project?.name || 'PROJ' }}
                  </span>
                </td>
                <td>
                  <strong class="text-dark">{{ item.jig_no }}</strong>
                </td>
                <td>
                  <span class="badge bg-light text-dark border">
                    Unit {{ item.unit_no }}
                  </span>
                </td>
                <td>
                  <span class="badge px-2 py-1" :class="getCategoryBadgeClass(item.category)">
                    {{ item.category }}
                  </span>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1.5">
                    <i class="fas fa-truck text-primary extra-small"></i>
                    <strong class="text-dark">{{ item.supplier?.name || 'Unknown' }}</strong>
                    <span v-if="item.supplier?.code" class="badge bg-light text-secondary border extra-small">
                      {{ item.supplier.code }}
                    </span>
                  </div>
                </td>
                <td>
                  <span class="badge bg-light text-dark border">
                    <i class="fas fa-calendar-day text-secondary me-1"></i>{{ item.assignment_date }}
                  </span>
                </td>
                <td>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">
                    <i class="fas fa-check me-0.5"></i> Active
                  </span>
                </td>
                <td v-if="canEdit" class="text-center">
                  <button 
                    class="btn btn-xs btn-outline-danger py-0.5 px-1.5" 
                    title="Remove Assignment" 
                    @click="removeAssignment(item.id, item.category, item.unit_no)"
                  >
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </td>
              </tr>
              <tr v-if="!items.length">
                <td :colspan="canEdit ? 8 : 7" class="text-center py-5 text-muted">
                  <i class="fas fa-inbox fs-3 d-block mb-2 text-secondary opacity-50"></i>
                  No supplier allocations match the selected criteria.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Bar -->
        <div v-if="pagination.last_page > 1" class="card-footer bg-white border-top py-2 px-3 d-flex justify-content-between align-items-center">
          <span class="extra-small text-muted">
            Showing Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} total)
          </span>
          <div class="btn-group btn-group-sm">
            <button 
              class="btn btn-outline-secondary btn-xs" 
              :disabled="pagination.current_page <= 1"
              @click="fetchOverview(pagination.current_page - 1)"
            >
              &larr; Prev
            </button>
            <button 
              class="btn btn-outline-secondary btn-xs" 
              :disabled="pagination.current_page >= pagination.last_page"
              @click="fetchOverview(pagination.current_page + 1)"
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
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

const authStore = useAuthStore();
const canEdit = computed(() => ['ADMIN', 'MANAGER', 'PURCHASE'].includes(authStore.userRole));

const loading = ref(false);
const error = ref('');
const successMessage = ref('');

const projects = ref([]);
const activeSuppliers = ref([]);
const items = ref([]);

const filters = ref({
  project_id: '',
  category: '',
  supplier_id: '',
  search: '',
});

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
});

let searchTimer = null;
const debounceSearch = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    fetchOverview(1);
  }, 300);
};

const resetFilters = () => {
  filters.value = {
    project_id: '',
    category: '',
    supplier_id: '',
    search: '',
  };
  fetchOverview(1);
};

const fetchSuppliers = async () => {
  try {
    const res = await axios.get('/api/v1/suppliers/active-list');
    activeSuppliers.value = res.data.suppliers || [];
  } catch (err) {
    console.error('Failed to load suppliers:', err);
  }
};

const fetchProjects = async () => {
  try {
    const res = await axios.get('/api/v1/projects');
    projects.value = res.data.data || res.data.projects || [];
  } catch (err) {
    console.error('Failed to load projects:', err);
  }
};

const fetchOverview = async (page = 1) => {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams();
    params.append('page', page);
    if (filters.value.project_id) params.append('project_id', filters.value.project_id);
    if (filters.value.category) params.append('category', filters.value.category);
    if (filters.value.supplier_id) params.append('supplier_id', filters.value.supplier_id);
    if (filters.value.search) params.append('search', filters.value.search);

    const res = await axios.get(`/api/v1/supplier-allocation/overview?${params.toString()}`);
    items.value = res.data.data || [];
    pagination.value = {
      current_page: res.data.current_page || 1,
      last_page: res.data.last_page || 1,
      total: res.data.total || 0,
    };
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load supplier allocation overview.';
  } finally {
    loading.value = false;
  }
};

const removeAssignment = async (id, category, unitNo) => {
  if (!confirm(`Remove ${category} supplier assignment for Unit ${unitNo}?`)) return;

  try {
    const res = await axios.delete(`/api/v1/supplier-allocation/assignments/${id}`);
    successMessage.value = res.data.message || 'Assignment removed successfully.';
    fetchOverview(pagination.value.current_page);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to remove assignment.';
  }
};

const getCategoryBadgeClass = (category) => {
  switch (category) {
    case 'BASE': return 'bg-success text-white';
    case 'WELDMENT': return 'bg-info text-dark';
    case 'CHILD_PART': return 'bg-warning text-dark';
    default: return 'bg-secondary text-white';
  }
};

onMounted(() => {
  fetchProjects();
  fetchSuppliers();
  fetchOverview(1);
});
</script>

<style scoped>
.supplier-overview-wrapper {
  font-family: inherit;
}
.app-card {
  border: 1px solid #cbd5e1 !important;
  border-radius: 6px;
}
.extra-small {
  font-size: 0.73rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.form-select-xs {
  font-size: 0.75rem;
  padding: 0.2rem 0.5rem;
}
</style>
