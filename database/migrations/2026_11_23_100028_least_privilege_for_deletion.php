<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Session 31, approved Option B (docs/security/ACCOUNT_DELETION_DB_SECURITY_DESIGN.md).
// The application role (mrn_app) loses the rights only the daily jobs used, and gets two narrow functions instead:
// - DELETE on users: only mrn_delete_account(request, user, clock), which checks the request in the database first.
// - DELETE on account_deletion_requests: only mrn_purge_deletion_records(clock), which keeps the one-year boundary.
// - DELETE on moderation_evidence: none (the app only ever creates and updates evidence).
// - INSERT, UPDATE, DELETE, TRUNCATE on migrations: none (migrations run as the owner role, from the deploy step).
// Both functions are SECURITY DEFINER, owned by the table owner, with a fixed search_path, no dynamic SQL, and
// EXECUTE for mrn_owner and mrn_app only. Clock: the application role always gets the database clock
// (localtimestamp, UTC like the app). Only a session of the owner role (tests, maintenance) may pass its own clock,
// because it can already delete any row; this keeps PHP time travel usable in tests without opening anything.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
create or replace function mrn_delete_account(p_request uuid, p_user uuid, p_clock timestamp) returns integer
language plpgsql security definer set search_path = public, pg_temp as $fn$
declare
    r account_deletion_requests%rowtype;
    clock timestamp := localtimestamp;
begin
    if p_clock is not null and pg_has_role(session_user, (select tableowner from pg_tables where schemaname = 'public' and tablename = 'users'), 'MEMBER') then
        clock := p_clock;
    end if;
    select * into r from account_deletion_requests where id = p_request for update;
    if not found then
        raise exception 'deletion request % does not exist', p_request using errcode = '42501';
    end if;
    if r.user_id is distinct from p_user then
        raise exception 'deletion request % is not for this account', p_request using errcode = '42501';
    end if;
    if r.status <> 'scheduled' then
        raise exception 'deletion request % is %, not scheduled', p_request, r.status using errcode = '42501';
    end if;
    if r.run_after > clock or r.run_after < r.requested_at + interval '30 days' then
        raise exception 'deletion request % is not due for final deletion', p_request using errcode = '42501';
    end if;
    if not exists (select 1 from users where id = r.user_id and deletion_scheduled_for is not null) then
        raise exception 'the account of deletion request % is not scheduled for deletion', p_request using errcode = '42501';
    end if;
    -- The check constraints still refuse this unless the run first anonymised or deleted the contributions and
    -- cancelled or deleted the events (fail-closed).
    delete from users where id = r.user_id;
    insert into audit_logs (actor_id, actor_type, action, subject_type, subject_id, old_data, new_data, ip_address, created_at)
    values (null, 'system', 'account.removed', 'account_deletion_requests', p_request::text, null,
            jsonb_build_object('by', 'mrn_delete_account'), null, now());
    return 1;
end
$fn$;

create or replace function mrn_purge_deletion_records(p_clock timestamp) returns integer
language plpgsql security definer set search_path = public, pg_temp as $fn$
declare
    clock timestamp := localtimestamp;
    n integer;
begin
    if p_clock is not null and pg_has_role(session_user, (select tableowner from pg_tables where schemaname = 'public' and tablename = 'account_deletion_requests'), 'MEMBER') then
        clock := p_clock;
    end if;
    -- D-10: finished records (done or cancelled) are kept one year from when they finished. Scheduled ones never go.
    delete from account_deletion_requests
    where status in ('done', 'cancelled') and coalesce(completed_at, cancelled_at) <= clock - interval '1 year';
    get diagnostics n = row_count;
    if n > 0 then
        insert into audit_logs (actor_id, actor_type, action, subject_type, subject_id, old_data, new_data, ip_address, created_at)
        values (null, 'system', 'account.deletion_records_purged', 'account_deletion_requests', '', null,
                jsonb_build_object('records', n, 'older_than', '1 year'), null, now());
    end if;
    return n;
end
$fn$;
SQL);
        DB::statement('revoke all on function mrn_delete_account(uuid, uuid, timestamp) from public');
        DB::statement('revoke all on function mrn_purge_deletion_records(timestamp) from public');
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant execute on function mrn_delete_account(uuid, uuid, timestamp) to mrn_app');
            DB::statement('grant execute on function mrn_purge_deletion_records(timestamp) to mrn_app');
            DB::statement('revoke delete on users, account_deletion_requests, moderation_evidence from mrn_app');
            DB::statement('revoke insert, update, delete, truncate on migrations from mrn_app');
        }
    }

    public function down(): void
    {
        // Once an account was removed through the function, rolling back would hand the app the raw DELETE again.
        if (DB::table('audit_logs')->where(['actor_type' => 'system', 'action' => 'account.removed'])->exists()) {
            throw new RuntimeException('Accounts were removed through mrn_delete_account; this migration cannot be rolled back.');
        }
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant delete on users, account_deletion_requests, moderation_evidence to mrn_app');
            DB::statement('grant insert, update, delete on migrations to mrn_app');
        }
        DB::statement('drop function if exists mrn_delete_account(uuid, uuid, timestamp)');
        DB::statement('drop function if exists mrn_purge_deletion_records(timestamp)');
    }
};
