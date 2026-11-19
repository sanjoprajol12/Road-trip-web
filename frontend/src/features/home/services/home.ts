import { api } from '@/lib/api/client'
import type { HomeSection, MenuItem, Notice } from '../types'

// Public CMS content. Published only; a staff preview link (lib/preview.ts) shows drafts.
export const getHome = async () => (await api.get<{ data: HomeSection[] }>('/home')).data.data
export const getMenu = async (location: 'header' | 'footer') => (await api.get<{ data: MenuItem[] }>(`/menus/${location}`)).data.data
export const getNotices = async () => (await api.get<{ data: Notice[] }>('/notices/active')).data.data
