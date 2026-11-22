import { api } from '@/lib/api/client'
import type { RideRoute } from '@/types/api'

// Explore Along My Route, curated mode (/api/v1/journeys). Distances are straight-line from the recorded route line.
export interface JourneyPlace {
  type: 'service' | 'destination' | 'place'
  id: string
  /** Service kind, place category, or "destination". */
  kind: string
  name: string
  notes: string | null
  position: [number, number] | null
  on_route: boolean
  distance_km: number
  near: string | null
  link: string | null
  last_confirmed_on: string | null
}
export interface Journey {
  from: { slug: string; name: string }
  to: { slug: string; name: string }
  within_km: number
  data: { route: RideRoute; reversed: boolean; places: JourneyPlace[] }[]
}
export const findJourneys = async (from: string, to: string, withinKm: number) => (await api.get<Journey>('/journeys', { params: { from, to, within_km: withinKm } })).data
