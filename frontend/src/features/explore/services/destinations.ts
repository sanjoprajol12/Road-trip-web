import { api } from '@/lib/api/client'
import type { Destination, RideRoute, ServicePoint } from '@/types/api'

export async function listDestinations(): Promise<Destination[]> {
  return (await api.get<{ data: Destination[] }>('/destinations')).data.data
}

/** Null when nothing is published at this address. */
export async function getDestination(slug: string): Promise<{ destination: Destination; routes: RideRoute[]; services: ServicePoint[] } | null> {
  try {
    const { data } = await api.get<{ data: Destination; routes: { data: RideRoute[] } | RideRoute[]; services: ServicePoint[] }>(`/destinations/${encodeURIComponent(slug)}`)
    return { destination: data.data, routes: Array.isArray(data.routes) ? data.routes : data.routes.data, services: data.services ?? [] }
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) return null
    throw e
  }
}
