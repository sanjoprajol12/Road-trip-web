<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GuideCategory;
use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuideResource;
use App\Models\Guide;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuideController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GuideResource::collection(Guide::orderBy('category')->orderBy('title')->get());
    }

    public function store(Request $request): GuideResource
    {
        $g = new Guide($this->validated($request));
        $g->author_id = $request->user()->id;
        $g->save();
        Audit::record('guide.created', $g, null, $g->only(['title', 'category']));

        return new GuideResource($g->refresh());
    }

    /** Any change to the text clears an earlier expert review: the expert approved the old words. */
    public function update(Request $request, Guide $guide): GuideResource
    {
        $guide->fill($this->validated($request, $guide));
        if ($guide->isDirty(['body', 'sources', 'category'])) {
            $guide->expert_reviewed_by = null;
            $guide->expert_reviewed_at = null;
            if ($guide->needsExpertReview() && $guide->status === PublishStatus::Published) {
                $guide->status = PublishStatus::Draft;
            }
        }
        $guide->save();
        Audit::record('guide.updated', $guide, null, $guide->only(['title', 'status']));

        return new GuideResource($guide);
    }

    public function publish(Guide $guide): GuideResource
    {
        if ($guide->needsExpertReview() && ! $guide->expert_reviewed_at) {
            throw ValidationException::withMessages(['guide' => ['Safety-critical guides need a review by a verified expert in this field before they can be published.']]);
        }
        $guide->forceFill(['status' => PublishStatus::Published])->save();
        Audit::record('guide.published', $guide, null, ['status' => 'published']);

        return new GuideResource($guide);
    }

    public function destroy(Guide $guide): JsonResponse
    {
        $guide->delete();
        Audit::record('guide.deleted', $guide, $guide->only(['title']));

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Guide $g = null): array
    {
        $req = $g ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => [$req, 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('guides')->ignore($g?->id)],
            'title' => [$req, 'string', 'min:5', 'max:140'],
            'category' => [$req, Rule::enum(GuideCategory::class)],
            'body' => [$req, 'string', 'min:50', 'max:30000'],
            'sources' => [$req, 'string', 'min:10', 'max:5000'],
        ], ['sources.required' => 'Cite where this guidance comes from.']);
    }
}
