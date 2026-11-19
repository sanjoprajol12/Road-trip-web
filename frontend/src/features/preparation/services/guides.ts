import { api } from '@/lib/api/client'
import type { Guide } from '@/types/api'

export const listGuides = async () => (await api.get<{ data: Guide[] }>('/guides')).data.data
export const getGuide = async (slug: string) => (await api.get<{ data: Guide }>(`/guides/${encodeURIComponent(slug)}`)).data.data
export const expertReview = (id: string) => api.post(`/guides/${id}/expert-review`)
