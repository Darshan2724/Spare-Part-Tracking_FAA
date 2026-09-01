<template>
  <div class="supplier-allocation-wrapper">
    <!-- Alert Feedback Messages -->
    <div v-if="error" class="alert alert-danger alert-dismissible fade show shadow-xs py-2 px-3 mb-2.5">
      <i class="fas fa-exclamation-triangle me-1.5"></i>{{ error }}
      <button type="button" class="btn-close py-2" @click="error = ''"></button>
    </div>
    <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-xs py-2 px-3 mb-2.5">
      <i class="fas fa-check-circle me-1.5"></i>{{ successMessage }}
      <button type="button" class="btn-close py-2" @click="successMessage = ''"></button>
    </div>

    <!-- Navigation Context & View Toggle Bar -->
    <div class="d-flex justify-content-between align-items-center mb-2.5">
      <!-- Breadcrumb / Hierarchy Context -->
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 align-items-center small">
          <li class="breadcrumb-item">
            <a href="#" @click.prevent="navigateWithGuard(goToProjects)" class="text-secondary text-decoration-none fw-semibold">
              <i class="fas fa-folder me-1 text-primary"></i>Projects
            </a>
          </li>
          <li v-if="selectedProject" class="breadcrumb-item">
            <a href="#" @click.prevent="navigateWithGuard(() => { selectedJig = null; selectedUnit = null; })" class="text-primary text-decoration-none fw-semibold">
              {{ selectedProject.project_code || selectedProject.name }}
            </a>
          </li>
          <li v-if="selectedJig" class="breadcrumb-item">
            <a href="#" @click.prevent="navigateWithGuard(() => { selectedUnit = null; })" class="text-primary text-decoration-none fw-semibold">
              JIG: {{ selectedJig.jig_no }}
            </a>
          </li>
          <li v-if="selectedUnit" class="breadcrumb-item active text-success fw-bold">
            Unit {{ selectedUnit.unit_no }}
          </li>
        </ol>
      </nav>
    </div>

    <!-- ========================================================================= -->
    <!-- HIERARCHY DRILL-DOWN (Projects -> Jigs -> Units -> Category Cards)        -->
    <!-- ========================================================================= -->
    <!-- Loading State for Initial Project Fetch -->
    <div v-if="loading && !hierarchyJigs.length" class="text-center py-4 bg-white rounded border shadow-xs app-card">
      <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
      <div class="small text-muted">Loading Supplier Allocation Hierarchy...</div>
    </div>

    <!-- LEVEL 1: COMPACT PROJECT CARDS WITH CLEAR BORDERS -->
    <div v-else-if="!selectedProjectId">
      <div class="row g-2.5">
          <div v-for="proj in projects" :key="proj.id" class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div 
              class="card project-card hover-card bg-white cursor-pointer h-100"
              @click="selectProject(proj.id)"
            >
              <div class="d-flex justify-content-between align-items-start mb-1.5">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">
                  {{ proj.project_code || 'PROJ' }}
                </span>
                <span class="badge bg-light text-dark border extra-small">
                  {{ proj.status ? proj.status.toUpperCase() : 'ACTIVE' }}
                </span>
              </div>
              <h6 class="fw-bold text-dark mb-1 text-truncate" :title="proj.name">{{ proj.name }}</h6>
              <div class="d-flex justify-content-between align-items-center mt-2.5 border-top pt-1.5">
                <span class="extra-small text-muted">Select Project</span>
                <span class="extra-small text-primary fw-bold">Open &rarr;</span>
              </div>
            </div>
          </div>
          <div v-if="!projects.length" class="col-12 text-center py-4 text-muted">
            <p class="small mb-0">No active projects found.</p>
          </div>
        </div>
      </div>

      <!-- LEVEL 2: COMPACT JIG CARDS WITH CLEAR BORDERS -->
      <div v-else-if="selectedProjectId && !selectedJig">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small fw-bold text-dark">
            <i class="fas fa-cubes me-1 text-primary"></i> Jigs in {{ selectedProject?.project_code || selectedProject?.name }} ({{ hierarchyJigs.length }} Jigs)
          </div>
          <button class="btn btn-xs btn-outline-secondary" @click="goToProjects">
            <i class="fas fa-arrow-left me-1"></i> Back to Projects
          </button>
        </div>

        <div class="row g-2.5">
          <div v-for="jig in hierarchyJigs" :key="jig.jig_no" class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div 
              class="card jig-card hover-card bg-white cursor-pointer h-100"
              :class="{ 'border-success-subtle': jig.allocation_pct === 100 }"
              @click="selectedJig = jig"
            >
              <div class="d-flex justify-content-between align-items-center mb-1.5">
                <strong class="text-dark fs-6">{{ jig.jig_no }}</strong>
                <span class="badge extra-small" :class="jig.allocation_pct === 100 ? 'bg-success' : (jig.assigned_slots > 0 ? 'bg-primary' : 'bg-secondary')">
                  {{ jig.allocation_pct }}%
                </span>
              </div>

              <div class="extra-small text-muted mb-1.5">
                {{ jig.total_units }} Units &bull; {{ jig.assigned_slots }} / {{ jig.total_slots }} Slots Assigned
              </div>

              <div class="progress" style="height: 4px;">
                <div 
                  class="progress-bar" 
                  :class="jig.allocation_pct === 100 ? 'bg-success' : 'bg-primary'"
                  :style="{ width: `${jig.allocation_pct}%` }"
                ></div>
              </div>
            </div>
          </div>
          <div v-if="!hierarchyJigs.length" class="col-12 text-center py-4 text-muted">
            <p class="small mb-0">No Jigs configured for this project.</p>
          </div>
        </div>
      </div>

      <!-- LEVEL 3: COMPACT UNIT CARDS WITH CLEAR BORDERS -->
      <div v-else-if="selectedJig && !selectedUnit">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small fw-bold text-dark">
            <i class="fas fa-layer-group me-1 text-primary"></i> Units in JIG {{ selectedJig.jig_no }} ({{ selectedJig.units?.length || 0 }} Units)
          </div>
          <button class="btn btn-xs btn-outline-secondary" @click="selectedJig = null">
            <i class="fas fa-arrow-left me-1"></i> Back to Jigs
          </button>
        </div>

        <div class="row g-2.5">
          <div v-for="unit in selectedJig.units" :key="unit.unit_no" class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div 
              class="card unit-card hover-card bg-white cursor-pointer h-100"
              :class="{ 'border-success-subtle': unit.is_fully_assigned }"
              @click="openUnit(unit)"
            >
              <div class="d-flex justify-content-between align-items-center mb-1.5 pb-1 border-bottom">
                <strong class="text-dark fs-6 d-flex align-items-center gap-1">
                  <i class="fas fa-cube text-primary"></i> Unit {{ unit.unit_no }}
                </strong>
                <span class="badge extra-small" :class="unit.is_fully_assigned ? 'bg-success' : (unit.assigned_count > 0 ? 'bg-warning text-dark' : 'bg-secondary')">
                  {{ unit.assigned_count }}/3
                </span>
              </div>

              <!-- Compact Inline Category Summary -->
              <div class="d-flex flex-column gap-1 extra-small">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-muted fw-semibold">BASE:</span>
                  <span class="text-truncate fw-bold" :class="unit.categories?.BASE ? 'text-success' : 'text-muted'" style="max-width: 120px;">
                    {{ unit.categories?.BASE ? unit.categories.BASE.supplier_name : 'Unassigned' }}
                  </span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-muted fw-semibold">WELDMENT:</span>
                  <span class="text-truncate fw-bold" :class="unit.categories?.WELDMENT ? 'text-info-emphasis' : 'text-muted'" style="max-width: 120px;">
                    {{ unit.categories?.WELDMENT ? unit.categories.WELDMENT.supplier_name : 'Unassigned' }}
                  </span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-muted fw-semibold">CHILD PART:</span>
                  <span class="text-truncate fw-bold" :class="unit.categories?.CHILD_PART ? 'text-warning-emphasis' : 'text-muted'" style="max-width: 120px;">
                    {{ unit.categories?.CHILD_PART ? unit.categories.CHILD_PART.supplier_name : 'Unassigned' }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================================= -->
      <!-- LEVEL 4: COMPACT 3-CATEGORY ALLOCATION VIEW (BASE / WELDMENT / CHILD PART)-->
      <!-- ========================================================================= -->
      <div v-else-if="selectedUnit">
        <!-- Compact Single Context Bar (No large Save button here) -->
        <div class="card app-card bg-white mb-2.5">
          <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <!-- Left: Path & Status -->
            <div class="d-flex align-items-center gap-2">
              <span class="fw-bold text-dark fs-6 d-flex align-items-center gap-1.5">
                <span>{{ selectedProject?.project_code || selectedProject?.name }}</span>
                <span class="text-muted">&rsaquo;</span>
                <span>JIG {{ selectedJig.jig_no }}</span>
                <span class="text-muted">&rsaquo;</span>
                <span class="text-primary">Unit {{ selectedUnit.unit_no }}</span>
              </span>
              <span class="badge extra-small" :class="selectedUnit.is_fully_assigned ? 'bg-success' : 'bg-primary-subtle text-primary border'">
                {{ selectedUnit.assigned_count }}/3 Assigned
              </span>
              <span v-if="hasUnsavedChanges" class="badge bg-warning text-dark extra-small">
                <i class="fas fa-edit me-0.5"></i> Unsaved Changes
              </span>
            </div>

            <!-- Right: Back Navigation -->
            <div class="d-flex align-items-center gap-2">
              <button class="btn btn-sm btn-outline-secondary py-1 px-2.5" @click="navigateWithGuard(() => { selectedUnit = null; })">
                <i class="fas fa-arrow-left me-1"></i> Back to Units
              </button>
            </div>
          </div>
        </div>

        <!-- THREE COMPACT HORIZONTAL CATEGORY CARDS (BASE, WELDMENT, CHILD PART) -->
        <div class="row g-2.5 position-relative">
          <!-- 1. BASE CATEGORY -->
          <div class="col-12 col-lg-4">
            <div class="card category-card h-100 bg-white border-top border-3 border-success">
              <!-- Card Header -->
              <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                  <i class="fas fa-square text-success"></i> BASE
                </span>
                <span v-if="unitDraft.BASE.current" class="badge bg-success extra-small">
                  <i class="fas fa-check me-0.5"></i> Assigned
                </span>
                <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                  Unassigned
                </span>
              </div>

              <!-- Card Body -->
              <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                <div>
                  <!-- Assigned Info Strip if currently assigned -->
                  <div v-if="unitDraft.BASE.current" class="p-1.5 mb-2 bg-success-subtle bg-opacity-25 rounded border border-success-subtle d-flex justify-content-between align-items-center extra-small">
                    <div class="text-truncate" style="max-width: 200px;">
                      <strong class="text-success">{{ unitDraft.BASE.current.supplier_name }}</strong>
                      <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.BASE.current.assignment_date) }})</span>
                    </div>
                    <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('BASE')">
                      Clear
                    </button>
                  </div>

                  <!-- BASE Searchable Supplier Dropdown with Prefix-Match Priority -->
                  <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('BASE')">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5">Supplier</label>
                    <div 
                      class="searchable-select-trigger shadow-xs" 
                      :class="{ 'disabled-trigger': !canEdit }"
                      @click="canEdit && toggleSupplierDropdown('BASE')"
                    >
                      <span class="text-truncate" :class="{ 'text-muted': !unitDraft.BASE.supplier_id }">
                        {{ getSelectedSupplierName('BASE') || '-- Select Supplier --' }}
                      </span>
                      <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                    </div>

                    <!-- Searchable Dropdown Popup -->
                    <div v-if="activeSupplierDropdown === 'BASE'" class="searchable-select-menu shadow-lg">
                      <div class="p-1.5 border-bottom bg-light">
                        <input 
                          v-model="supplierSearch.BASE" 
                          type="text" 
                          class="form-control form-control-sm extra-small" 
                          placeholder="Type to search (prefix match first)..." 
                          autofocus
                          @click.stop
                        />
                      </div>
                      <div class="searchable-select-list">
                        <div 
                          class="searchable-select-item text-danger border-bottom"
                          :class="{ selected: !unitDraft.BASE.supplier_id }"
                          @click="selectSupplier('BASE', '')"
                        >
                          <span><i class="fas fa-times me-1"></i> -- Unassigned / Clear --</span>
                        </div>
                        <div 
                          v-for="s in getFilteredSuppliers('BASE')" 
                          :key="s.id" 
                          class="searchable-select-item"
                          :class="{ selected: unitDraft.BASE.supplier_id == s.id }"
                          @click="selectSupplier('BASE', s.id)"
                        >
                          <span class="text-truncate">
                            <strong>{{ s.name }}</strong>
                          </span>
                          <span v-if="s.code" class="badge bg-light text-secondary border extra-small ms-1">
                            {{ s.code }}
                          </span>
                        </div>
                        <div v-if="!getFilteredSuppliers('BASE').length" class="p-2 text-center text-muted extra-small">
                          No matching suppliers found
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Compact Date Input & Real Calendar Popup -->
                  <div class="mb-1 position-relative">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                      <span>Assignment / Delivery Date</span>
                      <span class="text-muted extra-small">Today &plusmn; 3d</span>
                    </label>
                    <div class="input-group input-group-sm">
                      <input 
                        type="text" 
                        class="form-control form-control-sm bg-white cursor-pointer"
                        :value="formatDisplayDate(unitDraft.BASE.assignment_date)" 
                        readonly
                        :disabled="!canEdit"
                        @click="toggleCalendar('BASE')"
                        placeholder="Select Date"
                      />
                      <button 
                        class="btn btn-outline-secondary btn-sm" 
                        type="button" 
                        :disabled="!canEdit"
                        @click="toggleCalendar('BASE')"
                        title="Open Calendar"
                      >
                        <i class="fas fa-calendar-alt text-primary"></i>
                      </button>
                    </div>

                    <!-- Compact Real Calendar Popup (BASE) -->
                    <div 
                      v-if="activeCalendar === 'BASE'" 
                      class="calendar-popup-container shadow-lg border rounded p-2 bg-white"
                      v-click-outside="closeCalendar"
                    >
                      <div class="calendar-header d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="prevCalendarMonth">
                          <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="fw-bold extra-small text-dark">{{ calendarMonthLabel }}</span>
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="nextCalendarMonth">
                          <i class="fas fa-chevron-right"></i>
                        </button>
                      </div>

                      <div class="calendar-grid-weekdays mb-1">
                        <span v-for="w in ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']" :key="w" class="text-muted extra-small text-center fw-bold">{{ w }}</span>
                      </div>

                      <div class="calendar-grid-days">
                        <div v-for="pad in calendarLeadingBlanks" :key="'pad-' + pad" class="calendar-blank"></div>
                        <button
                          v-for="d in calendarDays"
                          :key="d.dateStr"
                          type="button"
                          class="calendar-day-btn btn btn-xs p-0"
                          :class="{
                            'btn-primary active fw-bold text-white': unitDraft.BASE.assignment_date === d.dateStr,
                            'btn-outline-primary fw-bold': unitDraft.BASE.assignment_date !== d.dateStr && d.isToday,
                            'btn-light text-dark': unitDraft.BASE.assignment_date !== d.dateStr && !d.isToday && d.isAllowed,
                            'disabled-day text-muted opacity-40': !d.isAllowed,
                          }"
                          :disabled="!d.isAllowed"
                          @click="selectDate('BASE', d.dateStr)"
                          :title="d.isAllowed ? (d.isToday ? 'Today' : d.dateStr) : 'Date outside allowed range (Today ± 3d)'"
                        >
                          {{ d.dayNumber }}
                        </button>
                      </div>

                      <div class="mt-1 pt-1 border-top d-flex justify-content-between align-items-center extra-small">
                        <span class="badge bg-warning text-dark py-0.5 px-1" style="font-size: 0.6rem;">Today: {{ formatDisplayDate(todayStr) }}</span>
                        <button type="button" class="btn btn-link p-0 extra-small text-decoration-none text-muted" @click="activeCalendar = null">
                          Close
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 2. WELDMENT CATEGORY -->
          <div class="col-12 col-lg-4">
            <div class="card category-card h-100 bg-white border-top border-3 border-info">
              <!-- Card Header -->
              <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                  <i class="fas fa-cog text-info"></i> WELDMENT
                </span>
                <span v-if="unitDraft.WELDMENT.current" class="badge bg-info text-dark extra-small">
                  <i class="fas fa-check me-0.5"></i> Assigned
                </span>
                <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                  Unassigned
                </span>
              </div>

              <!-- Card Body -->
              <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                <div>
                  <!-- Assigned Info Strip if currently assigned -->
                  <div v-if="unitDraft.WELDMENT.current" class="p-1.5 mb-2 bg-info-subtle bg-opacity-25 rounded border border-info-subtle d-flex justify-content-between align-items-center extra-small">
                    <div class="text-truncate" style="max-width: 200px;">
                      <strong class="text-info-emphasis">{{ unitDraft.WELDMENT.current.supplier_name }}</strong>
                      <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.WELDMENT.current.assignment_date) }})</span>
                    </div>
                    <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('WELDMENT')">
                      Clear
                    </button>
                  </div>

                  <!-- WELDMENT Searchable Supplier Dropdown with Prefix-Match Priority -->
                  <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('WELDMENT')">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5">Supplier</label>
                    <div 
                      class="searchable-select-trigger shadow-xs" 
                      :class="{ 'disabled-trigger': !canEdit }"
                      @click="canEdit && toggleSupplierDropdown('WELDMENT')"
                    >
                      <span class="text-truncate" :class="{ 'text-muted': !unitDraft.WELDMENT.supplier_id }">
                        {{ getSelectedSupplierName('WELDMENT') || '-- Select Supplier --' }}
                      </span>
                      <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                    </div>

                    <!-- Searchable Dropdown Popup -->
                    <div v-if="activeSupplierDropdown === 'WELDMENT'" class="searchable-select-menu shadow-lg">
                      <div class="p-1.5 border-bottom bg-light">
                        <input 
                          v-model="supplierSearch.WELDMENT" 
                          type="text" 
                          class="form-control form-control-sm extra-small" 
                          placeholder="Type to search (prefix match first)..." 
                          autofocus
                          @click.stop
                        />
                      </div>
                      <div class="searchable-select-list">
                        <div 
                          class="searchable-select-item text-danger border-bottom"
                          :class="{ selected: !unitDraft.WELDMENT.supplier_id }"
                          @click="selectSupplier('WELDMENT', '')"
                        >
                          <span><i class="fas fa-times me-1"></i> -- Unassigned / Clear --</span>
                        </div>
                        <div 
                          v-for="s in getFilteredSuppliers('WELDMENT')" 
                          :key="s.id" 
                          class="searchable-select-item"
                          :class="{ selected: unitDraft.WELDMENT.supplier_id == s.id }"
                          @click="selectSupplier('WELDMENT', s.id)"
                        >
                          <span class="text-truncate">
                            <strong>{{ s.name }}</strong>
                          </span>
                          <span v-if="s.code" class="badge bg-light text-secondary border extra-small ms-1">
                            {{ s.code }}
                          </span>
                        </div>
                        <div v-if="!getFilteredSuppliers('WELDMENT').length" class="p-2 text-center text-muted extra-small">
                          No matching suppliers found
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Compact Date Input & Real Calendar Popup -->
                  <div class="mb-1 position-relative">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                      <span>Assignment / Delivery Date</span>
                      <span class="text-muted extra-small">Today &plusmn; 3d</span>
                    </label>
                    <div class="input-group input-group-sm">
                      <input 
                        type="text" 
                        class="form-control form-control-sm bg-white cursor-pointer"
                        :value="formatDisplayDate(unitDraft.WELDMENT.assignment_date)" 
                        readonly
                        :disabled="!canEdit"
                        @click="toggleCalendar('WELDMENT')"
                        placeholder="Select Date"
                      />
                      <button 
                        class="btn btn-outline-secondary btn-sm" 
                        type="button" 
                        :disabled="!canEdit"
                        @click="toggleCalendar('WELDMENT')"
                        title="Open Calendar"
                      >
                        <i class="fas fa-calendar-alt text-primary"></i>
                      </button>
                    </div>

                    <!-- Compact Real Calendar Popup (WELDMENT) -->
                    <div 
                      v-if="activeCalendar === 'WELDMENT'" 
                      class="calendar-popup-container shadow-lg border rounded p-2 bg-white"
                      v-click-outside="closeCalendar"
                    >
                      <div class="calendar-header d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="prevCalendarMonth">
                          <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="fw-bold extra-small text-dark">{{ calendarMonthLabel }}</span>
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="nextCalendarMonth">
                          <i class="fas fa-chevron-right"></i>
                        </button>
                      </div>

                      <div class="calendar-grid-weekdays mb-1">
                        <span v-for="w in ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']" :key="w" class="text-muted extra-small text-center fw-bold">{{ w }}</span>
                      </div>

                      <div class="calendar-grid-days">
                        <div v-for="pad in calendarLeadingBlanks" :key="'pad-' + pad" class="calendar-blank"></div>
                        <button
                          v-for="d in calendarDays"
                          :key="d.dateStr"
                          type="button"
                          class="calendar-day-btn btn btn-xs p-0"
                          :class="{
                            'btn-primary active fw-bold text-white': unitDraft.WELDMENT.assignment_date === d.dateStr,
                            'btn-outline-primary fw-bold': unitDraft.WELDMENT.assignment_date !== d.dateStr && d.isToday,
                            'btn-light text-dark': unitDraft.WELDMENT.assignment_date !== d.dateStr && !d.isToday && d.isAllowed,
                            'disabled-day text-muted opacity-40': !d.isAllowed,
                          }"
                          :disabled="!d.isAllowed"
                          @click="selectDate('WELDMENT', d.dateStr)"
                          :title="d.isAllowed ? (d.isToday ? 'Today' : d.dateStr) : 'Date outside allowed range (Today ± 3d)'"
                        >
                          {{ d.dayNumber }}
                        </button>
                      </div>

                      <div class="mt-1 pt-1 border-top d-flex justify-content-between align-items-center extra-small">
                        <span class="badge bg-warning text-dark py-0.5 px-1" style="font-size: 0.6rem;">Today: {{ formatDisplayDate(todayStr) }}</span>
                        <button type="button" class="btn btn-link p-0 extra-small text-decoration-none text-muted" @click="activeCalendar = null">
                          Close
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 3. CHILD PART CATEGORY -->
          <div class="col-12 col-lg-4">
            <div class="card category-card h-100 bg-white border-top border-3 border-warning">
              <!-- Card Header -->
              <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                  <i class="fas fa-puzzle-piece text-warning"></i> CHILD PART
                </span>
                <span v-if="unitDraft.CHILD_PART.current" class="badge bg-warning text-dark extra-small">
                  <i class="fas fa-check me-0.5"></i> Assigned
                </span>
                <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                  Unassigned
                </span>
              </div>

              <!-- Card Body -->
              <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                <div>
                  <!-- Assigned Info Strip if currently assigned -->
                  <div v-if="unitDraft.CHILD_PART.current" class="p-1.5 mb-2 bg-warning-subtle bg-opacity-25 rounded border border-warning-subtle d-flex justify-content-between align-items-center extra-small">
                    <div class="text-truncate" style="max-width: 200px;">
                      <strong class="text-warning-emphasis">{{ unitDraft.CHILD_PART.current.supplier_name }}</strong>
                      <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.CHILD_PART.current.assignment_date) }})</span>
                    </div>
                    <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('CHILD_PART')">
                      Clear
                    </button>
                  </div>

                  <!-- CHILD PART Searchable Supplier Dropdown with Prefix-Match Priority -->
                  <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('CHILD_PART')">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5">Supplier</label>
                    <div 
                      class="searchable-select-trigger shadow-xs" 
                      :class="{ 'disabled-trigger': !canEdit }"
                      @click="canEdit && toggleSupplierDropdown('CHILD_PART')"
                    >
                      <span class="text-truncate" :class="{ 'text-muted': !unitDraft.CHILD_PART.supplier_id }">
                        {{ getSelectedSupplierName('CHILD_PART') || '-- Select Supplier --' }}
                      </span>
                      <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                    </div>

                    <!-- Searchable Dropdown Popup -->
                    <div v-if="activeSupplierDropdown === 'CHILD_PART'" class="searchable-select-menu shadow-lg">
                      <div class="p-1.5 border-bottom bg-light">
                        <input 
                          v-model="supplierSearch.CHILD_PART" 
                          type="text" 
                          class="form-control form-control-sm extra-small" 
                          placeholder="Type to search (prefix match first)..." 
                          autofocus
                          @click.stop
                        />
                      </div>
                      <div class="searchable-select-list">
                        <div 
                          class="searchable-select-item text-danger border-bottom"
                          :class="{ selected: !unitDraft.CHILD_PART.supplier_id }"
                          @click="selectSupplier('CHILD_PART', '')"
                        >
                          <span><i class="fas fa-times me-1"></i> -- Unassigned / Clear --</span>
                        </div>
                        <div 
                          v-for="s in getFilteredSuppliers('CHILD_PART')" 
                          :key="s.id" 
                          class="searchable-select-item"
                          :class="{ selected: unitDraft.CHILD_PART.supplier_id == s.id }"
                          @click="selectSupplier('CHILD_PART', s.id)"
                        >
                          <span class="text-truncate">
                            <strong>{{ s.name }}</strong>
                          </span>
                          <span v-if="s.code" class="badge bg-light text-secondary border extra-small ms-1">
                            {{ s.code }}
                          </span>
                        </div>
                        <div v-if="!getFilteredSuppliers('CHILD_PART').length" class="p-2 text-center text-muted extra-small">
                          No matching suppliers found
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Compact Date Input & Real Calendar Popup -->
                  <div class="mb-1 position-relative">
                    <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                      <span>Assignment / Delivery Date</span>
                      <span class="text-muted extra-small">Today &plusmn; 3d</span>
                    </label>
                    <div class="input-group input-group-sm">
                      <input 
                        type="text" 
                        class="form-control form-control-sm bg-white cursor-pointer"
                        :value="formatDisplayDate(unitDraft.CHILD_PART.assignment_date)" 
                        readonly
                        :disabled="!canEdit"
                        @click="toggleCalendar('CHILD_PART')"
                        placeholder="Select Date"
                      />
                      <button 
                        class="btn btn-outline-secondary btn-sm" 
                        type="button" 
                        :disabled="!canEdit"
                        @click="toggleCalendar('CHILD_PART')"
                        title="Open Calendar"
                      >
                        <i class="fas fa-calendar-alt text-primary"></i>
                      </button>
                    </div>

                    <!-- Compact Real Calendar Popup (CHILD PART) -->
                    <div 
                      v-if="activeCalendar === 'CHILD_PART'" 
                      class="calendar-popup-container shadow-lg border rounded p-2 bg-white"
                      v-click-outside="closeCalendar"
                    >
                      <div class="calendar-header d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="prevCalendarMonth">
                          <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="fw-bold extra-small text-dark">{{ calendarMonthLabel }}</span>
                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" @click="nextCalendarMonth">
                          <i class="fas fa-chevron-right"></i>
                        </button>
                      </div>

                      <div class="calendar-grid-weekdays mb-1">
                        <span v-for="w in ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']" :key="w" class="text-muted extra-small text-center fw-bold">{{ w }}</span>
                      </div>

                      <div class="calendar-grid-days">
                        <div v-for="pad in calendarLeadingBlanks" :key="'pad-' + pad" class="calendar-blank"></div>
                        <button
                          v-for="d in calendarDays"
                          :key="d.dateStr"
                          type="button"
                          class="calendar-day-btn btn btn-xs p-0"
                          :class="{
                            'btn-primary active fw-bold text-white': unitDraft.CHILD_PART.assignment_date === d.dateStr,
                            'btn-outline-primary fw-bold': unitDraft.CHILD_PART.assignment_date !== d.dateStr && d.isToday,
                            'btn-light text-dark': unitDraft.CHILD_PART.assignment_date !== d.dateStr && !d.isToday && d.isAllowed,
                            'disabled-day text-muted opacity-40': !d.isAllowed,
                          }"
                          :disabled="!d.isAllowed"
                          @click="selectDate('CHILD_PART', d.dateStr)"
                          :title="d.isAllowed ? (d.isToday ? 'Today' : d.dateStr) : 'Date outside allowed range (Today ± 3d)'"
                        >
                          {{ d.dayNumber }}
                        </button>
                      </div>

                      <div class="mt-1 pt-1 border-top d-flex justify-content-between align-items-center extra-small">
                        <span class="badge bg-warning text-dark py-0.5 px-1" style="font-size: 0.6rem;">Today: {{ formatDisplayDate(todayStr) }}</span>
                        <button type="button" class="btn btn-link p-0 extra-small text-decoration-none text-muted" @click="activeCalendar = null">
                          Close
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ========================================================================= -->
        <!-- BOTTOM ALIGNED PRIMARY ACTION BAR: "APPLY CHANGES" (Compact & Clean)     -->
        <!-- ========================================================================= -->
        <div class="card app-card bg-white mt-3 shadow-xs">
          <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
              <span v-if="hasUnsavedChanges" class="badge bg-warning text-dark py-1.5 px-2.5 fs-7 d-flex align-items-center gap-1 shadow-xs">
                <i class="fas fa-exclamation-circle"></i>
                <span>Unsaved Changes Pending</span>
              </span>
              <span v-else class="text-muted small d-flex align-items-center gap-1">
                <i class="fas fa-check-circle text-success"></i>
                <span>All category allocations saved with server</span>
              </span>
            </div>

            <div class="d-flex align-items-center gap-2">
              <button 
                v-if="hasUnsavedChanges"
                type="button" 
                class="btn btn-sm btn-outline-secondary py-1 px-3"
                @click="resetDraftToSaved"
                :disabled="savingBulk"
              >
                <i class="fas fa-undo me-1"></i> Discard
              </button>
              <button 
                v-if="canEdit"
                type="button" 
                class="btn btn-sm btn-primary fw-semibold py-1 px-3.5 shadow-xs"
                :disabled="!hasUnsavedChanges || savingBulk"
                @click="applyAllChanges"
              >
                <span v-if="savingBulk" class="spinner-border spinner-border-sm me-1.5"></span>
                <i v-else class="fas fa-check me-1.5"></i> Apply Changes
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import axios from 'axios';

// Simple click-outside directive for calendar popup
const vClickOutside = {
  mounted(el, binding) {
    el._clickOutsideHandler = (event) => {
      if (!(el === event.target || el.contains(event.target))) {
        binding.value(event);
      }
    };
    setTimeout(() => {
      document.addEventListener('click', el._clickOutsideHandler);
    }, 10);
  },
  unmounted(el) {
    if (el._clickOutsideHandler) {
      document.removeEventListener('click', el._clickOutsideHandler);
    }
  }
};

const authStore = useAuthStore();
const canEdit = computed(() => ['ADMIN', 'MANAGER', 'PURCHASE'].includes(authStore.userRole));

// State
const loading = ref(false);
const error = ref('');
const successMessage = ref('');
const projects = ref([]);
const activeSuppliers = ref([]);

const selectedProjectId = ref('');
const selectedJig = ref(null);
const selectedUnit = ref(null);
const hierarchyJigs = ref([]);

const savingBulk = ref(false);

// Date & 7-Day Window Range
const today = new Date();
const todayStr = today.toISOString().slice(0, 10);

const minAllowedDate = new Date(today);
minAllowedDate.setDate(today.getDate() - 3);
const minAllowedStr = minAllowedDate.toISOString().slice(0, 10);

const maxAllowedDate = new Date(today);
maxAllowedDate.setDate(today.getDate() + 3);
const maxAllowedStr = maxAllowedDate.toISOString().slice(0, 10);

// Calendar Navigation State
const activeCalendar = ref(null); // 'BASE' | 'WELDMENT' | 'CHILD_PART' | null
const calendarYear = ref(today.getFullYear());
const calendarMonth = ref(today.getMonth()); // 0-indexed

// Saved vs Draft State
const unitSavedState = ref({
  BASE: { supplier_id: '', assignment_date: todayStr, current: null },
  WELDMENT: { supplier_id: '', assignment_date: todayStr, current: null },
  CHILD_PART: { supplier_id: '', assignment_date: todayStr, current: null },
});

const unitDraft = ref({
  BASE: { supplier_id: '', assignment_date: todayStr, current: null },
  WELDMENT: { supplier_id: '', assignment_date: todayStr, current: null },
  CHILD_PART: { supplier_id: '', assignment_date: todayStr, current: null },
});

// Reactively detect if draft state differs from saved server state
const hasUnsavedChanges = computed(() => {
  if (!selectedUnit.value) return false;
  for (const cat of ['BASE', 'WELDMENT', 'CHILD_PART']) {
    const s = unitSavedState.value[cat];
    const d = unitDraft.value[cat];
    const sSupplier = s.supplier_id ? String(s.supplier_id) : '';
    const dSupplier = d.supplier_id ? String(d.supplier_id) : '';
    const sDate = s.assignment_date ? s.assignment_date.slice(0, 10) : todayStr;
    const dDate = d.assignment_date ? d.assignment_date.slice(0, 10) : todayStr;

    if (sSupplier !== dSupplier || sDate !== dDate) {
      return true;
    }
  }
  return false;
});

const selectedProject = computed(() => {
  return projects.value.find(p => p.id == selectedProjectId.value) || null;
});

// Calendar Month Grid Computed Properties
const calendarMonthLabel = computed(() => {
  const d = new Date(calendarYear.value, calendarMonth.value, 1);
  return d.toLocaleString('default', { month: 'short', year: 'numeric' });
});

const calendarLeadingBlanks = computed(() => {
  const firstDay = new Date(calendarYear.value, calendarMonth.value, 1).getDay();
  return Array.from({ length: firstDay }, (_, i) => i);
});

const calendarDays = computed(() => {
  const daysInMonth = new Date(calendarYear.value, calendarMonth.value + 1, 0).getDate();
  const list = [];

  for (let d = 1; d <= daysInMonth; d++) {
    const monthStr = String(calendarMonth.value + 1).padStart(2, '0');
    const dayStr = String(d).padStart(2, '0');
    const dateStr = `${calendarYear.value}-${monthStr}-${dayStr}`;

    const isAllowed = dateStr >= minAllowedStr && dateStr <= maxAllowedStr;
    const isToday = dateStr === todayStr;

    list.push({
      dayNumber: d,
      dateStr,
      isAllowed,
      isToday,
    });
  }
  return list;
});

const toggleCalendar = (category) => {
  if (activeCalendar.value === category) {
    activeCalendar.value = null;
  } else {
    activeCalendar.value = category;
    calendarYear.value = today.getFullYear();
    calendarMonth.value = today.getMonth();
  }
};

const closeCalendar = () => {
  activeCalendar.value = null;
};

const prevCalendarMonth = () => {
  if (calendarMonth.value === 0) {
    calendarMonth.value = 11;
    calendarYear.value--;
  } else {
    calendarMonth.value--;
  }
};

const nextCalendarMonth = () => {
  if (calendarMonth.value === 11) {
    calendarMonth.value = 0;
    calendarYear.value++;
  } else {
    calendarMonth.value++;
  }
};

const selectDate = (category, dateStr) => {
  unitDraft.value[category].assignment_date = dateStr;
  activeCalendar.value = null;
};

// Searchable Supplier Dropdown State & Prefix Match Priority
const activeSupplierDropdown = ref(null); // 'BASE' | 'WELDMENT' | 'CHILD_PART' | null
const supplierSearch = ref({ BASE: '', WELDMENT: '', CHILD_PART: '' });

const toggleSupplierDropdown = (category) => {
  if (activeSupplierDropdown.value === category) {
    activeSupplierDropdown.value = null;
  } else {
    activeSupplierDropdown.value = category;
    supplierSearch.value[category] = '';
  }
};

const closeSupplierDropdown = (category) => {
  if (activeSupplierDropdown.value === category) {
    activeSupplierDropdown.value = null;
  }
};

const selectSupplier = (category, supplierId) => {
  unitDraft.value[category].supplier_id = supplierId;
  activeSupplierDropdown.value = null;
  supplierSearch.value[category] = '';
};

const getSelectedSupplierName = (category) => {
  const sid = unitDraft.value[category].supplier_id;
  if (!sid) return '';
  const found = activeSuppliers.value.find(s => s.id == sid);
  return found ? `${found.name} (${found.code || 'SUP'})` : '';
};

const getFilteredSuppliers = (category) => {
  const q = (supplierSearch.value[category] || '').trim().toLowerCase();
  if (!q) {
    return activeSuppliers.value;
  }

  const prefixMatches = [];
  const wordStartMatches = [];
  const containsMatches = [];

  for (const s of activeSuppliers.value) {
    const name = (s.name || '').toLowerCase();
    const code = (s.code || '').toLowerCase();

    if (name.startsWith(q) || code.startsWith(q)) {
      prefixMatches.push(s);
    } else {
      const words = name.split(/\s+/);
      const isWordStart = words.some(w => w.startsWith(q));
      if (isWordStart) {
        wordStartMatches.push(s);
      } else if (name.includes(q) || code.includes(q)) {
        containsMatches.push(s);
      }
    }
  }

  return [...prefixMatches, ...wordStartMatches, ...containsMatches];
};

const formatDisplayDate = (dateVal) => {
  if (!dateVal) return todayStr;
  const clean = dateVal.slice(0, 10);
  const parts = clean.split('-');
  if (parts.length === 3) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const monthIdx = parseInt(parts[1], 10) - 1;
    const monthName = months[monthIdx] || parts[1];
    return `${parts[2]}-${monthName}-${parts[0]}`;
  }
  return clean;
};

// Navigation Guard if unsaved changes exist
const navigateWithGuard = (callback) => {
  if (hasUnsavedChanges.value) {
    if (confirm('You have unsaved changes. Discard and leave?')) {
      resetDraftToSaved();
      callback();
    }
  } else {
    callback();
  }
};

const goToProjects = () => {
  selectedProjectId.value = '';
  selectedJig.value = null;
  selectedUnit.value = null;
};

const selectProject = (projectId) => {
  selectedProjectId.value = projectId;
  selectedJig.value = null;
  selectedUnit.value = null;
  fetchHierarchy();
};

const fetchSuppliers = async () => {
  try {
    const res = await axios.get('/api/v1/suppliers/active-list');
    activeSuppliers.value = res.data.suppliers || [];
  } catch (err) {
    console.error('Failed to load active suppliers:', err);
  }
};

const fetchHierarchy = async (preserveSelection = false, silent = false) => {
  if (!silent) loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams();
    if (selectedProjectId.value) {
      params.append('project_id', selectedProjectId.value);
    }
    const res = await axios.get(`/api/v1/supplier-allocation/hierarchy?${params.toString()}`);
    projects.value = res.data.projects || [];
    
    if (selectedProjectId.value && res.data.hierarchy?.jigs) {
      hierarchyJigs.value = res.data.hierarchy.jigs || [];

      if (preserveSelection && selectedJig.value) {
        const foundJig = hierarchyJigs.value.find(j => j.jig_no === selectedJig.value.jig_no);
        if (foundJig) {
          selectedJig.value = foundJig;
          if (selectedUnit.value) {
            const foundUnit = foundJig.units.find(u => u.unit_no === selectedUnit.value.unit_no);
            if (foundUnit) {
              selectedUnit.value = foundUnit;
              initSavedAndDraftState(foundUnit);
            }
          }
        }
      }
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load supplier allocation hierarchy.';
  } finally {
    if (!silent) loading.value = false;
  }
};

const openUnit = async (unit) => {
  selectedUnit.value = unit;
  initSavedAndDraftState(unit);

  // Directly fetch latest assignments for this unit to guarantee 100% freshness from PostgreSQL
  try {
    const res = await axios.get('/api/v1/supplier-allocation/assignments', {
      params: {
        project_id: selectedProjectId.value,
        jig_no: selectedJig.value.jig_no,
        unit_no: unit.unit_no,
      }
    });
    if (res.data.assignments) {
      const cats = { BASE: null, WELDMENT: null, CHILD_PART: null };
      res.data.assignments.forEach(a => {
        if (cats.hasOwnProperty(a.category)) {
          cats[a.category] = {
            id: a.id,
            supplier_id: a.supplier_id,
            supplier_name: a.supplier?.name || 'Assigned Supplier',
            supplier_code: a.supplier?.code,
            assignment_date: a.assignment_date ? a.assignment_date.slice(0, 10) : todayStr,
            status: a.status,
          };
        }
      });
      selectedUnit.value.categories = cats;
      initSavedAndDraftState(selectedUnit.value);
    }
  } catch (e) {
    console.warn('Unit assignment sync warning:', e);
  }
};

const initSavedAndDraftState = (unit) => {
  const cats = unit.categories || {};
  const baseSaved = {
    supplier_id: cats.BASE ? cats.BASE.supplier_id : '',
    assignment_date: cats.BASE?.assignment_date ? cats.BASE.assignment_date.slice(0, 10) : todayStr,
    current: cats.BASE || null,
  };
  const weldmentSaved = {
    supplier_id: cats.WELDMENT ? cats.WELDMENT.supplier_id : '',
    assignment_date: cats.WELDMENT?.assignment_date ? cats.WELDMENT.assignment_date.slice(0, 10) : todayStr,
    current: cats.WELDMENT || null,
  };
  const childPartSaved = {
    supplier_id: cats.CHILD_PART ? cats.CHILD_PART.supplier_id : '',
    assignment_date: cats.CHILD_PART?.assignment_date ? cats.CHILD_PART.assignment_date.slice(0, 10) : todayStr,
    current: cats.CHILD_PART || null,
  };

  unitSavedState.value = {
    BASE: { ...baseSaved },
    WELDMENT: { ...weldmentSaved },
    CHILD_PART: { ...childPartSaved },
  };

  unitDraft.value = {
    BASE: { ...baseSaved },
    WELDMENT: { ...weldmentSaved },
    CHILD_PART: { ...childPartSaved },
  };
};

const resetDraftToSaved = () => {
  unitDraft.value = {
    BASE: { ...unitSavedState.value.BASE },
    WELDMENT: { ...unitSavedState.value.WELDMENT },
    CHILD_PART: { ...unitSavedState.value.CHILD_PART },
  };
};

const clearCategory = (category) => {
  unitDraft.value[category].supplier_id = '';
  unitDraft.value[category].current = null;
};

// APPLY CHANGES: Single transaction committing all draft modifications to PostgreSQL
const applyAllChanges = async () => {
  if (!hasUnsavedChanges.value) return;

  savingBulk.value = true;
  error.value = '';
  successMessage.value = '';

  const categoriesToSave = [];
  const categoriesToRemove = [];

  for (const cat of ['BASE', 'WELDMENT', 'CHILD_PART']) {
    const s = unitSavedState.value[cat];
    const d = unitDraft.value[cat];
    const sSupplier = s.supplier_id ? String(s.supplier_id) : '';
    const dSupplier = d.supplier_id ? String(d.supplier_id) : '';
    const sDate = s.assignment_date ? s.assignment_date.slice(0, 10) : todayStr;
    const dDate = d.assignment_date ? d.assignment_date.slice(0, 10) : todayStr;

    const isChanged = sSupplier !== dSupplier || sDate !== dDate;

    if (isChanged) {
      if (d.supplier_id) {
        categoriesToSave.push({
          category: cat,
          supplier_id: d.supplier_id,
          assignment_date: d.assignment_date ? d.assignment_date.slice(0, 10) : todayStr,
        });
      } else if (s.current && s.current.id) {
        categoriesToRemove.push({ category: cat, id: s.current.id });
      }
    }
  }

  try {
    // 1. Process removals if any
    for (const item of categoriesToRemove) {
      await axios.delete(`/api/v1/supplier-allocation/assignments/${item.id}`);
      if (selectedUnit.value?.categories) {
        selectedUnit.value.categories[item.category] = null;
      }
    }

    // 2. Process bulk assignments if any
    if (categoriesToSave.length > 0) {
      const res = await axios.post('/api/v1/supplier-allocation/bulk-assign', {
        project_id: selectedProjectId.value,
        jig_no: selectedJig.value.jig_no,
        unit_no: selectedUnit.value.unit_no,
        categories: categoriesToSave,
      });

      const savedList = res.data.assignments || [];
      savedList.forEach(a => {
        const cat = a.category;
        const suppObj = activeSuppliers.value.find(s => s.id == a.supplier_id) || a.supplier || {};
        const formatted = {
          id: a.id,
          supplier_id: a.supplier_id,
          supplier_name: suppObj.name || 'Assigned Supplier',
          supplier_code: suppObj.code,
          assignment_date: a.assignment_date ? a.assignment_date.slice(0, 10) : todayStr,
          status: 'active',
        };
        if (!selectedUnit.value.categories) selectedUnit.value.categories = {};
        selectedUnit.value.categories[cat] = formatted;
      });
    }

    // Update authoritative saved state & draft state from new authoritative values
    initSavedAndDraftState(selectedUnit.value);
    recalculateUnitStatus(selectedUnit.value);

    successMessage.value = 'Supplier allocation changes successfully applied.';

    // Silent background reload of hierarchy
    fetchHierarchy(true, true);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to apply supplier allocation changes.';
  } finally {
    savingBulk.value = false;
  }
};

const removeAssignment = async (assignmentId) => {
  if (!confirm('Remove this supplier assignment?')) return;

  error.value = '';
  successMessage.value = '';

  try {
    const res = await axios.delete(`/api/v1/supplier-allocation/assignments/${assignmentId}`);
    successMessage.value = res.data.message || 'Assignment removed.';

    fetchHierarchy(true, true);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to remove assignment.';
  }
};

const recalculateUnitStatus = (unit) => {
  if (!unit) return;
  const cats = unit.categories || {};
  let count = 0;
  if (cats.BASE) count++;
  if (cats.WELDMENT) count++;
  if (cats.CHILD_PART) count++;
  unit.assigned_count = count;
  unit.is_fully_assigned = count === 3;
};

// WebSocket Echo Realtime Handler
const setupEchoListener = () => {
  if (window.Echo) {
    window.Echo.channel('workflow')
      .listen('.supplier.assignment.updated', (e) => {
        if (selectedProjectId.value && e.projectId == selectedProjectId.value) {
          fetchHierarchy(true, true);
        }
      });
  }
};

onMounted(() => {
  fetchSuppliers();
  fetchHierarchy();
  setupEchoListener();
});

onUnmounted(() => {
  if (window.Echo) {
    window.Echo.leaveChannel('workflow');
  }
});
</script>

<style scoped>
.supplier-allocation-wrapper {
  font-family: inherit;
}
.cursor-pointer {
  cursor: pointer;
}

/* Distinct Card Borders & Separation */
.app-card {
  border: 1px solid #cbd5e1 !important;
  border-radius: 6px;
}
.project-card,
.jig-card,
.unit-card {
  border: 1px solid #cbd5e1 !important;
  border-radius: 6px;
  background-color: #ffffff;
  padding: 10px 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.category-card {
  border: 1px solid #cbd5e1 !important;
  border-radius: 6px;
  background-color: #ffffff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.hover-card {
  transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
}
.hover-card:hover {
  transform: translateY(-1px);
  border-color: #94a3b8 !important;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.03) !important;
}

/* Real Compact Calendar Popup Styling */
.calendar-popup-container {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 1050;
  width: 215px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
  margin-top: 3px;
}
.calendar-grid-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
}
.calendar-grid-days {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
}
.calendar-day-btn {
  height: 24px;
  font-size: 0.7rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 3px;
  line-height: 1;
}
.calendar-day-btn.disabled-day {
  cursor: not-allowed;
  background-color: #f8fafc;
  color: #94a3b8;
  opacity: 0.45;
  border: none;
}
.calendar-blank {
  height: 24px;
}

.extra-small {
  font-size: 0.72rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
.form-select-xs {
  font-size: 0.75rem;
  padding: 0.2rem 0.5rem;
}

/* Searchable Supplier Dropdown Styling */
.searchable-select-container {
  position: relative;
}
.searchable-select-trigger {
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  padding: 5px 8px;
  background-color: #ffffff;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.78rem;
  min-height: 31px;
  user-select: none;
}
.searchable-select-trigger:hover {
  border-color: #94a3b8;
}
.searchable-select-trigger.disabled-trigger {
  background-color: #f1f5f9;
  cursor: not-allowed;
  opacity: 0.75;
}
.searchable-select-menu {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  z-index: 1060;
  background-color: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
  margin-top: 2px;
}
.searchable-select-list {
  max-height: 200px;
  overflow-y: auto;
}
.searchable-select-item {
  padding: 5px 8px;
  font-size: 0.75rem;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  transition: background-color 0.1s ease;
}
.searchable-select-item:hover {
  background-color: #f1f5f9;
}
.searchable-select-item.selected {
  background-color: #e0f2fe;
  font-weight: 600;
  color: #0369a1;
}
</style>
