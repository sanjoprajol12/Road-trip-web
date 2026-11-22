import { api } from '@/lib/api/client'

// A rider's saved destinations, routes, services and experiences (/api/v1/users/me/saved).
export type SavedType = 'destination' | 'route' | 'service' | 'contribution' | 'place'
export interface SavedItem {
  type: SavedType
  id: string
  name: string
  /** Service kind (fuel, mechanic, ...) or contribution kind. */
  kind: string | null
  link: string | null
  place: string | null
  latitude: number | null
  longitude: number | null
  last_confirmed_on: string | null
  saved_at: string
}
export const listSaved = async (params: { type?: SavedType; kind?: string } = {}) => (await api.get<{ data: SavedItem[] }>('/users/me/saved', { params })).data.data
export const saveItem = (type: SavedType, id: string) => api.post('/users/me/saved', { type, id })
export const unsaveItem = (type: SavedType, id: string) => api.delete(`/users/me/saved/${type}/${id}`)
