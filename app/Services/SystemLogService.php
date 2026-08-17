<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SystemLogService
{
    /**
     * General safe log creation.
     */
    public static function log(array $data): ?SystemLog
    {
        try {
            $sanitizedDetails = self::sanitize($data['details'] ?? null);

            return SystemLog::create([
                'severity'         => strtoupper($data['severity'] ?? 'INFO'),
                'category'         => $data['category'] ?? 'application_errors',
                'module'           => strtoupper($data['module'] ?? 'SYSTEM'),
                'user_id'          => $data['user_id'] ?? null,
                'user_role'        => $data['user_role'] ?? null,
                'trace_id'         => $data['trace_id'] ?? (string) Str::uuid(),
                'endpoint'         => $data['endpoint'] ?? null,
                'method'           => strtoupper($data['method'] ?? 'GET'),
                'status_code'      => $data['status_code'] ?? null,
                'ip_address'       => $data['ip_address'] ?? null,
                'user_agent'       => $data['user_agent'] ?? null,
                'message'          => Str::limit($data['message'] ?? 'System Event', 2000),
                'details'          => $sanitizedDetails,
                'status'           => 'new',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create SystemLog database entry: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Log API request errors and runtime exceptions.
     */
    public static function logApiError(Request $request, Throwable|string $error, int $statusCode = 500, ?string $module = 'API'): ?SystemLog
    {
        $user = $request->user();
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $trace = $error instanceof Throwable ? array_slice($error->getTrace(), 0, 10) : null;

        $severity = $statusCode >= 500 ? 'ERROR' : ($statusCode === 403 || $statusCode === 401 ? 'WARNING' : 'INFO');
        if ($statusCode >= 500 && Str::contains(strtolower($message), ['database', 'connection', 'fatal', 'deadlock'])) {
            $severity = 'CRITICAL';
        }

        return self::log([
            'severity'    => $severity,
            'category'    => $statusCode >= 500 ? 'application_errors' : ($statusCode === 403 ? 'authorization_logs' : 'api_errors'),
            'module'      => $module,
            'user_id'     => $user?->id,
            'user_role'   => $user?->roles?->first()?->name ?? 'GUEST',
            'trace_id'    => $request->header('X-Trace-ID') ?? (string) Str::uuid(),
            'endpoint'    => $request->path(),
            'method'      => $request->method(),
            'status_code' => $statusCode,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'message'     => "HTTP {$statusCode} on {$request->method()} /{$request->path()}: {$message}",
            'details'     => [
                'exception_class' => $error instanceof Throwable ? get_class($error) : null,
                'file' => $error instanceof Throwable ? $error->getFile() . ':' . $error->getLine() : null,
                'input_params' => self::sanitize($request->except(['password', 'password_confirmation', 'token', 'secret'])),
                'trace_snippet' => $trace,
            ],
        ]);
    }

    /**
     * Log Authentication Events (Login success, failed login, logout, password change).
     */
    public static function logAuthEvent(string $event, ?Request $request = null, ?object $user = null, string $severity = 'INFO', ?string $detailsMsg = null): ?SystemLog
    {
        return self::log([
            'severity'    => $severity,
            'category'    => 'authentication_logs',
            'module'      => 'AUTH',
            'user_id'     => $user?->id,
            'user_role'   => $user?->roles?->first()?->name ?? ($user?->role ?? 'GUEST'),
            'trace_id'    => $request?->header('X-Trace-ID') ?? (string) Str::uuid(),
            'endpoint'    => $request?->path() ?? 'auth',
            'method'      => $request?->method() ?? 'POST',
            'status_code' => $severity === 'WARNING' || $severity === 'ERROR' ? 401 : 200,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'message'     => "Auth Event: {$event}" . ($detailsMsg ? " — {$detailsMsg}" : ''),
            'details'     => [
                'email' => $request?->input('email'),
                'event' => $event,
                'info'  => $detailsMsg,
            ],
        ]);
    }

    /**
     * Log Authorization Violations (403 Forbidden attempts by unauthorized roles).
     */
    public static function logAuthorizationViolation(Request $request, ?string $requiredAction = null): ?SystemLog
    {
        $user = $request->user();
        $role = $user?->roles?->first()?->name ?? 'GUEST';

        return self::log([
            'severity'    => 'WARNING',
            'category'    => 'authorization_logs',
            'module'      => strtoupper(explode('/', trim($request->path(), '/'))[2] ?? 'API'),
            'user_id'     => $user?->id,
            'user_role'   => $role,
            'trace_id'    => $request->header('X-Trace-ID') ?? (string) Str::uuid(),
            'endpoint'    => $request->path(),
            'method'      => $request->method(),
            'status_code' => 403,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'message'     => "Forbidden action attempt: Role '{$role}' attempted '{$request->method()} /{$request->path()}'" . ($requiredAction ? " ({$requiredAction})" : ''),
            'details'     => [
                'attempted_endpoint' => $request->path(),
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'role' => $role,
                'input' => self::sanitize($request->all()),
            ],
        ]);
    }

    /**
     * Log Workflow Business Errors (e.g. invalid status transitions, negative quantities, etc.).
     */
    public static function logWorkflowError(string $module, string $message, array $context = [], ?Request $request = null): ?SystemLog
    {
        $user = $request?->user();

        return self::log([
            'severity'    => 'ERROR',
            'category'    => 'workflow_errors',
            'module'      => strtoupper($module),
            'user_id'     => $user?->id,
            'user_role'   => $user?->roles?->first()?->name ?? 'SYSTEM',
            'trace_id'    => $request?->header('X-Trace-ID') ?? (string) Str::uuid(),
            'endpoint'    => $request?->path(),
            'method'      => $request?->method() ?? 'POST',
            'status_code' => 422,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'message'     => "Workflow Error [{$module}]: {$message}",
            'details'     => self::sanitize($context),
        ]);
    }

    /**
     * Log Database Errors (Connection failures, Query exceptions).
     */
    public static function logDatabaseError(Throwable $e, ?string $query = null, ?Request $request = null): ?SystemLog
    {
        $user = $request?->user();

        return self::log([
            'severity'    => 'CRITICAL',
            'category'    => 'database_errors',
            'module'      => 'DATABASE',
            'user_id'     => $user?->id,
            'user_role'   => $user?->roles?->first()?->name ?? 'SYSTEM',
            'trace_id'    => $request?->header('X-Trace-ID') ?? (string) Str::uuid(),
            'endpoint'    => $request?->path(),
            'method'      => $request?->method() ?? 'DB',
            'status_code' => 500,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'message'     => "PostgreSQL Database Exception: {$e->getMessage()}",
            'details'     => [
                'sql' => $query,
                'error_code' => $e->getCode(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice($e->getTrace(), 0, 5),
            ],
        ]);
    }

    /**
     * Log Realtime / WebSocket / Broadcast Errors.
     */
    public static function logRealtimeError(string $event, Throwable|string $error, array $context = []): ?SystemLog
    {
        $msg = $error instanceof Throwable ? $error->getMessage() : (string) $error;

        return self::log([
            'severity'    => 'WARNING',
            'category'    => 'realtime_logs',
            'module'      => 'REALTIME',
            'message'     => "Realtime Broadcast Issue [{$event}]: {$msg}",
            'details'     => array_merge(['event' => $event], self::sanitize($context)),
        ]);
    }

    /**
     * Deep sanitize any array/object to scrub sensitive credentials.
     */
    public static function sanitize(mixed $data): mixed
    {
        if (is_null($data) || is_scalar($data)) {
            return $data;
        }

        if (is_object($data)) {
            $data = (array) $data;
        }

        if (is_array($data)) {
            $sensitiveKeys = ['password', 'password_confirmation', 'token', 'access_token', 'secret', 'authorization', 'bearer', 'cookie'];
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                    $sanitized[$key] = '******** [REDACTED]';
                } else {
                    $sanitized[$key] = self::sanitize($value);
                }
            }
            return $sanitized;
        }

        return $data;
    }
}
