<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $insider = $user instanceof \App\Models\StaffAccount ? $user->hasPermission('contributions.moderate') : ($user && $user->id === $this->author_id);

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
            'last_confirmed_on' => $this->last_confirmed_on?->toDateString(),
            // The author's dated addendum: public once a moderator has seen it; the author and moderators see it at once.
            'author_correction' => $insider || $this->author_correction_reviewed_at ? $this->author_correction : null,
            'author_corrected_at' => $insider || $this->author_correction_reviewed_at ? $this->author_corrected_at?->toIso8601String() : null,
            'correction_pending_review' => $this->when($insider, fn () => $this->hasCorrectionToReview()),
            'correction_request' => $this->when($insider, $this->correction_request),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map->toPublic()->values()),
            'author' => $this->whenLoaded('author', fn () => (new PublicUserResource($this->author))->toArray($request)),
            'place' => $this->whenLoaded('place', fn () => $this->place ? ['slug' => $this->place->slug, 'name' => $this->place->name] : null),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination ? ['slug' => $this->destination->slug, 'name' => $this->destination->name] : null),
            'route' => $this->whenLoaded('route', fn () => $this->route ? ['slug' => $this->route->slug, 'name' => $this->route->name] : null),
            'segment' => $this->whenLoaded('segment', fn () => $this->segment ? ['id' => $this->segment->id, 'start_name' => $this->segment->start_name, 'end_name' => $this->segment->end_name] : null),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id, 'url' => route('api.media.show', $m->id, false), 'width' => $m->width, 'height' => $m->height, 'alt' => $m->alt,
            ])->values()),
        ];
    }
}
