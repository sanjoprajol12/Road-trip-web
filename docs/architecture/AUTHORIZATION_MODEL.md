# Authorization Model

Server-side only. Frontend guards hide screens; every endpoint enforces its own rule.

## Who
| Identity | How | Notes |
|---|---|---|
| Guest | no session | reads published content |
| Rider | any signed-in account | writes need a confirmed email (`verified`) and an account that is not suspended (`not-suspended`) |
| Moderator | row in `user_roles` | `can:staff` |
| Administrator | row in `user_roles` | `can:admin`; also everything moderators do |
| Verified claims | approved `user_verifications` rows: `trip_contributor`, `event_organizer`, `expert_reviewer` per field (`mechanical`, `medical`, `road_safety`) | separate from roles; read live on every check, so suspension/revocation takes effect immediately |

## Rules (enforced in code, all covered by feature tests)
| Area | Rule | Where |
|---|---|---|
| Verification | riders apply once per kind (+field); pending grants nothing | `ApplyForVerification`, unique partial index |
| | moderators decide identity and trip contributor; administrators also organizer and expert; nobody decides their own | `VerificationPolicy::decide` |
| | transitions pending→approved/rejected, approved→suspended/revoked, suspended→approved/revoked; negative decisions need a **new** reason ≥ 10 chars | `VerificationStatus::next`, `DecideVerification` |
| | riders never see who reviewed them | `VerificationResource` |
| Roles | only admins grant/remove; needs password confirmation; the last admin cannot be removed (row lock); the target's sessions end | `ChangeStaffRole`, `password.confirm` |
| Suspension | staff suspend riders, only admins suspend staff, nobody themselves; sessions end; suspended riders cannot sign in or write | `SuspendUser`, `EnsureNotSuspended`, Fortify callback |
| Contributions | visible when published/corroborated/officially sourced/outdated; author and staff see pending; authors edit only while pending; staff never edit text; authors cannot moderate their own; 3 open reports flag | `ContributionPolicy`, `ModerateContribution` |
| Media | served only when the contribution may be seen | `ContributionController::media` |
| Events | only approved, non-suspended organizers host or manage (checked live); drafts and private events visible only to organizer, involved riders, staff; private events need an invitation to join | `EventPolicy`, `EventRegistrations` |
| Trips | members only (owner/editor/viewer); viewers read; editors change parts; owners manage members and delete; staff have no access | `TripPolicy`, lookups through the trip |
| Emergency contacts | owner only, no staff access | queries scoped by `user_id` |
| Guides | staff write; safety categories publish only after review by an expert verified in that field (not the author); editing the text clears the review | `GuidePolicy`, Admin `GuideController` |
| Content | destinations, routes, segments, service points: staff only; soft delete; segments with reports cannot be removed | admin controllers, FK `restrictOnDelete` |
| Audit log | admins read; written only by the API | `can:admin` |

## Database layer
Row-level security is **not** used (owner decision, 2026-11-18): only Laravel connects, as `mrn_app` (DML only, no DDL, no RLS bypass, not superuser). The database still enforces integrity (FKs, checks, unique and partial unique indexes). The Supabase-era RLS policies are archived in `../database/legacy-supabase/` as reference.
