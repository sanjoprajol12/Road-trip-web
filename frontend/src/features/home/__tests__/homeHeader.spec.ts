import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import SiteHeader from '@/components/navigation/SiteHeader.vue'
import HomePage from '../pages/HomePage.vue'
import type { HomeSection } from '../types'

const getHome = vi.fn<() => Promise<HomeSection[]>>()
vi.mock('../services/home', () => ({ getHome: () => getHome() }))

const hero: HomeSection = {
  id: 'h1', type: 'hero', eyebrow: null, lines: ['Ride Nepal'], lead: null, primary_cta: null, secondary_cta: null,
  background: { kind: 'bundled', base: '/images/photos/x', widths: [640] }, photo_credit: null,
} as HomeSection
const text: HomeSection = { id: 't1', type: 'text', heading: 'About', body: 'Words' } as HomeSection

async function render(sections: Promise<HomeSection[]>, path = '/') {
  getHome.mockReturnValue(sections)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: HomePage, meta: { overlayHeader: true } },
      { path: '/other', component: { render: () => h('h1', 'Other') } },
    ],
  })
  await router.push(path)
  const App = defineComponent({ components: { SiteHeader }, template: '<SiteHeader /><main><RouterView /></main>' })
  const w = mount(App, {
    global: { plugins: [router], stubs: { CmsBanners: true }, directives: { reveal: {} } },
    attachTo: document.body,
  })
  await flushPromises()
  return { w, router, transparent: () => w.get('header').classes().includes('bg-transparent'), h1s: () => w.findAll('h1') }
}

let mounted: { unmount(): void } | null = null
afterEach(() => {
  mounted?.unmount() // unmounting the hero also resets the shared hero counter
  mounted = null
})

describe('home page header and heading', () => {
  it('loading: normal header and one descriptive h1', async () => {
    const r = await render(new Promise(() => {}))
    mounted = r.w
    expect(r.transparent()).toBe(false)
    expect(r.h1s()).toHaveLength(1)
    expect(r.h1s()[0]?.text()).toContain('loading the home page')
  })

  it('API failure: normal header and one descriptive h1', async () => {
    const r = await render(Promise.reject(new Error('offline')))
    mounted = r.w
    expect(r.transparent()).toBe(false)
    expect(r.h1s()).toHaveLength(1)
    expect(r.h1s()[0]?.text()).toContain('could not be loaded')
  })

  it('rendered hero: transparent header and only the hero h1', async () => {
    const r = await render(Promise.resolve([hero, text]))
    mounted = r.w
    expect(r.transparent()).toBe(true)
    expect(r.h1s()).toHaveLength(1)
    expect(r.h1s()[0]?.text()).toBe('Ride Nepal')
  })

  it('hero removed by staff: normal header and one hidden h1', async () => {
    const r = await render(Promise.resolve([text]))
    mounted = r.w
    expect(r.transparent()).toBe(false)
    expect(r.h1s()).toHaveLength(1)
    expect(r.h1s()[0]?.classes()).toContain('sr-only')
  })

  it('leaving the home page: the header goes back to normal', async () => {
    const r = await render(Promise.resolve([hero]))
    mounted = r.w
    expect(r.transparent()).toBe(true)
    await r.router.push('/other')
    await flushPromises()
    expect(r.transparent()).toBe(false)
  })

  it('retry after a failure keeps exactly one h1 in each state', async () => {
    let fail!: (e: Error) => void
    const r = await render(new Promise((_, reject) => (fail = reject)))
    mounted = r.w
    fail(new Error('offline'))
    await flushPromises()
    expect(r.h1s()).toHaveLength(1)
    getHome.mockReturnValue(Promise.resolve([hero]))
    await r.w.get('button.btn-quiet').trigger('click') // "Try again"
    await flushPromises()
    expect(r.h1s()).toHaveLength(1)
    expect(r.transparent()).toBe(true)
  })
})
