import { expect, test, type Page } from '@playwright/test'

// The header is transparent (light text) only over a rendered hero; every other state of the home page gets the
// normal header and exactly one h1. The home API is intercepted, so no database rows change.
const header = (page: Page) => page.locator('header').first()
const transparent = async (page: Page) => (await header(page).getAttribute('class'))?.includes('bg-transparent') ?? false

test('while the home page loads, the header is normal and the page has one h1', async ({ page }) => {
  let release!: () => void
  const held = new Promise<void>((r) => (release = r))
  await page.route('**/api/v1/home', async (route) => {
    await held
    await route.continue()
  })
  await page.goto('/')
  await expect(page.getByRole('status').getByText('Loading')).toBeVisible()
  expect(await transparent(page)).toBe(false)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/loading the home page/)
  await expect(header(page).getByRole('link', { name: 'My Ride Nepal, home' })).toBeVisible()
  release()
  // Then the hero arrives: transparent header, still one h1.
  await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(/loading/)
  await expect.poll(() => transparent(page)).toBe(true)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
})

test('when the home page fails to load, the header is normal and the page has one h1', async ({ page }) => {
  await page.route('**/api/v1/home', (route) => route.fulfill({ status: 500, json: { message: 'Server Error' } }))
  await page.goto('/')
  await expect(page.getByRole('alert')).toContainText('The home page could not be loaded')
  expect(await transparent(page)).toBe(false)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/could not be loaded/)
})

test('without a hero (removed by staff), the header is normal and the page has one h1', async ({ page }) => {
  await page.route('**/api/v1/home', async (route) => {
    const res = await route.fetch()
    const body = (await res.json()) as { data: { type: string }[] }
    await route.fulfill({ response: res, json: { ...body, data: body.data.filter((s) => s.type !== 'hero') } })
  })
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Every observation carries its date' })).toBeVisible()
  expect(await transparent(page)).toBe(false)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('My Ride Nepal')
})

test('with the hero rendered, the header is transparent over it and the hero holds the only h1', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('section.on-night h1')).toBeVisible()
  await expect.poll(() => transparent(page)).toBe(true)
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  // Other pages keep their normal header.
  await page.goto('/explore')
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  expect(await transparent(page)).toBe(false)
})
