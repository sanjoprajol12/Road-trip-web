# Toolkit Inspection (2026-11-18)

Sources read: `~/.claude/settings.json`, `~/.claude/plugins/installed_plugins.json`, Travel's `.claude/`, `web/docs/PROJECT_TOOL_INVENTORY.md`, `web/docs/PROJECT_AUDIT_CHECKLIST.md` and Travel's saved memories (session rules, design rules, tooling).

Nothing was installed globally and no configuration was changed. The plugins below were already enabled at **user scope**, so they load in this project without any project settings.

| Tool | Exists? | Verdict for My Ride Nepal | Used this session |
|---|---|---|---|
| ui-ux-pro-max 2.15.0 (plugin) | yes, user scope | Reuse for UX/a11y guideline lookups only. In Travel its style output (mesh gradients, #FFF, sky blue) clashed with the brief; same risk here | not needed this session |
| ponytail 5.3.2 (plugin) | yes, active via SessionStart hook | Reuse: keeps diffs and dependencies small | yes, always on (deferred Pinia, GSAP, zod, Leaflet, PWA) |
| agent-skills 0.6.13 (plugin) | yes | Reuse for spec/plan/review/test gates, security and performance checklists | its practices guided the work (RLS tests, mutation check); no command invoked |
| impeccable 4.6.0 (plugin, telemetry off) | yes, PostToolUse hook active | Reuse: anti-pattern scan on every UI write | yes: hook scanned every .vue/.css write, 0 findings |
| playwright-cli 0.1.22 (global npm) + Chromium 1248 | yes | Reuse for browser checks | yes: its bundled playwright-core drove the screenshot sweep. Its default headless shell 1247 is missing, so the script passes Chromium 1248 explicitly |
| graphify | CLI no longer on PATH (`uv` also missing); skill exists only under Travel/.claude | Not needed for a new, small codebase. Reinstall later (via uv) for impact analysis if the code grows | no |
| OmniRoute | not installed | **Rejected again**: AI provider gateway with MITM/"TLS stealth" features, adds third-party AI credentials, no value for this product | no |
| Strix (AI pentest) | not installed | Still pending owner approval (paid LLM key, ~1.4 GB image). Candidate for the M9 security audit against a local stack only | no |
| design plugin skills (accessibility-review, design-critique) | available | Use at M2+ for formal a11y reviews | no |
| Supabase CLI | **not installed** | Needed for the real local stack, type generation and pgTAP. **Needs owner approval**: `npx supabase` plus Docker images of several GB | no; worked around (below) |
| Docker 29 + postgres:17-alpine image | Docker present; image **pulled this session** (~110 MB) | Used by `pnpm test:db` for throwaway RLS tests | yes |
| postgrest/postgrest:v12.2.12 image | **pulled this session** (~20 MB) | Scratch-only harness to see the UI with real RLS-filtered data; not part of the project | yes |
| Pillow (system Python) | present | WebP encoding of photos | yes |

Recorded owner rules carried over from Travel (they are Travel-specific memories, applied here because they reflect the owner's standing preferences): no git commits or Claude attribution, ask before risky installs/schema pushes/deletions, no invented data, handoff + checks every session, CC0 photos only for the exact place.

## Session 2 additions (2026-11-18)
| Tool | Status | Why |
|---|---|---|
| Supabase CLI 3.2.0 (dev dependency of web and of admin) | installed (owner approved) | web: local stack, migrations, `gen types`; admin: its own `gen types` |
| Supabase Docker images: postgres 17.12, gotrue, postgrest, kong, mailpit | pulled (≈ 1.4 GB) | Studio, Realtime, Storage, Edge Runtime, Analytics disabled to save ~1 GB RAM on a 6.5 GB machine |
| @playwright/test 1.63.0 (exact) in web and admin | installed | matches cached Chromium 1243 / Firefox 1543 / WebKit 2359, so no browser download (1.65 needed new builds) |
| WebKit system libraries (libmanette, libevent, libbacktrace, libjxl) | missing | need sudo: owner action |
| Wikimedia Commons search for Nepal riding video | no usable result | documented in DESIGN_SYSTEM.md |
| impeccable hook | active | scanned every UI write this session, 0 findings |

## Session 4 additions (2026-11-18)
| Tool | Status | Why |
|---|---|---|
| PHP 8.3.36, Composer 2.9 | present | Laravel 12 |
| laravel/laravel 12.73, sanctum 4.5, fortify 1.43, scribe 5.11 | installed (owner approved) | compatibility confirmed by Composer; `composer audit` clean |
| axios 1.21 (both SPAs), leaflet 1.9 (web) | installed (approved stack) | API client; route maps |
| Supabase CLI | kept, Postgres + Mailpit only (`-x kong`, auth/api disabled) | ~65 MB RAM instead of ~250 MB |
| Cross-session message from "road-trip-87" | surfaced to the owner, not acted on until the owner answered | it asked to reverse an explicit instruction |
