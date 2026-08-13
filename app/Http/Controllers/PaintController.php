<?php

namespace App\Http\Controllers;

use App\Models\PaintRecord;
use App\Models\QcInspection;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaintController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        $records = PaintRecord::query()
            ->with(['bomItem.project', 'qcInspection', 'painter'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($records);
    }

    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        // Fetch QC approved inspections that are ready for painting
        $queue = QcInspection::query()
            ->with(['bomItem.project', 'receiptItem'])
            ->where('approved_quantity', '>', 0)
            ->whereDoesntHave('paintRecord')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($queue);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PAINT']) ?: abort(403);

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'qc_inspection_id' => ['required', 'exists:qc_inspections,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'paint_type' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $inspection = QcInspection::with(['receiptItem', 'bomItem'])->findOrFail($request->input('qc_inspection_id'));

            $record = PaintRecord::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'qc_inspection_id' => $inspection->id,
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'painted_by' => $request->user()->id,
                'status' => 'completed',
                'completed_at' => now(),
                'paint_type' => $request->input('paint_type'),
                'remarks' => $request->input('remarks'),
            ]);

            // Update receipt item status to 'paint_completed'
            if ($inspection->receiptItem) {
                $inspection->receiptItem->update(['status' => 'paint_completed']);
            }

            WorkflowEvent::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'project_id' => $inspection->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'paint_completed',
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'previous_state' => 'qc_approved',
                'new_state' => 'paint_completed',
                'remarks' => "Painting completed for {$request->input('quantity')} units. Type: " . ($request->input('paint_type') ?? 'Standard'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paint process completed successfully.',
                'paint_id' => $record->id,
            ]);
        });
    }
}
