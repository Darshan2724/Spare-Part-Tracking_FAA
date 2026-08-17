<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemLogController extends Controller
{
    /**
     * Admin System Logs Index with multi-criteria filtering and pagination.
     */
    public function index(Request $request)
    {
        $request->user()?->hasRole('ADMIN') ?: abort(403, 'Access denied. Administrator privileges required.');

        $query = SystemLog::with(['user:id,name,email', 'reviewer:id,name', 'resolver:id,name']);

        if ($request->filled('severity')) {
            $query->where('severity', strtoupper($request->input('severity')));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('module')) {
            $query->where('module', strtoupper($request->input('module')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('message', 'ILIKE', "%{$search}%")
                  ->orWhere('endpoint', 'ILIKE', "%{$search}%")
                  ->orWhere('trace_id', 'ILIKE', "%{$search}%")
                  ->orWhere('user_role', 'ILIKE', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('name', 'ILIKE', "%{$search}%")->orWhere('email', 'ILIKE', "%{$search}%"));
            });
        }

        $perPage = (int) $request->input('per_page', 25);
        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Admin System Logs Analytics Dashboard (Summary metrics, trend charts, category breakdown).
     */
    public function dashboard(Request $request)
    {
        $request->user()?->hasRole('ADMIN') ?: abort(403, 'Access denied. Administrator privileges required.');

        $today = now()->startOfDay();

        // 1. Today's Summary Metrics
        $summary = [
            'errors_today' => SystemLog::where('created_at', '>=', $today)->whereIn('severity', ['ERROR', 'CRITICAL'])->count(),
            'warnings_today' => SystemLog::where('created_at', '>=', $today)->where('severity', 'WARNING')->count(),
            'failed_api_requests' => SystemLog::where('created_at', '>=', $today)->where(function ($q) {
                $q->where('category', 'api_errors')->orWhere('status_code', '>=', 400);
            })->count(),
            'database_errors' => SystemLog::where('created_at', '>=', $today)->where('category', 'database_errors')->count(),
            'authentication_failures' => SystemLog::where('created_at', '>=', $today)->where('category', 'authentication_logs')->whereIn('severity', ['WARNING', 'ERROR'])->count(),
            'workflow_errors' => SystemLog::where('created_at', '>=', $today)->where('category', 'workflow_errors')->count(),
            'realtime_errors' => SystemLog::where('created_at', '>=', $today)->where('category', 'realtime_logs')->count(),
            'unresolved_total' => SystemLog::where('status', 'new')->count(),
        ];

        // 2. Errors by Category (All time / last 30 days)
        $errorsByCategory = SystemLog::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        // 3. Errors by Hour (Last 24 Hours)
        $errorsByHour = [];
        for ($h = 23; $h >= 0; $h--) {
            $timeSlot = now()->subHours($h);
            $start = $timeSlot->copy()->startOfHour();
            $end = $timeSlot->copy()->endOfHour();
            $count = SystemLog::whereBetween('created_at', [$start, $end])->whereIn('severity', ['ERROR', 'CRITICAL'])->count();
            $errorsByHour[] = [
                'hour' => $start->format('H:00'),
                'count' => $count,
            ];
        }

        // 4. Authentication Failure Trend (Last 7 Days)
        $authFailureTrend = [];
        for ($d = 6; $d >= 0; $d--) {
            $dayDate = now()->subDays($d)->format('Y-m-d');
            $fails = SystemLog::whereDate('created_at', $dayDate)
                ->where('category', 'authentication_logs')
                ->whereIn('severity', ['WARNING', 'ERROR'])
                ->count();
            $authFailureTrend[] = [
                'date' => $dayDate,
                'label' => now()->subDays($d)->format('d M'),
                'failures' => $fails,
            ];
        }

        // 5. Recent Critical Logs
        $recentCritical = SystemLog::with('user:id,name,email')
            ->whereIn('severity', ['ERROR', 'CRITICAL'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return response()->json([
            'summary' => $summary,
            'errors_by_category' => $errorsByCategory,
            'errors_by_hour' => $errorsByHour,
            'auth_failure_trend' => $authFailureTrend,
            'recent_critical' => $recentCritical,
        ]);
    }

    /**
     * Show single log detail.
     */
    public function show(Request $request, $id)
    {
        $request->user()?->hasRole('ADMIN') ?: abort(403, 'Access denied.');

        $log = SystemLog::with(['user', 'reviewer', 'resolver'])->findOrFail($id);

        return response()->json($log);
    }

    /**
     * Update log resolution status (Mark Reviewed / Resolved).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->user()?->hasRole('ADMIN') ?: abort(403, 'Access denied.');

        $request->validate([
            'status' => ['required', 'in:new,reviewed,resolved'],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = SystemLog::findOrFail($id);
        $status = $request->input('status');

        $updates = ['status' => $status];

        if ($status === 'reviewed') {
            $updates['reviewed_by'] = $request->user()->id;
            $updates['reviewed_at'] = now();
        } elseif ($status === 'resolved') {
            $updates['resolved_by'] = $request->user()->id;
            $updates['resolved_at'] = now();
            if ($request->filled('resolution_notes')) {
                $updates['resolution_notes'] = $request->input('resolution_notes');
            }
        } elseif ($status === 'new') {
            $updates['reviewed_by'] = null;
            $updates['reviewed_at'] = null;
            $updates['resolved_by'] = null;
            $updates['resolved_at'] = null;
        }

        $log->update($updates);

        return response()->json([
            'success' => true,
            'message' => "Log status updated to {$status}.",
            'log' => $log->fresh(['user', 'reviewer', 'resolver']),
        ]);
    }
}
