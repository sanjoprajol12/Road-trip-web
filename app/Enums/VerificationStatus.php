<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Suspended, self::Revoked],
            self::Suspended => [self::Approved, self::Revoked],
            self::Rejected, self::Revoked => [],
        };
    }

    public function needsReason(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended, self::Revoked], true);
    }

    /** Claims that block a new application for the same kind and scope. */
    public static function open(): array
    {
        return [self::Pending->value, self::Approved->value, self::Suspended->value];
    }
}
