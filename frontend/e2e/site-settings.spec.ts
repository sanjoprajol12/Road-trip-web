import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// Site settings staff save in the staff portal (tested there against the API) must show on the public website.
// The settings are written straight into the isolated E2E database here, then the API's settings cache is cleared.
function setSettings(rows: Record<string, unknown>) {
  for (const [key, value] of Object.entries(rows)) {
    sql(`insert into settings (key, value, updated_at) values ('${key}', '${JSON.stringify(value).replaceAll("'", "''")}'::jsonb, now())
         on conflict (key) do update set value = excluded.value, updated_at = now()`)
  }
  sql(`delete from cache where key like '%mrn.settings'`)
}
function resetSettings() {
  sql(`delete from settings where key in ('maintenance.enabled', 'maintenance.message', 'registration.open', 'registration.closed_message')`)
  sql(`delete from cache where key like '%mrn.settings'`)
}
test.beforeAll(resetSettings)
test.afterEach(resetSettings)

test('maintenance mode shows only the message to visitors', async ({ page }) => {
  setSettings({ 'maintenance.enabled': true, 'maintenance.message': 'Back after a short E2E maintenance check.' })
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Back soon' })).toBeVisible()
  await expect(page.getByText('Back after a short E2E maintenance check.')).toBeVisible()
  await page.goto('/explore')
  await expect(page.getByRole('heading', { name: 'Back soon' })).toBeVisible()
})

test('closed sign-up shows the message instead of the form', async ({ page }) => {
  setSettings({ 'registration.open': false, 'registration.closed_message': 'Sign-up is paused for an E2E check.' })
  await page.goto('/sign-up')
  await expect(page.getByText('Sign-up is paused for an E2E check.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Create account' })).toHaveCount(0)
})
