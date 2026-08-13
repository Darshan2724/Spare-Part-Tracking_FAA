<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-clipboard-check me-2 text-warning"></i>Quality Control Desk</h3>
                <p class="text-muted mb-0">Inspect received spare parts, record decisions, log defect photos, and dispatch to Paint or Rework.</p>
              </div>
              <span class="badge bg-warning text-dark px-3 py-2 fs-6">Quality Control</span>
            </div>
          </div>

          <div class="card-body">
            <div v-if="error" class="alert alert-danger alert-dismissible fade show">
              <i class="fas fa-exclamation-triangle me-2"></i>{{ error }}
              <button type="button" class="btn-close" @click="error = ''"></button>
            </div>
            <div v-if="successMessage" class="alert alert-success alert-dismissible fade show">
              <i class="fas fa-check-circle me-2"></i>{{ successMessage }}
              <button type="button" class="btn-close" @click="successMessage = ''"></button>
            </div>

            <!-- Queue Table -->
            <div class="table-responsive">
              <table class="table table-hover align-middle border-top">
                <thead class="table-light">
                  <tr>
                    <th>Part Number</th>
                    <th>Project</th>
                    <th>Supplier</th>
                    <th>Side</th>
                    <th>Received Qty</th>
                    <th>Arrival Date</th>
                    <th style="width: 180px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in queue" :key="item.id">
                    <td>
                      <div class="fw-bold text-dark">{{ item.bom_item?.standard_part_no }}</div>
                      <small class="text-muted" v-if="item.bom_item?.size">Size: {{ item.bom_item.size }}</small>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || 'N/A' }}</span></td>
                    <td>{{ item.bom_item?.supplier?.name || item.bom_item?.supplier_name_raw || 'Standard' }}</td>
                    <td>
                      <span class="badge" :class="item.side === 'RH' ? 'bg-primary' : (item.side === 'LH' ? 'bg-info' : 'bg-secondary')">{{ item.side }}</span>
                    </td>
                    <td><span class="fw-bold fs-6 text-primary">{{ item.received_quantity }}</span></td>
                    <td><small class="text-muted">{{ formatDate(item.created_at) }}</small></td>
                    <td>
                      <template v-if="authStore.userRole === 'MANAGER'">
                        <span class="badge bg-warning text-dark border px-3 py-2 w-100 d-block">
                          <i class="fas fa-microscope me-1"></i>PENDING INSPECTION
                        </span>
                      </template>
                      <template v-else>
                        <button class="btn btn-sm btn-warning text-dark fw-semibold w-100" @click="openInspectModal(item)">
                          <i class="fas fa-search me-1"></i> Inspect Part
                        </button>
                      </template>
                    </td>
                  </tr>
                  <tr v-if="!queue.length">
                    <td colspan="7" class="text-center py-5 text-muted">
                      <i class="fas fa-check-double fa-3x mb-3 text-success"></i>
                      <p class="mb-0">QC Queue is empty! No received parts awaiting inspection.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- QC Inspection Modal -->
    <div class="modal fade" id="qcModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-warning text-dark">
            <h5 class="modal-title fw-bold"><i class="fas fa-microscope me-2"></i>Record Quality Inspection</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" v-if="activeItem">
            <div class="alert alert-light border mb-3">
              <div class="row">
                <div class="col-6"><strong>Part Number:</strong> {{ activeItem.bom_item?.standard_part_no }}</div>
                <div class="col-6"><strong>Side:</strong> {{ activeItem.side }}</div>
                <div class="col-6"><strong>Project:</strong> {{ activeItem.bom_item?.project?.name }}</div>
                <div class="col-6"><strong>Quantity Arrived:</strong> {{ activeItem.received_quantity }}</div>
              </div>
            </div>

            <!-- Decision Radio Buttons -->
            <div class="mb-4">
              <label class="form-label fw-bold">Inspection Result</label>
              <div class="d-flex gap-3">
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resApproved" value="approved" v-model="inspectForm.result">
                  <label class="form-check-label text-success fw-bold me-2" for="resApproved">
                    <i class="fas fa-check-circle me-1"></i> Approve
                  </label>
                </div>
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resRework" value="rework" v-model="inspectForm.result">
                  <label class="form-check-label text-warning text-dark fw-bold me-2" for="resRework">
                    <i class="fas fa-tools me-1"></i> Rework
                  </label>
                </div>
                <div class="form-check form-check-inline border rounded p-2 flex-fill bg-light">
                  <input class="form-check-input" type="radio" id="resRejected" value="rejected" v-model="inspectForm.result">
                  <label class="form-check-label text-danger fw-bold me-2" for="resRejected">
                    <i class="fas fa-times-circle me-1"></i> Reject
                  </label>
                </div>
              </div>
            </div>

            <!-- Reason Fields -->
            <div class="mb-3" v-if="inspectForm.result === 'rejected'">
              <label class="form-label fw-semibold text-danger">Rejection Reason</label>
              <input type="text" v-model="inspectForm.rejection_reason" class="form-control border-danger" placeholder="e.g. Out of tolerance, crack detected, wrong material..." />
              <small class="text-danger">Note: Rejected items will be automatically placed into the Purchase Queue for reordering.</small>
            </div>

            <div class="mb-3" v-if="inspectForm.result === 'rework'">
              <label class="form-label fw-semibold text-warning text-dark">Rework Instructions</label>
              <input type="text" v-model="inspectForm.rework_reason" class="form-control border-warning" placeholder="e.g. Minor burr removal, re-thread hole, deburring..." />
              <small class="text-muted">Note: Reworked items will automatically return to QC queue upon completion.</small>
            </div>

            <!-- Upload Photo Attachment -->
            <div class="mb-3">
              <label class="form-label fw-semibold"><i class="fas fa-camera me-1 text-primary"></i>Attach Photo / Defect Document (Optional)</label>
              <input type="file" @change="handleFileChange" accept="image/*,.pdf" class="form-control" />
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">QC Remarks</label>
              <textarea v-model="inspectForm.remarks" class="form-control" rows="2" placeholder="Inspection notes..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-warning text-dark fw-bold" @click="submitInspection" :disabled="submitting">
              <i class="fas fa-save me-1"></i> Save Inspection & Dispatch
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
const submitting = ref(false);
const selectedFile = ref(null);

const inspectForm = ref({
  result: 'approved',
  rejection_reason: '',
  rework_reason: '',
  remarks: '',
});

const loadQueue = async () => {
  try {
    const response = await axios.get('/api/v1/qc/queue');
    queue.value = response.data.data || [];
  } catch (err) {
    error.value = 'Unable to load QC queue.';
  }
};

const openInspectModal = (item) => {
  activeItem.value = item;
  inspectForm.value = {
    result: 'approved',
    rejection_reason: '',
    rework_reason: '',
    remarks: '',
  };
  selectedFile.value = null;

  const modalEl = document.getElementById('qcModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
};

const handleFileChange = (event) => {
  const file = event.target.files[0];
  if (file) selectedFile.value = file;
};

const submitInspection = async () => {
  submitting.value = true;
  try {
    const formData = new FormData();
    formData.append('receipt_item_id', activeItem.value.id);
    formData.append('side', activeItem.value.side);
    formData.append('inspected_quantity', activeItem.value.received_quantity);
    formData.append('result', inspectForm.value.result);
    formData.append('rejection_reason', inspectForm.value.rejection_reason);
    formData.append('rework_reason', inspectForm.value.rework_reason);
    formData.append('remarks', inspectForm.value.remarks);

    if (selectedFile.value) {
      formData.append('photo', selectedFile.value);
    }

    const response = await axios.post('/api/v1/qc/inspect', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    successMessage.value = response.data.message || 'QC Inspection completed.';
    
    // Hide modal
    const modalEl = document.getElementById('qcModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    loadQueue();
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to record QC inspection.';
  } finally {
    submitting.value = false;
  }
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString() + ' ' + new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

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
