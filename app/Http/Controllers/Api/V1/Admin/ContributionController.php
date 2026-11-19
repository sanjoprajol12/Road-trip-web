<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Contributions\ModerateContribution;
use App\Enums\ContributionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionReport;
use App\Models\StaffAccount;
use App\Models\User;
use App\Notifications\ContributionCorrectionRequested;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Moderation queue. Staff change status and add notes; they never edit what the rider wrote. */
class ContributionController extends Controller
{
    private const WITH = ['author.verifications', 'destination', 'route', 'segment', 'media', 'sources'];

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([...array_map(fn ($s) => $s->value, ContributionStatus::cases()), 'all', 'corrections'])]])['status'] ?? 'pending_review';
        $open = fn ($r) => $r->whereNull('resolved_at');
        $q = Contribution::with([...self::WITH, 'reports' => $open])
            ->withCount(['reports as open_reports' => $open])
            ->withExists(['reports as dangerous' => fn ($r) => $r->whereNull('resolved_at')->where('category', 'dangerous')])
            // Dangerous reports first, then the most reported, then oldest first.
            ->orderByDesc('dangerous')->orderByDesc('open_reports')->orderBy('created_at');
        match ($status) {
            'all' => null,
            'corrections' => $q->whereNotNull('author_corrected_at')->whereNull('author_correction_reviewed_at'),
            default => $q->where('status', $status),
        };
        $page = $q->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Contribution $c) => $this->row($request, $c)),
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
            'source_title' => ['nullable', 'string', 'min:3', 'max:200'],
        ]);

        return new ContributionResource($moderate($contribution, ContributionStatus::from($data['status']), $data['moderation_note'] ?? null, $data['source_url'] ?? null, $data['source_title'] ?? null)
            ->load(self::WITH));
    }

    /** Ask the author to add a correction. The status does not change; the author is told in the app. */
    public function requestCorrection(Request $request, Contribution $contribution): JsonResponse
    {
        $this->authorize('moderate', $contribution);
        if ($contribution->status === ContributionStatus::Rejected) {
            throw ValidationException::withMessages(['note' => ['Rejected contributions cannot be corrected; the author can submit again.']]);
        }
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($contribution, $data) {
            $old = $contribution->only(['correction_request']);
            $contribution->forceFill(['correction_request' => trim($data['note']), 'correction_requested_at' => now()])->save();
            Audit::record('contribution.correction_requested', $contribution, $old, $contribution->only(['correction_request', 'author_id']));
        });
        $contribution->author?->notify(new ContributionCorrectionRequested($contribution));

        return response()->json(['message' => 'The author was asked for a correction.']);
    }

    /** Accept the author's addendum (shown publicly under the report) or decline it (hidden; the text stays in the audit log). */
    public function reviewCorrection(Request $request, Contribution $contribution): JsonResponse
    {
        $this->authorize('moderate', $contribution);
        $accept = $request->validate(['accept' => ['required', 'boolean']])['accept'];
        if (! $contribution->hasCorrectionToReview()) {
            throw ValidationException::withMessages(['accept' => ['There is no correction waiting for review.']]);
        }
        DB::transaction(function () use ($contribution, $accept) {
            $old = $contribution->only(['author_correction']);
            $contribution->forceFill($accept
                ? ['author_correction_reviewed_at' => now(), 'correction_request' => null, 'correction_requested_at' => null]
                : ['author_correction' => null, 'author_corrected_at' => null])->save();
            Audit::record($accept ? 'contribution.correction_accepted' : 'contribution.correction_declined', $contribution, $old, ['author_id' => $contribution->author_id]);
        });

        return response()->json(['message' => $accept ? 'Correction published.' : 'Correction declined.']);
    }

    /** Close one report as unfounded without changing the contribution. */
    public function dismissReport(Request $request, Contribution $contribution, ContributionReport $report): JsonResponse
    {
        $this->authorize('moderate', $contribution);
        abort_unless($report->contribution_id === $contribution->id && $report->resolved_at === null, 404);
        DB::transaction(function () use ($request, $report, $contribution) {
            $report->forceFill(['resolved_at' => now(), 'resolved_by_staff_id' => $request->user()->id, 'resolution' => 'dismissed'])->save();
            Audit::record('contribution.report_dismissed', $contribution, null, ['report_id' => $report->id, 'category' => $report->category]);
        });

        return response()->json(null, 204);
    }

    /** A moderator checked the report against a current source: it is still accurate today. */
    public function confirm(Request $request, Contribution $contribution): JsonResponse
    {
        $this->authorize('moderate', $contribution);
        abort_unless($contribution->isVisible(), 422, 'Only published contributions can be confirmed.');
        DB::transaction(function () use ($contribution) {
            $old = $contribution->only(['last_confirmed_on']);
            $contribution->forceFill(['last_confirmed_on' => today()])->save();
            Audit::record('contribution.confirmed', $contribution, ['last_confirmed_on' => $old['last_confirmed_on']?->toDateString()], ['last_confirmed_on' => today()->toDateString()]);
        });

        return response()->json(['last_confirmed_on' => today()->toDateString()]);
    }

    /** Everything that happened to one contribution, from the audit log. Visible to moderators, never to riders. */
    public function history(Contribution $contribution): JsonResponse
    {
        $rows = AuditLog::where('subject_type', 'contributions')->where('subject_id', $contribution->id)->orderBy('created_at')->orderBy('id')->get();
        $staff = StaffAccount::whereIn('id', $rows->where('actor_type', 'staff')->pluck('actor_id'))->pluck('display_name', 'id');
        $riders = User::whereIn('id', $rows->where('actor_type', 'user')->pluck('actor_id'))->pluck('display_name', 'id');

        return response()->json(['data' => $rows->map(fn (AuditLog $a) => [
            'action' => $a->action,
            'at' => $a->created_at?->toIso8601String(),
            'actor' => $a->actor_id ? (($a->actor_type === 'staff' ? $staff : $riders)[$a->actor_id] ?? 'Removed account') : 'System',
            'actor_type' => $a->actor_type,
            'note' => $a->new_data['moderation_note'] ?? $a->new_data['correction_request'] ?? $a->new_data['reason'] ?? null,
        ])->values()]);
    }

    private function row(Request $request, Contribution $c): array
    {
        return (new ContributionResource($c))->toArray($request) + [
            'open_reports' => $c->open_reports,
            'dangerous' => (bool) $c->dangerous,
            'next_statuses' => array_map(fn ($s) => $s->value, $c->status->next()),
            'reports' => $c->reports->map(fn (ContributionReport $r) => ['id' => $r->id, 'category' => $r->category, 'reason' => $r->reason, 'created_at' => $r->created_at?->toIso8601String()])->values(),
        ];
    }
}
