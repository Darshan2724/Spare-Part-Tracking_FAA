<template>
  <div class="p-3 p-md-4" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="container-fluid p-0">
      
      <!-- Topbar Header -->
      <div class="py-3 px-4 bg-white border-bottom shadow-sm rounded mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-3">
          <div class="p-2.5 rounded-3 d-flex align-items-center justify-content-center" style="background-color: rgba(239, 68, 68, 0.12); color: #ef4444; width: 44px; height: 44px;">
            <i class="fas fa-trash-alt fa-lg"></i>
          </div>
          <div>
            <h4 class="mb-0 fw-bold text-dark">Pending Part Deletion</h4>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button 
            @click="fetchEligibleParts" 
            class="btn btn-outline-secondary btn-sm text-nowrap" 
            :disabled="loadingParts || !selectedProject || !selectedBomType"
          >
            <i class="fas fa-sync-alt me-1" :class="{ 'fa-spin': loadingParts }"></i> Refresh List
          </button>
        </div>
      </div>

      <!-- Informational Safeguard Notice -->
      <div class="alert alert-light border border-secondary border-opacity-25 shadow-xs mb-4 p-3 rounded-3">
        <div class="d-flex align-items-start gap-3">
          <div class="text-warning mt-0.5">
            <i class="fas fa-info-circle fa-lg"></i>
          </div>
          <div class="small text-secondary flex-grow-1">
            <span class="fw-bold text-dark">Strict MES Production Rule:</span>
            Only parts that are in <strong>Pending</strong> state and have <strong>zero downstream operational records</strong> (no Store Receipts, QC Inspections, Rework, Paint, Assembly, or Purchase Queue items) can be deleted or decremented. Parts that have entered processing must be handled through standard department workflows or reverse pipelines.
          </div>
        </div>
      </div>

      <!-- Flash Messages -->
      <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show shadow-sm py-2 px-3 mb-3 d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-circle me-2 fs-5"></i>
        <div class="small flex-grow-1">{{ errorMessage }}</div>
        <button type="button" class="btn-close py-2" @click="errorMessage = ''" aria-label="Close"></button>
      </div>

      <div v-if="successMessage" class="alert alert-success alert-dismissible fade show shadow-sm py-2 px-3 mb-3 d-flex align-items-center" role="alert">
        <i class="fas fa-check-circle me-2 fs-5"></i>
        <div class="small flex-grow-1">{{ successMessage }}</div>
        <button type="button" class="btn-close py-2" @click="successMessage = ''" aria-label="Close"></button>
      </div>

      <!-- Progressive Cascade Filter Card -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2.5 px-3">
          <div class="d-flex align-items-center justify-content-between">
            <span class="fw-bold small text-uppercase text-muted">
              <i class="fas fa-filter me-1.5 text-primary"></i>Progressive Hierarchy Selection
            </span>
            <button 
              @click="resetAllFilters" 
              class="btn btn-link text-decoration-none btn-sm py-0 px-1 text-muted extra-small"
            >
              <i class="fas fa-undo me-1"></i>Reset All
            </button>
          </div>
        </div>
        <div class="card-body p-3 bg-light rounded-bottom">
          <div class="row g-2 align-items-end">
            
            <!-- Step 1: Project Dropdown -->
            <div class="col-12 col-md-3">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                1. Project <span class="text-danger">*</span>
              </label>
              <select 
                v-model="selectedProject" 
                @change="onProjectChange" 
                class="form-select form-select-sm"
                :disabled="loadingProjects"
              >
                <option :value="null">-- Select Project --</option>
                <option v-for="proj in projects" :key="proj.id" :value="proj.id">
                  {{ proj.project_code }} - {{ proj.name }}
                </option>
              </select>
            </div>

            <!-- Step 2: BOM Type Selector -->
            <div class="col-12 col-md-3">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                2. BOM Type <span class="text-danger">*</span>
              </label>
              <select 
                v-model="selectedBomType" 
                @change="onBomTypeChange" 
                class="form-select form-select-sm"
                :disabled="!selectedProject"
              >
                <option value="">-- Select Type --</option>
                <option value="MFG">MFG (Manufactured / In-House)</option>
                <option value="BOP">BOP (Bought Out Parts)</option>
                <option value="STD">STD (Standard Hardware)</option>
                <option value="ECN">ECN (Engineering Change Notice)</option>
              </select>
            </div>

            <!-- Step 3: Jig Dropdown -->
            <div class="col-6 col-md-2">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                3. Jig (Optional)
              </label>
              <select 
                v-model="selectedJig" 
                @change="onJigChange" 
                class="form-select form-select-sm"
                :disabled="!selectedProject || !selectedBomType || loadingJigs"
              >
                <option value="">All Jigs</option>
                <option v-for="jig in jigs" :key="jig" :value="jig">
                  {{ jig }}
                </option>
              </select>
            </div>

            <!-- Step 4: Unit Dropdown -->
            <div class="col-6 col-md-2">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                4. Unit (Optional)
              </label>
              <select 
                v-model="selectedUnit" 
                @change="onUnitChange" 
                class="form-select form-select-sm"
                :disabled="!selectedJig || loadingUnits"
              >
                <option value="">All Units</option>
                <option v-for="unit in units" :key="unit" :value="unit">
                  {{ unit }}
                </option>
              </select>
            </div>

            <!-- Step 5: Side Dropdown -->
            <div class="col-6 col-md-2">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                5. Side (Optional)
              </label>
              <select 
                v-model="selectedSide" 
                @change="fetchEligibleParts" 
                class="form-select form-select-sm"
                :disabled="!selectedUnit || loadingSides"
              >
                <option value="">All Sides</option>
                <option v-for="side in sides" :key="side" :value="side">
                  {{ side }}
                </option>
              </select>
            </div>

          </div>
        </div>
      </div>

      <!-- Parts Results Card -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <h6 class="mb-0 fw-bold text-dark">
              Eligible Pending Parts
            </h6>
            <span v-if="eligibleParts.length" class="badge bg-secondary-subtle text-secondary px-2 py-1 fs-7">
              {{ filteredParts.length }} of {{ eligibleParts.length }} parts
            </span>
            <!-- Selected count indicator -->
            <span v-if="selectedPartsList.length > 0" class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fs-7 fw-semibold">
              <i class="fas fa-check-square me-1"></i>{{ selectedPartsList.length }} selected ({{ selectedPartsTotalQty }} pcs)
            </span>
          </div>

          <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Bulk Delete Action Button -->
            <button 
              v-if="selectedPartsList.length > 0"
              type="button" 
              class="btn btn-danger btn-sm text-nowrap shadow-xs fw-semibold"
              @click="openBulkDeleteModal"
              :disabled="isDeleting"
            >
              <i class="fas fa-trash-alt me-1"></i>Delete Selected ({{ selectedPartsList.length }})
            </button>

            <!-- Quick In-Table Search Filter -->
            <div v-if="eligibleParts.length" class="d-flex align-items-center" style="max-width: 260px; width: 100%;">
              <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-end-0 text-muted">
                  <i class="fas fa-search"></i>
                </span>
                <input 
                  type="text" 
                  v-model="tableSearchQuery" 
                  class="form-control border-start-0" 
                  placeholder="Filter by part / item no..."
                />
                <button 
                  v-if="tableSearchQuery" 
                  @click="tableSearchQuery = ''" 
                  class="btn btn-outline-secondary border-start-0 border-secondary-subtle" 
                  type="button"
                >
                  &times;
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card-body p-0">
          
          <!-- Loading State -->
          <div v-if="loadingParts" class="text-center py-5">
            <div class="spinner-border text-danger mb-2" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
            <p class="text-muted small mb-0">Querying eligible pending parts...</p>
          </div>

          <!-- Initial Selection Prompt -->
          <div v-else-if="!selectedProject || !selectedBomType" class="text-center py-5 text-muted">
            <i class="fas fa-layer-group fa-3x mb-3 text-secondary opacity-50"></i>
            <h6 class="fw-semibold text-dark mb-1">Select Project and BOM Type</h6>
            <p class="small text-muted mb-0">Choose a project and BOM type above to view eligible pending parts for deletion.</p>
          </div>

          <!-- Empty State (No parts eligible) -->
          <div v-else-if="!eligibleParts.length" class="text-center py-5 text-muted">
            <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-75"></i>
            <h6 class="fw-semibold text-dark mb-1">No Pending Parts Found</h6>
            <p class="small text-muted mb-0">No untouched pending parts found matching the selected hierarchy filters.</p>
          </div>

          <!-- Parts Table -->
          <div v-else class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 font-sans" style="font-size: 0.85rem;">
              <thead class="table-light border-bottom text-uppercase extra-small text-muted fw-bold">
                <tr>
                  <!-- Selection Checkbox / Select All Column -->
                  <th class="ps-3 py-2.5 text-center" style="width: 44px;">
                    <div class="form-check m-0 p-0 d-flex align-items-center justify-content-center">
                      <input 
                        type="checkbox" 
                        class="form-check-input" 
                        :checked="isAllSelected"
                        :indeterminate.prop="isIndeterminate"
                        @change="toggleSelectAll"
                        title="Select all displayed pending parts"
                        style="cursor: pointer; width: 16px; height: 16px;"
                      />
                    </div>
                  </th>
                  <th class="py-2.5">Item No / Part No</th>
                  <th class="py-2.5">Standard Part No</th>
                  <th class="py-2.5">Type</th>
                  <th class="py-2.5">Jig</th>
                  <th class="py-2.5">Unit</th>
                  <th class="py-2.5">Side</th>
                  <th class="py-2.5">Description / Size</th>
                  <th class="py-2.5 text-center">Req Qty</th>
                  <th class="py-2.5 text-center">Status</th>
                  <th class="pe-3 py-2.5 text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr 
                  v-for="part in filteredParts" 
                  :key="part.bom_type + '-' + part.id"
                  :class="{ 'table-danger bg-danger-subtle bg-opacity-25': isSelected(part) }"
                >
                  <!-- Row Checkbox -->
                  <td class="ps-3 py-2 text-center">
                    <div class="form-check m-0 p-0 d-flex align-items-center justify-content-center">
                      <input 
                        type="checkbox" 
                        class="form-check-input" 
                        :checked="isSelected(part)"
                        @change="toggleSelectPart(part)"
                        style="cursor: pointer; width: 16px; height: 16px;"
                      />
                    </div>
                  </td>

                  <!-- Item No -->
                  <td class="fw-bold text-dark font-monospace">
                    {{ part.item_no || '—' }}
                  </td>

                  <!-- Standard Part No / ECN No -->
                  <td>
                    <div class="fw-semibold text-dark">{{ part.standard_part_no || '—' }}</div>
                    <small v-if="part.ecn_number" class="text-muted extra-small">
                      ECN #{{ part.ecn_number }}
                    </small>
                  </td>

                  <!-- BOM Type Badge -->
                  <td>
                    <span 
                      class="badge fw-bold shadow-xs px-2 py-0.5 extra-small"
                      :class="{
                        'bg-primary text-white': part.bom_type === 'MFG',
                        'bg-warning text-dark': part.bom_type === 'BOP',
                        'bg-teal text-white': part.bom_type === 'STD',
                        'bg-dark text-white': part.bom_type === 'ECN'
                      }"
                      :style="part.bom_type === 'STD' ? 'background-color: #0d9488;' : ''"
                    >
                      {{ part.bom_type }}
                    </span>
                  </td>

                  <!-- Jig -->
                  <td class="text-secondary font-monospace">{{ part.jig_no || '—' }}</td>

                  <!-- Unit -->
                  <td class="text-secondary font-monospace">{{ part.unit_no || '—' }}</td>

                  <!-- Side Badge -->
                  <td>
                    <span 
                      class="badge extra-small px-1.5 py-0.5"
                      :class="{
                        'bg-primary-subtle text-primary border border-primary-subtle': part.side === 'RH',
                        'bg-info-subtle text-info-emphasis border border-info-subtle': part.side === 'LH',
                        'bg-secondary-subtle text-secondary border border-secondary-subtle': part.side === 'COMMON' || part.side === 'COM'
                      }"
                    >
                      {{ part.side || 'COMMON' }}
                    </span>
                  </td>

                  <!-- Description / Size -->
                  <td class="text-muted text-truncate" style="max-width: 200px;" :title="part.description">
                    {{ part.description }}
                  </td>

                  <!-- Required Quantity -->
                  <td class="text-center fw-bold text-dark">
                    <span class="badge bg-light text-dark border px-2 py-1 fs-7">
                      {{ part.required_quantity }}
                    </span>
                  </td>

                  <!-- Status -->
                  <td class="text-center">
                    <span class="badge bg-success-subtle text-success border border-success-subtle extra-small px-2 py-0.5">
                      <i class="fas fa-check me-1"></i>Pending (Untouched)
                    </span>
                  </td>

                  <!-- Action Button -->
                  <td class="pe-3 text-end">
                    <button 
                      type="button" 
                      class="btn btn-outline-danger btn-sm py-0.5 px-2 text-nowrap"
                      @click="openDeleteModal(part)"
                      title="Delete or reduce quantity"
                    >
                      <i class="fas fa-trash-alt me-1"></i>Delete
                    </button>
                  </td>
                </tr>

                <tr v-if="tableSearchQuery && !filteredParts.length">
                  <td colspan="11" class="text-center py-4 text-muted small">
                    No parts match "{{ tableSearchQuery }}".
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>
      </div>

    </div>

    <!-- BULK DELETION CONFIRMATION MODAL -->
    <div 
      v-if="showBulkDeleteModal" 
      class="modal fade show d-block" 
      tabindex="-1" 
      style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;" 
      role="dialog" 
      aria-modal="true"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
          
          <!-- Modal Header -->
          <div class="modal-header bg-danger text-white border-0 py-2.5 px-3">
            <h6 class="modal-title fw-bold mb-0">
              <i class="fas fa-trash-alt me-2"></i>Confirm Bulk Pending Part Deletion
            </h6>
            <button 
              type="button" 
              class="btn-close btn-close-white" 
              :disabled="isDeleting" 
              @click="closeBulkDeleteModal" 
              aria-label="Close"
            ></button>
          </div>

          <!-- Modal Body -->
          <div class="modal-body p-3">
            
            <div v-if="bulkModalError" class="alert alert-danger shadow-sm py-2 px-3 mb-3 small d-flex align-items-center">
              <i class="fas fa-exclamation-triangle me-2"></i>
              <div class="flex-grow-1">{{ bulkModalError }}</div>
            </div>

            <!-- Danger Alert Warning -->
            <div class="alert alert-danger border-danger border-opacity-25 bg-danger-subtle text-danger py-2 px-3 mb-3 small">
              <i class="fas fa-exclamation-triangle me-1.5 fw-bold"></i>
              <strong>Permanent Destructive Action:</strong> You are about to permanently delete 
              <strong>{{ selectedPartsList.length }}</strong> pending part(s) totaling 
              <strong>{{ selectedPartsTotalQty }} pcs</strong>. This action is atomic and irreversible.
            </div>

            <!-- Summary Table of Selected Parts -->
            <div class="border rounded bg-white mb-3" style="max-height: 240px; overflow-y: auto;">
              <table class="table table-sm table-striped align-middle mb-0 font-sans" style="font-size: 0.8rem;">
                <thead class="table-light sticky-top text-uppercase extra-small text-muted fw-bold">
                  <tr>
                    <th class="ps-2 py-1.5">#</th>
                    <th class="py-1.5">Part / Item No</th>
                    <th class="py-1.5">BOM Type</th>
                    <th class="py-1.5">Jig</th>
                    <th class="py-1.5">Unit</th>
                    <th class="py-1.5">Side</th>
                    <th class="pe-2 py-1.5 text-center">Qty to Delete</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(p, idx) in selectedPartsList" :key="p.bom_type + '-' + p.id">
                    <td class="ps-2 text-muted font-monospace">{{ idx + 1 }}</td>
                    <td class="fw-bold font-monospace text-dark">{{ p.item_no || p.standard_part_no }}</td>
                    <td><span class="badge bg-secondary extra-small">{{ p.bom_type }}</span></td>
                    <td class="font-monospace text-muted">{{ p.jig_no }}</td>
                    <td class="font-monospace text-muted">{{ p.unit_no }}</td>
                    <td><span class="badge bg-light text-dark border extra-small">{{ p.side || 'COMMON' }}</span></td>
                    <td class="pe-2 text-center fw-bold text-danger">{{ p.required_quantity }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Audit Reason (Optional) -->
            <div class="mb-2">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                Audit Reason / Note (Optional):
              </label>
              <input 
                type="text" 
                v-model="bulkDeleteReason" 
                class="form-control form-control-sm" 
                placeholder="e.g., Inadvertent BOM import duplicates, engineering revision cancellation..."
                maxlength="250"
                :disabled="isDeleting"
              />
            </div>

          </div>

          <!-- Modal Footer -->
          <div class="modal-footer bg-light border-0 py-2.5 px-3 d-flex justify-content-between">
            <button 
              type="button" 
              class="btn btn-outline-secondary btn-sm" 
              :disabled="isDeleting" 
              @click="closeBulkDeleteModal"
            >
              Cancel
            </button>
            <button 
              type="button" 
              class="btn btn-danger btn-sm px-3 fw-bold" 
              :disabled="isDeleting || selectedPartsList.length === 0"
              @click="confirmBulkDelete"
            >
              <span v-if="isDeleting" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fas fa-trash-alt me-1"></i>
              Permanently Delete {{ selectedPartsList.length }} Selected Part(s)
            </button>
          </div>

        </div>
      </div>
    </div>

    <!-- INDIVIDUAL PARTIAL & FULL QUANTITY DELETION CONFIRMATION MODAL -->
    <div 
      v-if="showDeleteModal" 
      class="modal fade show d-block" 
      tabindex="-1" 
      style="background-color: rgba(15, 23, 42, 0.65); z-index: 1055;" 
      role="dialog" 
      aria-modal="true"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          
          <!-- Modal Header -->
          <div class="modal-header bg-danger text-white border-0 py-2.5 px-3">
            <h6 class="modal-title fw-bold mb-0">
              <i class="fas fa-trash-alt me-2"></i>Delete Pending BOM Part
            </h6>
            <button 
              type="button" 
              class="btn-close btn-close-white" 
              :disabled="isDeleting" 
              @click="closeDeleteModal" 
              aria-label="Close"
            ></button>
          </div>

          <!-- Modal Body -->
          <div class="modal-body p-3">
            
            <div v-if="modalError" class="alert alert-danger shadow-sm py-2 px-3 mb-3 small d-flex align-items-center">
              <i class="fas fa-exclamation-triangle me-2"></i>
              <div class="flex-grow-1">{{ modalError }}</div>
            </div>

            <!-- Target Part Details Card -->
            <div class="bg-light p-3 rounded border mb-3">
              <div class="row g-2 small">
                <div class="col-6">
                  <span class="text-muted extra-small text-uppercase d-block">BOM Type:</span>
                  <span class="fw-bold text-dark">{{ targetPart?.bom_type }}</span>
                </div>
                <div class="col-6">
                  <span class="text-muted extra-small text-uppercase d-block">Part / Item No:</span>
                  <span class="fw-bold font-monospace text-dark">{{ targetPart?.item_no || targetPart?.standard_part_no }}</span>
                </div>
                <div class="col-6">
                  <span class="text-muted extra-small text-uppercase d-block">Jig &amp; Unit:</span>
                  <span class="text-dark">{{ targetPart?.jig_no }} / {{ targetPart?.unit_no }}</span>
                </div>
                <div class="col-6">
                  <span class="text-muted extra-small text-uppercase d-block">Side:</span>
                  <span class="fw-semibold text-primary">{{ targetPart?.side || 'COMMON' }}</span>
                </div>
                <div class="col-12" v-if="targetPart?.description && targetPart?.description !== '-'">
                  <span class="text-muted extra-small text-uppercase d-block">Description:</span>
                  <span class="text-dark">{{ targetPart?.description }}</span>
                </div>
              </div>
            </div>

            <!-- Quantity Selection & Calculation Preview -->
            <div class="p-3 border rounded-3 bg-white mb-3 shadow-xs">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label fw-bold text-dark mb-0 small">
                  <i class="fas fa-calculator me-1.5 text-primary"></i>Quantity to Delete:
                </label>
                <span class="badge bg-light text-dark border extra-small">
                  Current Required: {{ targetPart?.required_quantity }}
                </span>
              </div>

              <!-- Quantity Input / Stepper -->
              <div class="input-group mb-2">
                <button 
                  class="btn btn-outline-secondary" 
                  type="button" 
                  :disabled="isDeleting || deleteQuantity <= 1"
                  @click="deleteQuantity = Math.max(1, deleteQuantity - 1)"
                >
                  <i class="fas fa-minus"></i>
                </button>
                <input 
                  type="number" 
                  v-model.number="deleteQuantity" 
                  class="form-control text-center fw-bold fs-6" 
                  :min="1" 
                  :max="targetPart?.required_quantity || 1"
                  :disabled="isDeleting"
                />
                <button 
                  class="btn btn-outline-secondary" 
                  type="button" 
                  :disabled="isDeleting || deleteQuantity >= (targetPart?.required_quantity || 1)"
                  @click="deleteQuantity = Math.min(targetPart?.required_quantity || 1, deleteQuantity + 1)"
                >
                  <i class="fas fa-plus"></i>
                </button>
              </div>

              <!-- Real-time Impact Preview Banner -->
              <div 
                class="p-2 rounded border extra-small mt-2"
                :class="remainingQuantityPreview > 0 ? 'bg-info-subtle border-info-subtle text-info-emphasis' : 'bg-danger-subtle border-danger-subtle text-danger'"
              >
                <div v-if="remainingQuantityPreview > 0" class="d-flex align-items-center gap-1.5">
                  <i class="fas fa-info-circle"></i>
                  <span>
                    <strong>Partial Deletion:</strong> Deleting {{ deleteQuantity }} part(s). 
                    Remaining required quantity: <strong>{{ remainingQuantityPreview }}</strong>.
                  </span>
                </div>
                <div v-else class="d-flex align-items-center gap-1.5">
                  <i class="fas fa-exclamation-circle"></i>
                  <span>
                    <strong>Complete Purge:</strong> Deleting all {{ targetPart?.required_quantity }} part(s). 
                    The requirement will be completely removed.
                  </span>
                </div>
              </div>
            </div>

            <!-- Audit Reason (Optional) -->
            <div class="mb-2">
              <label class="form-label extra-small fw-bold text-uppercase text-muted mb-1">
                Audit Reason / Note (Optional):
              </label>
              <input 
                type="text" 
                v-model="deleteReason" 
                class="form-control form-control-sm" 
                placeholder="e.g., Inadvertent BOM import duplicate, wrong requirement..."
                maxlength="250"
                :disabled="isDeleting"
              />
            </div>

          </div>

          <!-- Modal Footer -->
          <div class="modal-footer bg-light border-0 py-2.5 px-3 d-flex justify-content-between">
            <button 
              type="button" 
              class="btn btn-outline-secondary btn-sm" 
              :disabled="isDeleting" 
              @click="closeDeleteModal"
            >
              Cancel
            </button>
            <button 
              type="button" 
              class="btn btn-danger btn-sm px-3 fw-bold" 
              :disabled="isDeleting || !isValidDeleteQuantity"
              @click="confirmDelete"
            >
              <span v-if="isDeleting" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fas fa-trash-alt me-1"></i>
              {{ remainingQuantityPreview > 0 ? `Delete ${deleteQuantity} Part(s)` : 'Permanently Delete Part' }}
            </button>
          </div>

        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

// Filter states
const projects = ref([]);
const jigs = ref([]);
const units = ref([]);
const sides = ref([]);
const eligibleParts = ref([]);

const selectedProject = ref(null);
const selectedBomType = ref('');
const selectedJig = ref('');
const selectedUnit = ref('');
const selectedSide = ref('');
const tableSearchQuery = ref('');

// Loading states
const loadingProjects = ref(false);
const loadingJigs = ref(false);
const loadingUnits = ref(false);
const loadingSides = ref(false);
const loadingParts = ref(false);
const isDeleting = ref(false);

// Messages
const errorMessage = ref('');
const successMessage = ref('');
const modalError = ref('');
const bulkModalError = ref('');

// Single Delete Modal states
const showDeleteModal = ref(false);
const targetPart = ref(null);
const deleteQuantity = ref(1);
const deleteReason = ref('');

// Bulk Delete states
const showBulkDeleteModal = ref(false);
const bulkDeleteReason = ref('');
const selectedPartKeys = ref(new Set());

// Key generator for parts to isolate multi-type / duplicate IDs safely
const getPartKey = (part) => `${part.bom_type}_${part.id}`;

// Computed filtered parts for quick search input
const filteredParts = computed(() => {
  if (!tableSearchQuery.value.trim()) {
    return eligibleParts.value;
  }
  const q = tableSearchQuery.value.toLowerCase().trim();
  return eligibleParts.value.filter(p => {
    return (p.item_no && p.item_no.toLowerCase().includes(q)) ||
           (p.standard_part_no && p.standard_part_no.toLowerCase().includes(q)) ||
           (p.jig_no && p.jig_no.toLowerCase().includes(q)) ||
           (p.unit_no && p.unit_no.toLowerCase().includes(q)) ||
           (p.description && p.description.toLowerCase().includes(q));
  });
});

// Selection helpers
const isSelected = (part) => selectedPartKeys.value.has(getPartKey(part));

const toggleSelectPart = (part) => {
  const key = getPartKey(part);
  const next = new Set(selectedPartKeys.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  selectedPartKeys.value = next;
};

const isAllSelected = computed(() => {
  if (!filteredParts.value.length) return false;
  return filteredParts.value.every(p => selectedPartKeys.value.has(getPartKey(p)));
});

const isIndeterminate = computed(() => {
  if (!filteredParts.value.length || isAllSelected.value) return false;
  return filteredParts.value.some(p => selectedPartKeys.value.has(getPartKey(p)));
});

const toggleSelectAll = () => {
  const next = new Set(selectedPartKeys.value);
  if (isAllSelected.value) {
    filteredParts.value.forEach(p => next.delete(getPartKey(p)));
  } else {
    filteredParts.value.forEach(p => next.add(getPartKey(p)));
  }
  selectedPartKeys.value = next;
};

// Selected parts objects list
const selectedPartsList = computed(() => {
  return eligibleParts.value.filter(p => selectedPartKeys.value.has(getPartKey(p)));
});

// Total selected quantity
const selectedPartsTotalQty = computed(() => {
  return selectedPartsList.value.reduce((acc, p) => acc + (p.required_quantity || 0), 0);
});

// Real-time remaining preview in single delete modal
const remainingQuantityPreview = computed(() => {
  if (!targetPart.value) return 0;
  const current = targetPart.value.required_quantity || 0;
  return Math.max(0, current - (deleteQuantity.value || 0));
});

// Validation for delete quantity
const isValidDeleteQuantity = computed(() => {
  if (!targetPart.value) return false;
  const max = targetPart.value.required_quantity || 1;
  const qty = deleteQuantity.value;
  return Number.isInteger(qty) && qty >= 1 && qty <= max;
});

// Clear selection state safely whenever filters or query change
const clearSelection = () => {
  selectedPartKeys.value = new Set();
};

// Fetch distinct projects
const fetchProjects = async () => {
  loadingProjects.value = true;
  errorMessage.value = '';
  try {
    const res = await axios.get('/api/v1/pending-parts/projects');
    if (res.data.success) {
      projects.value = res.data.projects || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load projects.';
  } finally {
    loadingProjects.value = false;
  }
};

// Project change handler
const onProjectChange = () => {
  clearSelection();
  selectedBomType.value = '';
  selectedJig.value = '';
  selectedUnit.value = '';
  selectedSide.value = '';
  jigs.value = [];
  units.value = [];
  sides.value = [];
  eligibleParts.value = [];
};

// BOM Type change handler
const onBomTypeChange = async () => {
  clearSelection();
  selectedJig.value = '';
  selectedUnit.value = '';
  selectedSide.value = '';
  jigs.value = [];
  units.value = [];
  sides.value = [];
  eligibleParts.value = [];

  if (!selectedProject.value || !selectedBomType.value) return;

  await fetchJigs();
  await fetchEligibleParts();
};

// Fetch distinct jigs
const fetchJigs = async () => {
  if (!selectedProject.value || !selectedBomType.value) return;
  loadingJigs.value = true;
  try {
    const res = await axios.get('/api/v1/pending-parts/jigs', {
      params: {
        project_id: selectedProject.value,
        bom_type: selectedBomType.value,
      }
    });
    if (res.data.success) {
      jigs.value = res.data.jigs || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load Jigs.';
  } finally {
    loadingJigs.value = false;
  }
};

// Jig change handler
const onJigChange = async () => {
  clearSelection();
  selectedUnit.value = '';
  selectedSide.value = '';
  units.value = [];
  sides.value = [];

  if (selectedJig.value) {
    await fetchUnits();
  }
  await fetchEligibleParts();
};

// Fetch distinct units
const fetchUnits = async () => {
  if (!selectedProject.value || !selectedBomType.value || !selectedJig.value) return;
  loadingUnits.value = true;
  try {
    const res = await axios.get('/api/v1/pending-parts/units', {
      params: {
        project_id: selectedProject.value,
        bom_type: selectedBomType.value,
        jig_no: selectedJig.value,
      }
    });
    if (res.data.success) {
      units.value = res.data.units || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load Units.';
  } finally {
    loadingUnits.value = false;
  }
};

// Unit change handler
const onUnitChange = async () => {
  clearSelection();
  selectedSide.value = '';
  sides.value = [];

  if (selectedUnit.value) {
    await fetchSides();
  }
  await fetchEligibleParts();
};

// Fetch distinct sides
const fetchSides = async () => {
  if (!selectedProject.value || !selectedBomType.value || !selectedJig.value || !selectedUnit.value) return;
  loadingSides.value = true;
  try {
    const res = await axios.get('/api/v1/pending-parts/sides', {
      params: {
        project_id: selectedProject.value,
        bom_type: selectedBomType.value,
        jig_no: selectedJig.value,
        unit_no: selectedUnit.value,
      }
    });
    if (res.data.success) {
      sides.value = res.data.sides || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load Sides.';
  } finally {
    loadingSides.value = false;
  }
};

// Fetch eligible pending parts
const fetchEligibleParts = async () => {
  clearSelection();
  if (!selectedProject.value || !selectedBomType.value) {
    eligibleParts.value = [];
    return;
  }

  loadingParts.value = true;
  errorMessage.value = '';
  try {
    const res = await axios.get('/api/v1/pending-parts/eligible', {
      params: {
        project_id: selectedProject.value,
        bom_type: selectedBomType.value,
        jig_no: selectedJig.value || undefined,
        unit_no: selectedUnit.value || undefined,
        side: selectedSide.value || undefined,
      }
    });
    if (res.data.success) {
      eligibleParts.value = res.data.parts || [];
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Failed to load eligible pending parts.';
  } finally {
    loadingParts.value = false;
  }
};

// Reset all filters
const resetAllFilters = () => {
  clearSelection();
  selectedProject.value = null;
  selectedBomType.value = '';
  selectedJig.value = '';
  selectedUnit.value = '';
  selectedSide.value = '';
  jigs.value = [];
  units.value = [];
  sides.value = [];
  eligibleParts.value = [];
  tableSearchQuery.value = '';
  errorMessage.value = '';
  successMessage.value = '';
};

// Open single deletion confirmation modal
const openDeleteModal = (part) => {
  targetPart.value = part;
  deleteQuantity.value = part.required_quantity || 1;
  deleteReason.value = '';
  modalError.value = '';
  showDeleteModal.value = true;
};

// Close single deletion confirmation modal
const closeDeleteModal = () => {
  if (isDeleting.value) return;
  showDeleteModal.value = false;
  targetPart.value = null;
  deleteQuantity.value = 1;
  deleteReason.value = '';
  modalError.value = '';
};

// Confirm and execute single delete/decrement request
const confirmDelete = async () => {
  if (!targetPart.value || !isValidDeleteQuantity.value) return;

  isDeleting.value = true;
  modalError.value = '';
  try {
    const part = targetPart.value;
    const res = await axios.delete(`/api/v1/pending-parts/${part.id}`, {
      data: {
        bom_type: part.bom_type,
        quantity: deleteQuantity.value,
        reason: deleteReason.value || undefined,
      }
    });

    if (res.data.success) {
      const remaining = res.data.remaining_quantity;
      if (remaining > 0) {
        // Partial deletion: update quantity in-place
        part.required_quantity = remaining;
        successMessage.value = res.data.message || `Successfully reduced quantity to ${remaining}.`;
      } else {
        // Complete deletion: remove part row from eligible list
        eligibleParts.value = eligibleParts.value.filter(p => !(p.id === part.id && p.bom_type === part.bom_type));
        // Remove from selection if was selected
        const next = new Set(selectedPartKeys.value);
        next.delete(getPartKey(part));
        selectedPartKeys.value = next;
        successMessage.value = res.data.message || 'Part requirement successfully deleted.';
      }
      showDeleteModal.value = false;
      targetPart.value = null;
    } else {
      modalError.value = res.data.message || 'Deletion failed.';
    }
  } catch (err) {
    modalError.value = err.response?.data?.message || 'Error occurred while deleting part.';
  } finally {
    isDeleting.value = false;
  }
};

// Open bulk deletion confirmation modal
const openBulkDeleteModal = () => {
  if (selectedPartsList.value.length === 0) return;
  bulkDeleteReason.value = '';
  bulkModalError.value = '';
  showBulkDeleteModal.value = true;
};

// Close bulk deletion confirmation modal
const closeBulkDeleteModal = () => {
  if (isDeleting.value) return;
  showBulkDeleteModal.value = false;
  bulkDeleteReason.value = '';
  bulkModalError.value = '';
};

// Confirm and execute bulk deletion request
const confirmBulkDelete = async () => {
  if (selectedPartsList.value.length === 0) return;

  isDeleting.value = true;
  bulkModalError.value = '';
  try {
    const itemsPayload = selectedPartsList.value.map(p => ({
      id: p.id,
      bom_type: p.bom_type,
    }));

    const res = await axios.post('/api/v1/pending-parts/bulk-delete', {
      items: itemsPayload,
      reason: bulkDeleteReason.value || undefined,
    });

    if (res.data.success) {
      const deletedKeys = new Set(selectedPartsList.value.map(getPartKey));
      // Remove all deleted parts immediately from local list
      eligibleParts.value = eligibleParts.value.filter(p => !deletedKeys.has(getPartKey(p)));
      clearSelection();
      successMessage.value = res.data.message || `Successfully deleted ${res.data.deleted_count} pending part(s).`;
      showBulkDeleteModal.value = false;
    } else {
      bulkModalError.value = res.data.message || 'Bulk deletion failed.';
    }
  } catch (err) {
    bulkModalError.value = err.response?.data?.message || 'Error occurred during bulk deletion.';
  } finally {
    isDeleting.value = false;
  }
};

onMounted(() => {
  fetchProjects();
});
</script>

<style scoped>
.extra-small {
  font-size: 0.75rem;
}
.fs-7 {
  font-size: 0.8rem;
}
.shadow-xs {
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}
</style>
