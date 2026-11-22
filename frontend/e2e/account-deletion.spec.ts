import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 25: account deletion and export as a rider uses them (Q9 option D, D-01, D-03). The final run is the real
// daily command against the isolated E2E database, after moving this rider's date into the past.
const password = 'deletion-journey-2026'
const web = fileURLToPath(new URL('../../', import.meta.url))
const runDeletions = (userId: string) => {
  sql(`update account_deletion_requests set run_after = now() - interval '1 minute' where user_id = '${userId}' and status = 'scheduled'`)
  execFileSync('php', ['artisan', 'mrn:accounts:process-deletions'], { cwd: web, env: { ...process.env, DB_DATABASE: 'myridenepal_e2e', LOCAL_DISK_ROOT: 'app/e2e' }, encoding: 'utf8' })
}

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

test('a rider exports their data, schedules the deletion with a choice, signs in again, cancels, and finally is deleted', async ({ page }, info) => {
  const s = `${info.project.name}-${Date.now()}`
  const rider = await createConfirmedRider(`del-${info.project.name}`, password, `del_${info.project.name}_${Date.now() % 1e6}`)
  const title = `Washed-out ford ${s}`
  const c = sql(`insert into contributions (id, author_id, kind, title, body, observed_on, status, details, created_at, updated_at)
     values (gen_random_uuid(), '${rider.id}', 'observation', '${title}', 'The ford below the village was knee deep after the rain; wait for the morning.', current_date - 2, 'published', '{}', now(), now()) returning id`)

  await signIn(page, rider.email)
  await page.goto('/account#delete')
  const section = page.locator('#delete')

  // Export: password required, then a ZIP download.
  await section.locator('#export_password').fill('not-the-password')
  await section.getByRole('button', { name: 'Download my data' }).click()
  await expect(section.getByText('That password is not correct.')).toBeVisible()
  await section.locator('#export_password').fill(password)
  const [download] = await Promise.all([page.waitForEvent('download'), section.getByRole('button', { name: 'Download my data' }).click()])
  expect(download.suggestedFilename()).toMatch(/^my-ride-nepal-data-\d{4}-\d{2}-\d{2}\.zip$/)
  expect(readFileSync(await download.path()).subarray(0, 2).toString()).toBe('PK')
  await expect(section.getByText('Your download has started.')).toBeVisible()

  // Request: nothing is pre-selected and a choice is required.
  await section.getByRole('button', { name: 'Delete my account' }).click()
  const radios = section.getByRole('radio')
  await expect(radios).toHaveCount(3)
  for (const r of await radios.all()) await expect(r).not.toBeChecked()
  await section.locator('#delete_password').fill(password)
  await section.getByRole('checkbox').check()
  await section.getByRole('button', { name: 'Schedule the deletion' }).click()
  await expect(section.getByText('1 contribution still needs a choice.')).toBeVisible()
  await section.getByRole('radio', { name: /^Keep anonymously Stays on the site/ }).check()
  await section.getByRole('button', { name: 'Schedule the deletion' }).click()
  await expect(section.getByText(/Your account will be deleted on/)).toBeVisible()
  await expect(page.getByRole('alert').filter({ hasText: 'Your account will be deleted on' })).toBeVisible()
  expect(sql(`select status from account_deletion_requests where user_id = '${rider.id}'`)).toBe('scheduled')

  // Taking part stops; signing out and in again does not cancel.
  await page.goto(`/riders/${sql(`select username from users where id = '${rider.id}'`)}`)
  await expect(page.getByText(/not found|could not be found|no rider/i).first()).toBeVisible()
  await page.goto('/account')
  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL(/\/$/) // the sign-out finished
  await signIn(page, rider.email)
  await expect(page.locator('#delete').getByText(/Your account will be deleted on/)).toBeVisible()

  // Explicit cancel.
  await page.locator('#cancel_password').fill(password)
  await page.getByRole('button', { name: 'Cancel deletion' }).click()
  await expect(page.getByText('Deletion cancelled. Your account works as before.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Delete my account' })).toBeVisible()
  expect(sql(`select status from account_deletion_requests where user_id = '${rider.id}'`)).toBe('cancelled')

  // Schedule again and let the daily run do it: the account goes, the contribution stays as "Former rider".
  await page.getByRole('button', { name: 'Delete my account' }).click()
  await page.getByRole('radio', { name: /^Keep anonymously Stays on the site/ }).check()
  await page.getByRole('checkbox').check()
  await page.locator('#delete_password').fill(password)
  await page.getByRole('button', { name: 'Schedule the deletion' }).click()
  await expect(page.locator('#delete').getByText(/Your account will be deleted on/)).toBeVisible()
  runDeletions(rider.id)
  expect(sql(`select count(*) from users where id = '${rider.id}'`)).toBe('0')
  expect(sql(`select author_id is null and author_deleted_at is not null from contributions where id = '${c}'`)).toBe('t')
  await page.goto(`/experiences/${c}`)
  await expect(page.getByRole('heading', { name: title })).toBeVisible()
  await expect(page.getByText('Former rider').first()).toBeVisible()
  await page.goto('/account')
  await expect(page).toHaveURL(/\/sign-in/)
  sql(`delete from contributions where id = '${c}'`)
})

test('a group trip goes to the member who accepts the offer', async ({ browser }, info) => {
  const owner = await createConfirmedRider(`tripown-${info.project.name}`, password, `tripown_${info.project.name}_${Date.now() % 1e6}`)
  const member = await createConfirmedRider(`tripmem-${info.project.name}`, password, `tripmem_${info.project.name}_${Date.now() % 1e6}`)
  const tripTitle = `Handover loop ${info.project.name}`
  const trip = sql(`insert into trips (id, owner_id, title, created_at, updated_at) values (gen_random_uuid(), '${owner.id}', '${tripTitle}', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${trip}', '${owner.id}', 'owner', now(), now()), ('${trip}', '${member.id}', 'viewer', now(), now())`)

  const ownerPage = await (await browser.newContext()).newPage()
  await signIn(ownerPage, owner.email)
  await ownerPage.goto('/account#delete')
  await ownerPage.getByRole('button', { name: 'Delete my account' }).click()
  await expect(ownerPage.getByText(/own\s+1\s+group trip/)).toBeVisible()
  await ownerPage.getByRole('checkbox').check()
  await ownerPage.locator('#delete_password').fill(password)
  await ownerPage.getByRole('button', { name: 'Schedule the deletion' }).click()
  await ownerPage.getByLabel('Member to offer it to').selectOption({ label: 'E2E tripmem-' + info.project.name + ' (viewer)' })
  await ownerPage.getByRole('button', { name: 'Offer the trip' }).click()
  await expect(ownerPage.getByText(/waiting for an answer/)).toBeVisible()
  expect(sql(`select owner_id from trips where id = '${trip}'`)).toBe(owner.id)

  const memberPage = await (await browser.newContext()).newPage()
  await signIn(memberPage, member.email)
  const offers = memberPage.locator('#offers')
  await expect(offers.getByText(tripTitle)).toBeVisible()
  await offers.getByRole('button', { name: `Accept ${tripTitle}` }).click()
  await expect(offers.getByText('It is yours now.')).toBeVisible()
  expect(sql(`select owner_id from trips where id = '${trip}'`)).toBe(member.id)

  runDeletions(owner.id)
  expect(sql(`select count(*) from users where id = '${owner.id}'`)).toBe('0')
  expect(sql(`select role from trip_members where trip_id = '${trip}' and user_id = '${member.id}'`)).toBe('owner')
  await memberPage.goto(`/rides/${trip}`)
  await expect(memberPage.getByRole('heading', { name: tripTitle })).toBeVisible()
})
