<?php

namespace App\Http\Controllers;

use App\Models\AssemblyRecord;
use App\Models\PaintRecord;
use App\Models\QcInspection;
use App\Models\Project;
use App\Models\WorkflowEvent;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssemblyController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $records = AssemblyRecord::query()
            ->with(['bomItem.project', 'paintRecord', 'qcInspection', 'assembler'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($records);
    }

    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        // Fetch completed paint records that still have un-assembled quantity
        $paintQuery = PaintRecord::query()
            ->with(['bomItem.project', 'qcInspection'])
            ->whereIn('status', ['completed', 'assembled'])
            ->whereRaw('(quantity - (SELECT COALESCE(SUM(quantity), 0) FROM assembly_records WHERE assembly_records.paint_record_id = paint_records.id)) > 0');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $paintQuery->whereHas('bomItem', function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('side')) {
            $paintQuery->where('side', $request->input('side'));
        }

        if ($request->filled('project_id')) {
            $paintQuery->whereHas('bomItem', function ($q) use ($request) {
                $q->where('project_id', $request->input('project_id'));
            });
        }

        $perPage = (int) $request->input('per_page', 50);
        $queue = $paintQuery->orderByDesc('created_at')->paginate($perPage);

        return response()->json($queue);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'ASSEMBLY']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'paint_record_id' => ['nullable', 'exists:paint_records,id'],
            'qc_inspection_id' => ['nullable', 'exists:qc_inspections,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $paintRecordId = $request->input('paint_record_id');
            $qcInspectionId = $request->input('qc_inspection_id');
            $bomItemId = (int) $request->input('bom_item_id');
            $side = $request->input('side');
            $qty = (int) $request->input('quantity');
            $projectId = null;

            // 1. Query all eligible PaintRecords for this BOM item & side with row-level locks
            $paintQuery = PaintRecord::where('bom_item_id', $bomItemId)
                ->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                })
                ->whereIn('status', ['completed', 'assembled'])
                ->lockForUpdate()
                ->with(['bomItem', 'qcInspection.receiptItem']);

            if ($paintRecordId) {
                $paintQuery->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END, id ASC", [$paintRecordId]);
            } else {
                $paintQuery->orderBy('id', 'asc');
            }
            $paintRecords = $paintQuery->get();

            $paintAvailable = [];
            foreach ($paintRecords as $p) {
                $alreadyAssembled = (int) AssemblyRecord::where('paint_record_id', $p->id)->sum('quantity');
                $paintAvailable[$p->id] = max(0, $p->quantity - $alreadyAssembled);
            }

            // 2. Query all eligible Direct QC Inspections for this BOM item & side with row-level locks
            $qcQuery = QcInspection::where('bom_item_id', $bomItemId)
                ->where(function ($q) use ($side) {
                    $q->where('side', $side)->orWhere('side', 'COMMON');
                })
                ->where('destination', 'ASSEMBLY')
                ->where('approved_quantity', '>', 0)
                ->lockForUpdate()
                ->with(['bomItem', 'receiptItem']);

            if ($qcInspectionId) {
                $qcQuery->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END, id ASC", [$qcInspectionId]);
            } else {
                $qcQuery->orderBy('id', 'asc');
            }
            $qcInspections = $qcQuery->get();

            $qcAvailable = [];
            foreach ($qcInspections as $q) {
                $alreadyAssembled = (int) AssemblyRecord::where('qc_inspection_id', $q->id)->sum('quantity');
                $qcAvailable[$q->id] = max(0, $q->approved_quantity - $alreadyAssembled);
            }

            $totalAvailable = (int) array_sum($paintAvailable) + (int) array_sum($qcAvailable);

            if ($totalAvailable <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No parts ready for assembly found for this {$side} side requirement."
                ], 422);
            }

            if ($qty > $totalAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot complete {$qty} units. Only {$totalAvailable} units available for assembly."
                ], 422);
            }

            // 3. Sequentially fulfill requested assembly quantity across available sources
            $qtyRemaining = $qty;
            $createdAssemblyRecords = [];

            // A. Fulfill from Paint records first
            foreach ($paintRecords as $paint) {
                $pAvail = $paintAvailable[$paint->id] ?? 0;
                if ($qtyRemaining <= 0) break;
                if ($pAvail <= 0) continue;

                $consume = min($qtyRemaining, $pAvail);
                $qtyRemaining -= $consume;
                $paintAvailable[$paint->id] -= $consume;
                $projectId = $paint->bomItem->project_id;

                $record = AssemblyRecord::create([
                    'bom_item_id' => $bomItemId,
                    'paint_record_id' => $paint->id,
                    'qc_inspection_id' => null,
                    'side' => $side,
                    'quantity' => $consume,
                    'assembled_by' => $request->user()->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'remarks' => $request->input('remarks'),
                ]);
                $createdAssemblyRecords[] = $record;

                if ($paintAvailable[$paint->id] === 0) {
                    $paint->update(['status' => 'assembled']);
                    if ($paint->qcInspection?->receiptItem) {
                        $paint->qcInspection->receiptItem->update(['status' => 'assembly_completed']);
                    }
                }
            }

            // B. Fulfill from Direct QC inspections next
            foreach ($qcInspections as $insp) {
                $qAvail = $qcAvailable[$insp->id] ?? 0;
                if ($qtyRemaining <= 0) break;
                if ($qAvail <= 0) continue;

                $consume = min($qtyRemaining, $qAvail);
                $qtyRemaining -= $consume;
                $qcAvailable[$insp->id] -= $consume;
                $projectId = $insp->bomItem->project_id;

                $record = AssemblyRecord::create([
                    'bom_item_id' => $bomItemId,
                    'paint_record_id' => null,
                    'qc_inspection_id' => $insp->id,
                    'side' => $side,
                    'quantity' => $consume,
                    'assembled_by' => $request->user()->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'remarks' => $request->input('remarks'),
                ]);
                $createdAssemblyRecords[] = $record;

                if ($qcAvailable[$insp->id] === 0 && $insp->receiptItem) {
                    $insp->receiptItem->update(['status' => 'assembly_completed']);
                }
            }

            WorkflowEvent::create([
                'bom_item_id' => $bomItemId,
                'project_id' => $projectId,
                'user_id' => $request->user()->id,
                'event_type' => 'assembly_completed',
                'side' => $side,
                'quantity' => $qty,
                'previous_state' => 'assembly',
                'new_state' => 'assembly_completed',
                'remarks' => "Assembly completed for {$qty} units.",
            ]);

            // High-Performance Indexed Project 100% Completion Check
            if ($projectId) {
                $proj = Project::find($projectId);
                if ($proj && $proj->status === 'active') {
                    $totalRequired = (int) \App\Models\BomRequirement::whereHas('bomItem', fn($q) => $q->where('project_id', $projectId))->sum('required_quantity');
                    $totalAssembled = (int) AssemblyRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $projectId))->where('status', 'completed')->sum('quantity');

                    if ($totalRequired > 0 && $totalAssembled >= $totalRequired) {
                        $proj->update([
                            'status' => 'completed',
                            'actual_completion_date' => now()->toDateString(),
                        ]);
                    }
                }
            }

            $lastRecord = end($createdAssemblyRecords);

            // Realtime WebSocket Broadcast
            if ($lastRecord) {
                try {
                    broadcast(new \App\Events\AssemblyUpdated($lastRecord, $projectId, $side, $qty, 'assembly'))->toOthers();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Realtime broadcast for AssemblyUpdated failed: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Assembly process completed successfully.',
                'assembly_id' => $lastRecord ? $lastRecord->id : null,
                'quantity' => $qty,
            ]);
        });
    }

    public function bulkComplete(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'ASSEMBLY']) ?: abort(403, 'Unauthorized. Assembly operational permission required.');

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.bom_item_id' => ['required', 'exists:bom_items,id'],
            'items.*.paint_record_id' => ['nullable', 'exists:paint_records,id'],
            'items.*.qc_inspection_id' => ['nullable', 'exists:qc_inspections,id'],
            'items.*.side' => ['required', 'in:RH,LH,COMMON'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $items = $request->input('items');
            $processedCount = 0;
            $affectedProjectIds = [];
            $lastRecord = null;

            foreach ($items as $entry) {
                $paintRecordId = $entry['paint_record_id'] ?? null;
                $qcInspectionId = $entry['qc_inspection_id'] ?? null;
                $bomItemId = (int) $entry['bom_item_id'];
                $side = $entry['side'];
                $qty = (int) $entry['quantity'];

                // 1. Query Paint records
                $paintQuery = PaintRecord::where('bom_item_id', $bomItemId)
                    ->where(function ($q) use ($side) {
                        $q->where('side', $side)->orWhere('side', 'COMMON');
                    })
                    ->whereIn('status', ['completed', 'assembled'])
                    ->lockForUpdate()
                    ->with(['bomItem', 'qcInspection.receiptItem']);

                if ($paintRecordId) {
                    $paintQuery->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END, id ASC", [$paintRecordId]);
                } else {
                    $paintQuery->orderBy('id', 'asc');
                }
                $paintRecords = $paintQuery->get();

                $paintAvailable = [];
                foreach ($paintRecords as $p) {
                    $alreadyAssembled = (int) AssemblyRecord::where('paint_record_id', $p->id)->sum('quantity');
                    $paintAvailable[$p->id] = max(0, $p->quantity - $alreadyAssembled);
                }

                // 2. Query Direct QC inspections
                $qcQuery = QcInspection::where('bom_item_id', $bomItemId)
                    ->where(function ($q) use ($side) {
                        $q->where('side', $side)->orWhere('side', 'COMMON');
                    })
                    ->where('destination', 'ASSEMBLY')
                    ->where('approved_quantity', '>', 0)
                    ->lockForUpdate()
                    ->with(['bomItem', 'receiptItem']);

                if ($qcInspectionId) {
                    $qcQuery->orderByRaw("CASE WHEN id = ? THEN 0 ELSE 1 END, id ASC", [$qcInspectionId]);
                } else {
                    $qcQuery->orderBy('id', 'asc');
                }
                $qcInspections = $qcQuery->get();

                $qcAvailable = [];
                foreach ($qcInspections as $q) {
                    $alreadyAssembled = (int) AssemblyRecord::where('qc_inspection_id', $q->id)->sum('quantity');
                    $qcAvailable[$q->id] = max(0, $q->approved_quantity - $alreadyAssembled);
                }

                $totalAvailable = (int) array_sum($paintAvailable) + (int) array_sum($qcAvailable);
                $qtyToAssemble = min($qty, $totalAvailable);

                if ($qtyToAssemble <= 0) continue;

                $qtyRemaining = $qtyToAssemble;
                $itemProjectId = null;

                // Fulfill from Paint
                foreach ($paintRecords as $paint) {
                    $pAvail = $paintAvailable[$paint->id] ?? 0;
                    if ($qtyRemaining <= 0) break;
                    if ($pAvail <= 0) continue;

                    $consume = min($qtyRemaining, $pAvail);
                    $qtyRemaining -= $consume;
                    $paintAvailable[$paint->id] -= $consume;
                    $itemProjectId = $paint->bomItem->project_id;

                    $lastRecord = AssemblyRecord::create([
                        'bom_item_id' => $bomItemId,
                        'paint_record_id' => $paint->id,
                        'qc_inspection_id' => null,
                        'side' => $side,
                        'quantity' => $consume,
                        'assembled_by' => $request->user()->id,
                        'status' => 'completed',
                        'completed_at' => now(),
                        'remarks' => $request->input('remarks'),
                    ]);

                    if ($paintAvailable[$paint->id] === 0) {
                        $paint->update(['status' => 'assembled']);
                        if ($paint->qcInspection?->receiptItem) {
                            $paint->qcInspection->receiptItem->update(['status' => 'assembly_completed']);
                        }
                    }
                }

                // Fulfill from Direct QC
                foreach ($qcInspections as $insp) {
                    $qAvail = $qcAvailable[$insp->id] ?? 0;
                    if ($qtyRemaining <= 0) break;
                    if ($qAvail <= 0) continue;

                    $consume = min($qtyRemaining, $qAvail);
                    $qtyRemaining -= $consume;
                    $qcAvailable[$insp->id] -= $consume;
                    $itemProjectId = $insp->bomItem->project_id;

                    $lastRecord = AssemblyRecord::create([
                        'bom_item_id' => $bomItemId,
                        'paint_record_id' => null,
                        'qc_inspection_id' => $insp->id,
                        'side' => $side,
                        'quantity' => $consume,
                        'assembled_by' => $request->user()->id,
                        'status' => 'completed',
                        'completed_at' => now(),
                        'remarks' => $request->input('remarks'),
                    ]);

                    if ($qcAvailable[$insp->id] === 0 && $insp->receiptItem) {
                        $insp->receiptItem->update(['status' => 'assembly_completed']);
                    }
                }

                WorkflowEvent::create([
                    'bom_item_id' => $bomItemId,
                    'project_id' => $itemProjectId,
                    'user_id' => $request->user()->id,
                    'event_type' => 'assembly_completed',
                    'side' => $side,
                    'quantity' => $qtyToAssemble,
                    'previous_state' => 'assembly',
                    'new_state' => 'assembly_completed',
                    'remarks' => "Bulk Assembly completed for {$qtyToAssemble} units.",
                ]);

                $processedCount++;
                if ($itemProjectId) $affectedProjectIds[$itemProjectId] = true;
            }

            // Check completion for affected projects using indexed count
            foreach (array_keys($affectedProjectIds) as $pId) {
                $proj = Project::find($pId);
                if ($proj && $proj->status === 'active') {
                    $totalRequired = (int) \App\Models\BomRequirement::whereHas('bomItem', fn($q) => $q->where('project_id', $pId))->sum('required_quantity');
                    $totalAssembled = (int) AssemblyRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $pId))->where('status', 'completed')->sum('quantity');

                    if ($totalRequired > 0 && $totalAssembled >= $totalRequired) {
                        $proj->update([
                            'status' => 'completed',
                            'actual_completion_date' => now()->toDateString(),
                        ]);
                    }
                }
            }

            // Realtime Broadcast for bulk operation
            if ($lastRecord) {
                try {
                    broadcast(new \App\Events\AssemblyUpdated($lastRecord, $lastRecord->bomItem?->project_id, $lastRecord->side, $processedCount, 'bulk_assembly'))->toOthers();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Bulk Realtime broadcast for AssemblyUpdated failed: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'processed_count' => $processedCount,
                'message' => "Successfully recorded assembly for {$processedCount} items."
            ]);
        });
    }

    /**
     * Assembly Hierarchy API: Returns project JIG -> Unit -> Parts tree for Assembly shop.
     */
    public function hierarchy(Request $request, HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('assembly', $projectId, $filters);
        return response()->json($data);
    }
}
