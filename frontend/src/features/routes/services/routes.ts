import { api } from '@/lib/api/client'
import type { RideRoute } from '@/types/api'

export const listRoutes = async () => (await api.get<{ data: RideRoute[] }>('/routes')).data.data
export const getRoute = async (slug: string) => (await api.get<{ data: RideRoute }>(`/routes/${encodeURIComponent(slug)}`)).data.data
