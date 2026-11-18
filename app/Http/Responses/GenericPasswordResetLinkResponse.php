<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/** Same answer for known and unknown addresses, so the endpoint cannot be used to find accounts. */
class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public string $status = '') {}

    public function toResponse($request)
    {
        return response()->json(['message' => 'If an account uses that address, a reset link is on its way.']);
    }
}
