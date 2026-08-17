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

        // Fetch QC approved inspections that are routed to PAINT and ready for painting
        $query = QcInspection::query()
            ->with(['bomItem.project', 'receiptItem'])
            ->where('approved_quantity', '>', 0)
            ->where(function ($q) {
                $q->where('destination', 'PAINT')->orWhereNull('destination');
            })
            ->whereDoesntHave('paintRecord');

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

        return response()->json($queue);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'PAINT']) ?: abort(403, 'Unauthorized. Paint operational permission required.');

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'qc_inspection_id' => ['required', 'exists:qc_inspections,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'paint_type' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $inspection = QcInspection::where('id', $request->input('qc_inspection_id'))
                ->lockForUpdate()
                ->with(['receiptItem', 'bomItem', 'paintRecord'])
                ->firstOrFail();

            if ($inspection->paintRecord) {
                return response()->json(['success' => false, 'message' => 'Paint operation already completed for this inspection.'], 422);
            }

            if ($inspection->destination === 'ASSEMBLY') {
                return response()->json(['success' => false, 'message' => 'This part was routed directly to Assembly and cannot be painted.'], 422);
            }

            $record = PaintRecord::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'qc_inspection_id' => $inspection->id,
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'painted_by' => $request->user()->id,
                'status' => 'completed',
                'completed_at' => now(),
                'paint_type' => $request->input('paint_type'),
                'remarks' => $request->input('remarks'),
            ]);

            // Update receipt item status to 'paint_completed'
            if ($inspection->receiptItem) {
                $inspection->receiptItem->update(['status' => 'paint_completed']);
            }

            WorkflowEvent::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'project_id' => $inspection->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'paint_completed',
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'previous_state' => 'qc_approved',
                'new_state' => 'paint_completed',
                'remarks' => "Painting completed for {$request->input('quantity')} units. Type: " . ($request->input('paint_type') ?? 'Standard'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paint process completed successfully.',
                'paint_id' => $record->id,
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
