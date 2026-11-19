import { expect, test, type Page } from '@playwright/test'
import { API_URL, MAILPIT_URL, TEST_EMAIL_DOMAIN, createConfirmedRider, sql } from './support/local'

// Stage 1 screens over endpoints that already existed: my events, editing a pending contribution,
// resending the confirmation email, and the destination page's services and rider reports.
const password = 'rider-tools-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

async function signupEmails(to: string): Promise<number> {
  const r = await fetch(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(`to:"${to}" subject:"Confirm your email"`)}`).then((x) => x.json())
  return r.messages?.length ?? 0
}

test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E %'`)
  sql(`delete from destinations where slug like 'e2e-place-%'`)
})

test('riders see the events they host and the ones they joined', async ({ page, browser }, info) => {
  const org = await createConfirmedRider(`host-${info.project.name}`, password)
  sql(`insert into user_verifications (id, user_id, kind, scope, status, statement, created_at, updated_at)
       values (gen_random_uuid(), '${org.id}', 'event_organizer', '', 'approved', 'Approved for the E2E suite only.', now(), now())`)
  const title = `E2E my-events ride ${stamp()}`
  const eventId = sql(`insert into events (id, organizer_id, category, subtype, title, description, starts_at, ends_at, meeting_point, visibility, join_method, waitlist_enabled, status, created_at, updated_at)
       values (gen_random_uuid(), '${org.id}', 'ride', 'day ride', '${title}', 'An event created only for the E2E suite.', now() + interval '7 days', now() + interval '7 days 5 hours',
               'Test meeting point', 'public', 'instant', false, 'published', now(), now()) returning id`)
  const rider = await createConfirmedRider(`joiner-${info.project.name}`, password)
  sql(`insert into event_registrations (id, event_id, user_id, status, created_at, updated_at) values (gen_random_uuid(), '${eventId}', '${rider.id}', 'approved', now(), now())`)

  await signIn(page, rider.email)
  await page.goto('/events')
  const taking = page.getByRole('list', { name: 'Taking part' })
  await expect(taking.getByRole('link', { name: title })).toBeVisible()
  await expect(taking.getByText('Going')).toBeVisible()

  // The host, in a separate browser session.
  const ctx = await browser.newContext()
  const host = await ctx.newPage()
  await signIn(host, org.email)
  await host.goto('/events')
  await expect(host.getByRole('list', { name: 'Taking part' })).toHaveCount(0)
  await host.getByRole('list', { name: 'Hosting' }).getByRole('link', { name: title }).click()
  await expect(host).toHaveURL(new RegExp(`/events/${eventId}/manage$`))
  await ctx.close()
})

test('an author edits a contribution while it waits for review, then it locks', async ({ page }, info) => {
  const author = await createConfirmedRider(`editor-${info.project.name}`, password)
  const title = `E2E pending note ${stamp()}`
  const id = sql(`insert into contributions (id, author_id, kind, title, body, observed_on, created_at, updated_at)
       values (gen_random_uuid(), '${author.id}', 'observation', '${title}', 'A pending observation created only for the E2E suite.', current_date - 2, now(), now()) returning id`)

  await signIn(page, author.email)
  await page.getByRole('link', { name: `Edit ${title}` }).click()
  await expect(page.getByRole('heading', { name: 'Edit your contribution' })).toBeVisible()
  await expect(page.getByLabel('Title')).toHaveValue(title)
  await page.getByLabel('Title').fill(`${title} (corrected)`)
  await page.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Changes saved' })).toBeVisible()
  expect(sql(`select title from contributions where id = '${id}'`)).toBe(`${title} (corrected)`)
  expect(sql(`select count(*) from audit_logs where action = 'contribution.edited' and subject_id = '${id}'`)).toBe('1')

  sql(`update contributions set status = 'published' where id = '${id}'`)
  await page.goto(`/experiences/${id}/edit`)
  await expect(page.getByText('has already been reviewed')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0)
})

test('an unconfirmed rider asks for a new confirmation link from sign-up and sign-in', async ({ page }, info) => {
  const email = `resend-${info.project.name}-${stamp()}@${TEST_EMAIL_DOMAIN}`
  await page.goto('/sign-up')
  await page.getByLabel('Name shown to other riders').fill('E2E Resend')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByText('Check your email.')).toBeVisible()
  await expect.poll(() => signupEmails(email)).toBe(1)

  await page.getByRole('button', { name: 'Send a new confirmation link' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'a new link is on its way' })).toBeVisible()
  await expect.poll(() => signupEmails(email)).toBe(2)

  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('alert')).toContainText('Confirm your email first')
  await page.getByRole('button', { name: 'Send a new confirmation link' }).click()
  await expect.poll(() => signupEmails(email)).toBe(3)

  // A wrong password never reveals the account or offers the button.
  await page.getByLabel('Password', { exact: true }).fill('not-the-password')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('alert')).toHaveText('Email or password is incorrect.')
  await expect(page.getByRole('button', { name: 'Send a new confirmation link' })).toHaveCount(0)
})

test('a destination page shows its services and recent rider reports; unknown places say so', async ({ page }, info) => {
  const slug = `e2e-place-${info.project.name}-${stamp()}`
  const name = `E2E Place ${info.project.name}`
  const destId = sql(`insert into destinations (id, slug, name, district, status, created_at, updated_at) values (gen_random_uuid(), '${slug}', '${name}', 'E2E District', 'published', now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, destination_id, status, last_confirmed_on, created_at, updated_at)
       values (gen_random_uuid(), 'fuel', 'E2E Fuel stop', '${destId}', 'published', current_date - 30, now(), now()),
              (gen_random_uuid(), 'mechanic', 'E2E Draft workshop', '${destId}', 'draft', null, now(), now())`)
  const author = await createConfirmedRider(`place-${info.project.name}`, password)
  const report = `E2E report for ${name}`
  sql(`insert into contributions (id, author_id, kind, title, body, observed_on, destination_id, status, created_at, updated_at)
       values (gen_random_uuid(), '${author.id}', 'observation', '${report}', 'A published observation created only for the E2E suite.', current_date - 5, '${destId}', 'published', now(), now()),
              (gen_random_uuid(), '${author.id}', 'observation', 'E2E pending not shown', 'A pending observation created only for the E2E suite.', current_date - 5, '${destId}', 'pending_review', now(), now())`)

  // Browse and search on Explore, then open the place.
  await page.goto('/explore')
  await page.getByLabel('Search destinations').fill('E2E District')
  await page.getByRole('link', { name: new RegExp(name) }).first().click()
  await expect(page).toHaveURL(new RegExp(`/explore/${slug}$`))

  const services = page.getByRole('list', { name: 'Services' })
  await expect(services.getByText('E2E Fuel stop')).toBeVisible()
  await expect(services.getByText('Last confirmed')).toBeVisible()
  await expect(page.getByText('E2E Draft workshop')).toHaveCount(0)
  const reports = page.getByRole('list', { name: 'Recent rider reports' })
  await expect(reports.getByRole('link', { name: report })).toBeVisible()
  await expect(page.getByText('E2E pending not shown')).toHaveCount(0)
  await reports.getByRole('link', { name: report }).click()
  await expect(page.getByRole('heading', { name: report })).toBeVisible()

  await page.goto('/explore/e2e-no-such-place')
  await expect(page.getByRole('heading', { name: 'Destination not found' })).toBeVisible()
  // The API agrees: nothing is published there.
  expect((await fetch(`${API_URL}/api/v1/destinations/e2e-no-such-place`, { headers: { accept: 'application/json' } })).status).toBe(404)
})

test('approval joining: the organizer approves and the rider is notified', async ({ page, browser }, info) => {
  const org = await createConfirmedRider(`approver-${info.project.name}`, password)
  sql(`insert into user_verifications (id, user_id, kind, scope, status, statement, created_at, updated_at)
       values (gen_random_uuid(), '${org.id}', 'event_organizer', '', 'approved', 'Approved for the E2E suite only.', now(), now())`)
  const title = `E2E approval ride ${stamp()}`
  const eventId = sql(`insert into events (id, organizer_id, category, subtype, title, description, starts_at, ends_at, meeting_point, visibility, join_method, waitlist_enabled, status, created_at, updated_at)
       values (gen_random_uuid(), '${org.id}', 'ride', 'day ride', '${title}', 'An event created only for the E2E suite.', now() + interval '7 days', now() + interval '7 days 5 hours',
               'Test meeting point', 'public', 'approval', false, 'published', now(), now()) returning id`)
  const rider = await createConfirmedRider(`requester-${info.project.name}`, password)

  await signIn(page, rider.email)
  await page.goto(`/events/${eventId}`)
  await page.getByRole('button', { name: 'Request to join' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Request sent' })).toBeVisible()

  const ctx = await browser.newContext()
  const host = await ctx.newPage()
  await signIn(host, org.email)
  await host.goto(`/events/${eventId}/manage`)
  await host.getByRole('button', { name: 'Approve' }).click()
  await expect.poll(() => sql(`select status from event_registrations where event_id = '${eventId}' and user_id = '${rider.id}'`)).toBe('approved')
  await ctx.close()

  await page.goto('/account')
  await expect(page.getByRole('link', { name: `The organizer approved your place on ${title}.` })).toBeVisible()
})
