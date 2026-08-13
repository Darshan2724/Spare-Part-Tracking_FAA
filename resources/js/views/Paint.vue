<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-paint-roller me-2 text-info"></i>Paint Shop Operations</h3>
                <p class="text-muted mb-0">QC-approved spare parts awaiting surface coating / painting.</p>
              </div>
              <span class="badge bg-info text-white px-3 py-2 fs-6">Paint Shop</span>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>
            <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

            <!-- Queue Table -->
            <div class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Side</th>
                    <th>Approved Qty</th>
                    <th>Inspection Date</th>
                    <th style="width: 200px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in queue" :key="item.id">
                    <td><strong class="text-dark">{{ item.bom_item?.standard_part_no }}</strong></td>
                    <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || 'N/A' }}</span></td>
                    <td><span class="badge bg-primary">{{ item.side }}</span></td>
                    <td><span class="fw-bold fs-6 text-success">{{ item.approved_quantity }}</span></td>
                    <td><small class="text-muted">{{ formatDate(item.created_at) }}</small></td>
                    <td>
                      <template v-if="authStore.userRole === 'MANAGER'">
                        <span class="badge bg-info text-white border px-3 py-2 w-100 d-block">
                          <i class="fas fa-paint-roller me-1"></i>AWAITING PAINTING
                        </span>
                      </template>
                      <template v-else>
                        <button class="btn btn-sm btn-info text-white fw-semibold w-100" @click="openPaintModal(item)">
                          <i class="fas fa-fill-drip me-1"></i> Log Paint Completion
                        </button>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!queue.length">
                    <td colspan="6" class="text-center py-5 text-muted">
                      <i class="fas fa-paint-brush fa-3x mb-3 text-info"></i>
                      <p class="mb-0">No parts in paint queue. QC-approved parts will appear here.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Paint Completion Modal -->
    <div class="modal fade" id="paintModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-info text-white">
            <h5 class="modal-title fw-bold"><i class="fas fa-paint-roller me-2"></i>Complete Painting</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeItem">
            <p>Recording paint process for <strong>{{ activeItem.bom_item?.standard_part_no }}</strong> ({{ activeItem.approved_quantity }} units, {{ activeItem.side }}).</p>
            <div class="mb-3">
              <label class="form-label fw-semibold">Paint Type / Color Code</label>
              <input type="text" v-model="paintForm.paint_type" class="form-control" placeholder="e.g. RAL 7035 Powder Coat..." />
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Remarks</label>
              <textarea v-model="paintForm.remarks" class="form-control" rows="2" placeholder="Process notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-info text-white fw-bold" @click="submitPaint">
              <i class="fas fa-check me-1"></i> Complete & Push to Assembly
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
const queue = ref([]);
const activeItem = ref(null);
const error = ref('');
const successMessage = ref('');

const paintForm = ref({
  paint_type: 'RAL 7035 Powder Coat',
  remarks: '',
});

const loadQueue = async () => {
  try {
    const res = await axios.get('/api/v1/paint/queue');
    queue.value = res.data.data || [];
  } catch (err) {
    error.value = 'Failed to load paint queue.';
  }
};

const openPaintModal = (item) => {
  activeItem.value = item;
  paintForm.value = {
    paint_type: 'RAL 7035 Powder Coat',
    remarks: '',
  };

  const modalEl = document.getElementById('paintModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
};

const submitPaint = async () => {
  try {
    const payload = {
      bom_item_id: activeItem.value.bom_item_id,
      qc_inspection_id: activeItem.value.id,
      side: activeItem.value.side,
      quantity: activeItem.value.approved_quantity,
      paint_type: paintForm.value.paint_type,
      remarks: paintForm.value.remarks,
    };

    const res = await axios.post('/api/v1/paint/items', payload);
    successMessage.value = res.data.message;

    const modalEl = document.getElementById('paintModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    loadQueue();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to complete painting.';
  }
};

const formatDate = (d) => d ? new Date(d).toLocaleDateString() : '';

onMounted(() => {
  loadQueue();
});
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
