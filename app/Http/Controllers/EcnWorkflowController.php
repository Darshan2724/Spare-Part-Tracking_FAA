<?php

namespace App\Http\Controllers;

use App\Services\EcnWorkflowService;
use App\Services\EcnBulkSplitService;
use Illuminate\Http\Request;

class EcnWorkflowController extends Controller
{
    public function __construct(
        protected EcnWorkflowService $ecnWorkflowService = new EcnWorkflowService(),
        protected EcnBulkSplitService $ecnBulkSplitService = new EcnBulkSplitService()
    ) {}

    public function storeReceive(Request $request)
    {
        $request->validate([
            'ecn_requirement_id' => ['required', 'integer', 'exists:ecn_requirements,id'],
            'quantity' => ['required_without:received_quantity', 'nullable', 'integer', 'min:1'],
            'received_quantity' => ['required_without:quantity', 'nullable', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $qty = (int)($request->input('quantity') ?? $request->input('received_quantity'));

        $result = $this->ecnWorkflowService->receiveStore(
            (int)$request->input('ecn_requirement_id'),
            $qty,
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function sendToQc(Request $request)
    {
        $request->validate([
            'ecn_receipt_item_id' => ['required_without:ecn_requirement_id', 'nullable', 'integer', 'exists:ecn_receipt_items,id'],
            'ecn_requirement_id' => ['required_without:ecn_receipt_item_id', 'nullable', 'integer', 'exists:ecn_requirements,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $receiptItemId = $request->input('ecn_receipt_item_id');
        $item = null;
        if (!$receiptItemId && $request->input('ecn_requirement_id')) {
            $item = \App\Models\EcnReceiptItem::where('ecn_requirement_id', (int)$request->input('ecn_requirement_id'))
                ->where('status', 'received')
                ->latest('id')
                ->first();
            $receiptItemId = $item?->id;
        }

        if (!$receiptItemId) {
            return response()->json(['message' => 'No active ECN receipt item found in Store for this requirement.'], 422);
        }

        $qty = (int)($request->input('quantity') ?? $request->input('received_quantity') ?? ($item ? $item->received_quantity : 1));

        $result = $this->ecnWorkflowService->sendToQc(
            (int)$receiptItemId,
            $qty > 0 ? $qty : 1,
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function qcReceive(Request $request)
    {
        $request->validate([
            'ecn_receipt_item_id' => ['required_without:ecn_requirement_id', 'nullable', 'integer', 'exists:ecn_receipt_items,id'],
            'ecn_requirement_id' => ['required_without:ecn_receipt_item_id', 'nullable', 'integer', 'exists:ecn_requirements,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $receiptItemId = $request->input('ecn_receipt_item_id');
        if (!$receiptItemId && $request->input('ecn_requirement_id')) {
            $latestItem = \App\Models\EcnReceiptItem::where('ecn_requirement_id', (int)$request->input('ecn_requirement_id'))
                ->whereIn('status', ['received', 'sent_to_qc'])
                ->latest('id')
                ->first();
            $receiptItemId = $latestItem?->id;
        }

        if (!$receiptItemId) {
            return response()->json(['message' => 'No active ECN receipt item awaiting QC arrival for this requirement.'], 422);
        }

        $result = $this->ecnWorkflowService->qcReceive(
            (int)$receiptItemId,
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function qcInspect(Request $request)
    {
        $receiptItemId = $request->input('ecn_receipt_item_id');
        $reqId = $request->input('ecn_requirement_id');

        if (!$receiptItemId && $reqId) {
            $latestItem = \App\Models\EcnReceiptItem::where('ecn_requirement_id', (int)$reqId)
                ->whereIn('status', ['qc_received', 'sent_to_qc', 'received'])
                ->orderByRaw("CASE WHEN status = 'qc_received' THEN 1 WHEN status = 'sent_to_qc' THEN 2 ELSE 3 END")
                ->latest('id')
                ->first();
            $receiptItemId = $latestItem?->id;
        }

        if (!$receiptItemId) {
            return response()->json([
                'success' => false,
                'message' => 'ECN inspection record is incomplete (no active ECN QC receipt item found). Please refresh the QC queue and try again.',
                'error_code' => 'ECN_RECEIPT_ITEM_ID_MISSING'
            ], 422);
        }

        $request->merge(['ecn_receipt_item_id' => $receiptItemId]);

        $request->validate([
            'ecn_receipt_item_id' => ['required', 'integer', 'exists:ecn_receipt_items,id'],
            'approved_quantity' => ['nullable', 'integer', 'min:0'],
            'paint_quantity' => ['nullable', 'integer', 'min:0'],
            'assembly_quantity' => ['nullable', 'integer', 'min:0'],
            'destination' => ['nullable', 'string', 'in:PAINT,ASSEMBLY'],
            'rejected_quantity' => ['nullable', 'integer', 'min:0'],
            'rework_quantity' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $paintQty = (int)($request->input('paint_quantity') ?? 0);
        $assemblyQty = (int)($request->input('assembly_quantity') ?? 0);
        $appQty = (int)($request->input('approved_quantity') ?? ($paintQty + $assemblyQty));

        $result = $this->ecnWorkflowService->qcInspect(
            (int)$request->input('ecn_receipt_item_id'),
            $appQty,
            $request->input('destination') ?: 'ASSEMBLY',
            (int)($request->input('rejected_quantity') ?? 0),
            (int)($request->input('rework_quantity') ?? 0),
            $request->input('remarks'),
            $request->user()?->id,
            $paintQty,
            $assemblyQty
        );

        return response()->json($result);
    }

    public function reworkComplete(Request $request)
    {
        if (!$request->filled('workflow_record_id') && $request->filled('ecn_workflow_record_id')) {
            $request->merge(['workflow_record_id' => $request->input('ecn_workflow_record_id')]);
        }

        $request->validate([
            'workflow_record_id' => ['required', 'integer', 'exists:ecn_workflow_records,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->completeRework(
            (int)$request->input('workflow_record_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function paintComplete(Request $request)
    {
        if (!$request->filled('workflow_record_id') && $request->filled('ecn_workflow_record_id')) {
            $request->merge(['workflow_record_id' => $request->input('ecn_workflow_record_id')]);
        }

        $request->validate([
            'workflow_record_id' => ['required', 'integer', 'exists:ecn_workflow_records,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->completePaint(
            (int)$request->input('workflow_record_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function assemblyComplete(Request $request)
    {
        if (!$request->filled('workflow_record_id') && $request->filled('ecn_workflow_record_id')) {
            $request->merge(['workflow_record_id' => $request->input('ecn_workflow_record_id')]);
        }

        $request->validate([
            'workflow_record_id' => ['required', 'integer', 'exists:ecn_workflow_records,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->completeAssembly(
            (int)$request->input('workflow_record_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function revert(Request $request)
    {
        $request->validate([
            'department' => ['required', 'string', 'in:store,qc,rework,paint,assembly'],
            'record_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->revert(
            $request->input('department'),
            (int)$request->input('record_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function revertOptions(Request $request)
    {
        $request->validate([
            'department' => ['required', 'string', 'in:store,qc,rework,paint,assembly'],
            'ecn_requirement_id' => ['required', 'integer', 'exists:ecn_requirements,id'],
        ]);

        $options = $this->ecnWorkflowService->getRevertOptions(
            $request->input('department'),
            (int)$request->input('ecn_requirement_id')
        );

        return response()->json([
            'success' => true,
            'options' => $options,
            'total_revertible' => array_sum(array_column($options, 'available_quantity')),
        ]);
    }

    public function mixedBulkIntake(Request $request)
    {
        $request->validate([
            'items' => ['required', 'array', 'min:1'],
        ]);

        $result = $this->ecnBulkSplitService->processMixedStoreIntake(
            $request->input('items'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function mixedBulkRevert(Request $request)
    {
        $request->validate([
            'department' => ['required', 'string', 'in:store,qc,rework,paint,assembly'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $result = $this->ecnBulkSplitService->processMixedBulkRevert(
            $request->input('department'),
            $request->input('items'),
            $request->user()?->id
        );

        return response()->json($result);
    }
}
