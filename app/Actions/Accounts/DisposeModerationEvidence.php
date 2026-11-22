<?php

namespace App\Actions\Accounts;

use App\Models\ModerationEvidence;
use App\Support\Audit;
use App\Support\PendingFiles;
use Illuminate\Support\Facades\DB;

/**
 * Ends a moderation evidence hold (D-04): its content and photo copies are removed and only the outcome remains.
 * "expired" by the daily run once held_until has passed; "released" by staff as soon as it is no longer needed.
 * There is no way to extend a hold (and a database check caps it at 30 days).
 */
final class DisposeModerationEvidence
{
    public function dispose(ModerationEvidence $e, string $disposition): void
    {
        DB::transaction(function () use ($e, $disposition) {
            $row = ModerationEvidence::whereKey($e->id)->whereNull('disposed_at')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            foreach ($row->files ?? [] as $f) {
                PendingFiles::queue($f['disk'], $f['path']); // kept if an anonymised contribution still shows the photo
            }
            $row->update(['snapshot' => null, 'files' => null, 'disposition' => $disposition, 'disposed_at' => now()]);
            Audit::record('moderation.evidence_'.$disposition, $row, null, ['reason' => $row->reason]);
        });
    }

    public function purgeExpired(): int
    {
        $n = 0;
        foreach (ModerationEvidence::whereNull('disposed_at')->where('held_until', '<=', now())->get() as $e) {
            $this->dispose($e, 'expired');
            $n++;
        }

        return $n;
    }
}
