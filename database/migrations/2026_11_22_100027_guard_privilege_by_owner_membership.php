<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Session 28 correction to 2026_11_22_100026: the guards exempted "the table owner or a superuser", but the local
// Supabase `postgres` role (used by E2E fixtures and the setup script) is not a superuser; it is a MEMBER of the owner
// role (setup-local-db.sh: `grant mrn_owner to postgres`). The exemption is now "the owner role or one of its
// members". The application role `mrn_app` is not a member, so R1 and R2 stay closed for it (tested).
return new class extends Migration
{
    private function guards(string $privileged): string
    {
        return <<<SQL
create or replace function mrn_guard_system_audit() returns trigger language plpgsql as \$fn\$
begin
    if (new.actor_type = 'system' or new.action = 'audit.redacted') and not {$privileged} then
        raise exception 'only the redaction function may write system audit entries' using errcode = '42501';
    end if;
    return new;
end
\$fn\$;
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
SQL;
    }

    public function up(): void
    {
        DB::unprepared($this->guards("pg_has_role(current_user, (select tableowner from pg_tables where schemaname = 'public' and tablename = TG_TABLE_NAME), 'MEMBER')"));
    }

    public function down(): void
    {
        DB::unprepared($this->guards("(current_user = (select tableowner from pg_tables where schemaname = 'public' and tablename = TG_TABLE_NAME) or (select rolsuper from pg_roles where rolname = current_user))"));
    }
};
