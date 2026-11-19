import { api } from '@/lib/api/client'

// Public CMS content (/api/v1/content). Only published content inside its dates; staff preview links also show drafts.
export interface CmsImage { url: string; alt: string; credit: string; width: number; height: number }
export interface CmsRef { id: string; slug: string; title: string }
export interface CmsItem {
  id: string
  type: string
  slug: string | null
  title: string
  summary: string | null
  body: string | null
  data: {
    blocks?: { kind: 'heading' | 'text' | 'callout'; text: string }[]
    category?: CmsRef | string | null
    author?: CmsRef | null
    tags?: CmsRef[]
    role?: string
    cta?: { label: string; to: string } | null
    show_on?: 'all' | 'home'
    frequency?: 'once' | 'session'
    placement?: 'home' | 'blog'
  }
  media: CmsImage | null
  seo_title: string | null
  seo_description: string | null
  published_at: string | null
}
export interface CmsPage<T> { data: T[]; meta: { total: number; current_page: number; last_page: number } }

export const listContent = async (type: string, params: Record<string, string | number | undefined> = {}) => (await api.get<{ data: CmsItem[] }>(`/content/${type}`, { params })).data.data
export const listPosts = async (params: Record<string, string | number | undefined>) => (await api.get<CmsPage<CmsItem>>('/content/post', { params })).data
/** Null when nothing is published at this address. */
export async function getContent(type: 'page' | 'post' | 'author', slug: string): Promise<CmsItem | null> {
  try {
    return (await api.get<{ data: CmsItem }>(`/content/${type}/${encodeURIComponent(slug)}`)).data.data
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) return null
    throw e
  }
}
export const sendContact = async (body: { name: string; email: string; subject: string; body: string; website: string }) => (await api.post<{ message: string }>('/contact', body)).data.message
