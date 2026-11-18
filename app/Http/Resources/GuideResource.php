<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'category' => $this->category->value,
            'body' => $this->body,
            'sources' => $this->sources,
            'status' => $this->status->value,
            'needs_expert_review' => $this->needsExpertReview(),
            'expert_reviewed' => $this->expert_reviewed_at !== null,
            'expert_reviewed_at' => $this->expert_reviewed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
