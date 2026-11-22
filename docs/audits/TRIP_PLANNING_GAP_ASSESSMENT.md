# Trip planning gap assessment (F4, F15), session 20

Written 2026-11-21, before any change. The product spec (§6 My Rides) only lists the topics, so the acceptance criteria below come from the session 20 brief. The evidence is the code and the tests as they were at the end of session 19.

**Abbreviations:** FT = Laravel feature test, E2E = browser test.

## Evidence

| ID | Acceptance criterion | Existing implementation | Existing tests | Missing evidence | Actual gap | Action |
|---|---|---|---|---|---|---|
| F4.1 | Add accommodation to a trip | Free-text stop with `overnight`. A published lodging service can be added from service lists (`AddToTrip`, `service_point_id`) | FT:Trip (stop with day and overnight); FT:SavedItems (service stop) | browser: overnight stop | none in the API; the trip form has no "overnight" in the browser test | test |
| F4.2 | Link accommodation to trip days | `trip_stops.day` 1–365 | FT:Trip day=1 | browser | **day and overnight cannot be changed after adding** (PATCH exists, no UI) | add edit to the stop row |
| F4.3 | Overnight stops | `overnight` boolean, moon icon | FT:Trip | browser | none | test |
| F4.4 | Arrival and departure details | not modelled | — | — | not supported. Adding times or dates would be a new feature; the stop `note` holds free text | document; not built |
| F4.5 | Reordering | `PUT stops/order` (session 19) | FT:TripRouteParts; E2E:trip-route-parts | — | none | — |
| F4.6 | Multiple days and overnight transitions | days are per stop only | none | — | **no per-day view**: the rider cannot see which nights have no place to stay, or that the list order and day order disagree | day-by-day summary (Phase 4) |
| F4.7 | Missing accommodation information | none | — | — | **no warning** for a night without an overnight stop | per-day warning |
| F4.8 | Update and remove accommodation stops | remove: yes; update: API only | FT:Trip (IDOR) | browser | **no edit UI** | as F4.2 |
| F4.9 | Mobile itinerary | `TripPage` list | E2E mobile (session 19) | — | none | screenshot check |
| F4.10 | Map where coordinates exist | destination, place, service and checkpoint markers | E2E:trip-places, trip-route-parts | — | **an unpublished destination shows no notice** (`linked_unavailable` ignores destinations) | fix and test |
| F15.1 | Owners and editors manage stops; viewers read | `TripPolicy` | FT:Trip, TripRouteParts; E2E viewer | — | none | — |
| F15.2 | Changes consistent for all members | one row per trip; every response is the full trip | none explicit | an editor's change seen by the viewer | none (last write wins, documented under F14) | test |
| F15.3 | Planned vs actual expenses | **not modelled**: every expense is "an estimate you enter" | FT:Trip total | — | **missing** | `trip_expenses.status` planned / spent |
| F15.4 | Expense categories | enum of 6 categories | E2E (fuel) | — | none | — |
| F15.5 | Totals only from available values; missing amounts and currency | `amount_npr` required; one total | FT:Trip total | — | **a cost that is not known yet cannot be recorded**; the currency is shown only as an "NPR" prefix | nullable amount for planned items (required when spent); totals with counts of items without an amount; "all amounts NPR" |
| F15.6 | Unauthorized budget changes refused | `TripPolicy::edit`; `paid_by` must be a member | FT:Trip | viewer PATCH/DELETE | none | test |
| F15.7 | Checklist: create, edit, done / not done | create, toggle; PATCH for label exists | FT:Trip (viewer refused) | browser toggle | **no way to edit a label in the UI**; no empty-section text; no progress | edit, empty state, "n of m done" |
| F15.8 | Checklist categories | 3 sections | — | — | none | — |
| F15.9 | Notes: add, edit, remove | `trips.notes` (10,000) in the details form; clearing it removes it | none | FT and browser | none in code; **no test at all** | test |
| F15.10 | Notes: permissions and privacy | viewers get disabled fields; API `edit` | FT:Trip (outsider PATCH 403) | viewer PATCH | none. Notes are shared with all members by design; per-member private notes are F10 | test |
| F15.11 | Validation and errors | Laravel validation; page shows `err` | partial | — | none | — |

## Phase 4 and 5 findings

- **Days:** `day` is optional per stop, and positions are independent of days. A summary can group by day without changing either.
  - Per-day totals use only route and stretch stops with recorded values; missing values are counted.
  - A day number maps to a date only when `starts_on` is set.
- **Map:** the trip map shows markers only. Stretch stops and whole-route stops have recorded tracks (`route_segments.path`) when staff imported them, but trip responses don't include them.
  - Drawing those tracks needs no new data and no provider, so it is technically supported.
  - Stretches without a track get no line, never a straight one.

## Not in scope (named, not built)

- Arrival and departure times.
- Bookings and availability.
- Budget targets and per-person splits (F6).
- Per-member private notes (F10).
- Payments.

## Outcome (end of session 20)

- **Built:**
  - Stop editing (F4.2, F4.8).
  - Day-by-day view with overnight and ordering warnings (F4.6, F4.7).
  - The withdrawn-destination notice (F4.10).
  - Planned and spent costs with unknown planned amounts (F15.3, F15.5).
  - Checklist editing, progress and empty states (F15.7).
  - A confirmation when notes are saved.
  - Recorded tracks on the trip map.
- **Tested only, nothing built:** F4.1, F4.3, F15.2, F15.6, F15.9, F15.10.
- **Not built (documented):** F4.4 arrival and departure times.
- F4 and F15 are now Complete, tested. Evidence: FT `TripPlanningTest` (6 tests), unit `tripDays.spec.ts` (5), E2E `trip-planning.spec.ts` (Chromium, Firefox, mobile).

