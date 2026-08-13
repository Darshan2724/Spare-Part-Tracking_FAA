<template>
  <div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body shadow-sm px-3">
      <ul class="navbar-nav">
        <li class="nav-item">
          <router-link class="nav-link text-secondary" :to="{ name: 'dashboard' }">
            <i class="fas fa-arrow-left me-1"></i>Back
          </router-link>
        </li>
      </ul>
    </nav>

    <div class="app-content py-4 px-4">
      <div class="container-fluid">
        <div class="row g-3">
          <div class="col-lg-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <h5 class="fw-bold">Rework Queue</h5>
                <p class="text-muted">Track items that require reprocessing after QC review.</p>
                <router-link :to="{ name: 'rework' }" class="btn btn-outline-primary btn-sm">Open</router-link>
              </div>
            </div>
          </div>
          <div class="col-lg-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <h5 class="fw-bold">Purchase Queue</h5>
                <p class="text-muted">Review rejected parts that need supplier procurement.</p>
                <router-link :to="{ name: 'purchase' }" class="btn btn-outline-success btn-sm">Open</router-link>
              </div>
            </div>
          </div>
          <div class="col-lg-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <h5 class="fw-bold">Paint & Assembly</h5>
                <p class="text-muted">Coordinate painting and final assembly after approvals.</p>
                <router-link :to="{ name: 'paint' }" class="btn btn-outline-warning btn-sm">Open</router-link>
              </div>
            </div>
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
