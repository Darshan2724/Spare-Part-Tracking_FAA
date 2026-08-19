<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QuantityCalculationService
{
    /**
     * Active/Valid Receipt Statuses that represent valid physical inventory
     * (excludes reverted, returned_to_vendor, scrapped).
     */
    public const VALID_RECEIPT_STATUSES = [
        'received',
        'sent_to_qc',
        'qc_received',
        'qc_approved',
        'qc_rework',
        'qc_inspected',
        'paint_completed',
        'assembly_completed',
        'returned_to_store'
    ];

    /**
     * Calculate authoritative metrics for a single project.
     * Enforces mathematical consistency across all hierarchy levels.
     *
     * @param int|Project $project
     * @param string|null $sideFilter 'RH' | 'LH' | 'COMMON' | null
     * @param array $filters Additional filters: ['supplier_id', 'date_from', 'date_to']
     * @return array
     */
    public function calculateProjectMetrics(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $proj = $project instanceof Project ? $project : Project::findOrFail($project);

        $bomItemsQuery = BomItem::query()
            ->with(['requirements', 'supplier'])
            ->where('project_id', $proj->id);

        if (!empty($filters['supplier_id'])) {
            $bomItemsQuery->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $bomItemsQuery->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $bomItems = $bomItemsQuery->orderBy('standard_part_no')->get();

        if ($bomItems->isEmpty()) {
            return $this->formatProjectSummaryResult($proj, [
                'total_required' => 0,
                'total_received' => 0,
                'raw_received' => 0,
                'excess_received' => 0,
                'total_pending' => 0,
                'completion_pct' => 0,
                'awaiting_qc' => 0,
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
                'total_items' => 0,
            ]);
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Bulk load all related operational records strictly for this project's items
        $recQuery = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', self::VALID_RECEIPT_STATUSES);

        $qcQuery = QcInspection::query()->whereIn('bom_item_id', $bomItemIds);
        $reworkQuery = ReworkRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $paintQuery = PaintRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $asmQuery = AssemblyRecord::query()->whereIn('bom_item_id', $bomItemIds);

        if (!empty($filters['date_from'])) {
            $recQuery->where('created_at', '>=', $filters['date_from']);
            $qcQuery->where('inspection_date', '>=', $filters['date_from']);
            $reworkQuery->where('created_at', '>=', $filters['date_from']);
            $paintQuery->where('created_at', '>=', $filters['date_from']);
            $asmQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $recQuery->where('created_at', '<=', $filters['date_to']);
            $qcQuery->where('inspection_date', '<=', $filters['date_to']);
            $reworkQuery->where('created_at', '<=', $filters['date_to']);
            $paintQuery->where('created_at', '<=', $filters['date_to']);
            $asmQuery->where('created_at', '<=', $filters['date_to']);
        }

        $receiptsGrouped = $recQuery->get()->groupBy('bom_item_id');
        $qcGrouped = $qcQuery->get()->groupBy('bom_item_id');
        $reworkGrouped = $reworkQuery->get()->groupBy('bom_item_id');
        $paintGrouped = $paintQuery->get()->groupBy('bom_item_id');
        $asmGrouped = $asmQuery->get()->groupBy('bom_item_id');

        $metrics = [
            'total_required' => 0,
            'total_received' => 0,      // Canonical: capped at min(received, required) per part+side
            'raw_received' => 0,        // Total physical receipts sum
            'excess_received' => 0,     // Total over-receipt sum (raw_received - total_received)
            'total_pending' => 0,       // Canonical: max(0, required - total_received)
            'awaiting_qc' => 0,
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
            'total_items' => $bomItems->count(),
        ];

        foreach ($bomItems as $item) {
            $itemReceipts = $receiptsGrouped->get($item->id, collect());
            $itemQc = $qcGrouped->get($item->id, collect());
            $itemRework = $reworkGrouped->get($item->id, collect());
            $itemPaint = $paintGrouped->get($item->id, collect());
            $itemAsm = $asmGrouped->get($item->id, collect());

            foreach ($item->requirements as $req) {
                $side = $req->side;

                // Side isolation filter
                if (!empty($sideFilter) && $sideFilter !== $side && $side !== 'COMMON') {
                    continue;
                }

                $reqQty = (int) $req->required_quantity;
                $recForSide = $itemReceipts->where('side', $side);
                $qcForSide = $itemQc->where('side', $side);
                $reworkForSide = $itemRework->where('side', $side);
                $paintForSide = $itemPaint->where('side', $side);
                $asmForSide = $itemAsm->where('side', $side);

                $rawRecQty = (int) $recForSide->sum('received_quantity');
                $effectiveRecQty = min($rawRecQty, $reqQty);
                $excessQty = max(0, $rawRecQty - $reqQty);
                $pendingQty = max(0, $reqQty - $effectiveRecQty);

                // QC Stats
                $qcPendingArrival = (int) $recForSide->whereIn('status', ['received', 'sent_to_qc'])->sum('received_quantity');
                $qcPendingInsp = (int) $recForSide->where('status', 'qc_received')->sum('received_quantity');
                $qcApp = (int) $qcForSide->sum('approved_quantity');
                $qcRej = (int) $qcForSide->sum('rejected_quantity');
                $qcRew = (int) $qcForSide->sum('rework_quantity');

                // Rework Stats
                $rewPending = (int) $reworkForSide->where('status', 'pending')->sum('quantity');
                $rewProg = (int) $reworkForSide->where('status', 'in_progress')->sum('quantity');
                $rewComp = (int) $reworkForSide->where('status', 'completed')->sum('quantity');

                // Paint Stats
                $qcAppPaint = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirectAssembly = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $paintComp = (int) $paintForSide->where('status', 'completed')->sum('quantity');
                $paintReady = max(0, $qcAppPaint - $paintComp);

                // Assembly Stats
                $asmComp = (int) $asmForSide->where('status', 'completed')->sum('quantity');
                $asmReady = max(0, ($paintComp + $qcAppDirectAssembly) - $asmComp);

                // Accumulate strictly into project canonical metrics
                $metrics['total_required'] += $reqQty;
                $metrics['total_received'] += $effectiveRecQty;
                $metrics['raw_received'] += $rawRecQty;
                $metrics['excess_received'] += $excessQty;
                $metrics['total_pending'] += $pendingQty;
                $metrics['awaiting_qc'] += ($qcPendingArrival + $qcPendingInsp);
                $metrics['qc_approved'] += $qcApp;
                $metrics['qc_rejected'] += $qcRej;
                $metrics['qc_rework'] += $qcRew;
                $metrics['rework_pending'] += $rewPending;
                $metrics['rework_in_progress'] += $rewProg;
                $metrics['rework_completed'] += $rewComp;
                $metrics['paint_ready'] += $paintReady;
                $metrics['paint_completed'] += $paintComp;
                $metrics['assembly_ready'] += $asmReady;
                $metrics['assembly_completed'] += $asmComp;
            }
        }

        $metrics['completion_pct'] = $metrics['total_required'] > 0
            ? min(100, round(($metrics['total_received'] / $metrics['total_required']) * 100, 1))
            : 0;

        return $this->formatProjectSummaryResult($proj, $metrics);
    }

    /**
     * Compute authoritative dashboard summary across all projects (or filtered project).
     * Guarantees that Dashboard KPI cards equal the sum of active project metrics.
     *
     * @param array $filters ['project_id', 'side', 'supplier_id', 'date_from', 'date_to']
     * @return array
     */
    public function calculateDashboardSummary(array $filters = []): array
    {
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;
        $side = !empty($filters['side']) ? $filters['side'] : null;

        $projectsQuery = Project::query();
        if ($projectId) {
            $projectsQuery->where('id', $projectId);
        }

        $projects = $projectsQuery->get();

        $totalProjects = Project::where('status', 'active')->count();
        $completedProjects = Project::where('status', 'completed')->count();
        
        $delayedProjects = Project::where('status', 'active')
            ->where('created_at', '<', now()->subDays(14))
            ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
            ->count();

        $pqQuery = PurchaseQueueItem::query();
        if ($projectId) {
            $pqQuery->where('project_id', $projectId);
        }
        if ($side) {
            $pqQuery->where('side', $side);
        }
        if (!empty($filters['date_from'])) {
            $pqQuery->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $pqQuery->where('created_at', '<=', $filters['date_to']);
        }
        $pendingPurchase = (int) $pqQuery->where('status', 'pending_purchase')->count();

        $grandSummary = [
            'total_projects' => $totalProjects,
            'completed_projects' => $completedProjects,
            'delayed_projects' => $delayedProjects,
            'total_required' => 0,
            'total_received' => 0,
            'raw_received' => 0,
            'excess_received' => 0,
            'pending_store' => 0,
            'awaiting_qc' => 0,
            'qc_approved' => 0,
            'qc_rework' => 0,
            'qc_rejected' => 0,
            'pending_purchase' => $pendingPurchase,
            'paint_pending' => 0,
            'paint_completed' => 0,
            'assembly_pending' => 0,
            'assembly_completed' => 0,
            'completion_pct' => 0,
        ];

        foreach ($projects as $proj) {
            $pMetrics = $this->calculateProjectMetrics($proj, $side, $filters);
            $grandSummary['total_required'] += $pMetrics['required_qty'];
            $grandSummary['total_received'] += $pMetrics['received_qty'];
            $grandSummary['raw_received'] += $pMetrics['raw_received'];
            $grandSummary['excess_received'] += $pMetrics['excess_received'];
            $grandSummary['pending_store'] += $pMetrics['pending_qty'];
            $grandSummary['awaiting_qc'] += $pMetrics['awaiting_qc'];
            $grandSummary['qc_approved'] += $pMetrics['approved_qty'];
            $grandSummary['qc_rework'] += $pMetrics['qc_rework'];
            $grandSummary['qc_rejected'] += $pMetrics['rejected_qty'];
            $grandSummary['paint_pending'] += $pMetrics['paint_ready'];
            $grandSummary['paint_completed'] += $pMetrics['paint_qty'];
            $grandSummary['assembly_pending'] += $pMetrics['assembly_ready'];
            $grandSummary['assembly_completed'] += $pMetrics['assembly_qty'];
        }

        $grandSummary['completion_pct'] = $grandSummary['total_required'] > 0
            ? min(100, round(($grandSummary['total_received'] / $grandSummary['total_required']) * 100, 1))
            : 0;

        return $grandSummary;
    }

    /**
     * Compute progress breakdown across all projects for Dashboard Project Progress table.
     *
     * @param array $filters
     * @return Collection
     */
    public function calculateProjectsProgress(array $filters = []): Collection
    {
        $projects = Project::withCount('bomItems')->get();
        $side = $filters['side'] ?? null;

        return $projects->map(function ($proj) use ($side, $filters) {
            $pMetrics = $this->calculateProjectMetrics($proj, $side, $filters);

            return [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'total_items' => $proj->bom_items_count,
                'required_qty' => $pMetrics['required_qty'],
                'received_qty' => $pMetrics['received_qty'],
                'raw_received' => $pMetrics['raw_received'],
                'excess_received' => $pMetrics['excess_received'],
                'pending_qty' => $pMetrics['pending_qty'],
                'approved_qty' => $pMetrics['approved_qty'],
                'rework_qty' => $pMetrics['rework_qty'],
                'rejected_qty' => $pMetrics['rejected_qty'],
                'paint_qty' => $pMetrics['paint_qty'],
                'assembly_qty' => $pMetrics['assembly_qty'],
                'progress_percent' => $pMetrics['progress_percent'],
                'is_complete' => $pMetrics['is_complete'],
            ];
        });
    }

    /**
     * Format standardized project summary result dictionary.
     */
    protected function formatProjectSummaryResult(Project $proj, array $m): array
    {
        $req = $m['total_required'] ?? $m['required_qty'] ?? 0;
        $rec = $m['total_received'] ?? $m['received_qty'] ?? 0;
        $pending = $m['total_pending'] ?? $m['pending_qty'] ?? max(0, $req - $rec);
        $completion = $m['completion_pct'] ?? ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 0);

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_items' => $m['total_items'] ?? 0,
            'total_required' => $req,
            'total_received' => $rec,
            'required_qty' => $req,
            'received_qty' => $rec,
            'raw_received' => $m['raw_received'] ?? $rec,
            'excess_received' => $m['excess_received'] ?? 0,
            'pending_qty' => $pending,
            'awaiting_qc' => $m['awaiting_qc'] ?? 0,
            'approved_qty' => $m['qc_approved'] ?? $m['approved_qty'] ?? 0,
            'rejected_qty' => $m['qc_rejected'] ?? $m['rejected_qty'] ?? 0,
            'rework_qty' => $m['rework_pending'] ?? $m['rework_qty'] ?? 0,
            'qc_rework' => $m['qc_rework'] ?? 0,
            'paint_ready' => $m['paint_ready'] ?? 0,
            'paint_qty' => $m['paint_completed'] ?? $m['paint_qty'] ?? 0,
            'assembly_ready' => $m['assembly_ready'] ?? 0,
            'assembly_qty' => $m['assembly_completed'] ?? $m['assembly_qty'] ?? 0,
            'progress_percent' => $completion,
            'completion_pct' => $completion,
            'is_complete' => ($completion >= 100 && $req > 0),
        ];
    }
}
