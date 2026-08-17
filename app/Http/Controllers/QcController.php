<?php

namespace App\Http\Controllers;

use App\Models\BomItem;
use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\PurchaseQueueItem;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QcController extends Controller
{
    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC']) ?: abort(403);

        $query = ReceiptItem::query()
            ->with(['bomItem.project', 'bomItem.requirements', 'bomItem.supplier'])
            ->whereIn('status', ['received', 'sent_to_qc', 'qc_received']);

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

        $perPage = (int) $request->input('per_page', 100);
        $items = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($items);
    }

    public function confirmReceived(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_id' => ['required', 'exists:receipt_items,id'],
        ]);

        return DB::transaction(function () use ($request) {
            $item = ReceiptItem::where('id', $request->input('receipt_item_id'))
                ->lockForUpdate()
                ->with('bomItem.project')
                ->firstOrFail();

            if (!in_array($item->status, ['received', 'sent_to_qc'])) {
                return response()->json(['success' => false, 'message' => 'Item is not awaiting physical QC receipt or has already been received.'], 422);
            }

            $item->update([
                'status' => 'qc_received',
                'qc_received_at' => now(),
            ]);

            WorkflowEvent::create([
                'bom_item_id' => $item->bom_item_id,
                'project_id' => $item->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'qc_received',
                'side' => $item->side,
                'quantity' => $item->received_quantity,
                'previous_state' => 'sent_to_qc',
                'new_state' => 'qc_received',
                'remarks' => 'Physical arrival confirmed in QC department.',
            ]);

            return response()->json(['success' => true, 'message' => 'Physical arrival confirmed in QC department.']);
        });
    }

    public function bulkReceive(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_ids' => ['required', 'array', 'min:1'],
            'receipt_item_ids.*' => ['integer', 'exists:receipt_items,id'],
        ]);

        return DB::transaction(function () use ($request) {
            $ids = $request->input('receipt_item_ids');
            $items = ReceiptItem::whereIn('id', $ids)
                ->whereIn('status', ['received', 'sent_to_qc'])
                ->lockForUpdate()
                ->with('bomItem.project')
                ->get();

            if ($items->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No eligible items found awaiting physical QC receipt.'], 422);
            }

            $processedCount = 0;
            foreach ($items as $item) {
                $item->update([
                    'status' => 'qc_received',
                    'qc_received_at' => now(),
                ]);

                WorkflowEvent::create([
                    'bom_item_id' => $item->bom_item_id,
                    'project_id' => $item->bomItem->project_id,
                    'user_id' => $request->user()->id,
                    'event_type' => 'qc_received',
                    'side' => $item->side,
                    'quantity' => $item->received_quantity,
                    'previous_state' => 'sent_to_qc',
                    'new_state' => 'qc_received',
                    'remarks' => 'Bulk physical arrival confirmed in QC department.',
                ]);
                $processedCount++;
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully confirmed physical arrival for {$processedCount} items."
            ]);
        });
    }

    public function rejectArrival(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_id' => ['required', 'exists:receipt_items,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($request) {
            $item = ReceiptItem::where('id', $request->input('receipt_item_id'))
                ->lockForUpdate()
                ->with('bomItem.project')
                ->firstOrFail();

            if (!in_array($item->status, ['received', 'sent_to_qc'])) {
                return response()->json(['success' => false, 'message' => 'Item is not in physical arrival queue.'], 422);
            }

            // Return status to received with remark so store officer can re-verify physical delivery
            $item->update([
                'status' => 'received',
            ]);

            WorkflowEvent::create([
                'bom_item_id' => $item->bom_item_id,
                'project_id' => $item->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'qc_arrival_rejected',
                'side' => $item->side,
                'quantity' => $item->received_quantity,
                'previous_state' => $item->status,
                'new_state' => 'received',
                'remarks' => $request->input('reason', 'QC Officer rejected physical arrival: Stock not physically delivered to QC bay.'),
            ]);

            return response()->json(['success' => true, 'message' => 'Physical arrival rejected. Item returned to store verification.']);
        });
    }

    public function inspect(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_id' => ['required', 'exists:receipt_items,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'inspected_quantity' => ['required', 'integer', 'min:1'],
            'result' => ['required', 'in:approved,rejected,rework,partial'],
            'destination' => ['required_if:result,approved', 'nullable', 'in:PAINT,ASSEMBLY'],
            'approved_quantity' => ['nullable', 'integer', 'min:0'],
            'rejected_quantity' => ['nullable', 'integer', 'min:0'],
            'rework_quantity' => ['nullable', 'integer', 'min:0'],
            'rejection_reason' => ['nullable', 'string', 'max:255'],
            'rework_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'max:10240'], // Max 10MB
        ]);

        return DB::transaction(function () use ($request) {
            $receiptItem = ReceiptItem::where('id', $request->input('receipt_item_id'))
                ->lockForUpdate()
                ->with('bomItem.project')
                ->firstOrFail();

            if ($receiptItem->status !== 'qc_received') {
                return response()->json([
                    'success' => false,
                    'message' => 'Item is not awaiting QC inspection or has already been processed.'
                ], 422);
            }
            
            $inspectedQty = (int) $request->input('inspected_quantity');
            $result = $request->input('result');
            $destination = $result === 'approved' ? $request->input('destination') : null;

            $approvedQty = $result === 'approved' ? $inspectedQty : (int) $request->input('approved_quantity', 0);
            $rejectedQty = $result === 'rejected' ? $inspectedQty : (int) $request->input('rejected_quantity', 0);
            $reworkQty   = $result === 'rework'   ? $inspectedQty : (int) $request->input('rework_quantity', 0);

            $inspection = QcInspection::create([
                'bom_item_id' => $receiptItem->bom_item_id,
                'receipt_item_id' => $receiptItem->id,
                'rework_record_id' => $request->input('rework_record_id'),
                'side' => $request->input('side'),
                'inspected_quantity' => $inspectedQty,
                'approved_quantity' => $approvedQty,
                'rejected_quantity' => $rejectedQty,
                'rework_quantity' => $reworkQty,
                'result' => $result,
                'destination' => $destination,
                'rejection_reason' => $request->input('rejection_reason'),
                'rework_reason' => $request->input('rework_reason'),
                'remarks' => $request->input('remarks'),
                'is_reinspection' => (bool) $request->input('rework_record_id'),
                'inspected_by' => $request->user()->id,
                'inspection_date' => now(),
            ]);

            // Update receipt item status
            if ($result === 'approved') {
                $receiptItem->update(['status' => 'qc_approved']);
            } elseif ($result === 'rejected') {
                $receiptItem->update(['status' => 'returned_to_store']);
            } elseif ($result === 'rework') {
                $receiptItem->update(['status' => 'qc_rework']);
            } else {
                $receiptItem->update(['status' => 'qc_inspected']);
            }

            // Auto-create Return-to-Store Workflow Event and Purchase Queue Item if rejected
            if ($rejectedQty > 0) {
                WorkflowEvent::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'project_id' => $receiptItem->bomItem->project_id,
                    'user_id' => $request->user()->id,
                    'event_type' => 'returned_to_store',
                    'side' => $request->input('side'),
                    'quantity' => $rejectedQty,
                    'previous_state' => 'qc_received',
                    'new_state' => 'returned_to_store',
                    'remarks' => "QC Rejection: " . ($request->input('rejection_reason') ?? 'Dimensional / Surface Defect') . ". " . ($request->input('remarks') ?? ''),
                ]);

                PurchaseQueueItem::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'qc_inspection_id' => $inspection->id,
                    'project_id' => $receiptItem->bomItem->project_id,
                    'standard_part_no' => $receiptItem->bomItem->standard_part_no,
                    'side' => $request->input('side'),
                    'rejected_quantity' => $rejectedQty,
                    'rejection_reason' => $request->input('rejection_reason'),
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'status' => 'pending_purchase',
                    'remarks' => $request->input('remarks'),
                ]);
            }

            // Auto-create Rework Record if rework
            if ($reworkQty > 0) {
                $prevCycle = 0;
                if ($request->input('rework_record_id')) {
                    $prevRework = ReworkRecord::find($request->input('rework_record_id'));
                    $prevCycle = $prevRework?->cycle_number ?? 1;
                }

                ReworkRecord::create([
                    'qc_inspection_id' => $inspection->id,
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'side' => $request->input('side'),
                    'quantity' => $reworkQty,
                    'status' => 'pending',
                    'rework_description' => $request->input('rework_reason') ?? $request->input('remarks'),
                    'cycle_number' => $prevCycle + 1,
                ]);
            }

            // Handle optional photo attachment
            if ($request->hasFile('photo')) {
                $path = $request->file('photo')->store('qc_attachments', 'public');
                DB::table('attachments')->insert([
                    'attachable_type' => QcInspection::class,
                    'attachable_id' => $inspection->id,
                    'filename' => $request->file('photo')->hashName(),
                    'original_filename' => $request->file('photo')->getClientOriginalName(),
                    'mime_type' => $request->file('photo')->getClientMimeType(),
                    'size_bytes' => $request->file('photo')->getSize(),
                    'disk' => 'public',
                    'path' => $path,
                    'uploaded_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Record workflow audit event
            WorkflowEvent::create([
                'bom_item_id' => $receiptItem->bom_item_id,
                'project_id' => $receiptItem->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'qc_inspected',
                'side' => $request->input('side'),
                'quantity' => $inspectedQty,
                'previous_state' => 'sent_to_qc',
                'new_state' => $result,
                'remarks' => "QC Inspection Result: {$result} (Destination: {$destination}). Approved: {$approvedQty}, Rejected: {$rejectedQty}, Rework: {$reworkQty}.",
            ]);

            try {
                broadcast(new \App\Events\QcInspected($inspection))->toOthers();
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'inspection_id' => $inspection->id,
                'message' => 'QC inspection recorded successfully.',
            ]);
        });
    }

    public function bulkInspect(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_ids' => ['required', 'array', 'min:1'],
            'receipt_item_ids.*' => ['integer', 'exists:receipt_items,id'],
            'result' => ['required', 'in:approved,rejected,rework'],
            'destination' => ['required_if:result,approved', 'nullable', 'in:PAINT,ASSEMBLY'],
            'rejection_reason' => ['nullable', 'string', 'max:255'],
            'rework_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $ids = $request->input('receipt_item_ids');
            $result = $request->input('result');
            $destination = $result === 'approved' ? $request->input('destination') : null;

            $items = ReceiptItem::whereIn('id', $ids)
                ->where('status', 'qc_received')
                ->lockForUpdate()
                ->with('bomItem.project')
                ->get();

            if ($items->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No eligible items found awaiting QC inspection.'], 422);
            }

            $processedCount = 0;
            foreach ($items as $receiptItem) {
                $qty = $receiptItem->received_quantity;
                $approvedQty = $result === 'approved' ? $qty : 0;
                $rejectedQty = $result === 'rejected' ? $qty : 0;
                $reworkQty   = $result === 'rework'   ? $qty : 0;

                $inspection = QcInspection::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'receipt_item_id' => $receiptItem->id,
                    'side' => $receiptItem->side,
                    'inspected_quantity' => $qty,
                    'approved_quantity' => $approvedQty,
                    'rejected_quantity' => $rejectedQty,
                    'rework_quantity' => $reworkQty,
                    'result' => $result,
                    'destination' => $destination,
                    'rejection_reason' => $request->input('rejection_reason'),
                    'rework_reason' => $request->input('rework_reason'),
                    'remarks' => $request->input('remarks'),
                    'inspected_by' => $request->user()->id,
                    'inspection_date' => now(),
                ]);

                if ($result === 'approved') {
                    $receiptItem->update(['status' => 'qc_approved']);
                } elseif ($result === 'rejected') {
                    $receiptItem->update(['status' => 'returned_to_store']);
                    WorkflowEvent::create([
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'project_id' => $receiptItem->bomItem->project_id,
                        'user_id' => $request->user()->id,
                        'event_type' => 'returned_to_store',
                        'side' => $receiptItem->side,
                        'quantity' => $rejectedQty,
                        'previous_state' => 'qc_received',
                        'new_state' => 'returned_to_store',
                        'remarks' => "Bulk QC Rejection: " . ($request->input('rejection_reason') ?? 'Dimensional Defect'),
                    ]);
                    PurchaseQueueItem::create([
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'qc_inspection_id' => $inspection->id,
                        'project_id' => $receiptItem->bomItem->project_id,
                        'standard_part_no' => $receiptItem->bomItem->standard_part_no,
                        'side' => $receiptItem->side,
                        'rejected_quantity' => $rejectedQty,
                        'rejection_reason' => $request->input('rejection_reason'),
                        'rejected_by' => $request->user()->id,
                        'rejected_at' => now(),
                        'status' => 'pending_purchase',
                    ]);
                } elseif ($result === 'rework') {
                    $receiptItem->update(['status' => 'qc_rework']);
                    ReworkRecord::create([
                        'qc_inspection_id' => $inspection->id,
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'side' => $receiptItem->side,
                        'quantity' => $reworkQty,
                        'status' => 'pending',
                        'rework_description' => $request->input('rework_reason') ?? $request->input('remarks'),
                        'cycle_number' => 1,
                    ]);
                }

                WorkflowEvent::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'project_id' => $receiptItem->bomItem->project_id,
                    'user_id' => $request->user()->id,
                    'event_type' => 'qc_inspected',
                    'side' => $receiptItem->side,
                    'quantity' => $qty,
                    'previous_state' => 'qc_received',
                    'new_state' => $result,
                    'remarks' => "Bulk QC Inspection: {$result} (Destination: {$destination}). Qty: {$qty}.",
                ]);

                $processedCount++;
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully processed QC {$result} for {$processedCount} items."
            ]);
        });
    }

    public function history(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC']) ?: abort(403);

        $inspections = QcInspection::query()
            ->with(['bomItem.project', 'inspector'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($inspections);
    }

    /**
     * QC Hierarchy API: Returns project JIG -> Unit -> Parts tree for QC desk.
     */
    public function hierarchy(Request $request, \App\Services\HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'QC']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('qc', $projectId, $filters);
        return response()->json($data);
    }
}
