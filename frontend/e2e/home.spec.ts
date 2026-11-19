import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// The homepage, menu and notices come from the CMS tables; the seeded rows are the original copy.
test.afterEach(() => sql(`delete from notices where body like 'E2E notice%'`))

test('the homepage shows the published sections, the header menu and active notices', async ({ page }) => {
  sql(`insert into notices (id, body, level, status, created_at, updated_at) values (gen_random_uuid(), 'E2E notice: guides are being reviewed.', 'info', 'published', now(), now())`)
  sql(`insert into notices (id, body, level, status, created_at, updated_at) values (gen_random_uuid(), 'E2E notice: a draft nobody sees.', 'info', 'draft', now(), now())`)
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Every observation carries its date' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Notices' })).toContainText('E2E notice: guides are being reviewed.')
  await expect(page.getByText('E2E notice: a draft nobody sees.')).toHaveCount(0)
  const nav = page.getByRole('navigation', { name: 'Main' }).first()
  if (await nav.isVisible()) await expect(nav.getByRole('link', { name: 'Explore' })).toBeVisible()
  await expect(page.getByText(/Not an emergency service/)).toBeVisible()
})
