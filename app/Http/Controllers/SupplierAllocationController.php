<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\Supplier;
use App\Models\SupplierAssignment;
use App\Models\SupplierAssignmentHistory;
use App\Events\SupplierAssignmentUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierAllocationController extends Controller
{
    /**
     * Get Project -> Jig -> Unit hierarchy for Supplier Allocation.
     */
    public function hierarchy(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $projects = Project::orderBy('name')
            ->select('id', 'name', 'project_code', 'status')
            ->get();

        $projectId = $request->query('project_id');
        $hierarchy = [];

        if ($projectId) {
            // Fetch distinct Jigs and Units from BOM items for this project
            $bomUnits = BomItem::where('project_id', $projectId)
                ->select(DB::raw("TRIM(jig_no) as jig_no"), DB::raw("TRIM(unit_no) as unit_no"))
                ->distinct()
                ->orderBy('jig_no')
                ->orderBy('unit_no')
                ->get();

            // Fetch all active assignments for this project
            $activeAssignments = SupplierAssignment::with('supplier')
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->get()
                ->groupBy(function ($item) {
                    return trim((string)$item->jig_no) . '__' . trim((string)$item->unit_no);
                });

            // Group by Jig
            $jigGroups = $bomUnits->groupBy('jig_no');
            $jigList = [];

            foreach ($jigGroups as $jigNo => $units) {
                $unitList = [];
                $jigAssignedCount = 0;
                $jigTotalSlots = count($units) * 3; // 3 categories (BASE, WELDMENT, CHILD_PART)

                foreach ($units as $u) {
                    $key = trim((string)$jigNo) . '__' . trim((string)$u->unit_no);
                    $unitAssignments = $activeAssignments->get($key, collect());

                    $categories = [
                        'BASE' => null,
                        'WELDMENT' => null,
                        'CHILD_PART' => null,
                    ];

                    foreach ($unitAssignments as $assign) {
                        if (array_key_exists($assign->category, $categories)) {
                            $categories[$assign->category] = [
                                'id' => $assign->id,
                                'supplier_id' => $assign->supplier_id,
                                'supplier_name' => $assign->supplier?->name ?? 'Unknown',
                                'supplier_code' => $assign->supplier?->code,
                                'assignment_date' => $assign->assignment_date?->format('Y-m-d'),
                                'status' => $assign->status,
                            ];
                            $jigAssignedCount++;
                        }
                    }

                    $unitList[] = [
                        'unit_no' => $u->unit_no,
                        'categories' => $categories,
                        'is_fully_assigned' => !is_null($categories['BASE']) && !is_null($categories['WELDMENT']) && !is_null($categories['CHILD_PART']),
                        'assigned_count' => ($categories['BASE'] ? 1 : 0) + ($categories['WELDMENT'] ? 1 : 0) + ($categories['CHILD_PART'] ? 1 : 0),
                    ];
                }

                $jigList[] = [
                    'jig_no' => $jigNo,
                    'total_units' => count($unitList),
                    'assigned_slots' => $jigAssignedCount,
                    'total_slots' => $jigTotalSlots,
                    'allocation_pct' => $jigTotalSlots > 0 ? round(($jigAssignedCount / $jigTotalSlots) * 100) : 0,
                    'units' => $unitList,
                ];
            }

            $hierarchy = [
                'project_id' => (int) $projectId,
                'jigs' => $jigList,
            ];
        }

        return response()->json([
            'projects' => $projects,
            'hierarchy' => $hierarchy,
        ]);
    }

    /**
     * Get active assignments for a specific project, jig, and unit.
     */
    public function getAssignments(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'jig_no' => ['nullable', 'string'],
            'unit_no' => ['nullable', 'string'],
        ]);

        $query = SupplierAssignment::with('supplier')
            ->where('project_id', $request->input('project_id'))
            ->where('status', 'active');

        if ($request->filled('jig_no')) {
            $query->where('jig_no', $request->input('jig_no'));
        }
        if ($request->filled('unit_no')) {
            $query->where('unit_no', $request->input('unit_no'));
        }

        $assignments = $query->get();

        return response()->json([
            'success' => true,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Assign a supplier to a Project -> Jig -> Unit -> Category.
     * Enforces the 7-day restricted date window (today +/- 3 calendar days).
     */
    public function saveAssignment(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');

        $this->validateAssignmentRequest($request);

        $projectId = (int) $request->input('project_id');
        $jigNo = trim($request->input('jig_no'));
        $unitNo = trim($request->input('unit_no'));
        $category = strtoupper(trim($request->input('category')));
        $supplierId = (int) $request->input('supplier_id');
        $assignmentDate = $request->input('assignment_date');
        $userId = $request->user()?->id;

        $newAssignment = DB::transaction(function () use ($projectId, $jigNo, $unitNo, $category, $supplierId, $assignmentDate, $userId) {
            // Find existing active assignment
            $existing = SupplierAssignment::where('project_id', $projectId)
                ->where('jig_no', $jigNo)
                ->where('unit_no', $unitNo)
                ->where('category', $category)
                ->where('status', 'active')
                ->first();

            $prevSupplierId = null;
            $prevDate = null;
            $action = 'created';

            if ($existing) {
                $prevSupplierId = $existing->supplier_id;
                $prevDate = $existing->assignment_date?->format('Y-m-d');
                $action = 'updated';

                // Supersede existing
                $existing->update([
                    'status' => 'superseded',
                    'updated_by' => $userId,
                ]);
            }

            // Create new active assignment
            $assignment = SupplierAssignment::create([
                'project_id' => $projectId,
                'jig_no' => $jigNo,
                'unit_no' => $unitNo,
                'category' => $category,
                'supplier_id' => $supplierId,
                'assignment_date' => $assignmentDate,
                'status' => 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Log in history audit table
            SupplierAssignmentHistory::create([
                'supplier_assignment_id' => $assignment->id,
                'project_id' => $projectId,
                'jig_no' => $jigNo,
                'unit_no' => $unitNo,
                'category' => $category,
                'previous_supplier_id' => $prevSupplierId,
                'new_supplier_id' => $supplierId,
                'previous_date' => $prevDate,
                'new_date' => $assignmentDate,
                'action' => $action,
                'changed_by' => $userId,
                'created_at' => now(),
            ]);

            return $assignment;
        });

        $newAssignment->load('supplier');

        // Broadcast realtime update event
        broadcast(new SupplierAssignmentUpdated($newAssignment, 'assigned'))->toOthers();

        return response()->json([
            'success' => true,
            'message' => "Supplier successfully assigned to {$category} for Unit {$unitNo}.",
            'assignment' => $newAssignment,
        ]);
    }

    /**
     * Bulk assign all 3 categories (BASE, WELDMENT, CHILD_PART) for a Unit.
     */
    public function bulkAssign(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');

        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'jig_no' => ['required', 'string', 'max:255'],
            'unit_no' => ['required', 'string', 'max:255'],
            'categories' => ['required', 'array'],
            'categories.*.category' => ['required', 'in:BASE,WELDMENT,CHILD_PART'],
            'categories.*.supplier_id' => ['required', 'exists:suppliers,id'],
            'categories.*.assignment_date' => ['required', 'date'],
        ]);

        $today = Carbon::today();
        $minDate = $today->copy()->subDays(3)->format('Y-m-d');
        $maxDate = $today->copy()->addDays(3)->format('Y-m-d');

        foreach ($request->input('categories') as $cat) {
            $catDate = Carbon::parse($cat['assignment_date'])->format('Y-m-d');
            if ($catDate < $minDate || $catDate > $maxDate) {
                return response()->json([
                    'success' => false,
                    'message' => "Assignment date must be within 3 days before or after today ({$minDate} to {$maxDate}).",
                ], 422);
            }
        }

        $projectId = (int) $request->input('project_id');
        $jigNo = trim($request->input('jig_no'));
        $unitNo = trim($request->input('unit_no'));
        $userId = $request->user()?->id;

        $results = DB::transaction(function () use ($projectId, $jigNo, $unitNo, $request, $userId) {
            $saved = [];

            foreach ($request->input('categories') as $cat) {
                $category = strtoupper(trim($cat['category']));
                $supplierId = (int) $cat['supplier_id'];
                $assignmentDate = $cat['assignment_date'];

                $existing = SupplierAssignment::where('project_id', $projectId)
                    ->where('jig_no', $jigNo)
                    ->where('unit_no', $unitNo)
                    ->where('category', $category)
                    ->where('status', 'active')
                    ->first();

                $prevSupplierId = null;
                $prevDate = null;
                $action = 'created';

                if ($existing) {
                    $prevSupplierId = $existing->supplier_id;
                    $prevDate = $existing->assignment_date?->format('Y-m-d');
                    $action = 'updated';

                    $existing->update([
                        'status' => 'superseded',
                        'updated_by' => $userId,
                    ]);
                }

                $assignment = SupplierAssignment::create([
                    'project_id' => $projectId,
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                    'category' => $category,
                    'supplier_id' => $supplierId,
                    'assignment_date' => $assignmentDate,
                    'status' => 'active',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                SupplierAssignmentHistory::create([
                    'supplier_assignment_id' => $assignment->id,
                    'project_id' => $projectId,
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                    'category' => $category,
                    'previous_supplier_id' => $prevSupplierId,
                    'new_supplier_id' => $supplierId,
                    'previous_date' => $prevDate,
                    'new_date' => $assignmentDate,
                    'action' => $action,
                    'changed_by' => $userId,
                    'created_at' => now(),
                ]);

                $assignment->load('supplier');
                $saved[] = $assignment;

                broadcast(new SupplierAssignmentUpdated($assignment, $action))->toOthers();
            }

            return $saved;
        });

        return response()->json([
            'success' => true,
            'message' => "Successfully updated supplier allocations for Unit {$unitNo}.",
            'assignments' => $results,
        ]);
    }

    /**
     * Multi-Unit Assignment: Assign suppliers/dates to multiple units in a single atomic transaction.
     */
    public function multiUnitAssign(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');

        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'jig_no' => ['required', 'string', 'max:255'],
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_no' => ['required', 'string', 'max:255'],
            'units.*.categories' => ['required', 'array', 'min:1'],
            'units.*.categories.*.category' => ['required', 'in:BASE,WELDMENT,CHILD_PART'],
            'units.*.categories.*.supplier_id' => ['required', 'exists:suppliers,id'],
            'units.*.categories.*.assignment_date' => ['required', 'date'],
        ]);

        $today = Carbon::today();
        $minDate = $today->copy()->subDays(3)->format('Y-m-d');
        $maxDate = $today->copy()->addDays(3)->format('Y-m-d');

        // Check date window and collect supplier IDs for active validation
        $supplierIds = [];
        foreach ($request->input('units') as $unitItem) {
            foreach ($unitItem['categories'] as $cat) {
                $catDate = Carbon::parse($cat['assignment_date'])->format('Y-m-d');
                if ($catDate < $minDate || $catDate > $maxDate) {
                    return response()->json([
                        'success' => false,
                        'message' => "Assignment date must be within 3 days before or after today ({$minDate} to {$maxDate}).",
                    ], 422);
                }
                $supplierIds[] = (int) $cat['supplier_id'];
            }
        }

        // Validate all suppliers are active
        $inactiveSuppliers = Supplier::whereIn('id', array_unique($supplierIds))
            ->where('is_active', false)
            ->pluck('name')
            ->toArray();

        if (count($inactiveSuppliers) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot assign inactive supplier(s): ' . implode(', ', $inactiveSuppliers),
            ], 422);
        }

        $projectId = (int) $request->input('project_id');
        $jigNo = trim($request->input('jig_no'));
        $userId = $request->user()?->id;

        $results = DB::transaction(function () use ($projectId, $jigNo, $request, $userId) {
            $saved = [];

            foreach ($request->input('units') as $unitItem) {
                $unitNo = trim($unitItem['unit_no']);

                foreach ($unitItem['categories'] as $cat) {
                    $category = strtoupper(trim($cat['category']));
                    $supplierId = (int) $cat['supplier_id'];
                    $assignmentDate = $cat['assignment_date'];

                    $existing = SupplierAssignment::where('project_id', $projectId)
                        ->where('jig_no', $jigNo)
                        ->where('unit_no', $unitNo)
                        ->where('category', $category)
                        ->where('status', 'active')
                        ->first();

                    $prevSupplierId = null;
                    $prevDate = null;
                    $action = 'created';

                    if ($existing) {
                        $prevSupplierId = $existing->supplier_id;
                        $prevDate = $existing->assignment_date?->format('Y-m-d');
                        $action = 'updated';

                        $existing->update([
                            'status' => 'superseded',
                            'updated_by' => $userId,
                        ]);
                    }

                    $assignment = SupplierAssignment::create([
                        'project_id' => $projectId,
                        'jig_no' => $jigNo,
                        'unit_no' => $unitNo,
                        'category' => $category,
                        'supplier_id' => $supplierId,
                        'assignment_date' => $assignmentDate,
                        'status' => 'active',
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);

                    SupplierAssignmentHistory::create([
                        'supplier_assignment_id' => $assignment->id,
                        'project_id' => $projectId,
                        'jig_no' => $jigNo,
                        'unit_no' => $unitNo,
                        'category' => $category,
                        'previous_supplier_id' => $prevSupplierId,
                        'new_supplier_id' => $supplierId,
                        'previous_date' => $prevDate,
                        'new_date' => $assignmentDate,
                        'action' => $action,
                        'changed_by' => $userId,
                        'created_at' => now(),
                    ]);

                    $assignment->load('supplier');
                    $saved[] = $assignment;

                    broadcast(new SupplierAssignmentUpdated($assignment, $action))->toOthers();
                }
            }

            return $saved;
        });

        $unitCount = count($request->input('units'));

        return response()->json([
            'success' => true,
            'message' => "Successfully updated supplier allocations for {$unitCount} unit(s).",
            'unit_count' => $unitCount,
            'assignments_count' => count($results),
            'assignments' => $results,
        ]);
    }

    /**
     * Remove an existing active assignment.
     */
    public function removeAssignment(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403, 'Unauthorized. Purchase operational permission required.');

        $assignment = SupplierAssignment::findOrFail($id);

        if ($assignment->status === 'active') {
            DB::transaction(function () use ($assignment, $request) {
                $assignment->update([
                    'status' => 'removed',
                    'updated_by' => $request->user()?->id,
                ]);

                SupplierAssignmentHistory::create([
                    'supplier_assignment_id' => $assignment->id,
                    'project_id' => $assignment->project_id,
                    'jig_no' => $assignment->jig_no,
                    'unit_no' => $assignment->unit_no,
                    'category' => $assignment->category,
                    'previous_supplier_id' => $assignment->supplier_id,
                    'new_supplier_id' => null,
                    'previous_date' => $assignment->assignment_date?->format('Y-m-d'),
                    'new_date' => null,
                    'action' => 'removed',
                    'changed_by' => $request->user()?->id,
                    'created_at' => now(),
                ]);
            });

            broadcast(new SupplierAssignmentUpdated($assignment, 'removed'))->toOthers();
        }

        return response()->json([
            'success' => true,
            'message' => "Supplier assignment for {$assignment->category} on Unit {$assignment->unit_no} removed.",
        ]);
    }

    /**
     * Get real-time overview tabular data with filters and pagination.
     */
    public function overview(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $query = SupplierAssignment::with(['project', 'supplier', 'creator', 'updater'])
            ->orderByDesc('updated_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        if ($request->filled('jig_no')) {
            $query->where('jig_no', 'ILIKE', "%{$request->input('jig_no')}%");
        }
        if ($request->filled('unit_no')) {
            $query->where('unit_no', 'ILIKE', "%{$request->input('unit_no')}%");
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->where('status', 'active');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('assignment_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('assignment_date', '<=', $request->input('date_to'));
        }

        $items = $query->paginate($request->input('per_page', 20));

        return response()->json($items);
    }

    /**
     * Strict validation helper for single assignment request.
     */
    private function validateAssignmentRequest(Request $request): void
    {
        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'jig_no' => ['required', 'string', 'max:255'],
            'unit_no' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:BASE,WELDMENT,CHILD_PART'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'assignment_date' => ['required', 'date'],
        ]);

        // Validate supplier is active
        $supplier = Supplier::find($request->input('supplier_id'));
        if (!$supplier || !$supplier->is_active) {
            abort(422, 'Cannot assign an inactive supplier.');
        }

        // Strict 7-day range validation: today +/- 3 calendar days
        $selectedDate = Carbon::parse($request->input('assignment_date'))->startOfDay();
        $today = Carbon::today();
        $minDate = $today->copy()->subDays(3);
        $maxDate = $today->copy()->addDays(3);

        if ($selectedDate->lt($minDate) || $selectedDate->gt($maxDate)) {
            abort(422, "Assignment date must be between {$minDate->format('Y-m-d')} and {$maxDate->format('Y-m-d')} (within 3 days of today).");
        }
    }
}
