# Owner decision addendum, 22 November 2026

**Status: approved by the project owner.** This adds to `OWNER_DECISIONS_2026-11-22.md`. That record's approval boundaries still apply: no production data deletion, deployment, infrastructure change, external connection, purchase, remote Supabase access, commit, push or unapproved dependency.

| ID | Area | Decision | Implemented in |
|---|---|---|---|
| O8 | Contribution text in the audit history | **Redact**, through a privileged, audited process; the history stays append-only | session 26: function `mrn_redact_audit_text` (migration `2026_11_22_100025`), called by the deletion run |
| D-10 | Deletion request records | **Keep one year**, then delete automatically; minimum content only | session 26: the daily `mrn:accounts:process-deletions` |
| D-11 | Corroboration after removed confirmations | **Recalculate**; remove community corroboration below the threshold; keep staff decisions; keep the report published | session 26: `ProcessAccountDeletion` |

## What the owner specified

### O8: redaction

- Preserve the append-only history: entry ids, timestamps, action types and the needed security metadata.
- Only an authorised process may redact.
- Record each redaction as a separate immutable audit event: what was redacted, why, and which entry. No unnecessary personal information goes in that record.
- Never edit an entry silently.
- Check integrity implications first.

### D-10: retention

- Keep a request and its processing dates, completion or cancellation status, choice categories, counts, handover outcomes and minimal technical information.
- Never keep contribution text, photos, passwords, tokens or unnecessary identifiers.
- After one year, delete or anonymise.
- Legal exceptions must be justified and approved separately. No indefinite retention.

### D-11: recalculation

- Recalculate the remaining confirmations and compare them with the existing threshold.
- Below it, remove community corroboration.
- Keep independent staff verification and moderation decisions.
- Keep the report published, and make the API and the site show the new status.
- Community corroboration is not staff verification.

## How it was implemented (session 26)

### O8

- **Integrity investigation:** the audit log has no hash chain, signature or checksum, so redaction breaks no verification. If a chain is added later, it must hash the redacted form and link each `audit.redacted` record.
- **Append-only stays:** the app role (`mrn_app`) still has no `update`, `delete` or `truncate` on `audit_logs`.
  - It may only execute `mrn_redact_audit_text(entry, reason)`, a `security definer` function owned by the table owner. Execute is revoked from public.
  - The function accepts only the reason `account_deletion`, and only the entry types `contribution.edited`, `contribution.correction_added` and `place.resubmitted`.
  - It replaces only the text fields (`title`, `body`, `details`, `author_correction`, `name`, `summary`, `description`) with `"[redacted]"`. Ids, timestamps, actions, actors, subjects and IP addresses are untouched, and no key is removed.
  - In the same transaction it writes `audit.redacted`: actor type `system`, subject = the affected entry's id, with `{reason, entry_action, fields}` and no content or person.
  - Running it again on a redacted entry changes nothing and writes nothing.
- **When:** inside the account deletion transaction. It covers every contribution of the departing rider (deleted and anonymised), and the unreviewed place suggestions deleted with the account. Moderation evidence keeps its own restricted 30-day copy.
- **Not reachable over HTTP:** no endpoint or staff screen calls the function. A rollback of the migration is refused once any redaction exists.
- **Found and fixed:** the app role could update and delete audit rows in the development and E2E databases.
  - Cause: `scripts/setup-local-db.sh` grants update and delete on all tables, which undid the append-only revoke whenever the script ran after the migrations.
  - Fix: the script now re-applies the revoke, and the revoke was restored in both databases.
  - The test database was correct. Production must check this privilege before launch.

### D-10

- When a request finishes (`done`) or is cancelled, `choices` becomes category counts only (for example `{"delete": 1, "anonymise": 1}`); which contribution got which choice is no longer stored.
- `counts` holds the deleted, anonymised and removed counts, the handovers (`events_handed_over`, `trips_handed_over`), cancellations, deletions, the redacted audit entries and the withdrawn corroborations.
- Minimal technical information: `attempts` and the exception class.
- **The record still holds `user_id`:** the pseudonymous id of the deleted account, kept so that a deletion can be applied again after a database backup restore. It is gone with the record after a year.
- **After one year:** the daily command deletes `done` and `cancelled` records whose completion or cancellation is a year old, and writes `account.deletion_records_purged` with the number deleted. Scheduled requests are never purged.

### D-11

At the run, for each report the departing rider had confirmed:

- `last_confirmed_on` is recomputed.
- If the report is `community_corroborated` **because of the threshold**, the remaining distinct confirmers (not the author) are counted against the current threshold. Below it, the status becomes `published` and `contribution.corroboration_withdrawn` is audited with the count and the threshold.
  - "Because of the threshold" means the latest audit entry that set the status has `reason: threshold`.
- The count includes every remaining confirmation, without the corroboration time window or other riders' current standing. Only the removal can change the outcome.
- A corroboration a moderator set is kept, and so is `officially_sourced` (staff verification). The report stays published, and the API returns the new status.

## Owner follow-up to the independent review (session 27), implemented in session 28

| Item | Owner decision | Implemented |
|---|---|---|
| R8 moderator accuracy date | must fix | yes: `contributions.staff_confirmed_on` (backfilled from `contribution.confirmed` audit entries); the deletion run never lowers `last_confirmed_on` below it, and leaves a date it did not set untouched |
| R1 fake redaction records | fix before production | yes: trigger `audit_logs_system_guard`; only the table owner (the definer function) or a superuser may write `audit.redacted` or actor type `system` |
| R2 redact only deleted-account contributions | fix before production | yes: `mrn_redact_audit_text(entry, request)` checks the request (scheduled, due by the database clock, a full 30 days after it was made, account marked for deletion) and the entry's subject (contribution `author_id`, or an unreviewed place `submitted_by`, equal to the request's account). Trigger `account_deletion_requests_guard` stops the app role fabricating a due request |
| R5 sensitive error logging | fix before production | yes: the run and all three daily commands log only the step, the request id and the exception class |
| Raw account id vs keyed hash | **deferred** | not changed; needs a restore and replay design with a key lifecycle |
| Daily command on/off switch | **approved** | yes: `ACCOUNT_DELETION_PROCESSING_ENABLED` and `ACCOUNT_DELETION_RECORDS_PURGE_ENABLED`, independent, both default `false` |
| R9 recount interpretation | **confirmed** | kept: the remaining confirmations are recounted without the corroboration window and without re-checking other riders' standing; moderator and official-source statuses are kept (test `test_r9_…`) |

