<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mine = $this->registrationOf($request->user());

        return [
            'id' => $this->id,
            'category' => $this->category,
            'subtype' => $this->subtype,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'meeting_point' => $this->meeting_point,
            'visibility' => $this->visibility,
            'join_method' => $this->join_method,
            'capacity' => $this->capacity,
            'waitlist_enabled' => $this->waitlist_enabled,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'participant_count' => $this->approvedCount(),
            'organizer' => (new PublicUserResource($this->organizer))->toArray($request),
            'route' => $this->route ? ['slug' => $this->route->slug, 'name' => $this->route->name] : null,
            'my_registration' => $mine?->status->value,
            'is_organizer' => $request->user()?->id === $this->organizer_id,
            'updates' => $this->whenLoaded('updates', fn () => $this->updates->map(fn ($u) => [
                'id' => $u->id, 'kind' => $u->kind, 'body' => $u->body, 'created_at' => $u->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
