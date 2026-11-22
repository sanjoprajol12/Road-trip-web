import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// B8: services along a route on the map and in the stretch list. Only published services; only recorded positions get
// a marker; a marker opens that service's details; no road line is drawn without a recorded track.
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E Svc %'`)
  sql(`delete from routes where slug like 'e2e-svc-%'`)
})

function seed(s: string, withTrack: boolean) {
  const r = sql(`insert into routes (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), 'e2e-svc-${withTrack ? 'track' : 'plain'}-${s}', 'E2E Svc Road ${s}', 'published', now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, path, created_at, updated_at)
    values (gen_random_uuid(), '${r}', 1, 'North gate', 'South gate', 'paved', 28.0, 84.0, 28.2, 84.2, ${withTrack ? `'[[28.0,84.0],[28.1,84.05],[28.2,84.2]]'` : 'null'}, now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, notes, route_segment_id, latitude, longitude, status, last_confirmed_on, created_at, updated_at) values
    (gen_random_uuid(), 'fuel', 'E2E Svc Pump ${s}', 'Petrol only.', '${seg}', 28.05, 84.02, 'published', current_date - 3, now(), now()),
    (gen_random_uuid(), 'mechanic', 'E2E Svc Workshop ${s}', null, '${seg}', 28.15, 84.15, 'published', null, now(), now()),
    (gen_random_uuid(), 'medical', 'E2E Svc Draft Clinic ${s}', null, '${seg}', 28.1, 84.1, 'draft', null, now(), now()),
    (gen_random_uuid(), 'food', 'E2E Svc Tea House ${s}', null, '${seg}', null, null, 'published', null, now(), now())`)
}

test('service markers match the published services and open their details', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seed(s, true)
  await page.goto(`/routes/e2e-svc-track-${s}`)
  const map = page.getByRole('img', { name: /Map of the route/ })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  const markers = map.locator('path.leaflet-interactive[stroke="#285a8c"]')
  await expect(markers).toHaveCount(2) // pump and workshop; not the draft clinic, not the tea house without a position
  await expect(map.locator('path.leaflet-interactive[stroke-width="5"]')).toHaveCount(1) // the recorded track only

  // Each marker opens the details of its own service (desktop: click; phone profile: tap).
  const seen: string[] = []
  for (let i = 0; i < 2; i++) {
    const marker = markers.nth(i)
    if (info.project.name === 'mobile') await marker.tap()
    else await marker.click()
    const popup = page.getByTestId('service-popup')
    await expect(popup).toBeVisible()
    seen.push((await popup.textContent()) ?? '')
    await page.locator('.leaflet-popup-close-button').click()
    await expect(page.getByTestId('service-popup')).toHaveCount(0) // closed (Leaflet fades it out) before the next one
  }
  const pump = seen.find((t) => t.includes(`E2E Svc Pump ${s}`))
  const workshop = seen.find((t) => t.includes(`E2E Svc Workshop ${s}`))
  expect(pump).toContain('Fuel · North gate to South gate')
  expect(pump).toContain('Petrol only.')
  expect(pump).toContain('Last confirmed')
  expect(workshop).toContain('Repairs')
  expect(workshop).toContain('Never confirmed')
  await map.scrollIntoViewIfNeeded()
  await page.screenshot({ path: `test-results/route-services-${info.project.name}.png` })

  // The same information without the map: the stretch list names every published service with its kind.
  const list = page.getByRole('list', { name: 'Services along this stretch' })
  await expect(list).toContainText(`Fuel:E2E Svc Pump ${s}`)
  await expect(list).toContainText(`Repairs:E2E Svc Workshop ${s}`)
  await expect(list).toContainText(`E2E Svc Tea House ${s}`)
  await expect(list).toContainText('no map position')
  await expect(page.getByText(`E2E Svc Draft Clinic ${s}`)).toHaveCount(0)
})

test('without a recorded track, services and checkpoints show but no road line is drawn', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  seed(s, false)
  await page.goto(`/routes/e2e-svc-plain-${s}`)
  const map = page.getByRole('img', { name: /Map of the route/ })
  await expect(map.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 15_000 })
  await expect(map.locator('path.leaflet-interactive[stroke="#285a8c"]')).toHaveCount(2)
  await expect(map.locator('path.leaflet-interactive[stroke-width="5"]')).toHaveCount(0)
  await expect(page.getByText('No recorded track for this route yet')).toBeVisible()
})
