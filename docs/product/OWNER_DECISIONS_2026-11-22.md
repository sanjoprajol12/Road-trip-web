# Owner decision record, 22 November 2026

**Status: approved by the project owner.** These decisions are the implementation requirements for the work that follows. The approval boundaries in section 4 still apply.

This file records the decisions. **Addendum (same day):** O8 (audit text redaction), D-10 (deletion records kept one year) and D-11 (corroboration recalculated) are in `OWNER_DECISIONS_2026-11-22_ADDENDUM.md`. The documents that implement them are:

| Decision | Implemented in |
|---|---|
| Q9, D-01, D-02, D-03 | `docs/architecture/ACCOUNT_DELETION_AND_EXPORT.md` (the options are in `DECISION_Q9_CONTRIBUTIONS_ON_DELETION.md`) |
| D-04 | `ACCOUNT_DELETION_AND_EXPORT.md` §4 (moderation evidence) |
| D-05, D-06 | `docs/product/CONTACT_RETENTION_PLAN.md` |
| D-07 | `docs/security/STAFF_MFA_PLAN.md` (AR3) |
| D-08, D-09 | `docs/architecture/INFRASTRUCTURE_OPTIONS.md` (AR4, AR5) |

## 1. Approved decisions

| ID | Area | Decision |
|---|---|---|
| Q9 | Contributions on deletion | **D: the rider chooses** what happens to each eligible contribution. |
| D-01 | Account deletion | **30-day cooling-off period.** |
| D-02 | Events with registrations | Transfer to another organiser. If that is not possible, cancel the event and notify the registered riders. |
| D-03 | Group trips | Transfer ownership to a member who consents. If that is not possible, delete the trip and notify the members. |
| D-04 | Moderation cases | Hold for at most 30 days, keeping evidence under restricted access where needed. |
| D-05 | Spam contact messages | Keep for 30 days. |
| D-06 | Closed contact messages | Keep for 12 months. |
| D-07 | Staff MFA | Mandatory before public launch, with a 14-day enrolment grace period. |
| D-08 | Hosting | P1 is the selected candidate, subject to a final cost and capacity review. |
| D-09 | Infrastructure budget | **No budget is approved yet.** |

## 2. What the owner specified

### Q9 option D: the rider's choice

For each eligible contribution, the rider picks one of:

- **Delete:** the published contribution is removed.
- **Keep anonymously:** it stays, with identifying information removed.
- **Keep anonymously and remove photos:** it stays anonymised, and its photos are removed.

The screen explains each choice before the rider confirms. **No policy is chosen silently on the rider's behalf.** The implementation decides which content can be anonymised safely, which must be deleted, and which needs restricted retention for moderation or legal reasons.

### D-01: deletion and cooling-off

- The rider re-enters their password, or confirms in an equally secure way.
- The 30-day period starts when the request is made.
- The rider is shown the date the deletion takes effect and what happens until then.
- There is a defined way to cancel a pending request.
- Ordinary use of the account stops where appropriate, but no data is destroyed early.
- When the period ends, the deletion runs with the rider's contribution choices.
- Files are removed after the database step; failed removals are retried.
- The rider can export their data before the deletion is final.
- Only the minimum deletion and security record is kept.

### D-02 and D-03: events and group trips

- **Events:** try to transfer the event to another eligible organiser. If no transfer completes, cancel the event and notify the registered riders. Keep only the minimum needed for notification, accountability and legal obligations.
- **Group trips:** offer the trip to a member who consents. If nobody eligible accepts, delete the trip and notify the members. **Ownership is never transferred without consent.**

### D-04: moderation evidence

- The hold lasts at most 30 days; **there are no indefinite holds.**
- Only authorised moderation staff can see the evidence.
- The reason, every access, and the outcome are recorded.
- When the hold ends, the evidence is deleted or anonymised, unless a separately established legal obligation applies.

### D-05 and D-06: contact messages

- Spam is deleted after 30 days.
- Closed messages are deleted after 12 months.
- **Open messages have no approved period. They are not deleted automatically** until the owner approves a policy.
- Cleanup is audited, repeatable and safe to retry.

### D-07: staff MFA

- Required for staff before the public launch, with a 14-day enrolment grace period.
- Authenticator-app codes and one-time recovery codes, as in the existing plan.
- Audited: enrolment, use of a recovery code, administrator resets, and emergency recovery.
- **Rider sign-in does not change.**

### D-08 and D-09: hosting

P1 is the preferred candidate. **It does not authorise any purchase or deployment.** Before the owner approves the infrastructure, verify:

- current DigitalOcean Droplet and Managed PostgreSQL prices;
- enough CPU and memory for Laravel, PHP workers, the scheduler, Redis and background jobs;
- database backups, with a tested restore procedure;
- the persistent file-storage architecture;
- the expected costs for storage, bandwidth, email, monitoring and operations;
- latency from Kathmandu and Pokhara;
- compatibility with the current application and how it is deployed.

The owner must explicitly approve the final cost and budget before anything is purchased.

## 3. Implementation order

1. Update the decision and architecture documents (session 24, done).
2. Account deletion with the 30-day cooling-off, the contribution choices, and export.
3. Event and group-trip transfer, cancellation and deletion.
4. Moderation evidence retention and cleanup.
5. Contact-message retention for spam and closed messages.
6. Mandatory staff MFA with the 14-day grace period.
7. Full regression, authorisation, privacy, retention and browser tests.
8. Review P1 capacity and cost, then present a final infrastructure proposal for approval.
9. Pre-production readiness, only after the outstanding decisions and operational requirements are resolved.

## 4. What this record does not authorise

Each of these still needs its own authorisation:

- buying hosting, domains, email, monitoring or any other paid service;
- deploying to production or changing production infrastructure;
- connecting external services or creating external accounts;
- accessing or changing the remote Supabase;
- pushing repositories, creating commits, or changing remote branches;
- deleting real user content, or any destructive operation on a production database.

## 5. Still open (not approved)

| # | Open question | Interim handling in the designs |
|---|---|---|
| O1 | Retention and review of open contact messages | never deleted automatically |
| O2 | Who is eligible for an event or group-trip transfer, and how consent works | a proposal is in `ACCOUNT_DELETION_AND_EXPORT.md` §2.3; the owner confirms it before step 3 is built |
| O3 | Contributions that cannot safely be anonymised | the "keep" choices are only offered where the design can anonymise; the rider is warned that text and photos may still identify them; see §2.2 there |
| O4 | Final hosting capacity, monthly cost and budget | no purchase |
| O5 | Production email and error-monitoring providers | none connected |
| O6 | Production file-storage provider and configuration | the local disk stays in development only |
| O7 | Any legal or regulatory reason to keep data longer than the normal schedule | no exception is built; the contact "legal hold" from the earlier plan is deferred |
