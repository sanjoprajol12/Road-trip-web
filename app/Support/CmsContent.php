<?php

namespace App\Support;

use App\Models\CmsEntry;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Validation and public shapes for config/cms.php types. */
final class CmsContent
{
    public const LINK = ['nullable', 'string', 'max:300', 'regex:#^(/[a-z0-9\-/]*|https://\S+)$#'];

    public static function type(string $type): array
    {
        return config("cms.types.{$type}") ?? abort(404);
    }

    public static function rules(string $type, ?CmsEntry $entry = null): array
    {
        $t = self::type($type);
        $core = $t['core'];
        $rules = [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'status' => ['required', 'in:draft,published,archived'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
            'position' => ['nullable', 'integer', 'between:0,10000'],
            'data' => ['nullable', 'array'],
        ];
        if (in_array('slug', $core, true)) {
            $rules['slug'] = ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('cms_entries', 'slug')->where('type', $type)->ignore($entry?->id)];
        }
        if (in_array('summary', $core, true)) {
            $rules['summary'] = ['nullable', 'string', 'max:500'];
        }
        if (in_array('body', $core, true)) {
            $rules['body'] = [($t['body_required'] ?? false) ? 'required' : 'nullable', 'string', 'max:50000'];
        }
        if (in_array('media', $core, true)) {
            $rules['media_asset_id'] = ['nullable', 'uuid', Rule::exists('media_assets', 'id')->where('visibility', 'public')];
        }
        if (in_array('seo', $core, true)) {
            $rules['seo_title'] = ['nullable', 'string', 'max:70'];
            $rules['seo_description'] = ['nullable', 'string', 'max:170'];
        }
        foreach ($t['fields'] as $f) {
            $k = 'data.'.$f['key'];
            $req = ($f['required'] ?? false) ? 'required' : 'nullable';
            match ($f['kind']) {
                'text', 'textarea' => $rules[$k] = [$req, 'string', 'max:'.($f['max'] ?? 2000)],
                'number' => $rules[$k] = [$req, 'numeric'],
                'select' => $rules[$k] = [$req, Rule::in(array_keys($f['options']))],
                'link' => [$rules[$k] = ['nullable', 'array:label,to'], $rules["{$k}.label"] = ['nullable', 'required_with:'.$k.'.to', 'string', 'max:30'], $rules["{$k}.to"] = self::LINK],
                'ref' => $rules[$k] = [$req, 'uuid', Rule::exists('cms_entries', 'id')->where('type', $f['ref'])],
                'refs' => [$rules[$k] = ['nullable', 'array', 'max:'.($f['max'] ?? 20)], $rules["{$k}.*"] = ['uuid', 'distinct', Rule::exists('cms_entries', 'id')->where('type', $f['ref'])]],
                'blocks' => [$rules[$k] = ['nullable', 'array', 'max:'.($f['max'] ?? 30)], $rules["{$k}.*"] = ['array:kind,text'],
                    $rules["{$k}.*.kind"] = ['required', 'in:heading,text,callout'], $rules["{$k}.*.text"] = ['required', 'string', 'max:5000']],
            };
        }

        return $rules;
    }

    /** What the public website gets: no staff ids, no draft-only fields. Refs are resolved to {slug, title}. */
    public static function toPublic(CmsEntry $e, ?Collection $refs = null): array
    {
        $data = $e->data ?? [];
        $ref = fn (?string $id) => $id && $refs?->has($id) ? ['id' => $id, 'slug' => $refs[$id]->slug, 'title' => $refs[$id]->title] : null;
        if ($e->type === 'post') {
            $data = ['category' => $ref($data['category_id'] ?? null), 'author' => $ref($data['author_id'] ?? null),
                'tags' => collect($data['tag_ids'] ?? [])->map($ref)->filter()->values()];
        }

        return [
            'id' => $e->id, 'type' => $e->type, 'slug' => $e->slug, 'title' => $e->title, 'summary' => $e->summary, 'body' => $e->body,
            'data' => (object) $data, 'media' => $e->media_asset_id ? HomeContent::mediaOut($e->media_asset_id) : null, 'position' => $e->position,
            'seo_title' => $e->seo_title, 'seo_description' => $e->seo_description,
            'published_at' => ($e->published_at ?? $e->publish_at)?->toIso8601String(), 'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    /** Referenced live entries (categories, tags, authors) for a set of posts, keyed by id. */
    public static function refsFor(iterable $posts): Collection
    {
        $ids = collect($posts)->flatMap(fn (CmsEntry $p) => [($p->data['category_id'] ?? null), ($p->data['author_id'] ?? null), ...($p->data['tag_ids'] ?? [])])->filter()->unique();

        return $ids->isEmpty() ? collect() : CmsEntry::live()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
