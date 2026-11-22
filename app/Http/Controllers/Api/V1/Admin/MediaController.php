<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Support\Audit;
use App\Support\Images;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The media library. Every image needs alt text, a credit and an allowed licence (owner photo rule). */
class MediaController extends Controller
{
    private function rules(bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'alt' => [$r, 'string', 'min:5', 'max:200'],
            'credit' => [$r, 'string', 'min:2', 'max:200'],
            'license' => [$r, 'in:cc0,public_domain,owner_supplied'],
            'source_url' => ['nullable', 'url:https,http', 'max:500'],
            'place_note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = MediaAsset::withCount('usages')->orderByDesc('created_at');
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('alt', $like)->orWhereLike('credit', $like)->orWhereLike('place_note', $like));
        }
        $page = $q->paginate(40);

        return response()->json(['data' => $page->getCollection()->map(fn (MediaAsset $m) => $m->toApi() + ['usages' => $m->usages_count]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('mrn.uploads.max_kb')]] + $this->rules(),
            ['license.in' => 'Only CC0, public domain or owner-supplied images may be used.']);
        $stored = Images::store($request->file('file'), 'library/'.now()->format('Y/m'), 'file');
        try {
            $m = DB::transaction(function () use ($data, $stored, $request) {
                $m = new MediaAsset($data);
                $m->forceFill(['disk' => $stored['disk'], 'path' => $stored['path'], 'visibility' => 'public', 'mime' => 'image/webp', 'size_bytes' => $stored['bytes_size'],
                    'width' => $stored['width'], 'height' => $stored['height'], 'uploaded_by_staff_id' => $request->user()->id])->save();
                Audit::record('media.uploaded', $m, null, $m->only(['alt', 'credit', 'license']));

                return $m;
            });
        } catch (\Throwable $e) {
            Images::discard($stored); // no file without its row

            throw $e;
        }

        return response()->json(['data' => $m->toApi()], 201);
    }

    public function update(Request $request, MediaAsset $asset): JsonResponse
    {
        $data = $request->validate($this->rules(true), ['license.in' => 'Only CC0, public domain or owner-supplied images may be used.']);
        $old = $asset->only(array_keys($data));
        $asset->update($data);
        Audit::record('media.updated', $asset, $old, $asset->only(array_keys($data)));

        return response()->json(['data' => $asset->toApi()]);
    }

    public function destroy(MediaAsset $asset): JsonResponse
    {
        if ($n = $asset->usages()->count()) {
            throw ValidationException::withMessages(['media' => ["This image is used in {$n} ".($n === 1 ? 'place' : 'places').'. Replace it there first.']]);
        }
        DB::transaction(function () use ($asset) {
            Audit::record('media.deleted', $asset, $asset->only(['alt', 'credit', 'license', 'path']), null);
            $asset->delete();
        });
        Storage::disk($asset->disk)->delete($asset->path);

        return response()->json(null, 204);
    }
}
