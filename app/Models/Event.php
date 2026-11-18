<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['category', 'subtype', 'title', 'description', 'starts_at', 'ends_at', 'meeting_point', 'route_id', 'visibility', 'join_method', 'capacity', 'waitlist_enabled', 'summary'];

    protected function casts(): array
    {
        return ['status' => EventStatus::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'waitlist_enabled' => 'boolean'];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RideRoute::class, 'route_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(EventUpdate::class)->orderByDesc('id');
    }

    public function approvedCount(): int
    {
        return $this->registrations()->whereIn('status', [RegistrationStatus::Approved->value, RegistrationStatus::Attended->value])->count();
    }

    public function registrationOf(?User $user): ?EventRegistration
    {
        return $user ? $this->registrations()->where('user_id', $user->id)->first() : null;
    }
}
