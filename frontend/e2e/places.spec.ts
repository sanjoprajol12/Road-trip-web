import { expect, test } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Places of interest on the public site: discovery, detail, map markers, journeys, rider suggestions and corrections.
const password = 'places-rider-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from places where name like 'E2E Spot %'`)
  sql(`delete from routes where slug like 'e2e-spot-%'`)
  sql(`delete from destinations where slug like 'e2e-spot-%'`)
})

function seed(s: string) {
  const town = sql(`insert into destinations (id, slug, name, status, is_dev_seed, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-spot-town-${s}', 'E2E Spot Town ${s}', 'published', true, 27.5, 86.0, now(), now()) returning id`)
  const end = sql(`insert into destinations (id, slug, name, status, is_dev_seed, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-spot-end-${s}', 'E2E Spot End ${s}', 'published', true, 27.5, 86.8, now(), now()) returning id`)
  const r = sql(`insert into routes (id, slug, name, status, start_destination_id, end_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-spot-road-${s}', 'E2E Spot Road ${s}', 'published', '${town}', '${end}', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at) values (gen_random_uuid(), '${r}', 1, 'Town', 'End', 'paved', 27.5, 86.0, 27.5, 86.8, now(), now())`)
  sql(`insert into places (id, slug, name, category, summary, description, latitude, longitude, destination_id, status, is_dev_seed, last_confirmed_on, published_at, created_at, updated_at)
    values (gen_random_uuid(), 'e2e-spot-lake-${s}', 'E2E Spot Lake ${s}', 'lake', 'A test lake.', 'A published test place for the E2E suite.', 27.52, 86.0, '${town}', 'published', true, current_date - 10, now(), now(), now()),
           (gen_random_uuid(), 'e2e-spot-view-${s}', 'E2E Spot View ${s}', 'viewpoint', null, 'Another published test place.', 27.53, 86.01, null, 'published', true, null, now(), now(), now()),
           (gen_random_uuid(), 'e2e-spot-draft-${s}', 'E2E Spot Draft ${s}', 'temple', null, 'An unpublished test place.', 27.51, 86.0, null, 'draft', true, null, null, now(), now())`)
}

test('guests find places by search and category, open details, and follow map markers', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seed(s)
  await page.goto('/places')
  await page.getByLabel('Search places').fill(`E2E Spot Lake ${s}`)
  await page.getByRole('button', { name: 'Search' }).click()
  await expect(page.getByRole('link', { name: `E2E Spot Lake ${s}` })).toBeVisible()
  await page.getByLabel('Category').selectOption('viewpoint')
  await expect(page.getByText('No places found')).toBeVisible() // the search still applies, the lake is not a viewpoint
  await page.goto(`/places?category=lake&q=E2E Spot`)
  await expect(page.getByRole('link', { name: `E2E Spot Lake ${s}` })).toBeVisible()
  await expect(page.getByRole('link', { name: `E2E Spot Draft ${s}` })).toHaveCount(0)
  await page.getByRole('link', { name: `E2E Spot Lake ${s}` }).click()

  await expect(page.getByRole('heading', { level: 1, name: `E2E Spot Lake ${s}` })).toBeVisible()
  await expect(page.getByText(/Last confirmed/)).toBeVisible()
  await expect(page.getByRole('list', { name: 'Nearby places' })).toContainText(`E2E Spot View ${s}`)
  await expect(page.getByRole('list', { name: 'Nearby places' })).not.toContainText(`E2E Spot Draft ${s}`)
  const map = page.getByRole('img', { name: /Map of E2E Spot Lake/ })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await map.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/place-page-${info.project.name}.png` })

  // The destination map shows the place; selecting its marker opens the place page.
  await page.goto(`/explore/e2e-spot-town-${s}`)
  await expect(page.getByRole('list', { name: 'Places of interest' })).toContainText(`E2E Spot Lake ${s}`)
  const dmap = page.getByRole('img', { name: /Map of E2E Spot Town/ })
  await expect(dmap.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await dmap.locator('path.leaflet-interactive[stroke="#8a5a00"]').first().click({ force: true })
  await expect(page).toHaveURL(/\/places\/e2e-spot-(lake|view)-/)

  // Unpublished places are not public; journeys list published ones with a straight-line distance.
  await page.goto(`/places/e2e-spot-draft-${s}`)
  await expect(page.getByRole('heading', { name: 'Place not found' })).toBeVisible()
  await page.goto(`/journey?from=e2e-spot-town-${s}&to=e2e-spot-end-${s}&within=10`)
  const along = page.getByRole('list', { name: 'Places along the route' })
  await expect(along.getByRole('link', { name: `E2E Spot Lake ${s}` })).toBeVisible()
  await expect(along.getByRole('listitem').filter({ hasText: `E2E Spot Lake ${s}` })).toContainText('km from the route line')
  await expect(along).not.toContainText(`E2E Spot Draft ${s}`)
})

test('a rider suggests a place on the map, sees its status, and suggests a correction', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seed(s)
  const rider = await createConfirmedRider(`spot-${info.project.name}`, password)
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(rider.email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)

  await page.goto('/places/suggest')
  await page.getByRole('button', { name: 'Send for review' }).click()
  await expect(page.locator('#sp-name-e')).toBeVisible() // incomplete form refused
  await page.getByLabel('Name').fill(`E2E Spot Lake ${s}`)
  await page.getByLabel('Category').selectOption('lake')
  await page.getByLabel(/What is there/).fill('Same lake as the published one, to test duplicate detection.')
  await page.getByLabel('Latitude', { exact: true }).fill('27.52')
  await page.getByLabel('Longitude', { exact: true }).fill('86.0')
  await page.getByRole('button', { name: 'Send for review' }).click()
  await expect(page.getByRole('alert').filter({ hasText: 'It may already be listed' })).toBeVisible()

  await page.getByLabel('Name').fill(`E2E Spot Falls ${s}`)
  await page.getByLabel('Category').selectOption('waterfall')
  await page.getByLabel(/What is there/).fill('A small waterfall a short walk from the road, test data only.')
  const picker = page.getByTestId('location-picker')
  await expect(picker.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await picker.click({ position: { x: 200, y: 140 } })
  // Regression: scrolled under the sticky header, the map must stay below it (Leaflet's z-index is contained).
  await picker.evaluate((el) => window.scrollBy(0, el.getBoundingClientRect().top - 20))
  expect(await picker.evaluate((el) => getComputedStyle(el).isolation)).toBe('isolate') // its own stacking context
  await page.screenshot({ path: `test-results/place-picker-scrolled-${info.project.name}.png` })
  await expect(page.getByLabel('Latitude', { exact: true })).not.toHaveValue('27.52')
  await page.getByRole('button', { name: 'Send for review' }).click()
  await expect(page.getByText('Your suggestion is waiting for review')).toBeVisible()
  const mine = page.getByRole('list', { name: 'Your suggestions' })
  await expect(mine).toContainText(`E2E Spot Falls ${s}`)
  await expect(mine).toContainText('Waiting for review')
  expect(sql(`select status from places where name = 'E2E Spot Falls ${s}'`)).toBe('pending_review')
  await page.screenshot({ path: `test-results/place-suggest-${info.project.name}.png` })

  await page.goto(`/places/e2e-spot-lake-${s}`)
  await page.getByRole('button', { name: 'Suggest a correction' }).click()
  await page.getByLabel('What should change?').fill('The shore path is closed after the landslide.')
  await page.getByRole('button', { name: 'Send to moderators' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'A moderator will review' })).toBeVisible()
  expect(sql(`select count(*) from place_corrections c join places p on p.id = c.place_id where p.slug = 'e2e-spot-lake-${s}' and c.status = 'pending'`)).toBe('1')
})
