# Contact message retention: implementation plan (D7)

**Status:** periods approved on 2026-11-22: spam 30 days (D-05), closed 12 months (D-06), in `docs/product/OWNER_DECISIONS_2026-11-22.md`. **Open messages have no approved period** and are never deleted automatically. Not implemented yet. The development database holds 0 messages.

## Current state (inspected)

- **Table:** `contact_messages` holds `name`, `email`, `subject`, `body`, `status` (new, open, done, spam), `read_at`, `handled_by_staff_id`, and `ip_hash`. The IP is hashed with the app key; the raw address is never stored.
- **Intake:** public `POST contact`, rate-limited (`throttle:contact`).
- **Staff:** list and change status (`contact.view`); delete one message (`contact.manage`, audited as `contact.deleted`, with the message id only).
- **Retention:** none. Messages are kept forever unless a staff member deletes them one by one.
- **No account link:** deleting a rider account does not touch their contact messages (they are found by email only).

## Policy (approved)

| Message state | Keep for | Then |
|---|---|---|
| `spam` | 30 days after it was marked spam (D-05) | delete |
| `done` (closed) | 12 months after it was closed (D-06) | delete |
| `new` or `open` | **no approved period** | never deleted automatically; a staff list shows those older than 60 days (review aid only, deletes nothing) |

**Legal hold:** deferred. Any reason to keep a message longer is a legal or regulatory exception, which the owner has not decided (open O7). Until then, no hold is built and the purge has no exceptions.

## Design

### Schema (additive)

`contact_messages` gains `closed_at`: set when the status becomes `done` or `spam`, and cleared if the message is reopened. The migration backfills it from `updated_at` for existing closed messages, so nothing becomes older than it is.

### Periods and switch

- **Periods:** constants in `config/privacy.php` (`contact_spam_days = 30`, `contact_done_months = 12`). They are owner policy, so they are not staff-editable settings.
- **Switch:** `CONTACT_PURGE_ENABLED`, an environment variable, defaults to `false`.
  - The code implements the approved policy, but deleting stored messages in a real environment is a separate authorisation (record §4).
  - The owner turns it on per environment. Tests turn it on.

### Purge job

`php artisan contact:purge` runs daily from the scheduler.

- **Dry-run:** `--dry-run` prints counts per rule. It is also run automatically, with results shown on the staff contact screen ("12 messages will be removed on …").
- **Delete:** deletes in batches of 500 the messages past their period, and only when `CONTACT_PURGE_ENABLED` is true. Each batch is its own transaction, so a crash mid-run is completed safely by the next run.
- **Audit:** one `contact.purged` entry per run with the counts per rule. Message ids are not listed, so the audit log does not become a copy of the data.

### Staff actions

- **Rider request** ("delete my messages"): staff search by email and delete all of them, audited as `contact.deleted_on_request` with a count. Not linked to account deletion automatically (no account link; matching by email could remove someone else's messages).

### Archival

None. Contact messages are not business records worth archiving. If the owner needs them for accounting or legal reasons, that is a retention exception (open O7).

### Backups

Purged rows stay in provider backups until those expire; the privacy policy states the backup window.

### Privacy policy text

The text states spam 30 days, closed 12 months, and that open messages are kept while they are being handled.

## Tests

- **Purge rules:**
  - each rule at its boundary: spam on day 29 and day 31; closed at 12 months minus and plus one day;
  - `new` and `open` are never purged, however old;
  - with the purge disabled, nothing is deleted; dry-run deletes nothing;
  - a second run straight after is a no-op.
- **Status timestamps:** `closed_at` is set and cleared on status changes.
- **Audit content:** the purge audit holds counts only.
- **Staff contact screen (E2E):** the "will be removed" notice.

## Effort

About half a session.
