<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner decision O8 (addendum, 2026-11-22): redact contribution text in the audit history through a privileged,
// audited process. The app role still cannot update or delete audit rows (2026_11_18_100008). It may only call
// this function, which runs with the table owner's rights and can do exactly one thing: replace the text fields of
// a text-bearing contribution or place entry with "[redacted]", for an allowed reason, and write a separate
// immutable `audit.redacted` entry naming the affected entry and the fields (no content, no person).
// The ids, timestamps, actions, actors, subjects and IP addresses of the entry are never touched.
// Integrity: the audit log has no hash chain or signature today, so nothing to re-verify. A future chain must hash
// the redacted form and link the redaction entry.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table audit_logs drop constraint audit_logs_actor_type');
        DB::statement("alter table audit_logs add constraint audit_logs_actor_type check (actor_type in ('user', 'staff', 'system'))");

        DB::unprepared(<<<'SQL'
create or replace function mrn_redact_audit_text(p_entry bigint, p_reason text) returns integer
language plpgsql security definer set search_path = public, pg_temp as $fn$
declare
    e audit_logs%rowtype;
    k text;
    o jsonb;
    n jsonb;
    fields text[] := '{}';
    marker constant jsonb := '"[redacted]"';
begin
    if p_reason is distinct from 'account_deletion' then
        raise exception 'audit redaction reason % is not allowed', p_reason;
    end if;
    select * into e from audit_logs where id = p_entry for update;
    if not found then
        raise exception 'audit entry % does not exist', p_entry;
    end if;
    if e.action not in ('contribution.edited', 'contribution.correction_added', 'place.resubmitted') then
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
            jsonb_build_object('reason', p_reason, 'entry_action', e.action, 'fields', to_jsonb(fields)), null, now());
    return cardinality(fields);
end
$fn$
SQL);
        DB::statement('revoke all on function mrn_redact_audit_text(bigint, text) from public');
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant execute on function mrn_redact_audit_text(bigint, text) to mrn_app');
        }
    }

    public function down(): void
    {
        // Audit history is never rewritten, not even by a rollback: once a redaction is recorded, this stays.
        if (DB::table('audit_logs')->where('actor_type', 'system')->exists()) {
            throw new RuntimeException('Redaction records exist in the audit log; this migration cannot be rolled back.');
        }
        DB::statement('drop function if exists mrn_redact_audit_text(bigint, text)');
        DB::statement('alter table audit_logs drop constraint audit_logs_actor_type');
        DB::statement("alter table audit_logs add constraint audit_logs_actor_type check (actor_type in ('user', 'staff'))");
    }
};
