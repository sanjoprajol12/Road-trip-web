# Authorization Model

Server-side only. Frontend guards hide screens; every endpoint enforces its own rule. Current as of 2026-11-18 (session 10: separate staff accounts, see `AUTHENTICATION_FLOW.md`).

## Who
| Identity | How | Notes |
|---|---|---|
| Guest | no session | reads published content |
| Rider | a signed-in rider account (`users`, guard `web`) | never holds a role or permission; writes need a confirmed email (`verified`) and an account that is neither suspended nor deactivated (`not-suspended`) |
| Staff | a signed-in staff account (`staff_accounts`, guard `staff`) with at least one row in `staff_account_roles`, not deactivated | `auth:staff` + `can:staff` open the staff API group; each route then needs a permission. Gates accept only `StaffAccount`. `user_roles` is kept as history (no longer read) |
| Permissions | keys in `config/permissions.php` (the registry), synced to `permissions` by `php artisan mrn:permissions:sync` | one gate per key (`can:<key>`); policies call `hasPermission()` |
| Roles | `roles` (slug, name, is_system) + `permission_role`; a person may hold several, rights add up | built-in `admin` (always every permission, including future ones) and `moderator` (the defaults in the registry); staff create more in the staff app |
| Verified claims | approved `user_verifications` rows: `trip_contributor`, `event_organizer`, `expert_reviewer` per field (`mechanical`, `medical`, `road_safety`), `identity` | separate from roles and never grant a permission (tested); read live on every check |

## Rules (enforced in code, all covered by feature tests)
| Area | Rule | Where |
|---|---|---|
| Every route | classified as public, rider account, rider write, staff account or staff; rider routes carry `auth:sanctum` and never `auth:staff`, staff routes carry `auth:staff` and never `auth:sanctum` and name a registry permission; privilege changes also need `password.confirm`; riders get 401 on every staff route and staff 401 on every rider route | `tests/Feature/RouteAuthorizationTest.php` (fails on any unclassified or unguarded route) |
| Roles | only `roles.manage`; nobody can create, widen or narrow a role around a permission they lack; `admin` permissions are fixed; built-in roles cannot be deleted; a role in use cannot be deleted | `Admin\RoleController` |
| Giving roles | `users.roles.manage` + password confirmation; you can give or take only roles whose permissions you hold; only administrators give or take `admin`; the last **active** administrator keeps the role (row lock); the target's sessions end | `ChangeStaffRole` |
| Staff invitations | `users.invite` + password confirmation; roles limited as above; token stored as a hash, 72 h, single use, replaced by a newer invite; accepting creates a confirmed account, or needs the existing account's password | `Admin\StaffInvitationController`, `V1\StaffInvitationController` |
| Account controls | suspension (`users.suspend`), deactivation (`users.activate`, reason, sessions end), reset link (`users.password_reset`), sign out everywhere (`users.sessions.revoke`); never on yourself; staff targets also need `users.manage_staff`; deactivating the last active administrator is refused | `SuspendUser`, `Admin\UserController` |
| Verification | riders apply once per kind (+field); pending grants nothing | `ApplyForVerification`, unique partial index |
| | `verification.decide` for identity and trip contributor, `verification.decide_sensitive` for organizer and expert; nobody decides their own | `VerificationPolicy::decide` |
| | transitions pending→approved/rejected, approved→suspended/revoked, suspended→approved/revoked; negative decisions need a **new** reason ≥ 10 chars | `VerificationStatus::next`, `DecideVerification` |
| | riders never see who reviewed them | `VerificationResource` |
| Contributions | visible when published/corroborated/officially sourced/outdated; author and `contributions.moderate` see pending; authors edit only while pending (edits audited); staff never edit text; authors cannot moderate their own; 3 open reports flag | `ContributionPolicy`, `ModerateContribution` |
| Media | served only when the contribution may be seen | `ContributionController::media` |
| Events | only approved, non-suspended organizers host or manage (checked live); drafts and private events visible only to organizer, involved riders and `events.view`; private events need an invitation to join; `events.cancel` cancels any event | `EventPolicy`, `EventRegistrations` |
| Trips | members only (owner/editor/viewer); viewers read; editors change parts; owners manage members and delete; staff have no access | `TripPolicy`, lookups through the trip |
| Emergency contacts | owner only, no staff access | queries scoped by `user_id` |
| Guides | `guides.manage` writes, `guides.publish` publishes; safety categories publish only after review by an expert verified in that field (not the author); editing the text clears the review | `GuidePolicy`, Admin `GuideController` |
| Content | destinations, routes/segments, service points: `content.*.manage`; soft delete; segments with reports cannot be removed | admin controllers, FK `restrictOnDelete` |
| Audit log | `audit.view`; written only by the API; the app database role cannot change rows | `Audit::record` |

## Database layer
Row-level security is **not** used (owner decision): only Laravel connects, as `mrn_app` (DML only, no DDL, no RLS bypass, not superuser). The database still enforces integrity (FKs, checks, unique and partial unique indexes; `user_roles.role` references `roles.slug`, on delete restrict). The Supabase-era RLS policies are archived in `../database/legacy-supabase/` as reference.

## Adding a permission
1. Add the key to `config/permissions.php` (and to the moderator defaults if moderators should have it).
2. Use it on the route (`->middleware('can:<key>')`) or in a policy (`hasPermission('<key>')`).
3. Run `php artisan mrn:permissions:sync` (new installs get it from the migration). `RouteAuthorizationTest` fails until every staff route names a registry key.

## Session 13 permissions
`cms.pages.manage`, `cms.faqs.manage`, `cms.blog.manage` (posts, categories, tags, authors), `cms.team.manage`, `cms.promotions.manage` (popups, banners): drafts of that module. `cms.content.publish`: needed in addition to make content public, schedule, archive, or edit content that is already public. `contact.view` (read the inbox, including sender emails), `contact.manage` (status, delete). `settings.mail.manage`, `settings.maps.manage`, `settings.security.manage`, `settings.trust.manage`. Contribution pauses use the existing `users.suspend`. The CMS entry routes are registered per type, each behind its type's permission (route-matrix test), and the controller checks again.
- Trips: a rider becomes a member only by accepting an invitation; before that they see only the trip title, the inviter's display name and the role. Someone else's invitation answers 404.
- Contribution pauses never block reading, trips, saved items or events; they block submitting, editing, photos, reports, confirmations and corrections (`can-contribute` middleware).
