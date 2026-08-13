<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\BomRequirement;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PurchaseQueueItem;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $side = $request->query('side');
        $supplierId = $request->query('supplier_id');

        // Query builders
        $reqQuery = BomRequirement::query();
        $recQuery = ReceiptItem::query();
        $qcQuery = QcInspection::query();
        $reworkQuery = ReworkRecord::query();
        $pqQuery = PurchaseQueueItem::query();
        $paintQuery = PaintRecord::query();
        $asmQuery = AssemblyRecord::query();

        if ($projectId) {
            $reqQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $recQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $qcQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $reworkQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $pqQuery->where('project_id', $projectId);
            $paintQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
            $asmQuery->whereHas('bomItem', fn($q) => $q->where('project_id', $projectId));
        }

        if ($side) {
            $reqQuery->where('side', $side);
            $recQuery->where('side', $side);
            $qcQuery->where('side', $side);
            $reworkQuery->where('side', $side);
            $pqQuery->where('side', $side);
            $paintQuery->where('side', $side);
            $asmQuery->where('side', $side);
        }

        if ($supplierId) {
            $reqQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $recQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $qcQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $reworkQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $paintQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
            $asmQuery->whereHas('bomItem', fn($q) => $q->where('supplier_id', $supplierId));
        }

        if ($dateFrom) {
            $recQuery->where('created_at', '>=', $dateFrom);
            $qcQuery->where('inspection_date', '>=', $dateFrom);
            $reworkQuery->where('created_at', '>=', $dateFrom);
            $pqQuery->where('created_at', '>=', $dateFrom);
            $paintQuery->where('created_at', '>=', $dateFrom);
            $asmQuery->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $recQuery->where('created_at', '<=', $dateTo);
            $qcQuery->where('inspection_date', '<=', $dateTo);
            $reworkQuery->where('created_at', '<=', $dateTo);
            $pqQuery->where('created_at', '<=', $dateTo);
            $paintQuery->where('created_at', '<=', $dateTo);
            $asmQuery->where('created_at', '<=', $dateTo);
        }

        $totalProjects = Project::where('status', 'active')->count();
        $completedProjects = Project::where('status', 'completed')->count();
        
        // Delayed projects: active projects with zero receipt updates in last 14 days
        $delayedProjects = Project::where('status', 'active')
            ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
            ->count();

        $totalRequired = (int) $reqQuery->sum('required_quantity');
        $totalReceived = (int) $recQuery->sum('received_quantity');
        $totalPendingStore = max(0, $totalRequired - $totalReceived);

        $awaitingQc = (int) (clone $recQuery)->whereIn('status', ['received', 'sent_to_qc', 'qc_received'])->sum('received_quantity');
        $qcApproved = (int) $qcQuery->sum('approved_quantity');
        $qcRework   = (int) (clone $reworkQuery)->whereIn('status', ['pending', 'in_progress'])->sum('quantity');
        $qcRejected = (int) $qcQuery->sum('rejected_quantity');

        $pendingPurchase = (int) (clone $pqQuery)->where('status', 'pending_purchase')->count();

        $paintPending   = (int) (clone $recQuery)->where('status', 'qc_approved')->sum('received_quantity');
        $paintCompleted = (int) (clone $paintQuery)->where('status', 'completed')->sum('quantity');

        $assemblyPending   = (int) (clone $paintQuery)->where('status', 'completed')->sum('quantity');
        $assemblyCompleted = (int) (clone $asmQuery)->where('status', 'completed')->sum('quantity');

        // Part Status Distribution
        $statusDistribution = (clone $recQuery)
            ->select('status', DB::raw('SUM(received_quantity) as total_qty'), DB::raw('COUNT(*) as total_items'))
            ->groupBy('status')
            ->get()
            ->pluck('total_qty', 'status');

        // Delayed Parts (> 3 days in current status without progress)
        $delayedParts = ReceiptItem::query()
            ->with(['bomItem.project'])
            ->whereNotIn('status', ['assembly_completed', 'qc_rejected', 'reverted'])
            ->where('updated_at', '<', now()->subDays(3))
            ->orderBy('updated_at', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'standard_part_no' => $item->bomItem?->standard_part_no ?? 'Part #' . $item->id,
                    'project' => $item->bomItem?->project?->name ?? 'N/A',
                    'side' => $item->side,
                    'status' => $item->status,
                    'waiting_since' => $item->updated_at->toDateTimeString(),
                    'duration_days' => round(now()->diffInHours($item->updated_at) / 24, 1),
                ];
            });

        // Quality Trend (30 Days)
        $qualityTrend = QcInspection::query()
            ->select(
                DB::raw('DATE(inspection_date) as date'),
                DB::raw('SUM(approved_quantity) as approved'),
                DB::raw('SUM(rework_quantity) as rework'),
                DB::raw('SUM(rejected_quantity) as rejected')
            )
            ->where('inspection_date', '>=', now()->subDays(30))
            ->groupBy(DB::raw('DATE(inspection_date)'))
            ->orderBy('date', 'asc')
            ->get();

        // Recent Workflow Activity Log
        $recentEvents = WorkflowEvent::query()
            ->with(['bomItem.project', 'user'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Project Progress Breakdown
        $projectsProgress = Project::withCount('bomItems')
            ->get()
            ->map(function ($proj) {
                $reqSum = (int) BomRequirement::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('required_quantity');
                $recSum = (int) ReceiptItem::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('received_quantity');
                $appSum = (int) QcInspection::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('approved_quantity');
                $rewSum = (int) ReworkRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->whereIn('status', ['pending', 'in_progress'])->sum('quantity');
                $rejSum = (int) QcInspection::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('rejected_quantity');
                $paintSum = (int) PaintRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->where('status', 'completed')->sum('quantity');
                $asmSum = (int) AssemblyRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->where('status', 'completed')->sum('quantity');
                
                $progress = $reqSum > 0 ? min(100, round(($recSum / $reqSum) * 100, 1)) : 0;

                return [
                    'id' => $proj->id,
                    'project_code' => $proj->project_code,
                    'name' => $proj->name,
                    'total_items' => $proj->bom_items_count,
                    'required_qty' => $reqSum,
                    'received_qty' => $recSum,
                    'approved_qty' => $appSum,
                    'rework_qty' => $rewSum,
                    'rejected_qty' => $rejSum,
                    'paint_qty' => $paintSum,
                    'assembly_qty' => $asmSum,
                    'progress_percent' => $progress,
                ];
            });

        return response()->json([
            'summary' => [
                'total_projects' => $totalProjects,
                'completed_projects' => $completedProjects,
                'delayed_projects' => $delayedProjects,
                'total_required' => $totalRequired,
                'total_received' => $totalReceived,
                'pending_store' => $totalPendingStore,
                'awaiting_qc' => $awaitingQc,
                'qc_approved' => $qcApproved,
                'qc_rework' => $qcRework,
                'qc_rejected' => $qcRejected,
                'pending_purchase' => $pendingPurchase,
                'paint_pending' => $paintPending,
                'paint_completed' => $paintCompleted,
                'assembly_pending' => $assemblyPending,
                'assembly_completed' => $assemblyCompleted,
            ],
            'status_distribution' => $statusDistribution,
            'delayed_parts' => $delayedParts,
            'quality_trend' => $qualityTrend,
            'recent_events' => $recentEvents,
            'projects_progress' => $projectsProgress,
        ]);
    }

    public function bottleneck(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER']) ?: abort(403);

        $stages = [
            'store_to_qc' => ['start' => 'store_received', 'end' => 'sent_to_qc'],
            'qc_inspection' => ['start' => 'sent_to_qc', 'end' => 'qc_inspected'],
            'rework_cycle' => ['start' => 'qc_rework', 'end' => 'rework_completed'],
            'paint_shop' => ['start' => 'qc_approved', 'end' => 'paint_completed'],
            'assembly_shop' => ['start' => 'paint_completed', 'end' => 'assembly_completed'],
        ];

        $bottlenecks = [];
        $hasSufficientData = false;

        $isSqlite = DB::getDriverName() === 'sqlite';
        $diffExpr = $isSqlite 
            ? 'AVG(julianday(e2.created_at) - julianday(e1.created_at))'
            : 'AVG(EXTRACT(EPOCH FROM (e2.created_at - e1.created_at)) / 86400)';

        foreach ($stages as $key => $events) {
            $avgDays = DB::table('workflow_events as e1')
                ->join('workflow_events as e2', function ($join) use ($events) {
                    $join->on('e1.bom_item_id', '=', 'e2.bom_item_id')
                         ->on('e1.side', '=', 'e2.side')
                         ->where('e1.event_type', '=', $events['start'])
                         ->where('e2.event_type', '=', $events['end'])
                         ->whereColumn('e2.created_at', '>', 'e1.created_at');
                })
                ->select(DB::raw("{$diffExpr} as avg_days"), DB::raw('COUNT(*) as sample_count'))
                ->first();

            $days = $avgDays && $avgDays->sample_count >= 1 ? round((float) $avgDays->avg_days, 1) : null;
            if ($days !== null) {
                $hasSufficientData = true;
            }

            $bottlenecks[$key] = [
                'stage' => ucwords(str_replace('_', ' ', $key)),
                'avg_days' => $days,
                'sample_count' => $avgDays ? (int) $avgDays->sample_count : 0,
            ];
        }

        return response()->json([
            'sufficient_data' => $hasSufficientData,
            'stages' => $bottlenecks,
        ]);
    }

    public function dailyMovement(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $query = WorkflowEvent::query()->with(['bomItem.project', 'user']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        if ($request->filled('side')) {
            $query->where('side', $request->input('side'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $events = $query->orderBy('created_at', 'desc')->get();

        $grouped = [];
        $totals = [
            'store_received' => 0,
            'qc_inspected' => 0,
            'rework' => 0,
            'paint' => 0,
            'assembly' => 0,
            'grand_total' => 0,
        ];

        foreach ($events as $evt) {
            $dateKey = $evt->created_at->format('Y-m-d');
            $dateFormatted = $evt->created_at->format('d-M-y');

            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'formatted_date' => $dateFormatted,
                    'store_received' => 0,
                    'qc_inspected' => 0,
                    'rework' => 0,
                    'paint' => 0,
                    'assembly' => 0,
                    'total_day' => 0,
                    'parts' => [],
                ];
            }

            $qty = $evt->quantity;
            $type = $evt->event_type;

            if ($type === 'store_received') {
                $grouped[$dateKey]['store_received'] += $qty;
                $totals['store_received'] += $qty;
            } elseif ($type === 'store_receipt_reverted') {
                $grouped[$dateKey]['store_received'] += $qty; // negative
                $totals['store_received'] += $qty;
            } elseif ($type === 'qc_inspected') {
                $grouped[$dateKey]['qc_inspected'] += $qty;
                $totals['qc_inspected'] += $qty;
                if ($evt->new_state === 'rework') {
                    $grouped[$dateKey]['rework'] += $qty;
                    $totals['rework'] += $qty;
                }
            } elseif (in_array($type, ['qc_rework', 'rework_started', 'rework_completed'])) {
                $grouped[$dateKey]['rework'] += $qty;
                $totals['rework'] += $qty;
            } elseif ($type === 'paint_completed') {
                $grouped[$dateKey]['paint'] += $qty;
                $totals['paint'] += $qty;
            } elseif ($type === 'assembly_completed') {
                $grouped[$dateKey]['assembly'] += $qty;
                $totals['assembly'] += $qty;
            }

            // Calculate total day parts from positive stage movements
            if ($qty > 0 && in_array($type, ['store_received', 'qc_inspected', 'rework_completed', 'paint_completed', 'assembly_completed'])) {
                $grouped[$dateKey]['total_day'] += $qty;
                $totals['grand_total'] += $qty;
            }

            // Format event description
            $eventLabel = strtoupper(str_replace('_', ' ', $type));
            if ($type === 'qc_inspected' && !empty($evt->new_state)) {
                $eventLabel = 'QC INSPECTED (' . strtoupper($evt->new_state) . ')';
            }

            $grouped[$dateKey]['parts'][] = [
                'id' => $evt->id,
                'standard_part_no' => $evt->bomItem?->standard_part_no ?? 'Part #' . $evt->bom_item_id,
                'project' => $evt->bomItem?->project?->name ?? 'N/A',
                'side' => $evt->side,
                'quantity' => $evt->quantity,
                'department_event' => $eventLabel,
                'user' => $evt->user?->name ?? 'System',
                'created_at_iso' => $evt->created_at->toIso8601String(),
            ];
        }

        return response()->json([
            'matrix' => array_values($grouped),
            'totals' => $totals,
        ]);
    }

    /**
     * Pipeline Transparency: Returns all receipt items with their current stage.
     * Shows WHERE every part currently is in the workflow.
     */
    public function pipelineStatus(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $items = ReceiptItem::query()
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->orderByDesc('updated_at')
            ->get();

        $stageMap = [
            'pending'          => ['label' => '⏳ Pending Arrival',       'color' => 'secondary', 'dept' => 'STORE'],
            'received'         => ['label' => '📦 Received in Store',      'color' => 'success',   'dept' => 'STORE'],
            'sent_to_qc'       => ['label' => '🚚 Dispatched to QC',      'color' => 'info',      'dept' => 'QC'],
            'qc_received'      => ['label' => '🔬 In QC (Physical Rcvd)',  'color' => 'info',      'dept' => 'QC'],
            'qc_approved'      => ['label' => '✅ QC Approved → Paint',    'color' => 'primary',   'dept' => 'PAINT'],
            'qc_rework'        => ['label' => '⚙️ Sent to Rework',         'color' => 'warning',   'dept' => 'REWORK'],
            'qc_rejected'      => ['label' => '❌ QC Rejected → Reorder',  'color' => 'danger',    'dept' => 'PURCHASE'],
            'qc_inspected'     => ['label' => '🔬 QC Inspected',           'color' => 'info',      'dept' => 'QC'],
            'paint_completed'  => ['label' => '🎨 Paint Done → Assembly',  'color' => 'success',   'dept' => 'ASSEMBLY'],
            'assembly_completed'=>['label' => '🏭 Assembly Complete',       'color' => 'success',   'dept' => 'DONE'],
        ];

        $formatted = $items->map(function ($item) use ($stageMap) {
            $stage = $stageMap[$item->status] ?? ['label' => strtoupper($item->status), 'color' => 'secondary', 'dept' => 'UNKNOWN'];
            return [
                'id'              => $item->id,
                'standard_part_no'=> $item->bomItem?->standard_part_no ?? 'Part #' . $item->bom_item_id,
                'project'         => $item->bomItem?->project?->name ?? 'N/A',
                'project_code'    => $item->bomItem?->project?->project_code ?? '',
                'side'            => $item->side,
                'quantity'        => $item->received_quantity,
                'status'          => $item->status,
                'stage_label'     => $stage['label'],
                'stage_color'     => $stage['color'],
                'department'      => $stage['dept'],
                'supplier'        => $item->bomItem?->supplier?->name ?? 'Standard',
                'received_at'     => $item->created_at?->toDateString(),
                'updated_at'      => $item->updated_at?->toDateString(),
            ];
        });

        // Group by department for summary
        $byDept = $formatted->groupBy('department')->map(fn($g) => $g->count());

        return response()->json([
            'parts'      => $formatted->values(),
            'by_dept'    => $byDept,
            'total'      => $formatted->count(),
        ]);
    }

    /**
     * Parts Priority Intelligence: Groups BOM parts by JIG and Unit number,
     * calculates completion %, and assigns Priority Tiers so managers know which parts to order urgently.
     */
    public function priorityMap(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id');

        $query = BomItem::query()->with(['project', 'requirements', 'supplier', 'receiptItems']);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $bomItems = $query->get();

        $unitsGrouped = [];

        foreach ($bomItems as $item) {
            $partNo = trim($item->standard_part_no);
            // Parse standard_part_no: e.g. 62800-ST7-01-11-R or 62800-ST07-00-00-R
            $parts = explode('-', $partNo);

            $jigName = 'General / Standard';
            $unitNo = 'Unit 00';

            if (count($parts) >= 3) {
                $jigName = strtoupper(trim($parts[1]));
                $unitNo = 'Unit ' . trim($parts[2]);
            }

            $key = ($item->project?->name ?? 'Project') . ' | ' . $jigName . ' | ' . $unitNo;

            if (!isset($unitsGrouped[$key])) {
                $unitsGrouped[$key] = [
                    'key' => $key,
                    'project_id' => $item->project_id,
                    'project_name' => $item->project?->name ?? 'N/A',
                    'project_code' => $item->project?->project_code ?? 'N/A',
                    'jig_name' => $jigName,
                    'unit_no' => $unitNo,
                    'total_required' => 0,
                    'total_received' => 0,
                    'pending_quantity' => 0,
                    'total_items_count' => 0,
                    'pending_parts' => [],
                ];
            }

            $unitsGrouped[$key]['total_items_count']++;

            // Sum required & received across sides
            $itemReceivedTotals = $item->receiptItems
                ->groupBy('side')
                ->map(fn($group) => $group->sum('received_quantity'));

            foreach ($item->requirements as $req) {
                $reqQty = (int) $req->required_quantity;
                $recQty = (int) ($itemReceivedTotals[$req->side] ?? 0);
                $pendQty = max(0, $reqQty - $recQty);

                $unitsGrouped[$key]['total_required'] += $reqQty;
                $unitsGrouped[$key]['total_received'] += min($recQty, $reqQty);

                if ($pendQty > 0) {
                    $unitsGrouped[$key]['pending_parts'][] = [
                        'bom_item_id' => $item->id,
                        'standard_part_no' => $item->standard_part_no,
                        'side' => $req->side,
                        'required' => $reqQty,
                        'received' => $recQty,
                        'pending' => $pendQty,
                        'supplier' => $item->supplier?->name ?? $item->supplier_name_raw ?? 'Standard Supplier',
                    ];
                }
            }
        }

        $unitsList = [];
        $summaryCounts = [
            'CRITICAL' => 0,
            'HIGH' => 0,
            'MEDIUM' => 0,
            'LOW' => 0,
            'COMPLETE' => 0,
        ];

        foreach ($unitsGrouped as $u) {
            $req = $u['total_required'];
            $rec = $u['total_received'];
            $pending = max(0, $req - $rec);
            $u['pending_quantity'] = $pending;

            $completionPct = $req > 0 ? round(($rec / $req) * 100, 1) : 100;
            $u['completion_pct'] = min(100, $completionPct);

            // Determine Priority Tier
            if ($u['completion_pct'] >= 100 || $pending === 0) {
                $tier = 'COMPLETE';
                $tierOrder = 5;
                $badgeColor = 'success';
                $label = 'Completed';
            } elseif ($u['completion_pct'] >= 70 && $pending > 0) {
                $tier = 'CRITICAL';
                $tierOrder = 1;
                $badgeColor = 'danger';
                $label = 'CRITICAL (Order Urgent)';
            } elseif ($u['completion_pct'] >= 40) {
                $tier = 'HIGH';
                $tierOrder = 2;
                $badgeColor = 'warning text-dark';
                $label = 'High Priority';
            } elseif ($u['completion_pct'] >= 20) {
                $tier = 'MEDIUM';
                $tierOrder = 3;
                $badgeColor = 'info text-white';
                $label = 'Medium Priority';
            } else {
                $tier = 'LOW';
                $tierOrder = 4;
                $badgeColor = 'secondary';
                $label = 'Low Priority';
            }

            $u['priority_tier'] = $tier;
            $u['tier_order'] = $tierOrder;
            $u['badge_color'] = $badgeColor;
            $u['priority_label'] = $label;

            // Score for sorting: lower tierOrder first, then higher completion_pct
            $u['priority_score'] = (10 - $tierOrder) * 1000 + $u['completion_pct'];

            $summaryCounts[$tier]++;
            $unitsList[] = $u;
        }

        // Sort: CRITICAL first, then HIGH, MEDIUM, LOW, COMPLETE
        usort($unitsList, function ($a, $b) {
            if ($a['tier_order'] !== $b['tier_order']) {
                return $a['tier_order'] <=> $b['tier_order'];
            }
            return $b['completion_pct'] <=> $a['completion_pct'];
        });

        // Top units for bar chart (only incomplete units sorted by completion_pct desc)
        $chartUnits = array_filter($unitsList, fn($u) => $u['priority_tier'] !== 'COMPLETE');
        usort($chartUnits, fn($a, $b) => $b['completion_pct'] <=> $a['completion_pct']);
        $chartUnits = array_slice($chartUnits, 0, 10);

        $projects = Project::orderBy('name')->get(['id', 'name', 'project_code']);

        return response()->json([
            'units' => $unitsList,
            'summary_counts' => $summaryCounts,
            'projects' => $projects,
            'chart' => [
                'labels' => array_column($chartUnits, 'unit_no'),
                'jigs' => array_column($chartUnits, 'jig_name'),
                'percentages' => array_column($chartUnits, 'completion_pct'),
                'tiers' => array_column($chartUnits, 'priority_tier'),
            ],
        ]);
    }
}
