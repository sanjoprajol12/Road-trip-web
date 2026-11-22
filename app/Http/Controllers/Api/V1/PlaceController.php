<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Http\Resources\PlaceResource;
use App\Models\Contribution;
use App\Models\Destination;
use App\Models\Place;
use App\Models\PlaceCorrection;
use App\Support\Audit;
use App\Support\Geo;
use App\Support\PreviewToken;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Places of interest. Public: published places only (a staff preview token or the rider who suggested it also sees
 * an unpublished one). Riders suggest places and corrections; both wait for staff review.
 */
class PlaceController extends Controller
{
    public const NEAR_KM = ['places' => 10, 'destinations' => 30];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in(Place::CATEGORIES)],
            'destination' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = Place::published()->with('destination')->orderBy('name');
        $q->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c));
        $q->when($f['destination'] ?? null, fn ($q, $s) => $q->whereHas('destination', fn ($d) => $d->where('slug', $s)));
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('summary', $like)->orWhereLike('description', $like));
        }

        return PlaceResource::collection($q->paginate(24));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $place = Place::with(['destination', 'sources'])->where('slug', $slug)->firstOrFail();
        $preview = PreviewToken::verify($request->header(PreviewToken::HEADER)) !== null;
        $own = $request->user() && $request->user()->id === $place->submitted_by;
        abort_unless($place->status === 'published' || $preview || $own, 404);

        return response()->json([
            'data' => (new PlaceResource($place))->resolve($request),
            'nearby' => $place->status === 'published' ? $this->nearby($place) : null,
            'experiences' => ContributionResource::collection(Contribution::visible()->where('place_id', $place->id)->with('author.verifications')->latest('observed_on')->limit(5)->get()),
            'preview' => $preview,
        ]);
    }

    /** Published places and destinations near a place, straight-line (never riding distance). */
    private function nearby(Place $p): array
    {
        $box = function ($q, float $km) use ($p) {
            $dLat = $km / 111.0;
            $dLng = $km / (111.0 * cos(deg2rad($p->latitude)));
            $q->whereBetween('latitude', [$p->latitude - $dLat, $p->latitude + $dLat])->whereBetween('longitude', [$p->longitude - $dLng, $p->longitude + $dLng]);
        };
        $km = fn ($lat, $lng) => round(Geo::haversineKm($p->latitude, $p->longitude, (float) $lat, (float) $lng), 1);
        $places = Place::published()->whereKeyNot($p->id)->tap(fn ($q) => $box($q, self::NEAR_KM['places']))->limit(100)->get()
            ->map(fn (Place $o) => ['slug' => $o->slug, 'name' => $o->name, 'category' => $o->category, 'position' => [$o->latitude, $o->longitude], 'distance_km' => $km($o->latitude, $o->longitude)])
            ->filter(fn ($o) => $o['distance_km'] <= self::NEAR_KM['places'])->sortBy('distance_km')->take(10)->values();
        $destinations = Destination::published()->whereNotNull('latitude')->tap(fn ($q) => $box($q, self::NEAR_KM['destinations']))->limit(100)->get()
            ->map(fn (Destination $d) => ['slug' => $d->slug, 'name' => $d->name, 'position' => [$d->latitude, $d->longitude], 'distance_km' => $km($d->latitude, $d->longitude)])
            ->filter(fn ($d) => $d['distance_km'] <= self::NEAR_KM['destinations'])->sortBy('distance_km')->take(5)->values();

        return ['radius_km' => self::NEAR_KM, 'places' => $places, 'destinations' => $destinations];
    }

    /** The signed-in rider's own suggestions with their status and the moderator's note. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return PlaceResource::collection(Place::where('submitted_by', $request->user()->id)->with('destination')->latest()->get());
    }

    /** A rider suggests a place. It always starts as "waiting for review"; riders never publish. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(Place::rules(false));
        self::refuseDuplicate($data);
        $place = DB::transaction(function () use ($request, $data) {
            $place = new Place($data + ['slug' => self::uniqueSlug($data['name'])]);
            $place->forceFill(['status' => 'pending_review', 'submitted_by' => $request->user()->id])->save();
            Audit::record('place.suggested', $place, null, $place->only(['name', 'category', 'latitude', 'longitude', 'status']));

            return $place;
        });

        return (new PlaceResource($place->load('destination')))->response()->setStatusCode(201);
    }

    /** The rider edits their own suggestion while it waits or after changes were requested; it goes back to review. */
    public function update(Request $request, Place $place): PlaceResource
    {
        abort_unless($place->submitted_by === $request->user()->id, 404);
        abort_unless(in_array($place->status, ['pending_review', 'changes_requested'], true), 403, 'Reviewed suggestions cannot be edited. Suggest a correction instead.');
        $data = $request->validate(Place::rules(false));
        self::refuseDuplicate($data, $place);
        DB::transaction(function () use ($place, $data) {
            $old = $place->only(['name', 'category', 'summary', 'description', 'latitude', 'longitude', 'status']);
            $place->fill($data)->forceFill(['status' => 'pending_review'])->save();
            Audit::record('place.resubmitted', $place, $old, $place->only(['name', 'category', 'summary', 'description', 'latitude', 'longitude', 'status']));
        });

        return new PlaceResource($place->load('destination'));
    }

    /** Any confirmed rider can suggest a correction to a published place; staff decide what to change. */
    public function correct(Request $request, Place $place): JsonResponse
    {
        abort_unless($place->status === 'published', 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.implode(',', Place::LAT)],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.implode(',', Place::LNG)],
        ]);
        if (PlaceCorrection::where(['place_id' => $place->id, 'user_id' => $request->user()->id, 'status' => 'pending'])->exists()) {
            throw ValidationException::withMessages(['body' => ['You already have a correction waiting for review on this place.']]);
        }
        DB::transaction(function () use ($request, $place, $data) {
            $c = PlaceCorrection::create($data + ['place_id' => $place->id, 'user_id' => $request->user()->id]);
            Audit::record('place.correction_suggested', $place, null, ['correction_id' => $c->id]);
        });

        return response()->json(['message' => 'Thanks. A moderator will review your correction.'], 201);
    }

    /**
     * Same category within 150 m, or the same name within 2 km, is treated as the same place. Staff may confirm a real
     * second place with allow_duplicate; riders are pointed at the existing one.
     */
    public static function refuseDuplicate(array $data, ?Place $self = null, bool $allow = false): void
    {
        if ($allow) {
            return;
        }
        $near = Place::whereNotIn('status', ['rejected', 'archived'])->when($self, fn ($q) => $q->whereKeyNot($self->id))
            ->whereBetween('latitude', [$data['latitude'] - 0.02, $data['latitude'] + 0.02])->whereBetween('longitude', [$data['longitude'] - 0.025, $data['longitude'] + 0.025])->get();
        $match = $near->first(function (Place $p) use ($data) {
            $km = Geo::haversineKm($p->latitude, $p->longitude, (float) $data['latitude'], (float) $data['longitude']);

            return ($p->category === $data['category'] && $km <= 0.15) || (Str::lower(trim($p->name)) === Str::lower(trim($data['name'])) && $km <= 2);
        });
        if ($match) {
            $message = "This looks like a place that already exists: {$match->name}.";
            throw new HttpResponseException(response()->json(['message' => $message, 'errors' => ['name' => [$message]],
                'duplicate' => $match->status === 'published' ? ['slug' => $match->slug, 'name' => $match->name] : null], 422));
        }
    }

    public static function uniqueSlug(string $name, ?string $wanted = null): string
    {
        $base = $wanted ?: (Str::slug($name) ?: 'place');
        $slug = $base;
        for ($i = 2; Place::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
