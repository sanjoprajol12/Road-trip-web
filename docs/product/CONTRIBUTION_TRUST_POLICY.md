# Contribution Trust Policy

## Principles
1. Anyone registered may contribute; publication and trust labels are controlled.
2. Moderation approval means "passed review", never "proven true". The UI says so.
3. Every observation is dated (`observed_on`, required, not in the future) and separate from `submitted_at`.
4. Historical observations never appear as live. Age is shown in words ("5 months ago").
5. A rider's original text is never silently rewritten. Corrections are requested from the author; staff notes are separate fields; every moderation action is logged.
6. Verification is claim-specific (see `USER_ROLES_AND_PERMISSIONS.md`).
7. Safety-critical technical guidance (mechanical fixes, first aid, riding technique in hazards) needs an expert reviewer in the matching scope **and** a cited source before publication. A contributor's claimed experience is not enough.
8. No fabricated content. Development seed rows carry `is_dev_seed = true` and are labelled in the UI.

## Content statuses (`content_status`, for rider contributions)
| Status | Meaning | Public? |
|---|---|---|
| `pending_review` | submitted, not reviewed | author + staff only |
| `published` | passed moderation | yes |
| `community_corroborated` | matched by independent reports on the same segment within a window | yes, labelled |
| `officially_sourced` | backed by an official source (cited) | yes, labelled with source |
| `outdated` | superseded or too old for its type | yes, greyed, labelled |
| `rejected` | failed moderation (reason stored) | author + staff only |
| `flagged` | under re-review after a report | author + staff only |

## Freshness fields
contributor, observed_on, submitted_at, location (segment/destination/service), source (rider | official + citation), last_confirmed_on, review status, moderation history (`moderation_actions`).

## Staleness defaults (to confirm with owner)
Road condition: outdated after 90 days or after a newer report on the same segment. Fuel/ATM/connectivity: 180 days. Accommodation/mechanic: 365 days. Destination facts: show "last confirmed" always; warn after 365 days.

## Reports of incorrect information
Any user can report a contribution (`moderation_reports`). Three open reports move it to `flagged` automatically; staff decide.

## Repeat offenders
Moderators can suspend contribution rights (planned `profiles.suspended_until`), logged with reason.
