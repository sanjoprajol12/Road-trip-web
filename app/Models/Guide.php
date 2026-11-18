<?php

namespace App\Models;

use App\Enums\GuideCategory;
use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Guide extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'title', 'category', 'body', 'sources'];

    protected function casts(): array
    {
        return ['category' => GuideCategory::class, 'status' => PublishStatus::class, 'expert_reviewed_at' => 'datetime'];
    }

    public function needsExpertReview(): bool
    {
        return $this->category->expertScope() !== null;
    }
}
