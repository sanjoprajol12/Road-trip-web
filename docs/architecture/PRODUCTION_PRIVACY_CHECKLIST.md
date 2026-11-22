# Production checklist: account deletion, audit redaction and retention

**Purpose:** what must be verified in the production database and host before the account deletion jobs are switched on. It covers owner decisions D-01 to D-04, D-10, D-11 and O8, and the session 27 review follow-up.

**Status:** nothing here has been run against production. No production database exists yet (D-08, D-09). Every query is read-only.

Run the checks **as the database owner role** (`mrn_owner`, or a member of it) unless a step says otherwise. Expected results are given in bold.

## 0. Owner credentials stay out of the web runtime (session 30, finding F1)

- The running web and scheduler environment must **not** contain `DB_MIGRATION_USERNAME` or `DB_MIGRATION_PASSWORD`. Run migrations from the deploy step, with a secret only that step can read.
- With the owner password on the web host, every check below can be bypassed.
- **Check:** on the running container, `php artisan tinker --execute="var_dump(filled(config('database.connections.pgsql_owner.password')))"` prints **`bool(false)`**. This shows only whether a password is set, never the password itself.

## 1. Application role (`mrn_app`)

```sql
-- Not a member of the owner role, and no elevated attributes
select pg_has_role('mrn_app', 'mrn_owner', 'MEMBER') as member_of_owner,                      -- false
       rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication                     -- all false
from pg_roles where rolname = 'mrn_app';

-- Owns nothing in the schema
select count(*) from pg_tables where schemaname = 'public' and tableowner = 'mrn_app';       -- 0
select has_schema_privilege('mrn_app', 'public', 'create');                                  -- false
select has_database_privilege('mrn_app', current_database(), 'temporary');                   -- false
```

## 2. Audit log privileges

```sql
select has_table_privilege('mrn_app', 'audit_logs', 'insert')   as can_insert,    -- true
       has_table_privilege('mrn_app', 'audit_logs', 'select')   as can_select,    -- true
       has_table_privilege('mrn_app', 'audit_logs', 'update')   as can_update,    -- FALSE
       has_table_privilege('mrn_app', 'audit_logs', 'delete')   as can_delete,    -- FALSE
       has_table_privilege('mrn_app', 'audit_logs', 'truncate') as can_truncate;  -- FALSE
```

**Provisioning:** a script that grants table rights in bulk (`grant … on all tables`) must re-apply `revoke update, delete, truncate on audit_logs from mrn_app` afterwards. The local `scripts/setup-local-db.sh` does this. Whatever provisions production must as well, and the check must be re-run after every provisioning or restore.

## 3. Guard triggers (R1, R2)

```sql
select tgname, tgrelid::regclass, tgenabled
from pg_trigger
where tgname in ('audit_logs_system_guard', 'account_deletion_requests_guard') and not tgisinternal;
-- two rows; tgenabled = 'O' (enabled)
```

**Behaviour check, as `mrn_app`:** wrap it in `begin; … rollback;`.

```sql
insert into audit_logs (action, actor_type, subject_type, subject_id, created_at)
values ('audit.redacted', 'user', 'audit_logs', '1', now());               -- must FAIL (42501)
```

## 4. Redaction function (O8, R2)

```sql
select prosecdef as security_definer,                              -- true
       pg_get_userbyid(proowner) as owner,                         -- mrn_owner (the audit table's owner)
       array_to_string(proconfig, ';') as config,                  -- search_path=public, pg_temp
       proacl::text as acl                                         -- {mrn_owner=X/mrn_owner,mrn_app=X/mrn_owner}; no "=X" for public
from pg_proc where proname = 'mrn_redact_audit_text';
select count(*) from pg_proc where proname = 'mrn_redact_audit_text';   -- 1 (only the (bigint, uuid) version)
```

## 5. Job switches

| Variable | Default | Enables |
|---|---|---|
| `ACCOUNT_DELETION_PROCESSING_ENABLED` | `false` | `mrn:accounts:process-deletions` (due deletions) |
| `ACCOUNT_DELETION_RECORDS_PURGE_ENABLED` | `false` | `mrn:accounts:purge-deletion-records` (finished records after 1 year) |
| — | always on | `mrn:accounts:expire-evidence` (30-day evidence end, failed file retries; it only removes data whose retention already ended) |

- **Check the values** with `php artisan config:show mrn.account_deletion`. Both are `false` until the owner approves.
- **Enable processing first.** Enable the purge only when the first records approach one year.
- **After any change,** run each command once by hand and read its output. A switched-off command prints "switched off … Nothing was changed."

## 6. Scheduler

- **Cron:** `* * * * * cd /path/to/web && php artisan schedule:run >> /dev/null 2>&1` on **one** server.
  - The tasks also use `onOneServer()` and `withoutOverlapping()`, which need the shared cache (the database cache store).
- **Check** with `php artisan schedule:list`. Expected: `process-deletions` 02:30, `expire-evidence` 02:50, `purge-deletion-records` 03:10. Times are in the app timezone.
- **Monitoring:** a failing run logs only the step, the request id and the exception class (R5). Alert on `Account deletion run failed` and `Account deletion maintenance step failed`.
- **Staff view:** "Deletions and evidence" shows the attempts and error class.

## 7. Mail

- The "scheduled", "cancelled" and "completed" emails need a production mail provider (O5, not chosen).
- Without one, deletions still run. Riders are then not told the date by email; the account page still shows it.

## 8. Backups and restore

- **Backups keep deleted data** until they expire. The privacy policy must state the backup window.
- **Restore = re-apply deletions.** After restoring a backup taken before a deletion, the account would come back. Before the site reopens:
  1. Run `php artisan mrn:accounts:expire-evidence` first. It ends any evidence that outlived its 30 days in the backup.
  2. For every `account_deletion_requests` row with `status = done` in the **live** database's latest copy, created after the backup was taken, delete that account again.
     - **No tool exists for this yet** (blocker below).
     - Per-contribution choices are kept only as categories (D-10), so a replay cannot reproduce "keep anonymously". The safe default is to delete those contributions, unless the owner decides otherwise.
  3. Re-run sections 1 to 4: a restore can bring back old grants.
- **The raw account id** in `account_deletion_requests.user_id` is what makes step 2 possible. A keyed hash was deferred by the owner, pending this replay design and a key lifecycle.
- **File storage backups** (D1/O6, no provider yet) also hold removed photos until they expire.

## 9. Production blockers (as of session 29)

| # | Blocker | Owner of the next step |
|---|---|---|
| 1 | No production database or host (D-08 candidate only; no budget, D-09) | owner |
| 2 | Sections 1 to 4 never verified in production | operations, at provisioning |
| 3 | No production cron | operations |
| 4 | No mail provider (O5) | owner |
| 5 | No durable file storage (O6); uploads stay off in production | owner |
| 6 | No restore-and-replay tool for completed deletions (section 8) | build task, after the owner decides the replay default |
| 7 | Staff MFA is mandatory before launch (D-07) and not built | build task |
| 8 | Contact retention (D-05, D-06) is not built | build task |
| 9 | WebKit browser tests have never run (local system libraries missing; CI not run) | owner (push to CI) |
| 10 | The account-id hash is deferred | owner |
| 11 | Log retention period not set (logs hold request ids and exception classes only) | owner |
| 12 | Owner credentials in the production runtime template (F1, `docs/security/ACCOUNT_DELETION_DB_SECURITY_DESIGN.md`); `mrn_app` can still delete `users`, deletion records and evidence, and write `migrations` (F2–F4, Option B awaiting approval) | owner, then an implementation session |
