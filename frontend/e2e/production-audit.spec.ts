import { expect, test, type Page } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

// Session 22 production-readiness audit, without new dependencies (axe-core and Lighthouse await owner approval):
// WCAG text contrast, visible and unobscured keyboard focus, phone tap-target size, structure on detail and signed-in
// pages, and lab performance with mobile CPU and network throttling. Findings are printed and asserted.
const password = 'prod-audit-2026'
const stamp = `${Date.now()}`
let routeSlug = ''
let tripId = ''
let riderEmail = ''

test.beforeAll(async () => {
  const r = sql(`insert into routes (id, slug, name, status, distance_km, created_at, updated_at) values (gen_random_uuid(), 'e2e-audit-${stamp}', 'E2E Audit Route ${stamp}', 'published', 60, now(), now()) returning id`)
  const seg = sql(`insert into route_segments (id, route_id, position, start_name, end_name, distance_km, estimated_hours, surface, start_lat, start_lng, end_lat, end_lng, path, created_at, updated_at)
    values (gen_random_uuid(), '${r}', 1, 'Alpha', 'Bravo', 25, 1, 'paved', 28.2, 83.9, 28.3, 84.0, '[[28.2, 83.9], [28.3, 84.0]]', now(), now()) returning id`)
  sql(`insert into service_points (id, kind, name, route_segment_id, status, latitude, longitude, created_at, updated_at) values (gen_random_uuid(), 'fuel', 'E2E Audit Pump', '${seg}', 'published', 28.25, 83.95, now(), now())`)
  routeSlug = `e2e-audit-${stamp}`
  const rider = await createConfirmedRider(`audit-${stamp}`, password)
  riderEmail = rider.email
  tripId = sql(`insert into trips (id, owner_id, title, starts_on, ends_on, created_at, updated_at) values (gen_random_uuid(), '${rider.id}', 'E2E Audit trip', '2027-03-01', '2027-03-02', now(), now()) returning id`)
  sql(`insert into trip_members (trip_id, user_id, role, created_at, updated_at) values ('${tripId}', '${rider.id}', 'owner', now(), now())`)
  sql(`insert into trip_stops (id, trip_id, position, day, name, route_id, route_segment_id, route_part, overnight, created_at, updated_at) values (gen_random_uuid(), '${tripId}', 1, 1, 'Alpha to Bravo', '${r}', '${seg}', 'stretch', true, now(), now())`)
  sql(`insert into trip_expenses (id, trip_id, label, category, amount_npr, status, created_at, updated_at) values (gen_random_uuid(), '${tripId}', 'Fuel', 'fuel', 2000, 'planned', now(), now())`)
  sql(`insert into trip_checklist_items (id, trip_id, label, section, done, position, created_at, updated_at) values (gen_random_uuid(), '${tripId}', 'Chain lube', 'motorcycle', true, 1, now(), now())`)
})
test.afterAll(() => {
  sql(`delete from trips where title = 'E2E Audit trip'`)
  sql(`delete from service_points where name = 'E2E Audit Pump'`)
  sql(`delete from routes where slug like 'e2e-audit-%'`)
})

async function signIn(page: Page) {
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(riderEmail)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

/** Every public page, a route detail page, and the signed-in pages a rider uses most. */
const publicPages = () => ['/', '/explore', '/routes', `/routes/${routeSlug}`, '/journey', '/places', '/experiences', '/events', '/preparation', '/faq', '/contact', '/sign-in', '/sign-up']
const riderPages = () => ['/account', '/rides', `/rides/${tripId}`, '/saved']

/** Text below WCAG AA contrast (4.5:1, or 3:1 for large text) against its real, solid background. */
async function contrastFailures(page: Page) {
  return page.evaluate(() => {
    // Any CSS colour (rgb, oklab, color-mix) to [r, g, b, a] by letting the browser paint it.
    const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!
    const parse = (c: string): [number, number, number, number] => {
      ctx.clearRect(0, 0, 1, 1)
      ctx.fillStyle = c
      ctx.fillRect(0, 0, 1, 1)
      const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data
      return [r!, g!, b!, a! / 255]
    }
    const lum = ([r, g, b]: number[]) => {
      const f = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
      return 0.2126 * f(r!) + 0.7152 * f(g!) + 0.0722 * f(b!)
    }
    const blend = (top: number[], a: number, bottom: number[]) => top.map((v, i) => v * a + bottom[i]! * (1 - a))
    // The solid colour behind an element; null when an image or gradient is behind it (cannot be judged here).
    const backdrop = (el: Element | null): number[] | null => {
      if (!el) return [255, 255, 255]
      const s = getComputedStyle(el)
      if (s.backgroundImage !== 'none') return null
      const [r, g, b, a] = parse(s.backgroundColor)
      // A see-through fixed layer (the header) sits over whatever scrolls beneath it: not judged here.
      if (a < 0.9 && (s.position === 'fixed' || s.position === 'sticky')) return null
      if (a === 0) return backdrop(el.parentElement)
      if (a >= 1) return [r, g, b]
      const under = backdrop(el.parentElement)
      return under && blend([r, g, b], a, under)
    }
    const out: string[] = []
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    const seen = new Set<Element>()
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const el = n.parentElement
      if (!el || seen.has(el) || !n.textContent?.trim()) continue
      seen.add(el)
      const r = el.getBoundingClientRect()
      const s = getComputedStyle(el)
      if (r.width < 2 || r.height < 2 || s.visibility === 'hidden' || s.display === 'none') continue
      if (el.closest('[disabled], [aria-disabled="true"], .leaflet-container, option, .sr-only, [aria-hidden="true"]')) continue // disabled UI and map tiles are exempt or not text
      let opacity = 1
      for (let p: Element | null = el; p; p = p.parentElement) opacity *= Number(getComputedStyle(p).opacity)
      if (opacity < 0.95) continue // mid reveal-animation, or disabled styling
      const bg = backdrop(el)
      if (!bg) continue
      const [cr, cg, cb, ca] = parse(s.color)
      const fg = blend([cr, cg, cb], ca, bg)
      const [l1, l2] = [lum(fg), lum(bg)].sort((a, b) => b - a)
      const ratio = (l1! + 0.05) / (l2! + 0.05)
      const size = parseFloat(s.fontSize)
      const large = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700)
      if (ratio < (large ? 3 : 4.5)) out.push(`${ratio.toFixed(2)}:1 "${n.textContent.trim().slice(0, 40)}" (${s.color} on rgb(${bg.map(Math.round).join(',')}))`)
    }
    return out
  })
}

/** Tab through the page: each stop must show a focus indicator and not be hidden under the sticky header. */
async function focusProblems(page: Page, stops = 30) {
  const problems: string[] = []
  for (let i = 0; i < stops; i++) {
    await page.keyboard.press('Tab')
    const p = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement | null
      if (!el || el === document.body) return null
      const s = getComputedStyle(el)
      const ring = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 1) || s.boxShadow !== 'none'
      const r = el.getBoundingClientRect()
      const x = Math.min(Math.max(r.left + r.width / 2, 0), innerWidth - 1)
      const y = Math.min(Math.max(r.top + Math.min(r.height / 2, 10), 0), innerHeight - 1)
      const hit = document.elementFromPoint(x, y)
      const covered = r.height > 0 && hit !== null && !el.contains(hit) && !hit.contains(el) && !!hit.closest('header')
      const label = (el.getAttribute('aria-label') || el.textContent || el.outerHTML).trim().slice(0, 90)
      return !ring ? `no visible focus: ${label}` : covered ? `covered by the header: ${label}` : ''
    })
    if (p === null) break
    if (p) problems.push(p)
  }
  return problems
}

/**
 * Phone: controls smaller than 24x24 CSS px (WCAG 2.2 AA 2.5.8) that also fail its spacing exception (a 24px circle
 * centred on the target overlaps another target). Links inside a sentence are exempt.
 */
async function smallTargets(page: Page) {
  return page.evaluate(() => {
    const all = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button]')].filter((el) => el.getBoundingClientRect().width > 0)
    const crowded = (el: Element) => {
      const r = el.getBoundingClientRect()
      const [cx, cy] = [r.left + r.width / 2, r.top + r.height / 2]
      return all.some((o) => {
        if (o === el || o.contains(el) || el.contains(o)) return false
        const q = o.getBoundingClientRect()
        const dx = Math.max(q.left - cx, 0, cx - q.right)
        const dy = Math.max(q.top - cy, 0, cy - q.bottom)
        return Math.hypot(dx, dy) < 12
      })
    }
    return all
    .filter((el) => {
      const r = el.getBoundingClientRect()
      const s = getComputedStyle(el)
      if (r.width === 0 || s.visibility === 'hidden' || el.closest('.sr-only, .leaflet-control-attribution')) return false
      if (el.tagName === 'A' && s.display === 'inline' && (el.parentElement?.textContent?.trim().length ?? 0) > (el.textContent?.trim().length ?? 0) + 5) return false // inline in text
      if (el.matches('input[type=checkbox], input[type=radio]') && (el as HTMLInputElement).labels?.length) return false // the label is the target
      return (r.width < 24 || r.height < 24) && crowded(el)
    })
    .map((el) => `${Math.round(el.getBoundingClientRect().width)}x${Math.round(el.getBoundingClientRect().height)} ${(el.getAttribute('aria-label') || el.textContent || el.tagName).trim().slice(0, 40)}`)
  })
}

async function settle(page: Page) {
  await page.waitForLoadState('networkidle')
  // Let reveal animations finish everywhere so contrast is judged on the final state.
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += innerHeight / 2) {
      scrollTo(0, y)
      await new Promise((r) => setTimeout(r, 60))
    }
    scrollTo(0, 0)
    await new Promise((r) => setTimeout(r, 800))
  })
}

test('contrast meets WCAG AA on public, detail and signed-in pages', async ({ page }, info) => {
  test.setTimeout(240_000)
  test.skip(info.project.name !== 'chromium', 'colour maths is browser-independent; run once')
  const found: Record<string, string[]> = {}
  for (const path of publicPages()) {
    await page.goto(path)
    await settle(page)
    const f = await contrastFailures(page)
    if (f.length) found[path] = f
  }
  await signIn(page)
  for (const path of riderPages()) {
    await page.goto(path)
    await settle(page)
    const f = await contrastFailures(page)
    if (f.length) found[path] = f
  }
  console.log('contrast failures:', JSON.stringify(found, null, 1))
  expect(found).toEqual({})
})

test('keyboard focus is visible and never hidden under the sticky header', async ({ page }, info) => {
  test.skip(info.project.name === 'mobile', 'no hardware keyboard on the phone profile')
  const found: Record<string, string[]> = {}
  for (const path of ['/', `/routes/${routeSlug}`, '/journey', '/sign-in']) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    const f = await focusProblems(page)
    if (f.length) found[path] = f
  }
  await signIn(page)
  await page.goto(`/rides/${tripId}`)
  await page.waitForLoadState('networkidle')
  const f = await focusProblems(page, 60)
  if (f.length) found[`/rides/${tripId}`] = f
  console.log(`focus problems (${info.project.name}):`, JSON.stringify(found, null, 1))
  expect(found).toEqual({})
})

test('tap targets on a phone are at least 24 by 24 pixels', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile', 'phone profile only')
  const found: Record<string, string[]> = {}
  for (const path of publicPages()) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    const f = await smallTargets(page)
    if (f.length) found[path] = f
  }
  await signIn(page)
  for (const path of riderPages()) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    const f = await smallTargets(page)
    if (f.length) found[path] = f
  }
  console.log('small targets:', JSON.stringify(found, null, 1))
  expect(found).toEqual({})
})

test('lab performance on a throttled phone profile (not Lighthouse)', async ({ browser }, info) => {
  test.skip(info.project.name !== 'chromium', 'CDP throttling and LCP entries are Chromium-only')
  // Pixel-7-sized viewport, 4x CPU slowdown, "fast 4G"-like network: a lab estimate, not field data.
  const ctx = await browser.newContext({ viewport: { width: 412, height: 915 }, deviceScaleFactor: 2.6, isMobile: true, hasTouch: true })
  const page = await ctx.newPage()
  const cdp = await ctx.newCDPSession(page)
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  await cdp.send('Network.enable')
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 })
  const results: Record<string, { lcp: number; cls: number; inp: number | null; jsKb: number }> = {}
  for (const path of ['/', `/routes/${routeSlug}`, '/journey', '/places']) {
    await page.goto(path, { waitUntil: 'load' })
    await page.waitForLoadState('networkidle')
    // A real interaction to measure Event Timing (INP-like): open the menu button on the phone header.
    await page.getByRole('button', { name: 'Menu' }).click()
    await page.keyboard.press('Escape')
    results[path] = await page.evaluate(() => new Promise((resolve) => {
      let lcp = 0
      let cls = 0
      let inp: number | null = null
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (lcp = Math.max(lcp, e.startTime)))).observe({ type: 'largest-contentful-paint', buffered: true })
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (cls += (e as unknown as { hadRecentInput: boolean; value: number }).hadRecentInput ? 0 : (e as unknown as { value: number }).value))).observe({ type: 'layout-shift', buffered: true })
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (inp = Math.max(inp ?? 0, e.duration)))).observe({ type: 'event', buffered: true, durationThreshold: 16 } as PerformanceObserverInit)
      const jsKb = Math.round(performance.getEntriesByType('resource').filter((r) => (r as PerformanceResourceTiming).initiatorType === 'script').reduce((t, r) => t + (r as PerformanceResourceTiming).encodedBodySize, 0) / 1024)
      setTimeout(() => resolve({ lcp: Math.round(lcp), cls: Math.round(cls * 1000) / 1000, inp, jsKb }), 800)
    }))
    await page.goto('about:blank')
  }
  console.log('throttled phone lab metrics:', JSON.stringify(results))
  for (const [path, v] of Object.entries(results)) {
    expect(v.cls, `${path} CLS`).toBeLessThan(0.1)
    expect(v.lcp, `${path} LCP ms (throttled)`).toBeLessThan(4000)
  }
  await ctx.close()
})
