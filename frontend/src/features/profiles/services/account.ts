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

// Account deletion (30-day cooling-off, explicit cancel) and data export.
export type DeletionChoice = 'delete' | 'anonymise' | 'anonymise_without_photos'
export interface HandoverOffer {
  id: string
  status: 'offered' | 'declined'
  to: { display_name: string; username: string | null }
}
export interface DeletionSummary {
  request: { requested_at: string; run_after: string; choices: Record<string, DeletionChoice> } | null
  cooling_off_days: number
  contributions: { id: string; title: string; kind: string; status: string; eligible: boolean; photos: number; open_case: boolean }[]
  events: { id: string; title: string; starts_at: string; status: string; participants: number; outcome: 'transfer_or_cancel' | 'delete'; offer: HandoverOffer | null }[]
  trips: { id: string; title: string; members: { display_name: string; username: string | null; role: string }[]; outcome: 'transfer_or_delete' | 'delete'; offer: HandoverOffer | null }[]
}
export interface IncomingOffer {
  id: string
  subject_type: 'event' | 'trip'
  title: string
  from: { display_name: string }
  offered_at: string
}
export const deletionSummary = async () => (await api.get<{ data: DeletionSummary }>('/users/me/deletion')).data.data
export const requestDeletion = async (password: string, choices: Record<string, DeletionChoice>) =>
  (await api.post<{ message: string }>('/users/me/deletion', { password, confirm: true, choices })).data.message
export const cancelDeletion = async (password: string) => (await api.delete<{ message: string }>('/users/me/deletion', { data: { password } })).data.message
export const offerHandover = (subject_type: 'event' | 'trip', subject_id: string, username: string) =>
  api.post('/users/me/deletion/offers', { subject_type, subject_id, username })
export const withdrawHandover = (id: string) => api.delete(`/users/me/deletion/offers/${id}`)
export const incomingOffers = async () => (await api.get<{ data: IncomingOffer[] }>('/users/me/ownership-offers')).data.data
export const answerOffer = async (id: string, answer: 'accept' | 'decline') => (await api.post<{ message: string }>(`/ownership-offers/${id}/${answer}`)).data.message

/** The ZIP comes back in the response (nothing is stored on the server). Errors arrive as a Blob too: read them back. */
export async function downloadExport(password: string): Promise<Blob> {
  try {
    return (await api.post<Blob>('/users/me/export', { password }, { responseType: 'blob', timeout: 120_000 })).data
  } catch (e) {
    const res = (e as { response?: { data?: unknown } }).response
    if (res?.data instanceof Blob) {
      try {
        res.data = JSON.parse(await res.data.text())
      } catch {
        res.data = {}
      }
    }
    throw e
  }
}
