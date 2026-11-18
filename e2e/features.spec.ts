import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

const password = 'feature-check-2026'

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

/** A published route with one segment, created directly in the local database for this test only. */
function seedRoute(tag: string): { slug: string; segmentId: string } {
  const slug = `e2e-road-${tag}-${Date.now()}`
  const routeId = sql(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), '${slug}', 'E2E test road ${tag}', 'published', now(), now()) returning id`)
  const segmentId = sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, created_at, updated_at)
    values (gen_random_uuid(), '${routeId}', 1, 'Checkpoint A', 'Checkpoint B', 'mixed', now(), now()) returning id`)
  return { slug, segmentId }
}

test.afterAll(() => {
  sql(`delete from contributions where route_id in (select id from routes where slug like 'e2e-road-%')`)
  sql(`delete from routes where slug like 'e2e-road-%'`)
})

test('a rider files a road report on a specific stretch and sees it waiting for review', async ({ page }, info) => {
  const { slug } = seedRoute(info.project.name)
  const rider = await createConfirmedRider(`reporter-${info.project.name}`, password)
  await signIn(page, rider.email)

  await page.goto(`/routes/${slug}`)
  await expect(page.getByText('No rider reports for this stretch yet.')).toBeVisible()
  await page.getByRole('link', { name: 'Report this stretch' }).click()
  await expect(page).toHaveURL(/\/contribute\?segment=/)
  await expect(page.getByLabel('What are you sharing?')).toHaveValue('road_report')
  await page.getByLabel('Route').selectOption(slug)
  await page.getByLabel('Stretch').selectOption({ label: 'Checkpoint A to Checkpoint B' })
  await page.getByLabel('Title').fill('Loose gravel after the second bridge')
  await page.getByLabel('Day you rode it').fill(new Date(Date.now() - 86_400_000).toISOString().slice(0, 10))
  await page.getByLabel('What you found').fill('About 500 metres of loose gravel; walking pace was fine on a 200cc bike.')
  await page.getByRole('button', { name: 'Send for review' }).click()
  await expect(page.getByText('Sent for review.')).toBeVisible()

  await page.goto('/account')
  const mine = page.getByRole('list', { name: 'Your contributions' })
  await expect(mine.getByText('Loose gravel after the second bridge')).toBeVisible()
  await expect(mine.getByText('Waiting for review')).toBeVisible()
  // Not public until a moderator publishes it.
  expect(sql(`select status from contributions where title = 'Loose gravel after the second bridge' and author_id = '${rider.id}'`)).toBe('pending_review')
})

test('a verified organizer publishes a capped event; riders join, the waitlist moves up', async ({ page, browser }, info) => {
  const org = await createConfirmedRider(`organizer-${info.project.name}`, password)
  sql(`insert into user_verifications (id, user_id, kind, scope, status, statement, created_at, updated_at)
       values (gen_random_uuid(), '${org.id}', 'event_organizer', '', 'approved', 'E2E organizer verification set up by the test suite.', now(), now())`)
  await signIn(page, org.email)
  await page.goto('/events')
  await page.getByRole('link', { name: 'Host an event' }).click()
  await page.getByLabel('Type').fill('weekend ride')
  await page.getByLabel('Title').fill(`E2E capped ride ${info.project.name}`)
  await page.getByLabel('Description').fill('A test event created by the automated suite; not a real ride.')
  const start = new Date(Date.now() + 7 * 86_400_000)
  const local = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
  await page.getByLabel('Starts').fill(local(start))
  await page.getByLabel('Ends').fill(local(new Date(start.getTime() + 6 * 3_600_000)))
  await page.getByLabel('Meeting point').fill('Test meeting point')
  await page.getByLabel('Places').fill('1')
  await page.getByLabel('Keep a waitlist when full').check()
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page).toHaveURL(/\/events\/.+\/manage$/)
  await page.getByLabel('New status').selectOption('published')
  await page.getByRole('button', { name: 'Apply' }).click()
  await expect(page.getByText('Status changed.')).toBeVisible()
  const eventUrl = page.url().replace(/\/manage$/, '')

  const joinAs = async (label: string) => {
    const ctx = await browser.newContext()
    const p = await ctx.newPage()
    const r = await createConfirmedRider(`${label}-${info.project.name}`, password)
    await signIn(p, r.email)
    await p.goto(eventUrl)
    await p.getByRole('button', { name: 'Join this event' }).click()
    return { ctx, p }
  }
  const first = await joinAs('first')
  await expect(first.p.getByText('You are going.')).toBeVisible()
  const second = await joinAs('second')
  await expect(second.p.getByText('You are on the waitlist')).toBeVisible()

  // The first rider leaves: the waitlisted rider moves up automatically.
  await first.p.getByRole('button', { name: 'Leave event' }).click()
  await expect(first.p.getByText('You are no longer registered.')).toBeVisible()
  await second.p.reload()
  await expect(second.p.getByText('Your status: Going')).toBeVisible()
  await first.ctx.close()
  await second.ctx.close()
})

test('a group trip: the owner plans, a viewer can read but not change it', async ({ page, browser }, info) => {
  const owner = await createConfirmedRider(`owner-${info.project.name}`, password)
  const viewerName = `viewer_${info.project.name.slice(0, 6)}_${Date.now() % 1e6}`
  const viewer = await createConfirmedRider(`viewer-${info.project.name}`, password, viewerName)
  await signIn(page, owner.email)
  await page.goto('/rides')
  await page.getByLabel('New trip').fill('E2E Mustang plan')
  await page.getByRole('button', { name: 'Create' }).click()
  await expect(page.getByRole('heading', { name: 'E2E Mustang plan' })).toBeVisible()

  await page.getByLabel('Stop', { exact: true }).fill('Beni')
  await page.getByLabel('Day', { exact: true }).fill('1')
  await page.getByRole('button', { name: 'Add' }).first().click()
  await expect(page.getByText('Day 1: Beni')).toBeVisible()
  await page.getByLabel('Item', { exact: true }).first().fill('Fuel at Beni')
  await page.getByLabel('Amount (NPR)').fill('2500')
  await page.getByRole('button', { name: 'Add' }).nth(1).click()
  await expect(page.getByText('NPR 2,500').first()).toBeVisible()
  await page.getByLabel('Username to add').fill(viewerName)
  await page.getByLabel('Role').selectOption('viewer')
  await page.getByRole('button', { name: 'Add member' }).click()
  await expect(page.getByText('E2E viewer-')).toBeVisible()
  const tripUrl = page.url()

  const ctx = await browser.newContext()
  const p = await ctx.newPage()
  await signIn(p, viewer.email)
  await p.goto(tripUrl)
  await expect(p.getByText('(read only)')).toBeVisible()
  await expect(p.getByText('Day 1: Beni')).toBeVisible()
  await expect(p.getByRole('button', { name: 'Add' })).toHaveCount(0)
  await ctx.close()

  // A rider who is not a member gets nothing.
  const outsider = await createConfirmedRider(`outsider-${info.project.name}`, password)
  const ctx2 = await browser.newContext()
  const p2 = await ctx2.newPage()
  await signIn(p2, outsider.email)
  await p2.goto(tripUrl)
  await expect(p2.getByText('Only members of a trip can open it.')).toBeVisible()
  await ctx2.close()
})
