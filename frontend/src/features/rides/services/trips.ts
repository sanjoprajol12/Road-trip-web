import { api } from '@/lib/api/client'
import type { Trip } from '@/types/api'

// Private trips (/api/v1/trips): only members reach them; editors change them; the owner manages members.
export type TripPart = 'stops' | 'expenses' | 'checklist'
export const listTrips = async () => (await api.get<{ data: Trip[] }>('/trips')).data.data
export const createTrip = async (title: string) => (await api.post<{ data: Trip }>('/trips', { title })).data
export const getTrip = async (id: string) => (await api.get<{ data: Trip }>(`/trips/${id}`)).data.data
export const updateTrip = (id: string, body: object) => api.patch(`/trips/${id}`, body)
export const deleteTrip = (id: string) => api.delete(`/trips/${id}`)
export const addPart = (id: string, part: TripPart, body: object) => api.post(`/trips/${id}/${part}`, body)
export const updatePart = (id: string, part: TripPart, partId: string, body: object) => api.patch(`/trips/${id}/${part}/${partId}`, body)
export const removePart = (id: string, part: string, partId: string) => api.delete(`/trips/${id}/${part}/${partId}`)
export const addMember = (id: string, body: object) => api.post(`/trips/${id}/members`, body)
export const setMemberRole = (id: string, userId: string, role: string) => api.patch(`/trips/${id}/members/${userId}`, { role })
export const removeMember = (id: string, userId: string) => api.delete(`/trips/${id}/members/${userId}`)
export interface TripInvitation { id: string; role: 'editor' | 'viewer'; expires_at: string; trip_title: string; invited_by: string | null }
export const myInvitations = async () => (await api.get<{ data: TripInvitation[] }>('/users/me/trip-invitations')).data.data
export const answerInvitation = async (id: string, answer: 'accept' | 'decline') => (await api.post<{ status: string; trip_id: string | null }>(`/trip-invitations/${id}/${answer}`)).data
export const cancelInvitation = (tripId: string, id: string) => api.delete(`/trips/${tripId}/invitations/${id}`)
