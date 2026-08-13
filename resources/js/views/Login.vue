<template>
  <div class="login-page bg-body-secondary d-flex align-items-center justify-content-center min-vh-100">
    <div class="login-box width-400">
      <div class="card card-outline card-primary shadow-sm">
        <div class="card-header text-center py-4">
          <h3 class="fw-bold mb-0 text-primary">
            <i class="fas fa-boxes-stacked me-2"></i>SpareTrack
          </h3>
          <p class="text-muted small mb-0 mt-1">Industrial Spare Parts Tracking System</p>
        </div>
        <div class="card-body login-card-body p-4">
          <p class="login-box-msg text-center text-secondary mb-4">Sign in to start your session</p>

          <div v-if="authStore.error" class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
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
                  placeholder="name@company.com"
                  required
                  autocomplete="email"
                />
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label small fw-semibold">Password</label>
              <div class="input-group">
                <input
                  v-model="password"
                  type="password"
                  class="form-control"
                  placeholder="Enter your password"
                  required
                  autocomplete="current-password"
                />
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
              </div>
            </div>

            <div class="row align-items-center">
              <div class="col-12">
                <button
                  type="submit"
                  class="btn btn-primary w-100 py-2 fw-semibold"
                  :disabled="authStore.loading"
                >
                  <span v-if="authStore.loading" class="spinner-border spinner-border-sm me-2"></span>
                  <i v-else class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
              </div>
            </div>
          </form>
        </div>
        <div class="card-footer text-center py-3 bg-light">
          <small class="text-muted">Internal LAN System &bull; Faith Automation</small>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const email = ref('');
const password = ref('');
const router = useRouter();
const authStore = useAuthStore();

const handleLogin = async () => {
  const success = await authStore.login({
    email: email.value,
    password: password.value,
  });

  if (success) {
    router.push({ name: 'dashboard' });
  }
};
</script>

<style scoped>
.width-400 {
  width: 100%;
  max-width: 420px;
}
</style>
