<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** One route per settings group (routes/api.php), each behind its own settings.<group>.manage permission. */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => Settings::group($request->route('group'))]);
    }

    public function update(Request $request): JsonResponse
    {
        $group = $request->route('group');
        Settings::update($group, $request->except('group'), $request->user());

        return response()->json(['data' => Settings::group($group)]);
    }

    /** Writes, reads back and deletes a small probe file on the configured disk, and reports usage. */
    public function testStorage(): JsonResponse
    {
        $disk = Settings::get('storage.driver');
        $probe = 'probes/'.Str::uuid().'.txt';
        $payload = 'my-ride-nepal storage probe '.now()->toIso8601String();
        try {
            Storage::disk($disk)->put($probe, $payload);
            $ok = Storage::disk($disk)->get($probe) === $payload;
            Storage::disk($disk)->delete($probe);
            $error = $ok ? null : 'The file read back did not match what was written.';
        } catch (Throwable $e) {
            $ok = false;
            $error = 'The storage could not be written or read: '.class_basename($e).'.'; // no paths or credentials
        }
        Audit::record('settings.storage.tested', 'settings', null, ['disk' => $disk, 'ok' => $ok], 'storage');

        return response()->json(['ok' => $ok, 'disk' => $disk, 'error' => $error, 'usage' => [
            'library' => ['files' => MediaAsset::count(), 'bytes' => (int) MediaAsset::sum('size_bytes')],
            'rider_photos' => ['files' => DB::table('contribution_media')->count(), 'bytes' => (int) DB::table('contribution_media')->sum('size_bytes')],
            'by_disk' => DB::table('media_assets')->select('disk', DB::raw('count(*) as files'))->groupBy('disk')->pluck('files', 'disk')
                ->mergeRecursive(DB::table('contribution_media')->select('disk', DB::raw('count(*) as files'))->groupBy('disk')->pluck('files', 'disk')),
        ]], $ok ? 200 : 502);
    }

    public function clearCache(): JsonResponse
    {
        Settings::forget();
        Audit::record('system.cache_cleared', 'settings', null, ['scope' => 'settings'], 'cache');

        return response()->json(['message' => 'Settings cache cleared.']);
    }
}
