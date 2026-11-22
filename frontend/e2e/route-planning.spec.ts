import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// Session 18: plan a ride from recorded stretch data (order, cumulative distance and time, fuel gaps, reverse)
// and find routes with filters. All rows are test data, removed afterwards.
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E Plan %'`)
  sql(`delete from routes where slug like 'e2e-plan-%'`)
  sql(`delete from destinations where slug like 'e2e-plan-%'`)
})

function seedRoute(s: string) {
  const r = sql(`insert into routes (id, slug, name, status, distance_km, created_at, updated_at) values (gen_random_uuid(), 'e2e-plan-main-${s}', 'E2E Plan Main ${s}', 'published', 150, now(), now()) returning id`)
  const segs = [['Alpha', 'Bravo', 20, 1, 'paved'], ['Bravo', 'Charlie', 30, 1.5, 'paved'], ['Charlie', 'Delta', 40, null, 'gravel'], ['Delta', 'Echo', 25, 1, 'dirt']] as const
  const ids = segs.map(([a, b, km, h, surf], i) => sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, created_at, updated_at)
    values (gen_random_uuid(), '${r}', ${i + 1}, '${a}', '${b}', ${km}, ${h ?? 'null'}, '${surf}', now(), now()) returning id`))
  sql(`insert into service_points (id, kind, name, route_segment_id, status, created_at, updated_at) values (gen_random_uuid(), 'fuel', 'E2E Plan Pump ${s}', '${ids[1]}', 'published', now(), now())`)
}

test('a rider plans a ride stretch by stretch, with fuel gaps, and reverses it', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seedRoute(s)
  await page.goto(`/routes/e2e-plan-main-${s}`)
  const plan = page.getByRole('region', { name: 'Plan this ride' })
  // Phones show one card per stretch, wider screens a table: the same rows in either form.
  const narrow = (page.viewportSize()?.width ?? 1280) < 768
  const rows = narrow ? plan.getByRole('list', { name: /Stretches in riding order/ }).getByRole('listitem') : plan.getByRole('table').getByRole('row').filter({ hasNot: page.getByRole('columnheader') })
  await expect(rows.nth(0)).toContainText('Alpha to Bravo')
  await expect(rows.nth(3)).toContainText('Delta to Echo')
  await expect(rows.nth(3)).toContainText('115 km') // 20 + 30 + 40 + 25 from the start
  await expect(plan).toContainText('Not every stretch has a time estimate, so no total is shown.')
  // Fuel on stretch 2 only: the longest run counts Bravo-Charlie in full, then Charlie-Delta and Delta-Echo: 95 km.
  await expect(plan).toContainText('Up to 95 km with no recorded fuel station (Bravo to Echo)')
  await expect(plan).toContainText('The stretches add up to 115 km, but the route total says 150 km.')

  await plan.getByRole('button', { name: 'Ride it in reverse' }).click()
  await expect(rows.nth(0)).toContainText('Echo to Delta')
  await expect(rows.nth(0)).toContainText('25 km')
  await expect(plan.getByRole('button', { name: /Riding from/ })).toHaveAttribute('aria-pressed', 'true')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true) // no sideways scrolling
  await plan.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/route-plan-${info.project.name}.png` })
})

test('riders filter routes by surface, province, distance and recorded track', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  const g = sql(`insert into destinations (id, slug, name, status, province, is_dev_seed, created_at, updated_at) values (gen_random_uuid(), 'e2e-plan-g-${s}', 'E2E Plan G ${s}', 'published', 'Gandaki', true, now(), now()) returning id`)
  const smooth = sql(`insert into routes (id, slug, name, status, distance_km, start_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-plan-smooth-${s}', 'E2E Plan Smooth ${s}', 'published', 60, '${g}', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, path, created_at, updated_at) values (gen_random_uuid(), '${smooth}', 1, 'A', 'B', 'paved', '[[28.0,84.0],[28.1,84.1]]', now(), now())`)
  const rough = sql(`insert into routes (id, slug, name, status, distance_km, created_at, updated_at) values (gen_random_uuid(), 'e2e-plan-rough-${s}', 'E2E Plan Rough ${s}', 'published', 300, now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, created_at, updated_at) values (gen_random_uuid(), '${rough}', 1, 'C', 'D', 'dirt', now(), now())`)

  await page.goto('/routes')
  const filters = page.getByRole('search', { name: 'Filter routes' })
  await filters.getByLabel('Search routes').fill(`E2E Plan`)
  await filters.getByLabel('Surface').selectOption('paved_only')
  await filters.getByRole('button', { name: 'Show' }).click()
  await expect(page).toHaveURL(/surface=paved_only/)
  await expect(page.getByRole('link', { name: new RegExp(`E2E Plan Smooth ${s}`) })).toBeVisible()
  await expect(page.getByRole('link', { name: new RegExp(`E2E Plan Rough ${s}`) })).toHaveCount(0)
  await filters.getByLabel('Surface').selectOption('rough')
  await filters.getByLabel('Up to (km)').fill('100')
  await filters.getByRole('button', { name: 'Show' }).click()
  await expect(page.getByText('No routes match these filters')).toBeVisible() // the rough route is 300 km
  await filters.getByRole('button', { name: 'Clear' }).click()
  await page.goto(`/routes?q=E2E Plan&province=Gandaki&has_track=1`)
  await expect(page.getByRole('link', { name: new RegExp(`E2E Plan Smooth ${s}`) })).toBeVisible()
  await expect(page.getByRole('link', { name: new RegExp(`E2E Plan Rough ${s}`) })).toHaveCount(0)
})
