<template>
  <div class="login-page bg-body-secondary d-flex align-items-center justify-content-center min-vh-100 py-4">
    <div class="login-box width-450">
      <div class="card card-outline card-primary shadow-sm border-0">
        <div class="card-header text-center py-4 bg-white border-bottom">
          <div class="d-flex justify-content-center">
            <img src="/images/logo.png" alt="FAITH AUTOMATION" style="height: 56px; width: auto; object-fit: contain; max-width: 260px;" />
          </div>
        </div>
        <div class="card-body login-card-body p-4">
          <p class="login-box-msg text-center text-secondary mb-3">Sign in to start your session</p>

          <div v-if="authStore.error" class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>{{ authStore.error }}
          </div>

          <form @submit.prevent="handleLogin">
            <div class="mb-3">
              <label class="form-label small fw-semibold">Email Address</label>
              <div class="input-group">
                <input
                  v-model="email"
                  type="email"
                  class="form-control"
                  placeholder="name@sparetrack.internal"
                  required
                  autocomplete="email"
                />
                <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
              </div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <label class="form-label small fw-semibold mb-1">Password</label>
                <button 
                  type="button" 
                  class="btn btn-link btn-sm p-0 text-decoration-none extra-small text-muted"
                  @click="showPassword = !showPassword"
                >
                  <i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i> {{ showPassword ? 'Hide' : 'Show' }}
                </button>
              </div>
              <div class="input-group">
                <input
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  class="form-control"
                  placeholder="Enter password"
                  required
                  autocomplete="current-password"
                />
                <button 
                  class="input-group-text bg-light" 
                  type="button"
                  @click="showPassword = !showPassword"
                  title="Toggle password visibility"
                >
                  <i class="fas text-muted" :class="showPassword ? 'fa-eye-slash' : 'fa-lock'"></i>
                </button>
              </div>
            </div>

            <div class="row align-items-center mb-3">
              <div class="col-12">
                <button
                  type="submit"
                  class="btn btn-primary w-100 py-2 fw-semibold shadow-sm"
                  :disabled="authStore.loading"
                >
                  <span v-if="authStore.loading" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
              </div>
            </div>
          </form>

          <!-- Quick 1-Click Role Login for Internal LAN -->
          <div class="mt-4 pt-3 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="extra-small text-uppercase fw-bold text-muted">
                <i class="fas fa-bolt text-warning me-1"></i> Quick Sign-In (Internal LAN)
              </span>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <button 
                  type="button" 
                  class="btn btn-outline-primary btn-sm w-100 text-start d-flex align-items-center gap-2 py-1 px-2"
                  @click="quickLogin('manager@sparetrack.internal', 'password123')"
                >
                  <i class="fas fa-user-tie text-primary fs-7"></i>
                  <div>
                    <div class="fw-bold extra-small lh-1">Plant Manager</div>
                    <div class="text-muted" style="font-size: 0.65rem;">manager@</div>
                  </div>
                </button>
              </div>
              <div class="col-6">
                <button 
                  type="button" 
                  class="btn btn-outline-secondary btn-sm w-100 text-start d-flex align-items-center gap-2 py-1 px-2"
                  @click="quickLogin('admin@sparetrack.internal', 'password123')"
                >
                  <i class="fas fa-user-shield text-dark fs-7"></i>
                  <div>
                    <div class="fw-bold extra-small lh-1">System Admin</div>
                    <div class="text-muted" style="font-size: 0.65rem;">admin@</div>
                  </div>
                </button>
              </div>
              <div class="col-6">
                <button 
                  type="button" 
                  class="btn btn-outline-warning btn-sm w-100 text-start d-flex align-items-center gap-2 py-1 px-2"
                  @click="quickLogin('store@sparetrack.internal', 'password123')"
                >
                  <i class="fas fa-warehouse text-warning fs-7"></i>
                  <div>
                    <div class="fw-bold extra-small lh-1 text-dark">Store Officer</div>
                    <div class="text-muted" style="font-size: 0.65rem;">store@</div>
                  </div>
                </button>
              </div>
              <div class="col-6">
                <button 
                  type="button" 
                  class="btn btn-outline-info btn-sm w-100 text-start d-flex align-items-center gap-2 py-1 px-2"
                  @click="quickLogin('qc@sparetrack.internal', 'password123')"
                >
                  <i class="fas fa-check-double text-info fs-7"></i>
                  <div>
                    <div class="fw-bold extra-small lh-1 text-dark">QC Inspector</div>
                    <div class="text-muted" style="font-size: 0.65rem;">qc@</div>
                  </div>
                </button>
              </div>
            </div>
          </div>

        </div>
        <div class="card-footer text-center py-2 bg-light">
          <small class="text-muted extra-small">Internal LAN System &bull; Faith Automation</small>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const email = ref('manager@sparetrack.internal');
const password = ref('password123');
const showPassword = ref(false);
const router = useRouter();
const authStore = useAuthStore();

const handleLogin = async () => {
  const success = await authStore.login({
    email: (email.value || '').trim(),
    password: password.value,
  });

  if (success) {
    router.push({ name: 'dashboard' });
  }
};

const quickLogin = async (userEmail, userPass) => {
  email.value = userEmail;
  password.value = userPass;
  await handleLogin();
};
</script>

<style scoped>
.width-450 {
  width: 100%;
  max-width: 450px;
}
.extra-small {
  font-size: 0.72rem;
}
.fs-7 {
  font-size: 0.85rem;
}
</style>
