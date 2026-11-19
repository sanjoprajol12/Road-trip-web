<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Records which media assets a record uses, so the media library refuses to delete them. Call inside the transaction. */
final class MediaUsages
{
    /** @param array<string, ?string> $fields field name => media asset id (null removes the usage) */
    public static function sync(string $ownerType, string $ownerId, array $fields): void
    {
        foreach ($fields as $field => $mediaId) {
            $key = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'field' => $field];
            $mediaId
                ? DB::table('media_usages')->updateOrInsert($key, ['media_asset_id' => $mediaId, 'updated_at' => now(), 'created_at' => now()])
                : DB::table('media_usages')->where($key)->delete();
        }
    }

    /** Replaces the usages of a record with every media asset id found in its JSON fields (nested CMS data). */
    public static function syncFromJson(string $ownerType, string $ownerId, ?string ...$json): void
    {
        preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', implode(' ', array_filter($json)), $m);
        $ids = $m[0] ? DB::table('media_assets')->whereIn('id', array_unique($m[0]))->pluck('id')->all() : [];
        DB::table('media_usages')->where(['owner_type' => $ownerType, 'owner_id' => $ownerId])->where('field', 'like', 'media:%')->whereNotIn('media_asset_id', $ids)->delete();
        foreach ($ids as $id) {
            DB::table('media_usages')->updateOrInsert(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'field' => "media:{$id}"], ['media_asset_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function forget(string $ownerType, string $ownerId): void
    {
        DB::table('media_usages')->where(['owner_type' => $ownerType, 'owner_id' => $ownerId])->delete();
    }
}
