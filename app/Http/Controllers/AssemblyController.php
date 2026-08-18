<?php

namespace App\Http\Controllers;

use App\Models\AssemblyRecord;
use App\Models\PaintRecord;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssemblyController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $records = AssemblyRecord::query()
            ->with(['bomItem.project', 'paintRecord', 'assembler'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($records);
    }

    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $query = PaintRecord::query()
            ->with(['bomItem.project', 'qcInspection'])
            ->where('status', 'completed');

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
        $request->user()?->hasAnyRole(['ADMIN', 'ASSEMBLY']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'paint_record_id' => ['nullable', 'exists:paint_records,id'],
            'qc_inspection_id' => ['nullable', 'exists:qc_inspections,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if (!$request->filled('paint_record_id') && !$request->filled('qc_inspection_id')) {
            return response()->json(['success' => false, 'message' => 'Either paint_record_id or qc_inspection_id must be provided.'], 422);
        }

        return DB::transaction(function () use ($request) {
            $paintRecordId = $request->input('paint_record_id');
            $qcInspectionId = $request->input('qc_inspection_id');
            $bomItemId = $request->input('bom_item_id');
            $side = $request->input('side');
            $qty = $request->input('quantity');
            $projectId = null;
            $prevState = 'paint_completed';

            if ($paintRecordId) {
                $paint = PaintRecord::where('id', $paintRecordId)
                    ->where(function ($q) use ($side) {
                        $q->where('side', $side)->orWhere('side', 'COMMON');
                    })
                    ->lockForUpdate()
                    ->with(['bomItem', 'qcInspection.receiptItem'])
                    ->first();

                if (!$paint) {
                    return response()->json(['success' => false, 'message' => "No eligible Paint record found for {$side} side."], 422);
                }

                if ($paint->status === 'assembled') {
                    return response()->json(['success' => false, 'message' => 'Assembly already completed for this paint record.'], 422);
                }

                $paint->update(['status' => 'assembled']);
                if ($paint->qcInspection?->receiptItem) {
                    $paint->qcInspection->receiptItem->update(['status' => 'assembly_completed']);
                }
                $projectId = $paint->bomItem->project_id;
                $prevState = 'paint_completed';
            } elseif ($qcInspectionId) {
                $inspection = \App\Models\QcInspection::where('id', $qcInspectionId)
                    ->where(function ($q) use ($side) {
                        $q->where('side', $side)->orWhere('side', 'COMMON');
                    })
                    ->lockForUpdate()
                    ->with(['bomItem', 'receiptItem', 'assemblyRecord'])
                    ->first();

                if (!$inspection) {
                    return response()->json(['success' => false, 'message' => "No eligible QC inspection found for {$side} side."], 422);
                }

                if ($inspection->assemblyRecord) {
                    return response()->json(['success' => false, 'message' => 'Assembly already completed for this QC inspection.'], 422);
                }

                if ($inspection->receiptItem) {
                    $inspection->receiptItem->update(['status' => 'assembly_completed']);
                }
                $projectId = $inspection->bomItem->project_id;
                $prevState = 'qc_approved';
            }

            $record = AssemblyRecord::create([
                'bom_item_id' => $bomItemId,
                'paint_record_id' => $paintRecordId,
                'qc_inspection_id' => $qcInspectionId,
                'side' => $side,
                'quantity' => $qty,
                'assembled_by' => $request->user()->id,
                'status' => 'completed',
                'completed_at' => now(),
                'remarks' => $request->input('remarks'),
            ]);

            WorkflowEvent::create([
                'bom_item_id' => $bomItemId,
                'project_id' => $projectId,
                'user_id' => $request->user()->id,
                'event_type' => 'assembly_completed',
                'side' => $side,
                'quantity' => $qty,
                'previous_state' => $prevState,
                'new_state' => 'assembly_completed',
                'remarks' => "Assembly completed for {$qty} units.",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Assembly process completed successfully.',
                'assembly_id' => $record->id,
            ]);
        });
    }

    public function bulkComplete(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'ASSEMBLY']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.bom_item_id' => ['required', 'exists:bom_items,id'],
            'items.*.paint_record_id' => ['nullable', 'exists:paint_records,id'],
            'items.*.qc_inspection_id' => ['nullable', 'exists:qc_inspections,id'],
            'items.*.side' => ['required', 'in:RH,LH,COMMON'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $items = $request->input('items');
            $processedCount = 0;

            foreach ($items as $entry) {
                $paintRecordId = $entry['paint_record_id'] ?? null;
                $qcInspectionId = $entry['qc_inspection_id'] ?? null;
                $bomItemId = $entry['bom_item_id'];
                $side = $entry['side'];
                $qty = $entry['quantity'];
                $projectId = null;
                $prevState = 'paint_completed';

                if ($paintRecordId) {
                    $paint = PaintRecord::where('id', $paintRecordId)
                        ->where('status', '!=', 'assembled')
                        ->lockForUpdate()
                        ->with(['bomItem', 'qcInspection.receiptItem'])
                        ->first();

                    if ($paint) {
                        $paint->update(['status' => 'assembled']);
                        if ($paint->qcInspection?->receiptItem) {
                            $paint->qcInspection->receiptItem->update(['status' => 'assembly_completed']);
                        }
                        $projectId = $paint->bomItem->project_id;
                    }
                } elseif ($qcInspectionId) {
                    $inspection = \App\Models\QcInspection::where('id', $qcInspectionId)
                        ->whereDoesntHave('assemblyRecord')
                        ->lockForUpdate()
                        ->with(['bomItem', 'receiptItem'])
                        ->first();

                    if ($inspection) {
                        if ($inspection->receiptItem) {
                            $inspection->receiptItem->update(['status' => 'assembly_completed']);
                        }
                        $projectId = $inspection->bomItem->project_id;
                        $prevState = 'qc_approved';
                    }
                }

                AssemblyRecord::create([
                    'bom_item_id' => $bomItemId,
                    'paint_record_id' => $paintRecordId,
                    'qc_inspection_id' => $qcInspectionId,
                    'side' => $side,
                    'quantity' => $qty,
                    'assembled_by' => $request->user()->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'remarks' => $request->input('remarks'),
                ]);

                WorkflowEvent::create([
                    'bom_item_id' => $bomItemId,
                    'project_id' => $projectId,
                    'user_id' => $request->user()->id,
                    'event_type' => 'assembly_completed',
                    'side' => $side,
                    'quantity' => $qty,
                    'previous_state' => $prevState,
                    'new_state' => 'assembly_completed',
                    'remarks' => "Bulk Assembly completed for {$qty} units.",
                ]);

                $processedCount++;
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully recorded assembly for {$processedCount} items."
            ]);
        });
    }

    /**
     * Assembly Hierarchy API: Returns project JIG -> Unit -> Parts tree for Assembly shop.
     */
    public function hierarchy(Request $request, \App\Services\HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('assembly', $projectId, $filters);
        return response()->json($data);
    }
}
