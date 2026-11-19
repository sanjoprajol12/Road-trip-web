<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'province' => $this->province,
            'district' => $this->district,
            'summary' => $this->summary,
            'overview' => $this->overview,
            'elevation_m' => $this->elevation_m,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'photo_path' => $this->photo_path,
            'photo_alt' => $this->photo_alt,
            'photo_credit' => $this->photo_credit,
            'status' => $this->status->value,
            'last_confirmed_on' => $this->last_confirmed_on?->toDateString(),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map->toPublic()->values()),
            'is_dev_seed' => $this->is_dev_seed,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
