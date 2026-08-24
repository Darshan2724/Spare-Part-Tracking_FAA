<template>
  <div v-if="authStore.isAuthenticated && route.name !== 'login'" class="d-flex" style="min-height: 100vh; max-width: 100vw; overflow-x: hidden; background-color: #f8fafc;">
    <!-- PERSISTENT & STATIC FIXED LEFT SIDEBAR (COLLAPSIBLE) -->
    <aside 
      class="sidebar-container bg-dark text-white d-flex flex-column shadow" 
      :class="isSidebarCollapsed ? 'sidebar-collapsed' : 'sidebar-expanded'"
      aria-label="Sidebar Navigation"
    >
      <!-- Brand Header with Collapse Toggle -->
      <div class="sidebar-header border-bottom border-secondary border-opacity-25" :class="isSidebarCollapsed ? 'header-collapsed' : 'header-expanded'">
        <!-- Expanded Brand Header -->
        <div v-if="!isSidebarCollapsed" class="d-flex align-items-center justify-content-between gap-2 w-100">
          <div class="brand-logo-card bg-white rounded-3 p-1.5 shadow-sm d-flex align-items-center justify-content-center flex-grow-1" style="height: 46px;">
            <img src="/images/logo.png" alt="FAITH AUTOMATION" class="brand-logo-img" />
          </div>
          <button 
            @click="toggleSidebar" 
            class="sidebar-toggle-btn rounded-circle d-flex align-items-center justify-content-center"
            title="Collapse Sidebar"
            aria-label="Collapse Sidebar"
          >
            <i class="fas fa-chevron-left"></i>
          </button>
        </div>

        <!-- Collapsed Brand Header (Using LOGO_APP.png) -->
        <div v-else class="d-flex flex-column align-items-center gap-2 w-100">
          <div class="brand-mark-card bg-white rounded-2 shadow-sm d-flex align-items-center justify-content-center p-1" title="Faith Automation">
            <img src="/images/LOGO_APP.png" alt="Faith Automation" class="brand-mark-app-img" />
          </div>
          <button 
            @click="toggleSidebar" 
            class="sidebar-toggle-btn rounded-circle d-flex align-items-center justify-content-center"
            title="Expand Sidebar"
            aria-label="Expand Sidebar"
          >
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>

      <!-- Navigation Links -->
      <nav class="sidebar-nav flex-grow-1 py-2" :class="isSidebarCollapsed ? 'px-2' : 'px-3'">
        <ul class="nav flex-column mb-auto">
          <!-- 1. Dashboard -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'dashboard' }" 
              :to="{ name: 'dashboard' }"
              :aria-label="isSidebarCollapsed ? 'Dashboard' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-chart-line"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Dashboard</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Dashboard</span>
            </router-link>
          </li>

          <!-- 2. Import BOM (Admin/Manager) -->
          <li v-if="['ADMIN', 'MANAGER'].includes(authStore.userRole)" class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'bom-import' }" 
              :to="{ name: 'bom-import' }"
              :aria-label="isSidebarCollapsed ? 'Import BOM' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-file-upload"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Import BOM</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Import BOM</span>
            </router-link>
          </li>

          <!-- 3. Reports (Directly below Import BOM) -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'reports' }" 
              :to="{ name: 'reports' }"
              :aria-label="isSidebarCollapsed ? 'Reports' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-file-invoice"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Reports</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Reports</span>
            </router-link>
          </li>

          <!-- 4. Store -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'store' }" 
              :to="{ name: 'store' }"
              :aria-label="isSidebarCollapsed ? 'Store' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-boxes"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Store</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Store</span>
            </router-link>
          </li>

          <!-- 5. QC Queue -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'qc' }" 
              :to="{ name: 'qc' }"
              :aria-label="isSidebarCollapsed ? 'QC Queue' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-clipboard-check"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">QC Queue</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">QC Queue</span>
            </router-link>
          </li>

          <!-- 6. Rework -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'rework' }" 
              :to="{ name: 'rework' }"
              :aria-label="isSidebarCollapsed ? 'Rework' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-tools"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Rework</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Rework</span>
            </router-link>
          </li>

          <!-- 7. Paint -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'paint' }" 
              :to="{ name: 'paint' }"
              :aria-label="isSidebarCollapsed ? 'Paint' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-paint-roller"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Paint</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Paint</span>
            </router-link>
          </li>

          <!-- 8. Assembly -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'assembly' }" 
              :to="{ name: 'assembly' }"
              :aria-label="isSidebarCollapsed ? 'Assembly' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-cogs"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Assembly</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Assembly</span>
            </router-link>
          </li>

          <!-- 9. Purchase -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'purchase' }" 
              :to="{ name: 'purchase' }"
              :aria-label="isSidebarCollapsed ? 'Purchase' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-shopping-cart"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Purchase</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Purchase</span>
            </router-link>
          </li>

          <!-- 10. Suppliers -->
          <li class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active': route.name === 'suppliers' }" 
              :to="{ name: 'suppliers' }"
              :aria-label="isSidebarCollapsed ? 'Suppliers' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-truck"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">Suppliers</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">Suppliers</span>
            </router-link>
          </li>

          <!-- 11. System Logs (Admin Only) -->
          <li v-if="authStore.userRole === 'ADMIN'" class="nav-item">
            <router-link 
              class="sidebar-nav-link" 
              :class="{ 'active admin-active': route.name === 'admin-logs' }" 
              :to="{ name: 'admin-logs' }"
              :aria-label="isSidebarCollapsed ? 'System Logs' : undefined"
            >
              <span class="nav-icon-wrap">
                <i class="fas fa-shield-alt"></i>
              </span>
              <span v-if="!isSidebarCollapsed" class="nav-label">System Logs</span>
              <span v-if="isSidebarCollapsed" class="sidebar-tooltip">System Logs</span>
            </router-link>
          </li>
        </ul>
      </nav>

      <!-- User Profile & Footer Actions -->
      <div class="sidebar-footer border-top border-secondary border-opacity-25" :class="isSidebarCollapsed ? 'footer-collapsed' : 'footer-expanded'">
        <!-- Expanded Footer -->
        <div v-if="!isSidebarCollapsed" class="d-flex align-items-center justify-content-between w-100" style="gap: 8px;">
          <div class="d-flex align-items-center" style="gap: 10px; min-width: 0; flex: 1;">
            <div class="user-avatar-circle d-flex align-items-center justify-content-center flex-shrink-0">
              <i class="fas fa-user-tie"></i>
            </div>
            <div class="d-flex flex-column justify-content-center" style="min-width: 0; flex: 1;">
              <div class="user-name-text text-truncate" :title="authStore.user?.name || 'Plant Manager'">
                {{ authStore.user?.name || 'Plant Manager' }}
              </div>
              <div class="d-flex align-items-center" style="margin-top: 3px;">
                <span class="user-role-badge">
                  {{ authStore.userRole || 'MANAGER' }}
                </span>
              </div>
            </div>
          </div>
          <button 
            @click="handleLogout" 
            class="logout-btn d-flex align-items-center justify-content-center flex-shrink-0" 
            title="Sign Out"
            aria-label="Sign Out"
          >
            <i class="fas fa-sign-out-alt"></i>
          </button>
        </div>

        <!-- Collapsed Footer -->
        <div v-else class="d-flex flex-column align-items-center gap-2 w-100">
          <div class="user-avatar-btn position-relative" :aria-label="`${authStore.user?.name || 'Plant Manager'} (${authStore.userRole})`">
            <div class="user-avatar-circle d-flex align-items-center justify-content-center">
              <i class="fas fa-user-tie"></i>
            </div>
            <span class="sidebar-tooltip">{{ authStore.user?.name || 'Plant Manager' }} ({{ authStore.userRole }})</span>
          </div>

          <button 
            @click="handleLogout" 
            class="logout-btn collapsed position-relative d-flex align-items-center justify-content-center" 
            aria-label="Sign Out"
          >
            <i class="fas fa-sign-out-alt"></i>
            <span class="sidebar-tooltip">Sign Out</span>
          </button>
        </div>
      </div>
    </aside>

    <!-- MAIN SCROLLABLE RIGHT VIEW CONTAINER (DYNAMICALLY RESIZING) -->
    <main 
      class="main-content-wrapper" 
      :class="isSidebarCollapsed ? 'main-collapsed' : 'main-expanded'"
    >
      <router-view />
    </main>
  </div>

  <div v-else>
    <router-view />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

// Collapsible sidebar state with localStorage persistence
const isSidebarCollapsed = ref(localStorage.getItem('sparetrack_sidebar_collapsed') === 'true');

const toggleSidebar = () => {
  isSidebarCollapsed.value = !isSidebarCollapsed.value;
  localStorage.setItem('sparetrack_sidebar_collapsed', isSidebarCollapsed.value ? 'true' : 'false');
  // Dispatch resize event after transition completes so Chart.js canvases and responsive grids recalculate smoothly
  setTimeout(() => {
    window.dispatchEvent(new Event('resize'));
  }, 260);
};

const handleLogout = async () => {
  await authStore.logout();
  router.push({ name: 'login' });
};

onMounted(() => {
  authStore.initAuth();
});
</script>

<style scoped>
/* =========================================================================
   SIDEBAR CONTAINER & ANIMATIONS
   ========================================================================= */
.sidebar-container {
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  height: 100vh;
  z-index: 1030;
  background-color: #0f172a !important;
  transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  overflow-x: hidden !important;
  overflow-y: auto;
  user-select: none;
  scrollbar-width: thin;
}

/* Slim professional custom scrollbar */
.sidebar-container::-webkit-scrollbar {
  width: 4px;
}
.sidebar-container::-webkit-scrollbar-track {
  background: transparent;
}
.sidebar-container::-webkit-scrollbar-thumb {
  background: #334155;
  border-radius: 4px;
}
.sidebar-container::-webkit-scrollbar-thumb:hover {
  background: #475569;
}

.sidebar-expanded {
  width: 240px;
}

.sidebar-collapsed {
  width: 72px;
}

/* =========================================================================
   SIDEBAR HEADER & BRANDING
   ========================================================================= */
.sidebar-header {
  transition: padding 0.25s ease;
  flex-shrink: 0;
  overflow: hidden !important;
}

.sidebar-header::-webkit-scrollbar {
  display: none !important;
}

.header-expanded {
  padding: 16px 14px;
}

.header-collapsed {
  padding: 14px 10px 10px 10px;
}

.brand-logo-card {
  height: 44px;
  overflow: hidden !important;
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px;
  background: #ffffff;
  padding: 4px 8px;
}

.brand-logo-img {
  max-height: 32px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  display: block;
}

.brand-mark-card {
  width: 40px;
  height: 40px;
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px;
  overflow: hidden !important;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
}

.brand-mark-app-img {
  max-width: 32px;
  max-height: 32px;
  width: auto;
  height: auto;
  object-fit: contain;
  display: block;
}

/* Collapse / Expand Toggle Button */
.sidebar-toggle-btn {
  width: 30px;
  height: 30px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background-color: rgba(255, 255, 255, 0.06);
  color: #94a3b8;
  font-size: 0.75rem;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  cursor: pointer;
  flex-shrink: 0;
}

.sidebar-toggle-btn:hover {
  background-color: rgba(37, 99, 235, 0.2);
  border-color: rgba(37, 99, 235, 0.4);
  color: #ffffff;
  transform: scale(1.05);
}

.sidebar-toggle-btn:focus-visible {
  outline: 2px solid #3b82f4;
  outline-offset: 2px;
}

/* =========================================================================
   NAVIGATION LINKS & ICONS
   ========================================================================= */
.sidebar-nav {
  overflow-y: auto;
  overflow-x: hidden;
  scrollbar-width: none;
}

.sidebar-nav::-webkit-scrollbar {
  display: none;
}

.sidebar-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  height: 42px;
  border-radius: 8px;
  color: #94a3b8;
  text-decoration: none;
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  margin-bottom: 4px;
}

.sidebar-expanded .sidebar-nav-link {
  padding: 0 12px;
  gap: 12px;
}

.sidebar-collapsed .sidebar-nav-link {
  justify-content: center;
  padding: 0;
  width: 44px;
  margin-left: auto;
  margin-right: auto;
}

.nav-icon-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  font-size: 1.05rem;
  flex-shrink: 0;
  color: #94a3b8;
  transition: color 0.18s ease, transform 0.18s ease, filter 0.18s ease;
}

.nav-label {
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  letter-spacing: 0.01em;
  color: inherit;
}

/* Hover State */
.sidebar-nav-link:hover:not(.active) {
  background-color: rgba(255, 255, 255, 0.07);
  color: #f1f5f9;
}

.sidebar-nav-link:hover:not(.active) .nav-icon-wrap {
  color: #ffffff;
  transform: scale(1.08);
}

/* =========================================================================
   EXPANDED ACTIVE STATE (Clean Flat Professional Pill - No Glow)
   ========================================================================= */
.sidebar-expanded .sidebar-nav-link.active {
  background-color: #2563eb;
  color: #ffffff !important;
  box-shadow: none !important;
}

.sidebar-expanded .sidebar-nav-link.active .nav-icon-wrap {
  color: #ffffff !important;
  filter: none !important;
}

.sidebar-expanded .sidebar-nav-link.active .nav-label {
  font-weight: 600;
  color: #ffffff !important;
  text-shadow: none !important;
}

.sidebar-expanded .sidebar-nav-link.active.admin-active {
  background-color: #dc2626;
  box-shadow: none !important;
}

/* =========================================================================
   COLLAPSED ACTIVE STATE (Clean Flat Icon + Solid Left Indicator - No Glow)
   ========================================================================= */
.sidebar-collapsed .sidebar-nav-link.active {
  background: transparent !important;
  background-color: transparent !important;
  box-shadow: none !important;
  border: none !important;
  color: #3b82f4 !important;
}

.sidebar-collapsed .sidebar-nav-link.active .nav-icon-wrap {
  color: #3b82f4 !important;
  transform: scale(1.1);
  filter: none !important;
}

.sidebar-collapsed .sidebar-nav-link.active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 8px;
  bottom: 8px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background-color: #3b82f4;
  box-shadow: none !important;
}

.sidebar-collapsed .sidebar-nav-link.active.admin-active {
  color: #ef4444 !important;
}

.sidebar-collapsed .sidebar-nav-link.active.admin-active .nav-icon-wrap {
  color: #ef4444 !important;
  filter: none !important;
}

.sidebar-collapsed .sidebar-nav-link.active.admin-active::before {
  background-color: #ef4444;
  box-shadow: none !important;
}

.sidebar-nav-link:focus-visible {
  outline: 2px solid #3b82f4;
  outline-offset: 2px;
}

/* =========================================================================
   ENTERPRISE FLOATING TOOLTIP (COLLAPSED STATE)
   ========================================================================= */
.sidebar-tooltip {
  position: absolute;
  left: calc(100% + 14px);
  top: 50%;
  transform: translateY(-50%) translateX(-6px);
  background-color: #0f172a;
  color: #f8fafc;
  font-size: 0.78rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid #334155;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.15s ease-out, transform 0.15s ease-out, visibility 0.15s;
  z-index: 1090;
}

/* Tooltip caret arrow */
.sidebar-tooltip::before {
  content: '';
  position: absolute;
  right: 100%;
  top: 50%;
  transform: translateY(-50%);
  border-width: 5px;
  border-style: solid;
  border-color: transparent #334155 transparent transparent;
}

.sidebar-tooltip::after {
  content: '';
  position: absolute;
  right: 100%;
  top: 50%;
  transform: translateY(-50%);
  border-width: 4px;
  border-style: solid;
  border-color: transparent #0f172a transparent transparent;
}

/* Tooltip trigger on hover */
.sidebar-nav-link:hover .sidebar-tooltip,
.user-avatar-btn:hover .sidebar-tooltip,
.logout-btn.collapsed:hover .sidebar-tooltip {
  opacity: 1;
  visibility: visible;
  transform: translateY(-50%) translateX(0);
}

/* =========================================================================
   SIDEBAR FOOTER & USER PROFILE
   ========================================================================= */
.sidebar-footer {
  transition: padding 0.25s ease;
  flex-shrink: 0;
  background-color: #0c1322;
  overflow: hidden !important;
}

.footer-expanded {
  padding: 12px 14px;
}

.footer-collapsed {
  padding: 12px 8px;
}

.user-avatar-circle {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: #94a3b8;
  font-size: 0.95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.user-name-text {
  font-size: 0.85rem;
  font-weight: 600;
  color: #ffffff;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.user-role-badge {
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #cbd5e1;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 4px;
  padding: 1px 6px;
  line-height: 1.3;
}

.logout-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid rgba(239, 68, 68, 0.3);
  background-color: rgba(239, 68, 68, 0.08);
  color: #f87171;
  font-size: 0.85rem;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  padding: 0;
}

.logout-btn.collapsed {
  width: 36px;
  height: 36px;
}

.logout-btn:hover {
  background-color: #ef4444 !important;
  border-color: #ef4444 !important;
  color: #ffffff !important;
  box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
  transform: scale(1.05);
}

.logout-btn:focus-visible {
  outline: 2px solid #ef4444;
  outline-offset: 2px;
}

/* =========================================================================
   MAIN CONTENT DYNAMIC RESIZING
   ========================================================================= */
.main-content-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
  overflow-x: hidden;
  transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.main-expanded {
  margin-left: 240px;
  width: calc(100vw - 240px);
}

.main-collapsed {
  margin-left: 72px;
  width: calc(100vw - 72px);
}

.fs-7 {
  font-size: 0.8rem;
}
</style>


