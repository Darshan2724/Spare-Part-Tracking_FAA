<?php

namespace App\Http\Controllers;

use App\Services\PendingPartDeletionService;
use App\Services\SystemLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class PendingPartDeletionController extends Controller
{
    protected PendingPartDeletionService $deletionService;

    public function __construct(PendingPartDeletionService $deletionService)
    {
        $this->deletionService = $deletionService;
    }

    /**
     * Authorize user: only ADMIN and MANAGER roles allowed.
     */
    protected function authorizeAdminOrManager(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if (method_exists($user, 'hasAnyRole')) {
            if (!$user->hasAnyRole(['ADMIN', 'MANAGER'])) {
                abort(403, 'Unauthorized. Only ADMIN and MANAGER roles can access Pending Part Deletion.');
            }
        } else {
            $role = $user->role ?? ($user->roles?->first()?->name ?? null);
            if (!in_array($role, ['ADMIN', 'MANAGER'], true)) {
                abort(403, 'Unauthorized. Only ADMIN and MANAGER roles can access Pending Part Deletion.');
            }
        }
    }

    /**
     * Get distinct projects with pending parts.
     */
    public function projects(Request $request): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $projects = $this->deletionService->getProjects();

        return response()->json([
            'success'  => true,
            'projects' => $projects,
        ]);
    }

    /**
     * Get distinct Jigs for selected project and BOM type.
     */
    public function jigs(Request $request): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'bom_type'   => ['required', 'string', Rule::in(PendingPartDeletionService::VALID_BOM_TYPES)],
        ]);

        $jigs = $this->deletionService->getJigs((int)$validated['project_id'], $validated['bom_type']);

        return response()->json([
            'success' => true,
            'jigs'    => $jigs,
        ]);
    }

    /**
     * Get distinct Units for selected project, BOM type, and Jig.
     */
    public function units(Request $request): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'bom_type'   => ['required', 'string', Rule::in(PendingPartDeletionService::VALID_BOM_TYPES)],
            'jig_no'     => 'required|string',
        ]);

        $units = $this->deletionService->getUnits(
            (int)$validated['project_id'],
            $validated['bom_type'],
            $validated['jig_no']
        );

        return response()->json([
            'success' => true,
            'units'   => $units,
        ]);
    }

    /**
     * Get distinct Sides for selected project, BOM type, Jig, and Unit.
     */
    public function sides(Request $request): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'bom_type'   => ['required', 'string', Rule::in(PendingPartDeletionService::VALID_BOM_TYPES)],
            'jig_no'     => 'required|string',
            'unit_no'    => 'required|string',
        ]);

        $sides = $this->deletionService->getSides(
            (int)$validated['project_id'],
            $validated['bom_type'],
            $validated['jig_no'],
            $validated['unit_no']
        );

        return response()->json([
            'success' => true,
            'sides'   => $sides,
        ]);
    }

    /**
     * Get eligible pending parts strictly matching hierarchy filters.
     */
    public function eligibleParts(Request $request): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'bom_type'   => ['required', 'string', Rule::in(PendingPartDeletionService::VALID_BOM_TYPES)],
            'jig_no'     => 'nullable|string',
            'unit_no'    => 'nullable|string',
            'side'       => 'nullable|string',
        ]);

        $parts = $this->deletionService->getEligiblePendingParts(
            (int)$validated['project_id'],
            $validated['bom_type'],
            $validated['jig_no'] ?? null,
            $validated['unit_no'] ?? null,
            $validated['side'] ?? null
        );

        return response()->json([
            'success'     => true,
            'parts'       => $parts,
            'total_count' => $parts->count(),
        ]);
    }

    /**
     * Delete or decrement pending part.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdminOrManager($request);

        $validated = $request->validate([
            'bom_type' => ['required', 'string', Rule::in(PendingPartDeletionService::VALID_BOM_TYPES)],
            'quantity' => 'nullable|integer|min:1',
            'reason'   => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->deletionService->deletePendingPart(
                $id,
                $validated['bom_type'],
                isset($validated['quantity']) ? (int)$validated['quantity'] : null,
                $validated['reason'] ?? null,
                $request->user()
            );

            return response()->json($result);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Pending part deletion failed: " . $e->getMessage(), [
                'id' => $id,
                'bom_type' => $validated['bom_type'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
