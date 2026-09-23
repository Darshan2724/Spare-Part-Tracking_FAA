<?php

namespace App\Http\Controllers;

use App\Services\AssemblyAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssemblyAllocationController extends Controller
{
    public function __construct(
        protected AssemblyAllocationService $allocationService
    ) {}

    /**
     * Ensure user has permission to view or manage assembly allocations.
     * Allowed roles: ADMIN, MANAGER, ASSEMBLY.
     */
    protected function authorizeAllocationAccess(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY'])) {
            abort(403, 'Unauthorized. Assembly allocation requires ADMIN, MANAGER, or ASSEMBLY role.');
        }
    }

    /**
     * Get allocation context for a part number (available stock and unit eligibility).
     */
    public function context(Request $request): JsonResponse
    {
        $this->authorizeAllocationAccess($request);

        $validated = $request->validate([
            'standard_part_no' => 'required|string',
            'bom_type' => 'required|string|in:BOP,STD,bop,std',
            'project_id' => 'nullable|integer',
        ]);

        try {
            $context = $this->allocationService->getPartAllocationContext(
                standardPartNo: $validated['standard_part_no'],
                bomType: $validated['bom_type'],
                projectId: !empty($validated['project_id']) ? (int) $validated['project_id'] : null
            );

            return response()->json([
                'success' => true,
                'data' => $context,
            ]);
        } catch (\Throwable $e) {
            Log::error('AssemblyAllocationController::context failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load allocation context: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Allocate available generic stock to a specific unit & side.
     */
    public function allocate(Request $request): JsonResponse
    {
        $this->authorizeAllocationAccess($request);

        $validated = $request->validate([
            'bom_item_id' => 'required|integer',
            'side' => 'required|string|in:COMMON,RH,LH,common,rh,lh',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:500',
        ]);

        try {
            $userId = $request->user()?->id;

            $allocation = $this->allocationService->allocate(
                bomItemId: (int) $validated['bom_item_id'],
                side: strtoupper($validated['side']),
                quantity: (int) $validated['quantity'],
                userId: $userId,
                remarks: $validated['remarks'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Successfully allocated %d pcs to Unit %s (%s)',
                    $allocation->allocated_quantity,
                    $allocation->bomItem?->unit_no,
                    $allocation->side
                ),
                'data' => $allocation,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AssemblyAllocationController::allocate failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to allocate parts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Release an active allocation back to available stock.
     */
    public function deallocate(Request $request): JsonResponse
    {
        $this->authorizeAllocationAccess($request);

        $validated = $request->validate([
            'allocation_id' => 'required|integer',
            'remarks' => 'nullable|string|max:500',
        ]);

        try {
            $userId = $request->user()?->id;

            $allocation = $this->allocationService->deallocate(
                allocationId: (int) $validated['allocation_id'],
                userId: $userId,
                remarks: $validated['remarks'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Allocation successfully released back to available pool.',
                'data' => $allocation,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AssemblyAllocationController::deallocate failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to release allocation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Adjust an existing allocation quantity.
     */
    public function adjust(Request $request): JsonResponse
    {
        $this->authorizeAllocationAccess($request);

        $validated = $request->validate([
            'allocation_id' => 'required|integer',
            'quantity' => 'required|integer|min:0',
            'remarks' => 'nullable|string|max:500',
        ]);

        try {
            $userId = $request->user()?->id;

            $allocation = $this->allocationService->adjustAllocation(
                allocationId: (int) $validated['allocation_id'],
                newQuantity: (int) $validated['quantity'],
                userId: $userId,
                remarks: $validated['remarks'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Allocation quantity adjusted successfully.',
                'data' => $allocation,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AssemblyAllocationController::adjust failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust allocation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get summary of all active allocations for a project.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeAllocationAccess($request);

        $validated = $request->validate([
            'project_id' => 'required|integer',
            'bom_type' => 'nullable|string|in:BOP,STD,bop,std',
        ]);

        try {
            $summary = $this->allocationService->getProjectAllocationSummary(
                projectId: (int) $validated['project_id'],
                bomType: $validated['bom_type'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Throwable $e) {
            Log::error('AssemblyAllocationController::summary failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load allocation summary: ' . $e->getMessage(),
            ], 500);
        }
    }
}
