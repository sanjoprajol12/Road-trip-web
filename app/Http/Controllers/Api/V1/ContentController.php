<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CmsEntry;
use App\Support\CmsContent;
use App\Support\PreviewToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public CMS reads: lists (FAQs, team, categories, tags, authors, posts), details (pages, posts, authors) and active
 * popups/banners. Only live content; a staff preview token (X-Preview-Token) also shows drafts on detail pages.
 */
class ContentController extends Controller
{
    public function index(Request $request, string $type): JsonResponse
    {
        $t = CmsContent::type($type);
        abort_unless(in_array($t['public'], ['list', 'detail', 'active'], true) && $type !== 'page', 404);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:100'], 'tag' => ['nullable', 'string', 'max:100'],
            'author' => ['nullable', 'string', 'max:100'], 'placement' => ['nullable', 'in:home,blog'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = CmsEntry::live()->where('type', $type);
        if ($type === 'post') {
            foreach (['category' => 'blog_category', 'author' => 'author'] as $param => $refType) {
                if ($slug = $f[$param] ?? null) {
                    $id = CmsEntry::live()->where(['type' => $refType, 'slug' => $slug])->value('id');
                    $q->whereRaw("data->>'{$param}_id' = ?", [$id ?? '']);
                }
            }
            if ($slug = $f['tag'] ?? null) {
                $id = CmsEntry::live()->where(['type' => 'blog_tag', 'slug' => $slug])->value('id');
                $q->whereRaw("jsonb_exists(coalesce(data->'tag_ids', '[]'::jsonb), ?)", [$id ?? '']);
            }
            if ($s = trim($f['q'] ?? '')) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->whereLike('title', $like)->orWhereLike('summary', $like)->orWhereLike('body', $like));
            }
            $page = $q->orderByRaw('coalesce(publish_at, published_at) desc')->paginate(12);
            $refs = CmsContent::refsFor($page->items());

            return response()->json(['data' => collect($page->items())->map(fn ($e) => array_merge(CmsContent::toPublic($e, $refs), ['body' => null])),
                'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
        }
        $q->when($type === 'banner' && ($f['placement'] ?? null), fn ($q) => $q->whereRaw("data->>'placement' = ?", [$f['placement']]));

        return response()->json(['data' => $q->orderBy('position')->orderBy('title')->limit(200)->get()->map(fn ($e) => CmsContent::toPublic($e))],
            200, ['Cache-Control' => 'public, max-age=60']);
    }

    public function show(Request $request, string $type, string $slug): JsonResponse
    {
        abort_unless(in_array($type, ['page', 'post', 'author'], true), 404);
        $preview = PreviewToken::verify($request->header(PreviewToken::HEADER)) !== null;
        $e = ($preview ? CmsEntry::where('status', '!=', 'archived') : CmsEntry::live())->where(['type' => $type, 'slug' => $slug])->firstOrFail();

        return response()->json(['data' => CmsContent::toPublic($e, $type === 'post' ? CmsContent::refsFor([$e]) : null), 'preview' => $preview],
            200, ['Cache-Control' => $preview ? 'no-store' : 'public, max-age=60']);
    }
}
