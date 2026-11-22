import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 20 (F4, F15): days and overnight stops, editing stops, planned and spent costs, checklist, notes, recorded
// tracks on the trip map; editors change, viewers read. All rows are test data, removed afterwards.
const password = 'trip-planning-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from trips where title like 'E2E TPL %'`)
  sql(`delete from routes where slug like 'e2e-tpl-%'`)
  sql(`delete from destinations where slug like 'e2e-tpl-%'`)
})

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}
const noSideScroll = async (page: Page) => expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)

test('riders plan days, overnight stops, costs, a checklist and notes together; a viewer only reads', async ({ page, browser }, info) => {
  const s = `${info.project.name}-${stamp()}`
  sql(`insert into destinations (id, slug, name, status, latitude, longitude, is_dev_seed, created_at, updated_at) values (gen_random_uuid(), 'e2e-tpl-town-${s}', 'E2E TPL Town ${s}', 'published', 28.21, 83.96, true, now(), now())`)
  const r = sql(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), 'e2e-tpl-${s}', 'E2E TPL Route ${s}', 'published', now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, path, created_at, updated_at)
    values (gen_random_uuid(), '${r}', 1, 'Beni', 'Tatopani', 40, 2.5, 'mixed', '[[28.35, 83.56], [28.42, 83.6], [28.48, 83.65]]', now(), now()) returning id`)
  const owner = await createConfirmedRider(`tpl-own-${info.project.name}`, password)
  const editor = await createConfirmedRider(`tpl-ed-${info.project.name}`, password)
  const viewer = await createConfirmedRider(`tpl-view-${info.project.name}`, password)
  const trip = `E2E TPL trip ${s}`
  const tripId = sql(`insert into trips (id, owner_id, title, starts_on, ends_on, created_at, updated_at) values (gen_random_uuid(), '${owner.id}', '${trip}', '2027-03-01', '2027-03-03', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${owner.id}', 'owner', now(), now()), ('${tripId}', '${editor.id}', 'editor', now(), now()), ('${tripId}', '${viewer.id}', 'viewer', now(), now())`)
  sql(`insert into trip_stops (id, trip_id, position, day, name, route_id, route_segment_id, route_part, created_at, updated_at) values (gen_random_uuid(), '${tripId}', 1, 2, 'Beni to Tatopani', '${r}', '${seg}', 'stretch', now(), now())`)

  await signIn(page, owner.email)
  await page.goto(`/rides/${tripId}`)
  const stops = page.getByRole('list', { name: 'Stops in order' }).getByRole('listitem')

  // F4: an overnight stop at a destination on day 1, a stop on day 2 that is not a stay yet.
  await page.locator('#sn').fill('Lakeside guest house')
  await page.locator('#sd').fill('1')
  await page.locator('#sdest').selectOption({ label: `E2E TPL Town ${s}` })
  await page.getByLabel('Overnight stop').check()
  await page.getByRole('button', { name: 'Add', exact: true }).first().click()
  await expect(stops).toHaveCount(2)
  await page.locator('#sn').fill('Tatopani hot springs')
  await page.locator('#sd').fill('2')
  await page.getByRole('button', { name: 'Add', exact: true }).first().click()
  await expect(stops).toHaveCount(3)

  const days = page.getByRole('list', { name: 'Days' }).getByRole('listitem')
  await expect(days).toHaveCount(2)
  await expect(days.nth(0)).toContainText('Overnight: Lakeside guest house')
  await expect(days.nth(1)).toContainText('40 km recorded, about 2.5 h riding (estimate).')
  await expect(days.nth(1)).toContainText('No overnight stop recorded')
  const notes = page.getByRole('list', { name: 'Planning notes' })
  await expect(notes).toContainText('No overnight stop recorded for the night after day 2.')
  await expect(notes).toContainText('The itinerary lists a later day before an earlier one.')

  // Edit the day 2 stop into the night's stay; fix the order with the move buttons.
  await page.getByRole('button', { name: 'Edit Tatopani hot springs' }).click()
  const form = page.getByRole('form', { name: 'Edit Tatopani hot springs' })
  await form.getByLabel('Stop', { exact: true }).fill('Tatopani guest house')
  await form.getByLabel(/^Note/).fill('Not booked yet')
  await form.getByLabel('Overnight stop').check()
  await form.getByRole('button', { name: 'Save' }).click()
  await expect(stops.nth(2)).toContainText('Day 2: Tatopani guest house')
  await expect(stops.nth(2)).toContainText('Not booked yet')
  await expect(stops.nth(2).getByLabel('overnight stop')).toBeVisible()
  await page.getByRole('button', { name: 'Move Lakeside guest house up' }).click()
  await expect(stops.nth(0)).toContainText('Lakeside guest house')
  await expect(page.getByRole('list', { name: 'Planning notes' })).toHaveCount(0)
  await expect(days.nth(1)).toContainText('Overnight: Tatopani guest house')

  // F4 map: the destination marker and the recorded track (a grey line, never a straight guess).
  const map = page.getByRole('img', { name: new RegExp(`Map of ${trip} stops`) })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await expect(map.locator('path.leaflet-interactive[stroke="#5c5850"]')).toHaveCount(1)
  await expect(map.locator('path.leaflet-interactive[stroke="#17181b"]')).toHaveCount(1)
  await expect(page.getByText('Grey lines: recorded route tracks.')).toBeVisible()

  // F15 budget: planned with and without an amount, then marked as spent with the amount.
  const budget = page.getByRole('region', { name: 'Budget' })
  await page.locator('#el').fill('Fuel for three days')
  await page.locator('#ea').fill('6000')
  await budget.getByRole('button', { name: 'Add' }).click()
  await expect(budget.getByRole('list', { name: 'Planned costs' })).toContainText('Fuel for three days') // saved and the form reset
  await page.locator('#el').fill('Permit')
  await page.locator('#ec').selectOption('permits')
  await budget.getByRole('button', { name: 'Add' }).click()
  await expect(page.getByTestId('budget-planned')).toHaveText('NPR 6,000')
  await expect(budget).toContainText('1 planned item has no amount yet, so this total is incomplete.')
  await expect(budget.getByRole('list', { name: 'Planned costs' })).toContainText('amount not known yet')
  await page.locator('#es').selectOption('spent')
  await page.locator('#el').fill('Lunch')
  await expect(budget.getByRole('button', { name: 'Add' })).toBeDisabled() // spent needs an amount
  await page.locator('#es').selectOption('planned')
  await page.locator('#el').fill('')
  await budget.getByRole('button', { name: 'Edit Permit' }).click()
  await budget.getByLabel('Status').first().selectOption('spent')
  await budget.getByLabel('Amount (NPR)').first().fill('3000')
  await budget.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByTestId('budget-spent')).toHaveText('NPR 3,000')
  await expect(budget.getByRole('list', { name: 'Spent costs' })).toContainText('Permit · permits · paid by')
  await expect(budget).not.toContainText('no amount yet')

  // F15 checklist: empty state, add, tick, edit, untick; progress follows.
  const list = page.getByRole('region', { name: 'Checklist' })
  await expect(page.getByTestId('checklist-progress')).toContainText('No items yet.')
  await page.locator('#cl').fill('Chain lube')
  await list.getByRole('button', { name: 'Add' }).click()
  await expect(page.getByTestId('checklist-progress')).toHaveText('0 of 1 done')
  await list.getByRole('checkbox', { name: 'Chain lube' }).check()
  await expect(page.getByTestId('checklist-progress')).toHaveText('1 of 1 done')
  await list.getByRole('button', { name: 'Edit Chain lube' }).click()
  await list.getByLabel('Item').first().fill('Chain lube and rag')
  await list.getByLabel('Section').first().selectOption('personal')
  await list.getByRole('button', { name: 'Save' }).click()
  await expect(list.getByRole('list', { name: 'Personal checklist' })).toContainText('Chain lube and rag')
  await list.getByRole('checkbox', { name: 'Chain lube and rag' }).uncheck()
  await expect(page.getByTestId('checklist-progress')).toHaveText('0 of 1 done')

  // F15 notes: written, saved, shown to members.
  await page.getByLabel('Notes').fill('Meet at the Lakeside pump at 7.')
  await page.getByRole('button', { name: 'Save details' }).click()
  await expect(page.getByText('Details saved.')).toBeVisible()
  await noSideScroll(page)
  await page.getByRole('region', { name: 'Day by day' }).scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/trip-days-${info.project.name}.png` })
  await budget.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/trip-budget-${info.project.name}.png` })

  // The editor changes the plan; the viewer reads exactly that and can change nothing.
  const opts = info.project.use.viewport ? { viewport: info.project.use.viewport } : {}
  const ectx = await browser.newContext(opts)
  const ep = await ectx.newPage()
  await signIn(ep, editor.email)
  await ep.goto(`/rides/${tripId}`)
  await ep.getByLabel('Notes').fill('Meet at 6.30 instead.')
  await ep.getByRole('button', { name: 'Save details' }).click()
  await expect(ep.getByText('Details saved.')).toBeVisible()
  ep.once('dialog', (d) => d.accept())
  await ep.getByRole('region', { name: 'Budget' }).getByRole('button', { name: 'Remove Fuel for three days' }).click()
  await expect(ep.getByTestId('budget-planned')).toHaveText('NPR 0')
  await ectx.close()

  const vctx = await browser.newContext(opts)
  const vp = await vctx.newPage()
  await signIn(vp, viewer.email)
  await vp.goto(`/rides/${tripId}`)
  await expect(vp.getByLabel('Notes')).toHaveValue('Meet at 6.30 instead.')
  await expect(vp.getByLabel('Notes')).toBeDisabled()
  await expect(vp.getByTestId('budget-spent')).toHaveText('NPR 3,000')
  await expect(vp.getByRole('list', { name: 'Days' }).getByRole('listitem')).toHaveCount(2)
  await expect(vp.getByRole('checkbox', { name: 'Chain lube and rag' })).toBeDisabled()
  await expect(vp.getByRole('button', { name: /^(Edit|Remove|Move) / })).toHaveCount(0)
  await expect(vp.getByRole('button', { name: 'Add', exact: true })).toHaveCount(0)
  await expect(vp.getByRole('button', { name: 'Save details' })).toHaveCount(0)
  await vctx.close()

  // Notes can be cleared again by the owner.
  await page.reload()
  await expect(page.getByLabel('Notes')).toHaveValue('Meet at 6.30 instead.')
  await page.getByLabel('Notes').fill('')
  await page.getByRole('button', { name: 'Save details' }).click()
  await expect(page.getByText('Details saved.')).toBeVisible()
  await page.reload()
  await expect(page.getByLabel('Notes')).toHaveValue('')
})
