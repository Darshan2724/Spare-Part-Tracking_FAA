<?php

namespace App\Services;

use App\Events\EcnUpdated;
use App\Models\EcnReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnWorkflowEvent;
use App\Models\EcnWorkflowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EcnWorkflowService
{
    /**
     * Receive ECN part into Store.
     */
    public function receiveStore(int $ecnRequirementId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnRequirementId, $quantity, $remarks, $userId) {
            $req = EcnRequirement::lockForUpdate()->findOrFail($ecnRequirementId);

            $req->received_qty += $quantity;
            $prevState = $req->current_state;
            $req->current_state = 'STORE';
            $req->save();

            $receiptItem = EcnReceiptItem::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'received_quantity' => $quantity,
                'status' => 'received',
                'remarks' => $remarks,
                'processed_by' => $userId,
            ]);

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_RECEIVED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'STORE',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'STORE',
                'event_type' => 'ECN_RECEIVED',
            ]));

            return [
                'success' => true,
                'message' => "Successfully received {$quantity} pcs of ECN part {$req->part_no} into Store.",
                'receipt_item' => $receiptItem,
            ];
        });
    }

    /**
     * Transfer ECN parts from Store to QC.
     */
    public function sendToQc(int $ecnReceiptItemId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnReceiptItemId, $quantity, $remarks, $userId) {
            $item = EcnReceiptItem::lockForUpdate()->findOrFail($ecnReceiptItemId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($item->ecn_requirement_id);

            $prevState = $item->status;
            $item->status = 'sent_to_qc';
            $item->save();

            $req->current_state = 'QC';
            $req->save();

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_SENT_TO_QC',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'QC',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'QC',
                'event_type' => 'ECN_MOVED',
            ]));

            return [
                'success' => true,
                'message' => "Successfully sent {$quantity} pcs to QC.",
                'receipt_item' => $item,
            ];
        });
    }

    /**
     * QC Arrival confirmation.
     */
    public function qcReceive(int $ecnReceiptItemId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnReceiptItemId, $quantity, $remarks, $userId) {
            $item = EcnReceiptItem::lockForUpdate()->findOrFail($ecnReceiptItemId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($item->ecn_requirement_id);

            $prevState = $item->status;
            $item->status = 'qc_received';
            $item->save();

            $req->current_state = 'QC';
            $req->save();

            $wfRecord = EcnWorkflowRecord::create([
                'ecn_receipt_item_id' => $item->id,
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'department' => 'QC',
                'action' => 'qc_received',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'remarks' => $remarks,
                'processed_by' => $userId,
                'status' => 'completed',
            ]);

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_QC_RECEIVED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'QC_ARRIVED',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => $prevState,
                'new_state' => 'QC_ARRIVED',
                'event_type' => 'ECN_APPROVED',
            ]));

            return [
                'success' => true,
                'message' => "QC Arrival confirmed for {$quantity} pcs.",
                'workflow_record' => $wfRecord,
            ];
        });
    }

    /**
     * QC Inspection with routing to PAINT, ASSEMBLY, or REWORK.
     */
    public function qcInspect(
        int $ecnReceiptItemId,
        int $approvedQty = 0,
        string $destination = 'ASSEMBLY',
        int $rejectedQty = 0,
        int $reworkQty = 0,
        ?string $remarks = null,
        ?int $userId = null,
        int $paintQty = 0,
        int $assemblyQty = 0
    ): array {
        return DB::transaction(function () use ($ecnReceiptItemId, $approvedQty, $destination, $rejectedQty, $reworkQty, $remarks, $userId, $paintQty, $assemblyQty) {
            $item = EcnReceiptItem::lockForUpdate()->findOrFail($ecnReceiptItemId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($item->ecn_requirement_id);

            // Strict check: item must be in active QC inspection queue
            if (!in_array($item->status, ['qc_received', 'sent_to_qc'])) {
                throw new \InvalidArgumentException("This ECN item (status: {$item->status}) is no longer in the active QC inspection queue.");
            }

            if ($paintQty > 0 || $assemblyQty > 0) {
                $effectiveApproved = $paintQty + $assemblyQty;
            } else {
                $effectiveApproved = $approvedQty;
            }

            $totalInspected = $effectiveApproved + $rejectedQty + $reworkQty;
            if ($totalInspected <= 0) {
                throw new \InvalidArgumentException("Total inspection quantity must be greater than zero.");
            }

            if ($totalInspected > $item->received_quantity) {
                throw new \InvalidArgumentException("Total inspected quantity ({$totalInspected}) exceeds available quantity ({$item->received_quantity}).");
            }

            $destUpper = strtoupper(trim($destination));
            if (!in_array($destUpper, ['PAINT', 'ASSEMBLY'])) {
                $destUpper = 'ASSEMBLY';
            }

            // Determine status for receipt item
            if ($rejectedQty > 0 && $effectiveApproved === 0 && $reworkQty === 0) {
                $item->status = 'qc_rejected';
            } elseif ($reworkQty > 0 && $effectiveApproved === 0 && $rejectedQty === 0) {
                $item->status = 'qc_rework';
            } else {
                $item->status = 'qc_approved';
            }
            $item->save();

            // Record QC inspection record
            $qcRecord = EcnWorkflowRecord::create([
                'ecn_receipt_item_id' => $item->id,
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'department' => 'QC',
                'action' => 'qc_inspected',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $totalInspected,
                'destination' => $destUpper,
                'approved_quantity' => $effectiveApproved,
                'rejected_quantity' => $rejectedQty,
                'rework_quantity' => $reworkQty,
                'remarks' => $remarks,
                'processed_by' => $userId,
                'status' => 'completed',
            ]);

            // Route Approved quantities to destination queues
            if ($paintQty > 0 || $assemblyQty > 0) {
                if ($paintQty > 0) {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $item->id,
                        'ecn_requirement_id' => $req->id,
                        'project_id' => $req->project_id,
                        'ecn_number' => $req->ecn_number,
                        'department' => 'PAINT',
                        'action' => 'paint_queued',
                        'side' => $req->side,
                        'side_display' => $req->side_display,
                        'quantity' => $paintQty,
                        'remarks' => "QC Approved Split -> PAINT ({$paintQty} pcs)",
                        'processed_by' => $userId,
                        'status' => 'in_progress',
                    ]);
                }
                if ($assemblyQty > 0) {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $item->id,
                        'ecn_requirement_id' => $req->id,
                        'project_id' => $req->project_id,
                        'ecn_number' => $req->ecn_number,
                        'department' => 'ASSEMBLY',
                        'action' => 'assembly_queued',
                        'side' => $req->side,
                        'side_display' => $req->side_display,
                        'quantity' => $assemblyQty,
                        'remarks' => "QC Approved Split -> ASSEMBLY ({$assemblyQty} pcs)",
                        'processed_by' => $userId,
                        'status' => 'in_progress',
                    ]);
                }

                $req->current_state = ($paintQty > 0 && $assemblyQty === 0) ? 'PAINT' : 'ASSEMBLY';
                $req->save();
            } elseif ($effectiveApproved > 0) {
                EcnWorkflowRecord::create([
                    'ecn_receipt_item_id' => $item->id,
                    'ecn_requirement_id' => $req->id,
                    'project_id' => $req->project_id,
                    'ecn_number' => $req->ecn_number,
                    'department' => $destUpper,
                    'action' => $destUpper === 'PAINT' ? 'paint_queued' : 'assembly_queued',
                    'side' => $req->side,
                    'side_display' => $req->side_display,
                    'quantity' => $effectiveApproved,
                    'remarks' => "QC Approved -> {$destUpper}",
                    'processed_by' => $userId,
                    'status' => 'in_progress',
                ]);

                $req->current_state = $destUpper;
                $req->save();
            }

            // Route Rework quantity to Rework queue
            if ($reworkQty > 0) {
                EcnWorkflowRecord::create([
                    'ecn_receipt_item_id' => $item->id,
                    'ecn_requirement_id' => $req->id,
                    'project_id' => $req->project_id,
                    'ecn_number' => $req->ecn_number,
                    'department' => 'REWORK',
                    'action' => 'rework_queued',
                    'side' => $req->side,
                    'side_display' => $req->side_display,
                    'quantity' => $reworkQty,
                    'remarks' => "QC Rejected to Rework: {$remarks}",
                    'processed_by' => $userId,
                    'status' => 'in_progress',
                ]);

                $req->current_state = 'REWORK';
                $req->save();
            }

            // Route Rejected quantity to Purchase queue
            if ($rejectedQty > 0) {
                EcnWorkflowRecord::create([
                    'ecn_receipt_item_id' => $item->id,
                    'ecn_requirement_id' => $req->id,
                    'project_id' => $req->project_id,
                    'ecn_number' => $req->ecn_number,
                    'department' => 'QC',
                    'action' => 'qc_rejected',
                    'side' => $req->side,
                    'side_display' => $req->side_display,
                    'quantity' => $rejectedQty,
                    'rejected_quantity' => $rejectedQty,
                    'remarks' => "QC Rejected -> Purchase: " . ($remarks ?: 'Defective part requiring purchase replacement'),
                    'processed_by' => $userId,
                    'status' => 'pending_purchase',
                ]);

                $normalizedSide = in_array(strtoupper($req->side_display ?: $req->side), ['RH', 'LH', 'COMMON']) 
                    ? strtoupper($req->side_display ?: $req->side) 
                    : (str_starts_with(strtoupper($req->side), 'R') ? 'RH' : 'LH');

                // Deduplicate PurchaseQueueItem per ECN requirement / receipt item
                $existingPurchase = \App\Models\PurchaseQueueItem::where('project_id', $req->project_id)
                    ->where('standard_part_no', $req->part_no)
                    ->where('side', $normalizedSide)
                    ->where('status', 'pending_purchase')
                    ->where('remarks', 'LIKE', "%Req #{$req->id}%")
                    ->first();

                if ($existingPurchase) {
                    $existingPurchase->increment('rejected_quantity', $rejectedQty);
                } else {
                    \App\Models\PurchaseQueueItem::create([
                        'bom_item_id' => null,
                        'qc_inspection_id' => null,
                        'project_id' => $req->project_id,
                        'standard_part_no' => $req->part_no,
                        'side' => $normalizedSide,
                        'rejected_quantity' => $rejectedQty,
                        'rejection_reason' => $remarks ?: 'ECN QC Inspection Rejection',
                        'rejected_by' => $userId,
                        'rejected_at' => now(),
                        'status' => 'pending_purchase',
                        'remarks' => "ECN: {$req->ecn_number} | Req #{$req->id} | ReceiptItem #{$item->id} | Jig: {$req->jig_no} | Unit: {$req->unit_no} | Original Side: {$req->side}",
                    ]);
                }

                // If rejected, decrement received_qty and update current_state so it leaves active QC
                $req->received_qty = max(0, $req->received_qty - $rejectedQty);
                if ($effectiveApproved === 0 && $reworkQty === 0) {
                    $req->current_state = $req->received_qty > 0 ? 'STORE' : 'PENDING';
                }
                $req->save();
            }

            $finalState = $req->current_state;
            $eventType = ($rejectedQty > 0 && $effectiveApproved === 0) ? 'ECN_QC_REJECTED' : 'ECN_QC_APPROVED';

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => $eventType,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $totalInspected,
                'previous_state' => 'QC',
                'new_state' => $finalState,
                'remarks' => $remarks,
                'metadata' => [
                    'approved' => $approvedQty,
                    'rejected' => $rejectedQty,
                    'rework' => $reworkQty,
                    'destination' => $destUpper,
                ],
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $totalInspected,
                'previous_state' => 'QC',
                'new_state' => $finalState,
                'event_type' => $eventType,
            ]));

            return [
                'success' => true,
                'message' => "QC Inspection saved: {$approvedQty} approved -> {$destUpper}, {$reworkQty} rework, {$rejectedQty} rejected.",
                'qc_record' => $qcRecord,
            ];
        });
    }

    /**
     * Complete ECN Rework and return to QC.
     */
    public function completeRework(int $ecnWorkflowRecordId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnWorkflowRecordId, $quantity, $remarks, $userId) {
            $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($ecnWorkflowRecordId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

            $record->status = 'completed';
            $record->action = 'rework_completed';
            $record->save();

            // Create QC Arrival record for re-inspection
            EcnWorkflowRecord::create([
                'ecn_receipt_item_id' => $record->ecn_receipt_item_id,
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'department' => 'QC',
                'action' => 'qc_received',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'remarks' => "Returned from Rework: {$remarks}",
                'processed_by' => $userId,
                'status' => 'in_progress',
            ]);

            $req->current_state = 'QC';
            $req->save();

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_REWORK_COMPLETED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'REWORK',
                'new_state' => 'QC',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'REWORK',
                'new_state' => 'QC',
                'event_type' => 'ECN_REWORKED',
            ]));

            return [
                'success' => true,
                'message' => "Rework completed for {$quantity} pcs -> returned to QC queue.",
            ];
        });
    }

    /**
     * Complete ECN Paint Shop work and transfer to Assembly.
     */
    public function completePaint(int $ecnWorkflowRecordId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnWorkflowRecordId, $quantity, $remarks, $userId) {
            $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($ecnWorkflowRecordId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

            $record->status = 'completed';
            $record->action = 'paint_completed';
            $record->save();

            // Route to Assembly
            EcnWorkflowRecord::create([
                'ecn_receipt_item_id' => $record->ecn_receipt_item_id,
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'department' => 'ASSEMBLY',
                'action' => 'assembly_queued',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'remarks' => "Painted -> Transferred to Assembly: {$remarks}",
                'processed_by' => $userId,
                'status' => 'in_progress',
            ]);

            $req->current_state = 'ASSEMBLY';
            $req->save();

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_PAINT_COMPLETED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'PAINT',
                'new_state' => 'ASSEMBLY',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'PAINT',
                'new_state' => 'ASSEMBLY',
                'event_type' => 'ECN_MOVED',
            ]));

            return [
                'success' => true,
                'message' => "Paint completed for {$quantity} pcs -> moved to Assembly queue.",
            ];
        });
    }

    /**
     * Complete ECN Assembly work (final stage).
     */
    public function completeAssembly(int $ecnWorkflowRecordId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($ecnWorkflowRecordId, $quantity, $remarks, $userId) {
            $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($ecnWorkflowRecordId);
            $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

            $record->status = 'completed';
            $record->action = 'assembly_completed';
            $record->save();

            $req->current_state = 'ASSEMBLY_COMPLETED';
            $req->save();

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_ASSEMBLY_COMPLETED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'ASSEMBLY',
                'new_state' => 'ASSEMBLY_COMPLETED',
                'remarks' => $remarks,
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => 'ASSEMBLY',
                'new_state' => 'ASSEMBLY_COMPLETED',
                'event_type' => 'ECN_COMPLETED',
            ]));

            return [
                'success' => true,
                'message' => "Assembly completed for {$quantity} pcs.",
            ];
        });
    }

    /**
     * ECN Revert operation (isolated from regular Revert).
     */
    public function revert(string $department, int $recordId, int $quantity, ?string $remarks = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($department, $recordId, $quantity, $remarks, $userId) {
            $dept = strtolower(trim($department));

            switch ($dept) {
                case 'store':
                    $item = EcnReceiptItem::lockForUpdate()->findOrFail($recordId);
                    $req = EcnRequirement::lockForUpdate()->findOrFail($item->ecn_requirement_id);

                    $revertQty = min($quantity, $item->received_quantity);
                    $item->status = 'reverted';
                    $item->save();

                    $req->received_qty = max(0, $req->received_qty - $revertQty);
                    $req->current_state = $req->received_qty > 0 ? 'STORE' : 'PENDING';
                    $req->save();

                    $targetDept = 'PENDING_ARRIVAL';
                    break;

                case 'qc':
                    $item = EcnReceiptItem::lockForUpdate()->findOrFail($recordId);
                    $req = EcnRequirement::lockForUpdate()->findOrFail($item->ecn_requirement_id);

                    $item->status = 'received';
                    $item->save();

                    $req->current_state = 'STORE';
                    $req->save();

                    $targetDept = 'STORE';
                    break;

                case 'rework':
                    $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($recordId);
                    $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

                    $record->status = 'reverted';
                    $record->save();

                    if ($record->ecn_receipt_item_id) {
                        $recItem = EcnReceiptItem::find($record->ecn_receipt_item_id);
                        if ($recItem) {
                            $recItem->status = 'qc_received';
                            $recItem->save();
                        }
                    }

                    $req->current_state = 'QC';
                    $req->save();

                    $targetDept = 'QC';
                    break;

                case 'paint':
                    $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($recordId);
                    $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

                    $record->status = 'reverted';
                    $record->save();

                    if ($record->ecn_receipt_item_id) {
                        $recItem = EcnReceiptItem::find($record->ecn_receipt_item_id);
                        if ($recItem) {
                            $recItem->status = 'qc_received';
                            $recItem->save();
                        }
                    }

                    $req->current_state = 'QC';
                    $req->save();

                    $targetDept = 'QC';
                    break;

                case 'assembly':
                    $record = EcnWorkflowRecord::lockForUpdate()->findOrFail($recordId);
                    $req = EcnRequirement::lockForUpdate()->findOrFail($record->ecn_requirement_id);

                    $record->status = 'reverted';
                    $record->save();

                    // Check lineage to determine if it came from Paint or QC
                    $hasPaint = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
                        ->where('department', 'PAINT')
                        ->where('action', 'paint_completed')
                        ->exists();

                    $targetDept = $hasPaint ? 'PAINT' : 'QC';

                    if ($targetDept === 'QC' && $record->ecn_receipt_item_id) {
                        $recItem = EcnReceiptItem::find($record->ecn_receipt_item_id);
                        if ($recItem) {
                            $recItem->status = 'qc_received';
                            $recItem->save();
                        }
                    }

                    $req->current_state = $targetDept;
                    $req->save();
                    break;

                case 'purchase':
                    $pq = \App\Models\PurchaseQueueItem::lockForUpdate()->find($recordId);
                    $item = null;
                    $req = null;

                    if ($pq) {
                        if (preg_match('/ReceiptItem #(\d+)/', $pq->remarks ?? '', $mRec)) {
                            $item = EcnReceiptItem::lockForUpdate()->find((int)$mRec[1]);
                        }
                        if (preg_match('/Req #(\d+)/', $pq->remarks ?? '', $mReq)) {
                            $req = EcnRequirement::lockForUpdate()->find((int)$mReq[1]);
                        }
                        if (!$req && $pq->project_id) {
                            $req = EcnRequirement::where('project_id', $pq->project_id)
                                ->where('part_no', $pq->standard_part_no)
                                ->lockForUpdate()
                                ->first();
                        }
                        $revertQty = min($quantity, (int)$pq->rejected_quantity);
                        if ($revertQty === (int)$pq->rejected_quantity) {
                            $pq->update(['rejected_quantity' => 0, 'status' => 'closed', 'remarks' => trim(($pq->remarks ?? '') . " | [REVERTED TO QC ARRIVAL] Reverted to QC Arrival: {$remarks}")]);
                        } else {
                            $pq->decrement('rejected_quantity', $revertQty);
                            $pq->update(['remarks' => trim(($pq->remarks ?? '') . " | [PARTIALLY REVERTED TO QC ARRIVAL] Partially reverted {$revertQty} pcs to QC Arrival: {$remarks}")]);
                        }
                    } else {
                        $item = EcnReceiptItem::lockForUpdate()->find($recordId);
                        if ($item) {
                            $req = EcnRequirement::lockForUpdate()->find($item->ecn_requirement_id);
                        } else {
                            $req = EcnRequirement::lockForUpdate()->findOrFail($recordId);
                        }
                        $revertQty = $quantity;
                    }

                    if ($item) {
                        $item->status = 'received';
                        $item->save();
                    }
                    if ($req) {
                        $req->current_state = 'STORE'; // In ECN, STORE is QC Arrival
                        $req->save();
                    } else {
                        throw new \InvalidArgumentException("Could not resolve ECN requirement for purchase revert record #{$recordId}");
                    }

                    $targetDept = 'QC_ARRIVAL';
                    break;

                default:
                    throw new \InvalidArgumentException("Invalid department '{$department}' for ECN revert.");
            }

            EcnWorkflowEvent::create([
                'ecn_requirement_id' => $req->id,
                'project_id' => $req->project_id,
                'ecn_number' => $req->ecn_number,
                'user_id' => $userId,
                'event_type' => 'ECN_REVERTED',
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => strtoupper($dept),
                'new_state' => $targetDept,
                'remarks' => "Reverted from " . strtoupper($dept) . " to {$targetDept}: {$remarks}",
            ]);

            event(new EcnUpdated([
                'project_id' => $req->project_id,
                'ecn_requirement_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'jig_no' => $req->jig_no,
                'unit_no' => $req->unit_no,
                'part_no' => $req->part_no,
                'side' => $req->side,
                'side_display' => $req->side_display,
                'quantity' => $quantity,
                'previous_state' => strtoupper($dept),
                'new_state' => $targetDept,
                'event_type' => 'ECN_REVERTED',
            ]));

            return [
                'success' => true,
                'message' => "Successfully reverted ECN part from " . strtoupper($dept) . " to {$targetDept}.",
                'target_department' => $targetDept,
            ];
        });
    }

    /**
     * Get revert-eligible options for a specific ECN requirement.
     */
    public function getRevertOptions(string $department, int $ecnRequirementId): array
    {
        $dept = strtolower(trim($department));
        $req = EcnRequirement::findOrFail($ecnRequirementId);
        $options = [];

        switch ($dept) {
            case 'store':
                $items = EcnReceiptItem::where('ecn_requirement_id', $req->id)
                    ->whereIn('status', ['received', 'store_received'])
                    ->get();
                foreach ($items as $item) {
                    $options[] = [
                        'source_type' => 'ecn_receipt_item',
                        'source_id' => $item->id,
                        'available_quantity' => $item->received_quantity,
                        'from_department' => 'STORE',
                        'to_department' => 'PENDING_ARRIVAL',
                        'target_label' => 'Pending Supplier Arrival',
                        'description' => "ECN Store Stock Receipt #{$item->id} ({$item->received_quantity} pcs)",
                    ];
                }
                break;

            case 'qc':
                $items = EcnReceiptItem::where('ecn_requirement_id', $req->id)
                    ->whereIn('status', ['sent_to_qc', 'qc_received'])
                    ->get();
                foreach ($items as $item) {
                    $options[] = [
                        'source_type' => 'ecn_receipt_item',
                        'source_id' => $item->id,
                        'available_quantity' => $item->received_quantity,
                        'from_department' => 'QC',
                        'to_department' => 'STORE',
                        'target_label' => 'Store Bay',
                        'description' => "ECN QC Arrival ({$item->received_quantity} pcs)",
                    ];
                }
                break;

            case 'rework':
                $records = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
                    ->where('department', 'REWORK')
                    ->where('status', 'in_progress')
                    ->get();
                foreach ($records as $r) {
                    $options[] = [
                        'source_type' => 'ecn_workflow_record',
                        'source_id' => $r->id,
                        'available_quantity' => $r->quantity,
                        'from_department' => 'REWORK',
                        'to_department' => 'QC',
                        'target_label' => 'QC Bay',
                        'description' => "ECN Rework Queue #{$r->id} ({$r->quantity} pcs)",
                    ];
                }
                break;

            case 'paint':
                $records = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
                    ->where('department', 'PAINT')
                    ->where('status', 'in_progress')
                    ->get();
                foreach ($records as $r) {
                    $options[] = [
                        'source_type' => 'ecn_workflow_record',
                        'source_id' => $r->id,
                        'available_quantity' => $r->quantity,
                        'from_department' => 'PAINT',
                        'to_department' => 'QC',
                        'target_label' => 'QC Bay',
                        'description' => "ECN Paint Shop Queue #{$r->id} ({$r->quantity} pcs)",
                    ];
                }
                break;

            case 'assembly':
                $records = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
                    ->where('department', 'ASSEMBLY')
                    ->whereIn('status', ['in_progress', 'completed'])
                    ->get();
                foreach ($records as $r) {
                    $hasPaint = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
                        ->where('department', 'PAINT')
                        ->where('action', 'paint_completed')
                        ->exists();
                    $target = $hasPaint ? 'PAINT' : 'QC';

                    $options[] = [
                        'source_type' => 'ecn_workflow_record',
                        'source_id' => $r->id,
                        'available_quantity' => $r->quantity,
                        'from_department' => 'ASSEMBLY',
                        'to_department' => $target,
                        'target_label' => $target . ' Bay',
                        'description' => "ECN Assembly Record #{$r->id} ({$r->quantity} pcs)",
                    ];
                }
                break;
        }

        return $options;
    }
}
