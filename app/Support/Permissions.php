<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** The permission registry in config/permissions.php and its copy in the database. */
class Permissions
{
    /** @return array<string, string> key => description */
    public static function registry(): array
    {
        return config('permissions.registry');
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::registry());
    }

    /**
     * Writes the registry to `permissions`: adds new keys, updates descriptions, removes keys that left the
     * registry (their role assignments go with them, since no code checks them any more).
     */
    public static function sync(): void
    {
        DB::transaction(function () {
            foreach (self::registry() as $key => $description) {
                [$module, $action] = [substr($key, 0, strrpos($key, '.')), substr($key, strrpos($key, '.') + 1)];
                DB::table('permissions')->updateOrInsert(['key' => $key], ['module' => $module, 'action' => $action, 'description' => $description]);
            }
            DB::table('permissions')->whereNotIn('key', self::keys())->delete();
        });
    }
}
