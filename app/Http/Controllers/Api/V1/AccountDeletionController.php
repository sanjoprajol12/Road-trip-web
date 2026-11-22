<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\DeletionPlan;
use App\Actions\Accounts\ExportAccountData;
use App\Actions\Accounts\ScheduleAccountDeletion;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest as Req;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The rider's own account deletion (request, status, explicit cancel) and data export. */
class AccountDeletionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();
        $req = Req::where(['user_id' => $u->id, 'status' => 'scheduled'])->first();

        return response()->json(['data' => [
            'request' => $req ? ['requested_at' => $req->requested_at->toIso8601String(), 'run_after' => $req->run_after->toIso8601String(), 'choices' => (object) $req->choices] : null,
            'cooling_off_days' => ScheduleAccountDeletion::COOLING_OFF_DAYS,
        ] + DeletionPlan::summary($u)]);
    }

    public function store(Request $request, ScheduleAccountDeletion $action): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
            'choices' => ['present', 'array'],
            'choices.*' => ['required', Rule::in(Req::CHOICES)],
        ], ['confirm.accepted' => 'Confirm that you understand what happens.', 'choices.*.in' => 'Choose delete, keep anonymously, or keep anonymously without photos.']);
        $this->checkPassword($request);
        $req = $action->request($request->user(), $data['choices'], $request->hasSession() ? $request->session()->getId() : null);

        return response()->json(['data' => ['run_after' => $req->run_after->toIso8601String()], 'message' => 'Your account will be deleted on '.$req->run_after->format('j M Y').'.'], 201);
    }

    /** Only this explicit action cancels; signing in does not. */
    public function cancel(Request $request, ScheduleAccountDeletion $action): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->checkPassword($request);
        $action->cancel($request->user());

        return response()->json(['message' => 'Deletion cancelled. Your account works as before.']);
    }

    /** Streamed once; nothing is stored, so there is no link to share, guess or expire. */
    public function export(Request $request, ExportAccountData $export): BinaryFileResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->checkPassword($request);
        $path = $export->build($request->user());
        Audit::record('account.exported', $request->user());

        return response()->download($path, 'my-ride-nepal-data-'.now()->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    private function checkPassword(Request $request): void
    {
        if (! Hash::check((string) $request->input('password'), $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => ['That password is not correct.']]);
        }
    }
}
