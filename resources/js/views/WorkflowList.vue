<template>
  <div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body shadow-sm px-3">
      <ul class="navbar-nav">
        <li class="nav-item">
          <router-link class="nav-link text-secondary" :to="{ name: 'workflow-hub' }">
            <i class="fas fa-arrow-left me-1"></i>Back
          </router-link>
        </li>
      </ul>
    </nav>

    <div class="app-content py-4 px-4">
      <div class="container-fluid">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white border-bottom py-3">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h3 class="fw-bold mb-1">Workflow Module</h3>
                <p class="text-muted mb-0">Manage items for the current stage of the workflow.</p>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="alert alert-info">This module is ready for future expansion. The API endpoints are now available for rework, purchase, paint, and assembly operations.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
</script>

<style scoped>
.app-wrapper {
  min-height: 100vh;
  background-color: #f8fafc;
}
</style>
