<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    /** Public site settings for both frontends (name, contact, maintenance, registration). No secrets. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => Settings::public()]);
    }

    /** Library images marked public. Private ones are never served here. */
    public function file(MediaAsset $asset): StreamedResponse
    {
        abort_unless($asset->visibility === 'public', 404);

        return Storage::disk($asset->disk)->response($asset->path, null, ['Content-Type' => $asset->mime, 'Cache-Control' => 'public, max-age=86400']);
    }
}
