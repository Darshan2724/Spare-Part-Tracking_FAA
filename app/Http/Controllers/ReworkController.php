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

    public function complete(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

        $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $id) {
            $record = ReworkRecord::where('id', $id)
                ->lockForUpdate()
                ->with(['bomItem', 'qcInspection.receiptItem'])
                ->firstOrFail();

            if (!in_array($record->status, ['pending', 'in_progress'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rework record is not in an active state or has already been completed.',
                ], 422);
            }

            $notes = $request->input('completion_notes') ?? $request->input('remarks') ?? 'Rework completed.';

            $record->update([
                'status' => 'returned_to_qc',
                'completion_notes' => $notes,
                'completed_at' => now(),
            ]);

            // Re-enter QC Queue by returning receipt item status to 'sent_to_qc'
            if ($record->qcInspection?->receiptItem) {
                $record->qcInspection->receiptItem->update([
                    'status' => 'sent_to_qc',
                ]);
            }

            // Log workflow audit event
            WorkflowEvent::create([
                'bom_item_id' => $record->bom_item_id,
                'project_id' => $record->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'rework_completed',
                'side' => $record->side,
                'quantity' => $record->quantity,
                'previous_state' => 'in_progress',
                'new_state' => 'returned_to_qc',
                'remarks' => "Rework cycle #{$record->cycle_number} completed: {$notes}. Returned to QC queue for re-inspection.",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rework completed successfully and returned to QC for re-inspection.',
                'record' => $record,
            ]);
        });
    }

    public function bulkAction(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'REWORK']) ?: abort(403, 'Unauthorized. Rework operational permission required.');

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
                ->with(['bomItem', 'qcInspection.receiptItem'])
                ->get();

            if ($records->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No eligible rework records found.'], 422);
            }

            $processedCount = 0;
            foreach ($records as $record) {
                if ($action === 'start') {
                    if ($record->status === 'pending') {
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
                            'remarks' => "Bulk rework cycle #{$record->cycle_number} started.",
                        ]);
                        $processedCount++;
                    }
                } elseif ($action === 'complete') {
                    if (in_array($record->status, ['pending', 'in_progress'])) {
                        $record->update([
                            'status' => 'returned_to_qc',
                            'completion_notes' => $notes,
                            'completed_at' => now(),
                        ]);
                        if ($record->qcInspection?->receiptItem) {
                            $record->qcInspection->receiptItem->update([
                                'status' => 'sent_to_qc',
                            ]);
                        }
                        WorkflowEvent::create([
                            'bom_item_id' => $record->bom_item_id,
                            'project_id' => $record->bomItem->project_id,
                            'user_id' => $request->user()->id,
                            'event_type' => 'rework_completed',
                            'side' => $record->side,
                            'quantity' => $record->quantity,
                            'previous_state' => $record->status,
                            'new_state' => 'returned_to_qc',
                            'remarks' => "Bulk rework cycle #{$record->cycle_number} completed: {$notes}.",
                        ]);
                        $processedCount++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully processed {$action} for {$processedCount} rework records."
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
