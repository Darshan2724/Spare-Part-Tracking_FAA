<?php

namespace App\Http\Middleware;

use App\Services\SystemLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureSystemLogsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Ensure a unique trace identifier exists
        $traceId = $request->header('X-Trace-ID') ?? (string) Str::uuid();
        $request->headers->set('X-Trace-ID', $traceId);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            // Log uncaught runtime exception
            SystemLogService::logApiError($request, $e, 500);
            throw $e;
        }

        // Attach trace ID to response headers
        $response->headers->set('X-Trace-ID', $traceId);

        // Auto-log failed responses (403, 404, 422, 500 etc)
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400 && !$request->is('api/v1/admin/logs*')) {
            $content = $response->getContent();
            $decoded = json_decode($content, true);
            $errorMsg = $decoded['message'] ?? ($decoded['error'] ?? "HTTP {$statusCode} error");

            if ($statusCode === 403) {
                SystemLogService::logAuthorizationViolation($request, $errorMsg);
            } elseif ($statusCode >= 500) {
                SystemLogService::logApiError($request, $errorMsg, $statusCode);
            } elseif ($statusCode === 422) {
                SystemLogService::logWorkflowError('VALIDATION', $errorMsg, $decoded['errors'] ?? [], $request);
            }
        }

        return $response;
    }
}
