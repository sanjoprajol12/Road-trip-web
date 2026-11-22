# Database-level account deletion security: design review (session 30)

**Status:** design review only. Nothing here is implemented. No schema, privilege, code or data was changed. It needs owner approval before an implementation session.

**Inputs:**
- `docs/architecture/ACCOUNT_DELETION_AND_EXPORT.md`
- `docs/architecture/PRODUCTION_PRIVACY_CHECKLIST.md`
- `docs/product/OWNER_DECISIONS_2026-11-22_ADDENDUM.md`
- the session 27 to 29 reports
- the live privileges of the local databases (inspected read-only, 2026-11-22)

## 1. Question

Session 29 showed two things:
- an ordinary application session can only request its own rider's deletion;
- the database cannot tell a genuine request from one written by a compromised application database role.

This review asks: **what can the database itself guarantee about account deletion, against which attacker, and at what cost?**

## 2. Threat model

| Attacker | Has | Example | In scope |
|---|---|---|---|
| A1: a rider using the API | their own session | tampered request body | yes, already closed at the application layer (session 29 tests) |
| A2: a bug or SQL injection in a web request | arbitrary SQL **as `mrn_app`** | a missed binding in a query | **yes, the main target of this review** |
| A3: a stolen `mrn_app` password | the same as A2, from outside | a leaked `.env` value | yes |
| A4: a compromised web host | everything in the runtime environment | a shell on the app server | partly: see finding F1 |
| A5: database owner, operator, provider | `mrn_owner` or a superuser | malicious or mistaken operator | no; covered by process, backups and audit |

## 3. Findings (current state)

| # | Severity | Finding | Evidence |
|---|---|---|---|
| **F1** | **high** | **The owner credentials are meant to live in the web runtime.** `.env.production.example` contains `DB_MIGRATION_USERNAME` and `DB_MIGRATION_PASSWORD`, and `config/database.php` defines the `pgsql_owner` connection from them. Attacker A4 (and A3, if the whole `.env` leaks) then has `mrn_owner`, so **every database protection is void**: the audit log's append-only rule, both guard triggers, and the redaction function's checks. | template lines 34–35; `config/database.php:103–110` |
| F2 | medium | `mrn_app` can delete from 57 tables, including `users`. Only the deletion run ever deletes a `users` row (`ProcessAccountDeletion`: `$user->delete()`), yet any `mrn_app` SQL can do it immediately. | `information_schema.role_table_grants`; a code search found no other path |
| F3 | medium | `mrn_app` can delete `account_deletion_requests` and `moderation_evidence`. Only the one-year purge deletes requests, and nothing deletes evidence (disposal is an update). A2 could erase the record that a deletion happened, or destroy evidence early, without the 30-day and one-year rules applying. | code search |
| F4 | medium | `mrn_app` has full DML on the `migrations` table, which the app never uses at runtime. A2 could insert a row naming a future security migration, so a later `migrate` skips it silently. | grants |
| F5 | low | A fabricated request, accepted since the session 28 trigger, waits 30 database-clock days before redaction accepts it. But F2 means A2 doesn't need a request at all to delete an account. | session 29 test |
| — | holds | **Already enforced in the database:** <ul><li>the audit log is insert-only for `mrn_app`;</li><li>`audit.redacted` and `system` entries only from the owner role;</li><li>a request's dates come from the database clock and cannot be moved;</li><li>a hold can't exceed 30 days (check);</li><li>a rider row can't be deleted while it still authors non-anonymised contributions or organises a non-cancelled event (checks `contributions_author_or_deleted`, `events_organizer_or_cancelled`), so a raw delete fails unless the content was handled first.</li></ul> | sessions 25–28 |

**What no design below changes:** with UPDATE on `users`, A2 can still change a rider's email or password hash (account takeover), and it can delete content in tables the app legitimately deletes from (trips, saved items and so on). Those are integrity and takeover threats, not unauthorised *account deletion*. They are listed in §8.

## 4. Options

### Option A: keep the current state

- **Pros:** no work.
- **Cons:** F1 to F5 stay. Database-level deletion protection rests entirely on `mrn_app` never being misused.

### Option B (recommended): least privilege for deletion, through two definer functions

1. **F1, credentials:** production web runtime never holds `DB_MIGRATION_*`.
   - Migrations run from the deploy step (or a one-off release job) with a secret that only that step can read.
   - The `pgsql_owner` connection stays in the code for development and tests.
   - The production checklist gains: "`DB_MIGRATION_PASSWORD` is unset in the running web container."
2. **F2, rider rows:** `revoke delete on users from mrn_app`.
   - New `mrn_delete_account(p_request uuid)`: `security definer`, owner `mrn_owner`, fixed `search_path`.
   - It runs the same request checks as `mrn_redact_audit_text` (scheduled; due by the database clock; at least 30 days after `requested_at`; account marked with `deletion_scheduled_for`), then `delete from users where id = r.user_id`.
   - The existing checks still make the delete fail unless contributions and events were handled first: fail-closed.
   - `ProcessAccountDeletion` calls it in place of `$user->delete()`, in the same transaction.
3. **F3, records and evidence:**
   - `revoke delete on account_deletion_requests, moderation_evidence from mrn_app`.
   - New `mrn_purge_deletion_records()`: definer. It deletes `done` and `cancelled` rows whose completion or cancellation is at least one year old by the database clock, writes its own `system` audit entry (allowed, it runs as the owner), and returns the count.
   - The purge command calls it.
   - Evidence needs no function: the app only ever updates it, and the 30-day check stays.
4. **F4, migrations table:** `revoke insert, update, delete on migrations from mrn_app`.
5. **Same pattern as sessions 26–28:**
   - each function is executable by `mrn_owner` and `mrn_app` only, with public revoked;
   - rollback is refused once the function has been used;
   - the setup script and the production checklist re-apply and verify the revokes.

**What B gains:** against A2 and A3,
- no account can be removed without a request that has waited 30 real days, during which the rider sees the deletion date in the app;
- deletion and evidence records can't be erased early;
- migrations can't be skipped.

With F1 fixed, A4 needs the deploy secret too.

**What B doesn't change:** A1 behaviour, D-10 and D-11 behaviour, notifications, export, the UI.

### Option C: a separate database role for the daily jobs

- The scheduler connects as `mrn_jobs`, which holds DELETE on `users`; the web keeps `mrn_app` without it.
- **Pros:** standard separation, with no deletion logic in SQL.
- **Cons:**
  - the jobs run on the same host, so A4 gets both credentials;
  - A2 is blocked only if the web role never gains the right;
  - the 30-day rule is still enforced only by application code;
  - one more credential to manage.
- **Verdict:** weaker than B against A2, A3 and A4 combined. It can be added on top of B later.

### Option D: move the whole deletion run into one stored procedure

- **Pros:** the strongest database-side guarantee.
- **Cons:**
  - it duplicates the business rules (choices, events, trips, evidence, D-11) in PL/pgSQL;
  - notifications, mail and file removal must stay in the app anyway;
  - it is harder to test and review;
  - two sources of truth.
- **Verdict:** not proportionate.

## 5. Recommendation

**Adopt Option B.**
- F1 (credentials) is a configuration and deployment rule and should be fixed first: without it, B's guarantees don't hold against A4.
- F2 to F4 are one migration plus two small call-site changes. The estimate is half a session.

## 6. Implementation plan for B (for a later, approved session)

1. **Migration `2026_11_23_1000xx_least_privilege_for_deletion`:**
   - create both functions (`create or replace`, so `migrate:fresh` is safe);
   - revoke the rights listed in §4 B 2–4;
   - grant execute;
   - `down()`:
     - refuse if any account was deleted through the function (a `system` audit entry `account.deleted_by_function`, or a marker);
     - otherwise re-grant the rights and drop the functions.
2. **Code:**
   - `ProcessAccountDeletion::erase()`: `DB::select('select mrn_delete_account(?)', [$req->id])` in place of `$user->delete()`;
   - the purge command calls `mrn_purge_deletion_records()`;
   - keep `Audit::record('account.deleted', …)` in the app.
3. **Clock and test design:**
   - The functions check the **database** clock, while the feature tests move PHP time. As with the guard triggers, skip the timing checks when `session_user` is a member of the owner role.
   - Tests run as the owner; production and E2E run as `mrn_app`.
   - Prove the timing checks with tests on the real `mrn_app` connection (refusal paths), and prove the legitimate path in E2E, where the daily command runs as `mrn_app` after a fixture shifts the dates with `postgres`.
   - Use `session_user`, not `current_user`: inside a definer function `current_user` is the owner. Only a superuser can change `session_user`.
4. **Tests to add:**
   - as `mrn_app`: a direct `delete from users`, `delete from account_deletion_requests`, `delete from moderation_evidence` and an insert into `migrations` are all refused (42501);
   - `mrn_delete_account` refuses: an unknown request, one not due, one under 30 days, an account not marked, a cancelled or done request;
   - with a due request and content not handled, it fails on the existing checks (fail-closed);
   - the purge function keeps the exact D-10 boundaries (re-run the session 27 probe through it);
   - E2E deletion journey: account removed by the function as `mrn_app`;
   - the existing 30 deletion tests and the full browser suites;
   - the production checklist queries updated and re-run on all three databases.
5. **Docs:** production checklist (F1 rule, new revokes, function ACLs), `ACCOUNT_DELETION_AND_EXPORT.md`, handoff, report.

## 7. Risks and their handling

| Risk | Handling |
|---|---|
| App clock ahead of the database clock: the command picks a request the function refuses | fail-closed (rolled back, logged by class, retried next day). The checklist requires NTP on both hosts. |
| A rolled-back migration leaves code calling a missing function | fail-closed, as above. The rollback is also refused after first use. |
| Restore and replay of deletions (blocker 6) | a replay runs as the owner during the restore procedure, so it is not blocked by B; it must be documented with the replay tool. |
| Owner-role exemption misused | only the owner role and its members are exempt; `mrn_app` is not a member (verified). F1 keeps the owner credentials off the web runtime. |
| A cancellation by the rider during the window | unchanged (`scheduled → cancelled` is allowed by the request trigger); the function then refuses. |

## 8. Out of scope (recorded for the owner)

- **Account takeover by a compromised `mrn_app`:** it can update `users.email` or `password`. Mitigations would be a separate review: change notifications to the old address, and a definer-only path for email changes.
- **Content destruction through tables the app legitimately deletes from:** backups and the audit log are the controls.
- The account-id hash (deferred), the restore and replay tool, staff MFA, contact retention: see the production checklist §9.

## 9. Owner decisions needed

1. Approve F1 as a deployment rule: owner credentials never in the production web runtime.
2. Approve Option B for an implementation session (or choose A, C or D).
3. Whether to start the separate takeover review (§8).
