import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 17: places in trips (roles respected) and route maps that draw only recorded tracks.
const password = 'trip-places-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from places where name like 'E2E TP %'`)
  sql(`delete from routes where slug like 'e2e-tp-%'`)
  sql(`delete from destinations where slug like 'e2e-tp-%'`)
})

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

test('a rider adds a place to a trip, sees it on the trip map, and removes it; a viewer cannot edit', async ({ page, browser }, info) => {
  const s = `${info.project.name}-${stamp()}`
  sql(`insert into places (id, slug, name, category, description, latitude, longitude, status, is_dev_seed, published_at, created_at, updated_at)
    values (gen_random_uuid(), 'e2e-tp-lake-${s}', 'E2E TP Lake ${s}', 'lake', 'Test place for the trip E2E.', 28.3, 84.2, 'published', true, now(), now(), now())`)
  const owner = await createConfirmedRider(`tp-own-${info.project.name}`, password)
  const viewer = await createConfirmedRider(`tp-view-${info.project.name}`, password)
  const trip = `E2E TP trip ${s}`
  const tripId = sql(`insert into trips (id, owner_id, title, created_at, updated_at) values (gen_random_uuid(), '${owner.id}', '${trip}', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${owner.id}', 'owner', now(), now()), ('${tripId}', '${viewer.id}', 'viewer', now(), now())`)

  await signIn(page, owner.email)
  await page.goto(`/places/e2e-tp-lake-${s}`)
  await page.getByLabel('Add to a trip').selectOption({ label: trip })
  await page.getByRole('button', { name: /^Add E2E TP Lake/ }).click()
  await expect(page.getByRole('status').filter({ hasText: `Added to ${trip}` })).toBeVisible()
  await page.getByRole('button', { name: /^Add E2E TP Lake/ }).click()
  await expect(page.getByRole('alert').filter({ hasText: 'already a stop on this trip' })).toBeVisible()

  await page.goto(`/rides/${tripId}`)
  await expect(page.getByRole('link', { name: `E2E TP Lake ${s}` })).toBeVisible()
  const map = page.getByRole('img', { name: new RegExp(`Map of ${trip} stops`) })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await map.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/trip-map-${info.project.name}.png` })
  await map.locator('path.leaflet-interactive[stroke="#8a5a00"]').first().click({ force: true })
  await expect(page).toHaveURL(new RegExp(`/places/e2e-tp-lake-${s}$`))

  // The viewer sees the stop but no way to change it, and cannot pick this trip when adding a place.
  const ctx = await browser.newContext()
  const vp = await ctx.newPage()
  await signIn(vp, viewer.email)
  await vp.goto(`/rides/${tripId}`)
  await expect(vp.getByRole('link', { name: `E2E TP Lake ${s}` })).toBeVisible()
  await expect(vp.getByRole('button', { name: `Remove E2E TP Lake ${s}` })).toHaveCount(0)
  await vp.goto(`/places/e2e-tp-lake-${s}`)
  await expect(vp.getByLabel('Add to a trip').locator('option', { hasText: trip })).toHaveCount(0)
  await ctx.close()

  await page.goto(`/rides/${tripId}`)
  await page.getByRole('button', { name: `Remove E2E TP Lake ${s}` }).click()
  await expect(page.getByText('No stops yet.')).toBeVisible()
  expect(sql(`select count(*) from trip_stops where trip_id = '${tripId}'`)).toBe('0')
})

test('destination and route maps draw only recorded tracks', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  const town = sql(`insert into destinations (id, slug, name, status, is_dev_seed, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-tp-town-${s}', 'E2E TP Town ${s}', 'published', true, 28.0, 84.0, now(), now()) returning id`)
  const tracked = sql(`insert into routes (id, slug, name, status, start_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-tp-tracked-${s}', 'E2E TP Tracked ${s}', 'published', '${town}', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, path, created_at, updated_at)
    values (gen_random_uuid(), '${tracked}', 1, 'Town', 'Pass', 'paved', 28.0, 84.0, 28.1, 84.2, '[[28.0,84.0],[28.04,84.05],[28.07,84.12],[28.1,84.2]]', now(), now())`)
  const plain = sql(`insert into routes (id, slug, name, status, start_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-tp-plain-${s}', 'E2E TP Plain ${s}', 'published', '${town}', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at)
    values (gen_random_uuid(), '${plain}', 1, 'Town', 'Valley', 'gravel', 28.0, 84.0, 27.9, 83.8, now(), now())`)

  await page.goto(`/explore/e2e-tp-town-${s}`)
  const map = page.getByRole('img', { name: new RegExp(`Map of E2E TP Town`) })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await expect(map.locator('path.leaflet-interactive[stroke="#5c5850"]')).toHaveCount(1) // the tracked route only
  await expect(page.getByRole('link', { name: `E2E TP Tracked ${s}` }).first()).toContainText('drawn on the map')
  await expect(page.getByRole('link', { name: `E2E TP Plain ${s}` }).first()).toContainText('no recorded track: not drawn')
  await expect(page.getByText('Grey lines: recorded route tracks.')).toBeVisible()
  await map.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/destination-tracks-${info.project.name}.png` })
  await map.locator('path.leaflet-interactive[stroke="#5c5850"]').dispatchEvent('click') // a forced click lands on the bounding-box centre, off the line
  await expect(page).toHaveURL(new RegExp(`/routes/e2e-tp-tracked-${s}$`))

  await page.goto(`/routes/e2e-tp-plain-${s}`)
  await expect(page.getByText('No recorded track for this route yet')).toBeVisible()
  await expect(page.locator('.leaflet-container path.leaflet-interactive[stroke-width="5"]')).toHaveCount(0) // no guessed line
  await page.goto(`/routes/e2e-tp-tracked-${s}`)
  await expect(page.getByText(/Lines are recorded tracks/)).toBeVisible()
  await expect(page.locator('.leaflet-container path.leaflet-interactive[stroke-width="5"]')).toHaveCount(1) // the recorded track
})
