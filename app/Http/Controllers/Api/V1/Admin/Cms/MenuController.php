<?php

namespace App\Http\Controllers\Api\V1\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Header and footer menus: the whole list is replaced on save (small lists, one audit row). */
class MenuController extends Controller
{
    public function show(string $location): JsonResponse
    {
        abort_unless(in_array($location, config('home.menu_locations'), true), 404);

        return response()->json(['data' => DB::table('menu_items')->where('location', $location)->orderBy('position')->get(['id', 'label', 'target', 'signed_in_only', 'is_visible'])]);
    }

    public function update(Request $request, string $location): JsonResponse
    {
        abort_unless(in_array($location, config('home.menu_locations'), true), 404);
        $items = $request->validate([
            'items' => ['present', 'array', 'max:12'],
            'items.*.label' => ['required', 'string', 'max:40'],
            // Internal paths of the public website, or https links.
            'items.*.target' => ['required', 'string', 'max:300', 'regex:#^(/[a-z0-9\-/]*|https://\S+)$#'],
            'items.*.signed_in_only' => ['boolean'],
            'items.*.is_visible' => ['boolean'],
        ])['items'];
        DB::transaction(function () use ($location, $items) {
            $old = DB::table('menu_items')->where('location', $location)->orderBy('position')->get(['label', 'target'])->toArray();
            DB::table('menu_items')->where('location', $location)->delete();
            foreach (array_values($items) as $i => $it) {
                DB::table('menu_items')->insert(['id' => (string) Str::uuid(), 'location' => $location, 'label' => $it['label'], 'target' => $it['target'], 'position' => $i,
                    'signed_in_only' => $it['signed_in_only'] ?? false, 'is_visible' => $it['is_visible'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record('menu.updated', 'menu_items', ['items' => $old], ['items' => $items], $location);
        });

        return $this->show($location);
    }
}
