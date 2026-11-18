<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $insider = $user && ($user->id === $this->author_id || $user->isStaff());

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'body' => $this->body, // the rider's own words, never edited by staff
            'observed_on' => $this->observed_on?->toDateString(),
            'submitted_at' => $this->created_at?->toIso8601String(),
            'status' => $this->status->value,
            'details' => (object) ($this->details ?? []),
            'source_url' => $this->source_url,
            'moderation_note' => $this->when($insider, $this->moderation_note),
            'author' => $this->whenLoaded('author', fn () => (new PublicUserResource($this->author))->toArray($request)),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['slug' => $this->destination->slug, 'name' => $this->destination->name] : null),
            'route' => $this->whenLoaded('route', fn () => $this->route ? ['slug' => $this->route->slug, 'name' => $this->route->name] : null),
            'segment' => $this->whenLoaded('segment', fn () => $this->segment ? ['id' => $this->segment->id, 'start_name' => $this->segment->start_name, 'end_name' => $this->segment->end_name] : null),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id, 'url' => route('api.media.show', $m->id, false), 'width' => $m->width, 'height' => $m->height, 'alt' => $m->alt,
            ])->values()),
        ];
    }
}
