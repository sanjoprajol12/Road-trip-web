import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// L1, L5, L6, L13: a destination with a recorded position shows a map and what is near it (straight-line).
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`

test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E Near %'`)
  sql(`delete from routes where slug like 'e2e-near-%'`)
  sql(`delete from destinations where slug like 'e2e-near-%'`)
})

test('a destination page shows its map and nearby destinations, services and routes', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  const ins = (sqlText: string) => sql(sqlText)
  ins(`insert into destinations (id, slug, name, status, is_dev_seed, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-near-here-${s}', 'E2E Near Here ${s}', 'published', true, 28.0, 84.0, now(), now())`)
  const close = ins(`insert into destinations (id, slug, name, status, is_dev_seed, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-near-close-${s}', 'E2E Near Close ${s}', 'published', true, 28.1, 84.0, now(), now()) returning id`)
  ins(`insert into destinations (id, slug, name, status, is_dev_seed, created_at, updated_at) values (gen_random_uuid(), 'e2e-near-none-${s}', 'E2E Near None ${s}', 'published', true, now(), now())`)
  ins(`insert into service_points (id, kind, name, destination_id, latitude, longitude, status, created_at, updated_at) values (gen_random_uuid(), 'mechanic', 'E2E Near Workshop ${s}', '${close}', 28.05, 84.0, 'published', now(), now())`)
  const r = ins(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), 'e2e-near-road-${s}', 'E2E Near Road ${s}', 'published', now(), now()) returning id`)
  ins(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at) values (gen_random_uuid(), '${r}', 1, 'West', 'East', 'paved', 28.05, 83.5, 28.05, 84.5, now(), now())`)

  await page.goto(`/explore/e2e-near-here-${s}`)
  await expect(page.getByRole('heading', { level: 1, name: `E2E Near Here ${s}` })).toBeVisible()
  const map = page.getByRole('img', { name: /Map of E2E Near Here/ })
  await expect(map).toBeVisible()
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await expect(map.locator('path.leaflet-interactive')).toHaveCount(3) // here, nearby destination, nearby service

  const dests = page.getByRole('list', { name: 'Nearby destinations' })
  await expect(dests.getByRole('link', { name: `E2E Near Close ${s}` })).toBeVisible()
  await expect(dests).toContainText('11.1 km')
  await expect(page.getByRole('list', { name: 'Nearby services' })).toContainText(`E2E Near Workshop ${s}`)
  await expect(page.getByRole('list', { name: 'Routes passing nearby' })).toContainText('5.6 km from the route line')
  await expect(page.getByText(/Straight-line distance from E2E Near Here/)).toBeVisible()
  await map.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/destination-map-${info.project.name}.png`, fullPage: false })

  // Following a nearby link works; a destination without a position says so instead of showing a map.
  await dests.getByRole('link', { name: `E2E Near Close ${s}` }).click()
  await expect(page.getByRole('heading', { level: 1, name: `E2E Near Close ${s}` })).toBeVisible()
  await page.goto(`/explore/e2e-near-none-${s}`)
  await expect(page.getByText(/No map position has been recorded/)).toBeVisible()
  await expect(page.getByRole('img', { name: /Map of/ })).toHaveCount(0)
})
