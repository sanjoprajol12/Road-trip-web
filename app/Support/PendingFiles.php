<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Files to remove once a database change has committed. queue() runs inside the transaction (so a rollback forgets
 * the files too); flush() runs after it. A file that cannot be removed stays queued and is retried by the next run,
 * so the database never points at a removed file and no removal is silently lost.
 */
final class PendingFiles
{
    public static function queue(string $disk, string $path): void
    {
        DB::table('pending_file_deletions')->insertOrIgnore(['disk' => $disk, 'path' => $path, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{removed: int, failed: int} */
    public static function flush(): array
    {
        $removed = $failed = 0;
        foreach (DB::table('pending_file_deletions')->orderBy('id')->limit(1000)->get() as $f) {
            // A file still used by a photo row or by held evidence is never removed.
            if (self::stillUsed($f->disk, $f->path)) {
                DB::table('pending_file_deletions')->where('id', $f->id)->delete();

                continue;
            }
            try {
                $disk = Storage::disk($f->disk);
                if ($disk->exists($f->path) && ! $disk->delete($f->path)) {
                    throw new \RuntimeException('delete returned false');
                }
                DB::table('pending_file_deletions')->where('id', $f->id)->delete();
                $removed++;
            } catch (Throwable $e) {
                DB::table('pending_file_deletions')->where('id', $f->id)->update(['attempts' => $f->attempts + 1, 'last_error' => mb_substr($e::class, 0, 200), 'updated_at' => now()]);
                $failed++;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    private static function stillUsed(string $disk, string $path): bool
    {
        return DB::table('contribution_media')->where(['disk' => $disk, 'path' => $path])->exists()
            || DB::table('moderation_evidence')->whereNull('disposed_at')->whereRaw('files @> ?::jsonb', [json_encode([['disk' => $disk, 'path' => $path]])])->exists();
    }
}
