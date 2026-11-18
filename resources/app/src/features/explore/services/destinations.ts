import { api } from '@/lib/api/client'
import type { Destination, RideRoute } from '@/types/api'

export async function listDestinations(): Promise<Destination[]> {
  return (await api.get<{ data: Destination[] }>('/destinations')).data.data
}

/** Null when nothing is published at this address. */
export async function getDestination(slug: string): Promise<{ destination: Destination; routes: RideRoute[] } | null> {
  try {
    const { data } = await api.get<{ data: Destination; routes: { data: RideRoute[] } | RideRoute[] }>(`/destinations/${encodeURIComponent(slug)}`)
    return { destination: data.data, routes: Array.isArray(data.routes) ? data.routes : data.routes.data }
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) return null
    throw e
  }
}
