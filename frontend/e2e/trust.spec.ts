import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Stage 4 on the public site: "still accurate", categorized reports, and the author's correction.
const password = 'trust-pipeline-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`

async function signIn(page: Page, email: string) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

test('riders confirm and report a published contribution; the author adds a correction', async ({ browser }, info) => {
  const author = await createConfirmedRider(`trust-a-${info.project.name}`, password)
  const other = await createConfirmedRider(`trust-b-${info.project.name}`, password)
  const title = `E2E trust note ${stamp()}`
  const id = sql(`insert into contributions (id, author_id, kind, title, body, observed_on, status, created_at, updated_at)
       values (gen_random_uuid(), '${author.id}', 'observation', '${title}', 'A published observation created only for the E2E suite.', current_date - 2, 'published', now(), now()) returning id`)

  const riderPage = await (await browser.newContext()).newPage()
  await signIn(riderPage, other.email)
  await riderPage.goto(`/experiences/${id}`)
  await riderPage.getByRole('button', { name: /still accurate/ }).click()
  await expect(riderPage.getByText('Marked as still accurate today')).toBeVisible()
  await expect(riderPage.getByText(/still accurate on/)).toBeVisible()
  await riderPage.getByRole('button', { name: 'Report a problem' }).click()
  await riderPage.getByLabel('What kind of problem?').selectOption('dangerous')
  await riderPage.getByLabel('What is wrong?').fill('Suggests crossing the river where it floods.')
  await riderPage.getByRole('button', { name: 'Send to moderators' }).click()
  await expect(riderPage.getByText('Moderators will look at it')).toBeVisible()
  expect(sql(`select category from contribution_reports where contribution_id = '${id}'`)).toBe('dangerous')

  const authorPage = await (await browser.newContext()).newPage()
  await signIn(authorPage, author.email)
  await authorPage.goto(`/experiences/${id}`)
  await expect(authorPage.getByRole('button', { name: 'Report a problem' })).toHaveCount(0)
  await authorPage.getByRole('button', { name: 'Add a correction' }).click()
  await authorPage.getByLabel('Your correction').fill('The ford is only safe in the dry season.')
  await authorPage.getByRole('button', { name: 'Send correction' }).click()
  await expect(authorPage.getByText('only you see it')).toBeVisible()

  // Not public until a moderator has seen it.
  const guest = await (await browser.newContext()).newPage()
  await guest.goto(`/experiences/${id}`)
  await expect(guest.getByRole('heading', { name: title })).toBeVisible()
  await expect(guest.getByText('The ford is only safe in the dry season.')).toHaveCount(0)
  sql(`update contributions set author_correction_reviewed_at = now() where id = '${id}'`)
  await guest.reload()
  await expect(guest.getByText('The ford is only safe in the dry season.')).toBeVisible()
})
