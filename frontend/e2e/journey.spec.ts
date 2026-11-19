import { expect, test } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Explore Along My Route (curated mode): choose start and destination, see the route, filter what is near it.
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`

test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E Journey %'`)
  sql(`delete from routes where slug like 'e2e-journey-%'`)
  sql(`delete from destinations where slug like 'e2e-journey-%'`)
})

test('a guest explores along a route between two places', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  const a = sql(`insert into destinations (id, slug, name, status, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-a-${s}', 'E2E Journey Start ${s}', 'published', 28.0, 84.0, now(), now()) returning id`)
  const b = sql(`insert into destinations (id, slug, name, status, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-b-${s}', 'E2E Journey End ${s}', 'published', 28.0, 85.0, now(), now()) returning id`)
  const r = sql(`insert into routes (id, slug, name, status, start_destination_id, end_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-r-${s}', 'E2E Journey road ${s}', 'published', '${a}', '${b}', now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at)
       values (gen_random_uuid(), '${r}', 1, 'Start town', 'End town', 'paved', 28.0, 84.0, 28.0, 85.0, now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, route_segment_id, status, created_at, updated_at) values (gen_random_uuid(), 'fuel', 'E2E Journey pump', '${seg}', 'published', now(), now())`)
  sql(`insert into service_points (id, kind, name, destination_id, latitude, longitude, status, created_at, updated_at) values (gen_random_uuid(), 'mechanic', 'E2E Journey workshop', '${a}', 28.02, 84.3, 'published', now(), now())`)

  await page.goto('/routes')
  await page.getByRole('link', { name: 'Explore along my route' }).click()
  await page.getByLabel('Start', { exact: true }).selectOption({ label: `E2E Journey Start ${s}` })
  await page.getByLabel('Destination', { exact: true }).selectOption({ label: `E2E Journey End ${s}` })
  await page.getByRole('button', { name: 'Show routes' }).click()

  await expect(page.getByRole('heading', { name: `E2E Journey road ${s}` })).toBeVisible()
  const list = page.getByRole('list', { name: 'Places along the route' })
  await expect(list.getByText('E2E Journey pump')).toBeVisible()
  await expect(list.getByRole('listitem').filter({ hasText: 'E2E Journey pump' })).toContainText('On this route')
  await expect(list.getByRole('listitem').filter({ hasText: 'E2E Journey workshop' })).toContainText('km from the route line')

  await page.getByRole('group', { name: 'Filter places' }).getByRole('button', { name: 'Repairs' }).click()
  await expect(list.getByText('E2E Journey pump')).toHaveCount(0)
  await expect(list.getByText('E2E Journey workshop')).toBeVisible()

  // Reloading the address keeps the journey; swapping finds the same route in reverse.
  await page.reload()
  await expect(page.getByRole('heading', { name: `E2E Journey road ${s}` })).toBeVisible()
  await page.getByRole('button', { name: 'Swap start and destination' }).click()
  await page.getByRole('button', { name: 'Show routes' }).click()
  await expect(page.getByText('Recorded in the other direction')).toBeVisible()
})

test('a rider adds a service from the journey page to a trip, once', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  const a = sql(`insert into destinations (id, slug, name, status, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-a-${s}', 'E2E Journey Start ${s}', 'published', 28.0, 84.0, now(), now()) returning id`)
  const b = sql(`insert into destinations (id, slug, name, status, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-b-${s}', 'E2E Journey End ${s}', 'published', 28.0, 85.0, now(), now()) returning id`)
  const r = sql(`insert into routes (id, slug, name, status, start_destination_id, end_destination_id, created_at, updated_at) values (gen_random_uuid(), 'e2e-journey-r-${s}', 'E2E Journey road ${s}', 'published', '${a}', '${b}', now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, surface, start_lat, start_lng, end_lat, end_lng, created_at, updated_at)
       values (gen_random_uuid(), '${r}', 1, 'Start town', 'End town', 'paved', 28.0, 84.0, 28.0, 85.0, now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, route_segment_id, status, created_at, updated_at) values (gen_random_uuid(), 'fuel', 'E2E Journey pump', '${seg}', 'published', now(), now())`)
  const password = 'journey-trip-2026'
  const rider = await createConfirmedRider(`journey-${info.project.name}`, password)
  const trip = `E2E Journey trip ${s}`
  const tripId = sql(`insert into trips (id, owner_id, title, created_at, updated_at) values (gen_random_uuid(), '${rider.id}', '${trip}', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${rider.id}', 'owner', now(), now())`)

  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(rider.email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
  await page.goto(`/journey?from=e2e-journey-a-${s}&to=e2e-journey-b-${s}&within=10`)
  const row = page.getByRole('list', { name: 'Places along the route' }).getByRole('listitem').filter({ hasText: 'E2E Journey pump' })
  await row.getByLabel('Add to a trip').selectOption({ label: trip })
  await row.getByRole('button', { name: /Add E2E Journey pump/ }).click()
  await expect(row.getByRole('status')).toContainText(`Added to ${trip}`)
  await row.getByRole('button', { name: /Add E2E Journey pump/ }).click()
  await expect(row.getByRole('alert')).toContainText('already a stop on this trip')
  expect(sql(`select count(*) from trip_stops where trip_id = '${tripId}'`)).toBe('1')
})
