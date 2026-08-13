<?php

namespace App\Http\Controllers;

use App\Models\BomItem;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\BomRequirement;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $query = BomItem::query()->with(['project', 'requirements', 'supplier']);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%")
                  ->orWhere('size', 'LIKE', "%{$search}%")
                  ->orWhereHas('project', function ($pq) use ($search) {
                      $pq->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('project_code', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('name', 'LIKE', "%{$search}%");
                  });
            });
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        if ($request->filled('side')) {
            $query->whereHas('requirements', function ($q) use ($request) {
                $q->where('side', $request->input('side'));
            });
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $perPage = (int) $request->input('per_page', 100);
        if ($perPage > 500) $perPage = 500;
        $bomItems = $query->orderBy('standard_part_no')->paginate($perPage);

        // Calculate received quantities per bom_item and side
        $bomItemIds = $bomItems->pluck('id')->toArray();
        $receiptTotals = ReceiptItem::query()
            ->select('bom_item_id', 'side', DB::raw('SUM(received_quantity) as total_received'))
            ->whereIn('bom_item_id', $bomItemIds)
            ->groupBy('bom_item_id', 'side')
            ->get()
            ->groupBy('bom_item_id');

        $bomItems->getCollection()->transform(function ($item) use ($receiptTotals) {
            $itemReceipts = $receiptTotals->get($item->id, collect());
            
            $sideStats = [];
            foreach ($item->requirements as $req) {
                $rec = $itemReceipts->firstWhere('side', $req->side);
                $received = $rec ? (int) $rec->total_received : 0;
                $pending = max(0, $req->required_quantity - $received);

                $sideStats[$req->side] = [
                    'required' => $req->required_quantity,
                    'received' => $received,
                    'pending' => $pending,
                ];
            }

            $item->side_stats = $sideStats;
            return $item;
        });

        $projects = Project::orderBy('name')->get(['id', 'name', 'project_code']);

        return response()->json([
            'items' => $bomItems,
            'projects' => $projects,
        ]);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'delivery_note_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.bom_item_id' => ['required', 'exists:bom_items,id'],
            'items.*.side' => ['required', 'in:RH,LH,COMMON'],
            'items.*.received_quantity' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($request) {
            $receipt = Receipt::create([
                'project_id' => $request->input('project_id'),
                'supplier_id' => $request->input('supplier_id'),
                'delivery_note_number' => $request->input('delivery_note_number'),
                'received_by' => $request->user()->id,
                'remarks' => $request->input('remarks'),
            ]);

            foreach ($request->input('items') as $item) {
                $receiptItem = ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'bom_item_id' => $item['bom_item_id'],
                    'side' => $item['side'],
                    'received_quantity' => $item['received_quantity'],
                    'status' => 'received',
                    'remarks' => $item['remarks'] ?? null,
                ]);

                WorkflowEvent::create([
                    'bom_item_id' => $item['bom_item_id'],
                    'project_id' => $request->input('project_id'),
                    'user_id' => $request->user()->id,
                    'event_type' => 'store_received',
                    'side' => $item['side'],
                    'quantity' => $item['received_quantity'],
                    'previous_state' => 'pending',
                    'new_state' => 'received',
                    'remarks' => "Received {$item['received_quantity']} units in store. DN: " . ($request->input('delivery_note_number') ?? 'N/A'),
                ]);

                try {
                    broadcast(new \App\Events\StoreReceived($receiptItem))->toOthers();
                } catch (\Throwable $e) {}
            }

            return response()->json([
                'success' => true,
                'message' => 'Receipt recorded successfully.',
                'receipt_id' => $receipt->id,
            ]);
        });
    }

    public function sendToQc(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $item = ReceiptItem::with('bomItem.project')->findOrFail($id);

        if ($item->status !== 'received') {
            return response()->json(['success' => false, 'message' => 'Item is not in received status.'], 422);
        }

        $item->update(['status' => 'sent_to_qc']);

        WorkflowEvent::create([
            'bom_item_id' => $item->bom_item_id,
            'project_id' => $item->bomItem->project_id,
            'user_id' => $request->user()->id,
            'event_type' => 'sent_to_qc',
            'side' => $item->side,
            'quantity' => $item->received_quantity,
            'previous_state' => 'received',
            'new_state' => 'sent_to_qc',
            'remarks' => 'Dispatched to QC from Store.',
        ]);

        return response()->json(['success' => true, 'message' => 'Item dispatched to QC queue.']);
    }

    public function history(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $items = ReceiptItem::query()
            ->with(['bomItem.project', 'receipt.receiver', 'receipt.supplier'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($items);
    }

    public function revert(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $item = ReceiptItem::with('bomItem.project')->findOrFail($id);

        if (in_array($item->status, ['qc_approved', 'qc_rejected', 'qc_rework', 'qc_inspected', 'paint_completed', 'assembly_completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot revert item. Quality Control inspection or downstream workflow has already been recorded.'
            ], 422);
        }

        return DB::transaction(function () use ($item, $request) {
            $prevStatus = $item->status;
            $qty = $item->received_quantity;

            WorkflowEvent::create([
                'bom_item_id' => $item->bom_item_id,
                'project_id' => $item->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'store_receipt_reverted',
                'side' => $item->side,
                'quantity' => -$qty,
                'previous_state' => $prevStatus,
                'new_state' => 'reverted',
                'remarks' => "Store receipt of {$qty} units ({$item->side}) reverted by user.",
            ]);

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Store receipt successfully reverted and quantity restored to pending.'
            ]);
        });
    }

    /**
     * Store Hierarchy API: Returns project JIG -> Unit -> Parts tree for 62800 project.
     * Completeness coloring: Units turn green when 100% parts received; JIGs turn green when all units complete.
     */
    public function hierarchy(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $projectId = $request->input('project_id');
        $projects = Project::orderBy('name')->get();

        $projectsList = $projects->map(function ($proj) {
            $reqSum = (int) BomRequirement::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('required_quantity');
            $recSum = (int) ReceiptItem::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('received_quantity');
            $progress = $reqSum > 0 ? min(100, round(($recSum / $reqSum) * 100, 1)) : 0;
            return [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'total_required' => $reqSum,
                'total_received' => $recSum,
                'completion_pct' => $progress,
                'is_complete' => ($reqSum > 0 && $recSum >= $reqSum)
            ];
        });

        if (!$projectId) {
            return response()->json([
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'message' => 'Please select a project to view JIG hierarchy.',
            ]);
        }

        $project = Project::findOrFail($projectId);

        // Fetch items to check if parts have JIG-Unit pattern (e.g. 62800-ST7-01-11-R)
        $bomItems = BomItem::query()
            ->with(['requirements', 'supplier', 'project'])
            ->where('project_id', $project->id)
            ->orderBy('standard_part_no')
            ->get();

        if ($bomItems->isEmpty()) {
            return response()->json([
                'is_hierarchical' => false,
                'project' => $project,
                'message' => 'No BOM items found for this project.',
            ]);
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();
        $receiptTotals = ReceiptItem::query()
            ->select('bom_item_id', 'side', DB::raw('SUM(received_quantity) as total_received'))
            ->whereIn('bom_item_id', $bomItemIds)
            ->groupBy('bom_item_id', 'side')
            ->get()
            ->groupBy('bom_item_id');

        $jigsTree = [];

        foreach ($bomItems as $item) {
            $partNo = trim($item->standard_part_no);
            $parts = explode('-', $partNo);

            $jigName = count($parts) >= 3 ? strtoupper(trim($parts[1])) : 'GENERAL';
            $unitNo = count($parts) >= 3 ? 'Unit ' . trim($parts[2]) : 'Unit 00';

            $itemReceipts = $receiptTotals->get($item->id, collect());

            $sideStats = [];
            $itemTotalRequired = 0;
            $itemTotalReceived = 0;
            $itemTotalPending = 0;

            foreach ($item->requirements as $req) {
                $rec = $itemReceipts->firstWhere('side', $req->side);
                $received = $rec ? (int) $rec->total_received : 0;
                $pending = max(0, $req->required_quantity - $received);

                $sideStats[$req->side] = [
                    'required' => $req->required_quantity,
                    'received' => $received,
                    'pending' => $pending,
                ];

                $itemTotalRequired += $req->required_quantity;
                $itemTotalReceived += min($received, $req->required_quantity);
                $itemTotalPending += $pending;
            }

            $item->side_stats = $sideStats;
            $item->total_required = $itemTotalRequired;
            $item->total_received = $itemTotalReceived;
            $item->total_pending = $itemTotalPending;
            $item->is_complete = ($itemTotalRequired > 0 && $itemTotalPending === 0);

            if (!isset($jigsTree[$jigName])) {
                $jigsTree[$jigName] = [
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_parts' => 0,
                    'complete_units' => 0,
                    'total_units' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'units' => [],
                ];
            }

            if (!isset($jigsTree[$jigName]['units'][$unitNo])) {
                $jigsTree[$jigName]['units'][$unitNo] = [
                    'unit_no' => $unitNo,
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'pending_quantity' => 0,
                    'total_parts' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'parts' => [],
                ];
            }

            $jigsTree[$jigName]['units'][$unitNo]['parts'][] = $item;
            $jigsTree[$jigName]['units'][$unitNo]['total_parts']++;
            $jigsTree[$jigName]['units'][$unitNo]['total_required'] += $itemTotalRequired;
            $jigsTree[$jigName]['units'][$unitNo]['total_received'] += $itemTotalReceived;
            $jigsTree[$jigName]['units'][$unitNo]['pending_quantity'] += $itemTotalPending;

            $jigsTree[$jigName]['total_parts']++;
            $jigsTree[$jigName]['total_required'] += $itemTotalRequired;
            $jigsTree[$jigName]['total_received'] += $itemTotalReceived;
        }

        $formattedJigs = [];

        foreach ($jigsTree as $jigName => $jigData) {
            $formattedUnits = [];
            $completeUnitsCount = 0;

            foreach ($jigData['units'] as $unitNo => $unitData) {
                $req = $unitData['total_required'];
                $rec = $unitData['total_received'];
                $pend = $unitData['pending_quantity'];
                $unitData['is_complete'] = ($req > 0 && $pend === 0);
                $unitData['completion_pct'] = $req > 0 ? round(($rec / $req) * 100, 1) : 100;

                if ($unitData['is_complete']) {
                    $completeUnitsCount++;
                }

                $formattedUnits[] = $unitData;
            }

            usort($formattedUnits, fn($a, $b) => strcmp($a['unit_no'], $b['unit_no']));

            $jigReq = $jigData['total_required'];
            $jigRec = $jigData['total_received'];
            $totalUnitsCount = count($formattedUnits);
            $jigData['complete_units'] = $completeUnitsCount;
            $jigData['total_units'] = $totalUnitsCount;
            $jigData['is_complete'] = ($totalUnitsCount > 0 && $completeUnitsCount === $totalUnitsCount);
            $jigData['completion_pct'] = $jigReq > 0 ? round(($jigRec / $jigReq) * 100, 1) : 100;
            $jigData['units'] = $formattedUnits;

            $formattedJigs[] = $jigData;
        }

        usort($formattedJigs, fn($a, $b) => strcmp($a['jig_name'], $b['jig_name']));

        return response()->json([
            'is_hierarchical' => true,
            'project' => $project,
            'jigs' => $formattedJigs,
            'projects' => $projectsList,
        ]);
    }
}
