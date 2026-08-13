<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-tools me-2 text-danger"></i>Rework Management Desk</h3>
                <p class="text-muted mb-0">Perform required corrections on defective parts and submit completed work back to QC queue.</p>
              </div>
              <span class="badge bg-danger px-3 py-2 fs-6">Rework Shop</span>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

            <!-- Rework Table -->
            <div class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Side</th>
                    <th>Quantity</th>
                    <th>Cycle #</th>
                    <th>Defect / Instructions</th>
                    <th>Status</th>
                    <th style="width: 220px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in items" :key="item.id">
                    <td>
                      <div class="fw-bold text-dark">{{ item.bom_item?.standard_part_no }}</div>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || 'N/A' }}</span></td>
                    <td><span class="badge bg-primary">{{ item.side }}</span></td>
                    <td><span class="fw-bold fs-6 text-danger">{{ item.quantity }}</span></td>
                    <td><span class="badge bg-secondary">Cycle #{{ item.cycle_number }}</span></td>
                    <td><small class="text-dark">{{ item.rework_description || 'Defect correction' }}</small></td>
                    <td>
                      <span class="badge" :class="getStatusBadge(item.status)">{{ item.status }}</span>
                    </td>
                    <td>
                      <template v-if="authStore.userRole === 'MANAGER'">
                        <span v-if="item.status === 'pending'" class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                          <i class="fas fa-hourglass-start me-1"></i>REWORK PENDING
                        </span>
                        <span v-else-if="item.status === 'in_progress'" class="badge bg-primary border px-3 py-2 w-100 d-block">
                          <i class="fas fa-cogs me-1"></i>REWORK IN PROGRESS
                        </span>
                        <span v-else-if="item.status === 'returned_to_qc'" class="badge bg-success border px-3 py-2 w-100 d-block">
                          <i class="fas fa-check-circle me-1"></i>RETURNED TO QC
                        </span>
                        <span v-else class="badge bg-secondary border px-3 py-2 w-100 d-block">
                          <i class="fas fa-info-circle me-1"></i>{{ item.status?.toUpperCase() || 'REWORK' }}
                        </span>
                      </template>
                      <template v-else>
                        <button v-if="item.status === 'pending'" class="btn btn-sm btn-outline-primary me-1" @click="startRework(item)">
                          <i class="fas fa-play me-1"></i> Start Work
                        </button>
                        <button v-if="item.status === 'in_progress'" class="btn btn-sm btn-success" @click="openCompleteModal(item)">
                          <i class="fas fa-check-circle me-1"></i> Complete & Return to QC
                        </button>
                        <span v-if="item.status === 'returned_to_qc'" class="text-success small fw-bold">Returned to QC</span>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!items.length">
                    <td colspan="8" class="text-center py-5 text-muted">
                      <i class="fas fa-smile fa-3x mb-3 text-success"></i>
                      <p class="mb-0">No active rework items in queue.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Complete Rework Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title fw-bold"><i class="fas fa-check-double me-2"></i>Complete Rework Cycle</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeItem">
            <p>You are marking rework as completed for <strong>{{ activeItem.bom_item?.standard_part_no }}</strong> ({{ activeItem.quantity }} units, {{ activeItem.side }}).</p>
            <div class="mb-3">
              <label class="form-label fw-semibold">Completion Notes / Work Performed</label>
              <textarea v-model="completionNotes" class="form-control" rows="3" placeholder="Describe correction actions taken..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success fw-bold" @click="submitCompletion">
              <i class="fas fa-paper-plane me-1"></i> Submit & Return to QC Queue
            </button>
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
const items = ref([]);
const activeItem = ref(null);
const completionNotes = ref('');
const error = ref('');
const successMessage = ref('');

const loadItems = async () => {
  try {
    const res = await axios.get('/api/v1/rework/items');
    items.value = res.data.data || [];
  } catch (err) {
    error.value = 'Failed to load rework queue.';
  }
};

const startRework = async (item) => {
  try {
    const res = await axios.post(`/api/v1/rework/items/${item.id}/start`);
    successMessage.value = res.data.message;
    loadItems();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to start rework.';
  }
};

const openCompleteModal = (item) => {
  activeItem.value = item;
  completionNotes.value = '';
  const modalEl = document.getElementById('completeModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
};

const submitCompletion = async () => {
  try {
    const res = await axios.post(`/api/v1/rework/items/${activeItem.value.id}/complete`, {
      completion_notes: completionNotes.value,
    });
    successMessage.value = res.data.message;

    const modalEl = document.getElementById('completeModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    loadItems();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to complete rework.';
  }
};

const getStatusBadge = (status) => {
  switch (status) {
    case 'pending': return 'bg-warning text-dark';
    case 'in_progress': return 'bg-primary';
    case 'returned_to_qc': return 'bg-success';
    default: return 'bg-secondary';
  }
};

onMounted(() => {
  loadItems();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
