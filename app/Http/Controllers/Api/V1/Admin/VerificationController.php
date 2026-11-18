<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Verification\DecideVerification;
use App\Enums\VerificationKind;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...array_map(fn ($s) => $s->value, VerificationStatus::cases()), 'all'])],
            'kind' => ['nullable', Rule::in([...array_map(fn ($k) => $k->value, VerificationKind::cases()), 'all'])],
        ]);
        $status = $data['status'] ?? 'pending';
        $q = Verification::with('user')->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc');
        $q->when($status !== 'all', fn ($q) => $q->where('status', $status));
        $q->when(($data['kind'] ?? 'all') !== 'all', fn ($q) => $q->where('kind', $data['kind']));

        return response()->json($q->paginate(50)->through(fn ($v) => $this->row($v)));
    }

    public function show(Request $request, Verification $claim): JsonResponse
    {
        $history = AuditLog::where('subject_type', 'user_verifications')->where('subject_id', $claim->id)->orderBy('id')->get();
        $names = User::whereIn('id', $history->pluck('actor_id')->filter())->pluck('display_name', 'id');

        return response()->json([
            'data' => $this->row($claim->load('user')) + [
                'statement' => $claim->statement,
                'can_decide' => $request->user()->can('decide', $claim),
                'next' => array_map(fn ($s) => $s->value, $claim->status->next()),
                'applicant' => ['id' => $claim->user->id, 'display_name' => $claim->user->display_name, 'username' => $claim->user->username,
                    'home_city' => $claim->user->home_city, 'bio' => $claim->user->bio, 'joined' => $claim->user->created_at?->toDateString()],
                'other_claims' => $claim->user->verifications()->latest()->get()->map(fn ($v) => ['id' => $v->id, 'kind' => $v->kind->value, 'scope' => $v->scope, 'status' => $v->status->value]),
            ],
            'history' => $history->map(fn ($h) => [
                'id' => $h->id, 'action' => $h->action, 'actor' => $h->actor_id ? ($names[$h->actor_id] ?? 'Deleted account') : null,
                'from' => $h->old_data['status'] ?? null, 'to' => $h->new_data['status'] ?? null,
                'note' => ($h->new_data['review_note'] ?? null) !== ($h->old_data['review_note'] ?? null) ? ($h->new_data['review_note'] ?? null) : null,
                'created_at' => $h->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function decide(Request $request, Verification $claim, DecideVerification $decide): JsonResponse
    {
        $this->authorize('decide', $claim);
        $data = $request->validate(['status' => ['required', Rule::enum(VerificationStatus::class)], 'review_note' => ['nullable', 'string', 'max:2000']]);
        $claim = $decide($request->user(), $claim, VerificationStatus::from($data['status']), $data['review_note'] ?? null);

        return response()->json(['data' => $this->row($claim->load('user'))]);
    }

    private function row(Verification $v): array
    {
        return [
            'id' => $v->id, 'kind' => $v->kind->value, 'scope' => $v->scope, 'status' => $v->status->value,
            'review_note' => $v->review_note, 'reviewed_at' => $v->reviewed_at?->toIso8601String(), 'created_at' => $v->created_at?->toIso8601String(),
            'user' => $v->user ? ['id' => $v->user->id, 'display_name' => $v->user->display_name, 'username' => $v->user->username] : null,
        ];
    }
}
