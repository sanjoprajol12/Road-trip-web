<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\TransferOwnership;
use App\Http\Controllers\Controller;
use App\Models\OwnershipTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Handing over events and group trips during an account deletion; the recipient must accept. */
class OwnershipTransferController extends Controller
{
    public function offer(Request $request, TransferOwnership $action): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:event,trip'],
            'subject_id' => ['required', 'uuid'],
            'username' => ['required', 'string', 'max:30'],
        ]);
        $t = $action->offer($request->user(), $data['subject_type'], $data['subject_id'], $data['username']);

        return response()->json(['data' => ['id' => $t->id, 'status' => $t->status], 'message' => 'Offer sent. Nothing changes unless they accept.'], 201);
    }

    public function withdraw(Request $request, string $offer, TransferOwnership $action): JsonResponse
    {
        $action->withdraw($request->user(), $this->find($offer));

        return response()->json(null, 204);
    }

    /** Offers waiting for this rider's answer. */
    public function incoming(Request $request): JsonResponse
    {
        $rows = OwnershipTransfer::where(['to_user_id' => $request->user()->id, 'status' => 'offered'])->with('from:id,display_name,username')->latest()->get()
            ->map(fn (OwnershipTransfer $t) => ['id' => $t->id, 'subject_type' => $t->subject_type, 'title' => $t->subject()?->title,
                'from' => ['display_name' => $t->from->display_name], 'offered_at' => $t->created_at->toIso8601String()])->filter(fn ($r) => $r['title'] !== null)->values();

        return response()->json(['data' => $rows]);
    }

    public function respond(Request $request, string $offer, TransferOwnership $action): JsonResponse
    {
        $accept = $request->route('answer') === 'accept';
        $action->answer($request->user(), $this->find($offer), $accept);

        return response()->json(['status' => $accept ? 'accepted' : 'declined', 'message' => $accept ? 'It is yours now.' : 'Declined.']);
    }

    private function find(string $id): OwnershipTransfer
    {
        abort_unless(Str::isUuid($id), 404);

        return OwnershipTransfer::findOrFail($id);
    }
}
