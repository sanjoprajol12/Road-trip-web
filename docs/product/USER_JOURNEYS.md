# User Journeys

Each journey lists the happy path, then failure states that must be designed.

## J1 Guest discovers a destination (Built, M1)
Home → Explore → search/filter → destination detail (summary, freshness block, dev-seed warning when applicable).
Failure states: loading, database unreachable (retry), nothing published, no search match (clear), unknown slug (not-found with link back).

## J2 Rider creates an account (Built in UI, M1; live auth untested)
Sign up (name, email, password ≥ 8) → "check your email" → confirmation link → `/account`.
Failure: invalid fields inline; existing email shown by Supabase's message; database not configured.

## J3 Rider edits profile (Built, M1)
Account → edit display name / username / home city / bio → Saved. Username taken → message. RLS prevents editing anyone else.

## J4 Rider forgets password (Built in UI, M1)
Forgot password → same message whether or not the address exists → email link → choose new password. Expired link → request a new one.

## J5 Rider files a road report (Designed, M3)
Route → segment → "Report this stretch": observed date (required, not in the future), surface condition, note, optional photo → pending review → rider sees status in "My contributions".

## J6 Rider plans a personal trip (Designed, M4)
My Rides → New trip → destination + dates → route → stops → checklist → budget. Private by default.

## J7 Group trip (Designed, M4)
Owner invites registered riders by username → members accept → editors change itinerary, viewers read. Leaving or removal revokes access at once.

## J8 Organizer application (Schema built, UI Designed, M2)
Rider → "Host rides" → statement (20–2000 chars) → pending. Admin approves/rejects with note. Suspension or revocation immediately removes hosting rights (verified by RLS test).

## J9 Organizer hosts an event (Designed, M5)
See `EVENT_SYSTEM.md`.

## J10 Moderator reviews a contribution (Designed, M3)
Admin app → queue → open item with photos and trip details → approve / reject / request correction / flag dangerous → action logged; original text never rewritten.

## J11 Staff signs in to admin (Built, M1)
Admin sign-in → role check (RLS-backed) → Overview. Rider account → "No staff access". Signed out → redirected to sign-in.
