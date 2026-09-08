<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\StdIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StdIntakeController extends Controller
{
    public function __construct(
        protected StdIntakeService $stdIntakeService
    ) {}

    /**
     * Get aggregated STD parts across projects/units.
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

            $result = $this->stdIntakeService->getAggregatedStdParts($filters, $page, $perPage);
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
            Log::error('StdIntakeController::index failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch STD parts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unit/jig/project breakdown for an aggregated STD part.
     */
    public function breakdown(Request $request, string $partNo): JsonResponse
    {
        try {
            $projectId = $request->query('project_id') ? (int) $request->query('project_id') : null;

            $breakdown = $this->stdIntakeService->getPartBreakdown($partNo, $projectId);

            return response()->json([
                'success' => true,
                'data' => [
                    'breakdown' => $breakdown,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('StdIntakeController::breakdown failed: ' . $e->getMessage(), [
                'part_no' => $partNo,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch STD breakdown: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move quantity between STD workflow departments.
     */
    public function transition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'standard_part_no' => 'required|string',
            'from_state' => 'required|string|in:pending,store,qc,rework,paint,assembly',
            'to_state' => 'required|string|in:store,qc,rework,paint,assembly,completed',
            'quantity' => 'required|integer|min:1',
            'project_id' => 'nullable|integer',
            'destination' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        try {
            $userId = $request->user()?->id ?? 1;

            $options = [
                'project_id' => !empty($validated['project_id']) ? (int) $validated['project_id'] : null,
                'destination' => $validated['destination'] ?? 'ASSEMBLY',
                'remarks' => $validated['remarks'] ?? null,
                'user_id' => $userId,
            ];

            $result = $this->stdIntakeService->transitionQuantity(
                partNo: $validated['standard_part_no'],
                fromDept: $validated['from_state'],
                toDept: $validated['to_state'],
                quantity: (int) $validated['quantity'],
                options: $options
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
            Log::error('StdIntakeController::transition failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to transition STD parts: ' . $e->getMessage(),
            ], 500);
        }
    }
}
