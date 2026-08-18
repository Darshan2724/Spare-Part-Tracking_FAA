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

        $projects = Project::orderBy('name')->get()->map(function ($proj) {
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
                'is_complete' => ($reqSum > 0 && $recSum >= $reqSum),
            ];
        });

        return response()->json([
            'items' => $bomItems,
            'projects' => $projects,
        ]);
    }

    /**
     * Dedicated Store Pending Requirements API.
     * Returns un-collapsed, side-isolated requirement records.
     */
    public function pending(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $query = BomRequirement::query()
            ->with(['bomItem.project', 'bomItem.supplier'])
            ->whereHas('bomItem', function ($q) use ($request) {
                if ($request->filled('project_id')) {
                    $q->where('project_id', $request->input('project_id'));
                }
                if ($request->filled('jig_no')) {
                    $q->where('jig_no', $request->input('jig_no'));
                }
                if ($request->filled('unit_no')) {
                    $q->where('unit_no', $request->input('unit_no'));
                }
                if ($request->filled('search')) {
                    $search = trim($request->input('search'));
                    $q->where(function ($sq) use ($search) {
                        $sq->where('standard_part_no', 'LIKE', "%{$search}%")
                          ->orWhere('item_no', 'LIKE', "%{$search}%");
                    });
                }
            });

        if ($request->filled('side')) {
            $query->where('side', $request->input('side'));
        }

        $requirements = $query->get();
        $bomItemIds = $requirements->pluck('bom_item_id')->unique()->toArray();

        $receiptTotals = ReceiptItem::query()
            ->select('bom_item_id', 'side', DB::raw('SUM(received_quantity) as total_received'))
            ->whereIn('bom_item_id', $bomItemIds)
            ->groupBy('bom_item_id', 'side')
            ->get();

        $pendingList = [];

        foreach ($requirements as $req) {
            $item = $req->bomItem;
            if (!$item) continue;

            $rec = $receiptTotals->first(fn($r) => $r->bom_item_id == $req->bom_item_id && $r->side === $req->side);
            $receivedQty = $rec ? (int) $rec->total_received : 0;
            $requiredQty = (int) $req->required_quantity;
            $pendingQty = max(0, $requiredQty - $receivedQty);

            // If filtering for only pending items
            if ($request->boolean('only_pending', true) && $pendingQty <= 0) {
                continue;
            }

            $status = $receivedQty === 0 ? 'pending' : ($pendingQty === 0 ? 'received' : 'partially_received');

            $pendingList[] = [
                'project_id' => $item->project_id,
                'project_code' => $item->project?->project_code ?? 'N/A',
                'jig_id' => $item->jig_no ?? 'GENERAL',
                'unit_id' => $item->unit_no ? 'Unit ' . $item->unit_no : 'Unit 00',
                'part_id' => $item->id,
                'part_no' => $item->standard_part_no,
                'side' => $req->side,
                'required_qty' => $requiredQty,
                'received_qty' => $receivedQty,
                'pending_qty' => $pendingQty,
                'status' => $status,
                'supplier_name' => $item->supplier?->name ?? ($item->supplier_name_raw ?? 'Standard'),
                'size' => $item->size,
            ];
        }

        return response()->json([
            'total' => count($pendingList),
            'data' => $pendingList,
        ]);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');

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

    public function bulkReceive(Request $request)
    {
        return $this->store($request);
    }

    public function sendToQc(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');

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
        $request->user()?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');

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
     * Store Returned Items API: Lists all items returned from QC inspections.
     */
    public function returnedItems(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC']) ?: abort(403);

        $query = ReceiptItem::query()
            ->with(['bomItem.project', 'qcInspections.inspector', 'receipt.supplier'])
            ->where('status', 'returned_to_store');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->whereHas('bomItem', function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%")
                  ->orWhere('jig_no', 'LIKE', "%{$search}%")
                  ->orWhere('unit_no', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('project_id')) {
            $query->whereHas('bomItem', fn($q) => $q->where('project_id', $request->input('project_id')));
        }

        if ($request->filled('side')) {
            $query->where('side', $request->input('side'));
        }

        $perPage = (int) $request->input('per_page', 50);
        $items = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json($items);
    }

    /**
     * Process Returned Item: Re-receive into store, return to vendor, or scrap.
     */
    public function processReturnedItem(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'STORE']) ?: abort(403, 'Unauthorized. Store operational permission required.');

        $request->validate([
            'action' => ['required', 'in:re_receive,return_to_vendor,scrap'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $id) {
            $item = ReceiptItem::with('bomItem.project')->findOrFail($id);
            $action = $request->input('action');
            $remarks = $request->input('remarks') ?? 'Processed by store.';

            if ($action === 're_receive') {
                $item->update(['status' => 'received']);
                $newStatus = 'received';
            } elseif ($action === 'return_to_vendor') {
                $item->update(['status' => 'returned_to_vendor']);
                $newStatus = 'returned_to_vendor';
            } else {
                $item->update(['status' => 'scrapped']);
                $newStatus = 'scrapped';
            }

            WorkflowEvent::create([
                'bom_item_id' => $item->bom_item_id,
                'project_id' => $item->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'store_returned_item_processed',
                'side' => $item->side,
                'quantity' => $item->received_quantity,
                'previous_state' => 'returned_to_store',
                'new_state' => $newStatus,
                'remarks' => "Returned item action [{$action}]: {$remarks}",
            ]);

            return response()->json([
                'success' => true,
                'message' => "Returned item successfully processed as {$action}.",
                'item' => $item,
            ]);
        });
    }

    /**
     * Store Hierarchy API: Returns project JIG -> Unit -> Parts tree.
     */
    public function hierarchy(Request $request, \App\Services\HierarchyService $hierarchyService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE']) ?: abort(403);

        $projectId = $request->input('project_id') ? (int) $request->input('project_id') : null;
        $filters = [
            'side' => $request->input('side'),
            'search' => $request->input('search'),
        ];

        $data = $hierarchyService->getDepartmentHierarchy('store', $projectId, $filters);
        return response()->json($data);
    }
}
