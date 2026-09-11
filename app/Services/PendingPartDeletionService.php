<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\EcnWorkflowEvent;
use App\Events\EcnUpdated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PendingPartDeletionService
{
    /**
     * Supported BOM types.
     */
    public const VALID_BOM_TYPES = ['MFG', 'BOP', 'STD', 'ECN'];

    /**
     * Get distinct projects that have BOM items or ECN requirements.
     *
     * @return Collection
     */
    public function getProjects(): Collection
    {
        return Project::query()
            ->select('id', 'project_code', 'name', 'customer_name')
            ->where(function ($q) {
                $q->whereHas('bomItems')
                  ->orWhereHas('ecnRequirements');
            })
            ->orderBy('project_code')
            ->get();
    }

    /**
     * Get distinct Jigs for a given project and BOM type.
     *
     * @param int $projectId
     * @param string $bomType
     * @return array
     */
    public function getJigs(int $projectId, string $bomType): array
    {
        $bomType = strtoupper(trim($bomType));

        if ($bomType === 'ECN') {
            return EcnRequirement::query()
                ->where('project_id', $projectId)
                ->whereNotNull('jig_no')
                ->where('jig_no', '!=', '')
                ->distinct()
                ->orderBy('jig_no')
                ->pluck('jig_no')
                ->values()
                ->toArray();
        }

        return BomItem::query()
            ->where('project_id', $projectId)
            ->where('part_type', $bomType)
            ->whereNotNull('jig_no')
            ->where('jig_no', '!=', '')
            ->distinct()
            ->orderBy('jig_no')
            ->pluck('jig_no')
            ->values()
            ->toArray();
    }

    /**
     * Get distinct Units for a given project, BOM type, and Jig.
     *
     * @param int $projectId
     * @param string $bomType
     * @param string $jigNo
     * @return array
     */
    public function getUnits(int $projectId, string $bomType, string $jigNo): array
    {
        $bomType = strtoupper(trim($bomType));

        if ($bomType === 'ECN') {
            return EcnRequirement::query()
                ->where('project_id', $projectId)
                ->where('jig_no', $jigNo)
                ->whereNotNull('unit_no')
                ->where('unit_no', '!=', '')
                ->distinct()
                ->orderBy('unit_no')
                ->pluck('unit_no')
                ->values()
                ->toArray();
        }

        return BomItem::query()
            ->where('project_id', $projectId)
            ->where('part_type', $bomType)
            ->where('jig_no', $jigNo)
            ->whereNotNull('unit_no')
            ->where('unit_no', '!=', '')
            ->distinct()
            ->orderBy('unit_no')
            ->pluck('unit_no')
            ->values()
            ->toArray();
    }

    /**
     * Get distinct Sides for a given project, BOM type, Jig, and Unit.
     *
     * @param int $projectId
     * @param string $bomType
     * @param string $jigNo
     * @param string $unitNo
     * @return array
     */
    public function getSides(int $projectId, string $bomType, string $jigNo, string $unitNo): array
    {
        $bomType = strtoupper(trim($bomType));

        if ($bomType === 'ECN') {
            $sides = EcnRequirement::query()
                ->where('project_id', $projectId)
                ->where('jig_no', $jigNo)
                ->where('unit_no', $unitNo)
                ->selectRaw("COALESCE(NULLIF(side_display, ''), side) as resolved_side")
                ->distinct()
                ->pluck('resolved_side')
                ->filter()
                ->values()
                ->toArray();

            sort($sides);
            return array_values(array_unique($sides));
        }

        $sides = BomRequirement::query()
            ->join('bom_items', 'bom_requirements.bom_item_id', '=', 'bom_items.id')
            ->where('bom_items.project_id', $projectId)
            ->where('bom_items.part_type', $bomType)
            ->where('bom_items.jig_no', $jigNo)
            ->where('bom_items.unit_no', $unitNo)
            ->whereNull('bom_items.deleted_at')
            ->distinct()
            ->pluck('bom_requirements.side')
            ->filter()
            ->values()
            ->toArray();

        sort($sides);
        return array_values(array_unique($sides));
    }

    /**
     * Get eligible pending parts strictly matching filters and guaranteed 0 downstream operations.
     *
     * @param int $projectId
     * @param string $bomType
     * @param string|null $jigNo
     * @param string|null $unitNo
     * @param string|null $side
     * @return Collection
     */
    public function getEligiblePendingParts(
        int $projectId,
        string $bomType,
        ?string $jigNo = null,
        ?string $unitNo = null,
        ?string $side = null
    ): Collection {
        $bomType = strtoupper(trim($bomType));

        if (!in_array($bomType, self::VALID_BOM_TYPES, true)) {
            throw new InvalidArgumentException("Invalid BOM type: {$bomType}");
        }

        if ($bomType === 'ECN') {
            return $this->getEligiblePendingEcnParts($projectId, $jigNo, $unitNo, $side);
        }

        return $this->getEligiblePendingRegularParts($projectId, $bomType, $jigNo, $unitNo, $side);
    }

    /**
     * Query eligible pending parts for Regular BOM (MFG, BOP, STD).
     */
    protected function getEligiblePendingRegularParts(
        int $projectId,
        string $bomType,
        ?string $jigNo,
        ?string $unitNo,
        ?string $side
    ): Collection {
        $query = BomRequirement::query()
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->where('required_quantity', '>', 0)
            ->whereHas('bomItem', function ($q) use ($projectId, $bomType, $jigNo, $unitNo) {
                $q->where('project_id', $projectId)
                  ->where('part_type', $bomType);

                if (!empty($jigNo)) {
                    $q->where('jig_no', $jigNo);
                }
                if (!empty($unitNo)) {
                    $q->where('unit_no', $unitNo);
                }
            });

        if (!empty($side)) {
            $query->where('side', $side);
        }

        // Strict eligibility: NOT EXISTS in any downstream processing tables for this (bom_item_id, side)
        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('receipt_items')
                ->whereColumn('receipt_items.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('receipt_items.side', 'bom_requirements.side');
        });

        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('qc_inspections')
                ->whereColumn('qc_inspections.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('qc_inspections.side', 'bom_requirements.side');
        });

        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('rework_records')
                ->whereColumn('rework_records.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('rework_records.side', 'bom_requirements.side');
        });

        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('paint_records')
                ->whereColumn('paint_records.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('paint_records.side', 'bom_requirements.side');
        });

        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('assembly_records')
                ->whereColumn('assembly_records.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('assembly_records.side', 'bom_requirements.side');
        });

        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('purchase_queue_items')
                ->whereColumn('purchase_queue_items.bom_item_id', 'bom_requirements.bom_item_id')
                ->whereColumn('purchase_queue_items.side', 'bom_requirements.side');
        });

        $results = $query->orderBy('id')->get();

        return $results->map(function (BomRequirement $req) {
            $bomItem = $req->bomItem;
            return [
                'id'                => $req->id,
                'requirement_id'    => $req->id,
                'bom_item_id'       => $bomItem->id,
                'bom_type'          => $bomItem->part_type,
                'project_id'        => $bomItem->project_id,
                'project_code'      => $bomItem->project?->project_code ?? '',
                'project_name'      => $bomItem->project?->name ?? '',
                'jig_no'            => $bomItem->jig_no,
                'unit_no'           => $bomItem->unit_no,
                'side'              => $req->side,
                'item_no'           => $bomItem->item_no,
                'standard_part_no'  => $bomItem->standard_part_no,
                'description'       => $bomItem->size ?: ($bomItem->remarks ?: '-'),
                'supplier_name'     => $bomItem->supplier?->name ?? ($bomItem->supplier_name_raw ?: '-'),
                'required_quantity' => (int)$req->required_quantity,
                'status'            => 'Pending',
            ];
        });
    }

    /**
     * Query eligible pending parts for ECN.
     */
    protected function getEligiblePendingEcnParts(
        int $projectId,
        ?string $jigNo,
        ?string $unitNo,
        ?string $side
    ): Collection {
        $query = EcnRequirement::query()
            ->with(['project'])
            ->where('project_id', $projectId)
            ->where('current_state', 'PENDING')
            ->where('received_qty', 0)
            ->where('required_qty', '>', 0);

        if (!empty($jigNo)) {
            $query->where('jig_no', $jigNo);
        }
        if (!empty($unitNo)) {
            $query->where('unit_no', $unitNo);
        }
        if (!empty($side)) {
            $query->where(function ($q) use ($side) {
                $q->where('side_display', $side)
                  ->orWhere('side', $side);
            });
        }

        // Strict eligibility: zero receipt items, workflow records, or events
        $query->whereDoesntHave('receiptItems')
              ->whereDoesntHave('workflowRecords')
              ->whereDoesntHave('workflowEvents');

        $results = $query->orderBy('id')->get();

        return $results->map(function (EcnRequirement $req) {
            return [
                'id'                => $req->id,
                'requirement_id'    => $req->id,
                'bom_item_id'       => null,
                'bom_type'          => 'ECN',
                'project_id'        => $req->project_id,
                'project_code'      => $req->project?->project_code ?? '',
                'project_name'      => $req->project?->name ?? '',
                'jig_no'            => $req->jig_no,
                'unit_no'           => $req->unit_no,
                'side'              => $req->side_display ?: $req->side,
                'item_no'           => $req->part_no,
                'standard_part_no'  => $req->part_no,
                'ecn_number'        => $req->ecn_number,
                'description'       => "ECN: {$req->ecn_number}",
                'supplier_name'     => '-',
                'required_quantity' => (int)$req->required_qty,
                'status'            => 'Pending',
            ];
        });
    }

    /**
     * Delete or decrement pending part quantity with transaction and strict row locking.
     *
     * @param int $id Requirement ID
     * @param string $bomType 'MFG', 'BOP', 'STD', 'ECN'
     * @param int|null $quantity Quantity to delete (default: full remaining quantity)
     * @param string|null $reason Optional audit reason
     * @param mixed $user Requesting user
     * @return array
     */
    public function deletePendingPart(
        int $id,
        string $bomType,
        ?int $quantity = null,
        ?string $reason = null,
        $user = null
    ): array {
        $bomType = strtoupper(trim($bomType));

        if (!in_array($bomType, self::VALID_BOM_TYPES, true)) {
            throw new InvalidArgumentException("Invalid BOM type: {$bomType}");
        }

        return DB::transaction(function () use ($id, $bomType, $quantity, $reason, $user) {
            if ($bomType === 'ECN') {
                return $this->deletePendingEcnPart($id, $quantity, $reason, $user);
            }

            return $this->deletePendingRegularPart($id, $bomType, $quantity, $reason, $user);
        });
    }

    /**
     * Delete or decrement regular BOM pending requirement.
     */
    protected function deletePendingRegularPart(
        int $id,
        string $bomType,
        ?int $quantity,
        ?string $reason,
        $user
    ): array {
        /** @var BomRequirement|null $req */
        $req = BomRequirement::query()->lockForUpdate()->find($id);

        if (!$req) {
            throw new RuntimeException("BOM requirement with ID #{$id} not found.");
        }

        /** @var BomItem|null $bomItem */
        $bomItem = BomItem::query()->lockForUpdate()->find($req->bom_item_id);

        if (!$bomItem) {
            throw new RuntimeException("Associated BOM item not found for requirement #{$id}.");
        }

        if ($bomItem->part_type !== $bomType) {
            throw new RuntimeException("Mismatched BOM type: expected {$bomType}, found {$bomItem->part_type}.");
        }

        // Strict validation: Verify zero downstream records exist for this (bom_item_id, side)
        $hasReceipts = ReceiptItem::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasReceipts) {
            throw new RuntimeException("Cannot delete part: receipt records already exist for this part and side.");
        }

        $hasQc = QcInspection::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasQc) {
            throw new RuntimeException("Cannot delete part: QC inspection records exist.");
        }

        $hasRework = ReworkRecord::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasRework) {
            throw new RuntimeException("Cannot delete part: Rework records exist.");
        }

        $hasPaint = PaintRecord::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasPaint) {
            throw new RuntimeException("Cannot delete part: Paint records exist.");
        }

        $hasAssembly = AssemblyRecord::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasAssembly) {
            throw new RuntimeException("Cannot delete part: Assembly records exist.");
        }

        $hasPurchase = PurchaseQueueItem::query()
            ->where('bom_item_id', $bomItem->id)
            ->where('side', $req->side)
            ->exists();

        if ($hasPurchase) {
            throw new RuntimeException("Cannot delete part: Purchase queue records exist.");
        }

        $currentQty = (int)$req->required_quantity;
        if ($currentQty <= 0) {
            throw new RuntimeException("Requirement quantity is already 0.");
        }

        // Determine delete quantity: if null or 0, delete all remaining
        $qtyToDelete = ($quantity === null || $quantity <= 0) ? $currentQty : (int)$quantity;

        if ($qtyToDelete > $currentQty) {
            throw new RuntimeException("Quantity to delete ({$qtyToDelete}) exceeds current required quantity ({$currentQty}).");
        }

        $remainingQty = $currentQty - $qtyToDelete;
        $bomItemDeleted = false;

        $project = Project::find($bomItem->project_id);
        $projectCode = $project?->project_code ?? 'N/A';

        if ($remainingQty > 0) {
            // Decrement requirement quantity
            $req->required_quantity = $remainingQty;
            $req->save();
            $action = 'decremented';
        } else {
            // Delete entire requirement row
            $req->delete();
            $action = 'deleted';

            // Check if any other requirements remain for this BomItem
            $remainingReqsCount = BomRequirement::where('bom_item_id', $bomItem->id)->count();
            if ($remainingReqsCount === 0) {
                // Check if any downstream records exist across ANY side
                $anyDownstream = ReceiptItem::where('bom_item_id', $bomItem->id)->exists()
                    || QcInspection::where('bom_item_id', $bomItem->id)->exists()
                    || ReworkRecord::where('bom_item_id', $bomItem->id)->exists()
                    || PaintRecord::where('bom_item_id', $bomItem->id)->exists()
                    || AssemblyRecord::where('bom_item_id', $bomItem->id)->exists()
                    || PurchaseQueueItem::where('bom_item_id', $bomItem->id)->exists();

                if (!$anyDownstream) {
                    $bomItem->forceDelete();
                    $bomItemDeleted = true;
                }
            }
        }

        // Audit log
        SystemLogService::log([
            'severity'   => 'WARNING',
            'category'   => 'admin_actions',
            'module'     => 'PENDING_PART_DELETION',
            'user_id'    => $user?->id,
            'user_role'  => $user?->roles?->first()?->name ?? ($user?->role ?? 'ADMIN'),
            'message'    => "PENDING_PART_DELETED: {$bomType} Part '{$bomItem->standard_part_no}' ({$req->side}) {$action} by {$user?->name}. Deleted qty: {$qtyToDelete}, Remaining qty: {$remainingQty}.",
            'details'    => [
                'event'             => 'PENDING_PART_DELETED',
                'action'            => $action,
                'bom_type'          => $bomType,
                'bom_item_id'       => $bomItem->id,
                'requirement_id'    => $id,
                'project_id'        => $bomItem->project_id,
                'project_code'      => $projectCode,
                'jig_no'            => $bomItem->jig_no,
                'unit_no'           => $bomItem->unit_no,
                'side'              => $req->side,
                'item_no'           => $bomItem->item_no,
                'standard_part_no'  => $bomItem->standard_part_no,
                'deleted_quantity'  => $qtyToDelete,
                'remaining_quantity'=> $remainingQty,
                'bom_item_purged'   => $bomItemDeleted,
                'reason'            => $reason,
                'deleted_by_user_id'=> $user?->id,
                'deleted_by_name'   => $user?->name,
                'timestamp'         => now()->toIso8601String(),
            ],
        ]);

        return [
            'success'            => true,
            'action'             => $action,
            'bom_type'           => $bomType,
            'requirement_id'     => $id,
            'deleted_quantity'   => $qtyToDelete,
            'remaining_quantity' => $remainingQty,
            'bom_item_deleted'   => $bomItemDeleted,
            'message'            => $remainingQty > 0
                ? "Successfully reduced quantity by {$qtyToDelete}. Remaining quantity: {$remainingQty}."
                : "Successfully removed pending part completely (deleted quantity: {$qtyToDelete}).",
        ];
    }

    /**
     * Delete or decrement ECN pending requirement.
     */
    protected function deletePendingEcnPart(
        int $id,
        ?int $quantity,
        ?string $reason,
        $user
    ): array {
        /** @var EcnRequirement|null $ecnReq */
        $ecnReq = EcnRequirement::query()->lockForUpdate()->find($id);

        if (!$ecnReq) {
            throw new RuntimeException("ECN requirement with ID #{$id} not found.");
        }

        // Strict eligibility check
        if ($ecnReq->current_state !== 'PENDING') {
            throw new RuntimeException("Cannot delete ECN part: current state is '{$ecnReq->current_state}', not 'PENDING'.");
        }

        if ((int)$ecnReq->received_qty > 0) {
            throw new RuntimeException("Cannot delete ECN part: received quantity is {$ecnReq->received_qty}.");
        }

        if (EcnReceiptItem::where('ecn_requirement_id', $id)->exists()) {
            throw new RuntimeException("Cannot delete ECN part: receipt records exist.");
        }

        if (EcnWorkflowRecord::where('ecn_requirement_id', $id)->exists()) {
            throw new RuntimeException("Cannot delete ECN part: workflow records exist.");
        }

        if (EcnWorkflowEvent::where('ecn_requirement_id', $id)->exists()) {
            throw new RuntimeException("Cannot delete ECN part: workflow events exist.");
        }

        $currentQty = (int)$ecnReq->required_qty;
        if ($currentQty <= 0) {
            throw new RuntimeException("ECN requirement quantity is already 0.");
        }

        // Determine delete quantity: if null or 0, delete all remaining
        $qtyToDelete = ($quantity === null || $quantity <= 0) ? $currentQty : (int)$quantity;

        if ($qtyToDelete > $currentQty) {
            throw new RuntimeException("Quantity to delete ({$qtyToDelete}) exceeds current required quantity ({$currentQty}).");
        }

        $remainingQty = $currentQty - $qtyToDelete;
        $project = Project::find($ecnReq->project_id);
        $projectCode = $project?->project_code ?? 'N/A';

        if ($remainingQty > 0) {
            $ecnReq->required_qty = $remainingQty;
            $ecnReq->save();
            $action = 'decremented';

            broadcast(new EcnUpdated([
                'project_id'        => $ecnReq->project_id,
                'ecn_requirement_id'=> $ecnReq->id,
                'ecn_number'        => $ecnReq->ecn_number,
                'jig_no'            => $ecnReq->jig_no,
                'unit_no'           => $ecnReq->unit_no,
                'part_no'           => $ecnReq->part_no,
                'side'              => $ecnReq->side,
                'side_display'      => $ecnReq->side_display,
                'quantity'          => $remainingQty,
                'previous_state'    => 'PENDING',
                'new_state'         => 'PENDING',
                'event_type'        => 'ECN_QUANTITY_REDUCED',
            ]));
        } else {
            $action = 'deleted';
            $savedEcnData = [
                'project_id'        => $ecnReq->project_id,
                'ecn_requirement_id'=> $ecnReq->id,
                'ecn_number'        => $ecnReq->ecn_number,
                'jig_no'            => $ecnReq->jig_no,
                'unit_no'           => $ecnReq->unit_no,
                'part_no'           => $ecnReq->part_no,
                'side'              => $ecnReq->side,
                'side_display'      => $ecnReq->side_display,
            ];

            $ecnReq->forceDelete();

            broadcast(new EcnUpdated(array_merge($savedEcnData, [
                'quantity'          => 0,
                'previous_state'    => 'PENDING',
                'new_state'         => 'DELETED',
                'event_type'        => 'ECN_PART_DELETED',
            ])));
        }

        // Audit log
        SystemLogService::log([
            'severity'   => 'WARNING',
            'category'   => 'admin_actions',
            'module'     => 'PENDING_PART_DELETION',
            'user_id'    => $user?->id,
            'user_role'  => $user?->roles?->first()?->name ?? ($user?->role ?? 'ADMIN'),
            'message'    => "PENDING_ECN_PART_DELETED: ECN Part '{$ecnReq->part_no}' ({$ecnReq->ecn_number}) {$action} by {$user?->name}. Deleted qty: {$qtyToDelete}, Remaining qty: {$remainingQty}.",
            'details'    => [
                'event'             => 'PENDING_ECN_PART_DELETED',
                'action'            => $action,
                'bom_type'          => 'ECN',
                'ecn_requirement_id'=> $id,
                'ecn_number'        => $ecnReq->ecn_number,
                'project_id'        => $ecnReq->project_id,
                'project_code'      => $projectCode,
                'jig_no'            => $ecnReq->jig_no,
                'unit_no'           => $ecnReq->unit_no,
                'side'              => $ecnReq->side_display ?: $ecnReq->side,
                'part_no'           => $ecnReq->part_no,
                'deleted_quantity'  => $qtyToDelete,
                'remaining_quantity'=> $remainingQty,
                'reason'            => $reason,
                'deleted_by_user_id'=> $user?->id,
                'deleted_by_name'   => $user?->name,
                'timestamp'         => now()->toIso8601String(),
            ],
        ]);

        return [
            'success'            => true,
            'action'             => $action,
            'bom_type'           => 'ECN',
            'requirement_id'     => $id,
            'deleted_quantity'   => $qtyToDelete,
            'remaining_quantity' => $remainingQty,
            'bom_item_deleted'   => false,
            'message'            => $remainingQty > 0
                ? "Successfully reduced ECN quantity by {$qtyToDelete}. Remaining quantity: {$remainingQty}."
                : "Successfully removed pending ECN part completely (deleted quantity: {$qtyToDelete}).",
        ];
    }
}
