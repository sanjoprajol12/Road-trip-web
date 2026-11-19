<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $full = $this->relationLoaded('stops');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'motorcycle' => $this->motorcycle,
            'notes' => $this->notes,
            'my_role' => $this->roleOf($request->user())?->value,
            'member_count' => $this->members()->count(),
            'members' => $this->when($full, fn () => $this->members->map(fn ($m) => [
                'id' => $m->id, 'display_name' => $m->display_name, 'username' => $m->username, 'role' => $m->pivot->role,
            ])->values()),
            // Owner only: invitations still waiting for an answer (names of riders who have not agreed to anything yet).
            'invitations' => $this->when($full && $this->roleOf($request->user())?->value === 'owner', fn () => \Illuminate\Support\Facades\DB::table('trip_invitations as i')
                ->join('users as u', 'u.id', '=', 'i.user_id')->where('i.trip_id', $this->id)->where('i.status', 'pending')
                ->orderBy('i.created_at')->get(['i.id', 'i.role', 'i.expires_at', 'u.display_name', 'u.username'])
                ->map(fn ($i) => (array) $i + ['expired' => now()->greaterThan($i->expires_at)])),
            'stops' => $this->when($full, fn () => $this->stops),
            'expenses' => $this->when($full, fn () => $this->expenses),
            'expense_total_npr' => $this->when($full, fn () => round($this->expenses->sum('amount_npr'), 2)),
            'checklist' => $this->when($full, fn () => $this->checklist),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
