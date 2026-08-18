<template>
  <div v-if="authStore.isAuthenticated && route.name !== 'login'" class="d-flex" style="min-height: 100vh; max-width: 100vw; overflow-x: hidden; background-color: #f8fafc;">
    <!-- PERSISTENT & STATIC FIXED LEFT SIDEBAR -->
    <div class="bg-dark text-white p-3 d-flex flex-column shadow" style="position: fixed; top: 0; left: 0; bottom: 0; width: 240px; height: 100vh; z-index: 1030; background-color: #0f172a !important; overflow-y: auto;">
      <!-- Brand Header -->
      <div class="mb-4 pb-3 border-bottom border-secondary text-center">
        <div class="bg-white rounded-3 p-2 shadow-sm d-flex align-items-center justify-content-center" style="height: 52px;">
          <img src="/images/logo.png" alt="FAITH AUTOMATION" style="max-height: 40px; width: auto; max-width: 100%; object-fit: contain;" />
        </div>
      </div>

      <!-- Navigation Links -->
      <ul class="nav nav-pills flex-column mb-auto gap-1">
        <li class="nav-item">
          <router-link class="nav-link text-white" :class="{ 'active bg-primary': route.name === 'dashboard' }" :to="{ name: 'dashboard' }">
            <i class="fas fa-chart-line me-2 text-primary"></i>Dashboard
          </router-link>
        </li>
        <li v-if="['ADMIN', 'MANAGER'].includes(authStore.userRole)" class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'bom-import' }" :to="{ name: 'bom-import' }">
            <i class="fas fa-file-upload me-2 text-info"></i>Import BOM
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'store' }" :to="{ name: 'store' }">
            <i class="fas fa-boxes me-2 text-warning"></i>Store
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'qc' }" :to="{ name: 'qc' }">
            <i class="fas fa-clipboard-check me-2 text-success"></i>QC Queue
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'rework' }" :to="{ name: 'rework' }">
            <i class="fas fa-tools me-2 text-danger"></i>Rework
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'paint' }" :to="{ name: 'paint' }">
            <i class="fas fa-paint-roller me-2 text-primary"></i>Paint
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'assembly' }" :to="{ name: 'assembly' }">
            <i class="fas fa-cogs me-2 text-info"></i>Assembly
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'purchase' }" :to="{ name: 'purchase' }">
            <i class="fas fa-shopping-cart me-2 text-warning"></i>Purchase
          </router-link>
        </li>
        <li class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-primary text-white': route.name === 'suppliers' }" :to="{ name: 'suppliers' }">
            <i class="fas fa-truck me-2 text-secondary"></i>Suppliers
          </router-link>
        </li>
        <li v-if="authStore.userRole === 'ADMIN'" class="nav-item">
          <router-link class="nav-link text-white-50" :class="{ 'active bg-danger text-white': route.name === 'admin-logs' }" :to="{ name: 'admin-logs' }">
            <i class="fas fa-shield-alt me-2 text-danger"></i>System Logs
          </router-link>
        </li>
      </ul>

      <!-- User Profile Card (Positioned directly below Supplier tab) -->
      <div class="pt-3 mt-auto border-top border-secondary">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="fw-bold fs-6 text-white text-truncate" style="max-width: 140px;">
              {{ authStore.user?.name || 'Plant Manager' }}
            </div>
            <span class="badge bg-secondary text-uppercase px-2 py-1" style="font-size: 11px;">
              {{ authStore.userRole }}
            </span>
          </div>
          <button @click="handleLogout" class="btn btn-outline-danger btn-sm p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;" title="Sign Out">
            <i class="fas fa-sign-out-alt fa-lg"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- MAIN SCROLLABLE RIGHT VIEW CONTAINER -->
    <div style="margin-left: 240px; width: calc(100vw - 240px); min-height: 100vh; background-color: #f8fafc; overflow-x: hidden;">
      <router-view />
    </div>
  </div>

  <div v-else>
    <router-view />
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const handleLogout = async () => {
  await authStore.logout();
  router.push({ name: 'login' });
};

onMounted(() => {
  authStore.initAuth();
});
</script>

<style scoped>
.fs-7 {
  font-size: 0.75rem;
}
</style>
