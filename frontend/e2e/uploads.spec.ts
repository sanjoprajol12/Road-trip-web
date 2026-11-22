import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 24: the upload policy as riders see it. Uploads in this E2E API land in storage/app/e2e (isolated from
// development files). While uploads are paused the photo picker is replaced by the message; reports still send and
// photos uploaded earlier stay viewable.
const password = 'upload-policy-2026'
const photo = fileURLToPath(new URL('../public/images/photos/bandipur-bazaar-640.webp', import.meta.url))
const storage = fileURLToPath(new URL('../../storage/app/', import.meta.url))
const resetUploads = () => {
  sql(`delete from settings where key in ('storage.uploads_enabled', 'storage.uploads_paused_message')`)
  sql(`delete from cache where key like '%mrn.settings'`) // the API caches settings until they change
}
test.beforeAll(resetUploads)
test.afterAll(() => {
  resetUploads()
  sql(`delete from contributions where route_id in (select id from routes where slug like 'e2e-upl-%')`)
  sql(`delete from routes where slug like 'e2e-upl-%'`)
})

async function fileReport(page: Page, slug: string, title: string) {
  await page.goto(`/routes/${slug}`)
  await page.getByRole('link', { name: 'Report this stretch' }).click()
  await page.getByLabel('Route').selectOption(slug)
  await page.getByLabel('Stretch').selectOption({ label: 'Checkpoint A to Checkpoint B' })
  await page.getByLabel('Title').fill(title)
  await page.getByLabel('Day you rode it').fill(new Date(Date.now() - 86_400_000).toISOString().slice(0, 10))
  await page.getByLabel('What you found').fill('About 500 metres of loose gravel; walking pace was fine on a 200cc bike.')
  await page.getByRole('button', { name: 'Send for review' }).click()
  await expect(page.getByText('Sent for review.')).toBeVisible()
}

test('riders add photos while uploads are allowed, and see a clear message while they are paused', async ({ page }, info) => {
  const s = `${info.project.name}-${Date.now()}`
  const slug = `e2e-upl-${s}`
  const r = sql(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), '${slug}', 'E2E upload road ${s}', 'published', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, created_at, updated_at) values (gen_random_uuid(), '${r}', 1, 'Checkpoint A', 'Checkpoint B', 'mixed', now(), now())`)
  const rider = await createConfirmedRider(`upl-${info.project.name}`, password)
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(rider.email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)

  // Allowed (development environment, local disk): the photo is stored in the isolated E2E folder only.
  await fileReport(page, slug, `Gravel with a photo ${s}`)
  await page.getByLabel('Photo', { exact: true }).setInputFiles(photo)
  await page.getByLabel('Describe the photo').fill('Loose gravel below the second bridge')
  await page.getByRole('button', { name: 'Add photo' }).click()
  const img = page.getByRole('img', { name: 'Loose gravel below the second bridge' })
  await expect(img).toBeVisible()
  const path = sql(`select m.path from contribution_media m join contributions c on c.id = m.contribution_id where c.title = 'Gravel with a photo ${s}'`)
  expect(existsSync(`${storage}e2e/${path}`), 'stored under storage/app/e2e').toBe(true)
  expect(existsSync(`${storage}private/${path}`), 'not in the development folder').toBe(false)
  const photoUrl = await img.getAttribute('src')

  // Paused by staff: the picker is replaced by the message; the report still sends; the earlier photo still loads.
  sql(`insert into settings (key, value, updated_at) values ('storage.uploads_enabled', 'false', now()) on conflict (key) do update set value = excluded.value`)
  sql(`delete from cache where key like '%mrn.settings'`)
  await fileReport(page, slug, `Gravel while paused ${s}`)
  const paused = page.getByRole('region', { name: 'Photos' })
  await expect(paused.getByRole('status')).toHaveText('Photo uploads are paused for now. Everything else works, and you can send your report without photos.')
  await expect(page.getByLabel('Photo', { exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
  await page.screenshot({ path: `test-results/uploads-paused-${info.project.name}.png` })
  // Fetched from the page, as the browser loads it (same origin, with the rider's session).
  const earlier = await page.evaluate(async (url) => { const r = await fetch(url); return [r.status, r.headers.get('content-type')] }, photoUrl!)
  expect(earlier).toEqual([200, 'image/webp'])
  expect(sql(`select count(*) from contributions where title = 'Gravel while paused ${s}'`)).toBe('1')
})
