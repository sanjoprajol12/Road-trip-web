import { ref } from 'vue'
import { api } from '@/lib/api/client'
import type { MenuItem, Notice } from '@/features/home/types'

/** Public site settings from GET /api/v1/site (managed in the staff app). Loaded once per page load. */
export interface SiteSettings {
  general: { site_name: string; tagline: string | null; description: string | null; contact_email: string | null; contact_phone: string | null; address: string | null; default_language: string; timezone: string; date_format: string }
  maintenance: { enabled: boolean; message: string }
  registration: { open: boolean; closed_message: string }
  branding: { share_image_media_id: string | null; share_image_alt: string | null }
  maps?: { tile_url: string; attribution: string; attribution_url: string | null; default_lat: number; default_lng: number; default_zoom: number }
  social: Partial<Record<'facebook' | 'instagram' | 'youtube' | 'tiktok' | 'x', string | null>>
  seo: { default_title: string; default_description: string }
  footer: { tagline: string | null; disclaimer: string; note: string | null; credits: string | null }
}

export const site = ref<SiteSettings | null>(null)
/** Header and footer menus and active notices, all managed in the staff portal. Empty when the API is down. */
export const menus = ref<{ header: MenuItem[]; footer: MenuItem[] }>({ header: [], footer: [] })
export const notices = ref<Notice[]>([])

export async function loadSite(): Promise<void> {
  try {
    site.value = (await api.get<{ data: SiteSettings }>('/site')).data.data
  } catch {
    site.value = null // the pages show their own errors if the API is down
  }
  // Menus and notices are optional extras: during maintenance (503) or an outage the site still renders.
  const [header, footer, active] = await Promise.allSettled([
    api.get<{ data: MenuItem[] }>('/menus/header'),
    api.get<{ data: MenuItem[] }>('/menus/footer'),
    api.get<{ data: Notice[] }>('/notices/active'),
  ])
  menus.value = {
    header: header.status === 'fulfilled' ? header.value.data.data : [],
    footer: footer.status === 'fulfilled' ? footer.value.data.data : [],
  }
  notices.value = active.status === 'fulfilled' ? active.value.data.data : []
  applySeoDefaults()
}

/** Default description and social-sharing image from the SEO and branding settings (crawler visibility: D4). */
function applySeoDefaults() {
  const set = (attr: 'name' | 'property', key: string, value: string | null | undefined) => {
    if (!value) return
    let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
    if (!el) {
      el = document.createElement('meta')
      el.setAttribute(attr, key)
      document.head.appendChild(el)
    }
    el.content = value
  }
  const s = site.value
  set('name', 'description', s?.seo.default_description)
  set('property', 'og:title', s?.seo.default_title)
  set('property', 'og:description', s?.seo.default_description)
  if (s?.branding.share_image_media_id) set('property', 'og:image', `${window.location.origin}/api/v1/files/${s.branding.share_image_media_id}`)
}

/** Page title and description for one page (CMS SEO fields), falling back to the site defaults. */
export function setPageMeta(title: string | null | undefined, description?: string | null) {
  const name = site.value?.general.site_name ?? 'My Ride Nepal'
  if (title) document.title = `${title} | ${name}`
  const desc = description || site.value?.seo.default_description
  for (const [attr, key, value] of [['name', 'description', desc], ['property', 'og:title', title ?? site.value?.seo.default_title], ['property', 'og:description', desc]] as const) {
    if (!value) continue
    let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
    if (!el) {
      el = document.createElement('meta')
      el.setAttribute(attr, key)
      document.head.appendChild(el)
    }
    el.content = value
  }
}
