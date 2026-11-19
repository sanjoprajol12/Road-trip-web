<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\HomeContent;
use App\Support\PreviewToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public CMS reads: the homepage, menus and active notices. Only published content, unless a staff preview token. */
class HomeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $preview = PreviewToken::verify($request->header(PreviewToken::HEADER)) !== null;

        return response()->json(['data' => HomeContent::page($preview), 'preview' => $preview], 200, ['Cache-Control' => $preview ? 'no-store' : 'public, max-age=60']);
    }

    public function menu(string $location): JsonResponse
    {
        abort_unless(in_array($location, config('home.menu_locations'), true), 404);

        return response()->json(['data' => DB::table('menu_items')->where('location', $location)->where('is_visible', true)->orderBy('position')
            ->get(['label', 'target', 'signed_in_only'])->map(fn ($i) => ['label' => $i->label, 'to' => $i->target, 'signed_in_only' => (bool) $i->signed_in_only])]);
    }

    public function notices(): JsonResponse
    {
        $now = now();

        return response()->json(['data' => DB::table('notices')->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderByDesc('created_at')->limit(3)->get(['id', 'body', 'level', 'link_label', 'link_target'])]);
    }
}
