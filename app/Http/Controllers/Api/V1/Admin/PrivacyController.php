<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Accounts\DisposeModerationEvidence;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\ModerationEvidence;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff oversight of account deletions (dates and counts, never content) and of the restricted moderation evidence
 * held after a deletion (D-04): list without content, every view audited, early release, no extension.
 */
class PrivacyController extends Controller
{
    public function deletions(): JsonResponse
    {
        $rows = AccountDeletionRequest::latest('requested_at')->limit(200)->get()->map(fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'requested_at' => $r->requested_at->toIso8601String(), 'run_after' => $r->run_after->toIso8601String(),
            'cancelled_at' => $r->cancelled_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            'counts' => (object) ($r->counts ?? []), 'attempts' => $r->attempts, 'last_error' => $r->last_error,
        ]);

        return response()->json(['data' => $rows, 'file_retries' => DB::table('pending_file_deletions')->where('attempts', '>', 0)->count()]);
    }

    public function evidence(): JsonResponse
    {
        $rows = ModerationEvidence::latest()->limit(200)->get()->map(fn ($e) => $this->meta($e));

        return response()->json(['data' => $rows]);
    }

    public function showEvidence(ModerationEvidence $evidence): JsonResponse
    {
        abort_if($evidence->disposed_at, 410, 'This evidence has been removed.');
        Audit::record('moderation.evidence_viewed', $evidence, null, ['reason' => $evidence->reason]);

        return response()->json(['data' => $this->meta($evidence) + [
            'snapshot' => $evidence->snapshot,
            'files' => collect($evidence->files ?? [])->keys()->map(fn ($i) => route('api.admin.evidence.file', [$evidence->id, $i], false))->values(),
        ]]);
    }

    public function file(ModerationEvidence $evidence, int $index): StreamedResponse
    {
        abort_if($evidence->disposed_at, 410, 'This evidence has been removed.');
        $f = ($evidence->files ?? [])[$index] ?? abort(404);
        Audit::record('moderation.evidence_viewed', $evidence, null, ['reason' => $evidence->reason, 'file' => $index]);

        return Storage::disk($f['disk'])->response($f['path'], null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'no-store, private']);
    }

    /** Remove it now, because it is no longer needed. The 30-day limit cannot be extended. */
    public function release(ModerationEvidence $evidence, DisposeModerationEvidence $action): JsonResponse
    {
        abort_if($evidence->disposed_at, 422, 'This evidence has already been removed.');
        $action->dispose($evidence, 'released');
        \App\Support\PendingFiles::flush();

        return response()->json(['data' => $this->meta($evidence->refresh())]);
    }

    private function meta(ModerationEvidence $e): array
    {
        return ['id' => $e->id, 'contribution_id' => $e->contribution_id, 'reason' => $e->reason, 'created_at' => $e->created_at?->toIso8601String(),
            'held_until' => $e->held_until->toIso8601String(), 'disposition' => $e->disposition, 'disposed_at' => $e->disposed_at?->toIso8601String()];
    }
}
