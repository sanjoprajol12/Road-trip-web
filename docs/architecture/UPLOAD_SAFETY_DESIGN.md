# Upload safety (implemented in session 24)

**Status:** implemented and tested (session 24, 2026-11-22). **No persistent storage provider is configured**: until one is approved, configured and verified (owner decision D1 / O6), production accepts no new uploads.

Existing media were not migrated or changed.

## Upload paths (inspected again in session 24)

| Workflow | Endpoint | Writes a file? |
|---|---|---|
| Rider photos (experiences and road reports) | `POST contributions/{id}/media` (rider; `throttle:uploads`, `can-contribute`, `ContributionPolicy::update`) | yes |
| Staff media library (the only source of place, destination, CMS, slide and banner images) | `POST admin/media` (staff; `can:media.manage`, `throttle:uploads`) | yes |
| Place and destination images | choose library assets by id | no |
| Route tracks (GPX, GeoJSON) | `POST admin/route-segments/{id}/path` | no (parsed into `route_segments.path`) |
| Storage probe | `POST admin/settings/storage/test` | temporary probe file, staff only |

Both file-writing paths now go through **one function**, `App\Support\Images::store()`. No other code writes user files.

## The policy: `App\Support\UploadPolicy`

`status()` checks these in order. The first failure is the reason; there is no fallback disk.

| # | Check | Reason code | Alert |
|---|---|---|---|
| 1 | `UPLOADS_ENABLED` (env) is true | `disabled_by_environment` | no (deliberate) |
| 2 | setting `storage.uploads_enabled` is true | `disabled_by_setting` | no (deliberate) |
| 3 | the chosen disk (setting `storage.driver`) exists in `config/filesystems.php`; for `s3` the bucket, key and secret are set | `storage_not_configured` | **critical** |
| 4 | if the disk's driver is `local`, the environment is `local` or `testing` (owner decision O6: the local disk is for development only; **no override exists**) | `storage_not_persistent` | **critical** |

**The two switches:**

- The environment switch can only turn uploads off. The staff setting cannot turn them back on against it.
- `storage.driver` still accepts only `local` (unchanged rule: no provider until D1). Adding a provider later means: approved package, disk config, allowing the value, choosing it in the staff app, running "Test storage", then setting `UPLOADS_ENABLED=true`.

**Order inside `Images::store()`:**

1. Policy: refused, so a 422 on the upload field with the staff message, before any bytes are read.
2. Decode and re-encode: the existing validation, unchanged. Real JPEG, PNG or WebP only; size, dimension and type limits; WebP output, so EXIF and GPS are stripped.
3. Write to the chosen disk:
   - A failed or throwing write gives a 422 ("The image could not be stored right now. Try again later."), raises a critical `storage_write_failed` alert, makes a cleanup attempt, and creates **no database row**.
4. The caller creates its row. Rider photos are created directly; library assets are created in a transaction together with the audit entry. If that fails, `Images::discard()` deletes the file and the error is re-thrown: **no orphaned file**.

**Logging (`UploadPolicy::alert`):**

- `Log::critical('Uploads refused: storage is not usable for new files.', {reason, disk, driver, environment})`.
- No file names, paths, user data or credentials.
- At most once an hour per reason (`Cache::add`), so a busy form cannot flood the log.

**Authorization is untouched:** the policy runs after the route's auth and permission middleware and the controller's policy check and validation. Guests still get 401, other riders 403, and staff without `media.manage` 403, whatever the upload state.

## What users see

- **`GET /api/v1/site`** (public): `uploads: {enabled, message}`, with no reason or disk.
  - Public site: the "Add photos" form is replaced by a **Photos** section showing the message; the report itself still sends.
  - Staff media library: the upload form is replaced by the message plus "Existing images can still be used, edited and removed."
- **`GET /api/v1/health`:** `uploads: "enabled" | "paused"`. Paused is not an outage (status stays `ok`), but monitoring can alert on it.
- **`GET /api/v1/admin/settings/storage/upload-status`** (`settings.storage.manage`): `{allowed, reason, disk, driver, environment}`.
- **Staff → Settings → Storage and uploads:** shows that status in words, plus the "Accept new uploads" toggle and the paused message (both audited like every setting).
- **Existing images** (`GET media/{id}`, `GET files/{id}`) never consult the policy and keep working in every state.

## Configuration

| Variable or setting | Where | Default | Meaning |
|---|---|---|---|
| `UPLOADS_ENABLED` | env (`config/mrn.php` `uploads.enabled`) | `true` | `false` stops all new uploads; nothing can override it |
| `mrn.uploads.local_disk_environments` | config | `['local', 'testing']` | where the local disk may be used (O6); intentionally not an env variable |
| `storage.uploads_enabled` | staff setting | `true` | staff pause switch |
| `storage.uploads_paused_message` | staff setting | "Photo uploads are paused for now. Everything else works, and you can send your report without photos." | shown in both apps and returned with the 422 |
| `storage.driver` | staff setting | `local` (only allowed value until D1) | which disk new files go to |
| `LOCAL_DISK_ROOT` | env (`config/filesystems.php`) | `app/private` (inside `storage/`) | E2E sets `app/e2e`, so browser-test uploads never mix with development files |
| `AWS_*` | env | empty | the S3-compatible disk, once a provider is approved |

### Safe production configuration (until a provider is verified)

```
APP_ENV=production
UPLOADS_ENABLED=false        # explicit; even if forgotten, the local disk is refused in production
FILESYSTEM_DISK=local        # sessions and cache are in Postgres; no user file is written here
```

**Result:**

- New uploads give a 422 with the message, the pickers are hidden, and `/health` shows `uploads: paused`.
- Existing files are served.
- There is no critical log while `UPLOADS_ENABLED=false`, because the pause is deliberate.
- If `UPLOADS_ENABLED` is left `true` by mistake, every attempt is still refused and logged critically (once an hour).

## Tests

**`tests/Feature/UploadPolicyTest.php`** (7 tests, 105 assertions):

1. **Switched off:** off by environment, and off by setting (the setting cannot override the environment): rider and staff 422 with the message, nothing stored, no critical log, the report still works, `/site` and `/health` show paused.
2. **Production with the local disk:** refused for riders and staff; exactly one critical log with only reason, disk, driver and environment; earlier photos and library images still served.
3. **Other environments:** staging, production and preview all refuse the local disk (no override).
4. **Durable disk:**
   - Production with `s3` chosen but not configured: refused, critical `storage_not_configured`, nothing written to `local` or `s3` (no fallback).
   - Once configured (faked): both uploads land on `s3` only, and the rows record `disk = s3`.
5. **Development and testing:** accept the local disk; `/site` shows enabled.
6. **Failures:**
   - A failed database write removes the stored file (rider and staff).
   - A failed storage write gives a clear 422, a critical `storage_write_failed`, and no row.
7. **Authorization and validation:** guests 401, other riders 403, riders on the staff library 401, staff without `media.manage` 403. Invalid and too-small images still give 422. The status endpoint needs `settings.storage.manage`, and `/site` never shows the reason.

**Unchanged and passing:**

- `SecurityPostureTest` (EXIF and GPS stripping).
- `ContributionTest` and `SettingsAndMediaTest` (validation, re-encoding, `s3` still refused as a setting value).

**E2E:**

- `web/frontend/e2e/uploads.spec.ts` (Chromium, Firefox, mobile):
  - a photo upload stored only under `storage/app/e2e`;
  - after pausing, the message replaces the picker and the report still sends;
  - the earlier photo still loads.
- `admin/e2e/uploads.spec.ts` (Chromium, Firefox):
  - an administrator pauses uploads in Settings and the status explains why;
  - the media library hides upload while the existing image still renders;
  - resuming restores the form.

## Not done (by instruction)

- No storage provider, account or connection.
- No migration of existing media.
- No deployment.
