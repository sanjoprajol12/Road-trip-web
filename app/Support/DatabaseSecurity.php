<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F1 and Option B (docs/security/ACCOUNT_DELETION_DB_SECURITY_DESIGN.md). Never reads out or prints a secret: it
 * only ever reports whether a value is set and what the database says about the application role's rights.
 */
final class DatabaseSecurity
{
    public static function ownerCredentialsPresent(): bool
    {
        return filled(config('database.connections.pgsql_owner.password'));
    }

    /**
     * Production web requests must never hold the owner (migration) credentials. If they do, they are dropped from
     * this request's configuration and a critical alert is logged once an hour (without the value).
     */
    public static function guardRuntime(?bool $webRequest = null): void
    {
        if (! app()->environment('production') || ! ($webRequest ?? ! app()->runningInConsole()) || ! self::ownerCredentialsPresent()) {
            return;
        }
        config(['database.connections.pgsql_owner.username' => null, 'database.connections.pgsql_owner.password' => null]);
        if (Cache::add('security-alert:owner-credentials-in-runtime', 1, now()->addHour())) {
            Log::critical('Owner database credentials are set in the production web runtime. Remove DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD from it; migrations run from the release step only.');
        }
    }

    /**
     * The production checklist, checked as the application role itself. Each row: [check, ok, detail].
     *
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function checks(): array
    {
        $c = DB::connection('pgsql'); // the application role, as the website uses it
        $q = fn (string $sql, array $b = []) => $c->selectOne($sql, $b);
        $role = $q('select current_user as u')->u;
        $rows = [];
        $rows[] = ['not running as the owner role', ! $q('select pg_has_role(current_user, (select tableowner from pg_tables where schemaname = ? and tablename = ?), ?) as m', ['public', 'audit_logs', 'MEMBER'])->m, "connected as {$role}"];
        $attrs = $q('select rolsuper or rolcreaterole or rolcreatedb or rolbypassrls or rolreplication as elevated from pg_roles where rolname = current_user');
        $rows[] = ['no elevated role attributes', ! $attrs->elevated, ''];
        $rows[] = ['cannot create objects in the schema', ! $q("select has_schema_privilege(current_user, 'public', 'create') as p")->p, ''];
        $has = fn (string $table, string $priv) => (bool) $q('select has_table_privilege(current_user, ?, ?) as p', [$table, $priv])->p;
        $rows[] = ['audit log: insert only', $has('audit_logs', 'insert') && ! $has('audit_logs', 'update') && ! $has('audit_logs', 'delete') && ! $has('audit_logs', 'truncate'), ''];
        foreach (['users', 'account_deletion_requests', 'moderation_evidence'] as $t) {
            $rows[] = ["no direct DELETE on {$t}", ! $has($t, 'delete') && ! $has($t, 'truncate'), ''];
        }
        $rows[] = ['migrations table is read-only', ! $has('migrations', 'insert') && ! $has('migrations', 'update') && ! $has('migrations', 'delete') && ! $has('migrations', 'truncate'), ''];
        $triggers = $q("select count(*) as n from pg_trigger where tgname in ('audit_logs_system_guard', 'account_deletion_requests_guard') and tgenabled = 'O' and not tgisinternal")->n;
        $rows[] = ['both guard triggers enabled', (int) $triggers === 2, "{$triggers} of 2"];
        foreach (['mrn_redact_audit_text(bigint, uuid)', 'mrn_delete_account(uuid, uuid, timestamp)', 'mrn_purge_deletion_records(timestamp)'] as $fn) {
            $f = $q("select p.prosecdef as definer, pg_get_userbyid(p.proowner) as owner, array_to_string(p.proconfig, ';') as cfg,
                (select count(*) from aclexplode(p.proacl) a where a.privilege_type = 'EXECUTE' and a.grantee not in (p.proowner, (select oid from pg_roles where rolname = current_user))) as others,
                has_function_privilege(current_user, p.oid, 'execute') as mine
                from pg_proc p where p.oid = to_regprocedure(?)", [$fn]);
            $ok = $f && $f->definer && $f->owner === $q("select tableowner as o from pg_tables where schemaname = 'public' and tablename = 'audit_logs'")->o
                && str_contains((string) $f->cfg, 'search_path=public, pg_temp') && (int) $f->others === 0 && $f->mine;
            $rows[] = ["function {$fn}: definer, table owner, fixed search_path, execute for owner and app only", (bool) $ok, $f ? '' : 'missing'];
        }
        $rows[] = ['owner credentials absent from this runtime (required in production)', ! self::ownerCredentialsPresent() || ! app()->environment('production'),
            self::ownerCredentialsPresent() ? (app()->environment('production') ? 'SET: remove them' : 'set (expected outside production)') : 'not set'];

        return $rows;
    }
}
