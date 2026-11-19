import { api } from '@/lib/api/client'
import type { Contribution, PublicUser } from '@/types/api'

// The signed-in rider's own account areas, and public rider profiles.
export const myContributions = async () => (await api.get<{ data: Contribution[] }>('/users/me/contributions')).data.data
export const myNotifications = async <N>() => (await api.get<{ data: N[]; unread: number }>('/users/me/notifications')).data
export const markNotificationsRead = () => api.post('/users/me/notifications/read')
export const mySessions = async <S>() => (await api.get<{ data: S[] }>('/users/me/sessions')).data.data
export const signOutOtherSessions = async () => (await api.delete<{ message: string }>('/users/me/sessions/others')).data.message
export const changePassword = (current_password: string, password: string) => api.put('/auth/password', { current_password, password })
export const listContacts = async <C>() => (await api.get<{ data: C[] }>('/users/me/emergency-contacts')).data.data
export const addContact = (body: object) => api.post('/users/me/emergency-contacts', body)
export const deleteContact = (id: string) => api.delete(`/users/me/emergency-contacts/${id}`)
export const riderProfile = async (username: string) =>
  (await api.get<{ data: PublicUser; contributions: Contribution[] | { data: Contribution[] } }>(`/riders/${encodeURIComponent(username)}`)).data
export const appealContributionPause = async (appeal: string) => (await api.post<{ message: string }>('/users/me/contribution-appeal', { appeal })).data.message
