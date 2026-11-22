# Infrastructure options for pre-production (session 23)

**Status (updated 2026-11-22, session 24):** the owner chose **P1 as the hosting candidate** (D-08), subject to a final cost and capacity review. **No budget is approved** (D-09). Nothing is purchased, connected or deployed, and nothing may be until the owner approves the final cost.

**Before the final proposal, verify** (record §2, D-08):

- current DigitalOcean Droplet and Managed PostgreSQL prices, from the official pages;
- enough CPU and memory for Laravel, PHP workers, the scheduler, Redis and background jobs (the 2 GB Droplet below is unverified for this);
- backups, with a tested restore;
- persistent file storage (D1, still open);
- storage, bandwidth, email, monitoring and operations costs;
- latency from Kathmandu and Pokhara;
- compatibility with the current deployment (container and Vercel config).

Still open: email and monitoring providers (O5) and the storage provider (O6).

**Prices:** collected on 2026-11-22 from recent third-party pricing summaries (links in the session 23 report). They are approximate and change often; **verify every figure on the provider's own pricing page before deciding.** All prices are in USD, excluding tax.

## Constraints that shape every option

1. **Payment from Nepal.**
   - Recent guides say a personal "dollar card" is limited to about **USD 500 per fiscal year** for all online payments. That is roughly **USD 41/month for every subscription combined**.
   - IT businesses reportedly can get prepaid cards up to USD 3,000/year.
   - **Confirm with the bank and the Nepal Rastra Bank unified circular.**
   - Any plan above about $40/month likely needs a business card or a foreign-earnings account.
2. **Latency.**
   - There is no major cloud region in Nepal. The nearest are India (Bangalore, Mumbai) and Singapore.
   - No latency numbers are given here: **measure from Kathmandu and Pokhara** (for example with `curl -w` against each provider's test endpoint) before choosing.
3. **App needs:**
   - PHP 8.3 or 8.4 with GD, `pdo_pgsql` and intl.
   - PostgreSQL 17 (no PostGIS yet, D3).
   - Two static SPAs (public site and staff portal).
   - Session, cache and queue tables in Postgres.
   - A scheduler for the planned account-deletion and contact-purge jobs.
   - Durable private storage for photos.
4. **Existing project rules:** remote Supabase stays out of scope, and every paid service needs owner approval.

## Component options

### Static frontends (public site, staff portal)

| Option | Price | Laravel / Postgres | Persistence | Notes, limits, lock-in |
|---|---|---|---|---|
| **Cloudflare Pages** | free (500 builds/month, 20,000 files) | n/a (static) | n/a | Commercial use reportedly allowed on free; global CDN; low lock-in (static files). `vercel.json` rewrites and headers would move to `_redirects` and `_headers` |
| **Vercel** | Hobby is free but **non-commercial only**; Pro is $20/seat/month | n/a | n/a | Already configured (`vercel.json`). Commercial use needs Pro. Low lock-in |
| Netlify | free tier; paid per seat | n/a | n/a | Similar to Pages (pricing not re-checked this session) |
| Same VPS as the API (nginx) | included | n/a | n/a | One fewer vendor; no CDN unless Cloudflare (free) is placed in front |

### Laravel API

| Option | Price | Laravel | Postgres | Disk persistence | Backups | You maintain | Lock-in | Limits |
|---|---|---|---|---|---|---|---|---|
| **Laravel Cloud** | Starter $5/month + usage (small Flex compute about $4/month with hibernation); Growth $20 + usage | first-party | its own serverless Postgres (see below) or external | ephemeral; use its object storage | through its Postgres | app only | medium (platform features) | Starter: 1 replica and Flex compute only; hibernation adds cold starts; region list to check (Asia) |
| **VPS (DigitalOcean Droplet)**, optionally with Laravel Forge | $6 (1 GB) or $12 (2 GB)/month; backups +20–30%; Forge Hobby $12/month (optional) | full control | on the VPS or managed | **persistent disk** | droplet snapshots + your `pg_dump` | OS patches, PHP, nginx, TLS (Forge automates much) | low | single machine (no failover); region BLR1 (Bangalore) listed, pricing reportedly flat |
| **Hetzner Cloud Singapore** | CPX12 about €15.49/month after the 2026 increases; only 0.5 TB traffic, €7.40/TB over | full control | on the VPS | persistent | snapshots + your dumps | everything | low | traffic overage; plan availability varies |
| **Vercel container** (current `Dockerfile.vercel`) | Pro $20/seat + usage | works (FrankenPHP) | external only | **ephemeral** (uploads lost: see the upload safety design) | external | app | medium | no worker or scheduler (Vercel Cron would need an HTTP endpoint); cold starts; commercial use needs Pro |

### PostgreSQL

| Option | Price | Region near Nepal | Backups and restore | Notes |
|---|---|---|---|---|
| **DigitalOcean Managed Postgres** | about $15.15/month (1 GB / 1 vCPU); a standby doubles it | BLR1 or SGP1 (listed) | daily backups, 7-day restore (PITR window to confirm) | simple, predictable; no scale-to-zero |
| **Neon** | Launch: usage-based (about $0.106 per CU-hour while awake, storage about $0.35/GB-month); free tier with scale-to-zero | AWS Singapore (no India region) | point-in-time restore built in: 1 day by default, up to 7 days on Launch; history billed per GB | cheap while idle; cold start after scale-to-zero; branching is handy for previews |
| **Laravel Cloud Postgres** | about $0.106 per compute-hour while awake (≈ $76/month if never asleep) + $0.50/GB-month; backups billed separately | region to check | backups up to 30 days | only sensible with Laravel Cloud compute |
| Postgres on the API VPS | included | same as the VPS | **your own:** nightly `pg_dump` to object storage, plus a restore drill | cheapest; you own every failure mode |
| Supabase (hosted) | — | — | — | **Out of scope by owner rule** (no remote Supabase) |

### Object storage (photos, exports)

| Option | Price | Notes |
|---|---|---|
| **Cloudflare R2** | 10 GB free; then about $0.015/GB-month; **no egress fees**; Class A $4.50 per million | S3-compatible (Laravel `s3` driver, the `league/flysystem-aws-s3-v3` package needs approval); low lock-in |
| **Backblaze B2** | first 10 GB free; about $6.95/TB-month; egress free up to 3× stored | S3-compatible; also good as the database-backup target |
| DigitalOcean Spaces | about $5/month base (bundle to confirm) | same vendor as a DO VPS or database |
| Laravel Cloud object storage | about $0.02/GB-month + $0.10/GB transfer | only with Laravel Cloud |

### Email (confirmations, resets, invitations)

| Option | Price | Notes |
|---|---|---|
| **Amazon SES** | about $0.10 per 1,000 (classic rate); new 2026 plans reportedly from $0.16 per 1,000; Mumbai region exists | cheapest; needs sandbox exit, DNS (SPF, DKIM, DMARC) and an AWS account |
| **Postmark** | free 100/month; Basic $15/month for 10,000 | excellent deliverability and simple, but $15/month weighs on the $41 budget |
| SMTP from the VPS | free | **not recommended**: poor deliverability, likely spam-foldered |

### Error monitoring

| Option | Price | Notes |
|---|---|---|
| **Sentry Developer** | free: 1 user, about 5,000 errors/month, 30-day lookback | official Laravel and Vue SDKs (new dependencies need approval); a second user needs Team (about $26–29/month) |
| Flare (Laravel) | reportedly from about $9/month (sources disagree) | Laravel-native; verify the price |
| Logs only (stderr / files) + uptime ping | free | the current state; nobody is alerted |

### Background jobs and scheduler

| Hosting | How |
|---|---|
| VPS | cron `* * * * * php artisan schedule:run`, plus a `queue:work` under systemd or Supervisor (Forge sets both up) |
| Laravel Cloud | built-in scheduler and worker clusters (worker clusters from Growth) |
| Vercel container | no persistent worker; `QUEUE_CONNECTION=sync`, and the scheduler would need Vercel Cron calling a protected endpoint |

## Three complete profiles (estimates, low traffic)

**Assumptions:**

- Under 5,000 visitors a month; under 2,000 emails a month; under 10 GB of photos; one developer seat.
- Database awake most of the day.
- Prices as above, **unverified on official pages**.

| | **P1: Managed VPS (candidate, D-08)** | **P2: Managed Laravel platform** | **P3: Lowest cost** |
|---|---|---|---|
| Frontends | Cloudflare Pages (free) | Cloudflare Pages (free) | Cloudflare Pages (free) |
| API | DO Droplet 2 GB, Bangalore ($12) | Laravel Cloud Starter ($5 + usage, about $4–10) | DO Droplet 2 GB ($12) |
| Database | DO Managed Postgres ($15.15), daily backups | Neon Launch (usage; idle-heavy, roughly $5–20) | Postgres on the droplet ($0) + nightly dump to B2 |
| Storage | Cloudflare R2 (free tier) | Cloudflare R2 (free tier) | Cloudflare R2 (free tier) |
| Email | Amazon SES (< $1) | Amazon SES (< $1) | Amazon SES (< $1) |
| Monitoring | Sentry Developer (free) | Sentry Developer (free) | Sentry Developer (free) |
| Deploy tooling | Forge Hobby ($12, optional) or a scripted deploy | included | scripted deploy |
| **Monthly** | **about $28** ($40 with Forge) | **about $10–30** (usage-dependent) | **about $13** |
| **Yearly** | about $336–480 | about $120–360 | about $156 |
| Fits a USD 500/year card | yes (with little room) | yes | yes |
| Backup and restore | managed daily + your monthly restore drill | Neon point-in-time restore (≤ 7 days) | **your scripts only**; highest risk |
| You operate | the VPS OS, PHP and nginx | almost nothing | everything, including the database |
| Lock-in | low | medium | low |

### Why P1 was proposed (chosen as candidate, D-08)

- **Persistent disk:** the API works as built today, with no serverless rework.
- **Managed backups:** the database is the asset that cannot be recreated.
- **Location:** India is likely the nearest region (to be measured).
- **Cost and portability:** predictable flat cost inside the card limit, and every piece is replaceable.
- **What it costs in effort:** the owner, or a contractor, applies OS security updates monthly. Forge reduces that work.

## What does not change in the code for any profile

- **Already working:** `TRUSTED_PROXIES`, the production env template and the health endpoint.
- **Needed in every profile:** the S3 storage driver (D1; one approved package), the uploads kill switch (upload safety design), the scheduler entries, and `expose_php=Off`.
