<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Liveness plus a real database round trip. Reveals no versions or configuration. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
            $db = 'unavailable';
        }

        return response()->json(['status' => $db === 'ok' ? 'ok' : 'degraded', 'database' => $db], $db === 'ok' ? 200 : 503);
    }
}
