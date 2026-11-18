<?php

namespace App\Models;

use App\Enums\VerificationKind;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    use HasUuids;

    protected $table = 'user_verifications';

    protected $fillable = ['user_id', 'kind', 'scope', 'statement'];

    protected function casts(): array
    {
        return ['kind' => VerificationKind::class, 'status' => VerificationStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
