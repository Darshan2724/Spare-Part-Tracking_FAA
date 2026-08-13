<?php

namespace App\Http\Controllers;

use App\Models\AssemblyRecord;
use App\Models\PaintRecord;
use App\Models\WorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssemblyController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $records = AssemblyRecord::query()
            ->with(['bomItem.project', 'paintRecord', 'assembler'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($records);
    }

    public function queue(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $queue = PaintRecord::query()
            ->with(['bomItem.project', 'qcInspection'])
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($queue);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'ASSEMBLY']) ?: abort(403);

        $request->validate([
            'bom_item_id' => ['required', 'exists:bom_items,id'],
            'paint_record_id' => ['required', 'exists:paint_records,id'],
            'side' => ['required', 'in:RH,LH,COMMON'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request) {
            $paint = PaintRecord::with(['bomItem', 'qcInspection.receiptItem'])->findOrFail($request->input('paint_record_id'));

            $record = AssemblyRecord::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'paint_record_id' => $paint->id,
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'assembled_by' => $request->user()->id,
                'status' => 'completed',
                'completed_at' => now(),
                'remarks' => $request->input('remarks'),
            ]);

            // Update paint record status
            $paint->update(['status' => 'assembled']);
            if ($paint->qcInspection?->receiptItem) {
                $paint->qcInspection->receiptItem->update(['status' => 'assembly_completed']);
            }

            WorkflowEvent::create([
                'bom_item_id' => $request->input('bom_item_id'),
                'project_id' => $paint->bomItem->project_id,
                'user_id' => $request->user()->id,
                'event_type' => 'assembly_completed',
                'side' => $request->input('side'),
                'quantity' => $request->input('quantity'),
                'previous_state' => 'paint_completed',
                'new_state' => 'assembly_completed',
                'remarks' => "Assembly completed for {$request->input('quantity')} units.",
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Assembly process completed successfully.',
                'assembly_id' => $record->id,
            ]);
        });
    }
}
