<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Verification\ApplyForVerification;
use App\Enums\ExpertScope;
use App\Enums\VerificationKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\VerificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VerificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return VerificationResource::collection($request->user()->verifications()->latest()->get());
    }

    public function store(Request $request, ApplyForVerification $apply): VerificationResource
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn ($k) => $k->value, VerificationKind::applicable()))],
            'scope' => ['nullable', 'required_if:kind,expert_reviewer', 'prohibited_unless:kind,expert_reviewer', Rule::enum(ExpertScope::class)],
            'statement' => ['required', 'string', 'min:20', 'max:2000'],
        ], ['statement.min' => 'Write at least 20 characters.']);

        return new VerificationResource($apply($request->user(), VerificationKind::from($data['kind']), $data['scope'] ?? '', $data['statement']));
    }
}
