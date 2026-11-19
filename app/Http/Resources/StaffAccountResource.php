<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in staff member's own staff account. */
class StaffAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'roles' => $this->staffRoles(),
            // What the staff portal may show; the API still checks every permission itself.
            'permissions' => $this->permissions(),
            'rider_linked' => $this->rider_user_id !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
