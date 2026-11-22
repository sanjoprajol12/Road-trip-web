<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Session 28: the follow-up to the independent review (docs/sessions/2026-11-22-session-27-independent-review.html).
//
// R8: `contributions.staff_confirmed_on` records the date a moderator marked a report "still accurate" (it had no
//     row of its own, so a deletion run recomputing `last_confirmed_on` from rider confirmations could erase it).
//     Backfilled from the append-only `contribution.confirmed` audit entries.
// R1: only the table owner (that is, the security definer redaction function) or a superuser may write an
//     `audit.redacted` entry or any entry with actor type `system`. The application role cannot fabricate one.
// R2: the redaction function now takes the deletion request, not a free-text reason, and checks in the database
//     that the request is due and that the entry's subject belongs to that account. So that the application role
//     cannot fabricate a due request, a trigger fixes `requested_at` to the database clock, keeps `run_after` at
//     least 30 days later, and refuses later changes to `user_id`, `requested_at` or `run_after` and any status
//     move other than scheduled -> cancelled or scheduled -> done. The owner role (migrations, tests) and
//     superusers (local E2E setup) are exempt.
// Also (low priority): the first version of a place suggestion (`place.suggested`) is redactable.
return new class extends Migration
{
    private const PRIVILEGED = "(current_user = (select tableowner from pg_tables where schemaname = 'public' and tablename = TG_TABLE_NAME) or (select rolsuper from pg_roles where rolname = current_user))";

    public function up(): void
    {
        DB::statement('alter table contributions add column staff_confirmed_on date');
        DB::unprepared(<<<'SQL'
update contributions c set staff_confirmed_on = x.d
from (select distinct on (subject_id) subject_id, (new_data->>'last_confirmed_on')::date as d
      from audit_logs where action = 'contribution.confirmed' and subject_type = 'contributions' and new_data ? 'last_confirmed_on'
      order by subject_id, id desc) x
where c.id::text = x.subject_id
SQL);

        $privileged = self::PRIVILEGED;
        DB::unprepared(<<<SQL
create or replace function mrn_guard_system_audit() returns trigger language plpgsql as \$fn\$
begin
    if (new.actor_type = 'system' or new.action = 'audit.redacted') and not {$privileged} then
        raise exception 'only the redaction function may write system audit entries' using errcode = '42501';
    end if;
    return new;
end
\$fn\$;
create trigger audit_logs_system_guard before insert on audit_logs for each row execute function mrn_guard_system_audit();

create or replace function mrn_guard_deletion_request() returns trigger language plpgsql as \$fn\$
begin
    if {$privileged} then
        return new;
    end if;
    if tg_op = 'INSERT' then
        if new.status is distinct from 'scheduled' then
            raise exception 'a deletion request starts as scheduled' using errcode = '42501';
        end if;
        new.requested_at := now();
        new.run_after := greatest(new.run_after, now() + interval '30 days');
        return new;
    end if;
    if new.user_id is distinct from old.user_id or new.requested_at is distinct from old.requested_at or new.run_after is distinct from old.run_after then
        raise exception 'the account, request date and deletion date of a deletion request cannot change' using errcode = '42501';
    end if;
    if new.status is distinct from old.status and not (old.status = 'scheduled' and new.status in ('cancelled', 'done')) then
        raise exception 'a deletion request cannot move from % to %', old.status, new.status using errcode = '42501';
    end if;
    return new;
end
\$fn\$;
create trigger account_deletion_requests_guard before insert or update on account_deletion_requests for each row execute function mrn_guard_deletion_request();
SQL);

        // migrate:fresh drops tables, not functions, so both statements tolerate an earlier run.
        DB::statement('drop function if exists mrn_redact_audit_text(bigint, text)');
        DB::unprepared(<<<'SQL'
create or replace function mrn_redact_audit_text(p_entry bigint, p_request uuid) returns integer
language plpgsql security definer set search_path = public, pg_temp as $fn$
declare
    r account_deletion_requests%rowtype;
    e audit_logs%rowtype;
    k text;
    o jsonb;
    n jsonb;
    fields text[] := '{}';
    marker constant jsonb := '"[redacted]"';
begin
    -- The deletion request: scheduled, due by the database clock, after a full cooling-off, for an account that is
    -- itself marked for deletion. The deletion run calls this inside its own transaction, before it sets "done".
    select * into r from account_deletion_requests where id = p_request for share;
    if not found or r.status <> 'scheduled' or r.run_after > now() or r.run_after < r.requested_at + interval '30 days' then
        raise exception 'deletion request % is not due for final deletion', p_request using errcode = '42501';
    end if;
    if not exists (select 1 from users where id = r.user_id and deletion_scheduled_for is not null) then
        raise exception 'the account of deletion request % is not scheduled for deletion', p_request using errcode = '42501';
    end if;
    select * into e from audit_logs where id = p_entry for update;
    if not found then
        raise exception 'audit entry % does not exist', p_entry;
    end if;
    -- The entry's subject must belong to that account, by the database's own relationships.
    if e.action in ('contribution.edited', 'contribution.correction_added') then
        if e.subject_type <> 'contributions' or not exists (select 1 from contributions where id::text = e.subject_id and author_id = r.user_id) then
            raise exception 'audit entry % is not about a contribution of this account', p_entry using errcode = '42501';
        end if;
    elsif e.action in ('place.suggested', 'place.resubmitted') then
        if e.subject_type <> 'places' or not exists (select 1 from places where id::text = e.subject_id and submitted_by = r.user_id
                and status in ('draft', 'pending_review', 'changes_requested', 'rejected')) then
            raise exception 'audit entry % is not about an unreviewed place suggestion of this account', p_entry using errcode = '42501';
        end if;
    else
        raise exception 'audit entries of type % cannot be redacted', e.action;
    end if;
    o := e.old_data;
    n := e.new_data;
    foreach k in array array['title', 'body', 'details', 'author_correction', 'name', 'summary', 'description'] loop
        if o is not null and o ? k and o -> k is distinct from marker then
            o := jsonb_set(o, array[k], marker);
            fields := fields || ('old_data.' || k);
        end if;
        if n is not null and n ? k and n -> k is distinct from marker then
            n := jsonb_set(n, array[k], marker);
            fields := fields || ('new_data.' || k);
        end if;
    end loop;
    if cardinality(fields) = 0 then
        return 0; -- already redacted: nothing changes and no second record is written
    end if;
    update audit_logs set old_data = o, new_data = n where id = p_entry;
    insert into audit_logs (actor_id, actor_type, action, subject_type, subject_id, old_data, new_data, ip_address, created_at)
    values (null, 'system', 'audit.redacted', 'audit_logs', p_entry::text, null,
            jsonb_build_object('reason', 'account_deletion', 'entry_action', e.action, 'fields', to_jsonb(fields)), null, now());
    return cardinality(fields);
end
$fn$
SQL);
        DB::statement('revoke all on function mrn_redact_audit_text(bigint, uuid) from public');
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant execute on function mrn_redact_audit_text(bigint, uuid) to mrn_app');
        }
    }

    public function down(): void
    {
        // Rolling back would reopen R1 and R2; refuse once any redaction has been recorded (as 2026_11_22_100025 does).
        if (DB::table('audit_logs')->where('actor_type', 'system')->exists()) {
            throw new RuntimeException('Redaction records exist in the audit log; this migration cannot be rolled back.');
        }
        DB::statement('drop trigger if exists account_deletion_requests_guard on account_deletion_requests');
        DB::statement('drop function if exists mrn_guard_deletion_request()');
        DB::statement('drop trigger if exists audit_logs_system_guard on audit_logs');
        DB::statement('drop function if exists mrn_guard_system_audit()');
        DB::statement('drop function if exists mrn_redact_audit_text(bigint, uuid)');
        // Restore the session 26 function (reason-based), so 2026_11_22_100025 can be rolled back in turn.
        $previous = file_get_contents(__DIR__.'/2026_11_22_100025_add_audit_redaction.php');
        preg_match("/DB::unprepared\\(<<<'SQL'\\n(.*?)\\nSQL\\);/s", $previous, $m);
        DB::unprepared($m[1]);
        DB::statement('revoke all on function mrn_redact_audit_text(bigint, text) from public');
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant execute on function mrn_redact_audit_text(bigint, text) to mrn_app');
        }
        DB::statement('alter table contributions drop column staff_confirmed_on');
    }
};
