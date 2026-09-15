<?php

namespace App\Services;

use App\Models\AssemblyAllocation;
use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\WorkflowEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssemblyAllocationService
{
    /**
     * Get allocation context for a standard part number across units in a project.
     *
     * @param string $standardPartNo
     * @param string $bomType BOP|STD
     * @param int|null $projectId
     * @return array
     */
    public function getPartAllocationContext(string $standardPartNo, string $bomType, ?int $projectId = null): array
    {
        $bomType = strtoupper(trim($bomType));
        if (!in_array($bomType, ['BOP', 'STD'], true)) {
            throw ValidationException::withMessages([
                'bom_type' => ['Invalid BOM type. Only BOP and STD are supported.'],
            ]);
        }

        $standardPartNo = trim($standardPartNo);

        // 1. Fetch matching BOM items
        $query = BomItem::query()
            ->with(['requirements', 'project', 'supplier'])
            ->where('part_type', $bomType)
            ->where('standard_part_no', $standardPartNo);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $items = $query->orderBy('project_id')
            ->orderBy('jig_no')
            ->orderBy('unit_no')
            ->get();

        if ($items->isEmpty()) {
            return [
                'part_summary' => [
                    'standard_part_no' => $standardPartNo,
                    'bom_type' => $bomType,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_assembly_ready' => 0,
                    'total_allocated' => 0,
                    'unallocated_assembly_ready' => 0,
                    'total_assembly_completed' => 0,
                    'total_unfulfilled' => 0,
                ],
                'units' => [],
            ];
        }

        $bomItemIds = $items->pluck('id')->toArray();

        // 2. Batched queries
        $receipts = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get();

        $assemblyRecords = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get();

        $activeAllocations = AssemblyAllocation::query()
            ->with('allocator:id,name')
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'active')
            ->get();

        // If STD, fetch paint records and direct QC inspections
        $paintRecords = collect();
        $qcInspections = collect();
        if ($bomType === 'STD') {
            $paintRecords = PaintRecord::query()
                ->whereIn('bom_item_id', $bomItemIds)
                ->whereIn('status', ['completed', 'assembled'])
                ->get();

            $qcInspections = QcInspection::query()
                ->whereIn('bom_item_id', $bomItemIds)
                ->where('destination', 'ASSEMBLY')
                ->where('approved_quantity', '>', 0)
                ->get();
        }

        // 3. Compute total assembly ready stock across all matching items
        $totalAssemblyReady = 0;
        if ($bomType === 'BOP') {
            $totalAssemblyReady = (int) $receipts->where('status', 'in_assembly')->sum('received_quantity');
        } else {
            // Direct in_assembly
            $inAsmQty = (int) $receipts->where('status', 'in_assembly')->sum('received_quantity');

            // Paint completed available
            $paintAvail = 0;
            foreach ($paintRecords as $pnt) {
                $assembled = (int) $assemblyRecords->where('paint_record_id', $pnt->id)->sum('quantity');
                $paintAvail += max(0, (int)$pnt->quantity - $assembled);
            }

            // Direct QC available
            $qcAvail = 0;
            foreach ($qcInspections as $insp) {
                $assembled = (int) $assemblyRecords->where('qc_inspection_id', $insp->id)->sum('quantity');
                $qcAvail += max(0, (int)$insp->approved_quantity - $assembled);
            }

            $totalAssemblyReady = $inAsmQty + $paintAvail + $qcAvail;
        }

        $totalActiveAllocated = (int) $activeAllocations->sum('allocated_quantity');
        $unallocatedAssemblyReady = max(0, $totalAssemblyReady - $totalActiveAllocated);

        $totalRequired = 0;
        $totalReceived = 0;
        $totalCompleted = 0;
        $totalUnfulfilled = 0;

        $units = [];

        foreach ($items as $item) {
            foreach ($item->requirements as $req) {
                $side = $req->side;
                $reqQty = (int) $req->required_quantity;

                $itemRecs = $receipts->where('bom_item_id', $item->id)->where('side', $side);
                $itemAsm = $assemblyRecords->where('bom_item_id', $item->id)->where('side', $side);

                $recQty = (int) $itemRecs->sum('received_quantity');
                $effectiveRec = min($recQty, $reqQty);

                $completedQty = (int) $itemAsm->sum('quantity');
                $remainingNeed = max(0, $reqQty - $completedQty);

                $activeAlloc = $activeAllocations->first(function ($alloc) use ($item, $side) {
                    return $alloc->bom_item_id === $item->id && $alloc->side === $side;
                });

                $allocQty = $activeAlloc ? (int) $activeAlloc->allocated_quantity : 0;
                $maxAdditional = max(0, min($unallocatedAssemblyReady, $remainingNeed - $allocQty));
                $maxTotalAllocatable = min($unallocatedAssemblyReady + $allocQty, $remainingNeed);

                $totalRequired += $reqQty;
                $totalReceived += $effectiveRec;
                $totalCompleted += $completedQty;
                $totalUnfulfilled += $remainingNeed;

                $units[] = [
                    'bom_item_id' => $item->id,
                    'project_id' => $item->project_id,
                    'project_code' => $item->project?->project_code ?: $item->project?->name ?: ('Project #' . $item->project_id),
                    'jig_no' => $item->jig_no,
                    'unit_no' => $item->unit_no,
                    'item_no' => $item->item_no,
                    'side' => $side,
                    'required_quantity' => $reqQty,
                    'received_quantity' => $recQty,
                    'assembly_completed_quantity' => $completedQty,
                    'allocated_quantity' => $allocQty,
                    'remaining_need' => $remainingNeed,
                    'max_additional_allocatable' => $maxAdditional,
                    'max_total_allocatable' => $maxTotalAllocatable,
                    'active_allocation_id' => $activeAlloc?->id,
                    'allocated_by_name' => $activeAlloc?->allocator?->name,
                    'allocated_at' => $activeAlloc?->created_at?->toISOString(),
                    'remarks' => $activeAlloc?->remarks,
                ];
            }
        }

        return [
            'part_summary' => [
                'standard_part_no' => $standardPartNo,
                'bom_type' => $bomType,
                'total_required' => $totalRequired,
                'total_received' => $totalReceived,
                'total_assembly_ready' => $totalAssemblyReady,
                'total_allocated' => $totalActiveAllocated,
                'unallocated_assembly_ready' => $unallocatedAssemblyReady,
                'total_assembly_completed' => $totalCompleted,
                'total_unfulfilled' => $totalUnfulfilled,
            ],
            'units' => $units,
        ];
    }

    /**
     * Allocate generic stock to a specific unit & side.
     *
     * @param int $bomItemId
     * @param string $side
     * @param int $quantity
     * @param int|null $userId
     * @param string|null $remarks
     * @return AssemblyAllocation
     */
    public function allocate(
        int $bomItemId,
        string $side,
        int $quantity,
        ?int $userId = null,
        ?string $remarks = null
    ): AssemblyAllocation {
        return DB::transaction(function () use ($bomItemId, $side, $quantity, $userId, $remarks) {
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Allocated quantity must be greater than zero.'],
                ]);
            }

            $side = strtoupper(trim($side));
            if (!in_array($side, ['COMMON', 'RH', 'LH'], true)) {
                throw ValidationException::withMessages([
                    'side' => ['Invalid side specified. Must be COMMON, RH, or LH.'],
                ]);
            }

            $bomItem = BomItem::with('project')->where('id', $bomItemId)->lockForUpdate()->first();
            if (!$bomItem) {
                throw ValidationException::withMessages([
                    'bom_item_id' => ['BOM item not found.'],
                ]);
            }

            if (!in_array($bomItem->part_type, ['BOP', 'STD'], true)) {
                throw ValidationException::withMessages([
                    'part_type' => ['Assembly allocation is only permitted for BOP and STD parts.'],
                ]);
            }

            $req = BomRequirement::where('bom_item_id', $bomItemId)->where('side', $side)->lockForUpdate()->first();
            if (!$req) {
                throw ValidationException::withMessages([
                    'side' => ["No BOM requirement found for side {$side} on Unit {$bomItem->unit_no}."],
                ]);
            }

            $completedQty = (int) AssemblyRecord::where('bom_item_id', $bomItemId)
                ->where('side', $side)
                ->where('status', 'completed')
                ->sum('quantity');

            $remainingNeed = max(0, (int) $req->required_quantity - $completedQty);
            if ($quantity > $remainingNeed) {
                throw ValidationException::withMessages([
                    'quantity' => ["Cannot allocate {$quantity} pcs. Remaining need for Unit {$bomItem->unit_no} ({$side}) is only {$remainingNeed} pcs."],
                ]);
            }

            $existingAllocation = AssemblyAllocation::where('bom_item_id', $bomItemId)
                ->where('side', $side)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            // Calculate total assembly ready stock across all units sharing this standard_part_no in this project
            $siblingItemIds = BomItem::where('project_id', $bomItem->project_id)
                ->where('standard_part_no', $bomItem->standard_part_no)
                ->where('part_type', $bomItem->part_type)
                ->pluck('id')
                ->toArray();

            $totalAssemblyReady = $this->calculateAssemblyReadyStock($bomItem->part_type, $siblingItemIds);

            $otherAllocationsQuery = AssemblyAllocation::whereIn('bom_item_id', $siblingItemIds)
                ->where('status', 'active');
            if ($existingAllocation) {
                $otherAllocationsQuery->where('id', '!=', $existingAllocation->id);
            }
            $otherAllocatedQty = (int) $otherAllocationsQuery->sum('allocated_quantity');

            $availableForThisUnit = max(0, $totalAssemblyReady - $otherAllocatedQty);
            if ($quantity > $availableForThisUnit) {
                throw ValidationException::withMessages([
                    'quantity' => ["Cannot allocate {$quantity} pcs. Only {$availableForThisUnit} pcs are available for allocation across all units."],
                ]);
            }

            if ($existingAllocation) {
                $existingAllocation->update([
                    'allocated_quantity' => $quantity,
                    'allocated_by' => $userId,
                    'remarks' => $remarks ?: $existingAllocation->remarks,
                ]);
                $allocation = $existingAllocation;
            } else {
                $allocation = AssemblyAllocation::create([
                    'project_id' => $bomItem->project_id,
                    'bom_item_id' => $bomItem->id,
                    'side' => $side,
                    'bom_type' => $bomItem->part_type,
                    'allocated_quantity' => $quantity,
                    'status' => 'active',
                    'allocated_by' => $userId,
                    'remarks' => $remarks,
                ]);
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItem->id,
                'project_id' => $bomItem->project_id,
                'user_id' => $userId,
                'event_type' => 'assembly_allocated',
                'side' => $side,
                'quantity' => $quantity,
                'previous_state' => 'assembly',
                'new_state' => 'assembly_allocated',
                'remarks' => "Assembly Allocation: {$quantity} pcs of {$bomItem->standard_part_no} allocated to {$bomItem->jig_no}/Unit {$bomItem->unit_no}/{$side}." . ($remarks ? " Remarks: {$remarks}" : ''),
            ]);

            SystemLogService::log([
                'severity' => 'INFO',
                'category' => 'workflow',
                'module' => 'ASSEMBLY_ALLOCATION',
                'user_id' => $userId,
                'message' => "Allocated {$quantity} pcs of {$bomItem->standard_part_no} to {$bomItem->jig_no}/Unit {$bomItem->unit_no}/{$side}",
                'details' => [
                    'standard_part_no' => $bomItem->standard_part_no,
                    'bom_type' => $bomItem->part_type,
                    'bom_item_id' => $bomItem->id,
                    'jig_no' => $bomItem->jig_no,
                    'unit_no' => $bomItem->unit_no,
                    'side' => $side,
                    'quantity' => $quantity,
                    'allocation_id' => $allocation->id,
                ],
            ]);

            return $allocation->fresh(['project', 'bomItem', 'allocator']);
        });
    }

    /**
     * Release an active allocation back to the available pool.
     *
     * @param int $allocationId
     * @param int|null $userId
     * @param string|null $remarks
     * @return AssemblyAllocation
     */
    public function deallocate(int $allocationId, ?int $userId = null, ?string $remarks = null): AssemblyAllocation
    {
        return DB::transaction(function () use ($allocationId, $userId, $remarks) {
            $allocation = AssemblyAllocation::with(['bomItem', 'project'])
                ->where('id', $allocationId)
                ->lockForUpdate()
                ->first();

            if (!$allocation) {
                throw ValidationException::withMessages([
                    'allocation_id' => ['Allocation record not found.'],
                ]);
            }

            if ($allocation->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => ["Allocation is currently '{$allocation->status}' and cannot be released."],
                ]);
            }

            $allocation->update([
                'status' => 'released',
                'remarks' => $remarks ?: $allocation->remarks,
            ]);

            $bomItem = $allocation->bomItem;

            WorkflowEvent::create([
                'bom_item_id' => $allocation->bom_item_id,
                'project_id' => $allocation->project_id,
                'user_id' => $userId,
                'event_type' => 'assembly_deallocated',
                'side' => $allocation->side,
                'quantity' => $allocation->allocated_quantity,
                'previous_state' => 'assembly_allocated',
                'new_state' => 'assembly',
                'remarks' => "Assembly Allocation Released: {$allocation->allocated_quantity} pcs of {$bomItem?->standard_part_no} released from {$bomItem?->jig_no}/Unit {$bomItem?->unit_no}/{$allocation->side}." . ($remarks ? " Reason: {$remarks}" : ''),
            ]);

            SystemLogService::log([
                'severity' => 'INFO',
                'category' => 'workflow',
                'module' => 'ASSEMBLY_ALLOCATION',
                'user_id' => $userId,
                'message' => "Released allocation of {$allocation->allocated_quantity} pcs for {$bomItem?->standard_part_no} on Unit {$bomItem?->unit_no}/{$allocation->side}",
                'details' => [
                    'allocation_id' => $allocation->id,
                    'standard_part_no' => $bomItem?->standard_part_no,
                    'bom_item_id' => $allocation->bom_item_id,
                    'side' => $allocation->side,
                    'quantity' => $allocation->allocated_quantity,
                ],
            ]);

            return $allocation->fresh(['project', 'bomItem', 'allocator']);
        });
    }

    /**
     * Adjust existing allocation quantity.
     *
     * @param int $allocationId
     * @param int $newQuantity
     * @param int|null $userId
     * @param string|null $remarks
     * @return AssemblyAllocation
     */
    public function adjustAllocation(int $allocationId, int $newQuantity, ?int $userId = null, ?string $remarks = null): AssemblyAllocation
    {
        if ($newQuantity <= 0) {
            return $this->deallocate($allocationId, $userId, $remarks);
        }

        $allocation = AssemblyAllocation::findOrFail($allocationId);
        return $this->allocate($allocation->bom_item_id, $allocation->side, $newQuantity, $userId, $remarks);
    }

    /**
     * Automatically consume or decrement active allocation when assembly is completed.
     *
     * @param int $bomItemId
     * @param string $side
     * @param int $completedQuantity
     * @return void
     */
    public function consumeAllocationOnCompletion(int $bomItemId, string $side, int $completedQuantity): void
    {
        if ($completedQuantity <= 0) {
            return;
        }

        $alloc = AssemblyAllocation::where('bom_item_id', $bomItemId)
            ->where('side', $side)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (!$alloc) {
            return;
        }

        if ($alloc->allocated_quantity <= $completedQuantity) {
            $alloc->update(['status' => 'consumed']);
        } else {
            $alloc->decrement('allocated_quantity', $completedQuantity);
        }
    }

    /**
     * Get summary of all allocations for a project.
     *
     * @param int $projectId
     * @param string|null $bomType
     * @return array
     */
    public function getProjectAllocationSummary(int $projectId, ?string $bomType = null): array
    {
        $query = AssemblyAllocation::with(['bomItem', 'allocator:id,name'])
            ->where('project_id', $projectId)
            ->where('status', 'active');

        if ($bomType) {
            $query->where('bom_type', strtoupper($bomType));
        }

        $allocations = $query->get();

        return [
            'total_active_allocations' => $allocations->count(),
            'total_allocated_quantity' => (int) $allocations->sum('allocated_quantity'),
            'allocations' => $allocations->map(function ($alloc) {
                return [
                    'id' => $alloc->id,
                    'bom_item_id' => $alloc->bom_item_id,
                    'standard_part_no' => $alloc->bomItem?->standard_part_no,
                    'jig_no' => $alloc->bomItem?->jig_no,
                    'unit_no' => $alloc->bomItem?->unit_no,
                    'side' => $alloc->side,
                    'bom_type' => $alloc->bom_type,
                    'allocated_quantity' => $alloc->allocated_quantity,
                    'allocated_by' => $alloc->allocator?->name,
                    'created_at' => $alloc->created_at?->toISOString(),
                    'remarks' => $alloc->remarks,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Calculate assembly-ready stock for a set of BOM item IDs.
     *
     * @param string $bomType
     * @param array $bomItemIds
     * @return int
     */
    protected function calculateAssemblyReadyStock(string $bomType, array $bomItemIds): int
    {
        if (empty($bomItemIds)) {
            return 0;
        }

        if ($bomType === 'BOP') {
            return (int) ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                ->where('status', 'in_assembly')
                ->sum('received_quantity');
        }

        // STD part type
        $inAssemblyReceipts = (int) ReceiptItem::whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'in_assembly')
            ->sum('received_quantity');

        $paintRecords = PaintRecord::whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', ['completed', 'assembled'])
            ->get();

        $assemblyRecords = AssemblyRecord::whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get();

        $paintAvailable = 0;
        foreach ($paintRecords as $pnt) {
            $assembled = (int) $assemblyRecords->where('paint_record_id', $pnt->id)->sum('quantity');
            $paintAvailable += max(0, (int) $pnt->quantity - $assembled);
        }

        $qcInspections = QcInspection::whereIn('bom_item_id', $bomItemIds)
            ->where('destination', 'ASSEMBLY')
            ->where('approved_quantity', '>', 0)
            ->get();

        $qcAvailable = 0;
        foreach ($qcInspections as $insp) {
            $assembled = (int) $assemblyRecords->where('qc_inspection_id', $insp->id)->sum('quantity');
            $qcAvailable += max(0, (int) $insp->approved_quantity - $assembled);
        }

        return $inAssemblyReceipts + $paintAvailable + $qcAvailable;
    }
}
