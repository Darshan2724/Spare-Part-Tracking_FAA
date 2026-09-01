<?php

namespace App\Http\Controllers;

use App\Services\SupplierAnalyticsService;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierAnalyticsController extends Controller
{
    public function __construct(
        protected SupplierAnalyticsService $analyticsService
    ) {}

    public function kpis(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $filters = $this->extractFilters($request);
        $kpis = $this->analyticsService->getKpis($filters);

        return response()->json([
            'success' => true,
            'kpis' => $kpis,
        ]);
    }

    public function ranking(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $filters = $this->extractFilters($request);
        $sortBy = $request->input('sort_by', 'usage'); // usage, lowest_rework, highest_rework, best_overall
        $rankings = $this->analyticsService->getRankings($filters, $sortBy);

        return response()->json([
            'success' => true,
            'rankings' => $rankings,
        ]);
    }

    public function rework(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $filters = $this->extractFilters($request);
        $data = $this->analyticsService->getReworkAnalysis($filters);

        return response()->json([
            'success' => true,
            'rework' => $data,
        ]);
    }

    public function allocation(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $filters = $this->extractFilters($request);
        $allocation = $this->analyticsService->getAllocationBreakdown($filters);

        return response()->json([
            'success' => true,
            'allocation' => $allocation,
        ]);
    }

    public function history(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $filters = $this->extractFilters($request);
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $history = $this->analyticsService->getHistory($filters, $page, $perPage);

        return response()->json($history);
    }

    public function supplierDetail(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE', 'STORE', 'QC']) ?: abort(403);

        $supplier = Supplier::withTrashed()->withCount('bomItems')->findOrFail($id);

        $filters = ['supplier_id' => $id];
        $kpis = $this->analyticsService->getKpis($filters);
        $history = $this->analyticsService->getHistory($filters, 1, 15);

        return response()->json([
            'supplier' => $supplier,
            'kpis' => $kpis,
            'recent_history' => $history,
        ]);
    }

    private function extractFilters(Request $request): array
    {
        return [
            'project_id' => $request->input('project_id'),
            'supplier_id' => $request->input('supplier_id'),
            'category' => $request->input('category'),
            'action' => $request->input('action'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'active_only' => $request->boolean('active_only', false),
        ];
    }
}
