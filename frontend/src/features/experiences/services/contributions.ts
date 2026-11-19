import { api } from '@/lib/api/client'
import type { Contribution, Paginated } from '@/types/api'

// Rider contributions (/api/v1/contributions). Public lists show visible items only; writes need a confirmed rider.
export const searchContributions = async (params: Record<string, string | number | undefined>) => (await api.get<Paginated<Contribution>>('/contributions', { params })).data
export const listContributions = async (params: Record<string, string | number | undefined>) => (await api.get<{ data: Contribution[] }>('/contributions', { params })).data.data
export const getContribution = async (id: string) => (await api.get<{ data: Contribution }>(`/contributions/${id}`)).data.data
export const saveContribution = async (id: string | undefined, body: object) =>
  (id ? await api.patch<{ data: Contribution }>(`/contributions/${id}`, body) : await api.post<{ data: Contribution }>('/contributions', body)).data.data
export const uploadPhoto = async (id: string, fd: FormData) => (await api.post<{ data: { url: string; alt: string } }>(`/contributions/${id}/media`, fd)).data
export const reportContribution = async (id: string, reason: string) => (await api.post<{ message: string }>(`/contributions/${id}/reports`, { reason })).data.message
