<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/v1/health: "is the API up, and can it reach the database?"
 * Public (no login) and safe: it never reveals error details, versions of PHP/MySQL, or config.
 * The mobile app can call it to tell "server unreachable" apart from "no internet".
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $database = 'connected';
        } catch (Throwable $e) {
            // Details go to the server log for developers, never into the public response.
            Log::error('Health check: database unreachable', ['exception' => $e->getMessage()]);
            $database = 'unreachable';
        }

        $healthy = $database === 'connected';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'version' => 'v1',
            'db' => $database,
            'time' => now()->toIso8601ZuluString(),
        ], $healthy ? 200 : 503);
    }
}
