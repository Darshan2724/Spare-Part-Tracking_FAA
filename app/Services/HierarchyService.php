<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Services\QuantityCalculationService;
use App\Services\EcnQuantityCalculationService;
use Illuminate\Support\Facades\DB;

class HierarchyService
{
    public function __construct(
        protected QuantityCalculationService $quantityService = new QuantityCalculationService(),
        protected EcnQuantityCalculationService $ecnQuantityService = new EcnQuantityCalculationService()
    ) {}

    /**
     * Build unified hierarchy tree for any operational department.
     *
     * @param string $department 'store' | 'qc' | 'rework' | 'paint' | 'assembly' | 'manager'
     * @param int|null $projectId
     * @param array $filters ['side' => 'RH'|'LH', 'search' => '...']
     * @return array
     */
    public function getDepartmentHierarchy(string $department, ?int $projectId = null, array $filters = []): array
    {
        $projects = Project::orderBy('name')->get();
        $activeProjects = $projects->where('status', 'active')->values();
        $completedProjects = $projects->where('status', 'completed')->values();

        $stage = strtolower($filters['stage'] ?? $filters['queue_type'] ?? $filters['subtab'] ?? '');
        $ecnDeptContext = $department;
        if ($department === 'qc') {
            if ($stage === 'arrival') {
                $ecnDeptContext = 'qc_arrival';
            } elseif ($stage === 'inspection') {
                $ecnDeptContext = 'qc_inspection';
            }
        }

        $bulkMetrics = $this->quantityService->calculateBulkProjectsMetrics($projects, $filters['side'] ?? null, $filters);

        $projectsList = $projects->map(function ($proj) use ($department, $bulkMetrics, $filters) {
            $m = $bulkMetrics->get($proj->id) ?? [];
            return $this->formatProjectOverviewStatsFromMetrics($proj, $department, $m, $filters);
        });

        if (!$projectId) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'department' => $department,
                'message' => 'Select a project to view hierarchical breakdown.',
            ];
        }

        $project = Project::find($projectId);
        if (!$project) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'department' => $department,
                'message' => 'Project not found.',
            ];
        }

        // Query BOM Items with necessary relations
        $query = BomItem::query()
            ->with(['requirements', 'supplier', 'project'])
            ->where('project_id', $project->id);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%")
                  ->orWhere('jig_no', 'LIKE', "%{$search}%")
                  ->orWhere('unit_no', 'LIKE', "%{$search}%")
                  ->orWhere('part_description', 'LIKE', "%{$search}%")
                  ->orWhere('size', 'LIKE', "%{$search}%")
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('name', 'LIKE', "%{$search}%");
                  });
            });
        }

        $bomItems = $query->orderBy('standard_part_no')->get();
        $hasEcn = EcnRequirement::where('project_id', $project->id)->exists();

        if ($bomItems->isEmpty() && !$hasEcn) {
            $ecnMap = $this->ecnQuantityService->preloadProjectDepartmentEcnMap($project->id, $ecnDeptContext);
            $projEcnTotal = $ecnMap['project_total'] ?? 0;
            $project->setAttribute('ecn_parts', $projEcnTotal);
            $project->setAttribute('ecn_total_parts', $projEcnTotal);
            $project->setAttribute('ecn_part_count', $projEcnTotal);
            $project->setAttribute('ecn_count', $projEcnTotal);
            $project->setAttribute('is_ecn_present', ($projEcnTotal > 0));
            $project->setAttribute('ecn_present', ($projEcnTotal > 0));
            $project->setAttribute('ecn_numbers', $ecnMap['project_ecn_numbers'] ?? []);
            $project->setAttribute('ecn_summary', $ecnMap['project_ecn_summary'] ?? []);
            $project->setAttribute('ecn_number_display', $ecnMap['project_ecn_display'] ?? null);
            $project->ecn_parts = $projEcnTotal;
            $project->ecn_number_display = $ecnMap['project_ecn_display'] ?? null;

            return [
                'is_hierarchical' => false,
                'project' => $project,
                'projects' => $projectsList,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'canonical_summary' => $this->quantityService->calculateProjectMetrics($project, $filters['side'] ?? null, $filters),
                'department' => $department,
                'message' => 'No BOM items found for this project.',
            ];
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Pre-fetch related operational records in bulk, only valid non-reverted/non-scrapped receipts
        $receiptItemsGrouped = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get()
            ->groupBy('bom_item_id');

        $qcInspectionsGrouped = QcInspection::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $reworkRecordsGrouped = ReworkRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $paintRecordsGrouped = PaintRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecordsGrouped = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        // Pre-load department-specific ECN hierarchy breakdown map and requirements
        $ecnMap = $this->ecnQuantityService->preloadProjectDepartmentEcnMap($project->id, $ecnDeptContext);
        $ecnReqs = EcnRequirement::where('project_id', $project->id)->get();
        $ecnReqIds = $ecnReqs->pluck('id')->toArray();
        $ecnReceiptsGrouped = EcnReceiptItem::whereIn('ecn_requirement_id', $ecnReqIds)->get()->groupBy('ecn_requirement_id');
        $ecnWorkflowGrouped = EcnWorkflowRecord::whereIn('ecn_requirement_id', $ecnReqIds)->get()->groupBy('ecn_requirement_id');

        $ecnReqsByUnit = [];
        foreach ($ecnReqs as $er) {
            $jKey = strtoupper(trim($er->jig_no));
            $uRaw = trim($er->unit_no);
            $cleanNo = trim(str_ireplace('unit', '', $uRaw));
            $paddedNo = is_numeric($cleanNo) ? sprintf('%02d', (int)$cleanNo) : $cleanNo;

            $aliases = array_unique([
                $jKey . '|' . $uRaw,
                $jKey . '|' . $cleanNo,
                $jKey . '|Unit ' . $cleanNo,
                $jKey . '|Unit ' . $paddedNo,
            ]);

            foreach ($aliases as $aKey) {
                $ecnReqsByUnit[$aKey][] = $er;
            }
        }

        $jigsTree = [];

        foreach ($bomItems as $item) {
            $partNo = trim($item->standard_part_no);

            // Read JIG and Unit directly from the authoritative FA-279 BOM fields
            $jigName = !empty($item->jig_no) ? strtoupper(trim($item->jig_no)) : 'GENERAL';
            $rawUnit = !empty($item->unit_no) ? trim($item->unit_no) : '00';
            $unitNo = str_starts_with(strtoupper($rawUnit), 'UNIT') ? $rawUnit : ('Unit ' . $rawUnit);

            $itemReceipts = $receiptItemsGrouped->get($item->id, collect());
            $itemQcInspections = $qcInspectionsGrouped->get($item->id, collect());
            $itemReworks = $reworkRecordsGrouped->get($item->id, collect());
            $itemPaints = $paintRecordsGrouped->get($item->id, collect());
            $itemAssemblies = $assemblyRecordsGrouped->get($item->id, collect());

            // Compute side-specific breakdown
            $sideStats = [];
            $itemMetrics = [
                'total_required' => 0,
                'total_received' => 0,
                'total_pending' => 0,
                'parts_in_store' => 0,
                'parts_in_qc' => 0,
                'qc_pending_arrival' => 0,
                'qc_pending_inspection' => 0,
                'qc_approved' => 0,
                'qc_rejected' => 0,
                'qc_rework' => 0,
                'parts_in_rework' => 0,
                'rework_pending' => 0,
                'rework_in_progress' => 0,
                'rework_completed' => 0,
                'parts_in_paint' => 0,
                'paint_ready' => 0,
                'paint_completed' => 0,
                'parts_in_assembly' => 0,
                'assembly_ready' => 0,
                'assembly_completed' => 0,
            ];

            foreach ($item->requirements as $req) {
                $side = $req->side;

                $recForSide = $itemReceipts->where('side', $side);
                $qcForSide = $itemQcInspections->where('side', $side);
                $reworkForSide = $itemReworks->where('side', $side);
                $paintForSide = $itemPaints->where('side', $side);
                $assemblyForSide = $itemAssemblies->where('side', $side);

                $reqQty = (int) $req->required_quantity;
                $rawRecQty = (int) $recForSide->sum('received_quantity');
                $recQty = min($rawRecQty, $reqQty); // Canonical: capped so required = received + pending
                $pendingQty = max(0, $reqQty - $recQty);

                // QC Stats
                $qcAppPaint = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirectAssembly = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $qcApp = $qcAppPaint + $qcAppDirectAssembly;
                $qcRej = (int) $qcForSide->sum('rejected_quantity');
                $qcRew = (int) $qcForSide->sum('rework_quantity');

                // Rework Stats
                $rewComp = (int) $reworkForSide->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                $rewActive = max(0, $qcRew - $rewComp);

                // Paint Stats - Include all painted records (completed or assembled) so paint never re-acquires assembled parts
                $paintComp = (int) $paintForSide->whereIn('status', ['completed', 'assembled'])->sum('quantity');
                $paintActive = max(0, $qcAppPaint - $paintComp);

                // Assembly Stats
                $asmComp = (int) $assemblyForSide->where('status', 'completed')->sum('quantity');
                $asmReached = $paintComp + $qcAppDirectAssembly;
                $asmReady = max(0, $asmReached - $asmComp);

                // Location Residencies
                $validReceived = $recForSide->whereIn('status', ['received', 'sent_to_qc', 'qc_received', 'qc_approved', 'qc_rejected', 'qc_rework', 'qc_inspected', 'returned_to_store'])->sum('received_quantity');
                $storeResident = (int) $recForSide->whereIn('status', ['received', 'returned_to_store'])->sum('received_quantity');
                $qcPendingArrival = (int) $recForSide->whereIn('status', ['received', 'sent_to_qc'])->sum('received_quantity');
                $totalQcArrived = (int) $recForSide->whereIn('status', ['qc_received', 'qc_approved', 'qc_rejected', 'qc_rework', 'qc_inspected'])->sum('received_quantity');
                $qcPendingInspection = max(0, ($totalQcArrived + $rewComp) - ($qcApp + $qcRej + $qcRew));
                $qcResident = $qcPendingArrival + $qcPendingInspection;

                // Department Specific Status Badges
                if ($asmComp >= $reqQty && $reqQty > 0) {
                    $statusBadge = 'Assembled';
                    $statusColor = 'success';
                } elseif ($asmReady > 0) {
                    $statusBadge = 'Assembly';
                    $statusColor = 'pink';
                } elseif ($paintActive > 0) {
                    $statusBadge = 'Paint';
                    $statusColor = 'purple';
                } elseif ($rewActive > 0) {
                    $statusBadge = 'Rework';
                    $statusColor = 'warning';
                } elseif ($qcResident > 0) {
                    $statusBadge = 'QC';
                    $statusColor = 'info';
                } elseif ($qcRej > 0) {
                    $statusBadge = 'QC Rejected';
                    $statusColor = 'danger';
                } elseif ($storeResident > 0) {
                    $statusBadge = 'Store';
                    $statusColor = 'warning';
                } else {
                    $statusBadge = 'Pending';
                    $statusColor = 'secondary';
                }

                // Revert Options computation for this department and side
                $revertOptions = [];
                $deptKey = strtolower($department ?? '');
                if ($deptKey === 'store') {
                    foreach ($recForSide as $rec) {
                        if (in_array($rec->status, ['pending_qc', 'store_received', 'received']) && $rec->received_quantity > 0) {
                            $inspected = (int) $qcForSide->where('receipt_item_id', $rec->id)->sum(fn($q) => $q->approved_quantity + $q->rejected_quantity + $q->rework_quantity);
                            $uninspected = max(0, $rec->received_quantity - $inspected);
                            if ($uninspected > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'receipt_item',
                                    'source_id' => $rec->id,
                                    'available_quantity' => $uninspected,
                                    'from_department' => 'STORE',
                                    'to_department' => 'PENDING_ARRIVAL',
                                    'target_label' => 'Pending Supplier Arrival',
                                    'description' => "Receipt #{$rec->id} ({$uninspected} pcs)",
                                ];
                            }
                        }
                    }
                } elseif ($deptKey === 'qc') {
                    foreach ($recForSide as $rec) {
                        if ($rec->status === 'qc_received' && $rec->received_quantity > 0) {
                            $inspected = (int) $qcForSide->where('receipt_item_id', $rec->id)->sum(fn($q) => $q->approved_quantity + $q->rejected_quantity + $q->rework_quantity);
                            $avail = max(0, $rec->received_quantity - $inspected);
                            if ($avail > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'receipt_item',
                                    'source_id' => $rec->id,
                                    'available_quantity' => $avail,
                                    'from_department' => 'QC',
                                    'to_department' => 'STORE',
                                    'target_label' => 'Store Bay',
                                    'description' => "Arrived from Store ({$avail} pcs)",
                                ];
                            }
                        }
                    }
                } elseif ($deptKey === 'rework') {
                    foreach ($qcForSide as $insp) {
                        if ($insp->rework_quantity > 0) {
                            $completed = (int) $reworkForSide->where('qc_inspection_id', $insp->id)->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                            $avail = max(0, $insp->rework_quantity - $completed);
                            if ($avail > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'qc_inspection',
                                    'source_id' => $insp->id,
                                    'available_quantity' => $avail,
                                    'from_department' => 'REWORK',
                                    'to_department' => 'QC',
                                    'target_label' => 'Quality Control Bay',
                                    'description' => "From QC Inspection #{$insp->id} ({$avail} pcs)",
                                ];
                            }
                        }
                    }
                } elseif ($deptKey === 'paint') {
                    foreach ($qcForSide as $insp) {
                        if ($insp->approved_quantity > 0 && ($insp->destination === 'PAINT' || empty($insp->destination))) {
                            $painted = (int) $paintForSide->where('qc_inspection_id', $insp->id)->sum('quantity');
                            $avail = max(0, $insp->approved_quantity - $painted);
                            if ($avail > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'qc_inspection',
                                    'source_id' => $insp->id,
                                    'available_quantity' => $avail,
                                    'from_department' => 'PAINT',
                                    'to_department' => 'QC',
                                    'target_label' => 'Quality Control Bay',
                                    'description' => "From QC Approval #{$insp->id} ({$avail} pcs)",
                                ];
                            }
                        }
                    }
                } elseif ($deptKey === 'assembly') {
                    // Lineage 1: From Paint Records
                    foreach ($paintForSide as $p) {
                        if (in_array($p->status, ['completed', 'assembled']) && $p->quantity > 0) {
                            $assembled = (int) $assemblyForSide->where('paint_record_id', $p->id)->sum('quantity');
                            $avail = max(0, $p->quantity - $assembled);
                            if ($avail > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'paint_record',
                                    'source_id' => $p->id,
                                    'available_quantity' => $avail,
                                    'from_department' => 'ASSEMBLY',
                                    'to_department' => 'PAINT',
                                    'target_label' => 'Paint Shop',
                                    'description' => "From Paint Shop #{$p->id} ({$avail} pcs)",
                                ];
                            }
                        }
                    }
                    // Lineage 2: From Direct QC Approvals
                    foreach ($qcForSide as $insp) {
                        if ($insp->approved_quantity > 0 && $insp->destination === 'ASSEMBLY') {
                            $assembled = (int) $assemblyForSide->where('qc_inspection_id', $insp->id)->sum('quantity');
                            $avail = max(0, $insp->approved_quantity - $assembled);
                            if ($avail > 0) {
                                $revertOptions[] = [
                                    'source_type' => 'qc_inspection',
                                    'source_id' => $insp->id,
                                    'available_quantity' => $avail,
                                    'from_department' => 'ASSEMBLY',
                                    'to_department' => 'QC',
                                    'target_label' => 'Quality Control Bay',
                                    'description' => "From Direct QC Inspection #{$insp->id} ({$avail} pcs)",
                                ];
                            }
                        }
                    }
                }

                $sideStats[$side] = [
                    'required' => $reqQty,
                    'received' => $recQty,
                    'pending' => $pendingQty,
                    'parts_in_store' => $storeResident,
                    'parts_in_qc' => $qcResident,
                    'qc_pending_arrival' => $qcPendingArrival,
                    'qc_pending_inspection' => $qcPendingInspection,
                    'qc_approved' => $qcApp,
                    'qc_rejected' => $qcRej,
                    'qc_rework' => $qcRew,
                    'parts_in_rework' => $rewActive,
                    'rework_pending' => $rewActive,
                    'rework_in_progress' => (int) $reworkForSide->where('status', 'in_progress')->sum('quantity'),
                    'rework_completed' => $rewComp,
                    'parts_in_paint' => $paintActive,
                    'paint_ready' => $paintActive,
                    'paint_completed' => $paintComp,
                    'parts_in_assembly' => $asmReady,
                    'assembly_ready' => $asmReady,
                    'assembly_completed' => $asmComp,
                    'receipt_items' => $recForSide->values(),
                    'qc_inspections' => $qcForSide->values(),
                    'rework_records' => $reworkForSide->values(),
                    'paint_records' => $paintForSide->values(),
                    'assembly_records' => $assemblyForSide->values(),
                    'revert_options' => $revertOptions,
                    'total_revertible' => array_sum(array_column($revertOptions, 'available_quantity')),
                    'status_badge' => $statusBadge,
                    'status_color' => $statusColor,
                    'is_done' => ($reqQty > 0 && $asmComp >= $reqQty),
                ];

                // Accumulate into item metrics
                if (empty($filters['side']) || $filters['side'] === $side || $side === 'COMMON') {
                    $itemMetrics['total_required'] += $reqQty;
                    $itemMetrics['total_received'] += $recQty;
                    $itemMetrics['total_pending'] += $pendingQty;
                    $itemMetrics['parts_in_store'] += $storeResident;
                    $itemMetrics['parts_in_qc'] += $qcResident;
                    $itemMetrics['qc_pending_arrival'] += $qcPendingArrival;
                    $itemMetrics['qc_pending_inspection'] += $qcPendingInspection;
                    $itemMetrics['qc_approved'] += $qcApp;
                    $itemMetrics['qc_rejected'] += $qcRej;
                    $itemMetrics['qc_rework'] += $qcRew;
                    $itemMetrics['parts_in_rework'] += $rewActive;
                    $itemMetrics['rework_pending'] += $rewActive;
                    $itemMetrics['rework_in_progress'] += (int) $reworkForSide->where('status', 'in_progress')->sum('quantity');
                    $itemMetrics['rework_completed'] += $rewComp;
                    $itemMetrics['parts_in_paint'] += $paintActive;
                    $itemMetrics['paint_ready'] += $paintActive;
                    $itemMetrics['paint_completed'] += $paintComp;
                    $itemMetrics['parts_in_assembly'] += $asmReady;
                    $itemMetrics['assembly_ready'] += $asmReady;
                    $itemMetrics['assembly_completed'] += $asmComp;
                }
            }

            // If side filter is active and this item has no requirements for that side, skip
            if (!empty($filters['side']) && !isset($sideStats[$filters['side']]) && !isset($sideStats['COMMON'])) {
                continue;
            }

            $item->side_stats = $sideStats;
            $item->metrics = $itemMetrics;
            $item->receipt_items = $itemReceipts->values();
            $item->qc_inspections = $itemQcInspections->values();
            $item->paint_records = $itemPaints->values();
            $item->rework_records = $itemReworks->values();
            $item->assembly_records = $itemAssemblies->values();
            $item->is_done = ($itemMetrics['total_required'] > 0 && $itemMetrics['assembly_completed'] >= $itemMetrics['total_required']);

            // Group into JIG and Unit structure
            if (!isset($jigsTree[$jigName])) {
                $jigsTree[$jigName] = [
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'complete_units' => 0,
                    'total_units' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'units' => [],
                ];
            }

            if (!isset($jigsTree[$jigName]['units'][$unitNo])) {
                $jigsTree[$jigName]['units'][$unitNo] = [
                    'unit_no' => $unitNo,
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'parts' => [],
                ];
            }

            $jigsTree[$jigName]['units'][$unitNo]['parts'][] = $item;
            $jigsTree[$jigName]['units'][$unitNo]['total_parts']++;
            $jigsTree[$jigName]['units'][$unitNo]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['units'][$unitNo]['total_received'] += $itemMetrics['total_received'];
            $jigsTree[$jigName]['units'][$unitNo]['total_pending'] += $itemMetrics['total_pending'];
            $this->accumulateMetrics($jigsTree[$jigName]['units'][$unitNo]['metrics'], $itemMetrics);

            $jigsTree[$jigName]['total_parts']++;
            $jigsTree[$jigName]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['total_received'] += $itemMetrics['total_received'];
            $jigsTree[$jigName]['total_pending'] += $itemMetrics['total_pending'];
            $this->accumulateMetrics($jigsTree[$jigName]['metrics'], $itemMetrics);
        }

        // Ensure units containing ONLY ECN parts are initialized in $jigsTree
        foreach ($ecnReqs as $er) {
            $jKey = strtoupper(trim($er->jig_no));
            $uRaw = trim($er->unit_no);
            $cleanNo = trim(str_ireplace('unit', '', $uRaw));
            $paddedNo = is_numeric($cleanNo) ? sprintf('%02d', (int)$cleanNo) : $cleanNo;
            $unitDisplay = 'Unit ' . $paddedNo;

            if (!isset($jigsTree[$jKey])) {
                $jigsTree[$jKey] = [
                    'jig_name' => $jKey,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'complete_units' => 0,
                    'total_units' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'units' => [],
                ];
            }

            // Check if any variant of this unit already exists in $jigsTree[$jKey]['units']
            $existingKey = null;
            foreach ([$uRaw, $cleanNo, 'Unit ' . $cleanNo, 'Unit ' . $paddedNo, $unitDisplay] as $candidate) {
                if (isset($jigsTree[$jKey]['units'][$candidate])) {
                    $existingKey = $candidate;
                    break;
                }
            }

            if ($existingKey === null) {
                $targetKey = $uRaw !== '' ? $uRaw : $unitDisplay;
                $jigsTree[$jKey]['units'][$targetKey] = [
                    'unit_no' => $targetKey,
                    'jig_name' => $jKey,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_pending' => 0,
                    'total_parts' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'parts' => [],
                ];
            }
        }

        // Format and compute percentage completions per Unit and JIG
        $formattedJigs = [];

        foreach ($jigsTree as $jigName => $jigData) {
            $formattedUnits = [];
            $completeUnitsCount = 0;

            foreach ($jigData['units'] as $unitNo => $unitData) {
                $req = $unitData['total_required'];
                $rec = $unitData['total_received'];
                $unitData['pending_quantity'] = max(0, $req - $rec);

                // Compute dedicated LH and RH side breakdowns (COMMON parts included in both)
                $lhParts = [];
                $rhParts = [];
                $lhMetrics = $this->initZeroMetrics();
                $rhMetrics = $this->initZeroMetrics();
                $lhRequired = 0; $lhReceived = 0; $lhPending = 0; $lhAsmComp = 0;
                $rhRequired = 0; $rhReceived = 0; $rhPending = 0; $rhAsmComp = 0;

                // Process regular parts
                foreach ($unitData['parts'] as $part) {
                    $hasLh = isset($part->side_stats['LH']) || isset($part->side_stats['COMMON']);
                    $hasRh = isset($part->side_stats['RH']) || isset($part->side_stats['COMMON']);

                    if ($hasLh) {
                        $st = $part->side_stats['LH'] ?? $part->side_stats['COMMON'];
                        $lhParts[] = [
                            'id' => $part->id,
                            'standard_part_no' => $part->standard_part_no,
                            'item_no' => $part->item_no ?? '—',
                            'supplier' => $part->supplier?->name ?? ($part->supplier_name_raw ?? '—'),
                            'side' => isset($part->side_stats['LH']) ? 'LH' : 'COMMON',
                            'required_qty' => $st['required'] ?? 0,
                            'received_qty' => $st['received'] ?? 0,
                            'pending_qty' => $st['pending'] ?? 0,
                            'status_badge' => $st['status_badge'] ?? 'Pending',
                            'status_color' => $st['status_color'] ?? 'secondary',
                            'is_done' => $st['is_done'] ?? false,
                            'is_ecn' => false,
                            'classification' => 'REGULAR',
                            'side_stats' => $part->side_stats,
                        ];
                        $lhRequired += $st['required'] ?? 0;
                        $lhReceived += $st['received'] ?? 0;
                        $lhPending += $st['pending'] ?? 0;
                        $lhAsmComp += $st['assembly_completed'] ?? 0;
                        $this->accumulateMetrics($lhMetrics, $st);
                    }
                    if ($hasRh) {
                        $st = $part->side_stats['RH'] ?? $part->side_stats['COMMON'];
                        $rhParts[] = [
                            'id' => $part->id,
                            'standard_part_no' => $part->standard_part_no,
                            'item_no' => $part->item_no ?? '—',
                            'supplier' => $part->supplier?->name ?? ($part->supplier_name_raw ?? '—'),
                            'side' => isset($part->side_stats['RH']) ? 'RH' : 'COMMON',
                            'required_qty' => $st['required'] ?? 0,
                            'received_qty' => $st['received'] ?? 0,
                            'pending_qty' => $st['pending'] ?? 0,
                            'status_badge' => $st['status_badge'] ?? 'Pending',
                            'status_color' => $st['status_color'] ?? 'secondary',
                            'is_done' => $st['is_done'] ?? false,
                            'is_ecn' => false,
                            'classification' => 'REGULAR',
                            'side_stats' => $part->side_stats,
                        ];
                        $rhRequired += $st['required'] ?? 0;
                        $rhReceived += $st['received'] ?? 0;
                        $rhPending += $st['pending'] ?? 0;
                        $rhAsmComp += $st['assembly_completed'] ?? 0;
                        $this->accumulateMetrics($rhMetrics, $st);
                    }
                }

                $rawU = trim(str_ireplace('unit', '', $unitNo));
                $unitEcnReqs = $ecnReqsByUnit[$jigName . '|' . $unitNo] ?? ($ecnReqsByUnit[$jigName . '|' . $rawU] ?? []);
                $allUnitParts = $unitData['parts']; // starts with regular parts

                foreach ($unitEcnReqs as $er) {
                    $deptKey = strtolower($ecnDeptContext ?? $department ?? 'manager');
                    if ($deptKey === 'manager') {
                        continue;
                    }
                    $erReceipts = $ecnReceiptsGrouped->get($er->id, collect());
                    $erWorkflow = $ecnWorkflowGrouped->get($er->id, collect());

                    $qcPendingArrCalc = (int) $erReceipts->whereIn('status', ['received', 'store_received', 'sent_to_qc'])->sum('received_quantity');
                    $qcPendingInspCalc = (int) $erReceipts->where('status', 'qc_received')->sum('received_quantity');

                    if ($deptKey === 'store' && $er->current_state !== 'PENDING') {
                        continue;
                    }
                    if (($deptKey === 'store_resident' || $deptKey === 'qc_arrival') && (!in_array($er->current_state, ['STORE', 'SENT_TO_QC']) || ($erReceipts->isNotEmpty() && $qcPendingArrCalc === 0))) {
                        continue;
                    }
                    if ($deptKey === 'qc_inspection' && ($er->current_state !== 'QC' || ($erReceipts->isNotEmpty() && $qcPendingInspCalc === 0))) {
                        continue;
                    }
                    if ($deptKey === 'qc' && (!in_array($er->current_state, ['STORE', 'SENT_TO_QC', 'QC']) || ($erReceipts->isNotEmpty() && $qcPendingArrCalc === 0 && $qcPendingInspCalc === 0))) {
                        continue;
                    }
                    if ($deptKey === 'rework' && $er->current_state !== 'REWORK') {
                        continue;
                    }
                    if ($deptKey === 'paint' && $er->current_state !== 'PAINT') {
                        continue;
                    }
                    if ($deptKey === 'assembly' && !in_array($er->current_state, ['ASSEMBLY', 'ASSEMBLY_COMPLETED'])) {
                        continue;
                    }

                    $sideDisp = $er->side_display;
                    $reqQ = (int)$er->required_qty;
                    $recQ = (int)$er->received_qty;
                    $penQ = max(0, $reqQ - $recQ);

                    // Compute accurate QC state metrics
                    $qcPendingArrival = $qcPendingArrCalc;
                    $qcPendingInspection = $qcPendingInspCalc;
                    if ($qcPendingArrival === 0 && $qcPendingInspection === 0 && $erReceipts->isEmpty()) {
                        if ($er->current_state === 'SENT_TO_QC') {
                            $qcPendingArrival = $recQ;
                        } elseif ($er->current_state === 'QC') {
                            $qcPendingInspection = $recQ;
                        } elseif ($er->current_state === 'STORE' && $recQ > 0) {
                            $qcPendingArrival = $recQ;
                        }
                    }
                    $qcResident = $qcPendingArrival + $qcPendingInspection;

                    $ecnStatusBadge = match ($er->current_state) {
                        'ASSEMBLY_COMPLETED' => 'Assembled',
                        'ASSEMBLY' => 'Assembly',
                        'PAINT' => 'Paint',
                        'REWORK' => 'Rework',
                        'QC', 'SENT_TO_QC' => 'QC',
                        'STORE' => ($qcResident > 0 ? 'QC' : 'Store'),
                        default => 'Pending',
                    };
                    $ecnStatusColor = match ($er->current_state) {
                        'ASSEMBLY_COMPLETED' => 'success',
                        'ASSEMBLY' => 'pink',
                        'PAINT' => 'purple',
                        'REWORK' => 'warning',
                        'QC', 'SENT_TO_QC' => 'info',
                        'STORE' => ($qcResident > 0 ? 'info' : 'warning'),
                        default => 'secondary',
                    };

                    // Calculate ECN revert options for part
                    $ecnRevertOptions = [];
                    $deptKey = strtolower($department ?? '');
                    if ($deptKey === 'store') {
                        $storeItems = $erReceipts->where('status', 'received');
                        foreach ($storeItems as $si) {
                            $ecnRevertOptions[] = [
                                'source_type' => 'ecn_receipt_item',
                                'source_id' => $si->id,
                                'available_quantity' => (int)$si->received_quantity,
                                'from_department' => 'STORE',
                                'to_department' => 'PENDING_ARRIVAL',
                                'target_label' => 'Pending Supplier Arrival',
                                'description' => "ECN Receipt #{$si->id} ({$si->received_quantity} pcs)",
                                'is_ecn' => true,
                            ];
                        }
                    } elseif ($deptKey === 'qc') {
                        $qcItems = $erReceipts->where('status', 'qc_received');
                        foreach ($qcItems as $qi) {
                            $ecnRevertOptions[] = [
                                'source_type' => 'ecn_receipt_item',
                                'source_id' => $qi->id,
                                'available_quantity' => (int)$qi->received_quantity,
                                'from_department' => 'QC',
                                'to_department' => 'STORE',
                                'target_label' => 'Store Bay',
                                'description' => "ECN QC Received ({$qi->received_quantity} pcs)",
                                'is_ecn' => true,
                            ];
                        }
                    } elseif ($deptKey === 'rework') {
                        $rewRecs = $erWorkflow->where('department', 'REWORK')->where('status', 'in_progress');
                        foreach ($rewRecs as $rr) {
                            $ecnRevertOptions[] = [
                                'source_type' => 'ecn_workflow_record',
                                'source_id' => $rr->id,
                                'available_quantity' => (int)$rr->quantity,
                                'from_department' => 'REWORK',
                                'to_department' => 'QC',
                                'target_label' => 'Quality Control Bay',
                                'description' => "ECN Rework Record #{$rr->id} ({$rr->quantity} pcs)",
                                'is_ecn' => true,
                            ];
                        }
                    } elseif ($deptKey === 'paint') {
                        $paintRecs = $erWorkflow->where('department', 'PAINT')->where('status', 'in_progress');
                        foreach ($paintRecs as $pr) {
                            $ecnRevertOptions[] = [
                                'source_type' => 'ecn_workflow_record',
                                'source_id' => $pr->id,
                                'available_quantity' => (int)$pr->quantity,
                                'from_department' => 'PAINT',
                                'to_department' => 'QC',
                                'target_label' => 'Quality Control Bay',
                                'description' => "ECN Paint Record #{$pr->id} ({$pr->quantity} pcs)",
                                'is_ecn' => true,
                            ];
                        }
                    } elseif ($deptKey === 'assembly') {
                        $asmRecs = $erWorkflow->where('department', 'ASSEMBLY')->where('status', 'in_progress');
                        foreach ($asmRecs as $ar) {
                            $ecnRevertOptions[] = [
                                'source_type' => 'ecn_workflow_record',
                                'source_id' => $ar->id,
                                'available_quantity' => (int)$ar->quantity,
                                'from_department' => 'ASSEMBLY',
                                'to_department' => 'QC',
                                'target_label' => 'Quality Control Bay',
                                'description' => "ECN Assembly Record #{$ar->id} ({$ar->quantity} pcs)",
                                'is_ecn' => true,
                            ];
                        }
                    }

                    $ecnSideStat = [
                        'required' => $reqQ,
                        'received' => $recQ,
                        'pending' => $penQ,
                        'parts_in_store' => ($er->current_state === 'STORE' ? $recQ : 0),
                        'parts_in_qc' => $qcResident,
                        'qc_pending_arrival' => $qcPendingArrival,
                        'qc_pending_inspection' => $qcPendingInspection,
                        'qc_approved' => (in_array($er->current_state, ['PAINT', 'ASSEMBLY', 'ASSEMBLY_COMPLETED']) ? $recQ : 0),
                        'qc_rejected' => 0,
                        'qc_rework' => ($er->current_state === 'REWORK' ? $recQ : 0),
                        'parts_in_rework' => ($er->current_state === 'REWORK' ? $recQ : 0),
                        'rework_pending' => ($er->current_state === 'REWORK' ? $recQ : 0),
                        'rework_in_progress' => 0,
                        'rework_completed' => 0,
                        'parts_in_paint' => ($er->current_state === 'PAINT' ? $recQ : 0),
                        'paint_ready' => ($er->current_state === 'PAINT' ? $recQ : 0),
                        'paint_completed' => (in_array($er->current_state, ['ASSEMBLY', 'ASSEMBLY_COMPLETED']) ? $recQ : 0),
                        'parts_in_assembly' => ($er->current_state === 'ASSEMBLY' ? $recQ : 0),
                        'assembly_ready' => ($er->current_state === 'ASSEMBLY' ? $recQ : 0),
                        'assembly_completed' => ($er->current_state === 'ASSEMBLY_COMPLETED' ? $recQ : 0),
                        'status_badge' => $ecnStatusBadge,
                        'status_color' => $ecnStatusColor,
                        'is_done' => ($er->current_state === 'ASSEMBLY_COMPLETED'),
                        'revert_options' => $ecnRevertOptions,
                        'total_revertible' => array_sum(array_column($ecnRevertOptions, 'available_quantity')),
                        'receipt_items' => $erReceipts->values(),
                        'workflow_records' => $erWorkflow->values(),
                    ];

                    $ecnPartData = [
                        'id' => 'ecn_' . $er->id,
                        'standard_part_no' => $er->part_no,
                        'item_no' => $er->part_no,
                        'supplier' => '—',
                        'side' => $sideDisp,
                        'original_side' => $er->side,
                        'side_display' => $sideDisp,
                        'required_qty' => $reqQ,
                        'received_qty' => $recQ,
                        'pending_qty' => $penQ,
                        'status_badge' => $ecnStatusBadge,
                        'status_color' => $ecnStatusColor,
                        'is_done' => ($er->current_state === 'ASSEMBLY_COMPLETED'),
                        'is_ecn' => true,
                        'classification' => 'ECN',
                        'ecn_number' => $er->ecn_number,
                        'ecn_requirement_id' => $er->id,
                        'side_stats' => [
                            $sideDisp => $ecnSideStat,
                        ],
                    ];

                    // Append to master parts list for unit
                    $allUnitParts[] = (object) $ecnPartData;

                    // Append to side list
                    if ($sideDisp === 'LH') {
                        $lhParts[] = $ecnPartData;
                        $lhRequired += $reqQ;
                        $lhReceived += $recQ;
                        $lhPending += $penQ;
                        $lhAsmComp += $ecnSideStat['assembly_completed'];
                        $this->accumulateMetrics($lhMetrics, $ecnSideStat);
                    } else {
                        $rhParts[] = $ecnPartData;
                        $rhRequired += $reqQ;
                        $rhReceived += $recQ;
                        $rhPending += $penQ;
                        $rhAsmComp += $ecnSideStat['assembly_completed'];
                        $this->accumulateMetrics($rhMetrics, $ecnSideStat);
                    }
                }

                $unitData['parts'] = $allUnitParts;

                if (empty($unitData['parts'])) {
                    continue;
                }

                $lhCompletionPct = match ($department) {
                    'store' => ($lhRequired > 0 ? min(100, round(($lhReceived / $lhRequired) * 100, 1)) : 100),
                    'qc' => ($lhRequired > 0 ? min(100, round(($lhMetrics['qc_approved'] / $lhRequired) * 100, 1)) : 100),
                    'rework' => ($lhMetrics['qc_rework'] > 0 ? min(100, round(($lhMetrics['rework_completed'] / $lhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($lhRequired > 0 ? min(100, round(($lhMetrics['paint_completed'] / $lhRequired) * 100, 1)) : 100),
                    default => ($lhRequired > 0 ? min(100, round(($lhAsmComp / $lhRequired) * 100, 1)) : 100),
                };

                $rhCompletionPct = match ($department) {
                    'store' => ($rhRequired > 0 ? min(100, round(($rhReceived / $rhRequired) * 100, 1)) : 100),
                    'qc' => ($rhRequired > 0 ? min(100, round(($rhMetrics['qc_approved'] / $rhRequired) * 100, 1)) : 100),
                    'rework' => ($rhMetrics['qc_rework'] > 0 ? min(100, round(($rhMetrics['rework_completed'] / $rhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($rhRequired > 0 ? min(100, round(($rhMetrics['paint_completed'] / $rhRequired) * 100, 1)) : 100),
                    default => ($rhRequired > 0 ? min(100, round(($rhAsmComp / $rhRequired) * 100, 1)) : 100),
                };

                $lhIsComplete = ($lhRequired > 0 && $lhAsmComp >= $lhRequired);
                $rhIsComplete = ($rhRequired > 0 && $rhAsmComp >= $rhRequired);

                // Section 10: Unit is complete only when both required sides are complete!
                $unitIsComplete = false;
                if ($lhRequired > 0 && $rhRequired > 0) {
                    $unitIsComplete = ($lhIsComplete && $rhIsComplete);
                } elseif ($lhRequired > 0) {
                    $unitIsComplete = $lhIsComplete;
                } elseif ($rhRequired > 0) {
                    $unitIsComplete = $rhIsComplete;
                }

                $rawU = trim(str_ireplace('unit', '', $unitNo));
                $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;

                $uEcnCount = $ecnMap['units'][$jigName . '|' . $rawU] 
                    ?? ($ecnMap['units'][$jigName . '|' . $unitNo] 
                    ?? ($ecnMap['units'][$jigName . '|Unit ' . $rawU] 
                    ?? ($ecnMap['units'][$jigName . '|Unit ' . $paddedU] 
                    ?? ($ecnMap['units'][strtoupper($jigName) . '|' . $rawU] 
                    ?? ($ecnMap['units'][strtoupper($jigName) . '|Unit ' . $rawU] 
                    ?? ($ecnMap['units'][strtoupper($jigName) . '|Unit ' . $paddedU] ?? 0))))));

                $uEcnNums = $ecnMap['unit_ecn_numbers'][$jigName . '|' . $rawU] 
                    ?? ($ecnMap['unit_ecn_numbers'][$jigName . '|' . $unitNo] 
                    ?? ($ecnMap['unit_ecn_numbers'][$jigName . '|Unit ' . $rawU] 
                    ?? ($ecnMap['unit_ecn_numbers'][$jigName . '|Unit ' . $paddedU] ?? [])));

                $uEcnSum = $ecnMap['unit_ecn_summary'][$jigName . '|' . $rawU] 
                    ?? ($ecnMap['unit_ecn_summary'][$jigName . '|' . $unitNo] 
                    ?? ($ecnMap['unit_ecn_summary'][$jigName . '|Unit ' . $rawU] 
                    ?? ($ecnMap['unit_ecn_summary'][$jigName . '|Unit ' . $paddedU] ?? [])));

                $uEcnDisp = $ecnMap['unit_ecn_display'][$jigName . '|' . $rawU] 
                    ?? ($ecnMap['unit_ecn_display'][$jigName . '|' . $unitNo] 
                    ?? ($ecnMap['unit_ecn_display'][$jigName . '|Unit ' . $rawU] 
                    ?? ($ecnMap['unit_ecn_display'][$jigName . '|Unit ' . $paddedU] ?? null)));

                $lhEcnCount = $ecnMap['sides'][$jigName . '|' . $rawU . '|LH'] 
                    ?? ($ecnMap['sides'][$jigName . '|' . $unitNo . '|LH'] 
                    ?? ($ecnMap['sides'][$jigName . '|Unit ' . $rawU . '|LH'] 
                    ?? ($ecnMap['sides'][$jigName . '|Unit ' . $paddedU . '|LH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|' . $rawU . '|LH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|' . $unitNo . '|LH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|Unit ' . $rawU . '|LH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|Unit ' . $paddedU . '|LH'] ?? 0)))))));

                $rhEcnCount = $ecnMap['sides'][$jigName . '|' . $rawU . '|RH'] 
                    ?? ($ecnMap['sides'][$jigName . '|' . $unitNo . '|RH'] 
                    ?? ($ecnMap['sides'][$jigName . '|Unit ' . $rawU . '|RH'] 
                    ?? ($ecnMap['sides'][$jigName . '|Unit ' . $paddedU . '|RH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|' . $rawU . '|RH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|' . $unitNo . '|RH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|Unit ' . $rawU . '|RH'] 
                    ?? ($ecnMap['sides'][strtoupper($jigName) . '|Unit ' . $paddedU . '|RH'] ?? 0)))))));

                // Format display from canonical map or fallback
                if ($uEcnCount > 0 && empty($uEcnDisp)) {
                    $uWord = $uEcnCount === 1 ? 'part' : 'parts';
                    $uEcnDisp = "ECN ({$uEcnCount} {$uWord})";
                } elseif ($uEcnCount <= 0 && $department === 'manager' && (!empty($lhParts) || !empty($rhParts))) {
                    $unitEcnParts = array_filter(array_merge($lhParts, $rhParts), fn($p) => !empty($p['is_ecn']));
                    if (!empty($unitEcnParts)) {
                        $uEcnCount = count($unitEcnParts);
                        $uEcnNums = array_unique(array_filter(array_column($unitEcnParts, 'ecn_number')));
                        $uWord = $uEcnCount === 1 ? 'part' : 'parts';
                        $uEcnDisp = "ECN ({$uEcnCount} {$uWord})";
                    }
                }

                $unitData['ecn_count'] = $uEcnCount;
                $unitData['ecn_parts'] = $uEcnCount;
                $unitData['ecn_part_count'] = $uEcnCount;
                $unitData['is_ecn_present'] = ($uEcnCount > 0);
                $unitData['ecn_present'] = ($uEcnCount > 0);
                $unitData['ecn_numbers'] = $uEcnNums;
                $unitData['ecn_summary'] = $uEcnSum;
                $unitData['ecn_number_display'] = $uEcnDisp;
                $unitData['sides'] = [
                    'LH' => [
                        'side' => 'LH',
                        'total_parts' => count($lhParts),
                        'ecn_count' => $lhEcnCount,
                        'ecn_present' => ($lhEcnCount > 0),
                        'is_ecn_present' => ($lhEcnCount > 0),
                        'total_required' => $lhRequired,
                        'total_received' => $lhReceived,
                        'pending_quantity' => $lhPending,
                        'assembly_completed' => $lhAsmComp,
                        'completion_pct' => $lhCompletionPct,
                        'is_complete' => $lhIsComplete,
                        'parts' => $lhParts,
                        'metrics' => $lhMetrics,
                    ],
                    'RH' => [
                        'side' => 'RH',
                        'total_parts' => count($rhParts),
                        'ecn_count' => $rhEcnCount,
                        'ecn_present' => ($rhEcnCount > 0),
                        'is_ecn_present' => ($rhEcnCount > 0),
                        'total_required' => $rhRequired,
                        'total_received' => $rhReceived,
                        'pending_quantity' => $rhPending,
                        'assembly_completed' => $rhAsmComp,
                        'completion_pct' => $rhCompletionPct,
                        'is_complete' => $rhIsComplete,
                        'parts' => $rhParts,
                        'metrics' => $rhMetrics,
                    ],
                ];

                $unitData['completion_pct'] = match ($department) {
                    'store' => ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 100),
                    'qc' => ($req > 0 ? min(100, round(($unitData['metrics']['qc_approved'] / $req) * 100, 1)) : 100),
                    'rework' => ($unitData['metrics']['qc_rework'] > 0 ? min(100, round(($unitData['metrics']['rework_completed'] / $unitData['metrics']['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($req > 0 ? min(100, round(($unitData['metrics']['paint_completed'] / $req) * 100, 1)) : 100),
                    default => ($req > 0 ? min(100, round(($unitData['metrics']['assembly_completed'] / $req) * 100, 1)) : 100),
                };
                $unitData['is_complete'] = $unitIsComplete;

                if ($unitData['is_complete']) {
                    $completeUnitsCount++;
                }

                $formattedUnits[] = $unitData;
            }

            if (empty($formattedUnits)) {
                continue;
            }

            usort($formattedUnits, fn($a, $b) => strcmp($a['unit_no'], $b['unit_no']));

            $totalUnitsCount = count($formattedUnits);
            $jigData['complete_units'] = $completeUnitsCount;
            $jigData['total_units'] = $totalUnitsCount;
            // Section 10: Jig turns green only when ALL units in it are complete
            $jigData['is_complete'] = ($totalUnitsCount > 0 && $completeUnitsCount === $totalUnitsCount);
            $jigEcnCount = $ecnMap['jigs'][$jigName] ?? ($ecnMap['jigs'][strtoupper(trim($jigName))] ?? 0);
            $jigEcnNums = $ecnMap['jig_ecn_numbers'][$jigName] ?? ($ecnMap['jig_ecn_numbers'][strtoupper(trim($jigName))] ?? []);
            $jigEcnSum = $ecnMap['jig_ecn_summary'][$jigName] ?? ($ecnMap['jig_ecn_summary'][strtoupper(trim($jigName))] ?? []);
            $jigEcnDisp = $ecnMap['jig_ecn_display'][$jigName] ?? ($ecnMap['jig_ecn_display'][strtoupper(trim($jigName))] ?? null);

            // Fallback: If map returned 0, sum ecn parts across all units formatted in this Jig!
            if ($jigEcnCount <= 0 && !empty($formattedUnits)) {
                $jigEcnCount = array_sum(array_column($formattedUnits, 'ecn_part_count'));
                if ($jigEcnCount > 0) {
                    $jWord = $jigEcnCount === 1 ? 'part' : 'parts';
                    $jigEcnDisp = "ECN ({$jigEcnCount} {$jWord})";
                }
            } elseif ($jigEcnCount > 0 && empty($jigEcnDisp)) {
                $jWord = $jigEcnCount === 1 ? 'part' : 'parts';
                $jigEcnDisp = "ECN ({$jigEcnCount} {$jWord})";
            }

            $jigData['ecn_count'] = $jigEcnCount;
            $jigData['ecn_parts'] = $jigEcnCount;
            $jigData['ecn_part_count'] = $jigEcnCount;
            $jigData['is_ecn_present'] = ($jigEcnCount > 0);
            $jigData['ecn_present'] = ($jigEcnCount > 0);
            $jigData['ecn_numbers'] = $jigEcnNums;
            $jigData['ecn_summary'] = $jigEcnSum;
            $jigData['ecn_number_display'] = $jigEcnDisp;

            $jigReq = $jigData['total_required'];
            $jigRec = $jigData['total_received'];

            $jigData['completion_pct'] = match ($department) {
                'store' => ($jigReq > 0 ? min(100, round(($jigRec / $jigReq) * 100, 1)) : 100),
                'qc' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['qc_approved'] / $jigReq) * 100, 1)) : 100),
                'rework' => ($jigData['metrics']['qc_rework'] > 0 ? min(100, round(($jigData['metrics']['rework_completed'] / $jigData['metrics']['qc_rework']) * 100, 1)) : 100),
                'paint' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['paint_completed'] / $jigReq) * 100, 1)) : 100),
                default => ($jigReq > 0 ? min(100, round(($jigData['metrics']['assembly_completed'] / $jigReq) * 100, 1)) : 100),
            };

            $jigData['units'] = $formattedUnits;
            $formattedJigs[] = $jigData;
        }

        // Section 3: Ordering Jigs - Incomplete Jigs first, Completed Jigs at bottom
        usort($formattedJigs, function ($a, $b) {
            if ($a['is_complete'] !== $b['is_complete']) {
                return $a['is_complete'] ? 1 : -1;
            }
            return strcmp($a['jig_name'], $b['jig_name']);
        });

        $allProjects = Project::orderBy('name')->get();
        $activeProjects = $allProjects->where('status', 'active')->values();
        $completedProjects = $allProjects->where('status', 'completed')->values();

        if ($project) {
            $projEcnTotal = $ecnMap['project_total'] ?? 0;
            $project->setAttribute('ecn_parts', $projEcnTotal);
            $project->setAttribute('ecn_total_parts', $projEcnTotal);
            $project->setAttribute('ecn_part_count', $projEcnTotal);
            $project->setAttribute('ecn_count', $projEcnTotal);
            $project->setAttribute('is_ecn_present', ($projEcnTotal > 0));
            $project->setAttribute('ecn_present', ($projEcnTotal > 0));
            $project->setAttribute('ecn_numbers', $ecnMap['project_ecn_numbers'] ?? []);
            $project->setAttribute('ecn_summary', $ecnMap['project_ecn_summary'] ?? []);
            $project->setAttribute('ecn_number_display', $ecnMap['project_ecn_display'] ?? null);
            $project->ecn_parts = $projEcnTotal;
            $project->ecn_number_display = $ecnMap['project_ecn_display'] ?? null;
        }

        return [
            'is_hierarchical' => count($formattedJigs) > 0,
            'department' => $department,
            'project' => $project,
            'canonical_summary' => $project ? $this->quantityService->calculateProjectMetrics($project, $filters['side'] ?? null, $filters) : null,
            'jigs' => $formattedJigs,
            'projects' => $projectsList,
            'active_projects' => $activeProjects,
            'completed_projects' => $completedProjects,
            'total_jigs' => count($formattedJigs),
            'completed_jigs' => count(array_filter($formattedJigs, fn($j) => $j['is_complete'])),
            'message' => count($formattedJigs) === 0 ? "No BOM hierarchy found for this project." : null,
        ];
    }

    /**
     * Get high level progress stats for Project level cards from pre-calculated metrics
     */
    public function formatProjectOverviewStatsFromMetrics(Project $proj, string $department, array $m = [], array $filters = []): array
    {
        $stage = strtolower($filters['stage'] ?? $filters['queue_type'] ?? $filters['subtab'] ?? '');
        $ecnDeptContext = $department;
        if ($department === 'qc') {
            if ($stage === 'arrival') {
                $ecnDeptContext = 'qc_arrival';
            } elseif ($stage === 'inspection') {
                $ecnDeptContext = 'qc_inspection';
            }
        }

        $reqSum = $m['total_required'] ?? $m['required_qty'] ?? 0;
        $recSum = $m['total_received'] ?? $m['received_qty'] ?? 0;
        $appSum = $m['approved_qty'] ?? $m['qc_approved'] ?? 0;
        $qcPendingSum = $m['pending_qc'] ?? $m['qc_pending_inspection'] ?? ($recSum - $appSum);
        $rewCompSum = $m['rework_completed'] ?? 0;
        $rewActiveSum = $m['rework_active'] ?? $m['rework_pending'] ?? 0;
        $paintCompSum = $m['paint_completed'] ?? $m['paint_qty'] ?? 0;
        $asmCompSum = $m['assembly_completed'] ?? $m['assembly_qty'] ?? 0;
        $paintReadySum = $m['paint_ready'] ?? $m['parts_in_paint'] ?? 0;
        $asmReadySum = $m['assembly_ready'] ?? $m['parts_in_assembly'] ?? 0;

        $eligibleCount = match ($department) {
            'store' => $m['pending_qty'] ?? max(0, $reqSum - $recSum),
            'qc' => $qcPendingSum,
            'rework' => $rewActiveSum,
            'paint' => $paintReadySum,
            'assembly' => $asmReadySum,
            default => $reqSum,
        };

        $progressPercent = match ($department) {
            'store' => ($reqSum > 0 ? min(100, round(($recSum / $reqSum) * 100, 1)) : 100),
            'qc' => ($reqSum > 0 ? min(100, round(($appSum / $reqSum) * 100, 1)) : 100),
            'rework' => ($rewCompSum + $rewActiveSum > 0 ? min(100, round(($rewCompSum / ($rewCompSum + $rewActiveSum)) * 100, 1)) : 100),
            'paint' => ($reqSum > 0 ? min(100, round(($paintCompSum / $reqSum) * 100, 1)) : 100),
            'assembly' => ($reqSum > 0 ? min(100, round(($asmCompSum / $reqSum) * 100, 1)) : 100),
            default => ($reqSum > 0 ? min(100, round(($asmCompSum / $reqSum) * 100, 1)) : 100),
        };

        $projEcnCount = $this->ecnQuantityService->getEcnCountsForHierarchy($proj->id, null, null, null, $ecnDeptContext);
        $projEcnNums = $this->ecnQuantityService->getEcnNumbersForHierarchy($proj->id, null, null, $ecnDeptContext);
        $projEcnSum = $this->ecnQuantityService->getEcnSummaryForHierarchy($proj->id, null, null, $ecnDeptContext);
        $projEcnDisp = $this->ecnQuantityService->getEcnDisplayForHierarchy($proj->id, null, null, $ecnDeptContext);

        if ($projEcnCount > 0 && empty($projEcnDisp)) {
            $pWord = $projEcnCount === 1 ? 'part' : 'parts';
            $projEcnDisp = "ECN ({$projEcnCount} {$pWord})";
        }

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_items' => $m['total_items'] ?? 0,
            'total_required' => $reqSum,
            'total_received' => $recSum,
            'required_qty' => $reqSum,
            'received_qty' => $recSum,
            'raw_received' => $m['raw_received'] ?? $recSum,
            'excess_received' => $m['excess_received'] ?? 0,
            'pending_qty' => $m['pending_qty'] ?? max(0, $reqSum - $recSum),
            'approved_qty' => $appSum,
            'paint_qty' => $paintCompSum,
            'assembly_qty' => $asmCompSum,
            'eligible_qty' => $eligibleCount,
            'has_eligible_parts' => ($department === 'store' || $department === 'manager' || $eligibleCount > 0 || $progressPercent >= 100),
            'progress_percent' => min(100, $progressPercent),
            'completion_pct' => min(100, $progressPercent),
            'is_complete' => ($progressPercent >= 100),
            'ecn_count' => $projEcnCount,
            'ecn_parts' => $projEcnCount,
            'ecn_total_parts' => $projEcnCount,
            'ecn_part_count' => $projEcnCount,
            'is_ecn_present' => ($projEcnCount > 0),
            'ecn_present' => ($projEcnCount > 0),
            'ecn_numbers' => $projEcnNums,
            'ecn_summary' => $projEcnSum,
            'ecn_number_display' => $projEcnDisp,
        ];
    }

    /**
     * Get high level progress stats for Project level cards
     */
    protected function getProjectOverviewStats(Project $proj, string $department, array $filters = []): array
    {
        $side = $filters['side'] ?? null;
        $m = $this->quantityService->calculateProjectMetrics($proj, $side, $filters);
        return $this->formatProjectOverviewStatsFromMetrics($proj, $department, $m);
    }

    protected function initZeroMetrics(): array
    {
        return [
            'total_required' => 0,
            'total_received' => 0,
            'total_pending' => 0,
            'qc_pending_arrival' => 0,
            'qc_pending_inspection' => 0,
            'qc_approved' => 0,
            'qc_rejected' => 0,
            'qc_rework' => 0,
            'rework_pending' => 0,
            'rework_in_progress' => 0,
            'rework_completed' => 0,
            'paint_ready' => 0,
            'paint_completed' => 0,
            'assembly_ready' => 0,
            'assembly_completed' => 0,
        ];
    }

    protected function accumulateMetrics(array &$target, array $source): void
    {
        foreach ($source as $k => $v) {
            if (isset($target[$k])) {
                $target[$k] += (int) $v;
            }
        }
    }
}
