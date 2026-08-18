<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * Comprehensive System Health Check Endpoint for Server, Docker, Mobile & Automation Scripts
     */
    public function check(Request $request)
    {
        $status = 'healthy';
        $checks = [];

        // 1. PostgreSQL Database Check
        try {
            DB::connection()->getPdo();
            $dbVersion = DB::select('SHOW server_version')[0]->server_version ?? 'Unknown';
            $checks['database'] = [
                'status' => 'UP',
                'driver' => config('database.default'),
                'version' => $dbVersion,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['database'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
        }

        // 2. Redis / Cache & Queue Check
        try {
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                if (extension_loaded('redis')) {
                    Redis::ping();
                    $checks['redis'] = ['status' => 'UP'];
                } else {
                    $checks['redis'] = ['status' => 'SKIPPED', 'note' => 'Redis extension not loaded in CLI environment'];
                }
            } else {
                $checks['redis'] = ['status' => 'SKIPPED', 'note' => 'Redis not set as primary cache/queue driver'];
            }
        } catch (\Throwable $e) {
            $checks['redis'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
            // Only mark degraded if Redis was explicitly required
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                $status = 'degraded';
            }
        }

        // 3. Storage Write Check
        try {
            $testFile = 'health_check_' . time() . '.tmp';
            Storage::disk('local')->put($testFile, 'ok');
            Storage::disk('local')->delete($testFile);
            $checks['storage'] = [
                'status' => 'UP',
                'writable' => true,
            ];
        } catch (\Throwable $e) {
            $status = 'degraded';
            $checks['storage'] = [
                'status' => 'DOWN',
                'error' => $e->getMessage(),
            ];
        }

        // 4. Application Metadata
        $checks['application'] = [
            'name' => config('app.name', 'FAITH AUTOMATION'),
            'env' => config('app.env'),
            'debug' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
            'version' => '1.0.0',
        ];

        $httpCode = $status === 'healthy' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'timestamp' => now()->timestamp,
            'checks' => $checks,
        ], $httpCode);
    }
}
