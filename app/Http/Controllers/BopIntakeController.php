<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\BopIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BopIntakeController extends Controller
{
    public function __construct(
        protected BopIntakeService $bopIntakeService
    ) {}

    /**
     * Get aggregated BOP parts across projects/units.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'project_id' => $request->query('project_id') ? (int) $request->query('project_id') : null,
                'search' => $request->query('search') ? (string) $request->query('search') : null,
                'status' => $request->query('status') ? (string) $request->query('status') : 'all',
            ];
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 500);

            $result = $this->bopIntakeService->getAggregatedBopParts($filters, $page, $perPage);
            $projects = Project::select('id', 'name', 'project_code')->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'parts' => $result['data'],
                    'summary' => $result['summary'],
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total_pages' => $result['total_pages'],
                    'projects' => $projects,
                ],
                'summary' => $result['summary'],
                'total' => $result['total'],
            ]);
        } catch (\Throwable $e) {
            Log::error('BopIntakeController::index failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch BOP parts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unit/jig/project breakdown for an aggregated BOP part.
     */
    public function breakdown(Request $request, string $partNo): JsonResponse
    {
        try {
            $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

            $breakdown = $this->bopIntakeService->getPartBreakdown($partNo, $projectId);

            return response()->json([
                'success' => true,
                'data' => [
                    'breakdown' => $breakdown,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('BopIntakeController::breakdown failed: ' . $e->getMessage(), [
                'part_no' => $partNo,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch BOP breakdown: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move quantity between BOP workflow departments (Pending -> Store -> Assembly -> Completed).
     */
    public function transition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'standard_part_no' => 'required|string',
            'from_state' => 'required|string|in:pending,store,assembly',
            'to_state' => 'required|string|in:store,assembly,completed',
            'quantity' => 'required|integer|min:1',
            'project_id' => 'nullable|integer',
            'remarks' => 'nullable|string',
        ]);

        try {
            $userId = $request->user()?->id ?? 1;

            $result = $this->bopIntakeService->transitionQuantity(
                partNo: $validated['standard_part_no'],
                fromDept: $validated['from_state'],
                toDept: $validated['to_state'],
                quantity: (int) $validated['quantity'],
                projectId: !empty($validated['project_id']) ? (int) $validated['project_id'] : null,
                remarks: $validated['remarks'] ?? null,
                userId: $userId
            );

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Successfully transitioned %d unit(s) of %s from %s to %s',
                    $result['quantity_transitioned'] ?? $validated['quantity'],
                    $validated['standard_part_no'],
                    strtoupper($validated['from_state']),
                    strtoupper($validated['to_state'])
                ),
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BopIntakeController::transition failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to transition BOP parts: ' . $e->getMessage(),
            ], 500);
        }
    }
}
