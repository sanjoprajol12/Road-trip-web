<?php

namespace App\Support;

use App\Http\Resources\ContributionResource;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\RouteResource;
use App\Models\Contribution;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\RideRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Homepage sections: validation against config/home.php and the public, resolved shape. */
final class HomeContent
{
    /** Validated section data: only fields the type defines survive; images and featured items must exist. */
    public static function validate(string $type, array $data): array
    {
        $def = config("home.types.{$type}") ?? throw ValidationException::withMessages(['type' => ['Unknown section type.']]);
        $clean = Validator::make($data, $def['rules'])->validate();

        $errors = [];
        if (isset($clean['background'])) {
            $bg = $clean['background'];
            $ok = $bg['kind'] === 'bundled' ? array_key_exists($bg['ref'], config('home.bundled_photos')) : self::publicMedia($bg['ref']);
            if (! $ok) {
                $errors['background.ref'] = ['Choose a bundled photo or a public image from the media library.'];
            }
        }
        foreach (array_merge(array_column($clean['items'] ?? [], 'media_id'), isset($clean['media_id']) ? [$clean['media_id']] : []) as $id) {
            if ($id && ! self::publicMedia($id)) {
                $errors['media_id'] = ['Every image must be a public image from the media library.'];
            }
        }
        $table = ['featured_destinations' => 'destinations', 'featured_routes' => 'routes', 'featured_experiences' => 'contributions'][$type] ?? null;
        if ($table && ($clean['ids'] ?? []) && DB::table($table)->whereIn('id', $clean['ids'])->count() !== count($clean['ids'])) {
            $errors['ids'] = ['Some featured items do not exist.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private static function publicMedia(string $id): bool
    {
        return MediaAsset::whereKey($id)->where('visibility', 'public')->exists();
    }

    /**
     * The homepage as the public website shows it: published, visible, in-schedule sections in order. With
     * $draft (a valid staff preview token) the current drafts are shown instead, still never archived ones.
     */
    public static function page(bool $draft = false): array
    {
        $now = now();
        $q = DB::table('home_sections')->whereNull('archived_at')
            ->where(fn ($w) => $w->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $now));
        $q = $draft ? $q->where('is_visible', true)->orderBy('position')
            : $q->whereNotNull('published_data')->where('published_visible', true)->orderBy('published_position');

        return $q->get()->map(fn ($s) => ['id' => $s->id, 'type' => $s->type] + self::resolve($s->type, json_decode($draft ? $s->draft_data : $s->published_data, true)))->all();
    }

    /** Adds what the website needs to show a section: image URLs and the featured records (published only). */
    private static function resolve(string $type, array $d): array
    {
        $media = fn (?string $id) => $id ? self::mediaOut($id) : null;
        if ($type === 'hero') {
            $bg = $d['background'];
            $d['background'] = $bg['kind'] === 'bundled'
                ? ['kind' => 'bundled', 'base' => $bg['ref'], 'widths' => config("home.bundled_photos.{$bg['ref']}.widths", [])]
                : ['kind' => 'media'] + ($media($bg['ref']) ?? []);
        }
        if ($type === 'slides') {
            $d['items'] = array_values(array_filter(array_map(fn ($i) => ($m = $media($i['media_id'])) ? ['image' => $m] + $i : null, $d['items'])));
        }
        if ($type === 'banner') {
            $d['image'] = $media($d['media_id'] ?? null);
        }
        $request = request();
        if ($type === 'featured_destinations') {
            $rows = self::ordered(Destination::published(), $d['ids'], 7);
            $d['items'] = DestinationResource::collection($rows)->toArray($request);
        }
        if ($type === 'featured_routes') {
            $rows = self::ordered(RideRoute::published(), $d['ids'], 6);
            $d['items'] = RouteResource::collection($rows)->toArray($request);
        }
        if ($type === 'featured_experiences') {
            $rows = self::ordered(Contribution::visible()->with(['author.verifications', 'destination', 'route']), $d['ids'], 6);
            $d['items'] = ContributionResource::collection($rows)->toArray($request);
        }

        return $d;
    }

    /** Chosen items in the chosen order (unpublished ones drop out), or the newest when none were chosen. */
    private static function ordered($query, array $ids, int $limit)
    {
        if (! $ids) {
            return $query->latest()->limit($limit)->get();
        }

        return $query->whereIn('id', $ids)->get()->sortBy(fn ($r) => array_search($r->id, $ids, true))->values();
    }

    private static function mediaOut(string $id): ?array
    {
        $a = MediaAsset::whereKey($id)->where('visibility', 'public')->first();

        return $a ? ['url' => url("/api/v1/files/{$a->id}"), 'alt' => $a->alt, 'credit' => $a->credit, 'width' => $a->width, 'height' => $a->height] : null;
    }
}
