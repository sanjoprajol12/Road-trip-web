import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 19 (F3): a rider adds stretches, checkpoints or a whole route to a trip, reorders and removes them;
// a viewer only reads. All rows are test data, removed afterwards.
const password = 'trip-route-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from trips where title like 'E2E RT %'`)
  sql(`delete from routes where slug like 'e2e-rt-%'`)
})

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}
const noSideScroll = async (page: Page) => expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)

/** Alpha - Bravo - Charlie - Delta; the middle stretch has no distance and no time. Bravo has a recorded position. */
function seedRoute(s: string) {
  const r = sql(`insert into routes (id, slug, name, status, distance_km, created_at, updated_at) values (gen_random_uuid(), 'e2e-rt-${s}', 'E2E RT Route ${s}', 'published', 60, now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at) values
    (gen_random_uuid(), '${r}', 1, 'Alpha', 'Bravo', 20, 1, 'paved', null, null, null, null, now(), now()),
    (gen_random_uuid(), '${r}', 2, 'Bravo', 'Charlie', null, null, 'gravel', 28.25, 84.2, null, null, now(), now()),
    (gen_random_uuid(), '${r}', 3, 'Charlie', 'Delta', 40, 2, 'dirt', null, null, null, null, now(), now())`)
}

test('a rider adds stretches, checkpoints and a whole route to trips, reorders and removes them; a viewer cannot', async ({ page, browser }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seedRoute(s)
  const owner = await createConfirmedRider(`rt-own-${info.project.name}`, password)
  const viewer = await createConfirmedRider(`rt-view-${info.project.name}`, password)
  const trip = `E2E RT trip ${s}`
  const tripId = sql(`insert into trips (id, owner_id, title, created_at, updated_at) values (gen_random_uuid(), '${owner.id}', '${trip}', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${owner.id}', 'owner', now(), now()), ('${tripId}', '${viewer.id}', 'viewer', now(), now())`)

  // Guests are asked to sign in.
  await page.goto(`/routes/e2e-rt-${s}`)
  await expect(page.getByRole('region', { name: 'Add to a trip' })).toContainText('Sign in to add this route to one of your trips.')

  await signIn(page, owner.email)
  await page.goto(`/routes/e2e-rt-${s}`)
  const box = page.getByRole('region', { name: 'Add to a trip' })
  await box.getByLabel('Trip', { exact: true }).selectOption({ label: trip })
  await expect(box.getByRole('checkbox', { name: /^Bravo to Charlie stretch/ })).toHaveAccessibleName(/Missing: no distance recorded, no time estimate, no recorded track/)

  // Chosen out of order; added in riding order. Totals use recorded values only.
  await box.getByRole('checkbox', { name: /^Charlie to Delta stretch/ }).check()
  await box.getByRole('checkbox', { name: /^Bravo checkpoint/ }).check()
  await box.getByRole('checkbox', { name: /^Alpha to Bravo stretch/ }).check()
  await expect(box).toContainText('3 selected: 60 km recorded, about 3 h riding (estimate).')
  await box.getByRole('button', { name: 'Add 3 selected' }).click()
  await expect(box.getByRole('status')).toContainText(`Added 3 stops to ${trip}`)
  await expect(box.getByRole('checkbox', { name: /^Alpha to Bravo stretch · already on this trip/ })).toBeDisabled()
  await expect(box.getByRole('button', { name: 'Add the whole route' })).toBeDisabled()
  await expect(box).toContainText('Some stretches are already on this trip')

  await box.getByRole('checkbox', { name: /^Bravo to Charlie stretch/ }).check()
  await expect(box).toContainText('Totals cover recorded values only; 1 without a distance; 1 without a time estimate.')
  await box.getByRole('button', { name: 'Add 1 selected' }).click()
  await expect(box.getByRole('status')).toContainText(`Added 1 stop to ${trip} (4 from this route now)`)
  await noSideScroll(page)
  await box.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/route-to-trip-${info.project.name}.png`, fullPage: false })

  // A new trip from the same place, whole route, ridden in reverse.
  const fresh = `E2E RT new ${s}`
  await box.getByLabel('Trip', { exact: true }).selectOption({ label: 'A new trip' })
  await box.getByLabel('New trip title').fill(fresh)
  await box.getByRole('button', { name: 'Ride it in reverse' }).click()
  await expect(box.getByRole('checkbox').first()).toHaveAccessibleName(/^Delta checkpoint/)
  await box.getByRole('button', { name: 'Add the whole route' }).click()
  await expect(box.getByRole('status')).toContainText(`Added E2E RT Route ${s} to ${fresh}.`)
  await expect(box.getByRole('button', { name: 'Add the whole route' })).toBeDisabled()
  await box.getByRole('link', { name: 'Open the trip' }).click()
  await expect(page.getByRole('heading', { name: fresh })).toBeVisible()
  const freshStops = page.getByRole('list', { name: 'Stops in order' }).getByRole('listitem')
  await expect(freshStops).toHaveCount(1)
  await expect(freshStops.first()).toContainText('Whole route')
  await expect(freshStops.first()).toContainText('Missing: 1 of 3 stretches without a distance, 1 without a time estimate, 3 without a recorded track')
  await expect(page.getByTestId('trip-route-totals')).toContainText('60 km recorded')
  await expect(page.getByTestId('trip-route-totals')).toContainText('1 without a time estimate')

  // The first trip: riding order kept, then reordered; the order survives a reload.
  await page.goto(`/rides/${tripId}`)
  const stops = page.getByRole('list', { name: 'Stops in order' }).getByRole('listitem')
  const names = () => stops.locator('p.font-semibold').allTextContents()
  await expect(stops).toHaveCount(4)
  expect(await names()).toEqual(['Alpha to Bravo', 'Bravo', 'Charlie to Delta', 'Bravo to Charlie'])
  await expect(page.getByTestId('trip-route-totals')).toContainText('60 km recorded, about 3 h riding (estimate)')
  await expect(page.getByTestId('trip-route-totals')).toContainText('1 without a distance; 1 without a time estimate')
  await expect(page.getByRole('button', { name: 'Move Alpha to Bravo up' })).toBeDisabled()
  await page.getByRole('button', { name: 'Move Bravo to Charlie up' }).click()
  await expect(stops.nth(2)).toContainText('Bravo to Charlie')
  await page.reload()
  await expect(stops).toHaveCount(4)
  expect(await names()).toEqual(['Alpha to Bravo', 'Bravo', 'Bravo to Charlie', 'Charlie to Delta'])
  // The checkpoint with a recorded position is on the trip map.
  const map = page.getByRole('img', { name: new RegExp(`Map of ${trip} stops`) })
  await expect(map.locator('path.leaflet-interactive[stroke="#2f6b3a"]')).toHaveCount(1)
  await expect(page.getByText('Green: route checkpoints.')).toBeVisible()
  await noSideScroll(page)
  await page.getByRole('list', { name: 'Stops in order' }).scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/trip-route-parts-${info.project.name}.png` })

  // Removing asks first: cancelling keeps the stop.
  page.once('dialog', (d) => d.dismiss())
  await page.getByRole('button', { name: 'Remove Bravo', exact: true }).click()
  await expect(stops).toHaveCount(4)
  page.once('dialog', (d) => d.accept())
  await page.getByRole('button', { name: 'Remove Bravo', exact: true }).click()
  await expect(stops).toHaveCount(3)
  expect(await names()).toEqual(['Alpha to Bravo', 'Bravo to Charlie', 'Charlie to Delta'])

  // The viewer reads the itinerary but has no controls, and cannot choose this trip on the route page.
  const ctx = await browser.newContext(info.project.use.viewport ? { viewport: info.project.use.viewport } : {})
  const vp = await ctx.newPage()
  await signIn(vp, viewer.email)
  await vp.goto(`/rides/${tripId}`)
  await expect(vp.getByRole('list', { name: 'Stops in order' }).getByRole('listitem')).toHaveCount(3)
  await expect(vp.getByRole('button', { name: /^Move / })).toHaveCount(0)
  await expect(vp.getByRole('button', { name: /^Remove / })).toHaveCount(0)
  await vp.goto(`/routes/e2e-rt-${s}`)
  const vbox = vp.getByRole('region', { name: 'Add to a trip' })
  await expect(vbox.getByLabel('Trip', { exact: true }).locator('option', { hasText: trip })).toHaveCount(0)
  await expect(vbox.getByLabel('Trip', { exact: true })).toHaveValue('__new')
  await ctx.close()
})
