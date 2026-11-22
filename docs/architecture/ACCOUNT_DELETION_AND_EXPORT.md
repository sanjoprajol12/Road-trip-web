# Account deletion and data export: implementation design (G19, H11)

**Status:** implemented and tested in session 25 (2026-11-22); see "As built" below. The design sections after it are kept as written; where the build differs, "As built" says so.

## As built (session 25)

**Code.**
- Migration `2026_11_22_100024_add_account_deletion`.
- Actions in `app/Actions/Accounts/`: `DeletionPlan`, `ScheduleAccountDeletion`, `TransferOwnership`, `ProcessAccountDeletion`, `DisposeModerationEvidence`, `ExportAccountData`.
- `app/Support/PendingFiles.php`.
- Controllers `V1\AccountDeletionController`, `V1\OwnershipTransferController` and `Admin\PrivacyController`.
- The command `mrn:accounts:process-deletions`, scheduled daily at 02:30.
- Tests: `tests/Feature/AccountDeletionTest.php` (15 tests), `frontend/e2e/account-deletion.spec.ts` and `admin/e2e/privacy.spec.ts`.

**Endpoints.**

| Endpoint | Who | What |
|---|---|---|
| `GET users/me/deletion` | signed-in rider | request (if any), contributions with `eligible` and `open_case`, events and trips with their outcome and offer |
| `POST users/me/deletion` | signed-in rider, 5/hour | `password`, `confirm`, `choices` {contribution id → `delete` \| `anonymise` \| `anonymise_without_photos`}; every public contribution needs a choice, and ids that are not the rider's public ones are refused |
| `DELETE users/me/deletion` | signed-in rider, 5/hour | `password`; the only way to cancel |
| `POST users/me/export` | signed-in rider, 5/day | `password`; ZIP in the response, `Cache-Control: no-store` |
| `POST users/me/deletion/offers`, `DELETE …/offers/{id}` | the leaving rider | offer an event or group trip to one rider, or withdraw the offer; one open offer per item |
| `GET users/me/ownership-offers`; `POST ownership-offers/{id}/accept\|decline` | the recipient (confirmed, not suspended, not leaving) | someone else's offer answers 404 |
| `GET admin/account-deletions` | `users.deletions.view` | dates, status, counts, attempts and last error class; no user id, email or content |
| `GET admin/moderation-evidence` (metadata only), `GET …/{id}` and `GET …/{id}/files/{n}` (each audited `moderation.evidence_viewed`), `POST …/{id}/release` | `moderation.evidence.view` | restricted evidence |

**What the rider sees.**
- Account → "Your data": export, and the deletion form (every choice explained, nothing pre-selected, the identification warning, password and a confirmation box).
- During the cooling-off:
  - a banner with the date;
  - Cancel deletion;
  - handover forms for events (username of an approved organizer) and group trips (choose a member).
- Recipients answer under "Offers to take over".
- Notifications: `ownership_offered`, `ownership_accepted`, `ownership_declined`, `trip_closed`, and `event_update` (new organizer).
- Emails: scheduled, cancelled, and completed (sent to the old address after the deletion).

**Cooling-off.**
- The account can sign in, read, export, cancel and make offers.
- `not-suspended` routes answer 403, with the date and how to cancel. The rider cannot post, confirm, report, join, host, accept invitations or accept offers.
- The rider cannot be invited to a trip or an event, and the public profile answers 404.
- Other sessions end when the request is made. **Signing in never cancels** (tested).

**The run (`ProcessAccountDeletion`).**
- One transaction per request, holding row locks on the request and the user.
- A failure rolls everything back. Only `attempts` and `last_error` (the exception class) are kept, and the next daily run retries.
- A finished request is never run again.
- Order:
  1. offers lapse;
  2. events: those still needing a handover are cancelled and kept with `organizer_id = null` (an `event_updates` cancellation, and riders notified after the commit); others are deleted;
  3. owned trips: deleted, with members notified (title only);
  4. contributions:
     - open cases are copied to `moderation_evidence`;
     - public ones follow the recorded choice, and any without a recorded choice is **deleted**;
     - non-public ones are deleted;
  5. open reports the rider filed on others' content are copied to evidence, then all their reports are deleted;
  6. confirmations given are deleted, and `last_confirmed_on` is recomputed from those left. A threshold corroboration that falls below the threshold goes back to `published` (D-11, session 26); a moderator's corroboration is kept, and the report itself is never deleted;
  7. unreviewed place suggestions are deleted; published places keep `submitted_by = null`;
  8. the rider's notifications and password reset tokens are deleted;
  9. the user row goes, and the remaining rows cascade or are set null as in §1;
  10. places held on others' events are freed, and the waitlist moves up;
  11. the request becomes `done`, with counts and choice categories (no contribution ids, D-10); audit `account.deleted` with counts only. Before step 4, the text of the rider's contributions and deleted place suggestions is redacted in the audit history (O8, session 26).
- **Files** are queued in `pending_file_deletions` inside the transaction and removed by the command after the commit.
  - A failed removal stays queued with `attempts` and the error class, and is retried on the next run.
  - A file still used by a photo row or by held evidence is never removed.

**Anonymised contributions:**
- `author_id = null` and `author_deleted_at` set.
- The API shows `author: {id: null, display_name: "Former rider", username: null, joined: null, verified: []}`.
- Text, details, the author's own correction and (unless removed) photos stay.
- Free text is not scrubbed (O3). The rider is told to choose Delete if the text or photos identify them.

**Moderation evidence:**
- Kept for open cases on the rider's contributions and for open reports the rider filed.
- Holds the text, reports, author name and username, and photo references.
- Held 30 days from the run. A database check stops `held_until` exceeding `created_at + 30 days`, so a hold cannot be extended.
- Staff can release it early. Expiry runs in the daily command. Either way the snapshot and file references are nulled, the photos are removed, and only the reason, dates and outcome remain.

**Database safety nets:**
- `contributions.author_id` and `events.organizer_id` are now nullable and set null.
- A check allows a null author only with `author_deleted_at`, and a null organizer only on a cancelled event. **A raw `delete from users` for a rider who still authors content now fails** instead of silently cascading. The E2E cleanup helpers delete test content first.

**Differences from the design:**
- Transfers take effect **when accepted**, not at the run. The leaving trip owner becomes an editor until the run, so a cancelled deletion still finds the trip.
- No `failed` status. A failing request stays `scheduled`, and staff see its attempts and error class.
- Offers cascade away with the deleted account (the audit keeps `ownership.*` entries).
- The export is always streamed and needs the password (D1 is not decided, so no stored file or link).
- `contribution_suspensions` rows cascade with the account.

**Hardened in session 28** (owner follow-up to the session 27 review; see the addendum):

- **Daily jobs:** three commands, scheduled daily.
  - `mrn:accounts:process-deletions` (02:30) runs only with `ACCOUNT_DELETION_PROCESSING_ENABLED=true`.
  - `mrn:accounts:purge-deletion-records` (03:10) runs only with `ACCOUNT_DELETION_RECORDS_PURGE_ENABLED=true`.
  - The two switches are independent, and both default to off. A switched-off command says so and changes nothing.
  - `mrn:accounts:expire-evidence` (02:50) has no switch: it only ends 30-day evidence holds and retries failed file removals (D-04 allows no longer hold), and does nothing before any deletion ran.
  - Local development: both off, unless you set them in `.env`. Tests switch them on per test. The E2E journey switches processing on for its own command call.
- **Production activation requirements:**
  - owner approval;
  - a cron for `php artisan schedule:run`;
  - a production mail provider (O5);
  - `has_table_privilege('mrn_app', 'audit_logs', 'update')` and `delete` both false;
  - both triggers present;
  - then set the switches.
- **R8:**
  - A moderator's "still accurate" date is kept in `contributions.staff_confirmed_on` (migration `2026_11_22_100026`, backfilled from the audit log).
  - The run lowers `last_confirmed_on` only if the departing rider's latest confirmation could have set it, and never below the moderator's date.
- **R1, R2:**
  - Only the definer function (table owner) may write `audit.redacted` or actor type `system` (trigger).
  - The function now takes the deletion request and checks it is due by the database clock after a full 30 days, and that the account is marked for deletion.
  - It also checks that the entry's subject belongs to that account: contribution `author_id`, or an unreviewed place `submitted_by`. The first `place.suggested` version is covered.
  - The app role cannot backdate or revive a request: a trigger sets `requested_at` from the database clock, keeps `run_after` at least 30 days later, and refuses changes to the account or dates and any status move other than scheduled → cancelled or done.
  - **Residual risk:** a compromised app role could create a request for another rider. It can only be used 30 real days later, and the rider sees the deletion banner meanwhile.
- **R5:** failures log the step, request id and exception class only.
- **Rollback:** `2026_11_22_100026` refuses to roll back once any redaction is recorded. Without redactions, it drops the triggers and the column and restores the session 26 function.

**Decided and built in session 26** (addendum `docs/product/OWNER_DECISIONS_2026-11-22_ADDENDUM.md`):
- **O8:** contribution text in the audit history is redacted by the privileged function `mrn_redact_audit_text` during the run, and each redaction is recorded as `audit.redacted`.
- **D-10:** finished records keep choice categories and counts only, and are deleted after one year.
- **D-11:** a threshold corroboration is withdrawn when the remaining confirmers fall below the threshold; moderator decisions are kept.

**Still open:**
- **Other riders' notifications** that name the leaving rider (for example "X invited you") are theirs and are kept.
- **Not built:** the reminder email 3 days before the run, and a signed cancel link in the email (the email links to the account page; cancelling needs sign-in and the password).
- **Hosting:** the scheduler needs `schedule:run` on the host (no host yet, D-08 and D-09); mail needs a production provider (O5).


The decisions come from `docs/product/OWNER_DECISIONS_2026-11-22.md`:

- **Q9 option D:** the rider chooses per contribution.
- **D-01:** 30-day cooling-off.
- **D-02:** events are transferred, or cancelled with notification.
- **D-03:** group trips are transferred with consent, or deleted with notification.
- **D-04:** moderation evidence is held for at most 30 days.

Items still open with the owner are marked **[open O2]** and **[open O3]** (numbering from section 5 of the record).

## 1. Personal data inventory (from the migrations)

### Rider-owned

| Table / column | Personal data | Delete rule today |
|---|---|---|
| `users` | email, username, display name, bio, home city, password hash, suspension reason | — |
| `sessions.user_id` | IP address, user agent | cascade |
| `emergency_contacts` | other people's names, phones, relations | cascade |
| `user_verifications` | statement, review note (identity claims) | cascade |
| `user_roles` | granted rider roles | cascade |
| `saved_items` | bookmarks | cascade |
| `trips.owner_id`, `trip_members`, `trip_invitations` | private plans, notes, costs | cascade (**an owned group trip disappears for every member**) |
| `trip_expenses.paid_by` | who paid | set null |
| `events.organizer_id` | events organised | cascade (**other riders' registrations go too**) |
| `event_registrations` | sign-ups | cascade |
| `event_updates.author_id` | posts to participants | set null |
| `contributions.author_id` | reports, experiences | cascade (changes: see section 6) |
| `contribution_media` (through contributions) | photos; **files on disk are not deleted today** | cascade (rows only) |
| `contribution_confirmations.user_id` | confirmations | cascade |
| `contribution_reports.reporter_id` | reports filed against content | cascade |
| `contribution_suspensions.user_id` | moderation history | cascade |
| `places.submitted_by` | suggested places | set null |
| `place_corrections.user_id` | corrections | cascade |
| `staff_accounts.rider_user_id` | link from a staff account | set null |
| `notifications` (morph `notifiable_id`, **no FK**) | notification texts | **stays orphaned today** |
| `audit_logs.actor_id` (no FK, append-only) | who did what | kept by design |

### Rider as actor on other records (set null; nothing to erase beyond the id)

`user_roles.granted_by`, `user_verifications.reviewed_by`, `contribution_reports.resolved_by`, `destinations` and `routes.created_by`, `guides.author_id` and `expert_reviewed_by`, `settings.updated_by`, `media_assets.uploaded_by`, `staff_invitations.invited_by`, `trip_invitations.invited_by`.

### Not linked to an account

- `contact_messages` holds name and email but has no user link. It is covered by the contact retention plan.

## 2. Flow

```
Rider: Account → "Delete my account"
  1. Explanation: the effective date (request + 30 days), what stops now, what is kept, and how to cancel.
  2. Re-authentication: current password (Laravel password confirmation, max age 10 min) plus typing the
     username. Rate-limited (5 per hour).
  3. Contribution choices (2.2): one choice per eligible contribution, explained before confirming.
  4. Events and group trips (2.3): the screen lists them with the transfer that will be attempted.
  5. Request recorded: account_deletion_requests row (status scheduled, run_after = now + 30 days).
     Confirmation email with the date and the cancel link. All other sessions end.
     The account is deactivated (2.1).
  6. Cooling-off (30 days): the rider can sign in only to see "Deletion on <date>", cancel, export,
     change their contribution choices, and see transfer progress.
  7. Run, on or after run_after: the transaction in section 3, then file cleanup.
  8. Result: an email to the old address (sent before the address is erased), and the deletion record.
```

### 2.1 During the cooling-off

- **Deactivated, not destroyed:**
  - the profile is hidden and the rider cannot post, confirm, register, be invited, or join trips;
  - existing published contributions stay visible until the run, so nothing is lost if the rider cancels.
- **Cancel:** an explicit "Cancel deletion" button after sign-in (password re-entered), or the signed link in the email followed by sign-in.
  - Status becomes `cancelled` and the account is reactivated. Pending transfer offers are withdrawn.
  - **Signing in alone does not cancel;** the request is only cancelled on purpose.
- **Export:** available throughout (section 5). The final email reminds the rider 3 days before the run.

### 2.2 Contribution choices (Q9 option D)

**Eligible** means the rider's contributions that are public: status `published`, `community_corroborated`, `officially_sourced` or `outdated`. For each one, the rider chooses:

| Choice | Effect at the run |
|---|---|
| **Delete** | row, photos (rows and files) and its confirmations are removed |
| **Keep anonymously** | `author_id = null`, `author_deleted_at = now()`; shown as "Former rider"; text and photos stay |
| **Keep anonymously and remove photos** | as above, and the photos (rows and files) are removed |

- **No default:** the request cannot be submitted until every eligible contribution has a choice. A "Apply to all" control is allowed, but nothing is pre-selected.
- **Warning shown with both keep choices:** removing the name does not remove what the text or photos themselves reveal (people named in the text, faces, number plates). The rider can edit the text during the cooling-off.
- **Always deleted (not eligible):**
  - `pending_review`, `rejected` and `flagged` contributions;
  - confirmations the rider gave (one per rider per day, so they cannot be anonymised without losing their meaning), which lowers other reports' corroboration counts;
  - reports the rider filed, and place corrections.
- **Cannot be anonymised safely [open O3]:** until the owner decides, the design only knows the author link and display name as identifying fields. It does not try to scrub free text. Content under an open moderation case is handled by 2.4, whatever the choice.
- **Change of mind:** the choices can be edited until the run. The choices applied are recorded per contribution id in the request (no content).

### 2.3 Events and group trips (D-02, D-03)

**Events** organised by the rider with registrations (status not `cancelled` or `completed`):

1. The rider nominates another organiser, who **accepts in the app**. The nominee must hold an approved `event_organizer` verification and not be suspended (the same rule as `EventPolicy` for creating an event) **[open O2: confirm eligibility]**.
2. If accepted: `events.organizer_id` changes, the registered riders are notified, and the change is audited.
3. If no transfer is accepted by the run: the event is cancelled through the existing cancellation path (`event_updates` kind `cancellation`), and every registered rider is notified.
   - The event row is kept as `cancelled` with `organizer_id = null` (minimum record for the notification history and accountability).
   - This needs `events.organizer_id` to change from cascade to set null.
- Events without registrations, and past or cancelled events, are deleted.

**Group trips** owned by the rider with other members:

1. The rider offers ownership to a member (`editor` or `viewer`) **[open O2: confirm who can be offered]**. The member accepts or declines in the app. **Nobody becomes owner without accepting.**
2. If accepted: the member becomes `owner`, the leaving rider's membership is removed at the run, and the change is audited.
3. If nobody accepts by the run: the trip is deleted, and every member is notified first (by notification and email, without the trip's private content).
- Trips with no other members are deleted.

### 2.4 Moderation cases (D-04)

**Open case:** a report against one of the rider's contributions with `resolved_at` null, or a contribution with status `flagged`.

- **The account deletion is never delayed past day 30.**
- At the run, the content involved in an open case, whatever the rider chose, is copied into `moderation_evidence`, and the public row is removed or anonymised as chosen. The copy holds the text, the photo paths, the report and its category.
- **Restricted:** only staff with the new permission `moderation.evidence.view` can see it. Every view is audited (`moderation.evidence_viewed`).
- `held_until = run time + 30 days`, with a reason, and a disposition (`deleted` or `anonymised`) recorded when it ends. The daily purge removes expired evidence and its files.
- **No extensions.** A longer hold would need a legal exception, which is **[open O7]** and not built.

## 3. Deletion transaction (idempotent and resumable)

`php artisan accounts:process-deletions` runs daily from the scheduler. Each request runs in one database transaction; files are handled after the commit.

1. Lock the request row (`for update skip locked`). Skip it unless it is `scheduled` and `run_after` has passed.
2. **Events:** apply the accepted transfers, then cancel the rest and notify, as described in 2.3.
3. **Group trips:** apply the accepted transfers, then delete the rest and notify.
4. **Moderation evidence:** copy the content of open cases into `moderation_evidence` (2.4).
5. **Contributions:** apply each recorded choice. Queue the photo files of "Delete" and "remove photos" contributions.
6. Ineligible contributions, confirmations, corrections, filed reports, saved items, emergency contacts, verifications, sessions, trip memberships and invitations: delete them explicitly, so the order is predictable.
7. **Notifications:** delete rows where `notifiable_type = 'App\Models\User'` and `notifiable_id = id` (no foreign key).
8. **The user:** delete the `users` row. The id survives only in `audit_logs.actor_id` (pseudonymous, no lookup).
9. **The request:** set status `done` and `completed_at`, and record counts per category plus the choices per contribution id (no content). Write the audit entry `account.deleted` with the counts.
10. **After the commit:** delete the queued files. A failed delete goes to `deletion_file_failures` and is retried on the next run. The database never points at a file that should not exist.

**Failure recovery:**

- **All-or-nothing:** a crash before the commit leaves the request `scheduled` for the next run; after the commit, only file deletes can be pending, and they are retried.
- Re-running a `done` request is a no-op.
- **Staff view:** staff see `failed` requests in a small staff list (new permission `users.deletions.view` in `config/permissions.php`, not in the moderator defaults).

## 4. What is kept after deletion

| Kept | Why | How long |
|---|---|---|
| `audit_logs` entries naming the id | security and accountability (append-only) | indefinitely, pseudonymous (no name or email) |
| `account_deletion_requests` row (id, dates, counts, choices by contribution id) | proof the request was honoured; the minimum record (D-01) | **period not decided**; propose 2 years |
| Anonymised contributions the rider chose to keep | the rider's choice | while published |
| `moderation_evidence` for open cases | D-04 | at most 30 days, restricted |
| Cancelled events, with `organizer_id` null | notification history (D-02) | as other events |
| Database backups | provider backups expire on their own | the backup window (stated in the privacy policy); a restore re-runs completed deletions from the request rows |

**Not kept:** an email hash to block re-registration (not approved).

## 5. Data export

- **Request:** Account → "Download my data", also available during the cooling-off. Rate-limited to 1 per 24 h.
- **Build:** a job (synchronous for small accounts, as the hosting has no worker) produces one ZIP with:
  - `profile.json`: account fields without the password hash, roles, verifications.
  - `contributions.json` and `photos/` (the stored WebP files).
  - `trips.json`: trips the rider owns or belongs to; other members appear by display name only.
  - `events.json` (organised and registered), `saved.json`, `emergency_contacts.json`, `notifications.json`.
  - `README.txt`, explaining each file.
- **Delivery:** the ZIP is stored privately (durable disk, D1) with a signed, single-use link valid for 24 h, emailed to the account address and shown in the app. The file is deleted after download or expiry.
- **Before D1:** the export streams directly in the response; no file is stored.
- **Excluded:** other riders' private data (their emergency contacts, private trip notes in trips the rider merely viewed are included only as trip content they could already see), staff notes, and audit logs.

## 6. New schema (additive, except two constraint changes)

- `account_deletion_requests`: `id`, `user_id` (no FK, so it survives the user), `status` (scheduled, cancelled, done, failed), `requested_at`, `run_after`, `cancelled_at`, `completed_at`, `choices` jsonb (contribution id → choice), `counts` jsonb, `failure` text.
- `ownership_transfers`: `subject_type` (event or trip), `subject_id`, `from_user_id`, `to_user_id`, `status` (offered, accepted, declined, withdrawn), and the dates.
- `moderation_evidence`: `id`, `contribution_id` (no FK), `report_ids`, `snapshot` jsonb, `files` jsonb, `reason`, `held_until`, `disposition`, `disposed_at`.
- `deletion_file_failures`: `disk`, `path`, `attempts`, `last_error`.
- `users.deletion_scheduled_at` (nullable), for the deactivated state.
- **Constraint changes:**
  - `contributions.author_id` changes from cascade to set null and becomes nullable, plus a new `author_deleted_at` column.
  - `events.organizer_id` changes from cascade to set null.
  - Both migrations are reversible as long as no rows with a null link exist.
- **Permissions:** `users.deletions.view` and `moderation.evidence.view`.

## 7. Tests

**Feature tests:**

- Every choice (delete, keep anonymously, keep without photos) checked against every table in section 1. A request with an eligible contribution and no choice is refused.
- Re-authentication is required and rate-limited.
- **Cooling-off:**
  - the account is deactivated but its data is intact;
  - signing in does not cancel; the cancel action does;
  - nothing runs before day 30 (time travel to day 29 and day 30).
- **Events:** transfer accepted; transfer declined, then cancelled at the run with every registered rider notified; the nominee's eligibility is checked.
- **Group trips:** transfer only after acceptance; no acceptance means the trip is deleted at the run and the members are notified.
- **Moderation:**
  - open-case content is copied to the evidence table and only `moderation.evidence.view` can read it, each access audited;
  - the purge on day 31 deletes the evidence and files;
  - there is no way to extend a hold.
- **Robustness:** an idempotent re-run is a no-op; a crash mid-run rolls back; a failed file delete is retried.
- **Privacy:** notifications are removed; the audit entry and the request row hold no content.
- **Export:** it contains exactly the rider's own data and nothing of other riders' private data; the signed link expires.
- `RouteAuthorizationTest` covers the new routes.

**E2E:**

- Request deletion with choices; sign in during the cooling-off, see the date, cancel.
- A transfer offer accepted by another rider.
- Export download.

## 8. Effort

About three sessions:

1. Deletion, cooling-off and choices.
2. Transfers and moderation evidence.
3. Export and the E2E tests.

Before session 2, the owner confirms **[open O2]**.
