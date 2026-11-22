<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Places\DecidePlace;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * places.review: the review queue of rider-suggested places only. Read the suggestion and its history, then
 * approve, request changes or reject. No creating, editing, unpublishing or archiving here (that is places.manage
 * and places.publish), and staff-created places are not reachable through these routes.
 */
class PlaceReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending_review,changes_requested,published,rejected']])['status'] ?? 'pending_review';
        $page = Place::whereNotNull('submitted_by')->where('status', $status)->with(['destination', 'submitter'])->orderBy('updated_at')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Place $p) => (new PlaceResource($p))->resolve($request) + ['submitted_by_name' => $p->submitter?->display_name]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'pending' => Place::whereNotNull('submitted_by')->where('status', 'pending_review')->count()],
        ]);
    }

    public function show(Request $request, Place $place, PlaceController $places): JsonResponse
    {
        abort_unless($place->submitted_by, 404);
        $place->load(['destination', 'sources', 'submitter']);

        return response()->json([
            'data' => (new PlaceResource($place))->resolve($request) + ['submitted_by_name' => $place->submitter?->display_name],
            'history' => $places->history($place)->getData(true)['data'],
        ]);
    }

    public function decide(Request $request, Place $place, DecidePlace $decide): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,request_changes,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        $place = $decide($request->user(), $place, $data['decision'], $data['note'] ?? null, true);

        return response()->json(['data' => (new PlaceResource($place->load('destination')))->resolve($request)]);
    }
}
