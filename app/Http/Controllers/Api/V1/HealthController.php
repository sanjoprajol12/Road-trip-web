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

        // Uploads paused is not an outage (the site works); monitoring can alert on it. No reason or disk here.
        $uploads = $db === 'ok' && \App\Support\UploadPolicy::status()['allowed'] ? 'enabled' : 'paused';

        return response()->json(['status' => $db === 'ok' ? 'ok' : 'degraded', 'database' => $db, 'uploads' => $uploads], $db === 'ok' ? 200 : 503);
    }
}
