<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $place = fn ($d) => $d ? ['id' => $d->id, 'slug' => $d->slug, 'name' => $d->name] : null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'summary' => $this->summary,
            'start' => $place($this->startDestination),
            'end' => $place($this->endDestination),
            'distance_km' => $this->distance_km,
            'estimated_hours' => $this->estimated_hours,
            'status' => $this->status->value,
            'last_confirmed_on' => $this->last_confirmed_on?->toDateString(),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map->toPublic()->values()),
            'is_dev_seed' => $this->is_dev_seed,
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($s) => [
                'id' => $s->id, 'position' => $s->position, 'start_name' => $s->start_name, 'end_name' => $s->end_name,
                'start' => $s->start_lat !== null ? [$s->start_lat, $s->start_lng] : null,
                'end' => $s->end_lat !== null ? [$s->end_lat, $s->end_lng] : null,
                'distance_km' => $s->distance_km, 'estimated_hours' => $s->estimated_hours, 'surface' => $s->surface, 'notes' => $s->notes,
                'path' => $s->path, // imported track [[lat, lng], ...] or null: only this is drawn as a road line
                'services' => $s->relationLoaded('servicePoints') ? $s->servicePoints->map(fn ($p) => [
                    'id' => $p->id, 'kind' => $p->kind, 'name' => $p->name, 'notes' => $p->notes,
                    'position' => $p->latitude !== null ? [$p->latitude, $p->longitude] : null,
                    'last_confirmed_on' => $p->last_confirmed_on?->toDateString(),
                ])->values() : [],
            ])->values()),
        ];
    }
}
