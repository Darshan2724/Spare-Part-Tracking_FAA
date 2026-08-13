<?php

namespace App\Http\Controllers;

use App\Models\PurchaseQueueItem;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseQueueController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'QC']) ?: abort(403);

        $query = PurchaseQueueItem::query()
            ->with(['project', 'bomItem.supplier', 'rejectedBy', 'exporter']);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('rejection_reason', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $items = $query->orderByDesc('created_at')->paginate(20);
        $projects = Project::orderBy('name')->get(['id', 'name', 'project_code']);

        return response()->json([
            'items' => $items,
            'projects' => $projects,
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'status' => ['required', 'in:pending_purchase,exported,reordered,closed'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = PurchaseQueueItem::findOrFail($id);
        
        $item->update([
            'status' => $request->input('status'),
            'remarks' => $request->input('remarks', $item->remarks),
            'exported_by' => $request->input('status') === 'exported' ? $request->user()->id : $item->exported_by,
            'exported_at' => $request->input('status') === 'exported' ? now() : $item->exported_at,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Purchase queue item status updated to {$request->input('status')}.",
            'item' => $item,
        ]);
    }

    public function export(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'id' => ['nullable', 'exists:purchase_queue_items,id'],
            'format' => ['nullable', 'in:csv,pdf'],
        ]);

        if ($request->filled('id')) {
            $item = PurchaseQueueItem::findOrFail($request->input('id'));
            $item->update([
                'status' => 'exported',
                'exported_by' => $request->user()->id,
                'exported_at' => now(),
            ]);
        }

        $items = PurchaseQueueItem::with(['project', 'bomItem.supplier', 'rejectedBy'])
            ->where('status', 'pending_purchase')
            ->orderByDesc('created_at')
            ->get();

        $format = $request->input('format', 'csv');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.purchase_queue_pdf', ['items' => $items, 'date' => now()->format('Y-m-d H:i')]);
            return $pdf->download('Purchase_Reorder_Queue_' . now()->format('Ymd_His') . '.pdf');
        }

        // Default CSV export
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Purchase_Reorder_Queue_' . now()->format('Ymd_His') . '.csv"',
        ];

        $callback = function () use ($items) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Project Code', 'Project Name', 'Standard Part No', 'Side', 'Rejected Qty', 'Reason', 'Supplier', 'Rejected By', 'Rejection Date']);

            foreach ($items as $item) {
                fputcsv($file, [
                    $item->project?->project_code ?? '',
                    $item->project?->name ?? '',
                    $item->standard_part_no,
                    $item->side,
                    $item->rejected_quantity,
                    $item->rejection_reason ?? 'N/A',
                    $item->bomItem?->supplier?->name ?? $item->bomItem?->supplier_name_raw ?? 'N/A',
                    $item->rejectedBy?->name ?? 'System',
                    $item->created_at->format('Y-m-d H:i'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
