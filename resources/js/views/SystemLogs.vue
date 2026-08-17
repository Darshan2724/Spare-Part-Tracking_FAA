<template>
  <div class="p-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      <!-- Header Topbar -->
      <div class="py-3 px-4 bg-white border-bottom shadow-sm rounded mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h4 class="mb-0 fw-bold text-dark d-flex align-items-center">
            <i class="fas fa-shield-alt text-danger me-2"></i>Admin System & Audit Logs Center
          </h4>
          <small class="text-muted">Centralized monitoring for Application, API, Database, Authentication, and Workflow Diagnostics</small>
        </div>
        <div class="d-flex gap-2">
          <button @click="fetchLogs" class="btn btn-outline-primary btn-sm text-nowrap" :disabled="loading">
            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loading }"></i> Refresh Logs
          </button>
        </div>
      </div>

      <!-- Log Dashboard Summary Cards (Part 14) -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-danger text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Errors Today</div>
                <h2 class="fw-bold mb-0">{{ dashboard.summary?.errors_today || 0 }}</h2>
                <small class="text-white-50">Critical / Runtime Failures</small>
              </div>
              <i class="fas fa-exclamation-circle fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-warning text-dark h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-dark-50 text-uppercase fw-bold small">Warnings Today</div>
                <h2 class="fw-bold mb-0">{{ dashboard.summary?.warnings_today || 0 }}</h2>
                <small class="text-dark-50">AuthZ & Rate Limits</small>
              </div>
              <i class="fas fa-exclamation-triangle fa-2x text-dark-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-primary text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Failed API Requests</div>
                <h2 class="fw-bold mb-0">{{ dashboard.summary?.failed_api_requests || 0 }}</h2>
                <small class="text-white-50">HTTP 4xx / 5xx Responses</small>
              </div>
              <i class="fas fa-network-wired fa-2x text-white-50"></i>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm bg-dark text-white h-100">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
              <div>
                <div class="text-white-50 text-uppercase fw-bold small">Database / Auth Fails</div>
                <h2 class="fw-bold mb-0">{{ (dashboard.summary?.database_errors || 0) + (dashboard.summary?.authentication_failures || 0) }}</h2>
                <small class="text-white-50">Security & Storage Events</small>
              </div>
              <i class="fas fa-database fa-2x text-white-50"></i>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters Toolbar -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 bg-light rounded">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
              <label class="form-label small fw-bold mb-1"><i class="fas fa-search me-1 text-primary"></i> Search</label>
              <input type="text" v-model="filters.search" @input="debounceSearch" placeholder="Search message, endpoint, trace ID..." class="form-control form-control-sm" />
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Severity</label>
              <select v-model="filters.severity" @change="fetchLogs" class="form-select form-select-sm">
                <option value="">All Severities</option>
                <option value="CRITICAL">🔴 CRITICAL</option>
                <option value="ERROR">🟠 ERROR</option>
                <option value="WARNING">🟡 WARNING</option>
                <option value="INFO">🔵 INFO</option>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Category</label>
              <select v-model="filters.category" @change="fetchLogs" class="form-select form-select-sm">
                <option value="">All Categories</option>
                <option value="application_errors">Application Errors</option>
                <option value="api_errors">API Errors</option>
                <option value="database_errors">Database Errors</option>
                <option value="authentication_logs">Authentication Logs</option>
                <option value="authorization_logs">Authorization Logs</option>
                <option value="workflow_errors">Workflow Errors</option>
                <option value="realtime_logs">Real-time / WebSocket</option>
                <option value="system_health_logs">System Health</option>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label small fw-bold mb-1">Status</label>
              <select v-model="filters.status" @change="fetchLogs" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="new">🆕 New</option>
                <option value="reviewed">👀 Reviewed</option>
                <option value="resolved">✅ Resolved</option>
              </select>
            </div>

            <div class="col-6 col-md-3 d-flex align-items-end gap-2">
              <button @click="resetFilters" class="btn btn-outline-secondary btn-sm w-100 mt-4">
                <i class="fas fa-undo me-1"></i> Reset Filters
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Logs Table -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <h5 class="fw-bold mb-0 text-dark">
            <i class="fas fa-list text-primary me-2"></i>Recorded System Events ({{ pagination.total || 0 }} Events)
          </h5>
          <span class="badge bg-secondary">Page {{ pagination.current_page || 1 }} of {{ pagination.last_page || 1 }}</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0 small">
              <thead class="table-dark">
                <tr>
                  <th style="width: 130px;">TIMESTAMP</th>
                  <th style="width: 90px;">SEVERITY</th>
                  <th style="width: 140px;">CATEGORY</th>
                  <th style="width: 90px;">MODULE</th>
                  <th style="width: 130px;">USER / ROLE</th>
                  <th>SUMMARY / ERROR MESSAGE</th>
                  <th style="width: 90px;">STATUS</th>
                  <th style="width: 100px;" class="text-center">ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="log in logs" :key="log.id">
                  <td class="text-muted extra-small">
                    <div>{{ formatDate(log.created_at) }}</div>
                    <small class="text-monospace text-muted">{{ log.trace_id?.slice(0, 8) }}...</small>
                  </td>
                  <td>
                    <span class="badge px-2 py-1" :class="getSeverityBadgeClass(log.severity)">
                      {{ log.severity }}
                    </span>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark border">
                      {{ formatCategory(log.category) }}
                    </span>
                  </td>
                  <td>
                    <span class="badge bg-secondary">
                      {{ log.module || 'SYSTEM' }}
                    </span>
                  </td>
                  <td>
                    <div class="fw-bold text-dark text-truncate" style="max-width: 120px;" :title="log.user?.name || 'System'">
                      {{ log.user?.name || 'System / Guest' }}
                    </div>
                    <small class="badge bg-info-subtle text-dark extra-small">{{ log.user_role || 'SYSTEM' }}</small>
                  </td>
                  <td>
                    <div class="fw-semibold text-dark text-truncate" style="max-width: 450px;" :title="log.message">
                      {{ log.message }}
                    </div>
                    <small class="text-muted d-block" v-if="log.endpoint">
                      <code>{{ log.method }} /{{ log.endpoint }}</code>
                      <span v-if="log.status_code" class="ms-1 badge bg-light text-dark border">HTTP {{ log.status_code }}</span>
                    </small>
                  </td>
                  <td>
                    <span class="badge" :class="getStatusBadgeClass(log.status)">
                      {{ log.status?.toUpperCase() }}
                    </span>
                  </td>
                  <td class="text-center">
                    <button @click="openLogDetail(log)" class="btn btn-xs btn-outline-primary" title="View Technical Detail">
                      <i class="fas fa-eye me-1"></i>Details
                    </button>
                  </td>
                </tr>
                <tr v-if="!logs.length && !loading">
                  <td colspan="8" class="text-center py-4 text-muted">
                    <i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>
                    No matching system logs found.
                  </td>
                </tr>
                <tr v-if="loading">
                  <td colspan="8" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin fa-2x text-primary d-block mb-2"></i>
                    Loading system logs...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination Footer -->
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center" v-if="pagination.last_page > 1">
          <div class="small text-muted">
            Showing {{ logs.length }} of {{ pagination.total }} entries
          </div>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">
              <i class="fas fa-chevron-left"></i> Previous
            </button>
            <button class="btn btn-outline-secondary" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">
              Next <i class="fas fa-chevron-right"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Log Detail Modal (Safe Technical Diagnostics) -->
      <div v-if="selectedLog" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.6);" @click.self="selectedLog = null">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white py-3">
              <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                <i class="fas fa-file-medical-alt text-warning"></i>Log Event Detail #{{ selectedLog.id }}
                <span class="badge ms-2" :class="getSeverityBadgeClass(selectedLog.severity)">{{ selectedLog.severity }}</span>
              </h5>
              <button type="button" class="btn-close btn-close-white" @click="selectedLog = null"></button>
            </div>
            <div class="modal-body p-4">
              <!-- Meta Summary Grid -->
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <div class="p-2.5 bg-light rounded border">
                    <small class="text-muted d-block">Timestamp</small>
                    <strong>{{ selectedLog.created_at }}</strong>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="p-2.5 bg-light rounded border">
                    <small class="text-muted d-block">Trace / Request ID</small>
                    <code class="text-primary">{{ selectedLog.trace_id || 'N/A' }}</code>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="p-2.5 bg-light rounded border">
                    <small class="text-muted d-block">Category & Module</small>
                    <strong>{{ formatCategory(selectedLog.category) }} ({{ selectedLog.module || 'SYSTEM' }})</strong>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="p-2.5 bg-light rounded border">
                    <small class="text-muted d-block">User & Role</small>
                    <strong>{{ selectedLog.user?.name || 'Unauthenticated' }} (Role: {{ selectedLog.user_role || 'GUEST' }})</strong>
                  </div>
                </div>
                <div class="col-12" v-if="selectedLog.endpoint">
                  <div class="p-2.5 bg-light rounded border">
                    <small class="text-muted d-block">Endpoint & HTTP Status</small>
                    <strong>{{ selectedLog.method }} /{{ selectedLog.endpoint }}</strong>
                    <span class="badge bg-secondary ms-2">HTTP {{ selectedLog.status_code || 200 }}</span>
                  </div>
                </div>
              </div>

              <!-- Message -->
              <div class="mb-3">
                <label class="form-label fw-bold small text-muted text-uppercase">Error / Event Summary</label>
                <div class="p-3 bg-danger-subtle text-danger-emphasis rounded border border-danger-subtle font-monospace small">
                  {{ selectedLog.message }}
                </div>
              </div>

              <!-- Safe Technical Details / Stack Trace -->
              <div class="mb-3" v-if="selectedLog.details">
                <label class="form-label fw-bold small text-muted text-uppercase">Sanitized Technical Diagnostics</label>
                <pre class="p-3 bg-dark text-light rounded small" style="max-height: 250px; overflow-y: auto;"><code>{{ JSON.stringify(selectedLog.details, null, 2) }}</code></pre>
              </div>

              <!-- Resolution Section -->
              <div class="p-3 bg-light rounded border">
                <label class="form-label fw-bold small text-muted text-uppercase mb-2">Resolution Workflow</label>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <button @click="updateLogStatus('reviewed')" class="btn btn-sm btn-outline-warning" :disabled="selectedLog.status === 'reviewed'">
                    <i class="fas fa-check me-1"></i>Mark as Reviewed
                  </button>
                  <button @click="updateLogStatus('resolved')" class="btn btn-sm btn-success" :disabled="selectedLog.status === 'resolved'">
                    <i class="fas fa-check-double me-1"></i>Mark as Resolved
                  </button>
                  <button @click="updateLogStatus('new')" class="btn btn-sm btn-outline-secondary" v-if="selectedLog.status !== 'new'">
                    Reopen
                  </button>
                  <span class="small text-muted ms-auto" v-if="selectedLog.resolver">
                    Resolved by <strong>{{ selectedLog.resolver.name }}</strong> on {{ selectedLog.resolved_at }}
                  </span>
                </div>
              </div>
            </div>
            <div class="modal-footer py-2">
              <button type="button" class="btn btn-secondary btn-sm" @click="selectedLog = null">Close</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';

const logs = ref([]);
const dashboard = ref({ summary: {} });
const loading = ref(false);
const selectedLog = ref(null);

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  total: 0,
});

const filters = reactive({
  search: '',
  severity: '',
  category: '',
  status: '',
  page: 1,
});

let searchTimeout = null;

const fetchLogs = async () => {
  loading.value = true;
  try {
    const params = {
      search: filters.search || undefined,
      severity: filters.severity || undefined,
      category: filters.category || undefined,
      status: filters.status || undefined,
      page: filters.page || 1,
    };

    const [logsRes, dashRes] = await Promise.all([
      axios.get('/api/v1/admin/logs', { params }),
      axios.get('/api/v1/admin/logs/dashboard'),
    ]);

    logs.value = logsRes.data.data || [];
    pagination.current_page = logsRes.data.current_page;
    pagination.last_page = logsRes.data.last_page;
    pagination.total = logsRes.data.total;

    dashboard.value = dashRes.data || { summary: {} };
  } catch (err) {
    console.error('Failed to load system logs:', err);
  } finally {
    loading.value = false;
  }
};

const debounceSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    filters.page = 1;
    fetchLogs();
  }, 350);
};

const resetFilters = () => {
  filters.search = '';
  filters.severity = '';
  filters.category = '';
  filters.status = '';
  filters.page = 1;
  fetchLogs();
};

const changePage = (page) => {
  filters.page = page;
  fetchLogs();
};

const openLogDetail = (log) => {
  selectedLog.value = log;
};

const updateLogStatus = async (newStatus) => {
  if (!selectedLog.value) return;
  try {
    const res = await axios.patch(`/api/v1/admin/logs/${selectedLog.value.id}/status`, {
      status: newStatus,
    });
    if (res.data.success) {
      selectedLog.value.status = newStatus;
      fetchLogs();
    }
  } catch (err) {
    alert('Failed to update log status: ' + (err.response?.data?.message || err.message));
  }
};

const getSeverityBadgeClass = (severity) => {
  switch (severity) {
    case 'CRITICAL': return 'bg-danger text-white';
    case 'ERROR': return 'bg-danger-subtle text-danger border border-danger';
    case 'WARNING': return 'bg-warning text-dark';
    case 'INFO': return 'bg-info text-dark';
    default: return 'bg-secondary text-white';
  }
};

const getStatusBadgeClass = (status) => {
  switch (status) {
    case 'new': return 'bg-danger text-white';
    case 'reviewed': return 'bg-warning text-dark';
    case 'resolved': return 'bg-success text-white';
    default: return 'bg-secondary text-white';
  }
};

const formatCategory = (category) => {
  if (!category) return 'Application';
  return category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const formatDate = (dateStr) => {
  if (!dateStr) return 'N/A';
  const d = new Date(dateStr);
  return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

onMounted(() => {
  fetchLogs();
});
</script>

<style scoped>
.extra-small {
  font-size: 0.75rem;
}
.fs-7 {
  font-size: 0.8rem;
}
</style>
