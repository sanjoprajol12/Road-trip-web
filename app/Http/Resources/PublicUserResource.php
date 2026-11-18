<?php

namespace App\Http\Resources;

use App\Enums\VerificationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What other people may see about a rider: never the email address. */
class PublicUserResource extends JsonResource
{
    /** Include home city and bio (the profile page); lists show only the name and verified claims. */
    public bool $full = false;

    public static function full($user): static
    {
        $r = new static($user);
        $r->full = true;

        return $r;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'home_city' => $this->when($this->full, $this->home_city),
            'bio' => $this->when($this->full, $this->bio),
            'joined' => $this->created_at?->toDateString(),
            // Verification is claim-specific: each approved claim is listed on its own.
            'verified' => $this->verifications->where('status', VerificationStatus::Approved)
                ->map(fn ($v) => ['kind' => $v->kind->value, 'scope' => $v->scope])->values(),
        ];
    }
}
