<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Contributions\ModerateContribution;
use App\Enums\ContributionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Moderation queue. Staff change status and add notes; they never edit what the rider wrote. */
class ContributionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([...array_map(fn ($s) => $s->value, ContributionStatus::cases()), 'all'])]])['status'] ?? 'pending_review';
        $q = Contribution::with(['author.verifications', 'destination', 'route', 'segment', 'media'])->withCount(['reports as open_reports' => fn ($r) => $r->whereNull('resolved_at')])->orderBy('created_at');
        $q->when($status !== 'all', fn ($q) => $q->where('status', $status));
        $page = $q->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => (new ContributionResource($c))->toArray($request) + [
                'open_reports' => $c->open_reports,
                'reports' => $c->reports()->whereNull('resolved_at')->get(['reason', 'created_at']),
            ]),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function decide(Request $request, Contribution $contribution, ModerateContribution $moderate): ContributionResource
    {
        $this->authorize('moderate', $contribution);
        $data = $request->validate([
            'status' => ['required', Rule::in(['published', 'community_corroborated', 'officially_sourced', 'outdated', 'rejected', 'flagged'])],
            'moderation_note' => ['nullable', 'string', 'max:2000'],
            'source_url' => ['nullable', 'url:https,http', 'max:500'],
        ]);

        return new ContributionResource($moderate($contribution, ContributionStatus::from($data['status']), $data['moderation_note'] ?? null, $data['source_url'] ?? null)
            ->load(['author.verifications', 'destination', 'route', 'segment', 'media']));
    }
}
