<?php

namespace App\Models;

use App\Enums\TripRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['title', 'starts_on', 'ends_on', 'motorcycle', 'notes'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'trip_members')->withPivot('role')->withTimestamps();
    }

    public function roleOf(?User $user): ?TripRole
    {
        if (! $user) {
            return null;
        }
        $role = $this->members()->where('users.id', $user->id)->value('trip_members.role');

        return $role ? TripRole::from($role) : null;
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderBy('position');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class)->orderBy('created_at');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(TripChecklistItem::class)->orderBy('section')->orderBy('position');
    }
}
