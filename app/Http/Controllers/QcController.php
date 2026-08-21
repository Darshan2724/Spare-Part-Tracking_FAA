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
            ->with(['bomItem.project', 'bomItem.requirements', 'bomItem.supplier']);

        // Explicit Stage Filtering: Physical Arrival vs Quality Inspection
        $stage = $request->input('stage');
        if ($stage === 'arrival') {
            $query->whereIn('status', ['received', 'sent_to_qc']);
        } elseif ($stage === 'inspection') {
            $query->where('status', 'qc_received');
        } else {
            $query->whereIn('status', ['received', 'sent_to_qc', 'qc_received']);
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

        $perPage = (int) $request->input('per_page', 100);
        $items = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($items);
    }

    public function confirmReceived(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_id' => ['nullable', 'integer'],
            'bom_item_id' => ['nullable', 'integer'],
            'side' => ['nullable', 'in:RH,LH,COMMON'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($request) {
            $item = null;
            if ($request->filled('receipt_item_id')) {
                $item = ReceiptItem::where('id', $request->input('receipt_item_id'))
                    ->lockForUpdate()
                    ->with('bomItem.project')
                    ->first();
            }

            if (!$item && $request->filled('bom_item_id')) {
                $q = ReceiptItem::where('bom_item_id', $request->input('bom_item_id'))
                    ->whereIn('status', ['received', 'sent_to_qc'])
                    ->lockForUpdate()
                    ->with('bomItem.project');
                if ($request->filled('side')) {
                    $q->where('side', $request->input('side'));
                }
                $item = $q->first();
            }

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Item is not awaiting physical QC receipt or has already been received.'], 422);
            }

            if (!in_array($item->status, ['received', 'sent_to_qc'])) {
                return response()->json(['success' => false, 'message' => 'Item is not awaiting physical QC receipt or has already been received.'], 422);
            }

            $availableQty = (int) $item->received_quantity;
            $receiveQty = $request->filled('quantity') ? (int) $request->input('quantity') : $availableQty;

            if ($receiveQty <= 0) {
                return response()->json(['success' => false, 'message' => 'Quantity to receive must be at least 1.'], 422);
            }

            if ($receiveQty > $availableQty) {
                return response()->json([
                    'success' => false,
                    'message' => "Requested quantity ({$receiveQty}) exceeds pending physical arrival quantity ({$availableQty})."
                ], 422);
            }

            $activeItem = $item;

            // Handle Atomic ReceiptItem splitting if partial physical arrival
            if ($receiveQty < $availableQty) {
                $remainingQty = $availableQty - $receiveQty;
                
                // Retain remaining unarrived quantity in pending physical arrival
                $item->update(['received_quantity' => $remainingQty]);

                // Create new ReceiptItem for the arrived portion
                $activeItem = $item->replicate();
                $activeItem->received_quantity = $receiveQty;
                $activeItem->status = 'qc_received';
                $activeItem->qc_received_at = now();
                $activeItem->save();
            } else {
                $item->update([
                    'status' => 'qc_received',
                    'qc_received_at' => now(),
                ]);
                $activeItem = $item;
            }

            WorkflowEvent::create([
                'bom_item_id' => $activeItem->bom_item_id,
                'project_id' => $activeItem->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'qc_received',
                'side' => $activeItem->side,
                'quantity' => $receiveQty,
                'previous_state' => 'sent_to_qc',
                'new_state' => 'qc_received',
                'remarks' => "Physical arrival confirmed in QC department ({$receiveQty} pcs).",
            ]);

            try {
                broadcast(new \App\Events\PhysicalArrivalCompleted($activeItem))->toOthers();
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'processed_quantity' => $receiveQty,
                'message' => "Successfully confirmed physical arrival for {$receiveQty} pcs in QC department."
            ]);
        });
    }

    public function bulkReceive(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_ids' => ['nullable', 'array'],
            'receipt_item_ids.*' => ['integer'],
            'bom_item_ids' => ['nullable', 'array'],
            'bom_item_ids.*' => ['integer'],
            'items' => ['nullable', 'array'],
            'items.*.receipt_item_id' => ['nullable', 'integer'],
            'items.*.bom_item_id' => ['nullable', 'integer'],
            'items.*.side' => ['nullable', 'in:RH,LH,COMMON'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'side' => ['nullable', 'in:RH,LH,COMMON'],
        ]);

        return DB::transaction(function () use ($request) {
            $itemsPayload = $request->input('items', []);
            $ids = $request->input('receipt_item_ids', []);
            $bomIds = $request->input('bom_item_ids', []);
            $side = $request->input('side');

            if (!empty($itemsPayload)) {
                $processedCount = 0;
                $processedTotalQty = 0;

                foreach ($itemsPayload as $itemData) {
                    $q = ReceiptItem::query()->lockForUpdate()->with('bomItem.project');
                    if (!empty($itemData['receipt_item_id'])) {
                        $q->where('id', $itemData['receipt_item_id']);
                    } elseif (!empty($itemData['bom_item_id'])) {
                        $q->where('bom_item_id', $itemData['bom_item_id'])->whereIn('status', ['received', 'sent_to_qc', 'store_resident']);
                        if (!empty($itemData['side'])) {
                            $q->where('side', $itemData['side']);
                        }
                    } else {
                        continue;
                    }

                    $recItem = $q->first();
                    if (!$recItem || !in_array($recItem->status, ['received', 'sent_to_qc', 'store_resident'])) {
                        continue;
                    }

                    $avail = (int) $recItem->received_quantity;
                    $reqQty = !empty($itemData['quantity']) ? (int) $itemData['quantity'] : $avail;
                    $reqQty = min($reqQty, $avail);
                    if ($reqQty <= 0) continue;

                    if ($reqQty < $avail) {
                        $recItem->update(['received_quantity' => $avail - $reqQty]);
                        $arrived = $recItem->replicate();
                        $arrived->received_quantity = $reqQty;
                        $arrived->status = 'qc_received';
                        $arrived->qc_received_at = now();
                        $arrived->save();
                    } else {
                        $recItem->update(['status' => 'qc_received', 'qc_received_at' => now()]);
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $recItem->bom_item_id,
                        'project_id' => $recItem->bomItem->project_id,
                        'user_id' => $request->user()->id,
                        'event_type' => 'qc_received',
                        'side' => $recItem->side,
                        'quantity' => $reqQty,
                        'previous_state' => 'sent_to_qc',
                        'new_state' => 'qc_received',
                        'remarks' => "Bulk physical arrival confirmed in QC department ({$reqQty} pcs).",
                    ]);

                    $processedCount++;
                    $processedTotalQty += $reqQty;
                }

                return response()->json([
                    'success' => true,
                    'processed_count' => $processedCount,
                    'processed_quantity' => $processedTotalQty,
                    'message' => "Successfully confirmed physical arrival for {$processedCount} items ({$processedTotalQty} pcs)."
                ]);
            }

            $query = ReceiptItem::query()->lockForUpdate()->with('bomItem.project');

            if (!empty($ids) && !empty($bomIds)) {
                $query->where(function ($q) use ($ids, $bomIds) {
                    $q->whereIn('id', $ids)->orWhereIn('bom_item_id', $bomIds);
                });
            } elseif (!empty($ids)) {
                $query->whereIn('id', $ids);
            } elseif (!empty($bomIds)) {
                $query->whereIn('bom_item_id', $bomIds);
            } else {
                return response()->json(['success' => false, 'message' => 'No items provided for physical arrival.'], 422);
            }

            if ($side) {
                $query->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                });
            }

            $allItems = $query->get();
            $eligibleItems = $allItems->filter(fn($item) => in_array($item->status, ['received', 'sent_to_qc', 'store_resident']));

            if ($eligibleItems->isEmpty()) {
                $alreadyReceived = $allItems->where('status', 'qc_received')->count();
                if ($alreadyReceived > 0) {
                    return response()->json([
                        'success' => true,
                        'processed_count' => 0,
                        'already_processed' => $alreadyReceived,
                        'message' => "Selected items ({$alreadyReceived}) are already received in QC."
                    ]);
                }
                return response()->json(['success' => false, 'message' => 'No eligible items found awaiting physical QC receipt.'], 422);
            }

            $processedCount = 0;
            $processedTotalQty = 0;
            foreach ($eligibleItems as $item) {
                $qty = (int) $item->received_quantity;
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
                    'quantity' => $qty,
                    'previous_state' => $item->status,
                    'new_state' => 'qc_received',
                    'remarks' => "Bulk physical arrival confirmed in QC department ({$qty} pcs).",
                ]);

                try {
                    broadcast(new \App\Events\PhysicalArrivalCompleted($item))->toOthers();
                } catch (\Throwable $e) {}

                $processedCount++;
                $processedTotalQty += $qty;
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'processed_quantity' => $processedTotalQty,
                'message' => "Successfully confirmed physical arrival for {$processedCount} items ({$processedTotalQty} pcs)."
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
            'receipt_item_id' => ['required', 'integer'],
            'bom_item_id' => ['nullable', 'exists:bom_items,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'inspected_quantity' => ['nullable', 'integer', 'min:1'],
            'result' => ['required', 'in:approved,rejected,rework,partial'],
            'destination' => ['nullable', 'in:PAINT,ASSEMBLY'],
            'approved_quantity' => ['nullable', 'integer', 'min:0'],
            'paint_quantity' => ['nullable', 'integer', 'min:0'],
            'assembly_quantity' => ['nullable', 'integer', 'min:0'],
            'rejected_quantity' => ['nullable', 'integer', 'min:0'],
            'rework_quantity' => ['nullable', 'integer', 'min:0'],
            'rejection_reason' => ['nullable', 'string', 'max:255'],
            'rework_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'max:10240'], // Max 10MB
        ]);

        return DB::transaction(function () use ($request) {
            $receiptItemId = (int) $request->input('receipt_item_id');
            $side = $request->input('side');
            $bomItemId = $request->input('bom_item_id');

            // Explicit side mismatch guard: If receipt_item_id exists but belongs to a different side
            if ($receiptItemId > 0) {
                $checkItem = ReceiptItem::find($receiptItemId);
                if ($checkItem && $checkItem->side !== $side && $checkItem->side !== 'COMMON' && $side !== 'COMMON') {
                    return response()->json([
                        'success' => false,
                        'message' => "Side mismatch: Receipt item #{$receiptItemId} belongs to {$checkItem->side} side, but {$side} inspection was requested."
                    ], 422);
                }
            }

            // 1. Strict primary lookup by exact receipt_item_id to verify identity if provided
            if ($receiptItemId > 0 && !$bomItemId) {
                $temp = ReceiptItem::find($receiptItemId);
                if ($temp) {
                    $bomItemId = $temp->bom_item_id;
                    if (!$side) $side = $temp->side;
                }
            }

            // 2. Query all eligible receipt items in qc_received for this BOM item and side
            $eligibleQuery = ReceiptItem::query()->where('status', 'qc_received');
            if ($bomItemId) {
                $eligibleQuery->where('bom_item_id', $bomItemId);
            }
            if ($side) {
                $eligibleQuery->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                });
            } elseif ($receiptItemId > 0) {
                $eligibleQuery->where('id', $receiptItemId);
            }

            $eligibleReceiptItems = $eligibleQuery->orderBy('id')->lockForUpdate()->with('bomItem.project')->get();

            if ($eligibleReceiptItems->isEmpty() && $receiptItemId > 0) {
                $eligibleReceiptItems = ReceiptItem::where('id', $receiptItemId)
                    ->where('status', 'qc_received')
                    ->lockForUpdate()
                    ->with('bomItem.project')
                    ->get();
            }

            if ($eligibleReceiptItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "No eligible QC item found for {$side} side in inspection bay (or already processed)."
                ], 422);
            }

            $receiptItem = $eligibleReceiptItems->first();
            $availableQty = (int) $eligibleReceiptItems->sum('received_quantity');
            $result = $request->input('result');

            // Determine target quantities based on action
            if ($result === 'approved') {
                $approvedQty = $request->filled('approved_quantity') 
                    ? (int) $request->input('approved_quantity') 
                    : (int) $request->input('inspected_quantity', $availableQty);
                $rejectedQty = 0;
                $reworkQty = 0;
                $inspectedQty = $approvedQty;

                // Check Paint / Assembly split inputs
                $hasExplicitSplit = $request->has('paint_quantity') || $request->has('assembly_quantity');
                if ($hasExplicitSplit) {
                    $paintQty = (int) $request->input('paint_quantity', 0);
                    $assemblyQty = (int) $request->input('assembly_quantity', 0);
                    if (($paintQty + $assemblyQty) !== $approvedQty) {
                        return response()->json([
                            'success' => false,
                            'message' => "Paint quantity ({$paintQty}) + Assembly quantity ({$assemblyQty}) must equal approved quantity ({$approvedQty})."
                        ], 422);
                    }
                } else {
                    $dest = $request->input('destination', 'PAINT');
                    $paintQty = ($dest === 'ASSEMBLY') ? 0 : $approvedQty;
                    $assemblyQty = ($dest === 'ASSEMBLY') ? $approvedQty : 0;
                }
            } elseif ($result === 'rejected') {
                $rejectedQty = $request->filled('rejected_quantity') 
                    ? (int) $request->input('rejected_quantity') 
                    : (int) $request->input('inspected_quantity', $availableQty);
                $approvedQty = 0;
                $reworkQty = 0;
                $paintQty = 0;
                $assemblyQty = 0;
                $inspectedQty = $rejectedQty;
            } elseif ($result === 'rework') {
                $reworkQty = $request->filled('rework_quantity') 
                    ? (int) $request->input('rework_quantity') 
                    : (int) $request->input('inspected_quantity', $availableQty);
                $approvedQty = 0;
                $rejectedQty = 0;
                $paintQty = 0;
                $assemblyQty = 0;
                $inspectedQty = $reworkQty;
            } else {
                // Partial composite
                $approvedQty = (int) $request->input('approved_quantity', 0);
                $rejectedQty = (int) $request->input('rejected_quantity', 0);
                $reworkQty   = (int) $request->input('rework_quantity', 0);
                $inspectedQty = $approvedQty + $rejectedQty + $reworkQty;
                $paintQty = (int) $request->input('paint_quantity', 0);
                $assemblyQty = (int) $request->input('assembly_quantity', 0);
                if ($approvedQty > 0 && ($paintQty + $assemblyQty) !== $approvedQty) {
                    $dest = $request->input('destination', 'PAINT');
                    $paintQty = ($dest === 'ASSEMBLY') ? 0 : $approvedQty;
                    $assemblyQty = ($dest === 'ASSEMBLY') ? $approvedQty : 0;
                }
            }

            // Quantity Bounds Validation
            if ($inspectedQty <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Processed quantity must be greater than 0.'
                ], 422);
            }

            if ($inspectedQty > $availableQty) {
                return response()->json([
                    'success' => false,
                    'message' => "Requested quantity ({$inspectedQty}) exceeds available QC inspection quantity ({$availableQty})."
                ], 422);
            }

            // Sequentially fulfill inspected quantity across eligible receipt items
            $qtyToConsume = $inspectedQty;
            $newStatus = ($result === 'approved') ? 'qc_approved' : (($result === 'rejected') ? 'qc_rejected' : (($result === 'rework') ? 'qc_rework' : 'qc_inspected'));

            foreach ($eligibleReceiptItems as $rItem) {
                if ($qtyToConsume <= 0) {
                    break;
                }

                $rQty = (int) $rItem->received_quantity;
                if ($qtyToConsume >= $rQty) {
                    // Full row consumed
                    $qtyToConsume -= $rQty;
                    $rItem->update(['status' => $newStatus]);
                } else {
                    // Partial row consumed: split it!
                    $remQty = $rQty - $qtyToConsume;
                    
                    // Create remaining item staying in qc_received
                    $remItem = $rItem->replicate();
                    $remItem->received_quantity = $remQty;
                    $remItem->status = 'qc_received';
                    $remItem->save();

                    // Current item is the consumed portion
                    $rItem->received_quantity = $qtyToConsume;
                    $rItem->status = $newStatus;
                    $rItem->save();

                    $qtyToConsume = 0;
                }
            }

            $createdInspections = [];

            // 1. Create QC Inspection records for Approved (Split Paint vs Assembly)
            if ($approvedQty > 0) {
                if ($paintQty > 0) {
                    $createdInspections[] = QcInspection::create([
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'receipt_item_id' => $receiptItem->id,
                        'rework_record_id' => $request->input('rework_record_id'),
                        'side' => $receiptItem->side,
                        'inspected_quantity' => $paintQty,
                        'approved_quantity' => $paintQty,
                        'rejected_quantity' => 0,
                        'rework_quantity' => 0,
                        'result' => 'approved',
                        'destination' => 'PAINT',
                        'remarks' => $request->input('remarks'),
                        'is_reinspection' => (bool) $request->input('rework_record_id'),
                        'inspected_by' => $request->user()->id,
                        'inspection_date' => now(),
                    ]);
                }
                if ($assemblyQty > 0) {
                    $createdInspections[] = QcInspection::create([
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'receipt_item_id' => $receiptItem->id,
                        'rework_record_id' => $request->input('rework_record_id'),
                        'side' => $receiptItem->side,
                        'inspected_quantity' => $assemblyQty,
                        'approved_quantity' => $assemblyQty,
                        'rejected_quantity' => 0,
                        'rework_quantity' => 0,
                        'result' => 'approved',
                        'destination' => 'ASSEMBLY',
                        'remarks' => $request->input('remarks'),
                        'is_reinspection' => (bool) $request->input('rework_record_id'),
                        'inspected_by' => $request->user()->id,
                        'inspection_date' => now(),
                    ]);
                }
            }

            // 2. Create QC Inspection for Rejections
            if ($rejectedQty > 0) {
                $rejInsp = QcInspection::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'receipt_item_id' => $receiptItem->id,
                    'rework_record_id' => $request->input('rework_record_id'),
                    'side' => $receiptItem->side,
                    'inspected_quantity' => $rejectedQty,
                    'approved_quantity' => 0,
                    'rejected_quantity' => $rejectedQty,
                    'rework_quantity' => 0,
                    'result' => 'rejected',
                    'rejection_reason' => $request->input('rejection_reason') ?? 'Quality Non-conformance',
                    'remarks' => $request->input('remarks'),
                    'is_reinspection' => (bool) $request->input('rework_record_id'),
                    'inspected_by' => $request->user()->id,
                    'inspection_date' => now(),
                ]);
                $createdInspections[] = $rejInsp;

                WorkflowEvent::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'project_id' => $receiptItem->bomItem->project_id,
                    'user_id' => $request->user()->id,
                    'event_type' => 'returned_to_purchase',
                    'side' => $receiptItem->side,
                    'quantity' => $rejectedQty,
                    'previous_state' => 'qc_received',
                    'new_state' => 'qc_rejected',
                    'remarks' => "QC Rejection: " . ($request->input('rejection_reason') ?? 'Dimensional / Surface Defect') . ". Sent to Purchase Queue for procurement. " . ($request->input('remarks') ?? ''),
                ]);

                PurchaseQueueItem::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'qc_inspection_id' => $rejInsp->id,
                    'project_id' => $receiptItem->bomItem->project_id,
                    'standard_part_no' => $receiptItem->bomItem->standard_part_no,
                    'side' => $receiptItem->side,
                    'rejected_quantity' => $rejectedQty,
                    'rejection_reason' => $request->input('rejection_reason') ?? 'Dimensional Defect',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'status' => 'pending_purchase',
                    'remarks' => $request->input('remarks'),
                ]);
            }

            // 3. Create QC Inspection & Rework Record for Rework
            if ($reworkQty > 0) {
                $prevCycle = 0;
                if ($request->input('rework_record_id')) {
                    $prevRework = ReworkRecord::find($request->input('rework_record_id'));
                    $prevCycle = $prevRework?->cycle_number ?? 1;
                }

                $rewInsp = QcInspection::create([
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'receipt_item_id' => $receiptItem->id,
                    'rework_record_id' => $request->input('rework_record_id'),
                    'side' => $receiptItem->side,
                    'inspected_quantity' => $reworkQty,
                    'approved_quantity' => 0,
                    'rejected_quantity' => 0,
                    'rework_quantity' => $reworkQty,
                    'result' => 'rework',
                    'rework_reason' => $request->input('rework_reason') ?? 'Dimensional Correction',
                    'remarks' => $request->input('remarks'),
                    'is_reinspection' => (bool) $request->input('rework_record_id'),
                    'inspected_by' => $request->user()->id,
                    'inspection_date' => now(),
                ]);
                $createdInspections[] = $rewInsp;

                ReworkRecord::create([
                    'qc_inspection_id' => $rewInsp->id,
                    'bom_item_id' => $receiptItem->bom_item_id,
                    'side' => $receiptItem->side,
                    'quantity' => $reworkQty,
                    'status' => 'pending',
                    'rework_description' => $request->input('rework_reason') ?? $request->input('remarks'),
                    'cycle_number' => $prevCycle + 1,
                ]);
            }

            // Record workflow audit event
            WorkflowEvent::create([
                'bom_item_id' => $receiptItem->bom_item_id,
                'project_id' => $receiptItem->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'qc_inspected',
                'side' => $receiptItem->side,
                'quantity' => $inspectedQty,
                'previous_state' => 'qc_received',
                'new_state' => $result,
                'remarks' => "QC Inspection Result: {$result}. Approved: {$approvedQty} (Paint: {$paintQty}, Assembly: {$assemblyQty}), Rejected: {$rejectedQty}, Rework: {$reworkQty}.",
            ]);

            try {
                if (!empty($createdInspections)) {
                    broadcast(new \App\Events\QcInspected($createdInspections[0]))->toOthers();
                }
            } catch (\Throwable $e) {}

            return response()->json([
                'success' => true,
                'processed_quantity' => $inspectedQty,
                'inspection_ids' => collect($createdInspections)->pluck('id'),
                'message' => 'QC inspection recorded successfully.',
            ]);
        });
    }

    public function bulkInspect(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'QC']) ?: abort(403, 'Unauthorized. QC operational permission required.');

        $request->validate([
            'receipt_item_ids' => ['nullable', 'array'],
            'receipt_item_ids.*' => ['integer'],
            'bom_item_ids' => ['nullable', 'array'],
            'bom_item_ids.*' => ['integer'],
            'side' => ['nullable', 'in:RH,LH,COMMON'],
            'result' => ['required', 'in:approved,rejected,rework'],
            'destination' => ['required_if:result,approved', 'nullable', 'in:PAINT,ASSEMBLY'],
            'rejection_reason' => ['nullable', 'string', 'max:255'],
            'rework_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $ids = $request->input('receipt_item_ids', []);
            $bomIds = $request->input('bom_item_ids', []);
            $side = $request->input('side');
            $result = $request->input('result');
            $destination = $result === 'approved' ? $request->input('destination') : null;

            $query = ReceiptItem::query()->where('status', 'qc_received');

            if (!empty($ids) && !empty($bomIds)) {
                $query->where(function ($q) use ($ids, $bomIds) {
                    $q->whereIn('id', $ids)->orWhereIn('bom_item_id', $bomIds);
                });
            } elseif (!empty($ids)) {
                $query->whereIn('id', $ids);
            } elseif (!empty($bomIds)) {
                $query->whereIn('bom_item_id', $bomIds);
            } else {
                return response()->json(['success' => false, 'message' => 'No items provided for QC inspection.'], 422);
            }

            if ($side) {
                $query->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                });
            }

            $items = $query->lockForUpdate()
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
                    $receiptItem->update(['status' => 'qc_rejected']);
                    WorkflowEvent::create([
                        'bom_item_id' => $receiptItem->bom_item_id,
                        'project_id' => $receiptItem->bomItem->project_id,
                        'user_id' => $request->user()->id,
                        'event_type' => 'returned_to_purchase',
                        'side' => $receiptItem->side,
                        'quantity' => $rejectedQty,
                        'previous_state' => 'qc_received',
                        'new_state' => 'qc_rejected',
                        'remarks' => "Bulk QC Rejection: " . ($request->input('rejection_reason') ?? 'Dimensional Defect') . ". Sent to Purchase Queue for re-ordering.",
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
