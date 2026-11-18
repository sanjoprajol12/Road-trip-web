# User Roles and Permissions

The full, current rule set (who may do what, and where it is enforced) is in [`../architecture/AUTHORIZATION_MODEL.md`](../architecture/AUTHORIZATION_MODEL.md). Summary for product discussions:

| Role / status | Can |
|---|---|
| Guest | browse published destinations, routes, experiences, public events, guides, rider profiles |
| Rider (signed in, email confirmed, not suspended) | edit own profile; plan private and group trips; keep private emergency contacts; contribute reports and experiences (published after review); report wrong information; apply for verification; join or request to join events; accept invitations |
| Verified trip contributor | the "verified trip contributor" label on their contributions |
| Verified expert (per field) | review safety guides in their field before staff publish them |
| Verified event organizer | host rides and gatherings (public or private, instant or approval joining, capacity and waitlist), invite riders, post updates, record attendance |
| Moderator | review identity and trip-contributor claims, moderate contributions, manage destinations, routes, services and guides, cancel events, suspend riders |
| Administrator | everything moderators do, plus organizer and expert decisions, staff roles (with password confirmation), suspending staff, the audit log |

Verification is claim-specific: one approval never implies another. Suspension or revocation takes effect on the next request. Staff cannot see riders' private trips or emergency contacts. Nobody decides their own claim or moderates their own contribution.

First administrator: `php artisan mrn:grant-admin <email>` on the server (the account must exist and be confirmed).
