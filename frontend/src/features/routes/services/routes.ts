import { api } from '@/lib/api/client'
import type { RideRoute } from '@/types/api'

export const listRoutes = async (params: Record<string, string | number | undefined> = {}) => (await api.get<{ data: RideRoute[] }>('/routes', { params })).data.data
export const getRoute = async (slug: string) => (await api.get<{ data: RideRoute }>(`/routes/${encodeURIComponent(slug)}`)).data.data
