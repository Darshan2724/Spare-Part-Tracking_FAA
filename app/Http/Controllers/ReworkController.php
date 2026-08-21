<?php

namespace App\Http\Controllers;

use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReworkController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC', 'REWORK']) ?: abort(403);

        $status = $request->query('status');

        $query = ReworkRecord::query()
            ->with(['bomItem.project', 'qcInspection', 'assignee']);

        if ($status) {
            $query->where('status', $status);
        }

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
        $records = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($records);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

        $request->validate([
            'qc_inspection_id' => ['required', 'exists:qc_inspections,id'],
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'rework_description' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $qcInspection = QcInspection::findOrFail($request->input('qc_inspection_id'));
            
            $record = ReworkRecord::create([
                'qc_inspection_id' => $qcInspection->id,
                'bom_item_id' => $request->input('bom_item_id'),
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'assigned_to' => $request->user()->id,
                'status' => 'pending',
                'rework_description' => $request->input('rework_description'),
                'cycle_number' => 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rework record created successfully.',
                'rework_id' => $record->id,
            ]);
        });
    }

    public function start(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

        return DB::transaction(function () use ($request, $id) {
            $record = ReworkRecord::where('id', $id)
                ->lockForUpdate()
                ->with('bomItem')
                ->firstOrFail();

            if ($record->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Rework record is not in pending status or has already started.',
                ], 422);
            }

            $record->update([
                'status' => 'in_progress',
                'assigned_to' => $request->user()->id,
                'started_at' => now(),
            ]);

            WorkflowEvent::create([
                'bom_item_id' => $record->bom_item_id,
                'project_id' => $record->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'rework_started',
                'side' => $record->side,
                'quantity' => $record->quantity,
                'previous_state' => 'pending',
                'new_state' => 'in_progress',
                'remarks' => "Rework cycle #{$record->cycle_number} started.",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rework marked as in progress.',
                'record' => $record,
            ]);
        });
    }

    public function complete(Request $request, int $id = 0)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

        $request->validate([
            'rework_record_id' => ['nullable', 'integer'],
            'bom_item_id' => ['nullable', 'integer'],
            'side' => ['nullable', 'in:RH,LH,COMMON'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'completion_notes' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $id) {
            $record = null;
            if ($id > 0) {
                $record = ReworkRecord::where('id', $id)
                    ->lockForUpdate()
                    ->with(['bomItem.project', 'qcInspection.receiptItem'])
                    ->first();
            }

            if (!$record && $request->filled('rework_record_id')) {
                $record = ReworkRecord::where('id', $request->input('rework_record_id'))
                    ->lockForUpdate()
                    ->with(['bomItem.project', 'qcInspection.receiptItem'])
                    ->first();
            }

            if (!$record && $request->filled('bom_item_id')) {
                $q = ReworkRecord::where('bom_item_id', $request->input('bom_item_id'))
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->lockForUpdate()
                    ->with(['bomItem.project', 'qcInspection.receiptItem']);
                if ($request->filled('side')) {
                    $q->where('side', $request->input('side'));
                }
                $record = $q->first();
            }

            if (!$record) {
                return response()->json(['success' => false, 'message' => 'Rework record is not in an active state or has already been completed.'], 422);
            }

            if (!in_array($record->status, ['pending', 'in_progress'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rework record is not in an active state or has already been completed.',
                ], 422);
            }

            $availableQty = (int) $record->quantity;
            $completedQty = $request->filled('quantity') ? (int) $request->input('quantity') : $availableQty;

            if ($completedQty <= 0) {
                return response()->json(['success' => false, 'message' => 'Completed quantity must be greater than 0.'], 422);
            }

            if ($completedQty > $availableQty) {
                return response()->json(['success' => false, 'message' => "Completed quantity ({$completedQty}) exceeds available rework quantity ({$availableQty})."], 422);
            }

            // If partial rework completed, split remaining rework record
            if ($completedQty < $availableQty) {
                $remainingQty = $availableQty - $completedQty;
                $remainingRecord = $record->replicate();
                $remainingRecord->quantity = $remainingQty;
                $remainingRecord->status = 'pending';
                $remainingRecord->save();

                $record->quantity = $completedQty;
            }

            $notes = $request->input('completion_notes') ?? $request->input('remarks') ?? 'Rework completed.';

            $record->update([
                'status' => 'completed',
                'completion_notes' => $notes,
                'completed_at' => now(),
            ]);

            // Re-enter QC Inspection bay by transitioning or creating ReceiptItem with 'qc_received'
            $receiptItem = $record->qcInspection?->receiptItem;
            if ($receiptItem) {
                $recQty = (int) $receiptItem->received_quantity;
                if ($recQty > $completedQty) {
                    $receiptItem->update(['received_quantity' => $recQty - $completedQty]);
                    $returnedItem = $receiptItem->replicate();
                    $returnedItem->received_quantity = $completedQty;
                    $returnedItem->status = 'qc_received';
                    $returnedItem->qc_received_at = now();
                    $returnedItem->save();
                } else {
                    $receiptItem->update([
                        'status' => 'qc_received',
                        'qc_received_at' => now(),
                    ]);
                }
            } else {
                ReceiptItem::create([
                    'bom_item_id' => $record->bom_item_id,
                    'side' => $record->side,
                    'received_quantity' => $completedQty,
                    'status' => 'qc_received',
                    'qc_received_at' => now(),
                ]);
            }

            // Log workflow audit event
            WorkflowEvent::create([
                'bom_item_id' => $record->bom_item_id,
                'project_id' => $record->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'rework_completed',
                'side' => $record->side,
                'quantity' => $completedQty,
                'previous_state' => 'rework',
                'new_state' => 'qc_received',
                'remarks' => "Rework cycle #{$record->cycle_number} completed: {$notes}. Returned {$completedQty} units to QC Quality Inspection bay for re-inspection.",
            ]);

            try {
                broadcast(new \App\Events\PhysicalArrivalCompleted($receiptItem ?? $record))->toOthers();
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'processed_quantity' => $completedQty,
                'message' => "Rework completed successfully ({$completedQty} pcs) and returned to QC for re-inspection.",
                'record' => $record,
            ]);
        });
    }

    public function bulkAction(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

        $request->validate([
            'rework_record_ids' => ['required', 'array', 'min:1'],
            'rework_record_ids.*' => ['integer', 'exists:rework_records,id'],
            'action' => ['required', 'in:start,complete'],
            'completion_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $ids = $request->input('rework_record_ids');
            $action = $request->input('action');
            $notes = $request->input('completion_notes') ?? 'Bulk rework completed.';

            $records = ReworkRecord::whereIn('id', $ids)
                ->lockForUpdate()
                ->with(['bomItem.project', 'qcInspection.receiptItem'])
                ->get();

            if ($records->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No eligible rework records found.'], 422);
            }

            $processedCount = 0;
            $processedTotalQty = 0;
            foreach ($records as $record) {
                if ($action === 'start') {
                    if ($record->status === 'pending') {
                        $record->update([
                            'status' => 'in_progress',
                            'started_at' => now(),
                        ]);
                        $processedCount++;
                    }
                } elseif ($action === 'complete') {
                    if (in_array($record->status, ['pending', 'in_progress'])) {
                        $qty = (int) $record->quantity;
                        $record->update([
                            'status' => 'completed',
                            'completion_notes' => $notes,
                            'completed_at' => now(),
                        ]);

                        if ($record->qcInspection?->receiptItem) {
                            $record->qcInspection->receiptItem->update([
                                'status' => 'qc_received',
                                'qc_received_at' => now(),
                            ]);
                        } else {
                            ReceiptItem::create([
                                'bom_item_id' => $record->bom_item_id,
                                'side' => $record->side,
                                'received_quantity' => $qty,
                                'status' => 'qc_received',
                                'qc_received_at' => now(),
                            ]);
                        }

                        WorkflowEvent::create([
                            'bom_item_id' => $record->bom_item_id,
                            'project_id' => $record->bomItem->project_id,
                            'user_id' => $request->user()->id,
                            'event_type' => 'rework_completed',
                            'side' => $record->side,
                            'quantity' => $qty,
                            'previous_state' => 'rework',
                            'new_state' => 'qc_received',
                            'remarks' => "Bulk rework completed: {$notes}. Returned {$qty} units to QC for re-inspection.",
                        ]);

                        $processedCount++;
                        $processedTotalQty += $qty;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'processed_quantity' => $processedTotalQty,
                'message' => "Bulk rework {$action} processed successfully for {$processedCount} records ({$processedTotalQty} pcs).",
            ]);
        });
    }

    /**
     * Rework Hierarchy API: Returns project JIG -> Unit -> Parts tree for Rework center.
     */
    public function hierarchy(Request $request, \App\Services\HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'REWORK']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('rework', $projectId, $filters);
        return response()->json($data);
    }
}
