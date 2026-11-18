<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case Invited = 'invited';
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case DidNotAttend = 'did_not_attend';

    /** Registrations that may see a private event's details. */
    public static function involved(): array
    {
        return [self::Invited->value, self::Pending->value, self::Approved->value, self::Waitlisted->value, self::Attended->value, self::DidNotAttend->value];
    }
}
