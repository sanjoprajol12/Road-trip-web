# Owner decision Q9: what happens to a rider's public contributions when they delete their account

**Status: decided on 2026-11-22. The owner chose option D, with three choices per contribution.** The full record is `OWNER_DECISIONS_2026-11-22.md`.

| Question | Owner decision |
|---|---|
| Q9 | **D:** for each eligible contribution, the rider chooses **Delete**, **Keep anonymously**, or **Keep anonymously and remove photos**. No choice is made for them. |
| Q9a: events | Transfer to another organiser; if that fails, cancel and notify the registered riders (D-02). |
| Q9b: group trips | Transfer to a member who consents; if that fails, delete the trip and notify the members (D-03). |
| Q9c: safety hold | At most 30 days, with evidence under restricted access (D-04). |
| Q9d: cooling-off | 30 days (D-01). |

The analysis below, prepared in session 23, is kept as the reasoning behind the options.

## Why this needs a decision

Today the database would **hard-delete** a rider's contributions with the account (`contributions.author_id` cascades). Their published road reports, experiences, photos, confirmations of other riders' reports and the reports they filed against content would all vanish. Other riders' trip notes and confirmations may depend on those reports.

On the other side, the rider asked to be forgotten, and their name, photos and words are personal data.

There is no technically correct answer. It is a product and privacy policy choice.

## What counts as a "public contribution"

| Data | Visible to | Currently on deletion |
|---|---|---|
| Road reports and experiences (`contributions`, status published, corroborated, officially sourced or outdated) | everyone | deleted (cascade) |
| Contribution photos (`contribution_media`, files on disk) | everyone (published) | rows deleted with the contribution; **files stay on disk (orphaned)** |
| Confirmations of other riders' reports (`contribution_confirmations`) | counts shown publicly | deleted (cascade); the corroboration counts of *other* riders' reports drop |
| Place suggestions (`places.submitted_by`) | the place is public once staff publish it | link set to null; the place stays |
| Place corrections (`place_corrections`) | staff | deleted (cascade) |
| Events organised (`events.organizer_id`) | public listing | **deleted (cascade), including other riders' registrations** |
| Event updates posted (`event_updates.author_id`) | event participants | author set to null |
| Pending or rejected contributions (never public) | the author and staff | deleted (cascade) |

## Options

### A. Delete everything the rider wrote

- Contributions, photos (rows **and files**), confirmations and corrections are removed.
- **For the rider:** the strongest erasure; simple to explain ("everything you posted is removed").
- **For other riders:** stretch reports disappear; the corroboration counts of other riders' reports go down; links from trips and saved items break (they already handle "no longer listed").
- **Moderation:** reports under investigation or with open safety reports vanish. A short hold (see the design, "retention exceptions") is needed so a rider cannot erase evidence of abuse by deleting the account.

### B. Keep published contributions, anonymised

- Published contributions stay. The author becomes "Former rider" (no name, username or profile link); free text stays as written.
- Unpublished items, confirmations and corrections are deleted.
- **For the rider:** their words stay visible. The text may still identify them (for example "I rode with my brother Suman"), and photos may show them or their motorcycle's number plate.
- **For other riders:** the road knowledge base keeps its history; corroboration stays intact.
- **Needs:** a nullable `author_id` (schema change from cascade to set null) plus an `author_deleted_at` marker, and a clear statement in the privacy policy *before* riders contribute.

### C. Keep published text anonymised, delete photos

- Like B, but every photo is removed (rows and files).
- **For the rider:** reduces the identification risk from photos (faces, plates, home surroundings), though the text can still identify.
- **For other riders:** the report text stays, so most of the safety value of road reports is in the text.
- **Needs:** the same schema change as B, plus file deletion.

### D. Ask the rider at deletion time (between A and B or C)

- The deletion screen offers "Remove everything I posted" or "Keep my published reports without my name", with a plain explanation of each.
- **For the rider:** the most control.
- **For other riders:** unpredictable knowledge loss.
- **Needs:** both code paths (more to build and test), and the choice recorded in the deletion audit.

## Related choices the owner must make at the same time

1. **Events the rider organises with other riders registered:**
   - (i) refuse deletion until the rider cancels or hands over the events, or
   - (ii) cancel them automatically and notify participants, or
   - (iii) delete them silently, as today. **(iii) is not recommended for any option.**
2. **Group trips the rider owns with other members:**
   - (i) refuse until ownership is handed over (ownership transfer does not exist yet), or
   - (ii) transfer to the longest-standing editor automatically, or
   - (iii) delete the trip for everyone, as today.
3. **Safety hold:** how long a deletion waits when the rider has open moderation cases (for example 30 days), and whether published contributions under an open safety report are kept for the investigation.
4. **Grace period:** immediate deletion, or a cooling-off period (for example 14 days) during which signing in cancels the request.

## The exact decision needed

> **Q9.** When a rider deletes their account, their published road reports and experiences are: **A** deleted / **B** kept without their name (photos kept) / **C** kept without their name, photos deleted / **D** the rider chooses between A and B (or C).
> **Q9a.** Events they organise with registered riders: refuse deletion / cancel and notify / delete.
> **Q9b.** Group trips they own with other members: refuse deletion / transfer ownership / delete.
> **Q9c.** Safety hold when moderation cases are open: ___ days (or none).
> **Q9d.** Cooling-off period before deletion runs: ___ days (or none).

The decision above is applied in `docs/architecture/ACCOUNT_DELETION_AND_EXPORT.md`.
