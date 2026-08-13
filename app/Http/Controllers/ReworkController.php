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

        $records = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($records);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC', 'REWORK']) ?: abort(403);

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
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'REWORK']) ?: abort(403);

        $record = ReworkRecord::with('bomItem')->findOrFail($id);

        if ($record->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Rework record is not in pending status.',
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
    }

    public function complete(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'REWORK']) ?: abort(403);

        $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $id) {
            $record = ReworkRecord::with(['bomItem', 'qcInspection.receiptItem'])->findOrFail($id);

            $record->update([
                'status' => 'returned_to_qc',
                'completion_notes' => $request->input('completion_notes'),
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
                'remarks' => "Rework cycle #{$record->cycle_number} completed. Returned to QC queue for re-inspection.",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Rework completed successfully and returned to QC for re-inspection.',
                'record' => $record,
            ]);
        });
    }
}
