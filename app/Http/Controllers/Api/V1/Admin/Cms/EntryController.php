<?php

namespace App\Http\Controllers\Api\V1\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Models\CmsEntry;
use App\Support\Audit;
use App\Support\CmsContent;
use App\Support\MediaUsages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Staff CRUD for every config/cms.php type. Each type's own permission manages drafts; publishing, scheduling and
 * archiving also need cms.content.publish. Published items are archived, not deleted; drafts and archived items can be.
 */
class EntryController extends Controller
{
    /** Type descriptors for the staff forms, limited to the types this person may manage. */
    public function types(Request $request): JsonResponse
    {
        $staff = $request->user();

        return response()->json(['data' => collect(config('cms.types'))->filter(fn ($t) => $staff->hasPermission($t['permission']))
            ->map(fn ($t, $key) => Arr::only($t, ['label', 'singular', 'core', 'fields', 'public', 'title_label', 'body_label', 'summary_label', 'body_required']) + ['type' => $key])
            ->values(), 'can_publish' => $staff->hasPermission('cms.content.publish')]);
    }

    public function index(Request $request): JsonResponse
    {
        $type = $request->route('type');
        $this->can($request, $type);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:draft,published,archived']]);
        $q = CmsEntry::where('type', $type)->orderBy('position')->orderByDesc('updated_at');
        $q->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('title', $like)->orWhereLike('summary', $like)->orWhereLike('slug', $like));
        }
        $page = $q->paginate(25);

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->route('type');
        $this->can($request, $type);
        $data = $this->validated($request, $type);
        $e = DB::transaction(function () use ($request, $type, $data) {
            $e = new CmsEntry($data + ['type' => $type]);
            $this->save($request, $e);
            Audit::record("cms.{$type}.created", 'cms_entries', null, Arr::only($data, ['title', 'status', 'slug']), $e->id);

            return $e;
        });

        return response()->json(['data' => $e->refresh()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $type = $request->route('type');
        $this->can($request, $type);
        $e = CmsEntry::where('type', $type)->findOrFail($id);
        $data = $this->validated($request, $type, $e);
        DB::transaction(function () use ($request, $type, $e, $data) {
            $old = $e->only(['title', 'status', 'slug', 'publish_at', 'expires_at']);
            $e->fill($data);
            $this->save($request, $e);
            Audit::record("cms.{$type}.".($old['status'] !== $e->status ? $e->status : 'updated'), 'cms_entries', $old, Arr::only($data, ['title', 'status', 'slug', 'publish_at', 'expires_at']), $e->id);
        });

        return response()->json(['data' => $e->refresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $type = $request->route('type');
        $this->can($request, $type);
        $e = CmsEntry::where('type', $type)->findOrFail($id);
        abort_if($e->status === 'published', 422, 'Archive published content before deleting it.');
        abort_if(in_array($type, ['blog_category', 'blog_tag', 'author'], true) && $this->isReferenced($e), 422, 'Posts still use this. Remove it from them first.');
        DB::transaction(function () use ($e, $type) {
            MediaUsages::forget('cms_entries', $e->id);
            $e->delete();
            Audit::record("cms.{$type}.deleted", 'cms_entries', $e->only(['title', 'slug', 'status']), null, $e->id);
        });

        return response()->json(null, 204);
    }

    private function can(Request $request, string $type): void
    {
        abort_unless($request->user()->hasPermission(CmsContent::type($type)['permission']), 403);
    }

    private function validated(Request $request, string $type, ?CmsEntry $e = null): array
    {
        $data = $request->validate(CmsContent::rules($type, $e));
        // Draft editing is the module permission; anything that makes or keeps content public needs the publish one.
        $publishing = $data['status'] !== 'draft' || ($e && $e->status !== 'draft');
        abort_if($publishing && ! $request->user()->hasPermission('cms.content.publish'), 403, 'Publishing needs the cms.content.publish permission.');
        $data['data'] = Arr::only($data['data'] ?? [], array_column(CmsContent::type($type)['fields'], 'key'));

        return $data;
    }

    private function save(Request $request, CmsEntry $e): void
    {
        $e->updated_by_staff_id = $request->user()->id;
        if ($e->status === 'published' && ! $e->published_at) {
            $e->published_at = now();
        }
        $e->save();
        MediaUsages::sync('cms_entries', $e->id, ['media' => $e->media_asset_id]);
    }

    private function isReferenced(CmsEntry $e): bool
    {
        return CmsEntry::where('type', 'post')->where(fn ($w) => $w->whereRaw("data->>'category_id' = ?", [$e->id])
            ->orWhereRaw("data->>'author_id' = ?", [$e->id])->orWhereRaw("jsonb_exists(coalesce(data->'tag_ids', '[]'::jsonb), ?)", [$e->id]))->exists();
    }
}
