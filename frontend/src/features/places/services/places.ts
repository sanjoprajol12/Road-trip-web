import { api } from '@/lib/api/client'
import type { Contribution, Paginated, Source } from '@/types/api'
import type { CmsImage } from '@/features/content/services/content'

// Places of interest (/api/v1/places). Public: published only. Riders suggest places and corrections; staff review.
export type PlaceCategory = 'viewpoint' | 'lake' | 'waterfall' | 'temple' | 'monastery' | 'heritage' | 'cultural' | 'attraction' | 'rest_stop' | 'hidden_place' | 'other'
export interface Place {
  id: string
  slug: string
  name: string
  category: PlaceCategory
  summary: string | null
  description: string | null
  position: [number, number]
  destination: { slug: string; name: string; published: boolean } | null
  image: CmsImage | null
  gallery: CmsImage[]
  sources?: Source[]
  observed_on: string | null
  last_confirmed_on: string | null
  published_at: string | null
  suggested_by_rider: boolean
  /** Only for the rider who suggested it (and staff). */
  status?: 'draft' | 'pending_review' | 'changes_requested' | 'published' | 'rejected' | 'archived'
  moderation_note?: string | null
  is_dev_seed: boolean
}
export interface PlaceNearby {
  radius_km: { places: number; destinations: number }
  places: { slug: string; name: string; category: PlaceCategory; position: [number, number]; distance_km: number }[]
  destinations: { slug: string; name: string; position: [number, number]; distance_km: number }[]
}
export interface PlaceBody { name: string; category: PlaceCategory | ''; summary: string | null; description: string; latitude: number | string; longitude: number | string; observed_on: string | null }

export const listPlaces = async (params: Record<string, string | number | undefined>) => (await api.get<Paginated<Place>>('/places', { params })).data
/** Null when nothing visible to this visitor exists at this address. */
export async function getPlace(slug: string): Promise<{ data: Place; nearby: PlaceNearby | null; experiences: Contribution[] } | null> {
  try {
    const r = (await api.get<{ data: Place; nearby: PlaceNearby | null; experiences: { data: Contribution[] } | Contribution[] }>(`/places/${encodeURIComponent(slug)}`)).data
    return { data: r.data, nearby: r.nearby, experiences: Array.isArray(r.experiences) ? r.experiences : r.experiences.data }
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) return null
    throw e
  }
}
export const myPlaces = async () => (await api.get<{ data: Place[] }>('/users/me/places')).data.data
export const suggestPlace = async (body: PlaceBody) => (await api.post<{ data: Place }>('/places', body)).data.data
export const updateSuggestion = async (id: string, body: PlaceBody) => (await api.patch<{ data: Place }>(`/places/${id}`, body)).data.data
export const suggestCorrection = async (id: string, body: { body: string; latitude?: number; longitude?: number }) => (await api.post<{ message: string }>(`/places/${id}/corrections`, body)).data.message
