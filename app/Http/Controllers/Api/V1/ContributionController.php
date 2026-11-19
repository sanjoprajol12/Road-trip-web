<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contributions\StoreContributionPhoto;
use App\Enums\ContributionKind;
use App\Enums\ContributionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Models\ContributionMedia;
use App\Models\ContributionReport;
use App\Models\RouteSegment;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContributionController extends Controller
{
    private const WITH = ['author.verifications', 'destination', 'route', 'segment', 'media'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['kind' => ['nullable', Rule::enum(ContributionKind::class)], 'per_page' => ['nullable', 'integer', 'between:1,50']]);
        $q = Contribution::visible()->with(self::WITH)->orderByDesc('observed_on')->orderByDesc('created_at');
        $q->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k));
        $q->when($request->query('destination'), fn ($q, $s) => $q->whereHas('destination', fn ($d) => $d->where('slug', $s)));
        $q->when($request->query('route'), fn ($q, $s) => $q->whereHas('route', fn ($r) => $r->where('slug', $s)));
        $q->when($request->query('segment'), fn ($q, $id) => $q->where('route_segment_id', $id));
        if ($search = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('title', $like)->orWhereLike('body', $like));
        }

        return ContributionResource::collection($q->paginate((int) $request->query('per_page', 20)));
    }

    public function show(Request $request, Contribution $contribution): ContributionResource
    {
        Gate::forUser($request->user())->authorize('view', $contribution);

        return new ContributionResource($contribution->load(self::WITH));
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        return ContributionResource::collection(Contribution::where('author_id', $request->user()->id)->with(self::WITH)->latest()->get());
    }

    public function store(Request $request): ContributionResource
    {
        $data = $this->validated($request);
        $c = DB::transaction(function () use ($request, $data) {
            $c = new Contribution($data);
            $c->author_id = $request->user()->id;
            $c->save();
            Audit::record('contribution.submitted', $c, null, $c->only(['kind', 'status', 'author_id']));

            return $c;
        });

        return new ContributionResource($c->refresh()->load(self::WITH));
    }

    public function update(Request $request, Contribution $contribution): ContributionResource
    {
        $this->authorize('update', $contribution);
        $fields = ['kind', 'title', 'body', 'observed_on', 'destination_id', 'route_id', 'route_segment_id', 'details'];
        DB::transaction(function () use ($request, $contribution, $fields) {
            $old = $contribution->only($fields);
            $contribution->update($this->validated($request));
            // Moderators can see what changed between submission and review.
            $changed = array_keys($contribution->getChanges());
            if (array_diff($changed, ['updated_at'])) {
                Audit::record('contribution.edited', $contribution, array_intersect_key($old, array_flip($changed)), $contribution->only(array_intersect($fields, $changed)));
            }
        });

        return new ContributionResource($contribution->load(self::WITH));
    }

    public function upload(Request $request, Contribution $contribution, StoreContributionPhoto $store): JsonResponse
    {
        $this->authorize('update', $contribution);
        $request->validate([
            'photo' => ['required', 'file', 'max:'.config('mrn.uploads.max_kb')],
            'alt' => ['required', 'string', 'min:5', 'max:200'],
        ], ['alt.required' => 'Describe the photo for people who cannot see it.']);
        if ($contribution->media()->count() >= 6) {
            return response()->json(['message' => 'Up to 6 photos per contribution.'], 422);
        }
        $m = $store($contribution, $request->file('photo'), $request->string('alt'));

        return response()->json(['data' => ['id' => $m->id, 'url' => route('api.media.show', $m->id, false), 'width' => $m->width, 'height' => $m->height, 'alt' => $m->alt]], 201);
    }

    /** Photos are served only when their contribution may be seen by this person. */
    public function media(Request $request, ContributionMedia $media): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $media->contribution);

        return Storage::disk($media->disk)->response($media->path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function report(Request $request, Contribution $contribution): JsonResponse
    {
        $this->authorize('report', $contribution);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($request, $contribution, $data) {
            ContributionReport::firstOrCreate(['contribution_id' => $contribution->id, 'reporter_id' => $request->user()->id], ['reason' => $data['reason']]);
            // Three open reports send the contribution back to moderators.
            if ($contribution->reports()->whereNull('resolved_at')->count() >= 3 && $contribution->isVisible()) {
                $contribution->forceFill(['status' => ContributionStatus::Flagged])->save();
                Audit::record('contribution.flagged', $contribution, null, ['reason' => 'three open reports']);
            }
        });

        return response()->json(['message' => 'Thanks. Moderators will look at it.'], 202);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ContributionKind::class)],
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'body' => ['required', 'string', 'min:20', 'max:10000'],
            'observed_on' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'destination_id' => ['nullable', 'uuid', Rule::exists('destinations', 'id')->where('status', 'published')],
            'route_id' => ['nullable', 'uuid', Rule::exists('routes', 'id')->where('status', 'published')],
            'route_segment_id' => ['nullable', 'required_if:kind,road_report', 'uuid', 'exists:route_segments,id'],
            'details' => ['nullable', 'array:motorcycle,engine_cc,duration_days,expenses_npr,surface,problems,lessons,wish_known,places'],
            'details.motorcycle' => ['nullable', 'string', 'max:120'],
            'details.engine_cc' => ['nullable', 'integer', 'between:50,2500'],
            'details.duration_days' => ['nullable', 'integer', 'between:1,365'],
            'details.expenses_npr' => ['nullable', 'numeric', 'between:0,10000000'],
            'details.surface' => ['nullable', Rule::in(['paved', 'mixed', 'gravel', 'dirt', 'blocked'])],
            'details.problems' => ['nullable', 'string', 'max:2000'],
            'details.lessons' => ['nullable', 'string', 'max:2000'],
            'details.wish_known' => ['nullable', 'string', 'max:2000'],
            'details.places' => ['nullable', 'string', 'max:2000'],
        ], ['observed_on.before_or_equal' => 'The date you rode it cannot be in the future.', 'route_segment_id.required_if' => 'Choose the stretch of road you are reporting on.']);
        if (! empty($data['route_segment_id'])) {
            // The segment decides the route, so a report can never point at two different roads.
            $data['route_id'] = RouteSegment::find($data['route_segment_id'])->route_id;
        }
        $data['details'] = array_filter($data['details'] ?? [], fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
