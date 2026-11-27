# Design System

Status: **approved** by the owner on 2026-11-27 (Q-22), with one change: a plain white page background and neutral secondary surfaces (applied in §3). Both React apps implement these tokens in their own `src/styles/tokens.css` (copied, not shared: TD-01).

## 1. Who we design for

- **A rider planning at home** (laptop or phone): wants to know what was reported about a stretch of road, by whom and when, and whether it is still trustworthy.
- **A rider on the road** (phone, bright sun, gloves, poor signal): needs large targets, high contrast, short pages, and works offline later (F19).
- **A contributor after a ride:** wants to file a report in under two minutes.
- **Staff:** work through queues and tables for hours; need density, keyboard use and clear consequences before decisions.

## 2. Direction: "the road logbook"

Information first, and **dates everywhere**. The product's promise is honest, dated knowledge, so the design makes freshness, sources and estimates visible instead of hiding them in small print. The recurring visual motif is the **route line with checkpoint nodes**: a thin line with filled circles, used in route diagrams, itineraries, the homepage, and the moderation history timeline. It comes from the product itself, not from decoration.

The earlier implementation's spirit is kept (real photographs, signage-like headlines, one brand colour), but on a **clean white page** with cool neutral greys, so the product reads modern and precise rather than "vintage journal". Character comes from the condensed signage type, the crimson route line and real photography, not from a tinted background.

### Principles
1. **Show the real product.** Real routes, real dates, real photos of the place shown. Empty states explain what is missing; they never fill the gap with stock imagery.
2. **Age is always visible.** Every observation shows its date and its age in words ("observed 5 months ago"). Nothing is ever styled as "live".
3. **Estimates look like estimates.** A "≈" prefix and an "estimate" label. Never the same style as recorded values.
4. **One primary action per view.** Crimson is reserved for it.
5. **Danger never relies on colour alone.** Always an icon plus words.
6. **Density where it helps.** Staff tables and route segment lists are dense; reading pages breathe.

## 3. Colour tokens

Contrast ratios were measured on 2026-11-27 (WCAG 2.x formula). AA needs 4.5:1 for normal text and 3:1 for large text and UI boundaries.

Owner decision 2026-11-27: the primary page background is **plain white**, with subtle **neutral** (not warm) surfaces for secondary sections. All greys are cool neutrals so they do not read as cream or beige next to white.

| Token | Value | Use | Contrast (white / subtle / sunken) |
|---|---|---|---|
| `--page` | `#ffffff` | primary page background, cards, inputs | — |
| `--surface-subtle` | `#f5f6f7` | secondary sections (alternating bands, the information aside, the admin content area) | — |
| `--surface-sunken` | `#eceef0` | table headers, callout boxes, skeleton blocks, the selected tab track | — |
| `--line` | `#e1e4e8` | dividers, table rules, card borders | decorative only |
| `--line-strong` | `#7b8189` | input borders, control outlines | 3.93 / 3.63 / 3.38 (UI ≥ 3 ✓) |
| `--ink` | `#16181b` | text | 17.79 / 16.44 / 15.29 |
| `--muted` | `#575c63` | secondary text, metadata | 6.74 / 6.23 / 5.79 |
| `--night` | `#121417` | photo hero overlay, footer, freshness band, admin sidebar | — |
| `--night-text` | `#ecedef` | text on night | 15.75 on night |
| `--night-muted` | `#a4a9b0` | secondary text on night | 7.80 on night |
| `--crimson` | `#a3202e` | **the brand colour**: primary buttons, route nodes, focus ring, active navigation marker | 7.49 / 6.92 / 6.44; white text on it 7.49 |
| `--crimson-deep` | `#861a26` | primary button hover/active, crimson links | 9.59 / 8.86 / 8.25 |
| `--crimson-wash` | `#f8e8e7` | selected rows, active filter background | — |
| `--marigold` | `#e2a32c` | the one accent: small labels and focus ring **on night only** | 8.35 on night; fails on white, so never used there |
| `--success` | `#2f6b3d` | confirmed / corroborated | 6.38 / 5.90 / 5.49 |
| `--warning` | `#8a5a00` | ageing / needs attention | 5.93 / 5.48 / 5.10 |
| `--danger` | `#b42318` | dangerous report, destructive action, errors | 6.57 / 6.08 / 5.65 |
| `--info` | `#2a5b8c` | officially sourced, informational notices | 7.06 / 6.53 / 6.07 |

Every text token passes AA (≥ 4.5:1) on all three light backgrounds; measured with the WCAG 2.x formula on 2026-11-27.

Crimson echoes the red of Nepal's flag and of Nepal's red private-vehicle number plates. Because danger is close in hue, danger always has the `triangle-alert` icon and a word.

**No dark mode at first** (not a requirement; it can be added later through the tokens). Night surfaces are always dark. Forced-colours mode is respected: borders stay visible, and icons use `currentColor`.

## 4. Typography

| Role | Family | Use |
|---|---|---|
| UI and display | **Barlow** (sans) and **Barlow Condensed** (display) | Barlow was drawn from the look of highway signage, which suits a road product. Condensed for headlines and big numbers, regular Barlow for UI |
| Long reading | **Source Serif 4** | rider trip reports, guides, blog posts: long text where a serif reads calmer |

Both are under the SIL Open Font License and self-hosted through Fontsource (TD-17). No other families. The admin app uses Barlow only.

| Step | Size / line height | Use |
|---|---|---|
| `display` | clamp(2.5rem, 6vw, 4.5rem) / 0.95, Barlow Condensed 700, uppercase | page titles on public pages |
| `h1` | 2rem / 1.15, Barlow Condensed 700 | admin page titles, detail headers |
| `h2` | 1.5rem / 1.2, Barlow 600 | sections |
| `h3` | 1.125rem / 1.3, Barlow 600 | sub-sections, card titles |
| `body` | 1rem / 1.55, Barlow 400 | UI text (never below 16px on inputs, so iOS does not zoom) |
| `reading` | 1.125rem / 1.65, Source Serif 4 | long text, max 68ch |
| `small` | 0.875rem / 1.45 | help text, table cells in admin |
| `label` | 0.75rem / 1.3, 600, uppercase, letter-spacing 0.06em | metadata: province, dates, status |
| numbers | `font-variant-numeric: tabular-nums` | distances, times, money, tables |

One `h1` per page. Italic is used only for quoted rider text and citations. Monospace is used only for codes (MFA recovery codes, request ids).

## 5. Spacing, layout and shape

- **Spacing scale (4px base):** 4, 8, 12, 16, 24, 32, 48, 64, 96. Nothing in between.
- **Containers:** content max 78rem; reading max 68ch; 16px side gutters below 640px, 24px above, 32px from 1024px.
- **Breakpoints:** `sm` 640, `md` 768, `lg` 1024, `xl` 1280. Designed mobile-first from 360px wide, with no horizontal scroll at any width.
- **Radius:** 2px for inputs and buttons, 4px for panels and photos. No pill shapes except the status tags.
- **Shadows (minimal):** content on the page has none; structure comes from `--line` borders and the subtle/sunken surfaces. Only elements that float above the page get one shadow token, so they separate from the white page: `--shadow-overlay: 0 8px 24px rgb(18 20 23 / 0.12), 0 1px 2px rgb(18 20 23 / 0.08)` for dialogs, popovers, dropdown menus and toasts. The sticky header gets a 1px `--line` bottom border once the page scrolls, no shadow.
- **Borders:** 1px `line` for structure; 1px `line-strong` for interactive controls.

## 6. Iconography

`lucide-react` only (Q-22): 20px in UI, 16px inline with small text, stroke 1.75, `currentColor`. Icon-only buttons always have an accessible name. **No emojis in the UI.** Product-specific symbols (route node, checkpoint) are simple inline SVG in the same stroke style.

## 7. Components (built when a screen first needs them)

| Component | Rules |
|---|---|
| **Button** | variants: `primary` (crimson, one per view), `secondary` (outlined ink), `quiet` (text), `danger` (outlined danger; filled only in the final confirmation). Height 44px (36px `compact` in admin tables). A visible loading state disables the button and keeps its width. Never disabled without a visible reason nearby |
| **Field** | label above (always visible, never placeholder-only), input 44px, hint below, error below with an icon; `aria-invalid`, and `aria-describedby` pointing at the hint and error. Inputs sized to their content (a date field is not full width) |
| **Form** | validates on blur and on submit; on a failed submit, an error summary at the top with links that focus each field; the server's field errors are mapped onto the same fields |
| **Select / Combobox** | native `<select>` for short fixed lists; a searchable combobox (Radix Popover) for destinations, routes and riders |
| **Table (admin)** | plain ruled rows, a sticky header, sortable headers as buttons with `aria-sort`, row actions in a menu, a filter bar above, pagination below showing "21–40 of 134". On phones a table becomes stacked rows |
| **Dialog** | Radix Dialog: focus trapped and returned to the trigger, `Esc` closes. Destructive confirmations name the item and the consequence ("Cancel 'Mustang loop'? 14 riders will be notified."). Typing the name is required only for irreversible staff actions |
| **Toast** | `aria-live="polite"`; for confirmations of completed actions only, never for errors that need action (those stay inline); auto-dismiss after 5 s, paused on hover or focus |
| **Notice bar** | site-wide CMS notices; dismissible; does not shift the layout after load |
| **StatusPanel** | the single pattern for loading (skeleton matching the layout), empty (what is missing + the next action), and error (what failed + Retry + the request id in small text) |
| **Skeleton** | blocks shaped like the final content; shown only after 200 ms, so fast responses do not flash |
| **FreshnessTag** | `observed 5 months ago · 12 Jun 2026`, with states: fresh (no colour), ageing (warning + `clock-alert`), outdated (muted, struck label "Outdated" + `history`), never confirmed (muted + "Never confirmed"). The full date is always in a `<time>` element |
| **TrustTag** | Published (no tag: it is the default) · Community corroborated (success + `users`) · Officially sourced (info + `landmark` + source link) · Flagged / Dangerous (danger + `triangle-alert`). Tooltip-free: the label says it all |
| **EstimateValue** | `≈ 3 h 40 min` + the label "estimate", with the source on hover or focus and in the text for screen readers |
| **RouteLine** | vertical list of checkpoints joined by a line; nodes are crimson circles; each segment row shows distance, time (recorded or estimate) and the latest report with its age |
| **Map** | Leaflet with the tile attribution always visible; keyboard-reachable markers with a list view alternative below every map (a map is never the only way to reach content) |
| **Photo** | `srcset` 640/1280, fixed aspect ratio to avoid layout shift, `loading="lazy"` below the fold, credit line under it |
| **Pagination** | previous/next + page numbers; the page is in the URL |

Components are created only when a real screen repeats a pattern. No component library is built ahead of the screens.

## 8. States every data view must design

Loading (skeleton) · empty (the first-use explanation and the action) · empty after filtering (show the filters and "Clear filters") · error with retry · offline (the browser reports offline: a banner, and cached data marked with its sync time) · permission denied (admin: "You need *X* to do this", never a blank screen) · not found (with a link back) · partial data (a destination without photos shows a typographic plate saying there is no photo yet).

## 9. Motion

Only `opacity` and `transform` animate. Page transitions 150 ms fade; dialogs 150 ms fade and scale from 0.98; the route line draws in once on the homepage (transform). Hover changes colour only. `prefers-reduced-motion: reduce` removes all motion, and content is visible without JavaScript-driven reveals. No autoplaying sliders under reduced motion; sliders always have a pause control.

## 10. Accessibility (WCAG 2.2 AA)

- Contrast per §3. Focus ring: 2px crimson outline with a 2px offset (marigold on night), never removed.
- Targets ≥ 44×44px on touch layouts (WCAG 2.5.8 minimum is 24px; we use 44 for gloved hands).
- Full keyboard use; skip link to main content; landmarks (`header`, `nav`, `main`, `footer`); one `h1`; headings in order.
- Forms: visible labels, errors in text, error summary with focus management, no time limits except security ones (which warn first).
- Status messages through `aria-live`. Dates in `<time datetime>`.
- Maps always have a list alternative. Charts (admin) always have a table alternative.
- Automated checks with axe in component and E2E tests; a manual keyboard and screen-reader pass (NVDA + Firefox, VoiceOver + Safari) before each phase ends.

## 11. Public site vs admin

| | Public site | Admin |
|---|---|---|
| Feel | editorial, photo-led, generous | restrained, dense, tabular |
| Layout | full-bleed sections; detail pages with a main column and an information aside (freshness, sources, related) | dark `night` sidebar with permission-filtered navigation, top bar with the account menu, content in ruled tables and forms |
| Type | Barlow Condensed display + Barlow + Source Serif 4 | Barlow only |
| Imagery | real photos of the place shown | none, except media previews |
| Crimson | primary action, route nodes | primary action, history timeline nodes |

## 12. Content rules

- **No fabricated content:** no fake testimonials, riders, reviews, prices, distances or photos. Development seed rows show a visible "Development sample" tag.
- Photos: CC0/public domain or owner-supplied, only of the place they illustrate, credited.
- Writing: plain English, short sentences, verbs on buttons ("Submit report", not "OK"). Error messages say what happened and what to do next.
- Safety pages carry "My Ride Nepal is not an emergency service" with the official emergency numbers only when they are sourced (E10, E14).

## 13. Banned

Gradients (except a legibility scrim on photos), glassmorphism and backdrop blur, shadows on in-page content (only floating overlays get `--shadow-overlay`), cream or beige page backgrounds, emojis, rainbow palettes, decorative blobs, stock "feature trio" card grids, decorative badges, random italic or monospace text, spinners as decoration (only for real loading under 200 ms, otherwise skeletons), and placeholder content presented as real.
