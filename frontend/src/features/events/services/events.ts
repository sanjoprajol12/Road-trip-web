import { api } from '@/lib/api/client'
import type { Paginated, RideEvent } from '@/types/api'

// Events (/api/v1/events): public reads; hosting needs a verified organizer; joining needs a confirmed rider.
export const listEvents = async (category?: string) => (await api.get<Paginated<RideEvent>>('/events', { params: { category } })).data.data
export const myEvents = async () => (await api.get<{ organizing: RideEvent[]; participating: RideEvent[] }>('/users/me/events')).data
export const getEvent = async (id: string) => (await api.get<{ data: RideEvent }>(`/events/${id}`)).data.data
export const saveEvent = async (id: string | undefined, body: object) =>
  (id ? await api.patch<{ data: RideEvent }>(`/events/${id}`, body) : await api.post<{ data: RideEvent }>('/events', body)).data
export const joinOrLeave = async (id: string, action: string) => (await api.post<{ status: string | null }>(`/events/${id}/${action}`)).data
export const listRegistrations = async <R>(id: string) => (await api.get<{ data: R[] }>(`/events/${id}/registrations`)).data.data
export const decideRegistration = (id: string, registrationId: string, status: string) => api.post(`/events/${id}/registrations/${registrationId}`, { status })
export const inviteRider = (id: string, username: string) => api.post(`/events/${id}/invitations`, { username })
export const postEventUpdate = (id: string, body: string) => api.post(`/events/${id}/updates`, { body })
export const changeEventStatus = (id: string, body: object) => api.post(`/events/${id}/status`, body)
