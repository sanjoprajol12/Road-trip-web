import { expect, test } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Saved services: save from a destination page, filter by kind, add to a private trip, remove.
const password = 'saved-items-2026'
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`

test.afterAll(() => {
  sql(`delete from service_points where name like 'E2E Saved %'`)
  sql(`delete from destinations where slug like 'e2e-saved-%'`)
})

test('a rider saves a workshop, finds it under Services and adds it to a trip', async ({ page }, info) => {
  const slug = `e2e-saved-${info.project.name}-${stamp()}`
  const destId = sql(`insert into destinations (id, slug, name, status, created_at, updated_at) values (gen_random_uuid(), '${slug}', 'E2E Saved town', 'published', now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, destination_id, status, created_at, updated_at)
       values (gen_random_uuid(), 'mechanic', 'E2E Saved workshop', '${destId}', 'published', now(), now()),
              (gen_random_uuid(), 'fuel', 'E2E Saved pump', '${destId}', 'published', now(), now())`)
  const rider = await createConfirmedRider(`saved-${info.project.name}`, password)
  const trip = `E2E Saved trip ${stamp()}`
  const tripId = sql(`insert into trips (id, owner_id, title, created_at, updated_at) values (gen_random_uuid(), '${rider.id}', '${trip}', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${rider.id}', 'owner', now(), now())`)

  // Guests are asked to sign in, then come back to the page.
  await page.goto(`/explore/${slug}`)
  await page.getByRole('link', { name: 'Sign in to save' }).click()
  await page.getByLabel('Email').fill(rider.email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/explore/${slug}$`))

  await page.getByRole('button', { name: 'Save E2E Saved workshop' }).click()
  await expect(page.getByRole('button', { name: 'Remove E2E Saved workshop' })).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Save E2E Saved town' }).click()

  await page.goto('/saved')
  await expect(page.getByRole('link', { name: 'E2E Saved workshop' })).toBeVisible()
  await page.getByRole('button', { name: 'Services' }).click()
  await page.getByLabel('Kind of service').selectOption('fuel')
  await expect(page.getByText('Nothing saved here yet')).toBeVisible()
  await page.getByLabel('Kind of service').selectOption('mechanic')
  const row = page.getByRole('listitem').filter({ hasText: 'E2E Saved workshop' })
  await row.getByLabel('Add to a trip').selectOption({ label: trip })
  await row.getByRole('button', { name: /Add E2E Saved workshop/ }).click()
  await expect(row.getByRole('status')).toContainText(`Added to ${trip}`)
  expect(sql(`select count(*) from trip_stops s join service_points p on p.id = s.service_point_id where s.trip_id = '${tripId}' and p.name = 'E2E Saved workshop'`)).toBe('1')

  await row.getByRole('button', { name: 'Remove E2E Saved workshop from saved' }).click()
  await expect(page.getByText('Nothing saved here yet')).toBeVisible()
})
