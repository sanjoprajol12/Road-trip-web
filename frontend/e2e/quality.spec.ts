import { expect, test, type Page } from '@playwright/test'

// Accessibility and performance checks without extra dependencies (axe-core and Lighthouse are not installed: adding
// them is an owner decision). Structural rules a screen reader relies on, keyboard entry, and Web Vitals measured in
// the browser on the production build served by `vite preview`.
const pages = ['/', '/explore', '/routes', '/journey', '/experiences', '/events', '/preparation', '/blog', '/faq', '/team', '/contact', '/sign-in', '/sign-up']

async function audit(page: Page) {
  return page.evaluate(() => {
    const visible = (el: Element) => {
      const r = (el as HTMLElement).getBoundingClientRect()
      const s = getComputedStyle(el)
      return s.display !== 'none' && s.visibility !== 'hidden' && (r.width > 0 || r.height > 0)
    }
    const nameOf = (el: Element) => (el.getAttribute('aria-label') || (el.getAttribute('aria-labelledby') ?? '').split(' ').map((id) => document.getElementById(id)?.textContent ?? '').join('') || (el as HTMLElement).innerText || el.getAttribute('title') || '').trim()
    const controls = [...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter(visible)
    return {
      lang: document.documentElement.lang,
      h1: [...document.querySelectorAll('h1')].filter(visible).length,
      main: document.querySelectorAll('main').length,
      imgsWithoutAlt: [...document.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).map((i) => i.getAttribute('src')),
      unlabelled: controls.filter((c) => !(c as HTMLInputElement).labels?.length && !c.getAttribute('aria-label') && !c.getAttribute('aria-labelledby')).map((c) => c.outerHTML.slice(0, 80)),
      unnamedButtons: [...document.querySelectorAll('button, a[href]')].filter(visible).filter((b) => !nameOf(b)).map((b) => b.outerHTML.slice(0, 80)),
      duplicateIds: [...document.querySelectorAll('[id]')].map((e) => e.id).filter((id, i, all) => all.indexOf(id) !== i),
    }
  })
}

for (const path of pages) {
  test(`structure a screen reader relies on: ${path}`, async ({ page }) => {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    const a = await audit(page)
    expect(a.lang).toBe('en')
    expect(a.main).toBe(1)
    expect(a.h1, 'exactly one visible h1').toBe(1)
    expect(a.imgsWithoutAlt).toEqual([])
    expect(a.unlabelled).toEqual([])
    expect(a.unnamedButtons).toEqual([])
    expect(a.duplicateIds).toEqual([])
  })
}

test('keyboard: the first Tab reaches "Skip to content", which moves focus to the page content', async ({ page, browserName }, info) => {
  test.skip(info.project.name === 'mobile', 'no hardware keyboard on the phone profile')
  await page.goto('/routes')
  await page.waitForLoadState('networkidle')
  await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab')
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page.locator('#main')).toBeFocused()
})

test('reduced motion is respected: no running CSS animations on the homepage', async ({ browser }) => {
  const ctx = await browser.newContext({ reducedMotion: 'reduce' })
  const page = await ctx.newPage()
  await page.goto('/')
  await page.waitForLoadState('networkidle')
  const running = await page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running' && (a.effect as KeyframeEffect | null)?.getTiming().iterations === Infinity).length)
  expect(running).toBe(0)
  await ctx.close()
})

test('web vitals on the production build stay within budget', async ({ page }, info) => {
  test.skip(info.project.name !== 'chromium', 'LCP and layout-shift entries are Chromium APIs')
  const results: Record<string, { lcp: number; cls: number }> = {}
  for (const path of ['/', '/explore', '/routes', '/blog']) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    results[path] = await page.evaluate(() => new Promise<{ lcp: number; cls: number }>((resolve) => {
      let lcp = 0
      let cls = 0
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (lcp = Math.max(lcp, e.startTime)))).observe({ type: 'largest-contentful-paint', buffered: true })
      new PerformanceObserver((l) => l.getEntries().forEach((e) => (cls += (e as unknown as { value: number; hadRecentInput: boolean }).hadRecentInput ? 0 : (e as unknown as { value: number }).value))).observe({ type: 'layout-shift', buffered: true })
      setTimeout(() => resolve({ lcp: Math.round(lcp), cls: Math.round(cls * 1000) / 1000 }), 500)
    }))
  }
  console.log('web vitals (local preview, no throttling):', JSON.stringify(results))
  for (const [path, v] of Object.entries(results)) {
    expect(v.lcp, `${path} LCP ms`).toBeLessThan(2500)
    expect(v.cls, `${path} CLS`).toBeLessThan(0.1)
  }
})
