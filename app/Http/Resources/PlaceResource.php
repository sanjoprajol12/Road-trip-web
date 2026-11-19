<?php

namespace App\Http\Resources;

use App\Models\StaffAccount;
use App\Support\HomeContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        // Status and the moderator's note are for staff and for the rider who suggested the place.
        $insider = $user instanceof StaffAccount || ($user && $user->id === $this->submitted_by);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'summary' => $this->summary,
            'description' => $this->description,
            'position' => [$this->latitude, $this->longitude],
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['slug' => $this->destination->slug, 'name' => $this->destination->name, 'published' => $this->destination->status->value === 'published'] : null),
            'image' => $this->media_asset_id ? HomeContent::mediaOut($this->media_asset_id) : null,
            'gallery' => collect($this->gallery ?? [])->map(fn ($id) => HomeContent::mediaOut($id))->filter()->values(),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map->toPublic()->values()),
            'observed_on' => $this->observed_on?->toDateString(),
            'last_confirmed_on' => $this->last_confirmed_on?->toDateString(),
            'published_at' => $this->published_at?->toIso8601String(),
            'suggested_by_rider' => $this->submitted_by !== null,
            'status' => $this->when($insider, $this->status),
            'moderation_note' => $this->when($insider, $this->moderation_note),
            'is_dev_seed' => $this->is_dev_seed,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
