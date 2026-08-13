<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h3 class="fw-bold mb-1"><i class="fas fa-cogs me-2 text-primary"></i>Final Assembly Operations</h3>
              <p class="text-muted mb-0">Paint-completed parts ready for final assembly and project fulfillment.</p>
            </div>
            <span class="badge bg-primary text-white px-3 py-2 fs-6">Assembly Shop</span>
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
                  <th>Painted Qty</th>
                  <th>Paint Type</th>
                  <th style="width: 200px;">{{ authStore.userRole === 'MANAGER' ? 'Status' : 'Action' }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in queue" :key="item.id">
                  <td><strong class="text-dark">{{ item.bom_item?.standard_part_no }}</strong></td>
                  <td><span class="badge bg-light text-dark border">{{ item.bom_item?.project?.project_code || 'N/A' }}</span></td>
                  <td><span class="badge bg-primary">{{ item.side }}</span></td>
                  <td><span class="fw-bold fs-6 text-primary">{{ item.quantity }}</span></td>
                  <td><small class="text-muted">{{ item.paint_type || 'Standard' }}</small></td>
                  <td>
                    <template v-if="authStore.userRole === 'MANAGER'">
                      <span class="badge bg-success border px-3 py-2 w-100 d-block">
                        <i class="fas fa-bolt me-1"></i>READY FOR ASSEMBLY
                      </span>
                    </template>
                    <template v-else>
                      <button class="btn btn-sm btn-primary fw-semibold w-100" @click="submitAssembly(item)">
                        <i class="fas fa-check-double me-1"></i> Mark Assembly Done
                      </button>
                    </template>
                  </td>
                </tr>
                <tr v-if="!queue.length">
                  <td colspan="6" class="text-center py-5 text-muted">
                    <i class="fas fa-cubes fa-3x mb-3 text-primary"></i>
                    <p class="mb-0">No parts in assembly queue. Paint-completed parts will appear here.</p>
                  </td>
                </tr>
              </tbody>
            </table>
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
const queue = ref([]);
const error = ref('');
const successMessage = ref('');

const loadQueue = async () => {
  try {
    const res = await axios.get('/api/v1/assembly/queue');
    queue.value = res.data.data || [];
  } catch (err) {
    error.value = 'Failed to load assembly queue.';
  }
};

const submitAssembly = async (item) => {
  try {
    const res = await axios.post(`/api/v1/assembly/records`, {
      paint_record_id: item.id,
      quantity: item.quantity,
    });
    successMessage.value = res.data.message || 'Assembly completed!';
    loadQueue();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to complete assembly.';
  }
};

onMounted(() => {
  loadQueue();
});
</script>
