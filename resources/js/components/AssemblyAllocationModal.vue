<template>
  <div 
    class="modal fade" 
    id="assemblyAllocationModal" 
    tabindex="-1" 
    aria-labelledby="assemblyAllocationModalLabel" 
    aria-hidden="true"
    ref="modalRef"
  >
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content shadow-lg border-0">
        
        <!-- MODAL HEADER -->
        <div class="modal-header text-white" :style="headerGradient">
          <div class="d-flex align-items-center gap-3">
            <div class="p-2 rounded bg-white bg-opacity-20">
              <i class="fas fa-tasks fa-lg"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-dark fw-bold px-2 py-0.5 text-uppercase">{{ bomType }}</span>
                <h5 class="modal-title fw-bold mb-0" id="assemblyAllocationModalLabel">
                  Assembly Allocation: {{ standardPartNo }}
                </h5>
              </div>
              <p class="mb-0 extra-small text-white-50">
                Allocate available generic stock to specific units for production assembly.
              </p>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" @click="hide" aria-label="Close"></button>
        </div>

        <!-- MODAL BODY -->
        <div class="modal-body p-4 bg-light">
          
          <!-- NOTIFICATIONS -->
          <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show shadow-xs mb-3 py-2 px-3 small" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> {{ errorMessage }}
            <button type="button" class="btn-close py-2 px-3" @click="errorMessage = ''"></button>
          </div>

          <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs mb-3 py-2 px-3 small" role="alert">
            <i class="fas fa-check-circle me-1"></i> {{ successMessage }}
            <button type="button" class="btn-close py-2 px-3" @click="successMessage = ''"></button>
          </div>

          <!-- LOADING SPINNER -->
          <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted small mt-2">Loading unit allocation data...</p>
          </div>

          <div v-else>
            <!-- 4 SUMMARY CARDS BANNER -->
            <div class="row g-2 mb-4">
              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs h-100 bg-white p-3 text-center rounded-3">
                  <div class="text-muted extra-small text-uppercase fw-bold">Total Required</div>
                  <div class="fs-4 fw-bold text-dark">{{ summary.total_required || 0 }}</div>
                  <div class="extra-small text-muted">{{ units.length }} unit demand(s)</div>
                </div>
              </div>

              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs h-100 bg-white p-3 text-center rounded-3">
                  <div class="text-muted extra-small text-uppercase fw-bold">Assembly Ready</div>
                  <div class="fs-4 fw-bold text-primary">{{ summary.total_assembly_ready || 0 }}</div>
                  <div class="extra-small text-muted">Physically at Assembly Stage</div>
                </div>
              </div>

              <div class="col-6 col-md-3">
                <div class="card border-0 shadow-xs h-100 bg-white p-3 text-center rounded-3">
                  <div class="text-muted extra-small text-uppercase fw-bold">Manager Allocated</div>
                  <div class="fs-4 fw-bold text-warning">{{ summary.total_allocated || 0 }}</div>
                  <div class="extra-small text-muted">Reserved for specific units</div>
                </div>
              </div>

              <div class="col-6 col-md-3">
                <div 
                  class="card border-0 shadow-xs h-100 p-3 text-center rounded-3"
                  :class="summary.unallocated_assembly_ready > 0 ? 'bg-success bg-opacity-10 border border-success' : 'bg-secondary bg-opacity-10'"
                >
                  <div class="extra-small text-uppercase fw-bold" :class="summary.unallocated_assembly_ready > 0 ? 'text-success' : 'text-muted'">
                    Available Pool
                  </div>
                  <div class="fs-4 fw-bold" :class="summary.unallocated_assembly_ready > 0 ? 'text-success' : 'text-muted'">
                    {{ summary.unallocated_assembly_ready || 0 }}
                  </div>
                  <div class="extra-small" :class="summary.unallocated_assembly_ready > 0 ? 'text-success' : 'text-muted'">
                    Unallocated Free Stock
                  </div>
                </div>
              </div>
            </div>

            <!-- ALLOCATION TABLE -->
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden bg-white">
              <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div class="fw-bold text-dark small">
                  <i class="fas fa-cubes me-1 text-primary"></i>
                  Eligible Production Units &amp; Demand Breakdown
                </div>
                <button class="btn btn-outline-secondary btn-sm py-1 px-2 extra-small" @click="loadContext" :disabled="loading">
                  <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh
                </button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 extra-small">
                  <thead class="table-light">
                    <tr>
                      <th>Project / Jig</th>
                      <th>Unit No</th>
                      <th>Side</th>
                      <th class="text-center">Req Qty</th>
                      <th class="text-center">Assembled</th>
                      <th class="text-center">Remaining Need</th>
                      <th class="text-center">Current Allocation</th>
                      <th style="min-width: 260px;" class="text-center">Allocate Quantity</th>
                      <th class="text-center">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="units.length === 0">
                      <td colspan="9" class="text-center py-4 text-muted">
                        No units found requiring this part.
                      </td>
                    </tr>
                    <tr 
                      v-for="unit in units" 
                      :key="unit.bom_item_id + '_' + unit.side"
                      :class="{ 'table-warning bg-opacity-25': unit.allocated_quantity > 0 }"
                    >
                      <td>
                        <div class="fw-bold text-dark">{{ unit.project_code }}</div>
                        <div class="extra-small text-muted">{{ unit.jig_no }}</div>
                      </td>
                      <td>
                        <span class="badge bg-secondary-subtle text-dark border px-2 py-1">
                          {{ unit.unit_no }}
                        </span>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark border">{{ unit.side }}</span>
                      </td>
                      <td class="text-center fw-bold">{{ unit.required_quantity }}</td>
                      <td class="text-center fw-bold text-success">{{ unit.assembly_completed_quantity }}</td>
                      <td class="text-center">
                        <span 
                          class="badge" 
                          :class="unit.remaining_need > 0 ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-light text-muted'"
                        >
                          {{ unit.remaining_need }}
                        </span>
                      </td>
                      <td class="text-center">
                        <div v-if="unit.allocated_quantity > 0">
                          <span class="badge bg-warning text-dark fw-bold px-2 py-1 fs-7">
                            {{ unit.allocated_quantity }} pcs
                          </span>
                          <div v-if="unit.allocated_by_name" class="text-muted extra-small mt-0.5" style="font-size: 0.65rem;">
                            By: {{ unit.allocated_by_name }}
                          </div>
                        </div>
                        <span v-else class="text-muted">&mdash;</span>
                      </td>
                      
                      <!-- INPUT & STEPPER -->
                      <td class="text-center">
                        <div v-if="unit.remaining_need === 0" class="text-muted extra-small">
                          <i class="fas fa-check-circle text-success me-1"></i> Unit Completed
                        </div>
                        <div v-else class="d-flex align-items-center justify-content-center gap-1">
                          <button 
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            type="button"
                            @click="stepQty(unit, -1)"
                            :disabled="submitting || (allocQuantities[unitKey(unit)] || 0) <= 0"
                          >
                            <i class="fas fa-minus fa-xs"></i>
                          </button>
                          
                          <input 
                            type="number" 
                            class="form-control form-control-sm text-center fw-bold"
                            style="width: 75px;"
                            v-model.number="allocQuantities[unitKey(unit)]"
                            min="0"
                            :max="unit.max_total_allocatable"
                            :disabled="submitting"
                          />

                          <button 
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            type="button"
                            @click="stepQty(unit, 1)"
                            :disabled="submitting || (allocQuantities[unitKey(unit)] || 0) >= unit.max_total_allocatable"
                          >
                            <i class="fas fa-plus fa-xs"></i>
                          </button>

                          <button 
                            class="btn btn-sm btn-outline-primary px-2 py-1 extra-small"
                            type="button"
                            title="Max available for this unit"
                            @click="setMaxQty(unit)"
                            :disabled="submitting || unit.max_total_allocatable <= 0"
                          >
                            Max ({{ unit.max_total_allocatable }})
                          </button>
                        </div>
                      </td>

                      <!-- ACTION BUTTONS -->
                      <td class="text-center">
                        <div class="d-flex justify-content-center gap-1">
                          <!-- SAVE / UPDATE BUTTON -->
                          <button 
                            v-if="unit.allocated_quantity === 0"
                            class="btn btn-sm btn-primary py-1 px-2 extra-small"
                            @click="saveAllocation(unit)"
                            :disabled="submitting || !allocQuantities[unitKey(unit)] || allocQuantities[unitKey(unit)] <= 0"
                          >
                            <i class="fas fa-check me-1" :class="{ 'fa-spin': isRowLoading(unit) }"></i>
                            Allocate
                          </button>

                          <button 
                            v-else-if="allocQuantities[unitKey(unit)] !== unit.allocated_quantity && allocQuantities[unitKey(unit)] > 0"
                            class="btn btn-sm btn-warning text-dark py-1 px-2 extra-small fw-semibold"
                            @click="saveAllocation(unit)"
                            :disabled="submitting"
                          >
                            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': isRowLoading(unit) }"></i>
                            Update
                          </button>

                          <!-- RELEASE BUTTON -->
                          <button 
                            v-if="unit.allocated_quantity > 0"
                            class="btn btn-sm btn-outline-danger py-1 px-2 extra-small"
                            title="Release this unit's allocation back to the available pool"
                            @click="releaseAllocation(unit)"
                            :disabled="submitting"
                          >
                            <i class="fas fa-times me-1" :class="{ 'fa-spin': isRowLoading(unit) }"></i>
                            Release
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- FOOTER NOTICE -->
            <div class="d-flex align-items-center justify-content-between mt-3 text-muted extra-small">
              <div>
                <i class="fas fa-info-circle me-1 text-primary"></i>
                Manager allocations reserve generic stock for specific units. When assembly is completed, active allocations are automatically consumed.
              </div>
              <button type="button" class="btn btn-secondary btn-sm px-4" @click="hide">
                Done
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import axios from 'axios';
import * as bootstrap from 'bootstrap';

const props = defineProps({
  standardPartNo: {
    type: String,
    required: true,
  },
  bomType: {
    type: String,
    default: 'BOP',
  },
  projectId: {
    type: [Number, String],
    default: null,
  },
});

const emit = defineEmits(['allocated', 'closed']);

const modalRef = ref(null);
let modalInstance = null;

const loading = ref(false);
const submitting = ref(false);
const actionRowKey = ref(null);
const errorMessage = ref('');
const successMessage = ref('');

const summary = ref({
  standard_part_no: '',
  bom_type: 'BOP',
  total_required: 0,
  total_received: 0,
  total_assembly_ready: 0,
  total_allocated: 0,
  unallocated_assembly_ready: 0,
  total_assembly_completed: 0,
  total_unfulfilled: 0,
});

const units = ref([]);
const allocQuantities = reactive({});

const headerGradient = computed(() => {
  return props.bomType === 'STD'
    ? 'background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);'
    : 'background: linear-gradient(135deg, #d97706 0%, #b45309 100%);';
});

function unitKey(unit) {
  return `${unit.bom_item_id}_${unit.side}`;
}

function isRowLoading(unit) {
  return submitting.value && actionRowKey.value === unitKey(unit);
}

function stepQty(unit, delta) {
  const k = unitKey(unit);
  const current = allocQuantities[k] || 0;
  const next = Math.max(0, Math.min(unit.max_total_allocatable, current + delta));
  allocQuantities[k] = next;
}

function setMaxQty(unit) {
  const k = unitKey(unit);
  allocQuantities[k] = unit.max_total_allocatable;
}

// Load context from backend
async function loadContext() {
  if (!props.standardPartNo) return;
  loading.value = true;
  errorMessage.value = '';

  try {
    const params = {
      standard_part_no: props.standardPartNo,
      bom_type: props.bomType,
    };
    if (props.projectId) {
      params.project_id = props.projectId;
    }

    const res = await axios.get('/api/v1/assembly-allocation/context', { params });
    if (res.data?.success) {
      summary.value = res.data.data?.part_summary || {};
      units.value = res.data.data?.units || [];

      // Initialize inputs with current allocated quantity or 0
      units.value.forEach(u => {
        const k = unitKey(u);
        allocQuantities[k] = u.allocated_quantity > 0 ? u.allocated_quantity : 0;
      });
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load allocation context.';
  } finally {
    loading.value = false;
  }
}

// Save or update allocation for a unit
async function saveAllocation(unit) {
  const k = unitKey(unit);
  const qty = parseInt(allocQuantities[k], 10);

  if (!qty || qty <= 0) {
    errorMessage.value = 'Please enter a valid quantity greater than 0.';
    return;
  }

  submitting.value = true;
  actionRowKey.value = k;
  errorMessage.value = '';
  successMessage.value = '';

  try {
    let res;
    if (unit.active_allocation_id && qty > 0) {
      // Adjust existing allocation
      res = await axios.post('/api/v1/assembly-allocation/adjust', {
        allocation_id: unit.active_allocation_id,
        quantity: qty,
      });
    } else {
      // Create new allocation
      res = await axios.post('/api/v1/assembly-allocation/allocate', {
        bom_item_id: unit.bom_item_id,
        side: unit.side,
        quantity: qty,
      });
    }

    if (res.data?.success) {
      successMessage.value = res.data?.message || 'Allocation saved successfully.';
      emit('allocated', { standard_part_no: props.standardPartNo, bom_type: props.bomType });
      await loadContext();
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to save allocation.';
  } finally {
    submitting.value = false;
    actionRowKey.value = null;
  }
}

// Release allocation back to pool
async function releaseAllocation(unit) {
  if (!unit.active_allocation_id) return;

  submitting.value = true;
  actionRowKey.value = unitKey(unit);
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const res = await axios.post('/api/v1/assembly-allocation/deallocate', {
      allocation_id: unit.active_allocation_id,
    });

    if (res.data?.success) {
      successMessage.value = 'Allocation released back to available pool.';
      emit('allocated', { standard_part_no: props.standardPartNo, bom_type: props.bomType });
      await loadContext();
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to release allocation.';
  } finally {
    submitting.value = false;
    actionRowKey.value = null;
  }
}

// Modal open/close lifecycle
function show() {
  if (!modalInstance && modalRef.value) {
    modalInstance = new bootstrap.Modal(modalRef.value, { backdrop: 'static', keyboard: true });
  }
  loadContext();
  modalInstance?.show();
}

function hide() {
  modalInstance?.hide();
  emit('closed');
}

defineExpose({
  show,
  hide,
  loadContext,
});
</script>

<style scoped>
.extra-small {
  font-size: 0.75rem;
}
.fs-7 {
  font-size: 0.8rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
</style>
