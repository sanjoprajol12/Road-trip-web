<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuideResource;
use App\Models\Guide;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class GuideController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Guide::where('status', PublishStatus::Published->value)->orderBy('category')->orderBy('title');
        $q->when($request->query('category'), fn ($q, $c) => $q->where('category', $c));

        return GuideResource::collection($q->get());
    }

    public function show(Request $request, string $slug): GuideResource
    {
        $guide = Guide::where('slug', $slug)->firstOrFail();
        Gate::forUser($request->user())->authorize('view', $guide);

        return new GuideResource($guide);
    }

    /** A verified expert in the guide's field confirms the content; staff can then publish it. */
    public function expertReview(Request $request, Guide $guide): GuideResource
    {
        $this->authorize('expertReview', $guide);
        $guide->forceFill(['expert_reviewed_by' => $request->user()->id, 'expert_reviewed_at' => now()])->save();
        Audit::record('guide.expert_reviewed', $guide, null, ['expert_reviewed_by' => $request->user()->id]);

        return new GuideResource($guide);
    }
}
