<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Writes the append-only audit trail. Call inside the same transaction as the change it records. */
final class Audit
{
    public static function record(string $action, Model|string $subject, ?array $old = null, ?array $new = null, ?string $subjectId = null): void
    {
        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject instanceof Model ? $subject->getTable() : $subject,
            'subject_id' => $subjectId ?? ($subject instanceof Model ? (string) $subject->getKey() : ''),
            'old_data' => $old,
            'new_data' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
