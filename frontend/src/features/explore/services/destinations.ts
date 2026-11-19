import { api } from '@/lib/api/client'
import type { Destination, RideRoute, ServicePoint } from '@/types/api'

export async function listDestinations(): Promise<Destination[]> {
  return (await api.get<{ data: Destination[] }>('/destinations')).data.data
}

/** Null when nothing is published at this address. */
/** Straight-line neighbours of a destination (null when it has no coordinates). */
export interface Nearby {
  radius_km: { destinations: number; services: number; routes: number }
  destinations: { slug: string; name: string; position: [number, number]; distance_km: number }[]
  services: { id: string; kind: ServicePoint['kind']; name: string; position: [number, number]; distance_km: number; place: string | null; last_confirmed_on: string | null }[]
  routes: { slug: string; name: string; distance_km: number }[]
}

export async function getDestination(slug: string): Promise<{ destination: Destination; routes: RideRoute[]; services: ServicePoint[]; nearby: Nearby | null } | null> {
  try {
    const { data } = await api.get<{ data: Destination; routes: { data: RideRoute[] } | RideRoute[]; services: ServicePoint[]; nearby: Nearby | null }>(`/destinations/${encodeURIComponent(slug)}`)
    return { destination: data.data, routes: Array.isArray(data.routes) ? data.routes : data.routes.data, services: data.services ?? [], nearby: data.nearby ?? null }
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) return null
    throw e
  }
}
