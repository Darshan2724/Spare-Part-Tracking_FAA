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
            <a href="#" @click.prevent="navigateWithGuard(goToJigs)" class="text-primary text-decoration-none fw-semibold">
              {{ selectedProject.project_code || selectedProject.name }}
            </a>
          </li>
          <li v-if="selectedJig" class="breadcrumb-item active text-dark fw-bold">
            JIG: {{ selectedJig.jig_no }}
          </li>
        </ol>
      </nav>
    </div>

    <!-- ========================================================================= -->
    <!-- HIERARCHY DRILL-DOWN (Projects -> Jigs -> Split Unit Workspace)            -->
    <!-- ========================================================================= -->

    <!-- Loading State -->
    <div v-if="loading && !hierarchyJigs.length" class="text-center py-4 bg-white rounded border shadow-xs app-card">
      <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
      <div class="small text-muted">Loading Supplier Allocation Hierarchy...</div>
    </div>

    <!-- LEVEL 1: PROJECT CARDS -->
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

    <!-- LEVEL 2: JIG CARDS -->
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
            @click="selectJig(jig)"
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

    <!-- ========================================================================= -->
    <!-- LEVEL 3: SPLIT UNIT WORKSPACE (Left: Units List, Right: Assignment Panel) -->
    <!-- ========================================================================= -->
    <div v-else-if="selectedJig" class="split-workspace-container">
      <!-- Top Workspace Context Bar -->
      <div class="card app-card bg-white mb-2.5">
        <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-dark fs-6 d-flex align-items-center gap-1.5">
              <span>{{ selectedProject?.project_code || selectedProject?.name }}</span>
              <span class="text-muted">&rsaquo;</span>
              <span class="text-primary">JIG {{ selectedJig.jig_no }}</span>
            </span>
            <span class="badge extra-small bg-primary-subtle text-primary border">
              {{ selectedJig.units?.length || 0 }} Units
            </span>
            <span class="badge extra-small" :class="selectedJig.allocation_pct === 100 ? 'bg-success' : 'bg-secondary'">
              {{ selectedJig.allocation_pct }}% Allocated ({{ selectedJig.assigned_slots }}/{{ selectedJig.total_slots }})
            </span>
          </div>

          <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-secondary py-1 px-2.5" @click="navigateWithGuard(goToJigs)">
              <i class="fas fa-arrow-left me-1"></i> Back to Jigs
            </button>
          </div>
        </div>
      </div>

      <!-- Two-Panel Split Layout -->
      <div class="row g-3">
        <!-- LEFT PANEL: Vertical Selectable Units List (28-30% on desktop) -->
        <div class="col-12 col-lg-4 col-xl-3">
          <div class="card app-card bg-white h-100 units-panel">
            <!-- Left Panel Header: Controls & Multi-Select Counts -->
            <div class="card-header bg-white py-2 px-2.5 border-bottom">
              <div class="d-flex justify-content-between align-items-center mb-1.5">
                <strong class="text-dark small d-flex align-items-center gap-1">
                  <i class="fas fa-layer-group text-primary"></i> Units
                </strong>
                <span class="badge bg-primary extra-small fw-semibold">
                  {{ selectedUnits.length }} Selected
                </span>
              </div>

              <!-- Multi-Unit Controls Toolbar -->
              <div class="d-flex justify-content-between align-items-center gap-1">
                <div class="d-flex align-items-center gap-1">
                  <button 
                    type="button" 
                    class="btn btn-xs btn-outline-primary py-0.5 px-1.5 fw-semibold"
                    @click="selectAllUnits"
                    :disabled="!selectedJig.units?.length"
                  >
                    Select All
                  </button>
                  <button 
                    type="button" 
                    class="btn btn-xs btn-outline-secondary py-0.5 px-1.5"
                    @click="clearUnitSelection"
                    :disabled="!selectedUnits.length"
                  >
                    Clear
                  </button>
                </div>

                <!-- Fast Filter Input -->
                <input 
                  v-model="unitSearchFilter" 
                  type="text" 
                  class="form-control form-control-sm py-0 px-1.5 extra-small" 
                  style="width: 100px; height: 24px;"
                  placeholder="Filter unit..." 
                />
              </div>
            </div>

            <!-- Left Panel Body: Scrollable Vertical List of Unit Cards -->
            <div class="card-body p-2 units-scroll-list">
              <div class="d-flex flex-column gap-1.5">
                <div 
                  v-for="unit in filteredUnitsList" 
                  :key="unit.unit_no"
                  class="unit-list-item card p-2 cursor-pointer transition-all"
                  :class="{
                    'selected-unit-card border-primary bg-primary-subtle bg-opacity-10 shadow-xs': isUnitSelected(unit.unit_no),
                    'border-success-subtle': unit.is_fully_assigned && !isUnitSelected(unit.unit_no),
                    'border-default': !isUnitSelected(unit.unit_no) && !unit.is_fully_assigned
                  }"
                  @click="handleUnitRowClick(unit, $event)"
                >
                  <!-- Top Row: Checkbox, Unit Name, Assigned Count Badge -->
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="d-flex align-items-center gap-1.5">
                      <input 
                        type="checkbox" 
                        class="form-check-input cursor-pointer m-0"
                        :checked="isUnitSelected(unit.unit_no)"
                        @click.stop="toggleUnitSelection(unit)"
                      />
                      <strong class="text-dark small d-flex align-items-center gap-1">
                        <i class="fas fa-cube text-primary extra-small"></i> Unit {{ unit.unit_no }}
                      </strong>
                    </div>

                    <span 
                      class="badge extra-small"
                      :class="unit.is_fully_assigned ? 'bg-success' : (unit.assigned_count > 0 ? 'bg-warning text-dark' : 'bg-secondary')"
                    >
                      {{ unit.assigned_count || 0 }}/3
                    </span>
                  </div>

                  <!-- Compact Inline Category Summary: BASE ✓ | WELDMENT — | CHILD PART ✓ -->
                  <div class="unit-summary-pill d-flex justify-content-between align-items-center px-1.5 py-0.5 rounded bg-light extra-small">
                    <span :class="unit.categories?.BASE ? 'text-success fw-bold' : 'text-muted'">
                      BASE {{ unit.categories?.BASE ? '✓' : '—' }}
                    </span>
                    <span class="text-muted opacity-50">&bull;</span>
                    <span :class="unit.categories?.WELDMENT ? 'text-info-emphasis fw-bold' : 'text-muted'">
                      WELD {{ unit.categories?.WELDMENT ? '✓' : '—' }}
                    </span>
                    <span class="text-muted opacity-50">&bull;</span>
                    <span :class="unit.categories?.CHILD_PART ? 'text-warning-emphasis fw-bold' : 'text-muted'">
                      CHILD {{ unit.categories?.CHILD_PART ? '✓' : '—' }}
                    </span>
                  </div>
                </div>

                <div v-if="!filteredUnitsList.length" class="text-center py-3 text-muted extra-small">
                  No units matching filter.
                </div>
              </div>
            </div>

            <!-- Left Panel Footer: Total count -->
            <div class="card-footer bg-white py-1.5 px-2.5 border-top d-flex justify-content-between align-items-center extra-small text-muted">
              <span>Total: {{ selectedJig.units?.length || 0 }} Units</span>
              <span class="fw-semibold text-primary">{{ selectedUnits.length }} selected</span>
            </div>
          </div>
        </div>

        <!-- RIGHT PANEL: Selected Unit(s) Supplier Assignment (70-72% on desktop) -->
        <div class="col-12 col-lg-8 col-xl-9">
          <!-- Empty Selection State -->
          <div v-if="!selectedUnits.length" class="card app-card bg-white p-5 text-center text-muted h-100 d-flex flex-column justify-content-center align-items-center">
            <i class="fas fa-hand-pointer fa-3x text-primary opacity-25 mb-3"></i>
            <h6 class="fw-bold text-dark">No Unit Selected</h6>
            <p class="small mb-0 text-muted" style="max-width: 380px;">
              Select a single Unit on the left to edit its supplier allocations, or select multiple Units using checkboxes to apply batch changes.
            </p>
          </div>

          <!-- Active Assignment Workspace -->
          <div v-else class="d-flex flex-column gap-2.5">
            <!-- Context Header Banner -->
            <div class="card app-card bg-white">
              <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <!-- Single Unit Context -->
                <div v-if="selectedUnits.length === 1" class="d-flex align-items-center gap-2">
                  <span class="fw-bold text-dark fs-6 d-flex align-items-center gap-1.5">
                    <i class="fas fa-cube text-primary"></i>
                    <span>Unit {{ selectedUnits[0].unit_no }}</span>
                  </span>
                  <span class="badge extra-small" :class="selectedUnits[0].is_fully_assigned ? 'bg-success' : 'bg-primary-subtle text-primary border'">
                    {{ selectedUnits[0].assigned_count || 0 }}/3 Assigned
                  </span>
                  <span v-if="hasUnsavedChanges" class="badge bg-warning text-dark extra-small">
                    <i class="fas fa-edit me-0.5"></i> Unsaved Changes
                  </span>
                </div>

                <!-- Multi-Unit Context -->
                <div v-else class="d-flex flex-column gap-1">
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary fs-7 py-1 px-2.5">
                      <i class="fas fa-check-double me-1"></i> {{ selectedUnits.length }} Units Selected
                    </span>
                    <span class="text-muted extra-small">Batch Supplier Allocation Mode</span>
                    <span v-if="hasUnsavedChanges" class="badge bg-warning text-dark extra-small">
                      <i class="fas fa-edit me-0.5"></i> Batch Changes Pending
                    </span>
                  </div>
                  <!-- Selected Units Tags List -->
                  <div class="d-flex flex-wrap gap-1 mt-1" style="max-height: 48px; overflow-y: auto;">
                    <span 
                      v-for="u in selectedUnits" 
                      :key="u.unit_no" 
                      class="badge bg-light text-dark border extra-small d-flex align-items-center gap-1"
                    >
                      Unit {{ u.unit_no }}
                      <i class="fas fa-times text-muted cursor-pointer" @click.stop="toggleUnitSelection(u)"></i>
                    </span>
                  </div>
                </div>

                <!-- Right Side Hint -->
                <div class="extra-small text-muted d-none d-md-block">
                  <span v-if="selectedUnits.length > 1">
                    <i class="fas fa-info-circle text-primary me-1"></i>Changes apply only to selected {{ selectedUnits.length }} units
                  </span>
                </div>
              </div>
            </div>

            <!-- THREE COMPACT CATEGORY CARDS (BASE, WELDMENT, CHILD PART) -->
            <div class="row g-2.5">
              <!-- 1. BASE CATEGORY CARD -->
              <div class="col-12 col-md-4">
                <div class="card category-card h-100 bg-white border-top border-3 border-success">
                  <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                      <i class="fas fa-square text-success"></i> BASE
                    </span>
                    <!-- Status Badge -->
                    <span v-if="selectedUnits.length === 1 && unitDraft.BASE.current" class="badge bg-success extra-small">
                      <i class="fas fa-check me-0.5"></i> Assigned
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.BASE.isMixed" class="badge bg-warning text-dark extra-small" :title="multiStatus.BASE.tooltip">
                      <i class="fas fa-random me-0.5"></i> Mixed
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.BASE.commonSupplier" class="badge bg-success extra-small">
                      <i class="fas fa-check me-0.5"></i> Uniform
                    </span>
                    <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                      Unassigned
                    </span>
                  </div>

                  <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                    <div>
                      <!-- Single Unit: Current Assignment Strip -->
                      <div v-if="selectedUnits.length === 1 && unitDraft.BASE.current" class="p-1.5 mb-2 bg-success-subtle bg-opacity-25 rounded border border-success-subtle d-flex justify-content-between align-items-center extra-small">
                        <div class="text-truncate" style="max-width: 170px;">
                          <strong class="text-success">{{ unitDraft.BASE.current.supplier_name }}</strong>
                          <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.BASE.current.assignment_date) }})</span>
                        </div>
                        <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('BASE')">
                          Clear
                        </button>
                      </div>

                      <!-- Multi Unit: Mixed Breakdown Hint Strip -->
                      <div v-else-if="selectedUnits.length > 1" class="p-1.5 mb-2 rounded border extra-small" :class="multiStatus.BASE.isMixed ? 'bg-warning-subtle border-warning-subtle text-dark' : 'bg-light text-muted'">
                        <div class="d-flex justify-content-between align-items-center">
                          <span class="fw-semibold">
                            {{ multiStatus.BASE.summaryText }}
                          </span>
                        </div>
                      </div>

                      <!-- Searchable Supplier Dropdown (BASE) -->
                      <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('BASE')">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5">
                          Supplier <span v-if="selectedUnits.length > 1" class="text-muted extra-small">(Set for all)</span>
                        </label>
                        <div 
                          class="searchable-select-trigger shadow-xs" 
                          :class="{ 'disabled-trigger': !canEdit }"
                          @click="canEdit && toggleSupplierDropdown('BASE')"
                        >
                          <span class="text-truncate" :class="{ 'text-muted': !unitDraft.BASE.supplier_id && !multiStatus.BASE.commonSupplier }">
                            {{ getDropdownDisplayValue('BASE') }}
                          </span>
                          <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                        </div>

                        <!-- Dropdown Menu -->
                        <div v-if="activeSupplierDropdown === 'BASE'" class="searchable-select-menu shadow-lg">
                          <div class="p-1.5 border-bottom bg-light">
                            <input 
                              :ref="el => { if (el) el.focus() }"
                              v-model="supplierSearch.BASE" 
                              type="text" 
                              class="form-control form-control-sm extra-small" 
                              placeholder="Type to search active suppliers..." 
                              @click.stop
                              @keydown.esc="closeSupplierDropdown('BASE')"
                            />
                          </div>
                          <div class="searchable-select-list">
                            <div 
                              class="searchable-select-item text-danger border-bottom"
                              :class="{ selected: unitDraft.BASE.supplier_id === 'CLEAR' }"
                              @click="selectSupplier('BASE', 'CLEAR')"
                            >
                              <span><i class="fas fa-times me-1"></i> -- Remove / Clear Assignment --</span>
                            </div>
                            <div 
                              v-for="s in getFilteredSuppliers('BASE')" 
                              :key="s.id" 
                              class="searchable-select-item"
                              :class="{ selected: unitDraft.BASE.supplier_id == s.id }"
                              @click="selectSupplier('BASE', s.id)"
                            >
                              <div class="d-flex flex-column text-truncate">
                                <span class="fw-bold text-dark text-truncate">{{ s.name }}</span>
                                <span class="extra-small text-muted">{{ s.code || 'SUP-' + s.id }} &bull; {{ s.city || 'Maharashtra' }}</span>
                              </div>
                              <span 
                                class="badge extra-small ms-1"
                                :class="{
                                  'bg-danger-subtle text-danger border border-danger-subtle': s.load_status === 'High Load',
                                  'bg-warning-subtle text-dark border border-warning-subtle': s.load_status === 'Medium Load',
                                  'bg-success-subtle text-success border border-success-subtle': s.load_status === 'Low Load'
                                }"
                                :title="`Current active load: ${s.total_assignments || 0} assignments (${s.load_pct || 0}%)`"
                              >
                                {{ s.load_status || 'Low Load' }}
                              </span>
                            </div>
                            <div v-if="!getFilteredSuppliers('BASE').length" class="p-2 text-center text-muted extra-small">
                              No matching active suppliers found
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- Date Picker Input & Calendar Popup (BASE) -->
                      <div class="mb-1 position-relative">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                          <span>Assignment Date</span>
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

                        <!-- Real Calendar Popup -->
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

              <!-- 2. WELDMENT CATEGORY CARD -->
              <div class="col-12 col-md-4">
                <div class="card category-card h-100 bg-white border-top border-3 border-info">
                  <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                      <i class="fas fa-cog text-info"></i> WELDMENT
                    </span>
                    <!-- Status Badge -->
                    <span v-if="selectedUnits.length === 1 && unitDraft.WELDMENT.current" class="badge bg-info text-dark extra-small">
                      <i class="fas fa-check me-0.5"></i> Assigned
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.WELDMENT.isMixed" class="badge bg-warning text-dark extra-small" :title="multiStatus.WELDMENT.tooltip">
                      <i class="fas fa-random me-0.5"></i> Mixed
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.WELDMENT.commonSupplier" class="badge bg-success extra-small">
                      <i class="fas fa-check me-0.5"></i> Uniform
                    </span>
                    <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                      Unassigned
                    </span>
                  </div>

                  <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                    <div>
                      <!-- Single Unit: Current Assignment Strip -->
                      <div v-if="selectedUnits.length === 1 && unitDraft.WELDMENT.current" class="p-1.5 mb-2 bg-info-subtle bg-opacity-25 rounded border border-info-subtle d-flex justify-content-between align-items-center extra-small">
                        <div class="text-truncate" style="max-width: 170px;">
                          <strong class="text-info-emphasis">{{ unitDraft.WELDMENT.current.supplier_name }}</strong>
                          <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.WELDMENT.current.assignment_date) }})</span>
                        </div>
                        <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('WELDMENT')">
                          Clear
                        </button>
                      </div>

                      <!-- Multi Unit: Mixed Breakdown Hint Strip -->
                      <div v-else-if="selectedUnits.length > 1" class="p-1.5 mb-2 rounded border extra-small" :class="multiStatus.WELDMENT.isMixed ? 'bg-warning-subtle border-warning-subtle text-dark' : 'bg-light text-muted'">
                        <div class="d-flex justify-content-between align-items-center">
                          <span class="fw-semibold">
                            {{ multiStatus.WELDMENT.summaryText }}
                          </span>
                        </div>
                      </div>

                      <!-- Searchable Supplier Dropdown (WELDMENT) -->
                      <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('WELDMENT')">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5">
                          Supplier <span v-if="selectedUnits.length > 1" class="text-muted extra-small">(Set for all)</span>
                        </label>
                        <div 
                          class="searchable-select-trigger shadow-xs" 
                          :class="{ 'disabled-trigger': !canEdit }"
                          @click="canEdit && toggleSupplierDropdown('WELDMENT')"
                        >
                          <span class="text-truncate" :class="{ 'text-muted': !unitDraft.WELDMENT.supplier_id && !multiStatus.WELDMENT.commonSupplier }">
                            {{ getDropdownDisplayValue('WELDMENT') }}
                          </span>
                          <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                        </div>

                        <!-- Dropdown Menu -->
                        <div v-if="activeSupplierDropdown === 'WELDMENT'" class="searchable-select-menu shadow-lg">
                          <div class="p-1.5 border-bottom bg-light">
                            <input 
                              :ref="el => { if (el) el.focus() }"
                              v-model="supplierSearch.WELDMENT" 
                              type="text" 
                              class="form-control form-control-sm extra-small" 
                              placeholder="Type to search active suppliers..." 
                              @click.stop
                              @keydown.esc="closeSupplierDropdown('WELDMENT')"
                            />
                          </div>
                          <div class="searchable-select-list">
                            <div 
                              class="searchable-select-item text-danger border-bottom"
                              :class="{ selected: unitDraft.WELDMENT.supplier_id === 'CLEAR' }"
                              @click="selectSupplier('WELDMENT', 'CLEAR')"
                            >
                              <span><i class="fas fa-times me-1"></i> -- Remove / Clear Assignment --</span>
                            </div>
                            <div 
                              v-for="s in getFilteredSuppliers('WELDMENT')" 
                              :key="s.id" 
                              class="searchable-select-item"
                              :class="{ selected: unitDraft.WELDMENT.supplier_id == s.id }"
                              @click="selectSupplier('WELDMENT', s.id)"
                            >
                              <div class="d-flex flex-column text-truncate">
                                <span class="fw-bold text-dark text-truncate">{{ s.name }}</span>
                                <span class="extra-small text-muted">{{ s.code || 'SUP-' + s.id }} &bull; {{ s.city || 'Maharashtra' }}</span>
                              </div>
                              <span 
                                class="badge extra-small ms-1"
                                :class="{
                                  'bg-danger-subtle text-danger border border-danger-subtle': s.load_status === 'High Load',
                                  'bg-warning-subtle text-dark border border-warning-subtle': s.load_status === 'Medium Load',
                                  'bg-success-subtle text-success border border-success-subtle': s.load_status === 'Low Load'
                                }"
                                :title="`Current active load: ${s.total_assignments || 0} assignments (${s.load_pct || 0}%)`"
                              >
                                {{ s.load_status || 'Low Load' }}
                              </span>
                            </div>
                            <div v-if="!getFilteredSuppliers('WELDMENT').length" class="p-2 text-center text-muted extra-small">
                              No matching active suppliers found
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- Date Picker Input & Calendar Popup (WELDMENT) -->
                      <div class="mb-1 position-relative">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                          <span>Assignment Date</span>
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

                        <!-- Real Calendar Popup -->
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

              <!-- 3. CHILD PART CATEGORY CARD -->
              <div class="col-12 col-md-4">
                <div class="card category-card h-100 bg-white border-top border-3 border-warning">
                  <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark small d-flex align-items-center gap-1.5">
                      <i class="fas fa-puzzle-piece text-warning"></i> CHILD PART
                    </span>
                    <!-- Status Badge -->
                    <span v-if="selectedUnits.length === 1 && unitDraft.CHILD_PART.current" class="badge bg-warning text-dark extra-small">
                      <i class="fas fa-check me-0.5"></i> Assigned
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.CHILD_PART.isMixed" class="badge bg-warning text-dark extra-small" :title="multiStatus.CHILD_PART.tooltip">
                      <i class="fas fa-random me-0.5"></i> Mixed
                    </span>
                    <span v-else-if="selectedUnits.length > 1 && multiStatus.CHILD_PART.commonSupplier" class="badge bg-success extra-small">
                      <i class="fas fa-check me-0.5"></i> Uniform
                    </span>
                    <span v-else class="badge bg-secondary-subtle text-secondary border extra-small">
                      Unassigned
                    </span>
                  </div>

                  <div class="card-body p-2.5 d-flex flex-column justify-content-between">
                    <div>
                      <!-- Single Unit: Current Assignment Strip -->
                      <div v-if="selectedUnits.length === 1 && unitDraft.CHILD_PART.current" class="p-1.5 mb-2 bg-warning-subtle bg-opacity-25 rounded border border-warning-subtle d-flex justify-content-between align-items-center extra-small">
                        <div class="text-truncate" style="max-width: 170px;">
                          <strong class="text-warning-emphasis">{{ unitDraft.CHILD_PART.current.supplier_name }}</strong>
                          <span class="text-muted ms-1">({{ formatDisplayDate(unitDraft.CHILD_PART.current.assignment_date) }})</span>
                        </div>
                        <button v-if="canEdit" class="btn btn-link text-danger p-0 extra-small text-decoration-none" title="Clear Assignment" @click="clearCategory('CHILD_PART')">
                          Clear
                        </button>
                      </div>

                      <!-- Multi Unit: Mixed Breakdown Hint Strip -->
                      <div v-else-if="selectedUnits.length > 1" class="p-1.5 mb-2 rounded border extra-small" :class="multiStatus.CHILD_PART.isMixed ? 'bg-warning-subtle border-warning-subtle text-dark' : 'bg-light text-muted'">
                        <div class="d-flex justify-content-between align-items-center">
                          <span class="fw-semibold">
                            {{ multiStatus.CHILD_PART.summaryText }}
                          </span>
                        </div>
                      </div>

                      <!-- Searchable Supplier Dropdown (CHILD PART) -->
                      <div class="mb-2 searchable-select-container" v-click-outside="() => closeSupplierDropdown('CHILD_PART')">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5">
                          Supplier <span v-if="selectedUnits.length > 1" class="text-muted extra-small">(Set for all)</span>
                        </label>
                        <div 
                          class="searchable-select-trigger shadow-xs" 
                          :class="{ 'disabled-trigger': !canEdit }"
                          @click="canEdit && toggleSupplierDropdown('CHILD_PART')"
                        >
                          <span class="text-truncate" :class="{ 'text-muted': !unitDraft.CHILD_PART.supplier_id && !multiStatus.CHILD_PART.commonSupplier }">
                            {{ getDropdownDisplayValue('CHILD_PART') }}
                          </span>
                          <i class="fas fa-chevron-down extra-small text-secondary ms-1"></i>
                        </div>

                        <!-- Dropdown Menu -->
                        <div v-if="activeSupplierDropdown === 'CHILD_PART'" class="searchable-select-menu shadow-lg">
                          <div class="p-1.5 border-bottom bg-light">
                            <input 
                              :ref="el => { if (el) el.focus() }"
                              v-model="supplierSearch.CHILD_PART" 
                              type="text" 
                              class="form-control form-control-sm extra-small" 
                              placeholder="Type to search active suppliers..." 
                              @click.stop
                              @keydown.esc="closeSupplierDropdown('CHILD_PART')"
                            />
                          </div>
                          <div class="searchable-select-list">
                            <div 
                              class="searchable-select-item text-danger border-bottom"
                              :class="{ selected: unitDraft.CHILD_PART.supplier_id === 'CLEAR' }"
                              @click="selectSupplier('CHILD_PART', 'CLEAR')"
                            >
                              <span><i class="fas fa-times me-1"></i> -- Remove / Clear Assignment --</span>
                            </div>
                            <div 
                              v-for="s in getFilteredSuppliers('CHILD_PART')" 
                              :key="s.id" 
                              class="searchable-select-item"
                              :class="{ selected: unitDraft.CHILD_PART.supplier_id == s.id }"
                              @click="selectSupplier('CHILD_PART', s.id)"
                            >
                              <div class="d-flex flex-column text-truncate">
                                <span class="fw-bold text-dark text-truncate">{{ s.name }}</span>
                                <span class="extra-small text-muted">{{ s.code || 'SUP-' + s.id }} &bull; {{ s.city || 'Maharashtra' }}</span>
                              </div>
                              <span 
                                class="badge extra-small ms-1"
                                :class="{
                                  'bg-danger-subtle text-danger border border-danger-subtle': s.load_status === 'High Load',
                                  'bg-warning-subtle text-dark border border-warning-subtle': s.load_status === 'Medium Load',
                                  'bg-success-subtle text-success border border-success-subtle': s.load_status === 'Low Load'
                                }"
                                :title="`Current active load: ${s.total_assignments || 0} assignments (${s.load_pct || 0}%)`"
                              >
                                {{ s.load_status || 'Low Load' }}
                              </span>
                            </div>
                            <div v-if="!getFilteredSuppliers('CHILD_PART').length" class="p-2 text-center text-muted extra-small">
                              No matching active suppliers found
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- Date Picker Input & Calendar Popup (CHILD PART) -->
                      <div class="mb-1 position-relative">
                        <label class="form-label extra-small fw-semibold text-dark mb-0.5 d-flex justify-content-between">
                          <span>Assignment Date</span>
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

                        <!-- Real Calendar Popup -->
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

            <!-- BOTTOM ACTION BAR: APPLY CHANGES -->
            <div class="card app-card bg-white shadow-xs">
              <div class="card-body py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                  <span v-if="hasUnsavedChanges" class="badge bg-warning text-dark py-1.5 px-2.5 fs-7 d-flex align-items-center gap-1 shadow-xs">
                    <i class="fas fa-exclamation-circle"></i>
                    <span v-if="selectedUnits.length === 1">Unsaved changes pending for Unit {{ selectedUnits[0].unit_no }}</span>
                    <span v-else>Changes ready to apply to {{ selectedUnits.length }} selected units</span>
                  </span>
                  <span v-else class="text-muted small d-flex align-items-center gap-1">
                    <i class="fas fa-check-circle text-success"></i>
                    <span>All category allocations in sync with server</span>
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
                    @click="handleApplyChangesClick"
                  >
                    <span v-if="savingBulk" class="spinner-border spinner-border-sm me-1.5"></span>
                    <i v-else class="fas fa-check me-1.5"></i> 
                    {{ selectedUnits.length > 1 ? `Apply to ${selectedUnits.length} Units` : 'Apply Changes' }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CONFIRMATION MODAL FOR MULTI-UNIT BATCH ASSIGNMENT                       -->
    <!-- ========================================================================= -->
    <div v-if="showMultiConfirmModal" class="modal-backdrop fade show"></div>
    <div v-if="showMultiConfirmModal" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-primary text-white py-2.5 px-3">
            <h6 class="modal-title fw-bold">
              <i class="fas fa-check-double me-1.5"></i> Confirm Multi-Unit Assignment
            </h6>
            <button type="button" class="btn-close btn-close-white" @click="showMultiConfirmModal = false"></button>
          </div>

          <div class="modal-body p-3">
            <div class="alert alert-info py-2 px-2.5 small mb-3">
              <strong>Applying updates to {{ selectedUnits.length }} Units in JIG {{ selectedJig.jig_no }}:</strong>
              <div class="text-truncate mt-1 extra-small text-muted">
                {{ selectedUnits.map(u => 'Unit ' + u.unit_no).join(', ') }}
              </div>
            </div>

            <h6 class="fw-bold small mb-2 text-dark">Category Updates Summary:</h6>
            <div class="d-flex flex-column gap-1.5 small mb-3">
              <div v-for="cat in ['BASE', 'WELDMENT', 'CHILD_PART']" :key="cat" class="p-2 rounded border bg-light d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark">{{ cat }}:</span>
                <span v-if="unitDraft[cat].supplier_id === 'CLEAR'" class="badge bg-danger">
                  Will Remove / Clear
                </span>
                <span v-else-if="unitDraft[cat].supplier_id" class="text-success fw-bold">
                  {{ getSupplierNameById(unitDraft[cat].supplier_id) }} ({{ formatDisplayDate(unitDraft[cat].assignment_date) }})
                </span>
                <span v-else class="text-muted fst-italic">
                  No Change (Preserve per-unit assignments)
                </span>
              </div>
            </div>

            <div v-if="hasMixedOverwrites" class="alert alert-warning py-2 px-2.5 extra-small mb-0">
              <i class="fas fa-exclamation-triangle me-1"></i>
              <strong>Notice:</strong> One or more selected units have existing assignments that will be overwritten with the new selection.
            </div>
          </div>

          <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="showMultiConfirmModal = false" :disabled="savingBulk">
              Cancel
            </button>
            <button type="button" class="btn btn-sm btn-primary fw-bold px-3" @click="executeMultiUnitAssign" :disabled="savingBulk">
              <span v-if="savingBulk" class="spinner-border spinner-border-sm me-1.5"></span>
              <i v-else class="fas fa-check me-1"></i> Confirm &amp; Apply Changes
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

// Click-outside directive for calendar & dropdown popups
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
const hierarchyJigs = ref([]);

// Multi-Unit Selection State
const selectedUnits = ref([]); // Array of unit objects
const unitSearchFilter = ref('');
const showMultiConfirmModal = ref(false);

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
const calendarMonth = ref(today.getMonth());

// Searchable Supplier Dropdown State
const activeSupplierDropdown = ref(null);
const supplierSearch = ref({ BASE: '', WELDMENT: '', CHILD_PART: '' });

// Authoritative Saved State vs Editable Draft State
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

// Computed: Filtered Units in Left Panel
const filteredUnitsList = computed(() => {
  if (!selectedJig.value?.units) return [];
  const q = unitSearchFilter.value.trim().toLowerCase();
  if (!q) return selectedJig.value.units;
  return selectedJig.value.units.filter(u => String(u.unit_no).toLowerCase().includes(q));
});

// Computed: Selected Project
const selectedProject = computed(() => {
  return projects.value.find(p => p.id == selectedProjectId.value) || null;
});

// Helper: Check if Unit is in Selected Set
const isUnitSelected = (unitNo) => {
  return selectedUnits.value.some(u => u.unit_no === unitNo);
};

// Toggle Unit Selection Checkbox
const toggleUnitSelection = (unit) => {
  const idx = selectedUnits.value.findIndex(u => u.unit_no === unit.unit_no);
  if (idx >= 0) {
    selectedUnits.value.splice(idx, 1);
  } else {
    selectedUnits.value.push(unit);
  }
  syncDraftStateWithSelection();
};

// Unit Row Click: Single click selects that single unit (or toggles if Shift/Ctrl)
const handleUnitRowClick = (unit, event) => {
  if (event.ctrlKey || event.metaKey) {
    toggleUnitSelection(unit);
  } else {
    selectedUnits.value = [unit];
    syncDraftStateWithSelection();
  }
};

// Select All Units in current Jig
const selectAllUnits = () => {
  if (!selectedJig.value?.units) return;
  selectedUnits.value = [...selectedJig.value.units];
  syncDraftStateWithSelection();
};

// Clear Unit Selection
const clearUnitSelection = () => {
  selectedUnits.value = [];
  resetDraftToSaved();
};

// Sync draft state with selected units
const syncDraftStateWithSelection = () => {
  if (selectedUnits.value.length === 1) {
    initSavedAndDraftState(selectedUnits.value[0]);
  } else if (selectedUnits.value.length > 1) {
    // Multi-unit mode: reset explicit overrides
    unitDraft.value = {
      BASE: { supplier_id: '', assignment_date: todayStr, current: null },
      WELDMENT: { supplier_id: '', assignment_date: todayStr, current: null },
      CHILD_PART: { supplier_id: '', assignment_date: todayStr, current: null },
    };
    unitSavedState.value = {
      BASE: { supplier_id: '', assignment_date: todayStr, current: null },
      WELDMENT: { supplier_id: '', assignment_date: todayStr, current: null },
      CHILD_PART: { supplier_id: '', assignment_date: todayStr, current: null },
    };
  }
};

// Computed: Multi-unit assignment status (Detects mixed vs uniform per category)
const multiStatus = computed(() => {
  const result = {
    BASE: { isMixed: false, commonSupplier: null, summaryText: '', tooltip: '' },
    WELDMENT: { isMixed: false, commonSupplier: null, summaryText: '', tooltip: '' },
    CHILD_PART: { isMixed: false, commonSupplier: null, summaryText: '', tooltip: '' },
  };

  if (selectedUnits.value.length <= 1) return result;

  for (const cat of ['BASE', 'WELDMENT', 'CHILD_PART']) {
    const supplierMap = new Map();
    let unassignedCount = 0;

    selectedUnits.value.forEach(u => {
      const assign = u.categories?.[cat];
      if (assign && assign.supplier_id) {
        const count = supplierMap.get(assign.supplier_id) || { name: assign.supplier_name, count: 0 };
        count.count++;
        supplierMap.set(assign.supplier_id, count);
      } else {
        unassignedCount++;
      }
    });

    const uniqueCount = supplierMap.size;

    if (uniqueCount === 1 && unassignedCount === 0) {
      // All have same supplier
      const [suppId, data] = Array.from(supplierMap.entries())[0];
      result[cat].commonSupplier = { id: suppId, name: data.name };
      result[cat].isMixed = false;
      result[cat].summaryText = `All ${selectedUnits.value.length} units assigned to ${data.name}`;
    } else if (uniqueCount === 0) {
      // All unassigned
      result[cat].commonSupplier = null;
      result[cat].isMixed = false;
      result[cat].summaryText = `All ${selectedUnits.value.length} units currently unassigned`;
    } else {
      // Mixed state
      result[cat].isMixed = true;
      const breakdown = Array.from(supplierMap.values()).map(v => `${v.name} (${v.count})`).join(', ');
      const unassignedText = unassignedCount > 0 ? `, Unassigned (${unassignedCount})` : '';
      result[cat].summaryText = `Mixed: ${breakdown}${unassignedText}`;
      result[cat].tooltip = result[cat].summaryText;
    }
  }

  return result;
});

// Computed: Detect whether any draft modifications exist
const hasUnsavedChanges = computed(() => {
  if (!selectedUnits.value.length) return false;

  if (selectedUnits.value.length === 1) {
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
  } else {
    // Multi-unit mode: any explicit category choice is an unsaved change
    return ['BASE', 'WELDMENT', 'CHILD_PART'].some(cat => !!unitDraft.value[cat].supplier_id);
  }
});

// Check if multi-unit changes will overwrite existing assignments
const hasMixedOverwrites = computed(() => {
  if (selectedUnits.value.length <= 1) return false;
  for (const cat of ['BASE', 'WELDMENT', 'CHILD_PART']) {
    if (unitDraft.value[cat].supplier_id && unitDraft.value[cat].supplier_id !== 'CLEAR') {
      const hasAnyExisting = selectedUnits.value.some(u => u.categories?.[cat]);
      if (hasAnyExisting) return true;
    }
  }
  return false;
});

// Dropdown Display Value helper
const getDropdownDisplayValue = (category) => {
  const overrideId = unitDraft.value[category].supplier_id;
  if (overrideId === 'CLEAR') {
    return '-- Remove Assignment --';
  }
  if (overrideId) {
    const s = activeSuppliers.value.find(s => s.id == overrideId);
    return s ? `${s.name} (${s.code || 'SUP'})` : 'Selected Supplier';
  }

  if (selectedUnits.value.length === 1) {
    return '-- Select Supplier --';
  }

  // Multi-unit mode
  if (multiStatus.value[category].commonSupplier) {
    return `${multiStatus.value[category].commonSupplier.name} (Uniform)`;
  }
  if (multiStatus.value[category].isMixed) {
    return 'Mixed (Click to Overwrite)';
  }
  return '-- Select Supplier (Apply to all) --';
};

const getSupplierNameById = (id) => {
  const found = activeSuppliers.value.find(s => s.id == id);
  return found ? found.name : `Supplier #${id}`;
};

// Filter suppliers with Prefix-Match Ranking
const getFilteredSuppliers = (category) => {
  const q = (supplierSearch.value[category] || '').trim().toLowerCase();
  if (!q) {
    return activeSuppliers.value;
  }

  const exactMatches = [];
  const prefixMatches = [];
  const wordStartMatches = [];
  const containsMatches = [];

  for (const s of activeSuppliers.value) {
    const name = (s.name || '').toLowerCase();
    const code = (s.code || '').toLowerCase();

    if (name === q || code === q) {
      exactMatches.push(s);
    } else if (name.startsWith(q) || code.startsWith(q)) {
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

  return [...exactMatches, ...prefixMatches, ...wordStartMatches, ...containsMatches];
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

// Navigation Guards
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
  selectedUnits.value = [];
};

const goToJigs = () => {
  selectedJig.value = null;
  selectedUnits.value = [];
};

const selectProject = (projectId) => {
  selectedProjectId.value = projectId;
  selectedJig.value = null;
  selectedUnits.value = [];
  fetchHierarchy();
};

const selectJig = (jig) => {
  selectedJig.value = jig;
  // Automatically select the first unit if available
  if (jig.units && jig.units.length > 0) {
    selectedUnits.value = [jig.units[0]];
    initSavedAndDraftState(jig.units[0]);
  } else {
    selectedUnits.value = [];
  }
};

// Dropdown & Calendar toggles
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

const clearCategory = (category) => {
  unitDraft.value[category].supplier_id = 'CLEAR';
  unitDraft.value[category].current = null;
};

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

// Calendar Computeds
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

// Single-Unit state initialization
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

// Main Action Handler: Triggers save (or confirmation modal for multi-unit)
const handleApplyChangesClick = () => {
  if (selectedUnits.value.length > 1) {
    showMultiConfirmModal.value = true;
  } else {
    applySingleUnitChanges();
  }
};

// APPLY CHANGES: Single Unit Flow
const applySingleUnitChanges = async () => {
  if (!hasUnsavedChanges.value || !selectedUnits.value.length) return;

  const unit = selectedUnits.value[0];
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
      if (d.supplier_id && d.supplier_id !== 'CLEAR') {
        categoriesToSave.push({
          category: cat,
          supplier_id: d.supplier_id,
          assignment_date: d.assignment_date ? d.assignment_date.slice(0, 10) : todayStr,
        });
      } else if (d.supplier_id === 'CLEAR' || (!d.supplier_id && s.current?.id)) {
        if (s.current?.id) {
          categoriesToRemove.push({ category: cat, id: s.current.id });
        }
      }
    }
  }

  try {
    // 1. Process removals
    for (const item of categoriesToRemove) {
      await axios.delete(`/api/v1/supplier-allocation/assignments/${item.id}`);
      if (unit.categories) {
        unit.categories[item.category] = null;
      }
    }

    // 2. Process bulk assignments
    if (categoriesToSave.length > 0) {
      const res = await axios.post('/api/v1/supplier-allocation/bulk-assign', {
        project_id: selectedProjectId.value,
        jig_no: selectedJig.value.jig_no,
        unit_no: unit.unit_no,
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
        if (!unit.categories) unit.categories = {};
        unit.categories[cat] = formatted;
      });
    }

    initSavedAndDraftState(unit);
    recalculateUnitStatus(unit);

    successMessage.value = `Supplier allocation successfully updated for Unit ${unit.unit_no}.`;
    fetchHierarchy(true, true);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to apply supplier allocation changes.';
  } finally {
    savingBulk.value = false;
  }
};

// APPLY CHANGES: Multi-Unit Batch Flow (Single Atomic Backend Transaction)
const executeMultiUnitAssign = async () => {
  savingBulk.value = true;
  error.value = '';
  successMessage.value = '';

  const unitsPayload = [];

  selectedUnits.value.forEach(u => {
    const catsToApply = [];

    for (const cat of ['BASE', 'WELDMENT', 'CHILD_PART']) {
      const overrideSupplier = unitDraft.value[cat].supplier_id;
      const overrideDate = unitDraft.value[cat].assignment_date || todayStr;

      if (overrideSupplier && overrideSupplier !== 'CLEAR') {
        catsToApply.push({
          category: cat,
          supplier_id: overrideSupplier,
          assignment_date: overrideDate,
        });
      }
    }

    if (catsToApply.length > 0) {
      unitsPayload.push({
        unit_no: u.unit_no,
        categories: catsToApply,
      });
    }
  });

  try {
    if (unitsPayload.length > 0) {
      const res = await axios.post('/api/v1/supplier-allocation/multi-unit-assign', {
        project_id: selectedProjectId.value,
        jig_no: selectedJig.value.jig_no,
        units: unitsPayload,
      });

      successMessage.value = res.data.message || `Successfully applied supplier updates to ${selectedUnits.value.length} units.`;
    }

    showMultiConfirmModal.value = false;
    await fetchHierarchy(true, true);
    syncDraftStateWithSelection();
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to execute multi-unit assignment.';
  } finally {
    savingBulk.value = false;
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

// Fetchers
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
          // Refresh selected unit references
          if (selectedUnits.value.length > 0) {
            const updatedSelection = [];
            selectedUnits.value.forEach(u => {
              const matched = foundJig.units.find(ju => ju.unit_no === u.unit_no);
              if (matched) updatedSelection.push(matched);
            });
            selectedUnits.value = updatedSelection;
            if (selectedUnits.value.length === 1) {
              initSavedAndDraftState(selectedUnits.value[0]);
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

// Echo Realtime Listeners
const setupEchoListener = () => {
  if (window.Echo) {
    window.Echo.channel('workflow')
      .listen('.supplier.assignment.updated', (e) => {
        if (selectedProjectId.value && e.projectId == selectedProjectId.value) {
          fetchHierarchy(true, true);
          fetchSuppliers();
        }
      })
      .listen('.supplier.deactivated', () => {
        fetchSuppliers();
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

/* Split Workspace Panel Styling */
.units-panel {
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 220px);
}
.units-scroll-list {
  overflow-y: auto;
  max-height: calc(100vh - 330px);
}
.unit-list-item {
  border: 1px solid #e2e8f0;
  border-radius: 5px;
  background-color: #ffffff;
  transition: all 0.15s ease;
}
.unit-list-item:hover {
  border-color: #94a3b8;
  background-color: #f8fafc;
}
.selected-unit-card {
  border-color: #3b82f6 !important;
  background-color: #eff6ff !important;
}
.unit-summary-pill {
  font-size: 0.68rem;
}

/* Searchable Supplier Dropdown Styling with Portal Z-Index */
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
  z-index: 9999;
  background-color: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
  margin-top: 2px;
}
.searchable-select-list {
  max-height: 280px;
  overflow-y: auto;
}
.searchable-select-item {
  padding: 6px 10px;
  font-size: 0.75rem;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #f1f5f9;
  transition: background-color 0.1s ease;
}
.searchable-select-item:hover {
  background-color: #f8fafc;
}
.searchable-select-item.selected {
  background-color: #e0f2fe;
  font-weight: 600;
  color: #0369a1;
}

/* Calendar Popup Styling */
.calendar-popup-container {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 9999;
  width: 215px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
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
.transition-all {
  transition: all 0.15s ease;
}
</style>
