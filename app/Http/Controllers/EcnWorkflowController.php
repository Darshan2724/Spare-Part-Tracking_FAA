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
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->receiveStore(
            (int)$request->input('ecn_requirement_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function sendToQc(Request $request)
    {
        $request->validate([
            'ecn_receipt_item_id' => ['required', 'integer', 'exists:ecn_receipt_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->sendToQc(
            (int)$request->input('ecn_receipt_item_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function qcReceive(Request $request)
    {
        $request->validate([
            'ecn_receipt_item_id' => ['required', 'integer', 'exists:ecn_receipt_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->qcReceive(
            (int)$request->input('ecn_receipt_item_id'),
            (int)$request->input('quantity'),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function qcInspect(Request $request)
    {
        $request->validate([
            'ecn_receipt_item_id' => ['required', 'integer', 'exists:ecn_receipt_items,id'],
            'approved_quantity' => ['nullable', 'integer', 'min:0'],
            'destination' => ['nullable', 'string', 'in:PAINT,ASSEMBLY'],
            'rejected_quantity' => ['nullable', 'integer', 'min:0'],
            'rework_quantity' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->ecnWorkflowService->qcInspect(
            (int)$request->input('ecn_receipt_item_id'),
            (int)($request->input('approved_quantity') ?? 0),
            $request->input('destination') ?: 'ASSEMBLY',
            (int)($request->input('rejected_quantity') ?? 0),
            (int)($request->input('rework_quantity') ?? 0),
            $request->input('remarks'),
            $request->user()?->id
        );

        return response()->json($result);
    }

    public function reworkComplete(Request $request)
    {
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
