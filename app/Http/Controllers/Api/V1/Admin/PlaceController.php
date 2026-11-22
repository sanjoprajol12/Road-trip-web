<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\PlaceController as PublicPlaces;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Models\AuditLog;
use App\Models\Place;
use App\Models\PlaceCorrection;
use App\Models\StaffAccount;
use App\Models\User;
use App\Support\Audit;
use App\Support\MediaUsages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff management of places. places.manage edits drafts; places.publish publishes, unpublishes and decides rider
 * suggestions and corrections. Nobody reviews a suggestion from their own linked rider account.
 */
class PlaceController extends Controller
{
    private const FIELDS = ['slug', 'name', 'category', 'summary', 'description', 'latitude', 'longitude', 'destination_id', 'media_asset_id', 'gallery', 'observed_on', 'last_confirmed_on'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in(Place::CATEGORIES)],
            'status' => ['nullable', 'in:draft,pending_review,changes_requested,published,rejected,archived'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = Place::with(['destination', 'sources', 'submitter'])->withCount(['corrections as open_corrections' => fn ($c) => $c->where('status', 'pending')])
            ->orderByRaw("status = 'pending_review' desc")->orderByDesc('updated_at');
        $q->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c));
        $q->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('slug', $like)->orWhereLike('summary', $like));
        }
        $page = $q->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Place $p) => (new PlaceResource($p))->resolve($request) + [
                'submitted_by_name' => $p->submitter?->display_name, 'open_corrections' => $p->open_corrections, 'destination_id' => $p->destination_id,
                'media_asset_id' => $p->media_asset_id, 'gallery_ids' => $p->gallery ?? [],
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'pending' => Place::where('status', 'pending_review')->count(), 'open_corrections' => PlaceCorrection::where('status', 'pending')->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(Place::rules(true));
        PublicPlaces::refuseDuplicate($data, null, (bool) ($data['allow_duplicate'] ?? false));
        $place = DB::transaction(function () use ($request, $data) {
            $place = new Place(Arr::only($data, self::FIELDS));
            $place->slug = PublicPlaces::uniqueSlug($data['name'], $data['slug'] ?? null);
            $place->forceFill(['status' => 'draft', 'created_by_staff_id' => $request->user()->id])->save();
            $place->syncSources($data['sources'] ?? []);
            $this->syncMedia($place);
            Audit::record('place.created', $place, null, $place->only(['name', 'category', 'status']));

            return $place;
        });

        return (new PlaceResource($place->load(['destination', 'sources'])))->response()->setStatusCode(201);
    }

    /** Editing published content changes what riders see, so it needs places.publish too. */
    public function update(Request $request, Place $place): PlaceResource
    {
        abort_if($place->status === 'published' && ! $request->user()->hasPermission('places.publish'), 403, 'Editing a published place needs the places.publish permission.');
        $data = $request->validate(Place::rules(true, $place));
        PublicPlaces::refuseDuplicate($data, $place, (bool) ($data['allow_duplicate'] ?? false));
        DB::transaction(function () use ($place, $data) {
            $old = $place->only(['name', 'category', 'latitude', 'longitude', 'description', 'last_confirmed_on']);
            $place->fill(Arr::only($data, self::FIELDS));
            if (! empty($data['slug'])) {
                $place->slug = $data['slug'];
            }
            $place->save();
            if (array_key_exists('sources', $data)) {
                $place->syncSources($data['sources']);
            }
            $this->syncMedia($place);
            Audit::record('place.updated', $place, $old, $place->only(['name', 'category', 'latitude', 'longitude', 'description', 'last_confirmed_on']));
        });

        return new PlaceResource($place->load(['destination', 'sources']));
    }

    /**
     * publish | unpublish | request_changes | reject | archive. Reasons are required where the rider must understand
     * the decision. Rider suggestions: request_changes and reject notify the rider; publish notifies them too.
     */
    /** Lifecycle decisions (places.publish): publish, unpublish, request changes, reject, archive. */
    public function decide(Request $request, Place $place, \App\Actions\Places\DecidePlace $decide): PlaceResource
    {
        $data = $request->validate(['decision' => ['required', 'in:publish,unpublish,request_changes,reject,archive'], 'note' => ['nullable', 'string', 'max:2000']]);

        return new PlaceResource($decide($request->user(), $place, $data['decision'], $data['note'] ?? null, false)->load(['destination', 'sources']));
    }

    /** Everything that happened to one place, from the audit log (staff only). */
    public function history(Place $place): JsonResponse
    {
        $rows = AuditLog::where('subject_type', 'places')->where('subject_id', $place->id)->orderBy('created_at')->orderBy('id')->get();
        $staff = StaffAccount::whereIn('id', $rows->where('actor_type', 'staff')->pluck('actor_id'))->pluck('display_name', 'id');
        $riders = User::whereIn('id', $rows->where('actor_type', 'user')->pluck('actor_id'))->pluck('display_name', 'id');

        return response()->json(['data' => $rows->map(fn (AuditLog $a) => [
            'action' => $a->action, 'at' => $a->created_at?->toIso8601String(), 'actor_type' => $a->actor_type,
            'actor' => $a->actor_id ? (($a->actor_type === 'staff' ? $staff : $riders)[$a->actor_id] ?? 'Removed account') : 'System',
            'note' => $a->new_data['note'] ?? $a->new_data['moderation_note'] ?? null,
        ])->values()]);
    }

    public function corrections(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending,accepted,rejected']])['status'] ?? 'pending';
        $rows = PlaceCorrection::with(['place', 'user'])->where('status', $status)->orderBy('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (PlaceCorrection $c) => [
            'id' => $c->id, 'body' => $c->body, 'position' => $c->latitude !== null ? [$c->latitude, $c->longitude] : null, 'status' => $c->status,
            'review_note' => $c->review_note, 'created_at' => $c->created_at?->toIso8601String(), 'rider' => $c->user?->display_name,
            'place' => ['id' => $c->place->id, 'slug' => $c->place->slug, 'name' => $c->place->name, 'position' => [$c->place->latitude, $c->place->longitude]],
        ])]);
    }

    /** Accepting records the decision; the staff member then edits the place. Nothing is rewritten automatically. */
    public function decideCorrection(Request $request, PlaceCorrection $correction): JsonResponse
    {
        if ($request->user()->isSamePersonAs($correction->user_id)) {
            throw ValidationException::withMessages(['decision' => ['This correction comes from your own rider account.']]);
        }
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'note' => ['required', 'string', 'min:5', 'max:1000']]);
        $correction = DB::transaction(function () use ($request, $correction, $data) {
            $correction = PlaceCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            abort_unless($correction->status === 'pending', 422, 'This correction was already reviewed.');
            $correction->forceFill(['status' => $data['decision'], 'review_note' => trim($data['note']), 'reviewed_by_staff_id' => $request->user()->id, 'reviewed_at' => now()])->save();
            Audit::record('place.correction_'.$data['decision'], $correction->place, null, ['correction_id' => $correction->id, 'note' => $correction->review_note]);

            return $correction;
        });

        return response()->json(['status' => $correction->status]);
    }

    private function syncMedia(Place $place): void
    {
        MediaUsages::sync('places', $place->id, ['cover' => $place->media_asset_id]);
        MediaUsages::syncFromJson('places', $place->id, json_encode($place->gallery ?? []));
    }
}
