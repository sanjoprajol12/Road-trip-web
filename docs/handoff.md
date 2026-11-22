# Handoff

Last updated: 2026-11-22, session 30 (database-level deletion security design review: F1 owner credentials in the production runtime template; Option B recommended; nothing implemented). Earlier: 2026-11-22, session 29 (final verification: full public 173 passed / 0 failed; deletion requests proven own-account only; evidence job verified; production checklist). Earlier: 2026-11-22, session 28 (review follow-up: R8 moderator date kept, R1/R2 database guards, R5 safe logging, independent deletion and purge switches default off, R9 kept; H11 complete again, 179/261). Earlier: 2026-11-22, session 27b (homepage header follows the rendered hero; one h1 in every homepage state; staff create forms guarded against double submits). Session 27 (independent review of session 26: O8 and D-10 verified with conditions; D-11 defect found (moderator confirmation date reset); H11 back to Partial, progress 178/261). Earlier: 2026-11-22, session 26 (owner addendum built: O8 audit text redaction through a privileged audited database function, D-10 deletion records kept one year with categories and counts only, D-11 corroboration recalculated; append-only audit privilege restored in dev and E2E). Session 25 (account deletion step 2: request with per-contribution choices, 30-day cooling-off, explicit cancel, export, event and trip handover by acceptance with cancel/delete fallback, restricted 30-day moderation evidence, retryable file cleanup; tested; `web/CLAUDE.md` restored). Session 24 upload safety switch (central upload policy: production never accepts uploads on the local disk, staff and environment pause switches, critical alert, no orphans; tested; no storage provider configured). Session 24 owner decisions (recorded: Q9 option D, 30-day cooling-off, event and trip transfers, 30-day moderation hold, contact retention periods, mandatory staff MFA, P1 hosting candidate with no budget; four plans updated, no code). Session 23 (infrastructure and privacy readiness: plans for hosting, uploads, account deletion and export, staff MFA, contact retention; CI workflows updated). Session 22 (production-readiness audit: accessibility, performance, browsers, security, infrastructure, data; 2 accessibility defects fixed). Session 21 (complete verification; homepage shows the product: specific headline, working route planner with a real route preview, tools list). Session 20 (F4 and F15: trip days, overnight stops, planned and spent costs, checklist and notes; recorded tracks on the trip map). Session 19 (F3: whole routes, stretches and checkpoints in trips; stop reordering). Session 18 (route planning: plan table, fuel gaps, riding time per stretch, route filters, service markers with details; verification and audit closure). Session 17 follow-up (places.review for moderators; correction of the session 17 test report). Session 17 (places in trips and trip maps; real route tracks drawn on destination and route maps; place permission audit). Session 16 (places of interest: staff management, rider suggestions and corrections, public pages, maps, journeys, saved items, experiences; map stacking fix). Session 15 (destination maps: staff map picker, public map and nearby lists; progress measurement baseline; edit-form crash fixed). Session 14 (PostGIS pre-activation audit, inspection only: nothing changed). Session 13 (CMS modules and contact inbox; mail, maps, security, trust settings; trip invitations with consent; journey add-to-trip; corroboration threshold; contribution-only suspensions; event removal and postponement dates; accessibility and Web Vitals checks). Session 12 (Stage 4 trust pipeline; saved services; Explore Along My Route in curated mode). Session 11 (one command for API + public website: `npm run dev` in `web`). Session 10 (migration stages A1–A8 done: Option A layout, independent apps, deployment config, route split, separate staff authentication, feature services, dynamic homepage and CMS foundation). Session 9: architecture investigation. Session 8: roadmap Stages 1, 2 and 3 done (Stage 3 for the local disk only). Earlier sessions: 1 foundation, 2 Supabase auth and verification, 3 removed shared package, 4 Laravel and product areas, 5 Vercel container prep, 6 requirements audit, 7 scope expansion + architecture Option B.

## 1. Goal
My Ride Nepal, a motorcycle travel platform for Nepal: public website + rider platform, Laravel API, staff portal and CMS, all requirements in `docs/architecture/REQUIREMENTS_GAP_ANALYSIS.md` (261 rows). **Architecture Option A** (owner direction 2026-11-18, replaces Option B):
- `web/` (repo Road-trip-web): Laravel 12 API at the root + public website in `web/frontend/` (own package.json, .env, vercel.json, E2E).
- `admin/` (repo Road-trip-admin): staff portal only.
- Riders and staff are separate accounts (`users` / `staff_accounts`) with separate guards, sessions, sign-in, resets and provisioning.
- Supabase = local Postgres + Mailpit only. No code shared between repos. Hosting target Vercel (nothing deployed).

## 2. Current State
- **Session 30 (design review only; no code, schema, privilege or data change):**
  - **Design:** `docs/security/ACCOUNT_DELETION_DB_SECURITY_DESIGN.md`. **Report:** `docs/sessions/2026-11-22-session-30-db-deletion-security-review.html`.
  - **Findings:**
    - **F1 (high):** `.env.production.example` and `config/database.php` put the owner credentials (`DB_MIGRATION_*`) in the web runtime. Whoever reads that environment bypasses every database protection: the append-only audit log, the guard triggers and the redaction checks.
    - **F2:** `mrn_app` can delete from 57 tables, including `users`; only the deletion run needs to.
    - **F3:** it can also delete `account_deletion_requests` (only the purge needs that) and `moderation_evidence` (never needed).
    - **F4:** it has full DML on `migrations` (never needed), so it could make a future migration be skipped.
  - **Recommended (Option B):**
    - keep the owner credentials out of the runtime (deploy-step secret);
    - revoke DELETE on `users`, `account_deletion_requests` and `moderation_evidence`, and writes on `migrations`;
    - two definer functions: `mrn_delete_account(request)`, which checks a due request by the database clock, and `mrn_purge_deletion_records()`.
    - Options A (no change), C (a separate jobs role) and D (all in SQL) were assessed and not recommended.
  - **Production checklist:** gains §0 (no owner credentials in the runtime, with a check that does not print secrets) and blocker 12.
  - **Progress unchanged:** 179/261.
- **Session 29 (final verification of session 28; tests and docs only, no application code changed):**
  - **Report:** `docs/sessions/2026-11-22-session-29-final-verification.html`.
  - **Full public browser suite after the final trigger fix:** 173 passed, 0 failed, 10 skipped (Chromium, Firefox, mobile; exit 0). Staff: 57 passed, 1 skipped (exit 0).
  - **Deletion requests:**
    - Over HTTP a rider can only schedule their own deletion: the route takes no user parameter and the action uses the signed-in rider. Tested:
      - guest 401;
      - a staff session is refused (401);
      - a body naming another rider schedules only the caller, and the other rider's account, sessions and mail are untouched;
      - the audit actor is the caller;
      - cancel acts on the caller's request only.
    - At the database level, the application role cannot be tied to a signed-in rider. A compromised app role can still insert a request naming another rider: it can't be used for 30 days and changes nothing on that rider's account (tested). Closing that further needs an architectural change (blocker list in the checklist).
  - **Evidence job (`expire-evidence`):**
    - Kept through day 29, expired exactly at `held_until`. Earlier removal only through the release endpoint (moderator without the permission: 403).
    - Repeated runs are safe (one `moderation.evidence_expired` entry).
    - A photo still shown by an anonymised contribution is kept.
    - A failed removal stays queued and is completed by the next run.
    - Logs hold no contribution text.
  - **Production checklist:** `docs/architecture/PRODUCTION_PRIVACY_CHECKLIST.md` (role, privileges, triggers, function, switches, cron, mail, backup and restore, 11 blockers). Its queries were validated on all three local databases.
  - **Progress unchanged** at 179/261 = 68.6%, coverage 82.5%.
- **Session 28 (follow-up to the session 27 review; code, tested):**
  - **Report:** `docs/sessions/2026-11-22-session-28-review-follow-up.html`.
  - **Migrations:** `2026_11_22_100026_harden_account_deletion` and `2026_11_22_100027_guard_privilege_by_owner_membership` (dev and E2E migrated).
  - **R8 (complete):** `contributions.staff_confirmed_on` (set by the staff confirm action; backfilled from `contribution.confirmed` audit entries). The deletion run lowers `last_confirmed_on` only when the leaving rider's confirmation could have set it, and never below the moderator's date.
  - **R1 (complete):** trigger `audit_logs_system_guard`. Only the owner role or its members (and so the definer function) may write `audit.redacted` or actor type `system`.
  - **R2 (complete):** `mrn_redact_audit_text(entry, request)` checks:
    - the request: scheduled, due by the database clock, and at least 30 days after it was made;
    - the account: marked for deletion;
    - the subject: the contribution's author or the unreviewed place's submitter equals the account.
    - Trigger `account_deletion_requests_guard` stops the app role backdating, re-dating, re-pointing or reviving a request.
  - **R5 (complete):** the run and the commands log the step, request id and exception class only.
  - **Switches (complete):** `ACCOUNT_DELETION_PROCESSING_ENABLED` and `ACCOUNT_DELETION_RECORDS_PURGE_ENABLED`, independent, both default false.
    - Three commands: `process-deletions` (gated), `purge-deletion-records` (gated), and `expire-evidence` (ungated: 30-day evidence end and file retry only).
  - **R9 (confirmed and tested).**
  - **Low priority, done:** `place.suggested` is redactable; the staff audit page and two docs reworded; the Firefox focus test stubs the external map tiles (same assertions).
  - **Deferred by the owner:** the account-id HMAC.
  - **Residual risk:** a compromised app role can create a deletion request for another rider. It is usable only 30 real days later, and the rider sees the deletion banner meanwhile.
  - **Progress:** 179/261 = 68.6% (was 68.2%), coverage 82.5%. H11 is complete, tested again; the denominator is unchanged.
- **Session 27b (three UI fixes from the read-only UI audit; code, tested):** report `docs/sessions/2026-11-22-session-27b-header-headings-save-guards.html`.
  - **Header:** transparent only while a home hero is mounted (`heroesOnScreen` in `lib/site.ts`, counted by `HeroSection`). Loading, API failure, a hero removed by staff and other pages get the normal header.
  - **Headings:** the homepage has exactly one h1 in every state: hidden ones for loading, error and no-hero; the hero keeps its own.
  - **Staff double submits:** guide, destination, CMS entry and route service point forms each have a saving flag, a handler guard, a disabled "Saving…" button, and restore in `finally`. Service points have no unique field, so this one prevented real duplicate rows.
  - **Test config:** both `tsconfig.vitest.json` files no longer override `lib` to `[]`. These are the first component tests, and they compile the `.vue` files, which use ES2022 (`.at()`).
  - The owner commits "fix system" (web `9ba251e`, admin `4fcd155`) include these source and unit-test changes. The two new E2E specs are still untracked.
- **Session 27 (independent review of session 26; no code changed):** report `docs/sessions/2026-11-22-session-27-independent-review.html`.
  - **Verdict: accepted with conditions.**
    - O8 and D-10 are verified. D-11 is accepted except for defect R8.
    - Security hardening R1, R2 and R5 is needed before production.
    - A separate focused implementation task is requested (see §6).
  - **Privilege evidence (as `mrn_app` in `myridenepal`, `myridenepal_test`, `myridenepal_e2e`):**
    - `audit_logs` grants are INSERT and SELECT only. Direct UPDATE, DELETE and TRUNCATE are refused.
    - The function is executable by `mrn_owner` and `mrn_app` only. The role cannot create in public or create temp tables, and holds no elevated attributes.
    - `scripts/setup-local-db.sh` was run twice; the privileges were unchanged afterwards. The E2E redaction check record is kept.
  - **Findings:**
    - **R8 (medium):** the deletion run sets `last_confirmed_on` from rider confirmations only, so a moderator's "still accurate" date is lost. Reproduced: today → null.
    - **R1 (medium):** the app role can insert forged `audit.redacted` or `system` rows.
    - **R2 (medium):** the function cannot tell whether the entry belongs to a deleted account, so any app-role caller can redact a live contribution's history (the redaction is still recorded).
    - **R5 (medium):** a failed run writes the full exception (SQL with bindings) to the Laravel log, outside D-10.
    - **Low:**
      - R3: `place.suggested` (name, coordinates) is not redacted.
      - R4: docs and the staff audit page say entries "cannot be edited".
      - R6: handover counts include an earlier cancelled request.
      - R7: the purge audit is labelled actor `user`.
      - R9: the D-11 count ignores the window (an interpretation).
  - **HMAC assessment:** feasible. Its value is limited while `audit_logs.actor_id` keeps the raw id. A restore cannot reproduce per-contribution choices (D-10 keeps categories only), and no restore tool or runbook exists. Owner decision needed; no schema change made.
  - **Production:** nothing is activated (no host, no cron, and the dev scripts don't run the scheduler). The schedule is registered without an env switch.
  - **Progress corrected:** 178/261 = 68.2%, coverage 82.3% (H11 back to Partial).
- **Session 26 (owner addendum O8, D-10, D-11; code, tested):** record in `docs/product/OWNER_DECISIONS_2026-11-22_ADDENDUM.md` (with the implementation notes).
  - **O8:** migration `2026_11_22_100025` adds `mrn_redact_audit_text(entry, reason)`, a security definer function owned by the table owner.
    - The app role may execute it, but still has no update or delete on `audit_logs`.
    - It accepts one reason (`account_deletion`) and three entry types (contribution edits and corrections, place resubmissions). It replaces only text fields with `"[redacted]"` and writes a separate `audit.redacted` entry (actor type `system`, which is new in the actor check) naming the entry and fields.
    - It is idempotent. The deletion run calls it for every contribution of the rider and for the place suggestions it deletes.
    - Integrity: there is no hash chain today; the design note says what a future chain needs.
  - **D-10:** finished requests keep only choice categories and counts (handovers included). The daily command deletes `done` and `cancelled` records a year after they finished, and audits the number deleted. The pseudonymous `user_id` stays for that year so a deletion can be re-applied after a backup restore.
  - **D-11:** a threshold corroboration falls back to `published` when the remaining distinct confirmers (not the author; no window) drop below the threshold, audited as `contribution.corroboration_withdrawn`. Moderator corroborations and `officially_sourced` are kept.
  - **Found and fixed:**
    - In `myridenepal` and `myridenepal_e2e`, the app role could update and delete audit rows, because `scripts/setup-local-db.sh` re-grants every table after the migrations.
    - The script now re-applies the revoke, and the revoke was restored in both databases (the test DB was already correct).
    - Checked as `mrn_app` in the E2E DB: a direct update is refused and the function redacts. Those two check rows stay there (append-only).
    - **Production must check this privilege before launch.**
  - **Progress:** 179/261 = 68.6% (was 68.2%), coverage 82.5%. H11 moved from partial to complete, tested.
- **Session 25 (account deletion, step 2 of the decision record; code, tested):**
  - **Rules file:** `web/CLAUDE.md` was a symlink to itself (commit `02402ff`). It is restored from commit `54a9c4e`, the last version with content, and the root symlink `/var/www/html/Road-trip/CLAUDE.md -> web/CLAUDE.md` is recreated as the rules describe. Git sees `web/CLAUDE.md` as a type change (link to file).
  - **Built:** see "As built" in `docs/architecture/ACCOUNT_DELETION_AND_EXPORT.md`.
    - **Request:** password plus confirmation, and a choice for every public contribution (none pre-selected).
    - **Cooling-off (30 days):** the rider can sign in, read, export, cancel and offer handovers; taking part is blocked. Signing in never cancels.
    - **Cancel:** an explicit cancel with the password.
    - **Daily run (`mrn:accounts:process-deletions`):**
      - one transaction per request, retried after a failure;
      - choices applied, and a public contribution without a choice is deleted;
      - events with no accepted handover are cancelled with notice and kept without an organizer; group trips with none are deleted with notice;
      - confirmations given are removed and `last_confirmed_on` recomputed;
      - restricted evidence is kept for open cases;
      - files are removed after the commit and retried.
    - **Export:** a ZIP streamed in the response, never stored.
    - **Staff:** the "Deletions and evidence" page.
  - **Interpretations used (from the session brief):**
    - Evidence is held 30 days from the actual deletion; a database check caps it, and staff can release it earlier.
    - Event recipients are approved organizers who are not suspended, deactivated or leaving.
    - Trip recipients are any member, viewers included.
    - Transfers apply when accepted.
  - **Open, for the owner:**
    - **O8 (new):** append-only `audit_logs` entries keep edited contribution text after a deletion.
    - The retention period of deletion request rows.
    - Whether a `community_corroborated` report should lose that status when removed confirmations drop it below the threshold (unchanged today).
    - The 3-day reminder email and a signed cancel link (not built).
  - **Database:** dev and E2E migrated (`2026_11_22_100024`). A raw `delete from users` for a rider with contributions, or with an event that is not cancelled, now fails by design. The E2E cleanup deletes that content first.
  - **Progress:** 178/261 = 68.2% (was 67.8%), coverage 82.3% (was 81.9%). Only G19 changed (not built → complete, tested). H11 stays Partial (O8, retention).
- **Session 24, upload safety switch (code, tested):**
  - **Policy:** `app/Support/UploadPolicy.php`. One check for every new file. In order: `UPLOADS_ENABLED` env, staff setting `storage.uploads_enabled`, the chosen disk is configured, and the `local` disk is used only in `local`/`testing` (O6, no override). There is no fallback disk.
  - **One write path:** `Images::store()` (policy → re-encode/EXIF strip → write) is used by rider contribution photos and the staff media library. A failed write gives a 422 and no row. A failed row deletes the file (`Images::discard`).
  - **Alert:** `Log::critical` with only reason, disk, driver and environment, at most once an hour per reason. Deliberate pauses do not alert.
  - **Visible state:** `/site` `uploads {enabled, message}`, `/health` `uploads: enabled|paused`, staff `GET admin/settings/storage/upload-status` (`settings.storage.manage`). The public contribute page and the staff media library replace the picker with the message. Staff Settings → Storage shows why, plus the toggle and message.
  - **Existing images** are served in every state. No media migrated.
  - **Safe production config:** `APP_ENV=production`, `UPLOADS_ENABLED=false`, `FILESYSTEM_DISK=local` (in `.env.production.example`). Even with `UPLOADS_ENABLED=true`, production refuses the local disk.
  - **No persistent storage provider is configured or verified.** `storage.driver` still accepts only `local` (D1/O6 open).
  - Progress unchanged at 177/261 = 67.8% (coverage 81.9%). No audited requirement row was re-assessed.
- **Checks (session 24, all exit 0):** Laravel 169 passed (3521 assertions; `UploadPolicyTest` 7 tests, 105 assertions); `composer audit` clean; public type-check, lint, unit (30), build, `pnpm audit --prod`; staff type-check, lint, unit (13), build, `pnpm audit --prod`; public E2E 155 passed, 10 skipped (Chromium, Firefox, mobile); staff E2E 53 passed, 1 skipped (Chromium, Firefox). WebKit still cannot launch locally (missing system libraries).
- **Session 24 (owner decisions, documentation only):**
  - **Record:** `docs/product/OWNER_DECISIONS_2026-11-22.md` (approved decisions, approval boundaries, open items O1–O7).
  - **Updated to match:** `DECISION_Q9_CONTRIBUTIONS_ON_DELETION.md` (decided), `ACCOUNT_DELETION_AND_EXPORT.md` (rewritten: per-contribution choice, 30-day cooling-off with explicit cancel, event and trip transfers with acceptance, `moderation_evidence` held at most 30 days, two cascade-to-set-null changes), `STAFF_MFA_PLAN.md` (required, 14-day grace, no disable, env switch), `CONTACT_RETENTION_PLAN.md` (approved periods, open messages never purged, legal hold deferred, purge switch off by default), `INFRASTRUCTURE_OPTIONS.md` (P1 candidate, verification list, no budget).
  - **Interpretations to confirm with the owner:** the moderation hold runs at most 30 days *after* the deletion (the deletion itself is never delayed past day 30); signing in does not cancel a deletion; transfer eligibility (O2) as proposed in the design §2.3.
  - **Found:** `web/CLAUDE.md` is a symlink to itself (`web/CLAUDE.md -> web/CLAUDE.md`), so the project rules file cannot be read. Restore it.
  - No application code changed; no tests run. Progress unchanged at 177/261.
- **Session 23 (infrastructure and privacy readiness, planning only):**
  - **Infrastructure:** `docs/architecture/INFRASTRUCTURE_OPTIONS.md` compares frontends, API, Postgres, storage, email, monitoring and jobs, with sourced prices (third-party summaries, 2026-11-22; verify on official pages) and three costed profiles.
    - **Proposed (owner decides):** P1, about $28–40/month: Cloudflare Pages, a DigitalOcean Droplet in Bangalore, DO Managed Postgres, Cloudflare R2, Amazon SES, Sentry free.
    - **Key constraint found:** a personal Nepal dollar card is reportedly limited to about USD 500/year.
  - **Uploads:** `docs/architecture/UPLOAD_SAFETY_DESIGN.md`.
    - Only rider contribution photos and the staff media library write files; place images reuse the library, and tracks are parsed into the database.
    - The design: one switch (setting plus an environment override that can only turn it off), enforcement in `Images::reencode` plus route middleware, production refusing the `local` disk (uploads auto-paused, never lost), and frontends hiding pickers. Reads and existing content are untouched.
  - **Account deletion and export:** `docs/architecture/ACCOUNT_DELETION_AND_EXPORT.md` inventories 30 references to riders and designs the flow (re-authentication, pre-checks, cooling-off, an idempotent transaction, file cleanup after commit, retention exceptions, ZIP export).
    - **Found:** today, deleting a rider would cascade-delete their contributions, the events they organise (with other riders' registrations) and group trips they own (for all members). Notifications would be orphaned, and photo files would stay on disk.
  - **Q9:** `docs/product/DECISION_Q9_CONTRIBUTIONS_ON_DELETION.md` sets out options A, B, C and D plus sub-decisions Q9a–Q9d. Nothing is decided.
  - **Staff MFA:** `docs/security/STAFF_MFA_PLAN.md`. TOTP and hashed recovery codes on the custom staff guard; no new package (google2fa and bacon are already installed through Fortify); optional, then required, with admin reset and a break-glass command. Riders unchanged.
  - **Contact retention:** `docs/product/CONTACT_RETENTION_PLAN.md`. Placeholder periods (spam 30 days, done 12 months), holds, a daily purge with dry-run, **purge off by default**, counts-only audit. The dev database has 0 messages.
  - **CI:** both `.github/workflows/ci.yml` files updated locally (not pushed, not run):
    - least-privilege `permissions`, `concurrency`, and `composer validate --strict`;
    - per-browser E2E matrix (public: chromium, firefox, mobile, webkit; staff: chromium, firefox, webkit), with WebKit non-blocking until its first green run.
    - Pint left out because 58 files need a one-off formatting commit.
    - Both files parse as valid YAML.
  - **Progress unchanged at 177/261 = 67.8%** (planning and documentation only).
- **Checks (session 23):** `composer validate --strict` exit 0. `pint --test` exit 1 (58 files, style only). Both workflow files parse as valid YAML.
  - No application code changed. The last full suite run (session 22) stands: Laravel 162 passed, public E2E 152 passed, staff E2E 51 passed, all exit 0.
- **Session 22 (production-readiness audit):**
  - **Verdict: not ready for a public launch.**
    - The code is in good shape and well tested.
    - The blockers are owner decisions and infrastructure: hosting and production Postgres with backups (AR4, AR5), durable file storage (D1), account deletion and export (G19, H11), an email provider, and error monitoring.
    - The product has almost no published content.
    - Full risk register: `docs/sessions/2026-11-22-session-22-production-readiness.html`.
  - **Accessibility:** a new dependency-free audit (`e2e/production-audit.spec.ts` in both apps) checks WCAG AA text contrast, focus visibility and obstruction, and phone tap targets.
    - Two defects were found and fixed:
      - Keyboard focus could land under the fixed header (WCAG 2.4.11): fixed with `scroll-padding-top` on `html`.
      - Date fields lost their focus ring when focus moved to the built-in calendar button: fixed with `:focus-within`, in both apps.
    - Contrast had 0 failures across 30 screens.
  - **Security:** new `SecurityPostureTest` proves security headers (HSTS over HTTPS only), generic production error messages, and EXIF/GPS stripping from photos.
    - CSRF was probed live: 419 without the token, 422 with it.
    - CORS: a foreign origin gets no allowance.
    - Cookies are `httponly` and `samesite=lax`.
    - Found: no production switch to disable uploads; `X-Powered-By` reveals the PHP version.
  - **WebKit:** the browser binary is present; launching needs `libevent-2.1-7t64` and `libmanette-0.2-0` (sudo, not run).
  - **Performance (lab, not Lighthouse):** throttled phone LCP is 2.48 s on the home page and 3.74 s on a route page; CLS ≤ 0.048; initial load about 79 KB JS + 9 KB CSS gzipped.
  - **Progress unchanged at 177/261 = 67.8%** (an audit session; fixes are defects in existing features, and no requirement changed status).
- **Checks (session 22, every exit code captured, full logs read):**
  - `php artisan test` exit 0, **162 passed (3409 assertions)**; `composer audit` exit 0.
  - Public site: type-check, lint, unit (30), build all exit 0; `pnpm audit --prod` exit 0. The full `pnpm audit` exits 1 with 2 moderate dev-only advisories.
  - Staff portal: the same: all exit 0 (13 unit); the full audit has the same 2 dev-only advisories.
  - **Public E2E exit 0: 152 passed, 10 skipped by design, 0 failed. Staff E2E exit 0: 51 passed, 1 skipped by design.**
  - WebKit attempt: exit 1 (missing system libraries).
- **Session 21 (complete verification, then the homepage shows the product):**
  - **Verification first** (before any homepage change, every exit code captured):
    - Laravel 157 passed; `composer audit` clean.
    - Public site: type-check, lint, unit (30), build and audit all exit 0. Staff portal: the same, all exit 0 (13 unit).
    - Public E2E 144 passed, 3 skipped by design, 0 failed. Staff E2E 48 passed.
    - No regressions.
  - **Homepage:**
    - **Hero headline:** "Plan the ride / stretch by stretch.", with a specific lead and the main button "Plan a route" going to `/journey`. Changed only because the seeded copy was untouched; staff can edit it.
    - **New `planner` section (the main feature, enlarged):**
      - A working start and destination picker over the published destinations; it opens Explore Along My Route with both chosen.
      - Beside it, a real published route's plan: stretches, recorded km, distance from the start, time estimates, surface, fuel.
      - With no route published it lists what a plan shows, never an invented example.
    - **New `tools` section:** six tools (routes, trips, places, reports, events, preparation), each linking to the real page. The icon follows the link.
    - Both are staff-editable (`config/home.php`, staff portal `sectionSchemas.ts`) and validated: one route at most, safe links only.
  - **Progress unchanged at 177/261 = 67.8%** (coverage 81.9%). The homepage work is presentation; no audited requirement changed status.
- **Checks after the homepage change** (every exit code captured, full logs read):
  - `php artisan test` exit 0, **159 passed (3371 assertions)**; `composer audit` exit 0.
  - Public site: type-check, lint, unit (30), build and audit all exit 0. Staff portal: the same, all exit 0 (13 unit).
  - **Public E2E exit 0: 147 passed, 3 skipped by design, 0 failed.** **Staff E2E exit 0: 48 passed.**
- **Session 20 (trip planning, F4 and F15):**
  - **Evidence first:** `docs/audits/TRIP_PLANNING_GAP_ASSESSMENT.md` (gap table per acceptance criterion, written before any change, with the outcome). Real gaps:
    - Stops could not be edited in the UI.
    - No per-day view, and no warning for nights without a place to stay.
    - Planned and spent costs were not distinguished, and an unknown cost could not be entered.
    - Checklist items could not be edited.
    - A withdrawn destination gave no notice.
    - Notes worked, but had no test at all.
  - **Database:** migration `2026_11_21_100022_add_trip_expense_status` adds `trip_expenses.status` (planned or spent; existing rows planned) and makes `amount_npr` nullable, with a check that spent rows have an amount. Dev and E2E are migrated; rollback and re-apply were tested on E2E. Rollback refuses while amounts are missing, so it never invents one.
  - **API:** a spent cost needs an amount (422, also via PATCH). Trips return `budget` (planned, spent, planned items without an amount), per-stop `tracks` (recorded only), and `linked_unavailable` now also covers destinations.
  - **Public site:**
    - The trip page is split into `TripBudget`, `TripChecklist` and `TripDays` components.
    - Stops are edited in place (name, day, overnight, note).
    - Day by day shows dates from the start date, recorded km and time per day with missing values counted, the overnight stop per night, and warnings: no stay for a night, a day after the end date, list order against day order, stops without a day.
    - Budget shows planned and spent totals in NPR, "amount not known yet", "this total is incomplete", edit, "paid by", and confirmation before removal.
    - Checklist has edit, "n of m done" and empty states.
    - Notes say who can read them, and "Details saved." confirms a save.
    - The trip map draws recorded tracks of route and stretch stops as grey lines, never straight guesses.
    - Logic is in `lib/tripDays.ts`, with unit tests.
  - **Audit:** F4 and F15 are now Complete, tested. Arrival and departure times are not modelled and are documented as not built. The checklist rows A and M are updated.
  - **Progress:**
    - Verified completion: **175/261 = 67.0% before, 177/261 = 67.8% after (+0.8 points)**.
    - Coverage: 81.7% to 81.9%.
    - My Rides: 55.6% to 66.7% (coverage 78.9% to 81.7%).
    - Routes: unchanged at 53.8%.
- **Checks (session 20, every exit code captured, full logs read):**
  - `php artisan test` exit 0, **157 passed (3351 assertions)**; `composer audit` exit 0.
  - Public site: type-check, lint, unit (**30 passed**), build and `pnpm audit --prod` all exit 0.
  - Staff portal: type-check, lint, unit (**13**), build and audit all exit 0.
  - **Public E2E** (Chromium, Firefox, mobile): exit 0, **144 passed, 3 skipped by design, 0 failed**. A first full run was cut off at test 61 of 147 when the session ended, with no failures up to then; the complete re-run is the result above.
  - **Staff E2E** (Chromium, Firefox): exit 0, **48 passed**.
- **Session 19 (F3, routes, stretches and checkpoints in trips):**
  - **Design first:** `docs/architecture/TRIP_ROUTE_PARTS.md` (current and proposed model, compatibility, duplicates, authorization, rollback), written before the schema change. It extends `trip_stops`; it is not a second planning system.
  - **Database:** migration `2026_11_21_100021_add_trip_route_parts` adds `trip_stops.route_segment_id` (FK, null on delete) and `route_part` (check: route, stretch, checkpoint_start, checkpoint_end), plus two partial unique indexes (whole route once per trip; stretch or checkpoint once per trip). Dev and E2E are migrated; rollback and re-apply were tested on E2E.
  - **API:**
    - `POST trips/{id}/route-parts` takes the whole route, or chosen stretches and checkpoints. Stops are added in riding order, in either direction.
    - It accepts published routes only, and only that route's own stretches.
    - It refuses repeats (and names them), refuses a whole route together with its own stretches, and adds nothing if any item is refused.
    - A checkpoint is stored once: "end of stretch 2" is the same stop as "start of stretch 3".
    - `PUT trips/{id}/stops/order` reorders; a stale list is refused.
    - Owners and editors only (viewers and outsiders 403, guests 401).
    - Generic stops with only `route_id` become whole-route stops (deduplicated), and route stops keep their links on PATCH. Both rule changes are documented and tested.
  - **Public site:**
    - The route page has "Add to a trip": choose a trip or "A new trip", reverse, whole route, or tick stretches and checkpoints. It shows recorded km and time per stretch, names what is missing, gives a live total of the selection, marks items already on the trip, and asks guests to sign in.
    - The trip page shows route, stretch and checkpoint details, the missing values, route totals (recorded values only), move up and down, confirmation before removing a route stop, and green checkpoint markers where a position is recorded.
    - Logic is in `lib/tripRoute.ts`, with unit tests.
  - **Audit:** F3 is now Complete, tested. F4 and F15 stay "under-tested": overnight, budget and checklist paths were not newly browser-tested.
  - **Progress:**
    - Verified completion: **174/261 = 66.7% before, 175/261 = 67.0% after (+0.4 points)**.
    - Coverage: 81.5% to 81.7%.
    - My Rides: 50.0% to 55.6% (coverage 75.6% to 78.9%).
    - Routes: unchanged at 53.8%. F3 sits in the My Rides area; no B row changed.
- **Checks (session 19, every exit code captured, full logs read):**
  - `php artisan test` exit 0, **151 passed (3227 assertions)**; `composer audit` exit 0.
  - Public site: type-check, lint, unit (**25 passed**), build and `pnpm audit --prod` all exit 0.
  - Staff portal: type-check, lint, unit (**13 passed**), build and `pnpm audit --prod` all exit 0.
  - **Public E2E** (Chromium, Firefox, mobile): exit 0, **141 passed, 3 skipped by design, 0 failed**.
  - **Staff E2E** (Chromium, Firefox): exit 0, **48 passed, 0 failed**. A first staff run without the project filter also tried WebKit; all 24 WebKit tests failed at browser launch (system libraries not installed) and 48 passed.
- **Session 18 (route planning, then "route verification and audit closure"):**
  - **Route planning (public route page):** "Plan this ride" from recorded data only: stretches in riding order with distance, distance from the start, staff time estimate, surface and services; "Ride it in reverse"; recorded distance and estimated time totals (no time total if any stretch lacks one); fuel planning (longest run with no recorded fuel station, conservative, per direction, honest caveats); warning when stretches and the route total differ by more than 5 %. Phones get one card per stretch, wider screens a table. Logic in `frontend/src/lib/routePlan.ts` with unit tests (18 tests incl. edge cases).
  - **Riding time per stretch:** migration `2026_11_21_100020_add_segment_riding_time` (`route_segments.estimated_hours`, 0.1–48, check constraint; dev and E2E migrated); staff editor field with readable validation names; staff warning when segments and the route total disagree.
  - **Route filters (Routes page and API):** name, province, maximum recorded distance (unknown lengths excluded), surface (paved all the way / includes gravel or dirt), recorded track; kept in the address.
  - **Service markers (B8):** route map draws published services only at recorded positions; each marker opens a popup with that service's details (built as text); the stretch list says "no map position" when none is recorded.
  - **Audit:** B4, B5, E4, B8 to complete and tested; B13 stays partial (difficulty waits for owner question Q6; no seasonal data exists); nothing invented.
  - **Progress:** verified completion **170/261 = 65.1% before, 174/261 = 66.7% after (+1.6 points)**; coverage 80.7% to 81.5%; Routes 30.8% to 53.8%, Preparation and safety 21.4% to 28.6%.
- **Checks (session 18, final, every command's exit code captured):** `php artisan test` exit 0, **145 passed (3095 assertions)**; `composer audit` exit 0, clean; public type-check, lint, unit (**18 passed**), build, `pnpm audit --prod` all exit 0; staff type-check, lint, unit (**13 passed**), build, `pnpm audit --prod` all exit 0; **public E2E exit 0: 138 passed, 3 skipped by design, 0 failed; staff E2E exit 0: 48 passed, 0 failed, 0 skipped** (full logs read; no failure markers).
- **Session 17 follow-up (owner-approved `places.review`):**
  - `places.review` added (registry, moderator defaults, migration `2026_11_20_100019` with an idempotent single grant; dev and E2E: moderator 14 to 15 permissions, custom role `tester` untouched). New staff routes `admin/place-suggestions` (list, show with history, decision approve/request_changes/reject; rider suggestions only); correction queue moved from `places.publish` to `places.review`. Shared `App\Actions\Places\DecidePlace` (row lock, status re-check, no-self-review, reasons, audit with `via`). Staff portal: Places item for manage or review; moderators see only "Rider suggestions" and "Corrections" (no create, edit, publish, unpublish, archive).
  - **Correction of session 17:** its full public E2E run actually had **3 failures** (destination-map spec, "from the route line" wording removed in session 17), not "123 passed, 3 skipped" as reported; the output was misread (only the last lines were read; the pipe exit code was 0). Fixed in this follow-up (wording restored); the full public suite now passes.
  - **Progress:** unchanged at **170/261 = 65.1%** verified, 80.7% coverage (a permission change; no audited requirement changed status).
- **Checks (follow-up, final):** `php artisan test` **143 passed (3074 assertions)**, including `PlacesReviewPermissionTest` (4 tests, 68 assertions, owner's 27 scenarios; a deliberate hole made 3 of them fail, then restored); `composer audit` clean; public and staff type-check, lint, unit (7/13), build, `pnpm audit --prod` clean; **E2E staff 46/46**, **public 126 passed, 3 skipped by design, 0 failed** (full output read); phone-width screenshot of the review screen inspected.
- **Session 17 (owner brief "place-to-trip integration and destination route maps"):**
  - **Permission audit (no role changed):** moderators hold neither place permission; `places.publish` also grants unrestricted publish/unpublish/archive, `places.manage` lets staff edit riders' pending suggestions. Proposed `places.review` (decide rider suggestions and corrections only) in `AUTHORIZATION_MODEL.md`; waiting for the owner.
  - **Places in trips:** `trip_stops.place_id` (published only, once per trip: API check plus unique index); "Add to a trip" on the place, journey and saved pages (trips the rider owns or edits only); trip page shows each stop's place/destination/service (withdrawn ones marked, name kept) and a map of the stops (markers per kind, deduplicated, open their pages); viewers see no edit controls and the API refuses them.
  - **Real route geometry:** `route_segments.path` from staff-imported GPX/GeoJSON tracks (2–5000 points in Nepal, external XML entities refused, audited, removable) in the staff route editor. Destination maps draw recorded tracks under the markers (routes without one are listed "no recorded track: not drawn"); route maps now draw only recorded tracks and show checkpoints otherwise. **Behaviour change:** the route page no longer draws straight lines between checkpoints (owner rule: a straight line is not a road). Straight-line distance maths uses the track when present.
  - **Progress:** verified completion **168/261 = 64.4% before, 170/261 = 65.1% after (+0.7 points)**; coverage 80.2% to 80.7%; L13 and R4 partial to complete; R11 re-verified with places; Saved items 4/5 unchanged (F8 guides); no regressions found.
- **Checks (session 17, final):** `php artisan test` **139 passed (2982 assertions)**; `composer audit` clean; public and staff type-check, lint, unit (7/13), build, `pnpm audit --prod` clean; **E2E public 123 passed, 3 skipped by design; staff 40/40**; trip-places spec rerun (6/6) after adding the tracked-route assertion; screenshots of the trip map (Pixel 7) and the destination tracks (Chromium) inspected.
- **Session 16 (owner brief "Places of Interest feature"):**
  - **Places of interest, end to end.** Migration `2026_11_20_100017_create_places` (places, place_corrections, contributions.place_id, saved type `place`; additive; dev and E2E databases migrated, permissions synced). API: public list/detail with nearby places and destinations; rider suggest (always pending, duplicate check, rate limit, contribution rights), edit while pending or sent back, corrections; staff list/filter/create/edit, decisions (publish, unpublish, request changes and reject with a reason, archive) with no-self-review and audit, history, corrections queue. Staff portal: Places screen (search, category and status filters, pagination, editor with the Nepal map picker, cover and gallery from the media library, sources, preview link, decisions, history, corrections tab; nav item needs `places.manage`). Public site: `/places` (search, category, pages), `/places/:slug` (images and credits, description, map, coordinates, nearby lists, sources, freshness, experiences, save, suggest a correction, share an experience), `/places/suggest` (map picker, your suggestions with status and moderator note). Integrations: destination map and "Places of interest" list (markers open the place), journeys list places along the route (straight-line), saved items, contribute form `?place=`.
  - **Bugs found and fixed:** Leaflet maps drew over the sticky site header when scrolled (all maps since session 12; fixed with `isolate`, asserted and checked in a screenshot); `toArray()` on resources sent empty `[]` placeholders for hidden fields (now `resolve()`; journey route too); a map picker label said "destination" on the Places screen.
  - **Progress:** verified completion **160/261 = 61.3% before, 168/261 = 64.4% after (+3.1 points)**; coverage 77.3% to 80.2%; Places of interest 0/7 to 7/7; Saved items 3/5 to 4/5; blocked 4.
- **Checks (session 16, final):** `php artisan test` **135 passed (2923 assertions)**; `composer audit` clean; public and staff type-check, lint, unit (7/13), build, `pnpm audit --prod` clean; **E2E public 120 passed, 3 skipped by design; staff 38/38**; screenshots of the staff editor, the public place page (mobile) and the suggest form inspected.
- **Session 15 (owner brief "objective-driven implementation and progress accountability"):**
  - **Progress measurement:** `node scripts/requirements-progress.mjs` computes verified completion and implementation coverage from the two requirement tables (261 rows: audit A–H, X, N1–N6 plus gap-analysis N7–N9; owner-decision tables excluded). Mapping: "Complete, tested" = complete and verified (as recorded by the session that built it; not all such rows have a browser test), "Complete, under-tested"/"Unable to verify" = verification incomplete, "Partial" = partial, "Missing"/"Documented, not built" = not implemented, "Blocked"/owner-dependent = blocked. No earlier session computed percentages; the session 15 baseline is the first.
  - **Reconciled before measuring** (not counted as session progress): CM16 and R16 to complete (built and E2E-tested in sessions 10, 12, 13); R8, R14 (D2) and P12 (AR3) to blocked.
  - **Feature: destination maps.** Staff map picker on the destination form (click to place, drag, typed values move the marker, Nepal bounds, remove position; Leaflet 1.9.4 added to the staff portal, same library and version as the public site). Public destination page: interactive map (destination, its services, nearby services and destinations), "Nearby" lists with straight-line distances (destinations within 50 km, services within 20 km, routes passing within 15 km), "no position recorded" state, long names wrap. API: `nearby` on `GET destinations/{slug}` (bounding box + `App\Support\Geo`, published only).
  - **Bug found by browser testing and fixed:** opening an existing destination or CMS entry for editing threw `DataCloneError` (`structuredClone` of a Vue reactive proxy, introduced in sessions 12/13); fixed with `toRaw` in 3 places, regression steps added.
  - **Progress:** verified completion **156/261 = 59.8% before, 160/261 = 61.3% after (+1.5 points)**; implementation coverage 76.1% to 77.3%; completed this session L1, L2, L5, L6; L13 not implemented to partial; blocked 4.
- **Checks (session 15, final):** `php artisan test` **131 passed (2761 assertions)**; `composer audit` clean; public and staff type-check, lint, unit (7/13), build, `pnpm audit --prod` clean; **E2E public 114 passed, 3 skipped by design; staff 34/34**; screenshots of the staff picker (Chromium) and the public destination map (Chromium, Firefox, Pixel 7) inspected.
- **Session 14 (inspection only, owner brief "PostGIS pre-activation safety audit"):** plan in `docs/database/POSTGIS_ACTIVATION_PLAN.md`. Findings: PostgreSQL 17.12 in `public.ecr.aws/supabase/postgres:17.12.0.000`; PostGIS **3.3.7 is packaged but installed in no database**; it is untrusted but on Supabase's privileged-extension list, so `postgres` (member of `supabase_privileged_role`) can create it and `mrn_owner` cannot, which makes activation a setup-script step, not a Laravel migration; Laravel pins `search_path` to `public` (must become `public,extensions`); CI uses `postgres:17` without PostGIS (must change); no geographic types exist yet; dev has 4 destinations, none with coordinates, and no segments. Proposed: generated `geography(Point,4326)` columns from the existing lat/lng, `route_segments.path geography(LineString,4326)`, a `places` table, GiST indexes, `GEO_ENGINE` feature flag, rollback that never drops the extension. **Waiting for the owner's explicit approval (D3).** No database, extension, migration, image, volume or config was changed; no backup was run.
- **Session 13:**
  - **CMS (Stage 8) complete:** pages with content blocks, FAQs, blog posts with categories, tags and authors, team, popups, banners: one `cms_entries` table driven by `config/cms.php` (fields declared once; staff forms built from `GET admin/cms/types`), drafts, publish permission, schedule and expiry, order, SEO title/description, slugs, signed preview, search/filter/pagination, audit. Public: `/pages/:slug`, `/faq`, `/blog`, `/blog/:slug`, `/team`, `/contact`, banners on the homepage and blog, one accessible popup at a time. Contact inbox for staff (`contact.view` / `contact.manage`), honeypot and rate limit. Header and footer menus were already dynamic (session 10).
  - **Media usage fixed:** `media_usages` was never written, so "images in use cannot be deleted" never worked. Now written for CMS entries, homepage sections and settings images.
  - **Settings (Stage 9 part):** Mail (SMTP override, encrypted password, connection test, test email to the signed-in staff member only), Maps (tile URL, attribution, default view, geocoding/routing provider settings with a test that refuses private hosts; nothing is called by the website until D2), Security (staff idle and maximum session, sign-in attempts), Trust (corroboration threshold, default 3, window 90 days). The public map uses the tile and attribution settings (attribution escaped).
  - **Trips:** adding a member is now an invitation the rider must accept (14 days, decline, owner cancel, in-app notification); pending invitations on My rides; owner sees open invitations.
  - **Journey page:** add services and destinations straight to a trip (`AddToTrip`, shared with Saved); the same service twice on one trip is refused by the API.
  - **Trust:** community corroboration (published to corroborated when enough distinct eligible riders confirm in the window; audited; never official verification). Contribution-only suspension with reason, end date, one appeal, staff review (lift or uphold), audit and notifications; account suspension unchanged.
  - **Events:** organizers remove approved riders before the start (waitlist moves up, rider notified); postponing can set new dates.
  - **Quality:** dependency-free accessibility structure checks (13 public + 13 staff pages), keyboard skip link, reduced motion, LCP/CLS in Chromium. Found and fixed: layout shift 0.39 on the homepage and 0.23 on Explore (footer in the first screen while loading), duplicate dialog title ids in the staff portal. WebKit could not launch (missing system libraries).
- **Checks (session 13, final):** `php artisan test` **129 passed (2750 assertions)**; `composer audit` clean; public app type-check, lint, 7 unit tests, build, `pnpm audit --prod` clean; staff portal type-check, lint, 13 unit tests, build, `pnpm audit --prod` clean; **E2E public 111 passed, 3 skipped by design** (Chromium-only Web Vitals and desktop-only keyboard test), **staff 32/32**, on `myridenepal_e2e`.
- **Database (session 13):** `2026_11_19_100015_create_cms_content` and `2026_11_19_100016_create_trip_invitations_and_contribution_suspensions` applied (owner role) to `myridenepal` and `myridenepal_e2e`; permissions synced (49). Additive only.
- **Session 12:**
  - Dev commands verified: `npm run dev` in `web` starts the API (:8010) and the public site (:5180) together (concurrently, port guard refuses duplicates, Ctrl+C stopped only its own children, tested on spare ports 8020/5190 while the owner's servers ran); `npm run dev` in `admin` starts only the portal (:5181).
  - **Stage 4 trust pipeline done:** status transitions (`ContributionStatus::next`, rejected final); report categories with dangerous-first queue and per-report dismissal; staff correction request (rider notified in-app) and author addendum (public after a moderator accepts it; declined text stays in the audit log; body never edited); "still accurate" by other riders (one per day) and moderators (audited), `last_confirmed_on`; moderation history for moderators; rejected/flagged counts on the staff Riders list; structured `sources` on destinations, routes and officially sourced contributions (staff `SourcesEditor`, public `SourceList`). Not done: corroboration rule (Stage 6, Q4), per-fact sources.
  - **Saved items:** `GET/POST/DELETE users/me/saved` (destinations, routes, services, experiences; published only; owner-only rows); Save buttons on destination, route, experience and journey pages; `/saved` page with type and service-kind filters, remove, "Add to a trip" (trip stops gain `service_point_id`).
  - **Explore Along My Route, curated mode:** `GET journeys?from&to&within_km` (published routes between two destinations either way, services on the route's stretches, published services and destinations within N km of the recorded line, straight-line distance from `App\Support\Geo`, bounding-box prefilter; no PostGIS, no invented time or detour); `/journey` page (start, destination, radius, route choice, map with nearby markers, category filter, save). Missing: a `places` model (viewpoints, temples, lakes, rider-submitted places: Stage 5), geocoded free start (D2), detour distance/time (D2), add-to-trip directly from the journey page.
  - Bugs found by the new E2E and fixed: the experience page and the moderation queue re-mounted on refresh (status messages and open history lost); the moderation card captured its prop once.
- **Checks (session 12, final):** `php artisan test` **117 passed (2117 assertions)**; `composer audit` clean; public app type-check, lint, 7 unit tests, build, `pnpm audit --prod` clean; staff portal type-check, lint, 13 unit tests, build, `pnpm audit --prod` clean; **E2E public 60/60** (20 tests × Chromium, Firefox, Pixel 7), **staff 26/26** (13 × Chromium, Firefox), on `myridenepal_e2e`.
- **Database (session 12):** `2026_11_19_100013_create_trust_pipeline` and `2026_11_19_100014_create_saved_items` applied (owner role) to `myridenepal` and `myridenepal_e2e`; both additive (see `docs/database/DATABASE_DESIGN.md`).
- **Session 11:** `cd web && npm run dev` starts the Laravel API (:8010, with queue listener and log tail) and the public website (:5180) together. It first refuses if either port is taken (no duplicate servers), then runs them with concurrently 10.0.7 through `npx` (the tool `composer dev` already used, now pinned; no new dependency). Ctrl+C stops everything it started; if one part crashes the rest stop and it exits 1. `API_PORT` / `WEB_PORT` move the ports. Separate commands remain: `npm run dev:api` (= `composer dev`), `npm run dev:web`, `pnpm dev` in `frontend/`. The staff portal is unchanged (`cd admin && npm run dev` = Vite only). Verified on spare ports 8020/5190 while the owner's servers (8010, 5180, 5181) kept running.
- **Session 10:** stages A1–A8 of `docs/architecture/MIGRATION_PLAN.md` done and verified.
  - A7: all API calls in both apps go through `features/*/services` (ESLint forbids the raw client in pages/components); Settings is one page per group under tabs (General, Maintenance, Registration, Branding, Social media, SEO, Footer, Storage, System). Not yet split: public `TripPage`, staff `MediaPage`.
  - A8: homepage sections (`config/home.php`: hero, slides, layers, featured destinations/routes/experiences, freshness, banner, text) with drafts, publish (audited), order, visibility, schedule, archive, signed preview; header/footer menus; site-wide notices; settings branding (share image), social, SEO, footer. Migration `2026_11_18_100012` seeded the existing copy as published (applied to dev and E2E databases). Staff pages: Homepage, Menus, Notices + 4 settings tabs. Public site renders everything from `/api/v1/home`, `/menus/*`, `/notices/active`, `/site`.
  - Layout: public app in `web/frontend` (:5180/:4180), staff portal at the `admin` root (:5181/:4181); old copies removed after both passed; independence verified (stop either frontend, the other and the API keep answering; a frontend survives an API outage).
  - E2E isolated: database `myridenepal_e2e` (created by `scripts/setup-local-db.sh`); each suite starts its own API on :8011 with `DB_DATABASE=myridenepal_e2e`; the development database is never written by tests.
  - Deployment: `vercel.json` per frontend (placeholder `API_ORIGIN_NOT_SET`, SPA fallback, CSP/HSTS) + `scripts/check-deploy-config.mjs` guard, `web/.env.production.example`, `TRUSTED_PROXIES`, CORS closed by default. **Vercel failure diagnosed by reproducing the committed tree:** `composer install --no-dev` failed on `config/scribe.php` → fixed; image builds and serves `/api/v1/health`. Still blocked: AR4 (plan), AR5 (production Postgres), D1 (storage).
  - API routes split into `routes/api/{public,rider,staff}.php` (identical route list).
  - Staff authentication: migration `2026_11_18_100011` (additive; existing staff carried over without a password); guard `staff`, cookie `*_staff`, 60 min idle / 12 h max; `/api/v1/admin/auth/*`; `mrn:staff:create-admin` replaces `mrn:grant-admin`; signed preview tokens replace the staff maintenance bypass; self-review via linked rider or same email. Staff MFA **not** enabled (AR3 open).
  - Staff portal: Riders and Staff pages, Roles, staff profile (password, rider link), forgot/reset password, staff invitation page, services per module, shared `PasswordConfirmDialog`, maintenance preview button. Public app: preview banner/token.
  - Local dev: the dev database was migrated with `100011`; `admin@local.test` now also has a **staff** account with a new staff password (given to the owner in the session chat, not stored in files).
- **Checks (session 10, after A8):** `php artisan test` **104 passed (1925 assertions)**; `composer audit` and both `pnpm audit --prod` clean; both apps type-check, lint, unit tests (7 / 13), builds pass; **E2E public 51/51** (17 × Chromium, Firefox, Pixel 7), **staff 24/24** (12 × Chromium, Firefox), on `myridenepal_e2e`. After A6: `php artisan test` 96 passed (1687 assertions); `composer audit` clean; public app and staff portal: type-check, lint, unit tests (7 / 13), builds pass; **E2E public 48/48**, **staff 22/22** on `myridenepal_e2e`; `pnpm audit --prod` only the documented advisory; Docker image built and run.
- **Session 9 (investigation, owner brief "Architecture Investigation, Project Comparison & Structural Improvement"):** compared with `/var/www/html/consultancy/{web,portal}` and `/var/www/html/erp/{erp-web,erp-portal}` (both: `*-web` = Laravel + public site, `*-portal` = admin SPA; consultancy has a separate admin guard and table, ERP a separate staff session cookie and staff login). Findings: stopping "the admin dev server" stops both apps only because `admin` `pnpm dev` = `run-p dev:public dev:admin` (one parent process), with no runtime coupling; two apps share one `package.json`, Vite config, `.env`, repo and so cannot have separate Vercel configs; staff sign in through the rider endpoint, share the rider guard, table, cookie, 7-day session and reset broker (no privilege escalation: every staff route is permission-checked and tested); 37 `.vue` files call the API directly, `PeoplePage`/`SettingsPage`/`MediaPage`/`TripPage` mix several jobs; homepage, header and footer copy is hard-coded. Vercel: no logs here (owner must share them); GitHub `HEAD` is the pre-Option-B layout; it cannot serve pages without a production database (sessions/cache are database-backed, DB on 127.0.0.1) and `APP_KEY`; no `vercel.json` for the frontends; local-disk storage and the database queue are not durable on Vercel. Requirements: 261 rows (225 from the audit + 36 new N7 homepage, N8 independent apps, N9 separate auth): 87 complete and tested, 19 under-tested, 66 partial, 15 documented-not-built, 67 missing, 6 unverifiable, 1 suggestion.
- **Checks (session 9, non-destructive):** `php artisan test` 86 passed (1289 assertions, `myridenepal_test`); `composer audit` clean; admin type-check, oxlint and eslint **without `--fix`**, 14 unit tests, both builds pass; `pnpm audit --prod` 1 reviewed/ignored; E2E **not run** (it writes to the local development database).
- **Roadmap:** Stage M (Option B), Stages 1, 2 and 3 **done** (Stage 3 on the local disk; its S3-compatible driver waits for D1). **Next: Stage 4 (trust pipeline)**, which needs no owner decision.
- **Stage 3 (session 8), complete for local storage:** settings registry `config/settings.php` (general, maintenance, registration, storage) with `App\Support\Settings` (validated, cached, encrypted and masked secrets, audited), public `GET /api/v1/site`, maintenance switch (`MaintenanceMode` middleware: 503 + message, staff/sign-in/site/health open; public app shows "Back soon"), registration switch (sign-up refused with the message), media library (`media_assets`, `media_usages`, licence CC0/public domain/owner-supplied, credit, used images cannot be deleted, public `GET /files/{asset}`), one image pipeline `App\Support\Images` honouring the storage limits, `disk` + `size_bytes` on contribution photos, storage test with usage, settings cache clear. Staff app: Settings and Media pages. Six new permissions (moderators get `media.manage`).
- **Stage 1 (session 8), complete:** "Your events" on the Events page (hosting → manage, taking part with status); edit screen for contributions still waiting for review (`/experiences/:id/edit`, links on the experience and in Account), edits audited; "Send a new confirmation link" on sign-up and after an unconfirmed sign-in; destination page shows published services and the 5 newest rider reports; notifications for waitlist promotion and organizer approve/decline (`RegistrationChanged`); route-wide authorization test (`RouteAuthorizationTest`: every route classified, guests 401 everywhere protected, riders refused on every staff route; mutation-checked).
- **Stage 2 (session 8), complete:** configurable roles from a code registry (`config/permissions.php`, 23 permissions), `roles`/`permissions`/`permission_role` tables, built-in `admin` (all) and `moderator` (same rights as before), custom roles, several roles per person, no escalation (you cannot create, widen, narrow or give a role beyond your own permissions), every staff route names its permission; staff invitations by email (hash-only token, 72 h, single use; new accounts confirmed by the token, existing ones need their password); deactivation (refused at sign-in, sessions end, staff rights paused, last active admin protected); admin-triggered password reset link; sign out everywhere. Staff app: Roles page, People page with dynamic roles, invitations and account controls, accept-invitation page, menu and route guard driven by permissions.
- **Part 1 audit now:** 65 complete and tested, 18 under-tested, 45 partial, 8 documented-not-built, 2 missing, 6 unable to verify. **Part 2:** N3 (admin roles and permissions) complete and tested except staff MFA (suggestion only).
- **Checks (final, session 8):** `php artisan test` **86 passed (1289 assertions)**; `composer audit` clean; admin repo type-check, lint, 14 unit tests, both builds pass; E2E **64/64** (public 14 tests × Chromium, Firefox, Pixel 7; staff 11 × Chromium, Firefox) with `E2E_API_PORT=8011` because the owner's `composer dev` held :8010; `pnpm audit --prod`: only the documented accepted advisory.
- **Database:** local `myridenepal` migrated with `2026_11_18_100009_create_roles_and_permissions` and `2026_11_18_100010_create_settings_and_media` (both additive: new tables, `users.deactivated_at`, `user_roles.role` became a FK to `roles.slug`; existing assignments kept, `admin@local.test` still admin; `mrn_app` has DML on the new tables). PostGIS available but not enabled (D3).
- **Not verified:** WebKit, screen reader, axe, Lighthouse, CI on GitHub, external security review.
- Nothing committed. The owner's `composer dev` (Laravel :8010) and `vite --mode public` (:5180) were running; this session left no server of its own running. Recovery archive of the pre-Option-B layout still in `/var/www/html/Road-trip/_backups/2026-11-18-pre-option-b/` until the owner commits.

## 3. Active Files
- Session 30: `docs/security/ACCOUNT_DELETION_DB_SECURITY_DESIGN.md` (new), `docs/architecture/PRODUCTION_PRIVACY_CHECKLIST.md` (§0, blocker 12), the report.
- Session 29: `tests/Feature/AccountDeletionTest.php` (5 new tests, 30 in all); `docs/architecture/PRODUCTION_PRIVACY_CHECKLIST.md` (new); the report.
- Session 28:
  - **Laravel:**
    - migrations `100026`, `100027`;
    - `app/Actions/Accounts/ProcessAccountDeletion.php`, `Admin/ContributionController.php` (confirm), `Models/Contribution.php`;
    - `routes/console.php`, `config/mrn.php`, `.env.example`, `.env.production.example`;
    - `tests/Feature/AccountDeletionTest.php` (25 tests).
  - **E2E:** `frontend/e2e/account-deletion.spec.ts`, `frontend/e2e/production-audit.spec.ts` (focus test).
  - **Staff:** `admin/src/features/audit/pages/AuditLogPage.vue`.
  - **Docs:** `OWNER_DECISIONS_2026-11-22_ADDENDUM.md`, `ACCOUNT_DELETION_AND_EXPORT.md`, `DATABASE_DESIGN.md`, `BACKEND_ARCHITECTURE.md`, `COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (H11).
- Session 27b: public `components/navigation/SiteHeader.vue`, `lib/site.ts`, `features/home/{pages/HomePage.vue,sections/HeroSection.vue,__tests__/homeHeader.spec.ts}`, `e2e/home-header.spec.ts`, `tsconfig.vitest.json`. Staff `features/{guides/pages/GuidesPage,content/pages/DestinationsPage,cms/content/pages/ContentPage,content/pages/RouteEditorPage}.vue`, `features/content/__tests__/saveGuards.spec.ts`, `e2e/save-guards.spec.ts`, `tsconfig.vitest.json`.
- Session 27: `docs/audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (H11 row); the report. Review probes in the session scratchpad (`review/*.php`; read-only, or a single rolled-back transaction as `mrn_app`).
- Session 26:
  - Migration `2026_11_22_100025_add_audit_redaction.php`.
  - `app/Actions/Accounts/{ProcessAccountDeletion,ScheduleAccountDeletion}.php`, `routes/console.php`, `scripts/setup-local-db.sh`.
  - `tests/Feature/AccountDeletionTest.php` (18 tests).
  - Docs: `docs/product/OWNER_DECISIONS_2026-11-22_ADDENDUM.md` (new), `OWNER_DECISIONS_2026-11-22.md`, `ACCOUNT_DELETION_AND_EXPORT.md`, `COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (H11, G19).
- Session 25:
  - Laravel:
    - migration `2026_11_22_100024_add_account_deletion.php`;
    - `app/Actions/Accounts/*`, `app/Support/PendingFiles.php`;
    - models `AccountDeletionRequest`, `OwnershipTransfer`, `ModerationEvidence` (plus `User`, `Contribution` casts);
    - controllers `V1/{AccountDeletionController,OwnershipTransferController}.php`, `Admin/PrivacyController.php`;
    - notifications `AccountDeletionMail`, `OwnershipOffer`, `TripClosed`;
    - edited: `EnsureNotSuspended`, `PublicUserResource`, `UserResource`, `ContributionResource`, `ProfileController`, `TripController`, `EventRegistrations`, `AppServiceProvider` (limiters);
    - `routes/{api/rider,api/staff,console}.php`, `config/permissions.php`;
    - tests `AccountDeletionTest` (new), `RouteAuthorizationTest` (new routes, `index` placeholder).
  - Public site: `profiles/components/{AccountDeletion,OwnershipOffers}.vue` (new), `AccountPage.vue`, `MyActivity.vue`, `services/account.ts`, `types/api.ts`, `e2e/account-deletion.spec.ts` (new), `e2e/support/local.ts`.
  - Staff portal: `features/privacy/*` (new), `app/navigation.ts`, `app/router/index.ts`, `e2e/privacy.spec.ts` (new), `e2e/support/local.ts`, `e2e/settings.spec.ts`.
  - Docs: `ACCOUNT_DELETION_AND_EXPORT.md` (as built), `API_DOCUMENTATION.md`, `DATABASE_DESIGN.md`, `COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (G19, H11), `REQUIREMENTS_GAP_ANALYSIS.md`; `CLAUDE.md` restored.
- Session 24, upload safety:
  - Laravel: `app/Support/{UploadPolicy,Images}.php`, `app/Actions/Contributions/StoreContributionPhoto.php`, `Api/V1/Admin/{MediaController,SettingsController}.php`, `Api/V1/{SiteController,HealthController}.php`, `routes/api.php`, `config/{mrn,settings,filesystems}.php`, `.env.example`, `.env.production.example`, `tests/Feature/UploadPolicyTest.php` (new).
  - Public site: `lib/site.ts`, `ContributePage.vue`, `playwright.config.ts` (`LOCAL_DISK_ROOT=app/e2e`), `e2e/uploads.spec.ts` (new).
  - Staff portal: `media/services/media.ts`, `MediaPage.vue`, `settings/services/settings.ts`, `StorageSettingsPage.vue`, `playwright.config.ts`, `e2e/uploads.spec.ts` (new).
  - Docs: `UPLOAD_SAFETY_DESIGN.md` (rewritten as implemented), `DEPLOYMENT_READINESS.md`, `API_DOCUMENTATION.md`.
- Session 23:
  - New docs: `docs/architecture/{INFRASTRUCTURE_OPTIONS,UPLOAD_SAFETY_DESIGN,ACCOUNT_DELETION_AND_EXPORT}.md`, `docs/product/{DECISION_Q9_CONTRIBUTIONS_ON_DELETION,CONTACT_RETENTION_PLAN}.md`, `docs/security/STAFF_MFA_PLAN.md`.
  - CI: `web/.github/workflows/ci.yml`, `admin/.github/workflows/ci.yml`.
- Session 22:
  - Public site: `assets/styles/main.css` (scroll padding, date focus), `e2e/production-audit.spec.ts` (new).
  - Staff portal: `assets/styles/main.css` (date focus), `e2e/production-audit.spec.ts` (new).
  - Laravel: `tests/Feature/SecurityPostureTest.php` (new).
  - Docs: `PROJECT_AUDIT_CHECKLIST.md` rows A, E, F, G.
- Session 21:
  - Migration `2026_11_22_100023_add_home_planner_and_tools.php`; `config/home.php`; `app/Support/HomeContent.php`.
  - Tests `HomeProductTest` (new) and `CmsTest` (seeded order and headline).
  - Public site: `home/sections/{PlannerSection,ToolsSection}.vue` (new), `home/pages/HomePage.vue`, `home/types.ts`, `e2e/home.spec.ts`.
  - Staff portal: `cms/home/sectionSchemas.ts`.
- Session 20:
  - Migration `2026_11_21_100022_add_trip_expense_status.php`.
  - `app/Models/{TripExpense,TripStop}.php`, `TripPartController.php`, `TripResource.php`.
  - Tests `TripPlanningTest` (new).
  - Public site: `lib/tripDays.ts` with its spec (new); `rides/components/{TripBudget,TripChecklist,TripDays}.vue` and `types.ts` (new); `rides/pages/TripPage.vue`; `types/api.ts`; `e2e/trip-planning.spec.ts` (new).
  - Docs: `TRIP_PLANNING_GAP_ASSESSMENT.md` (new), `DATABASE_DESIGN.md`, `API_DOCUMENTATION.md`, the audit (F4, F15), `PROJECT_AUDIT_CHECKLIST.md`.
- Session 19:
  - Migration `2026_11_21_100021_add_trip_route_parts.php`.
  - `app/Models/TripStop.php`; controllers `TripItineraryController.php` (new) and `TripPartController.php`; `routes/api/rider.php`.
  - Tests `TripRoutePartsTest` (new).
  - Public site: `lib/tripRoute.ts` with its spec (new); `routes/components/RouteToTrip.vue` (new); `routes/pages/RoutePage.vue`; `rides/pages/TripPage.vue`; `rides/services/trips.ts`; `explore/components/DestinationMap.vue` (checkpoint colour); `types/api.ts`; `e2e/trip-route-parts.spec.ts` (new).
  - Docs: `TRIP_ROUTE_PARTS.md` (new), `DATABASE_DESIGN.md`, `API_DOCUMENTATION.md`, the audit (F3).
- Session 18: migration `2026_11_21_100020_add_segment_riding_time.php`; `app/Models/RouteSegment.php`, `app/Http/Resources/RouteResource.php`, controllers `RouteController` (filters), `Admin/RouteController` (time field, attribute names); tests `RoutePlanningTest` (new); public `lib/routePlan.ts` + `lib/__tests__/routePlan.spec.ts` (new), `routes/components/{RoutePlan.vue (new),RouteMap.vue}`, `routes/pages/{RoutePage,RoutesPage}.vue`, `routes/services/routes.ts`, `types/api.ts`, E2E `route-planning.spec.ts`, `route-services.spec.ts` (new); staff `content/pages/RouteEditorPage.vue`, `types/api.ts`, E2E `route-tracks.spec.ts`; audit rows B4, B5, E4, B8, B13.
- Session 17 follow-up: `app/Actions/Places/DecidePlace.php` (new), `app/Http/Controllers/Api/V1/Admin/{PlaceReviewController (new),PlaceController}.php`, `config/permissions.php`, `routes/api/staff.php`, migration `2026_11_20_100019_add_places_review_permission.php`, tests `PlacesReviewPermissionTest` (new), `PlacesTest`; staff `features/places/{components/SuggestionsQueue.vue (new),pages/PlacesPage.vue,services/places.ts}`, navigation, router, E2E `places-review.spec.ts` (new); public `explore/pages/DestinationPage.vue` (wording fix); docs `AUTHORIZATION_MODEL.md`.
- Session 17: migration `2026_11_20_100018_add_trip_places_and_segment_paths.php`; `app/Models/{RouteSegment,TripStop}.php`; `app/Support/TrackImport.php` (new); `app/Http/Resources/{TripResource,RouteResource}.php`; controllers `TripPartController`, `JourneyController`, `DestinationController`, `Admin/RouteController`; `routes/api/staff.php`; tests `TripPlacesAndPathsTest`, `Unit/TrackImportTest` (new); public `explore/components/DestinationMap.vue`, `explore/pages/DestinationPage.vue`, `routes/components/RouteMap.vue`, `rides/{components/AddToTrip,pages/TripPage}.vue`, `places/pages/PlacePage.vue`, `journeys/pages/JourneyPage.vue`, `saved/pages/SavedPage.vue`, `types/api.ts`, E2E `trip-places.spec.ts` (new); staff `content/{pages/RouteEditorPage.vue,services/content.ts}`, `types/api.ts`, E2E `route-tracks.spec.ts` (new); docs `AUTHORIZATION_MODEL`, `DATABASE_DESIGN`, `API_DOCUMENTATION`, audit rows L13, R4, R11.
- Session 16: `web/database/migrations/2026_11_20_100017_create_places.php`; `web/app/Models/{Place,PlaceCorrection}.php`; `web/app/Http/Resources/PlaceResource.php`; `web/app/Http/Controllers/Api/V1/{PlaceController,Admin/PlaceController,DestinationController,JourneyController,SavedItemController,ContributionController}.php`; `web/app/Notifications/PlaceReviewed.php`; `web/config/permissions.php`; routes; tests `PlacesTest`, `RouteAuthorizationTest`; public `frontend/src/features/places/**` (new), `explore/{components/DestinationMap,pages/DestinationPage,pages/ExplorePage,services/destinations}`, `journeys/**`, `saved/**`, `experiences/pages/ContributePage.vue`, `profiles/components/MyActivity.vue`, `routes/components/RouteMap.vue`, E2E `places.spec.ts` (new); staff `admin/src/features/places/**` (new), `content/components/MapPicker.vue`, navigation and router, E2E `places.spec.ts` (new).
- Session 15: `web/scripts/requirements-progress.mjs` (new); `web/app/Http/Controllers/Api/V1/DestinationController.php`; `web/tests/Feature/DestinationNearbyTest.php` (new); public `frontend/src/lib/mapTiles.ts` (new), `features/explore/components/DestinationMap.vue` (new), `features/explore/{pages/DestinationPage.vue,services/destinations.ts}`, `features/routes/components/RouteMap.vue`, E2E `destination-map.spec.ts` (new); staff `admin/package.json` + lockfile (leaflet, @types/leaflet), `features/content/{components/MapPicker.vue (new),pages/DestinationsPage.vue,pages/RouteEditorPage.vue,services/content.ts}`, `features/cms/content/pages/ContentPage.vue`, E2E `destinations.spec.ts` (new), `cms.spec.ts`; `docs/audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md`.
- Session 14: `web/docs/database/POSTGIS_ACTIVATION_PLAN.md` (new), report `web/docs/sessions/2026-11-19-session-14-postgis-audit.html`.
- Session 13: `web/config/{cms,settings,permissions,mrn}.php`; `web/app/Support/{CmsContent,MediaUsages,MailSettings,ProviderProbe,HomeContent,Settings}.php`; `web/app/Models/{CmsEntry,ContributionSuspension}.php`; controllers `Admin/Cms/EntryController`, `ContentController`, `ContactController`, `TripInvitationController`, `Admin/SettingsController`, `Admin/UserController`, `ProfileController`, `TripController`, `TripPartController`, `ContributionController`, `EventController`; `web/app/Actions/Events/EventRegistrations.php`; `web/app/Http/Middleware/{EnsureCanContribute,SelectSessionAudience}.php`; notifications `TripInvited`, `ContributionRightsChanged`; tests `CmsContentTest`, `SettingsTrustAndSuspensionTest`, `TripTest`, `EventTest`, `SavedItemsTest`, `RouteAuthorizationTest`; public `frontend/src/features/content/**`, `rides/components/AddToTrip.vue`, `rides/pages/{RidesPage,TripPage}.vue`, `profiles/components/ContributionPause.vue`, `events/pages/EventManagePage.vue`, `App.vue`, E2E `cms`, `quality`, `journey`, `features`; staff `admin/src/features/{cms/content,contact,settings,riders}/**`, `components/ui/ConfirmDialog.vue`, E2E `cms`, `settings`, `quality`.
- Session 12: `web/database/migrations/2026_11_19_1000{13,14}_*.php`; `web/app/{Enums/ContributionStatus,Models/Source,Models/Concerns/HasSources,Models/ContributionConfirmation,Actions/Contributions/ModerateContribution,Support/Geo,Notifications/ContributionCorrectionRequested}.php`; `web/app/Http/Controllers/Api/V1/{ContributionController,SavedItemController,JourneyController,TripPartController,Admin/ContributionController,Admin/DestinationController,Admin/RouteController,Admin/UserController}.php`; `web/routes/api/*.php`; tests `web/tests/Feature/{TrustPipelineTest,SavedItemsTest,JourneyTest,RouteAuthorizationTest}.php`, `web/tests/Unit/GeoTest.php`; public `web/frontend/src/features/{saved,journeys}/**`, `experiences/components/ContributionActions.vue`, `components/ui/SourceList.vue`, `routes/components/RouteMap.vue`, E2E `web/frontend/e2e/{trust,saved,journey}.spec.ts`; staff `admin/src/features/moderation/**`, `admin/src/features/content/components/SourcesEditor.vue`, `admin/src/features/riders/**`, `admin/e2e/verification.spec.ts`.
- Session 11: `web/package.json` (scripts `dev`, `dev:api`, `dev:web`), `web/scripts/dev-ports-free.mjs`, `web/README.md`, `web/docs/architecture/DEVELOPMENT_SETUP.md`, `web/CLAUDE.md`.
- Session 10 (A8): `web/config/home.php`, `web/app/Support/HomeContent.php`, `web/app/Http/Controllers/Api/V1/{HomeController,Admin/Cms/*}.php`, `web/database/migrations/2026_11_18_100012_create_home_menus_and_notices.php`, `web/tests/Feature/CmsTest.php`, `web/frontend/src/features/home/{sections,services,types.ts}`, `web/frontend/src/lib/site.ts`, `admin/src/features/cms/**`, `admin/src/features/settings/**`.
- Session 10: `web/frontend/**`, `admin/**` (single-app root), `web/routes/api/*.php`, `web/app/Models/StaffAccount.php`, `web/app/Http/Middleware/{SelectSessionAudience,SessionMaxAge,MaintenanceMode}.php`, `web/app/Http/Controllers/Api/V1/Admin/{AuthController,StaffAccountController,UserController}.php`, `web/app/Support/{PreviewToken,Audit}.php`, `web/database/migrations/2026_11_18_100011_create_staff_accounts.php`, `web/tests/{TestCase.php,Feature/StaffAuthTest.php}`, `web/.env.production.example`, `web/scripts/setup-local-db.sh`.
- Session 9 documents: `web/docs/architecture/{PROJECT_COMPARISON,CURRENT_ARCHITECTURE_AUDIT,PROPOSED_ARCHITECTURE,MIGRATION_PLAN,REQUIREMENTS_GAP_ANALYSIS}.md`, report `web/docs/sessions/2026-11-18-session-09-architecture-investigation.html`.
- Scope and plan: `web/docs/audits/{COMPLETE_PRODUCT_REQUIREMENTS_AUDIT,REMAINING_IMPLEMENTATION_ROADMAP}.md`, `web/docs/product/FEATURE_SCOPE.md`, `web/docs/architecture/{SYSTEM_ARCHITECTURE,AUTHORIZATION_MODEL,DEVELOPMENT_SETUP,DEPLOYMENT_READINESS}.md`.
- Stage 1 backend: `web/app/Http/Controllers/Api/V1/{DestinationController,ContributionController}.php`, `web/app/Actions/Events/{EventCapacity,EventRegistrations}.php`, `web/app/Notifications/RegistrationChanged.php`; tests `web/tests/Feature/{RiderWorkflowsTest,RouteAuthorizationTest,EventTest,ContentTest}.php`.
- Stage 1 frontend: `admin/src/apps/public/features/{events/pages/EventsPage.vue,experiences/pages/{ContributePage,ExperiencePage}.vue,explore/pages/DestinationPage.vue,explore/services/destinations.ts,routes/serviceKinds.ts,authentication/components/ResendConfirmation.vue,authentication/pages/{SignInPage,SignUpPage}.vue,profiles/components/MyActivity.vue}`, `admin/e2e/public/rider-tools.spec.ts`.
- Stage 2 backend: `web/config/permissions.php`, `web/app/Support/Permissions.php`, `web/database/migrations/2026_11_18_100009_create_roles_and_permissions.php`, `web/app/Models/User.php`, `web/app/Providers/{AppServiceProvider,FortifyServiceProvider}.php`, `web/app/Policies/*`, `web/app/Actions/Users/{ChangeStaffRole,SuspendUser}.php`, `web/app/Http/Controllers/Api/V1/Admin/{RoleController,StaffInvitationController,UserController}.php`, `web/app/Http/Controllers/Api/V1/StaffInvitationController.php`, `web/app/Notifications/StaffInvitationMail.php`, `web/routes/{api,console}.php`, `web/config/mrn.php` (`admin_url`), `web/tests/Feature/PermissionsTest.php`.
- Stage 3: `web/config/settings.php`, `web/app/Support/{Settings,Images}.php`, `web/app/Http/Middleware/MaintenanceMode.php`, `web/app/Http/Controllers/Api/V1/{SiteController,Admin/SettingsController,Admin/MediaController}.php`, `web/app/Models/{MediaAsset,MediaUsage}.php`, `web/database/migrations/2026_11_18_100010_create_settings_and_media.php`, `web/tests/Feature/SettingsAndMediaTest.php`; `admin/src/apps/public/lib/site.ts`, `admin/src/apps/public/App.vue`, `admin/src/apps/admin/features/{settings,media}/pages/*`, `admin/e2e/admin/settings.spec.ts`.
- Stage 2 frontend: `admin/src/apps/admin/{app/navigation.ts,app/router/index.ts,app/layouts/AdminLayout.vue,features/users/pages/{PeoplePage,RolesPage,AcceptInvitationPage}.vue,features/authentication/staffAccess.ts,types/api.ts}`, `admin/e2e/admin/roles.spec.ts`, `admin/playwright.config.ts` (`E2E_API_PORT`, `ADMIN_URL`).

## 4. Changes Made
- Session 30: documentation only. Privileges were inspected read-only on the dev database.
- Session 29: tests and documentation only. No application code, migration, dependency or data change; the jobs were not activated; no commit or push.
- Session 28: as in §2. Two migrations: one column, two triggers, and the redaction function's signature changed to `(bigint, uuid)`.
  - **Rollback:** `100026` refuses once any redaction exists; otherwise it restores the session 26 function. `100027` restores the owner-or-superuser exemption.
  - No dependency, remote service, deployment, production change, commit or push. The jobs were not activated anywhere.
- Session 27b: as in §2. UI and tests only; no API, schema, data, dependency or permission change. Texture files untouched.
  - Checks:
    - Public and staff type-check, lint, unit (36 and 21), build and `pnpm audit --prod`: all exit 0.
    - E2E public `home-header` + `home` (Chromium, Firefox, mobile): 18 passed.
    - E2E staff `save-guards` + `route-tracks` (Chromium, Firefox): 6 passed.
    - Laravel not run (no backend change).
- Session 27: documentation only (H11 status, handoff, report).
  - `scripts/setup-local-db.sh` was run twice on the local databases to verify it; nothing changed.
  - Probes wrote nothing that persisted (rolled back).
  - No code, schema, data or dependency change; no commit or push.
- Session 26c (texture evaluation, UI only): all 22 directions of the owner's texture brief evaluated in `docs/design/DESIGN_SYSTEM.md` ("Texture"). One chosen: contour lines, on night surfaces only (home freshness band, footer, Explore "no photo" plate).
  - Files: `frontend/src/assets/styles/main.css` (`.texture-contours`), `frontend/public/images/textures/contours.svg` (generated, about 16 KB gzipped), `scripts/contours-svg.mjs`, `FreshnessSection.vue`, `SiteFooter.vue`, `ExplorePage.vue`.
  - Checks: public type-check, lint, unit (30), build exit 0. E2E `production-audit`, `home`, `quality` (Chromium, Firefox, mobile): 55 passed, 10 skipped, 1 failed. The failure is Firefox "keyboard focus…", a `networkidle` timeout; it fails the same way with the texture disabled, so it is not caused by this change. Another Claude session was editing `DestinationPage.vue` at the time. Not investigated further.
  - Report `docs/sessions/2026-11-22-session-26c-texture.html`.
- Session 26 polish (UI only, after owner feedback): rider `AccountDeletion.vue` and `OwnershipOffers.vue`, staff `privacy/pages/PrivacyPage.vue`.
  - Busy labels on offer, withdraw, accept, decline and evidence view; `feedback-in` on status and error messages; long titles wrap (`text-balance wrap-anywhere`); choice cards inside the form use the 2px radius; tabular counts; check icon on the offer result.
  - Unchanged on purpose: no accent colour (marigold fails contrast on paper), no new badges or embellishments; the page already had one emphasised action per state.
  - Checks: public and staff type-check, lint, unit (30 and 13), build exit 0; E2E `account-deletion` 4 passed and `privacy` 2 passed (Chromium, Firefox), exit 0. Report `docs/sessions/2026-11-22-session-26b-deletion-ui-polish.html`.
- Session 26: as in §2.
  - Schema: one migration (a function, and `system` added to the audit actor check). Dev and E2E were migrated, and the audit revoke was restored there.
  - No dependency, remote service, deployment or push; no production data touched.
  - Report: `docs/sessions/2026-11-22-session-26-owner-addendum.html`.
- Session 25: as in §2.
  - **Schema:** one migration, additive except two foreign keys that change from cascade to set null, with check constraints. Two permissions, administrators only.
  - **Unchanged:** no dependency added; no remote service, deployment or push; no real data deleted. Deletions ran only in `myridenepal_test` and on E2E test accounts in `myridenepal_e2e`.
  - **Business rules (documented):** writes are blocked during the cooling-off; anonymised content shows as "Former rider"; organizer-less cancelled events.
  - Report: `docs/sessions/2026-11-22-session-25-account-deletion.html`.
- Session 24, upload safety: as in §2. Two new staff settings (`storage.uploads_enabled`, `storage.uploads_paused_message`; defaults keep uploads on in development); one new env variable (`UPLOADS_ENABLED`, default true) and `LOCAL_DISK_ROOT` (default `app/private`, E2E uses `app/e2e`). No migration, no dependency, no permission added, no remote service, no media migrated. **Business-rule change (documented):** the local disk is refused outside `local` and `testing`. Report `docs/sessions/2026-11-22-session-24-upload-safety.html`.
- Session 23: documents and CI workflow files only. No application code, migration, dependency, data, remote service, purchase or push. Report `docs/sessions/2026-11-22-session-23-infrastructure-privacy.html`.
- Session 22: audit plus two accessibility fixes (CSS only) and new tests. No migration, no dependency, no data change, no remote service. Report `docs/sessions/2026-11-22-session-22-production-readiness.html`.
- Session 21: as in §2. One content migration (no schema columns); no dependency; no permission change.
  - Proposed copy (hero, planner, tools) describes features that exist; staff can change all of it.
  - Report `docs/sessions/2026-11-22-session-21-homepage-product.html`.
- Session 20 polish (2026-11-22, after owner feedback):
  - Trip and route-to-trip screens: one emphasised action at a time on "Add to a trip"; "Adding…" states on add buttons; status and error messages settle in (`.feedback-in`, removed under reduced motion).
  - Long titles wrap (`text-balance wrap-anywhere`); tabular numbers on totals, progress and days.
  - The add-stop form sits under the stop list, with the map and day view after it; icon buttons are consistently 36px; checkpoint rows are aligned; the visible "stretch" word is now for screen readers only.
  - Unchanged on purpose: no new accent colour (the design system allows crimson only on paper; marigold fails contrast there); no filter badges, italics or mono text existed. Radii stay 2–4px, so inner corners never exceed outer ones.
  - Checks: public type-check, lint, unit (30), build exit 0; E2E trip-planning, trip-route-parts, trip-places, features, route-planning (Chromium, Firefox, mobile) 27 passed, exit 0.
- Session 20: as in §2. One additive migration; no dependency; no permission change; no real data.
  - Documented rule changes: expenses can be planned without an amount, and spent ones need one; existing expenses became planned.
  - Report `docs/sessions/2026-11-21-session-20-trip-planning.html`.
- Session 19: as in §2. One additive migration; no dependency; no permission change; no real data. Two documented rule changes to generic stops: route-only stops are deduplicated, and route links cannot be changed by PATCH. Report `docs/sessions/2026-11-21-session-19-trip-route-parts.html`.
- Session 18: as in §2. One additive migration. No dependency, no permission change, no real data added. Report `docs/sessions/2026-11-21-session-18-route-planning.html`.
- Session 17 follow-up: as in §2. One additive migration (one permission row, one role grant). No dependency, no other role change. Report `docs/sessions/2026-11-20-session-17b-places-review.html`.
- Session 17: as in §2 Session 17. One additive migration applied to dev and E2E. No dependency, no role or permission assignment change, no real data added. Report `docs/sessions/2026-11-20-session-17-trip-places-route-tracks.html`.
- Session 16: as in §2 Session 16. Docs: audit rows R2, R3, R9, R12, R13, A4, L4, R10, L13; `DATABASE_DESIGN.md`, `API_DOCUMENTATION.md`, `AUTHORIZATION_MODEL.md`; report `docs/sessions/2026-11-20-session-16-places-of-interest.html`. No dependency added. No real places, coordinates or photos were added to the development database.
- Session 15: as in §2 Session 15. No migration, no database schema change (dev and E2E data untouched apart from E2E test rows the specs create and delete); one dependency added to the staff portal (Leaflet, the approved map library). Report `docs/sessions/2026-11-19-session-15-destination-maps.html`.
- Session 14: documentation only (the plan, this handoff, the session report). No code, schema, data, extension, Docker or configuration change.
- Session 13: everything in §2 Session 13. Docs: requirement audit (40 rows, incl. CM-X1–X8, CM1–CM15, S2/S5–S11, F12, D11, D13, C14, H9, R11, X6, X7, X9), `API_DOCUMENTATION.md`, `DATABASE_DESIGN.md`, `AUTHORIZATION_MODEL.md`, `DEPLOYMENT_READINESS.md` (mail, maps CSP, provider options, limits, retention), `DEVELOPMENT_SETUP.md` (quality checks), `.env.example` (`CONTACT_PER_HOUR`), this handoff, report `docs/sessions/2026-11-19-session-13-cms-settings-invitations.html`. No commit, no dependency, no deployment, PostGIS not enabled, no process of the owner's stopped.
- Session 12: everything in §2 Session 12; docs updated: `COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (22 rows: C13 H6 B11 C17 H10 H5 H4 H3 A11 L12 H9 SV1–SV3 F8 R1 R5–R7 R10 R11 R15), `API_DOCUMENTATION.md`, `DATABASE_DESIGN.md`, this handoff, session report `docs/sessions/2026-11-19-session-12-trust-saved-journeys.html`. No commit, no dependency added, no process of the owner's stopped.
- Session 11: combined dev command and its port guard; startup docs updated. No application code, API, database, auth or build changed.
- Session 10: A1–A6 (§2); docs updated: both `CLAUDE.md` and `README.md`, `SYSTEM_ARCHITECTURE`, `DEVELOPMENT_SETUP`, `DEPLOYMENT_READINESS`, `AUTHENTICATION_FLOW`, `AUTHORIZATION_MODEL`, `MIGRATION_PLAN`, `REQUIREMENTS_GAP_ANALYSIS`, `FEATURE_SCOPE`; both CI workflows. Recovery archive `_backups/2026-11-18-pre-option-a/`.
- Session 9: five new architecture documents (above), this handoff, session report, and a pointer line at the top of `docs/audits/REMAINING_IMPLEMENTATION_ROADMAP.md`. No code, configuration, schema, data, dependency, process or git change.
- Session 8, Stage 3: settings, maintenance, registration, media library, storage settings (files in §2 and §3); general API rate limit now configurable (`API_PER_MINUTE`, default 120) so the E2E server can raise it.
- Session 8: Stage 1 and Stage 2 as in §2; audit rows updated (Part 1 summary recounted by script; N3 rows); `AUTHORIZATION_MODEL.md` rewritten for permissions; setup doc gained `mrn:permissions:sync`, `ADMIN_URL`, `E2E_API_PORT`; `.env.example` gained `ADMIN_URL`; roadmap marks Stages 1 and 2 done.
- Session 7: scope Part 2 and Option B migration (public app to `admin/src/apps/public`, staff to `admin/src/apps/admin`, Laravel backend only).
- Session 6: requirements audit and first roadmap; local dev admin account.
- Session 5: Vercel container preparation. Session 4: Laravel 12 API for all areas.

## 5. List of Failed Attempts
- Session 30: none. My first draft of the checklist command printed the owner password; it now prints only whether one is set.
- Session 29: none. The full public suite passed on the first run.
- Session 28 (all fixed):
  - The migration's jsonb `?` was read as a bind placeholder; it now uses `DB::unprepared`.
  - `migrate:fresh` keeps functions, so a re-created function clashed; the migration now uses `create or replace`.
  - **First full public E2E:** exit 1 (166 passed, 7 failed, 10 skipped).
    - Six failures were the deletion journey: its psql date shift was refused, because local `postgres` is a member of the owner role, not a superuser. Migration `100027` exempts members.
    - One Firefox `route-services` failure passed on rerun.
  - A test compared timestamps that the database stores in whole seconds; it now allows one second.
- Session 27b (all fixed):
  - The vitest tsconfig `lib: []` broke type-checking once tests imported components.
  - Lint refused untyped `vi.fn()` and conditional expects.
  - A test router lacked the named route `cms-content`, and a route fixture lacked `services`.
  - The first staff E2E cleanup deleted routes before their service points (check `service_points_place` refuses a point with neither segment nor destination); the rows were removed on the rerun.
  - A zsh `$F` word-splitting slip aborted a bisect before it changed anything.
  - My port-wait loop matched its own command line in `pgrep -f`.
- Session 27:
  - **Another session was active:** it ran public E2E and edited frontend styling (sessions 26b and 26c) during the review. The frontend and E2E runs waited until it had been quiet for 5 minutes.
  - **Full public E2E:** exit 1 (158 passed, 3 failed, 10 skipped). The rerun of the failing specs gave exit 1 (5 passed, 1 failed, 4 skipped).
    - The remaining failure is the Firefox keyboard-focus audit. It waits for network idle on a route page that loads external OpenStreetMap tiles; it passed earlier today and is not caused by session 26.
    - The Chromium LCP (4468 ms > 4000) and upload sign-in failures passed on rerun.
- Session 26 (fixed):
  - Three new test mistakes: an unedited field was expected in the audit diff; the D-10 timeline mixed the ages of the cancelled and done records; counts compared in key order. The code was right.
  - Verification showed the app role could change audit rows in dev and E2E (the setup-script cause is in §2).
- Session 25 (all fixed):
  - **psql and information_schema:**
    - Reading `.env` for psql was refused by the permission system, so queries go through `php artisan tinker`.
    - `information_schema.constraint_column_usage` hides foreign keys from the app role, so `pg_constraint` is used.
  - **The route authorization test:** it put a UUID in the numeric `{index}` route parameter (404 before the guard); it now uses a numeric placeholder.
  - **"Former rider":** `whenLoaded('author')` skips the closure when the author is null, so the placeholder never appeared. It now uses `when(relationLoaded(...))`.
  - **File-removal retries:** one command run counted three retries (flushed three times); files are now flushed once per run.
  - **Feature tests:**
    - Travelling 30 days expired the test sessions (401); the helper now flushes the session after the run.
    - A deliberate constraint violation aborted the test transaction; it now runs in a savepoint.
  - A cooling-off test compared timestamps a second apart when the clock ticked; the class now freezes time to the second (3 reruns green).
  - **E2E:**
    - A text match anchored with `^` missed text after an icon.
    - Signing in again raced the unfinished sign-out (Chromium).
    - "Delete your account" also matched the auth spec's "Your account" heading; it is renamed "Account deletion".
    - The staff evidence row is now targeted by `data-evidence-id`.
- Session 24, upload safety (all fixed):
  - Allowing `s3` as a `storage.driver` value broke the D1 rule test; reverted to `local` only, and the durable-disk test writes the setting directly.
  - Environment-switching tests got 419, because CSRF applies outside `testing`; those tests skip the CSRF middleware.
  - A planned `UPLOADS_LOCAL_DISK_IS_PERSISTENT` override was dropped because O6 says the local disk is development only.
  - The public E2E photo fetch with `page.request` got 403 (no Origin, so no session); it now fetches from inside the page.
- Session 23:
  - Two names in my first drafts didn't exist in the code (`people.manage`; a `user` morph alias for notifications). Both were corrected after checking `config/permissions.php` and the morph map.
  - Pricing sources disagree in places (Flare, Neon base, Postmark at volume); the docs show the ranges and say to verify.
  - GitHub Actions cannot be run locally, so the workflows are syntax-checked only.
- Session 22 (all fixed or explained):
  - **First contrast run:** it timed out, and it misread Tailwind 4 `oklab()` colours as near-black, producing false failures on the header. Colours are now converted by the browser's canvas, and see-through fixed layers are not judged.
  - **First tap-target run:** it ignored WCAG 2.5.8's spacing exception (text links 20 px tall with space around them pass).
  - **The `:focus` fix for date fields didn't work:** focus on the calendar button leaves the field matching only `:focus-within`, found by tabbing segment by segment.
  - **WebKit cannot launch here:** missing system libraries.
- Session 21 (fixed):
  - The CMS ordering test assumed exactly four sections; it is now scoped to the original four.
  - jsonb reorders keys, so one assertion now compares values, not key order.
  - E2E label clash: "Start" also matched "Swap start and destination".
  - The first screenshots were taken mid reveal-animation, so sections looked blank. The test now waits for the reveal; items below the fold correctly stay hidden until scrolled to.
- Session 20 (all fixed):
  - My test assumed a deleted destination nulls the stop link. Destinations are soft-deleted, so the link stays and the stop is flagged as unavailable, which is correct; the test was fixed.
  - An E2E label clash ("Stop" also matched "Overnight stop").
  - An E2E race: typing during a save was wiped when the form reset. The test now waits for the save, and the budget form now resets only if nothing new was typed.
  - The first full public E2E run was interrupted by the session ending, then re-run in full.
- Session 19 (all fixed):
  - One of my test assertions compared key order.
  - Lint flagged a redundant spread in the new E2E spec.
  - `npm audit` cannot run here (the lockfile is pnpm's); `pnpm audit --prod` is the check used.
  - My first staff E2E run had no project filter, so WebKit ran and could not launch (24 failures, environment only). It was re-run on Chromium and Firefox: 48 passed.
- Session 18 (fixed): a type error in the filter form; a test fixture tried to mass-assign `path` (correctly refused); the staff validation message showed the raw attribute name (now "riding time"); a unit-test expectation of mine was wrong (the code was right: largest neighbouring pair is stretches 2 and 3); Leaflet fades a closed popup out, so two popups existed briefly in a test (now waits for the close). The table was cut off on phones (fixed with cards).
- Session 17 follow-up: two test mistakes (role update is PATCH; role slugs refuse hyphens); a direct API call from Playwright to :8011 has no SPA origin, so Sanctum answered 401 (now via the portal proxy with a Referer); E2E cleanup deleted a role before its assignment. **Process failure found:** session 17's E2E result was misreported (3 failures missed by reading only `tail -4`); from now on the full filtered output is read before reporting.
- Session 17 (fixed): a forced Playwright click on a map line landed on the bounding-box centre, off the line (test now dispatches the click to the line element; real clicks on the stroke work).
- Session 16 (all fixed): a non-existent `ValidationException::withResponse()` (now `HttpResponseException`); hidden resource fields rendered as `[]` because of `toArray()`; a test that looked like a status leak was the shared in-memory test session (guest helper now flushes it); several ambiguous Playwright locators (option text, two cells, two status regions); a first header-overlap check using `elementFromPoint` passed even without the fix (Leaflet tiles ignore pointer events), so it was replaced by an isolation assertion plus a screenshot.
- Session 15 (all fixed): the staff E2E first navigated before sign-in completed; the map tile wait failed because the Edit form never opened (real `DataCloneError` bug, found in the Playwright trace); `getByLabel('Latitude')` also matched the map's label; the database check ran before the save finished (now waits for the form to close and polls).
- Session 14: none. Note: the host `pg_dump` is 18.6 and the server is 17.12, so the plan uses the container's `pg_dump` 17.12.
- Session 13 (all fixed): a test helper named `post()` clashed with Laravel's; a staff session expired after `travel(2)->days()` (12 h limit, correct); route `defaults()` are passed in a different order than expected (controllers now read `route('type')`/`route('answer')`); the route matrix refused unclassified routes and a `{answer}` constraint that answered 404 before auth (split into two routes); PDO treats `?` as a placeholder (used `jsonb_exists`); the contact rate limit blocked repeated E2E runs (`CONTACT_PER_HOUR` for the E2E API); the popup list is cached 60 s by the browser (test creates it first); several ambiguous E2E locators; a Vue template type cast with `|` parsed as a filter; `rides/components` did not exist so a heredoc failed; WebKit cannot launch (system libraries).
- Session 12 (all fixed): new E2E caught the experience page and moderation queue unmounting on refresh (messages lost), then the moderation card not following prop updates (`toRef`); `getByLabel('Start')` also matched the swap button (exact match); the route-authorization matrix failed until `journeys` (public) and `users/me/saved` (account) were classified; one combined check command was killed (exit 137, low memory: about 2.3 GB free with the owner's servers running), rerun step by step.
- Session 11: a `sed` edit of the guard message did not apply (quoting), redone with Python; one `php artisan test` run showed 7 failures that did not recur in three later full runs (104/104 each); the failing output was not captured, so the cause is unknown (possibly timing-sensitive tests).
- Session 10, A8 (fixed): the public app loaded menus and notices with `Promise.all`; during maintenance they answer 503, which also discarded the site settings, so the "Back soon" page did not show (caught by E2E, now loaded with `allSettled`); `FieldEditor` first mutated its prop (lint), now edits through a `computed`.
- Session 10 (all fixed): an interrupted E2E run (rerun clean); `setsid` changed the PID, and the owner's old `pnpm dev` held :5181 (left alone, re-verified on 5190/5191); container build failed on Scribe config (real bug); empty route names broke `route:cache`; test harness needed to mirror separate browser sessions (in-memory session reuse, Sanctum's cached user, leaked login keys); `AuditLog` fillable lacked `actor_type`; `EventResource` passed a staff account to a rider lookup; one `git mv -k` was tried and skipped (index untouched); a spec still used the removed invitation URL; writing a dev password into this file was refused (credentials stay out of the repo).
- Session 9: zsh expanded unquoted `--include=*.vue` globs (grep ran with no matches; rerun with quotes or `bash -c`); one frontend check command was not reviewed by auto mode and was re-issued unchanged; the first gap-analysis totals counted 25 new rows instead of 36 (fixed). The Vercel failure message could not be read (no linked project, CLI or logs on this machine).
- Session 8, Stage 3: `config()` dot notation broke a registry override in a test (dots inside keys); E2E cleanup deleted settings rows but not the API's settings cache, so sign-up stayed closed for the next test and the next run (cleanup now clears the cache, before and after); a full run hit 429 because every browser shares 127.0.0.1 (the 120/min API limit), fixed by a test-server-only `API_PER_MINUTE`.
- Session 8 (all fixed, none in product code unless stated): tests first wrote `author_id`/`organizer_id` through `create()` (not mass-assignable, correctly); audit columns are `old_data`/`new_data`; event updates are newest first; the factory password is `password-1234`; mixing the web guard (password confirmation) with Sanctum gave 401s, fixed by Sanctum + session flag and flushing the session between identities; a stale model kept cached roles; a "last admin" test was not really the last admin (fixed by deactivating the others). E2E: sign-out on /events is in a menu (used a second browser context); an ambiguous "Request sent" locator; the role confirmation text was reworded then restored because the existing test checks it; cleanup deleted a role still assigned. The first admin E2E run reused the owner's Laravel on :8010 (old `APP_URL`, unmigrated DB): migrated the local DB and added `E2E_API_PORT`.
- Session 7: a test built usernames from project names containing hyphens; `pkill` matched its own shell.
- Earlier: `.env.local` trap, `whereILike`, Fortify reset-link binding, route-name prefix, CSP for the SPA page, pnpm 12 audit config, Kong, rate-limiter bucket.

## 6. Next Steps
0. **After session 30, owner decisions:**
   1. Approve F1 as a deployment rule.
   2. Approve Option B for an implementation session (about half a session; plan in the design §6).
   3. Whether to start a separate account-takeover review (§8).
0. **After session 29:** the production blockers are listed in `docs/architecture/PRODUCTION_PRIVACY_CHECKLIST.md` §9:
   - hosting, privilege verification, cron, mail, durable storage;
   - a restore-and-replay tool;
   - staff MFA, contact retention;
   - WebKit/CI, the account-id hash, log retention.
   - **Optional hardening (owner decision):** revoke DELETE on `users` from `mrn_app` and remove accounts only through a definer function that checks a due request, so that even a compromised app role needs 30 days.
0. **After session 28:**
   - **Before production:**
     - verify in the production database: `has_table_privilege('mrn_app', 'audit_logs', 'update')` and `delete` are false, both triggers exist, and `mrn_app` is not a member of the owner role;
     - set the cron;
     - get owner approval, then set `ACCOUNT_DELETION_PROCESSING_ENABLED` and later `ACCOUNT_DELETION_RECORDS_PURGE_ENABLED`.
   - **Owner, deferred:** the account-id HMAC, with a restore-replay design and key lifecycle.
   - **Low:** R6 (handover counts), R7 (system actor label).
   - **Next in the decision record:** contact retention, then staff MFA.
0. **After session 27b:**
   - Owner: commit `web/frontend/e2e/home-header.spec.ts` and `admin/e2e/save-guards.spec.ts`.
   - Open edge: staff can add two hero sections, which would give two h1s. Decide whether the CMS should allow only one hero.
   - Finding: deleting a route that has service points fails (`service_points_place`). Staff routes are soft-deleted, so only hard deletes are affected; check before any route purge.
0. **After session 27: one focused implementation task (not started):**
   - **Must:**
     - R8: keep the moderator's `last_confirmed_on`. Recompute only when the leaving rider's confirmation set it, taking the later of the remaining confirmations and the latest `contribution.confirmed`.
   - **Should:**
     - R2: the function refuses entries whose subject still exists with an author; run the redaction after the delete and anonymise step.
     - R1: a trigger rejecting `system` or `audit.redacted` inserts unless they come from the function.
     - R5: report a sanitised exception.
   - **Could:** R3, R4, R6, R7.
   - **Owner:**
     - HMAC for the account id (with a restore runbook, and a decision on the raw id in `audit_logs.actor_id`).
     - Whether the daily run needs an env switch.
     - Confirm R9.
   - Make the focus audit test independent of external map tiles.
0. **After session 26:**
   - The addendum is done.
   - Next in the decision record: contact retention (step 5; purge off by default), then staff MFA (step 6).
   - Before production: check `has_table_privilege('mrn_app', 'audit_logs', 'update')` is false; set the scheduler cron.
   - Optional: the 3-day reminder email and a signed cancel link.
0. **After session 25:**
   1. **Owner:** decide O8 (audit entries that hold contribution text: keep, or redact by a privileged job), the request-row retention period, and whether corroboration should drop below the threshold.
   2. Optional, no decision needed: the 3-day reminder email and a signed cancel link.
   3. Continue the decision-record order: contact retention (step 5; purge off by default), then staff MFA (step 6). Steps 3 and 4 (transfers, evidence) were built with step 2.
   4. At hosting time: cron `schedule:run` (the deletion command runs daily at 02:30), a production mail provider (O5), durable storage (O6).
0. **After the session 24 upload switch:** the uploads item from the session 23 roadmap is done. Continue with the decision-record order (account deletion first). When a storage provider is approved: approved package, disk config, allow the value in `storage.driver`, choose it, run "Test storage", then set `UPLOADS_ENABLED=true` and verify before claiming persistent storage. Fix `web/CLAUDE.md` (self-symlink).
0. **After session 24 (order from the decision record §3):**
   1. Account deletion, 30-day cooling-off, contribution choices and export (design §2.1, §2.2, §5).
   2. Event and group-trip transfers (owner confirms O2 first).
   3. Moderation evidence and its purge.
   4. Contact retention (spam and closed).
   5. Mandatory staff MFA with the 14-day grace.
   6. Full regression, authorisation, privacy, retention and browser tests.
   7. P1 verification list, then a final cost proposal for owner approval (no purchase before).
   8. Pre-production readiness once O1–O7 are resolved. Fix `web/CLAUDE.md` before anything else.
0. **After session 23 (ordered roadmap):**
   1. **Owner decisions:** Q9 (and Q9a–d), D7 periods, AR3 approval, hosting profile (P1, P2 or P3, or other), email provider, Sentry yes or no, the S3 package (`league/flysystem-aws-s3-v3`) for D1, and the Pint formatting commit. Confirm the dollar-card limit with the bank.
   2. **Build, no further decision needed:** the uploads kill switch and production `local`-disk guard (about 1 session).
   3. **Build after Q9:** account deletion and export (about 2 sessions).
   4. **Build after AR3:** staff MFA (about 1.5 sessions).
   5. **Build after D7:** contact retention (about 0.5 session).
   6. **Owner:** commit and push both repos. The first CI run shows whether WebKit passes; make WebKit blocking once green.
   7. **After the hosting choice (pre-production, with approval):** provision, set the S3 disk, scheduler and `expose_php=Off`, run a restore drill, connect email and Sentry, staging smoke test.
   8. Then the remaining feature work (F8, F10, F6) and real content entry.
0. **After session 22, in order:**
   1. **Owner decisions that block launch:** AR4 hosting, AR5 production Postgres with automated backups and a tested restore, D1 storage (until then, add and use an "uploads off" switch), email provider, error monitoring, AR3 staff MFA.
   2. **Build, no decision needed:**
      - G19 and H11 account deletion and data export (needs Q9: what happens to a deleted rider's contributions).
      - A production uploads kill switch.
      - `expose_php=Off` in the production image.
   3. **Approve and add:** `@axe-core/playwright` and Lighthouse CI; install the WebKit system libraries (or rely on CI's `--with-deps`).
   4. **Content:** staff to enter real routes with stretches, services, places and events. A clearly labelled dev dataset (fictional "[DEV]" names, `is_dev_seed`) needs owner approval.
   5. **Performance:** look at route-page LCP on phones (Leaflet and API waterfall) and the home menu-tap latency.
0. **After session 21:**
   - Real published routes and destinations make the homepage planner preview meaningful. Until staff publish a route with stretches, it shows what a plan contains.
   - Owner: review the proposed homepage copy in the staff portal.
   - Then, no decision needed: F8 saved guides, G19 and H11 account deletion and export, F10 private notes, F6 budget target.
0. **After session 20:**
   - Owner Q6 (difficulty) and seasonal data are still open.
   - No decision needed: saving guides (F8); account deletion and export (G19, H11); per-member private notes (F10); a budget target and per-person split (F6).
   - E5 (accommodation planning) may now meet its criteria with lodging services as stops plus the day view. Verify it before changing its status.
   - Optional: arrival and departure times on stops.
0. **After session 19:**
   - Owner Q6 (difficulty scale) still blocks B6 and B13.
   - Seasonal data must come from the owner.
   - Next, with no decision needed: saving guides (F8); account deletion and export (G19, H11); per-member private notes (F10); a budget target and split (F6); browser tests for overnight, budget and checklist paths, which would close F4 and F15.
   - Optional F3 depth: per-day totals.
0. **After session 18:** owner decision **Q6** (difficulty scale) unblocks route difficulty and its filter (B6, B13); seasonal data for B13 and A8/L9 must come from the owner; F3 (stops on specific stretches/checkpoints in a trip) is the next route-planning gap; saving guides (F8); account deletion and export (G19, H11).
0. **After the follow-up:** real places and tracks to be entered by staff; route depth (B rows), preparation and safety (E rows), saving guides (F8), account deletion and export (G19, H11).
0. **Session 17 leaves:** owner decision on `places.review`; staff to import real GPX tracks and places; route depth (B rows 30.8%), preparation and safety (E rows 21.4%), saving guides (F8), account deletion and export (G19, H11).
0. **Session 16 leaves (no owner decision):** draw routes on the destination map (L13); route depth (B rows 30.8%); preparation and safety (E rows 21.4%); destinations depth (A/L rows 28%: seasons, permits, costs need owner-supplied content); account deletion and data export (G19, H11); add places to trips (trip stops have no `place_id` yet). Owner: enter real places and coordinates through the staff portal (none were invented), decide whether moderators should get `places.manage`/`places.publish`.
0. **Session 15 leaves (no owner decision):** places model and rider-submitted places without waiting for PostGIS (R2, R3, R9, R12, R13, A4, L4: 7 rows at 0%); draw nearby routes on the destination map (L13); preparation and safety content workflow (E rows, 21.4%); route depth (B rows, 30.8%); account deletion and data export (G19, H11); saving guides (F8). Run `node scripts/requirements-progress.mjs` at the start and end of every session.
0. **Owner:** approve or adjust `POSTGIS_ACTIVATION_PLAN.md` (D3). On approval: backups (§3), setup-script activation as `postgres` in all three databases, `search_path`, CI image, migrations `100017`/`100018`, parity tests, then `GEO_ENGINE=postgis`.
1. **Session 13 leaves (no owner decision needed):** branding logos/favicon/colours (S6); notification and feature settings (S11); delivery-failure view for mail; corroboration from matching independent reports on the same segment (C14 refinement); saving guides (F8); `TripPage` and staff `MediaPage` splits; event completion photos (D15), multi-route events (D18), event reports (D17, needs Q2); account deletion/export (G19, H11).
2. **Need the owner:** D3 PostGIS (places model for viewpoints, temples, lakes, rider-submitted places), D2 routing/geocoding provider (proxies, detours, free-text start), D1 storage, D4 SEO prerender, D7 contact retention period, AR3 staff MFA, AR4 hosting, AR5 production Postgres; approval to add `@axe-core/playwright` and Lighthouse; installing WebKit system libraries; owner-written About/Privacy/Terms texts for CMS pages.
1. **Session 12 leaves, in order (no owner decision needed):** (a) add-to-trip directly from the journey page and from destination service lists; (b) Stage 8 remaining CMS modules (pages + content blocks, FAQs, blog with categories/tags/authors, team, popups, banners, contact messages with inbox); (c) Stage 9 settings screens (mail with test connection, map settings, security, notifications); (d) Stage 11 rest (trip invitations with consent, private notes, trip contacts, budget, event participant list/removal, postponement dates); (e) `TripPage` and staff `MediaPage` splits; (f) saving guides (F8).
2. **Need an owner decision:** D3 PostGIS (then a `places` model with categories and rider submissions, geometry, GiST; replaces `App\Support\Geo`), D2 routing/geocoding provider (detour distance/time, alternatives, free-text start), Q4 corroboration rule (Stage 6), contribution-only suspension (H9), plus AR3 staff MFA, AR4 hosting, AR5 production Postgres, D1 storage, D4 SEO prerender, D6 banners, D7 contact retention.
0. Watch for the intermittent backend test failure seen once in session 11; capture the output if it happens again.
1. **Roadmap Stage 4 (next, no decision needed):** trust pipeline (moderation transitions, correction requests, report categories, history for moderators, `last_confirmed_on`, structured sources, offence counts).
2. Then Stage 8 remaining CMS modules (pages, FAQs, blog with categories/tags/authors, team, popups, content blocks, contact messages), Stage 9 settings (mail, map, security, notifications, integrations, features), Stage 11 My Rides/events (incl. `TripPage` split, trip invitations with consent, saved services), and the rest of `REMAINING_IMPLEMENTATION_ROADMAP.md`.
3. Owner decisions still blocking: AR3 staff MFA, AR4 hosting plan, AR5 production Postgres, D1 storage, D2 routing/geocoding, D3 PostGIS, D4 SEO prerender, D6 banner policy, D7 contact retention; Q2, Q4, Q6–Q11.
4. Earlier items below remain valid where not done.
0. **Owner, before anything else:** (a) decide AR1 (Option A), AR2/AR2b (separate staff accounts; rider link), AR3 (staff 2FA), AR4 (hosting plan), AR5 (production Postgres) from `PROPOSED_ARCHITECTURE.md` §10; (b) commit and push both working trees (recovery point A0; GitHub `HEAD` does not contain sessions 7–8); (c) share the Vercel build/runtime log; (d) add the original master brief to `docs/product/` if it still exists. Then `MIGRATION_PLAN.md` A1–A8, then roadmap Stage 4. If AR1 is postponed, A1 (one-line dev-script fix) can be done alone.
1. **Stage 4 (after the migration, no decision needed):** moderation transition rules, correction requests with an author addendum, report categories with dangerous-first ordering and per-report dismissal, moderation history visible to moderators, `last_confirmed_on` on contributions, structured sources shared with editorial records, offence counts on People.
2. The S3-compatible storage driver waits for **D1** (provider + `league/flysystem-aws-s3-v3`). After Stage 4: (trust pipeline: moderation transitions, correction requests, report categories, history for moderators, `last_confirmed_on`, structured sources), Stage 8 (CMS content, after 2 and 3), Stage 9 (settings screens).
3. **Owner decisions** still open (audit Part 4): D1 storage, D2 routing/geocoding, D3 PostGIS (blocks Stage 5 and so Stages 7 and 10), D4 crawler-visible SEO, D5 languages, D6 banners, D7 contact retention; Q2, Q4 (blocks Stage 6), Q6–Q11.
4. Owner: commit both repos (then remove the backup folder), push for CI, WebKit system libraries, deployment when ready.
