<?php

namespace App\Support;

use App\Models\StaffAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Site settings from config/settings.php, stored in `settings`. Values are cached until the next change.
 * Secrets are encrypted at rest, never returned, and masked in the audit log.
 */
class Settings
{
    private const CACHE = 'mrn.settings';

    /** @return array<string, array{type: string, default: mixed, rules: array, public?: bool, secret?: bool, item_rules?: array}> */
    public static function registry(): array
    {
        return config('settings.registry');
    }

    /** Every setting with its stored value or default. Secrets are decrypted here, for server-side use only. */
    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE, fn () => DB::table('settings')->get()
            ->mapWithKeys(fn ($r) => [$r->key => $r->secret !== null ? ['secret' => $r->secret] : ['value' => json_decode($r->value, true)]])->all());
        $out = [];
        foreach (self::registry() as $key => $def) {
            $row = $stored[$key] ?? null;
            $out[$key] = match (true) {
                $row === null => $def['default'],
                isset($row['secret']) => Crypt::decryptString($row['secret']),
                default => $row['value'],
            };
        }

        return $out;
    }

    public static function get(string $key): mixed
    {
        abort_unless(array_key_exists($key, self::registry()), 500, "Unknown setting {$key}");

        return self::all()[$key];
    }

    /** Values of one group for the staff app. Secrets show only whether they are set. */
    public static function group(string $group): array
    {
        $values = self::all();
        $out = [];
        foreach (self::keysOf($group) as $key) {
            $def = self::registry()[$key];
            $out[substr($key, strlen($group) + 1)] = ($def['secret'] ?? false) ? ['set' => filled($values[$key])] : $values[$key];
        }

        return $out;
    }

    /** What the public website may read (GET /api/v1/site). */
    public static function public(): array
    {
        $values = self::all();
        $out = [];
        foreach (self::registry() as $key => $def) {
            if (($def['public'] ?? false) && ! ($def['secret'] ?? false)) {
                data_set($out, $key, $values[$key]);
            }
        }

        return $out;
    }

    /**
     * Validates and saves the given keys of one group (short names, e.g. "site_name"). Unknown keys are refused.
     * Secrets: a non-empty value replaces it, an empty string clears it, leaving it out keeps it.
     */
    public static function update(string $group, array $input, ?StaffAccount $actor): void
    {
        $keys = self::keysOf($group);
        $unknown = array_diff(array_keys($input), array_map(fn ($k) => substr($k, strlen($group) + 1), $keys));
        if ($unknown) {
            throw ValidationException::withMessages(['settings' => ['Unknown setting: '.implode(', ', $unknown).'.']]);
        }
        $rules = [];
        foreach ($keys as $key) {
            $short = substr($key, strlen($group) + 1);
            if (! array_key_exists($short, $input)) {
                continue;
            }
            $def = self::registry()[$key];
            $rules[$short] = ($def['secret'] ?? false) ? ['nullable', 'string', 'max:500'] : $def['rules'];
            if (isset($def['item_rules'])) {
                $rules["{$short}.*"] = $def['item_rules'];
            }
        }
        $data = Validator::make($input, $rules)->validate();

        $before = self::all();
        DB::transaction(function () use ($group, $data, $actor, $before) {
            $old = $new = [];
            foreach ($data as $short => $value) {
                $key = "{$group}.{$short}";
                $def = self::registry()[$key];
                $secret = $def['secret'] ?? false;
                $value = self::cast($def['type'], $value);
                DB::table('settings')->updateOrInsert(['key' => $key], [
                    'value' => $secret ? null : json_encode($value),
                    'secret' => $secret && filled($value) ? Crypt::encryptString($value) : null,
                    'updated_by_staff_id' => $actor?->id, 'updated_at' => now(),
                ]);
                if (str_ends_with($key, '_media_id')) {
                    MediaUsages::sync('settings', $key, ['value' => $value]);
                }
                $old[$key] = $secret ? (filled($before[$key]) ? '[set]' : '[not set]') : $before[$key];
                $new[$key] = $secret ? (filled($value) ? '[set]' : '[not set]') : $value;
            }
            Audit::record("settings.{$group}.updated", 'settings', $old, $new, $group);
        });
        self::forget();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return list<string> */
    private static function keysOf(string $group): array
    {
        abort_unless(in_array($group, config('settings.groups'), true), 404);

        return array_values(array_filter(array_keys(self::registry()), fn ($k) => str_starts_with($k, "{$group}.")));
    }

    private static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'int' => (int) $value,
            'float' => (float) $value,
            'list' => array_values(array_unique($value ?? [])),
            default => $value === null ? null : (string) $value,
        };
    }
}
