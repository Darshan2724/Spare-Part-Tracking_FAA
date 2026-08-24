<?php

namespace App\Http\Controllers;

use App\Models\PaintRecord;
use App\Models\QcInspection;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaintController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        $records = PaintRecord::query()
            ->with(['bomItem.project', 'qcInspection', 'painter'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($records);
    }

    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        // Fetch QC approved inspections that are routed to PAINT and have unpainted quantity remaining
        $query = QcInspection::query()
            ->with(['bomItem.project', 'receiptItem', 'paintRecords'])
            ->where('approved_quantity', '>', 0)
            ->where(function ($q) {
                $q->where('destination', 'PAINT')->orWhereNull('destination');
            })
            ->whereRaw('approved_quantity > (SELECT COALESCE(SUM(quantity), 0) FROM paint_records WHERE qc_inspection_id = qc_inspections.id)');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->whereHas('bomItem', function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('side')) {
            $query->where('side', $request->input('side'));
        }

        if ($request->filled('project_id')) {
            $query->whereHas('bomItem', function ($q) use ($request) {
                $q->where('project_id', $request->input('project_id'));
            });
        }

        $perPage = (int) $request->input('per_page', 50);
        $queue = $query->orderByDesc('created_at')->paginate($perPage);

        $queue->getCollection()->transform(function ($insp) {
            $painted = $insp->paintRecords->sum('quantity');
            $insp->available_paint_quantity = max(0, $insp->approved_quantity - $painted);
            return $insp;
        });

        return response()->json($queue);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'PAINT']) ?: abort(403, 'Unauthorized. Paint operational permission required.');

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'qc_inspection_id' => ['nullable', 'exists:qc_inspections,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'paint_type' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $side = $request->input('side');
            $qcInspectionId = $request->input('qc_inspection_id');
            $bomItemId = (int) $request->input('bom_item_id');
            $requestedQty = (int) $request->input('quantity');

            // Query all candidate eligible QC inspections for this BOM item & side
            $qcQuery = QcInspection::where('bom_item_id', $bomItemId)
                ->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                })
                ->where('approved_quantity', '>', 0)
                ->where(function ($q) {
                    $q->where('destination', 'PAINT')->orWhereNull('destination');
                })
                ->lockForUpdate()
                ->with(['receiptItem', 'bomItem', 'paintRecords']);

            if ($qcInspectionId) {
                $qcQuery->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END, id ASC", [$qcInspectionId]);
            } else {
                $qcQuery->orderBy('id', 'asc');
            }

            $inspections = $qcQuery->get();

            $paintAvailable = [];
            foreach ($inspections as $insp) {
                $alreadyPainted = (int) $insp->paintRecords->sum('quantity');
                $paintAvailable[$insp->id] = max(0, $insp->approved_quantity - $alreadyPainted);
            }

            $totalAvailableToPaint = (int) array_sum($paintAvailable);

            if ($totalAvailableToPaint <= 0) {
                return response()->json(['success' => false, 'message' => "No unpainted QC approved units found for {$side} side requirement."], 422);
            }

            if ($requestedQty > $totalAvailableToPaint) {
                return response()->json([
                    'success' => false,
                    'message' => "Requested paint quantity ({$requestedQty}) exceeds available unpainted quantity ({$totalAvailableToPaint})."
                ], 422);
            }

            // Sequentially fulfill paint quantity across eligible inspections
            $qtyRemaining = $requestedQty;
            $createdPaintRecords = [];
            $projectId = null;

            foreach ($inspections as $inspection) {
                $pAvail = $paintAvailable[$inspection->id] ?? 0;
                if ($qtyRemaining <= 0) break;
                if ($pAvail <= 0) continue;

                $consume = min($qtyRemaining, $pAvail);
                $qtyRemaining -= $consume;
                $paintAvailable[$inspection->id] -= $consume;
                $projectId = $inspection->bomItem->project_id;

                $record = PaintRecord::create([
                    'bom_item_id' => $bomItemId,
                    'qc_inspection_id' => $inspection->id,
                    'side' => $side,
                    'quantity' => $consume,
                    'painted_by' => $request->user()->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'paint_type' => $request->input('paint_type') ?? 'Standard',
                    'remarks' => $request->input('remarks'),
                ]);
                $createdPaintRecords[] = $record;

                $alreadyPainted = (int) $inspection->paintRecords->sum('quantity');
                if (($alreadyPainted + $consume) >= $inspection->approved_quantity) {
                    if ($inspection->receiptItem) {
                        $inspection->receiptItem->update(['status' => 'paint_completed']);
                    }
                }
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItemId,
                'project_id' => $projectId,
                'user_id' => $request->user()->id,
                'event_type' => 'paint_completed',
                'side' => $side,
                'quantity' => $requestedQty,
                'previous_state' => 'qc_approved',
                'new_state' => 'paint_completed',
                'remarks' => "Painting completed for {$requestedQty} units. Type: " . ($request->input('paint_type') ?? 'Standard'),
            ]);

            $lastRec = end($createdPaintRecords);
            if ($lastRec) {
                try {
                    broadcast(new \App\Events\PaintUpdated($lastRec, $projectId, $side, $requestedQty))->toOthers();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Realtime broadcast for PaintUpdated failed: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Paint process completed successfully.',
                'paint_id' => $lastRec ? $lastRec->id : null,
                'processed_quantity' => $requestedQty,
                'remaining_quantity' => max(0, $totalAvailableToPaint - $requestedQty),
            ]);
        });
    }

    public function bulkComplete(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'PAINT']) ?: abort(403, 'Unauthorized. Paint operational permission required.');

        $request->validate([
            'qc_inspection_ids' => ['required', 'array', 'min:1'],
            'qc_inspection_ids.*' => ['integer', 'exists:qc_inspections,id'],
            'paint_type' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $ids = $request->input('qc_inspection_ids');
            $inspections = QcInspection::whereIn('id', $ids)
                ->where('approved_quantity', '>', 0)
                ->where(function ($q) {
                    $q->where('destination', 'PAINT')->orWhereNull('destination');
                })
                ->whereDoesntHave('paintRecord')
                ->lockForUpdate()
                ->with(['receiptItem', 'bomItem'])
                ->get();

            if ($inspections->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No eligible inspections found for paint completion.'], 422);
            }

            $processedCount = 0;
            foreach ($inspections as $inspection) {
                PaintRecord::create([
                    'bom_item_id' => $inspection->bom_item_id,
                    'qc_inspection_id' => $inspection->id,
                    'side' => $inspection->side,
                    'quantity' => $inspection->approved_quantity,
                    'painted_by' => $request->user()->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'paint_type' => $request->input('paint_type'),
                    'remarks' => $request->input('remarks'),
                ]);

                if ($inspection->receiptItem) {
                    $inspection->receiptItem->update(['status' => 'paint_completed']);
                }

                WorkflowEvent::create([
                    'bom_item_id' => $inspection->bom_item_id,
                    'project_id' => $inspection->bomItem->project_id,
                    'user_id' => $request->user()->id,
                    'event_type' => 'paint_completed',
                    'side' => $inspection->side,
                    'quantity' => $inspection->approved_quantity,
                    'previous_state' => 'qc_approved',
                    'new_state' => 'paint_completed',
                    'remarks' => "Bulk Painting completed. Type: " . ($request->input('paint_type') ?? 'Standard'),
                ]);
                $processedCount++;
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully recorded paint completion for {$processedCount} items."
            ]);
        });
    }

    /**
     * Paint Hierarchy API: Returns project JIG -> Unit -> Parts tree for Paint shop.
     */
    public function hierarchy(Request $request, \App\Services\HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('paint', $projectId, $filters);
        return response()->json($data);
    }
}
