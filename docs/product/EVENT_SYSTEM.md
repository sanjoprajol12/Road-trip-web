# Event System (designed, milestone M5)

## Who can host
Only users with an **approved `event_organizer` verification** (`public.has_verification('event_organizer')`). Checked in RLS on every insert/update, so suspension or revocation stops hosting immediately. Existing events of a suspended organizer: become read-only to the organizer; staff decide whether to cancel.

## Categories
- `ride`: group ride, weekend ride, long-distance expedition, multi-day trip, adventure ride.
- `gathering`: meetup, workshop, maintenance session, safety-awareness session, photography gathering, celebration, knowledge sharing.

## Event fields
title, category, subtype, description, start/end (timestamptz, Asia/Kathmandu display), meeting point, optional linked routes (`event_routes`), visibility (`public` | `private`), join method (`instant` | `approval`), capacity (nullable = unlimited), waitlist enabled, status.

## Event statuses
`draft → published → (full) → completed`; `published|full → postponed → published`; any non-completed → `cancelled`. `full` is derived when approved participants reach capacity and cleared when a place frees up.

## Participant statuses
`invited, pending, approved, declined, waitlisted, cancelled, attended, did_not_attend`.

| From | Action | To | Who |
|---|---|---|---|
| none | join, instant, place free | approved | rider |
| none | join, instant, full, waitlist on | waitlisted | rider |
| none | request, approval method | pending | rider |
| pending | approve / decline | approved / declined | organizer |
| none | invite | invited | organizer |
| invited | accept / decline | approved (or waitlisted if full) / declined | invitee |
| approved, pending, waitlisted | cancel | cancelled | participant |
| cancelled approved place | auto | first waitlisted → approved | database function |
| approved | after event | attended / did_not_attend | organizer |

Capacity is enforced in a `security definer` function with a row lock on the event (`select … for update`), so two riders cannot take the last place.

## Privacy
Private events: title, details, meeting point and participant list visible only to the organizer, invited/registered participants and staff. Public events: participant **count** public; participant list visible to participants and organizer only.

## Updates
`event_updates` (organizer posts: update, postponement, cancellation notice), notifying all non-cancelled participants. Completed events may get a summary and photos (`experience_media`-style storage, same upload rules).

## Tables
`events`, `event_routes`, `event_registrations` (one row per user per event, holds invited/pending/... status), `event_updates`. Invitations are registrations in `invited` status, not a separate table, so one row tells the whole story of a participant.
