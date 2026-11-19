import { ref } from 'vue'
import { api } from '@/lib/api/client'
import type { MenuItem, Notice } from '@/features/home/types'

/** Public site settings from GET /api/v1/site (managed in the staff app). Loaded once per page load. */
export interface SiteSettings {
  general: { site_name: string; tagline: string | null; description: string | null; contact_email: string | null; contact_phone: string | null; address: string | null; default_language: string; timezone: string; date_format: string }
  maintenance: { enabled: boolean; message: string }
  registration: { open: boolean; closed_message: string }
  branding: { share_image_media_id: string | null; share_image_alt: string | null }
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
    const [s, header, footer, active] = await Promise.all([
      api.get<{ data: SiteSettings }>('/site'),
      api.get<{ data: MenuItem[] }>('/menus/header'),
      api.get<{ data: MenuItem[] }>('/menus/footer'),
      api.get<{ data: Notice[] }>('/notices/active'),
    ])
    site.value = s.data.data
    menus.value = { header: header.data.data, footer: footer.data.data }
    notices.value = active.data.data
  } catch {
    site.value = null // the pages show their own errors if the API is down
  }
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
