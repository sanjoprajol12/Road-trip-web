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

// Session 21: the homepage shows the product working, with real published data only.
test('the homepage planner opens Explore Along My Route and previews a real published route', async ({ page }, info) => {
  const s = `${info.project.name}-${Date.now()}`
  sql(`insert into destinations (id, slug, name, status, is_dev_seed, created_at, updated_at) values
    (gen_random_uuid(), 'e2e-home-a-${s}', 'E2E Home Start ${s}', 'published', true, now(), now()),
    (gen_random_uuid(), 'e2e-home-b-${s}', 'E2E Home End ${s}', 'published', true, now(), now())`)
  const r = sql(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), 'e2e-home-${s}', 'E2E Home Route ${s}', 'published', now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, created_at, updated_at) values
    (gen_random_uuid(), '${r}', 1, 'Alpha', 'Bravo', 25, 1, 'paved', now(), now()) returning id`)
  sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, created_at, updated_at) values (gen_random_uuid(), '${r}', 2, 'Bravo', 'Charlie', 35, 2, 'gravel', now(), now())`)
  sql(`insert into service_points (id, kind, name, route_segment_id, status, created_at, updated_at) values (gen_random_uuid(), 'fuel', 'E2E Home Pump ${s}', '${seg}', 'published', now(), now())`)
  try {
    await page.goto('/')
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Plan the ride')
    await expect(page.getByRole('link', { name: 'Plan a route' })).toHaveAttribute('href', '/journey')

    // The preview is the real route, with its recorded numbers and the full plan one click away.
    const preview = page.getByRole('figure', { name: `Plan of E2E Home Route ${s}` })
    await expect(preview.getByRole('list', { name: /Stretches of/ }).getByRole('listitem')).toHaveCount(2)
    await expect(preview).toContainText('60 km · about 3 h (estimate)')
    await expect(preview.getByRole('listitem').first()).toContainText('fuel')
    const planner = page.getByRole('form', { name: 'Plan a route' })
    await planner.getByLabel('Start', { exact: true }).selectOption({ label: `E2E Home Start ${s}` })
    await planner.getByLabel('Destination', { exact: true }).selectOption({ label: `E2E Home Start ${s}` })
    await expect(planner.getByRole('alert')).toHaveText('Choose two different places.')
    await expect(planner.getByRole('button', { name: 'Show routes and stops' })).toBeDisabled()
    await planner.getByLabel('Destination', { exact: true }).selectOption({ label: `E2E Home End ${s}` })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
    // Screenshots once the reveal animation has settled.
    const plannerHeading = page.getByRole('heading', { name: 'Start with where you are going' })
    await plannerHeading.scrollIntoViewIfNeeded()
    await expect(plannerHeading).toHaveCSS('opacity', '1')
    await page.screenshot({ path: `test-results/home-planner-${info.project.name}.png` })
    const toolsHeading = page.getByRole('heading', { name: 'Everything the ride needs, in one plan' })
    await toolsHeading.scrollIntoViewIfNeeded()
    await expect(toolsHeading).toHaveCSS('opacity', '1')
    await expect(page.getByRole('region', { name: 'Everything the ride needs, in one plan' }).getByRole('listitem').first()).toHaveCSS('opacity', '1')
    await page.screenshot({ path: `test-results/home-tools-${info.project.name}.png` })
    const tools = page.getByRole('region', { name: 'Everything the ride needs, in one plan' }).getByRole('listitem')
    await expect(tools).toHaveCount(6)
    await expect(tools.nth(1).getByRole('link', { name: 'Plan a trip' })).toHaveAttribute('href', '/rides')

    await planner.getByRole('button', { name: 'Show routes and stops' }).click()
    await expect(page).toHaveURL(new RegExp(`/journey\\?from=e2e-home-a-${s}&to=e2e-home-b-${s}`))
    await expect(page.getByLabel('Start', { exact: true })).toHaveValue(`e2e-home-a-${s}`)

    await page.goto('/')
    await page.getByRole('link', { name: 'Open the full plan' }).click()
    await expect(page).toHaveURL(new RegExp(`/routes/e2e-home-${s}$`))
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`E2E Home Route ${s}`)
  } finally {
    sql(`delete from service_points where name like 'E2E Home Pump%'`)
    sql(`delete from routes where slug like 'e2e-home-%'`)
    sql(`delete from destinations where slug like 'e2e-home-%'`)
  }
})
