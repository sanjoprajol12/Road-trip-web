<?php

namespace App\Http\Resources;

use App\Enums\VerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in rider's own account. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'bio' => $this->bio,
            'home_city' => $this->home_city,
            'roles' => $this->staffRoles(),
            'verified' => $this->verifications()->where('status', VerificationStatus::Approved->value)->get(['kind', 'scope'])
                ->map(fn ($v) => ['kind' => $v->kind->value, 'scope' => $v->scope])->values(),
            'suspended_until' => $this->isSuspended() ? $this->suspended_until?->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
